<?php

/**
 * Two-factor authentication of the signed-in user: set it up (QR code or key
 * for an authenticator application, confirmed by a first code), see and
 * regenerate the one-time recovery codes, turn it off when it is not
 * required. Administrators reset it for a user who lost their device (the
 * user sets it up again at the next sign-in when it is required).
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require 'auth_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
$userId = (int) $_SESSION['user_id'];
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

$loadUser = function (int $id) use ($con): ?array {
    $stmt = $con->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $u;
};
$user = $loadUser($userId);
$required = two_factor_required($con, $user);
$labName = (string) ($con->query("SELECT value FROM settings WHERE name = 'lab_name'")->fetch_row()[0] ?? '') ?: 'MyAnimLab';
$newCodes = null;

// A typed code is valid: the current TOTP code (any step after the last used).
$checkCode = function (array $u, string $code) use ($con): bool {
    $step = totp_verify((string) $u['totp_secret'], $code, $u['totp_last_step'] !== null ? (int) $u['totp_last_step'] : null);
    if ($step === null) {
        return false;
    }
    $up = $con->prepare('UPDATE users SET totp_last_step = ? WHERE id = ?');
    $up->bind_param('ii', $step, $u['id']);
    $up->execute();
    $up->close();
    return true;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        die(tr('CSRF token validation failed'));
    }
    $code = trim((string) ($_POST['code'] ?? ''));
    try {
        if (isset($_POST['start_setup'])) {
            $_SESSION['totp_setup_secret'] = totp_new_secret();
        } elseif (isset($_POST['confirm_setup'])) {
            $secret = (string) ($_SESSION['totp_setup_secret'] ?? '');
            $step = $secret !== '' ? totp_verify($secret, $code, null) : null;
            if ($step === null) {
                throw new Exception(tr('Wrong code: check the time of your phone and type the code currently shown.'));
            }
            [$newCodes, $hashes] = totp_new_recovery_codes();
            $up = $con->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_enabled_at = NOW(), totp_last_step = ?, totp_recovery_codes = ? WHERE id = ?');
            $up->bind_param('sisi', $secret, $step, $hashes, $userId);
            $up->execute();
            $up->close();
            unset($_SESSION['totp_setup_secret'], $_SESSION['must_setup_2fa']);
            log_activity($con, 'two_factor_enabled', 'user', (string) $userId, null, $userId);
            $_SESSION['message'] = tr('Two-factor authentication is on.');
        } elseif (isset($_POST['regenerate_codes'])) {
            if (empty($user['totp_enabled']) || !$checkCode($user, $code)) {
                throw new Exception(tr('Wrong code.'));
            }
            [$newCodes, $hashes] = totp_new_recovery_codes();
            $up = $con->prepare('UPDATE users SET totp_recovery_codes = ? WHERE id = ?');
            $up->bind_param('si', $hashes, $userId);
            $up->execute();
            $up->close();
            log_activity($con, 'two_factor_recovery_codes_regenerated', 'user', (string) $userId, null, $userId);
        } elseif (isset($_POST['disable'])) {
            if ($required) {
                throw new Exception(tr('Two-factor authentication is required for your role: it cannot be turned off.'));
            }
            if (empty($user['totp_enabled']) || !$checkCode($user, $code)) {
                throw new Exception(tr('Wrong code.'));
            }
            db_execute($con, 'UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_enabled_at = NULL, totp_last_step = NULL, totp_recovery_codes = NULL WHERE id = ?', 'i', [$userId]);
            log_activity($con, 'two_factor_disabled', 'user', (string) $userId, null, $userId);
            $_SESSION['message'] = tr('Two-factor authentication is off.');
        } elseif (isset($_POST['admin_reset']) && $isAdmin) {
            $target = (int) ($_POST['target_user'] ?? 0);
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if ($reason === '') {
                throw new Exception(tr('Give the reason of the reset (e.g. phone lost).'));
            }
            $stmt = $con->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_enabled_at = NULL, totp_last_step = NULL, totp_recovery_codes = NULL WHERE id = ? AND totp_enabled = 1');
            $stmt->bind_param('i', $target);
            $stmt->execute();
            $done = $stmt->affected_rows === 1;
            $stmt->close();
            if (!$done) {
                throw new Exception(tr('This user has no two-factor authentication to reset.'));
            }
            log_activity($con, 'two_factor_reset', 'user', (string) $target, $reason, $userId);
            create_notification($con, $target, tr('Two-factor authentication reset'), tr('An administrator reset it ({reason}). Set it up again from your profile.', ['reason' => $reason]), 'two_factor.php', 'system');
            $_SESSION['message'] = tr('Two-factor authentication reset for this user.');
        }
    } catch (Exception $e) {
        $_SESSION['message'] = error_message($e);
    }
    if ($newCodes === null) {
        header('Location: two_factor.php');
        exit;
    }
    // New recovery codes: shown once on this response, never stored in clear.
    $user = $loadUser($userId);
}

$setupSecret = $_SESSION['totp_setup_secret'] ?? null;
$withTwoFactor = $isAdmin ? $con->query('SELECT id, name, username, totp_enabled_at FROM users WHERE totp_enabled = 1 ORDER BY name')->fetch_all(MYSQLI_ASSOC) : [];
$mustSetup = !empty($_SESSION['must_setup_2fa']);

require 'header.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Two-factor authentication') ?> | <?= htmlspecialchars($labName) ?></title>
</head>

<body>
    <main class="container mt-4 content" style="max-width: 820px">
        <h4><i class="fas fa-shield-alt"></i> <?= trh('Two-factor authentication') ?></h4>
        <p class="text-muted"><?= trh('After your password, a 6-digit code from an authenticator application on your phone (e.g. FreeOTP, Google Authenticator, Microsoft Authenticator) is asked. Someone who knows your password cannot sign in without your phone.') ?></p>
        <?php include 'message.php'; ?>
        <?php if ($mustSetup) : ?>
            <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> <?= trh('Two-factor authentication is required for administrators and BEA responsibles: set it up to continue.') ?></div>
        <?php endif; ?>

        <?php if ($newCodes) : ?>
            <div class="card border-success mb-3" id="recoveryCodes">
                <div class="card-header bg-success text-white"><?= trh('Your recovery codes — keep them now') ?></div>
                <div class="card-body">
                    <p class="small"><?= trh('Each code can be used once instead of the application code, e.g. if you lose your phone. They are shown only now: print them or keep them in a safe place.') ?></p>
                    <div class="row font-monospace fs-5">
                        <?php foreach ($newCodes as $c) : ?><div class="col-6 col-md-4"><?= htmlspecialchars($c) ?></div><?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-outline-dark btn-sm mt-2" onclick="window.print()"><i class="fas fa-print"></i> <?= trh('Print') ?></button>
                    <a href="home.php" class="btn btn-success btn-sm mt-2"><?= trh('I have kept them') ?></a>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($user['totp_enabled'])) : ?>
            <div class="card mb-3">
                <div class="card-body">
                    <p class="mb-2"><span class="badge bg-success"><?= trh('On') ?></span> <?= trh('since {date}', ['date' => substr((string) $user['totp_enabled_at'], 0, 16)]) ?> · <?= trh('{n} recovery code(s) left', ['n' => totp_recovery_count($user['totp_recovery_codes'])]) ?></p>
                    <form method="POST" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="col-md-4">
                            <label class="form-label small" for="code"><?= trh('Current code of the application') ?></label>
                            <input class="form-control" name="code" id="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="6">
                        </div>
                        <div class="col-md-8">
                            <button class="btn btn-outline-primary" name="regenerate_codes"><?= trh('New recovery codes') ?></button>
                            <?php if (!$required) : ?><button class="btn btn-outline-danger" name="disable" onclick="return confirm(<?= htmlspecialchars(trj('Turn off two-factor authentication?')) ?>)"><?= trh('Turn off') ?></button><?php endif; ?>
                        </div>
                    </form>
                    <?php if ($required) : ?><p class="small text-muted mt-2 mb-0"><?= trh('Required for your role: it cannot be turned off. If you lose your phone, use a recovery code or ask an administrator to reset it.') ?></p><?php endif; ?>
                </div>
            </div>
        <?php elseif ($setupSecret) : ?>
            <div class="card mb-3 border-primary" id="setup">
                <div class="card-body">
                    <h6>1. <?= trh('Scan this QR code with your authenticator application') ?></h6>
                    <div id="qrcode" class="my-2" style="width: 200px; height: 200px;"></div>
                    <p class="small mb-1"><?= trh('Or type this key in the application:') ?></p>
                    <p class="font-monospace fs-6 user-select-all"><?= htmlspecialchars(trim(chunk_split($setupSecret, 4, ' '))) ?></p>
                    <h6 class="mt-3">2. <?= trh('Type the code shown by the application') ?></h6>
                    <form method="POST" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="col-md-4"><input class="form-control form-control-lg text-center" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456"></div>
                        <div class="col-md-4"><button class="btn btn-primary btn-lg" name="confirm_setup"><?= trh('Turn on') ?></button></div>
                    </form>
                </div>
            </div>
            <!-- The QR code is drawn in the browser: the key is never sent to a third party. -->
            <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
            <script>
                if (window.QRCode) new QRCode(document.getElementById('qrcode'), { text: <?= json_encode(totp_uri($setupSecret, $user['username'], $labName), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>, width: 200, height: 200 });
            </script>
        <?php else : ?>
            <form method="POST" class="card card-body mb-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <p class="mb-2"><span class="badge bg-secondary"><?= trh('Off') ?></span> <?= $required ? trh('Required for your role.') : trh('Recommended.') ?></p>
                <div><button class="btn btn-primary" name="start_setup"><i class="fas fa-qrcode"></i> <?= trh('Set up two-factor authentication') ?></button></div>
            </form>
        <?php endif; ?>

        <?php if ($isAdmin && !$mustSetup) : ?>
            <div class="card mb-3">
                <div class="card-header"><?= trh('Reset for a user (administrators)') ?></div>
                <div class="card-body">
                    <?php if (!$withTwoFactor) : ?>
                        <p class="text-muted small mb-0"><?= trh('Nobody uses two-factor authentication yet.') ?></p>
                    <?php else : ?>
                        <form method="POST" class="row g-2 align-items-end" onsubmit="return confirm(<?= htmlspecialchars(trj('Reset the two-factor authentication of this user?')) ?>)">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <div class="col-md-5">
                                <label class="form-label small" for="target_user"><?= trh('User') ?></label>
                                <select class="form-select form-select-sm" name="target_user" id="target_user" required>
                                    <?php foreach ($withTwoFactor as $u) : ?><option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['name'] . ' — ' . $u['username']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small" for="reason"><?= trh('Reason') ?></label>
                                <input class="form-control form-control-sm" name="reason" id="reason" required placeholder="<?= trh('e.g. phone lost') ?>">
                            </div>
                            <div class="col-md-2"><button class="btn btn-sm btn-outline-danger w-100" name="admin_reset"><?= trh('Reset') ?></button></div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>
    <?php require 'footer.php'; ?>
</body>

</html>
