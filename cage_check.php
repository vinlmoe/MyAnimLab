<?php

/**
 * Daily check of a single cage, designed for a phone after scanning the
 * cage's QR code: shows the cage's animals and today's checks, validates the
 * cage "all OK" in one tap, or opens a health report on one of its animals.
 */

session_start();
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$cageId = trim($_GET['id'] ?? $_POST['cage_id'] ?? '');
$via = in_array($_GET['via'] ?? $_POST['via'] ?? '', ['qr', 'cage_page'], true) ? ($_GET['via'] ?? $_POST['via']) : 'cage_page';

$stmt = $con->prepare("SELECT cage_id, room, rack, status, IF(EXISTS (SELECT 1 FROM breeding b WHERE b.cage_id = c.cage_id), 'breeding', 'holding') AS kind FROM cages c WHERE cage_id = ?");
$stmt->bind_param("s", $cageId);
$stmt->execute();
$cage = $stmt->get_result()->fetch_assoc();
$stmt->close();

// A cage under experiment cannot be checked "all OK": its daily check is the
// follow-up of every animal, entered on the cage's grouped follow-up page.
if ($cage && $cage['status'] === 'active' && cage_experimental_progress($con, $cageId)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION['message'] = tr('Cage {cage} is under experiment: it is validated once every animal has its follow-up for the day and every act due is confirmed.', ['cage' => $cageId]);
    }
    header('Location: experiment_cage_followup.php?' . http_build_query(['cage_id' => $cageId]));
    exit;
}

if ($cage && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(tr('CSRF token validation failed'));
    }
    if ($cage['status'] !== 'active') {
        $_SESSION['message'] = tr('Cage {cage} is archived; no daily check needed.', ['cage' => $cageId]);
    } else {
        $note = trim($_POST['note'] ?? '') ?: null;
        record_cage_check($con, $cageId, 'routine', 'ok', $via, $note, null, $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? null);
        log_activity($con, 'daily_check', 'cage', $cageId, tr('Checked OK ({via})', ['via' => $via]), $_SESSION['user_id'] ?? null);
        $_SESSION['message'] = tr('Cage {cage} checked OK.', ['cage' => $cageId]);
    }
    $back = safe_check_return($_POST['return'] ?? null);
    header("Location: " . ($back ?? "cage_check.php?" . http_build_query(['id' => $cageId, 'via' => $via, 'done' => 1])));
    exit;
}

require 'header.php';

if (!$cage) {
    echo "<div class='container mt-4'><div class='alert alert-danger'>" . trh('Cage {cage} not found.', ['cage' => $cageId]) . "</div>"
        . "<a href='qr_scan.php' class='btn btn-success'>" . trh('Scan another cage') . "</a></div>";
    require 'footer.php';
    exit;
}

$animals = $con->prepare("SELECT id, animal_uid, mouse_id, sex, distinctive_mark FROM mice WHERE cage_id = ? AND status = 'active' ORDER BY animal_uid");
$animals->bind_param("s", $cageId);
$animals->execute();
$animals = $animals->get_result()->fetch_all(MYSQLI_ASSOC);
$openIssues = open_health_counts($con, array_column($animals, 'id'));
$todayChecks = cage_checks_for_date($con, date('Y-m-d'))[$cageId] ?? [];
$selfUrl = 'cage_check.php?' . http_build_query(['id' => $cageId, 'via' => $via]);
$roomKey = ($cage['room'] ?? '') === '' ? '__none' : $cage['room'];
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Check Cage {cage}', ['cage' => $cageId]); ?> | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container {
            max-width: 600px;
        }

        .big-btn {
            font-size: 1.3rem;
            padding: 14px;
        }
    </style>
</head>

<body>
    <div class="container mt-3 content">
        <h4><i class="fas fa-clipboard-check"></i> <?= trh('Daily check — cage {cage}', ['cage' => $cageId]); ?></h4>
        <p class="text-muted mb-2">
            <?= trh(ucfirst($cage['kind']) . ' cage'); ?> ·
            <?= htmlspecialchars(($cage['room'] ?? '') !== '' ? tr('Room {room}', ['room' => $cage['room']]) : tr('No room assigned')); ?>
            <?= ($cage['rack'] ?? '') !== '' ? '· Rack ' . htmlspecialchars($cage['rack']) : ''; ?>
        </p>

        <?php include('message.php'); ?>

        <div class="mb-3">
            <strong><?= trh('Seen today:') ?></strong>
            <?php foreach ($todayChecks as $chk) echo cage_check_badge($chk); ?>
            <?php if (!$todayChecks) : ?><span class="badge bg-secondary"><?= trh('not yet') ?></span><?php endif; ?>
        </div>

        <?php if ($cage['status'] !== 'active') : ?>
            <div class="alert alert-secondary"><?= trh('This cage is archived.') ?></div>
        <?php else : ?>
            <form method="POST" class="mb-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="cage_id" value="<?= htmlspecialchars($cageId); ?>">
                <input type="hidden" name="via" value="<?= htmlspecialchars($via); ?>">
                <input type="text" name="note" class="form-control mb-2" placeholder="<?= trh('Optional note (water, food, bedding...)') ?>">
                <button type="submit" class="btn btn-success w-100 big-btn"><i class="fas fa-check"></i> <?= trh('All OK for this cage') ?></button>
            </form>

            <h5><?= trh('Animals — report a health issue') ?></h5>
            <div class="list-group mb-3">
                <?php foreach ($animals as $a) :
                    $label = $a['animal_uid'] ?? $a['mouse_id'] ?? '#' . $a['id']; ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span>
                            <a href="animal_view.php?id=<?= (int) $a['id']; ?>"><?= htmlspecialchars($label); ?></a>
                            <?= $a['sex'] === 'male' ? '♂' : ($a['sex'] === 'female' ? '♀' : ''); ?>
                            <?php if (!empty($a['distinctive_mark'])) : ?><small class="text-muted mark" title="<?= trh('Distinctive mark') ?>"> [<?= htmlspecialchars($a['distinctive_mark']); ?>]</small><?php endif; ?>
                            <?php if (!empty($openIssues[$a['id']])) : ?><span class="badge bg-danger"><?= trh('{n} open issue(s)', ['n' => $openIssues[$a['id']]]); ?></span><?php endif; ?>
                        </span>
                        <a href="health_report.php?<?= htmlspecialchars(http_build_query(['animal_id' => $a['id'], 'via' => $via === 'qr' ? 'qr' : 'cage_page', 'return' => $selfUrl])); ?>" class="btn btn-warning btn-sm"><?= trh('Report issue') ?></a>
                    </div>
                <?php endforeach; ?>
                <?php if (!$animals) : ?><div class="list-group-item text-muted"><?= trh('No active animal in this cage.') ?></div><?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="d-grid gap-2">
            <a href="qr_scan.php" class="btn btn-primary big-btn"><i class="fas fa-qrcode"></i> <?= trh('Scan next cage') ?></a>
            <a href="daily_round.php?<?= htmlspecialchars(http_build_query(['room' => $roomKey, 'hide_seen' => 1])); ?>" class="btn btn-outline-secondary"><?= trh('Cages still to check in this room') ?></a>
        </div>
    </div>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
