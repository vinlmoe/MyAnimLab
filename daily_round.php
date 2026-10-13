<?php

/**
 * Daily round (visite journalière).
 *
 * Every active cage must be seen at least once a day. The round goes room by
 * room: each cage is ticked "all OK" (one by one or in bulk) or gets a health
 * report on one of its animals. Cages already seen today through the
 * experimental daily follow-up, a health report or a QR scan show as seen.
 * Past days can be reviewed (read-only) to spot missed cages.
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

$today = date('Y-m-d');
$date = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $today) {
    $date = $today;
}
$isToday = $date === $today;
$room = $_GET['room'] ?? $_POST['room'] ?? '';
$hideSeen = !empty($_GET['hide_seen']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    // Checks can only be recorded for today: the log is a real-time record.
    $cageIds = array_filter(array_map('trim', (array) ($_POST['cage_ids'] ?? [])));
    $note = trim($_POST['note'] ?? '') ?: null;
    $active = array_column(cages_due_on($con, $today), 'cage_id');
    $cageIds = array_values(array_intersect($cageIds, $active));

    foreach ($cageIds as $cid) {
        record_cage_check($con, $cid, 'routine', 'ok', 'round', $note, null, $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? null);
    }
    if ($cageIds) {
        log_activity($con, 'daily_check', 'cage', implode(',', $cageIds), count($cageIds) . " cage(s) checked OK on the round", $_SESSION['user_id'] ?? null);
    }
    $_SESSION['message'] = $cageIds ? count($cageIds) . " cage(s) marked as checked OK." : "No cage selected.";

    header("Location: daily_round.php?" . http_build_query(['room' => $room, 'hide_seen' => $_POST['hide_seen'] ?? null]));
    exit;
}

// Only cages that were in service on that day (created, not yet retired).
$cages = cages_due_on($con, $date);
$checks = cage_checks_for_date($con, $date);

// Animals currently housed in each active cage, with their open health issues.
$animalRows = $con->query(
    "SELECT m.id, m.animal_uid, m.mouse_id, m.sex, m.distinctive_mark, m.cage_id FROM mice m
     JOIN cages c ON c.cage_id = m.cage_id AND c.status = 'active'
     WHERE m.status = 'active' ORDER BY m.animal_uid"
)->fetch_all(MYSQLI_ASSOC);
$openIssues = open_health_counts($con, array_column($animalRows, 'id'));
$animalsByCage = [];
foreach ($animalRows as $a) {
    $animalsByCage[$a['cage_id']][] = $a;
}

// Room summary (cages with no room are grouped under "").
$rooms = [];
foreach ($cages as $c) {
    $r = $c['room'] ?? '';
    $rooms[$r] ??= ['total' => 0, 'seen' => 0, 'issues' => 0];
    $rooms[$r]['total']++;
    if (!empty($checks[$c['cage_id']])) {
        $rooms[$r]['seen']++;
        if (in_array('issue', array_column($checks[$c['cage_id']], 'result'), true)) {
            $rooms[$r]['issues']++;
        }
    }
}
$totalCages = count($cages);
$totalSeen = array_sum(array_column($rooms, 'seen'));
$roomLabel = fn($r) => $r === '' ? 'No room assigned' : "Room $r";

$shownCages = array_filter($cages, fn($c) => ($room === '' || ($c['room'] ?? '') === ($room === '__none' ? '' : $room))
    && !($hideSeen && !empty($checks[$c['cage_id']])));
$returnUrl = 'daily_round.php?' . http_build_query(['room' => $room, 'hide_seen' => $hideSeen ? 1 : null]);

require 'header.php';
?>

<!doctype html>
<html lang="en">

<head>
    <title>Daily Round | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container {
            max-width: 1200px;
        }

        tr.seen {
            background-color: #eaf6ec;
        }

        tr.missed {
            background-color: #fbeaea;
        }

        .room-card.active-room {
            outline: 3px solid #0d6efd;
        }
    </style>
</head>

<body>
    <div class="container mt-4 content">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <h4><i class="fas fa-clipboard-check"></i> Daily Round — <?= htmlspecialchars($date); ?><?= $isToday ? ' (today)' : ''; ?></h4>
            <div>
                <a href="qr_scan.php" class="btn btn-success btn-sm"><i class="fas fa-qrcode"></i> Check by scanning cage QR</a>
                <form method="GET" class="d-inline-flex align-items-center ms-2">
                    <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($date); ?>" max="<?= $today; ?>">
                    <button class="btn btn-outline-secondary btn-sm ms-1" type="submit">View day</button>
                </form>
            </div>
        </div>
        <p class="text-muted">Every active cage must be seen at least once a day. A cage counts as seen when it is ticked on this round, validated from its QR code, has an experimental daily follow-up entry, or a health report on one of its animals.</p>

        <?php include('message.php'); ?>

        <div class="alert <?= $totalSeen === $totalCages ? 'alert-success' : ($isToday ? 'alert-warning' : 'alert-danger'); ?>">
            <strong><?= $totalSeen; ?> / <?= $totalCages; ?></strong> cage(s) seen<?= $isToday ? ' so far today' : " on $date"; ?>.
            <?php if ($totalSeen < $totalCages) : ?>
                <?= $totalCages - $totalSeen; ?> <?= $isToday ? 'still to check' : 'missed'; ?>.
            <?php endif; ?>
            <?php if (!$isToday) : ?>
                <br><small>Past days are read-only. Only cages in service that day are expected (created on or before it, not yet retired).</small>
            <?php endif; ?>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
                <a href="daily_round.php?date=<?= $date; ?>" class="text-decoration-none">
                    <div class="card room-card text-center <?= $room === '' ? 'active-room' : ''; ?>">
                        <div class="card-body p-2"><strong>All rooms</strong><br><?= $totalSeen; ?> / <?= $totalCages; ?></div>
                    </div>
                </a>
            </div>
            <?php foreach ($rooms as $r => $st) :
                $key = $r === '' ? '__none' : $r;
                $done = $st['seen'] === $st['total'];
            ?>
                <div class="col-6 col-md-3">
                    <a href="daily_round.php?<?= htmlspecialchars(http_build_query(['date' => $date, 'room' => $key])); ?>" class="text-decoration-none">
                        <div class="card room-card text-center border-<?= $done ? 'success' : 'warning'; ?> <?= $room === $key ? 'active-room' : ''; ?>">
                            <div class="card-body p-2">
                                <strong><?= htmlspecialchars($roomLabel($r)); ?></strong><br>
                                <span class="badge bg-<?= $done ? 'success' : 'warning text-dark'; ?>"><?= $st['seen']; ?> / <?= $st['total']; ?> seen</span>
                                <?php if ($st['issues']) : ?><span class="badge bg-danger"><?= $st['issues']; ?> issue(s)</span><?php endif; ?>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($isToday) : ?>
            <div class="mb-2">
                <?php if ($hideSeen) : ?>
                    <a href="<?= htmlspecialchars('daily_round.php?' . http_build_query(['room' => $room])); ?>" class="btn btn-sm btn-outline-secondary">Show all cages</a>
                <?php else : ?>
                    <a href="<?= htmlspecialchars('daily_round.php?' . http_build_query(['room' => $room, 'hide_seen' => 1])); ?>" class="btn btn-sm btn-outline-secondary">Show only cages still to check</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="roundForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="room" value="<?= htmlspecialchars($room); ?>">
            <input type="hidden" name="hide_seen" value="<?= $hideSeen ? 1 : ''; ?>">

            <?php
            $currentRoom = null;
            foreach ($shownCages as $c) :
                $r = $c['room'] ?? '';
                if ($r !== $currentRoom) :
                    if ($currentRoom !== null) echo '</tbody></table></div>';
                    $currentRoom = $r;
            ?>
                    <h5 class="mt-3"><?= htmlspecialchars($roomLabel($r)); ?></h5>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <?php if ($isToday) : ?><th style="width: 30px;"><input type="checkbox" class="form-check-input select-room" title="Select all cages of this room"></th><?php endif; ?>
                                    <th>Cage</th>
                                    <th>Rack</th>
                                    <th><?= $isToday ? 'Animals' : 'Lifecycle'; ?></th>
                                    <th>Seen<?= $isToday ? ' today' : ''; ?></th>
                                    <?php if ($isToday) : ?><th class="text-end">Actions</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php endif;
                        $cid = $c['cage_id'];
                        $cageChecks = $checks[$cid] ?? [];
                        $view = $c['kind'] === 'breeding' ? 'bc_view.php' : 'hc_view.php';
                            ?>
                            <tr class="<?= $cageChecks ? 'seen' : ($isToday ? '' : 'missed'); ?>" data-cage="<?= htmlspecialchars($cid); ?>">
                                <?php if ($isToday) : ?>
                                    <td><input type="checkbox" class="form-check-input cage-box" name="cage_ids[]" value="<?= htmlspecialchars($cid); ?>" aria-label="Select cage <?= htmlspecialchars($cid); ?>"></td>
                                <?php endif; ?>
                                <td><a href="<?= $view; ?>?id=<?= rawurlencode($cid); ?>" class="fw-bold"><?= htmlspecialchars($cid); ?></a><br><small class="text-muted"><?= $c['kind']; ?></small></td>
                                <td><?= htmlspecialchars($c['rack'] ?? '-'); ?></td>
                                <td>
                                    <?php if (!$isToday) : ?>
                                        <small class="text-muted">
                                            <?= $c['created_at'] ? 'in service since ' . htmlspecialchars(substr($c['created_at'], 0, 10)) : 'legacy cage'; ?>
                                            <?= $c['retired_at'] ? '· retired ' . htmlspecialchars(substr($c['retired_at'], 0, 10)) : ''; ?>
                                        </small>
                                    <?php endif; ?>
                                    <?php foreach ($isToday ? ($animalsByCage[$cid] ?? []) : [] as $a) :
                                        $label = $a['animal_uid'] ?? $a['mouse_id'] ?? '#' . $a['id']; ?>
                                        <span class="text-nowrap me-2">
                                            <a href="animal_view.php?id=<?= (int) $a['id']; ?>"><?= htmlspecialchars($label); ?></a><?= $a['sex'] === 'male' ? '♂' : ($a['sex'] === 'female' ? '♀' : ''); ?><?php if (!empty($a['distinctive_mark'])) : ?><small class="text-muted mark" title="Distinctive mark"> [<?= htmlspecialchars($a['distinctive_mark']); ?>]</small><?php endif; ?>
                                            <?php if (!empty($openIssues[$a['id']])) : ?><span class="badge bg-danger" title="Open health issue">!</span><?php endif; ?>
                                            <?php if ($isToday) : ?>
                                                <a href="health_report.php?<?= htmlspecialchars(http_build_query(['animal_id' => $a['id'], 'via' => 'round', 'return' => $returnUrl])); ?>" class="btn btn-outline-warning btn-sm py-0 px-1" title="Report a health issue for <?= htmlspecialchars($label); ?>">Report</a>
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                    <?php if ($isToday && empty($animalsByCage[$cid])) : ?><span class="text-muted">No active animal</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ($cageChecks as $chk) echo cage_check_badge($chk); ?>
                                    <?php if (!$cageChecks) : ?><span class="badge bg-<?= $isToday ? 'secondary' : 'danger'; ?>"><?= $isToday ? 'not yet' : 'MISSED'; ?></span><?php endif; ?>
                                </td>
                                <?php if ($isToday) : ?>
                                    <td class="text-end">
                                        <button type="submit" name="cage_ids[]" value="<?= htmlspecialchars($cid); ?>" formnovalidate class="btn btn-success btn-sm single-ok">All OK</button>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($currentRoom !== null) echo '</tbody></table></div>'; ?>
                        <?php if (!$shownCages) : ?>
                            <p class="text-muted mt-3"><?= $totalCages ? 'Every cage in this view has been seen.' : 'No active cage.'; ?></p>
                        <?php endif; ?>

                        <?php if ($isToday && $shownCages) : ?>
                            <div class="card card-body mt-3 sticky-bottom bg-light">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-8">
                                        <label class="form-label" for="note">Note for the selected cages (optional)</label>
                                        <input type="text" class="form-control" id="note" name="note" placeholder="e.g. water and food checked">
                                    </div>
                                    <div class="col-md-4">
                                        <button type="submit" id="bulkOk" class="btn btn-success w-100"><i class="fas fa-check-double"></i> Mark selected cages OK</button>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
        </form>
    </div>

    <script>
        // "All OK" on one row must only submit that cage, not every ticked box.
        document.querySelectorAll('.single-ok').forEach(btn => btn.addEventListener('click', () => {
            document.querySelectorAll('.cage-box').forEach(b => b.checked = false);
        }));
        document.querySelectorAll('.select-room').forEach(master => master.addEventListener('change', () => {
            master.closest('table').querySelectorAll('.cage-box').forEach(b => b.checked = master.checked);
        }));
    </script>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
