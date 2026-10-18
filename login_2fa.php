<?php

/**
 * Second step of the sign-in for a user with two-factor authentication: the
 * code of the authenticator application (or a recovery code) is asked after
 * the password, within 5 minutes and 5 attempts; then the session is opened.
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require 'auth_lib.php';

$pending = $_SESSION['pending_2fa'] ?? null;
if (!$pending || time() - (int) $pending['at'] > TOTP_PENDING_TTL) {
    unset($_SESSION['pending_2fa']);
    $_SESSION['error_message'] = trh('Sign in again: the time to enter the code is over.');
    header('Location: index.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$stmt = $con->prepare('SELECT * FROM users WHERE id = ?');
$stmt->bind_param('i', $pending['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user || empty($user['totp_enabled']) || $user['status'] !== 'approved') {
    unset($_SESSION['pending_2fa']);
    header('Location: index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        die(tr('CSRF token validation failed'));
    }
    $code = trim((string) ($_POST['code'] ?? ''));
    $step = totp_verify((string) $user['totp_secret'], $code, $user['totp_last_step'] !== null ? (int) $user['totp_last_step'] : null);
    $remaining = $step === null ? totp_use_recovery_code($user['totp_recovery_codes'], $code) : null;
    if ($step !== null || $remaining !== null) {
        if ($step !== null) {
            $up = $con->prepare('UPDATE users SET totp_last_step = ? WHERE id = ?');
            $up->bind_param('ii', $step, $user['id']);
        } else {
            $up = $con->prepare('UPDATE users SET totp_recovery_codes = ? WHERE id = ?');
            $up->bind_param('si', $remaining, $user['id']);
        }
        $up->execute();
        $up->close();
        $target = $pending['redirect'] ?? 'home.php';
        complete_login($con, $user);
        if ($step === null) {
            log_activity($con, 'two_factor_recovery_code_used', 'user', (string) $user['id'], tr('{n} recovery code(s) left', ['n' => totp_recovery_count($remaining)]), (int) $user['id']);
            $_SESSION['message'] = tr('Recovery code used: {n} left. Generate new ones in “Two-factor authentication” if needed.', ['n' => totp_recovery_count($remaining)]);
        }
        header('Location: ' . $target);
        exit;
    }
    $_SESSION['pending_2fa']['attempts'] = (int) $pending['attempts'] + 1;
    if ($_SESSION['pending_2fa']['attempts'] >= TOTP_MAX_ATTEMPTS) {
        // Too many wrong codes: the account is locked like after wrong passwords.
        $lock = $con->prepare('UPDATE users SET account_locked = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?');
        $lock->bind_param('i', $user['id']);
        $lock->execute();
        $lock->close();
        log_activity($con, 'two_factor_failed', 'user', (string) $user['id'], tr('Too many wrong codes: account locked for 15 minutes.'), (int) $user['id']);
        unset($_SESSION['pending_2fa']);
        $_SESSION['error_message'] = trh('Too many wrong codes: account locked for 15 minutes.');
        header('Location: index.php');
        exit;
    }
    $error = tr('Wrong code ({n} attempt(s) left).', ['n' => TOTP_MAX_ATTEMPTS - $_SESSION['pending_2fa']['attempts']]);
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= trh('Two-factor authentication') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <main class="container" style="max-width: 420px; margin-top: 10vh;">
        <div class="card shadow-sm">
            <div class="card-body">
                <h4 class="mb-3"><?= trh('Two-factor authentication') ?></h4>
                <p class="text-muted small"><?= trh('Enter the 6-digit code shown by your authenticator application for {user}, or one of your recovery codes.', ['user' => $user['username']]) ?></p>
                <?php if ($error) : ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input class="form-control form-control-lg text-center mb-3" name="code" id="code" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="9" placeholder="123456">
                    <button class="btn btn-primary w-100"><?= trh('Verify') ?></button>
                </form>
                <p class="small mt-3 mb-0"><a href="logout.php"><?= trh('Cancel and sign in again') ?></a></p>
            </div>
        </div>
    </main>
</body>

</html>
