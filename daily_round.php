<?php

/**
 * Daily round (visite journalière).
 *
 * Every active cage must be seen at least once a day. The round goes room by
 * room: each cage is ticked "all OK" (one by one or in bulk) or gets a health
 * report on one of its animals. Cages already seen today through the
 * experimental daily follow-up, a health report or a QR scan show as seen.
 * Past days can be reviewed (read-only) to spot missed cages.
 * The temperature of each room is entered on the round: the minimum and
 * maximum of the day, or the current temperature (Manage Lab).
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require_once 'training_lib.php';
require 'experiment_acts_lib.php';

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
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
    if (!csrf_valid()) {
        die(tr('CSRF token validation failed'));
    }

    // Temperature of a room (today only, like the checks).
    if (isset($_POST['room_temperature'])) {
        try {
            $tempRoom = trim((string) ($_POST['temp_room'] ?? ''));
            record_room_temperature($con, $tempRoom, room_temperature_mode($con), ['min' => $_POST['temp_min'] ?? '', 'max' => $_POST['temp_max'] ?? '', 'current' => $_POST['temp_current'] ?? ''], $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? null);
            $_SESSION['message'] = tr('Temperature of room {room} recorded.', ['room' => $tempRoom]);
        } catch (Exception $e) {
            $_SESSION['message'] = tr('Temperature not recorded: {error}', ['error' => error_message($e)]);
        }
        header("Location: daily_round.php?" . http_build_query(['room' => $room, 'hide_seen' => $_POST['hide_seen'] ?? null]) . '#roomTemperatures');
        exit;
    }

    // Checks can only be recorded for today: the log is a real-time record.
    $cageIds = array_filter(array_map('trim', (array) ($_POST['cage_ids'] ?? [])));
    $note = trim($_POST['note'] ?? '') ?: null;
    $active = array_column(cages_due_on($con, $today), 'cage_id');
    $cageIds = array_values(array_intersect($cageIds, $active));
    // A cage under experiment is validated by its animals' follow-ups, never by "all OK".
    $underExperiment = array_values(array_intersect($cageIds, array_keys(experimental_followup_by_cage($con, $today))));
    $cageIds = array_values(array_diff($cageIds, $underExperiment));

    foreach ($cageIds as $cid) {
        record_cage_check($con, $cid, 'routine', 'ok', 'round', $note, null, $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? null);
    }
    if ($cageIds) {
        log_activity($con, 'daily_check', 'cage', implode(',', $cageIds), tr('{n} cage(s) checked OK on the round', ['n' => count($cageIds)]), $_SESSION['user_id'] ?? null);
    }
    $_SESSION['message'] = ($cageIds ? tr('{n} cage(s) marked as checked OK.', ['n' => count($cageIds)]) : ($underExperiment ? '' : tr('No cage selected.')))
        . ($underExperiment ? ' ' . tr('Cage(s) under experiment not validated: {cages} — enter the follow-up of each animal and confirm the acts due.', ['cages' => implode(', ', $underExperiment)]) : '');

    header("Location: daily_round.php?" . http_build_query(['room' => $room, 'hide_seen' => $_POST['hide_seen'] ?? null]));
    exit;
}

// Only cages that were in service on that day (created, not yet retired).
$cages = cages_due_on($con, $date);
$checks = cage_checks_for_date($con, $date);
// Cages under experiment: validated only once every animal has its follow-up.
$experimentalProgress = $date >= experimental_round_since($con) ? experimental_followup_by_cage($con, $date) : [];

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
$treatmentsByCage = [];
$treatmentRows = $con->query(
    "SELECT m.cage_id, COUNT(*) AS pending_count
     FROM prescription_animals pa
     JOIN prescriptions pr ON pr.id=pa.prescription_id AND pr.status='active'
     JOIN mice m ON m.id=pa.animal_id AND m.status='active'
     WHERE pa.planned_administrations > (SELECT COUNT(*) FROM medication_administrations ma WHERE ma.prescription_id=pa.prescription_id AND ma.animal_id=pa.animal_id AND ma.cancelled_at IS NULL)
     GROUP BY m.cage_id"
)->fetch_all(MYSQLI_ASSOC);
foreach ($treatmentRows as $treatment) $treatmentsByCage[$treatment['cage_id']] = (int) $treatment['pending_count'];
// Experimental acts to confirm today (or overdue), per cage.
$actsDueByCage = acts_due_by_cage($con, date('Y-m-d'));

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
// Room temperatures of the day: the last reading of each room counts.
$temperatureMode = room_temperature_mode($con);
$temperatures = room_temperatures_for_date($con, $date);
$temperatureRooms = array_values(array_filter(array_keys($rooms), fn($r) => $r !== '' && ($room === '' || $room === $r)));
foreach (array_keys($temperatures) as $r) {
    if (($room === '' || $room === $r) && !in_array($r, $temperatureRooms, true)) {
        $temperatureRooms[] = $r; // a room emptied since the reading
    }
}
$totalSeen = array_sum(array_column($rooms, 'seen'));
$roomLabel = fn($r) => $r === '' ? tr('No room assigned') : tr('Room {room}', ['room' => $r]);

$shownCages = array_filter($cages, fn($c) => ($room === '' || ($c['room'] ?? '') === ($room === '__none' ? '' : $room))
    && !($hideSeen && !empty($checks[$c['cage_id']])));
$returnUrl = 'daily_round.php?' . http_build_query(['room' => $room, 'hide_seen' => $hideSeen ? 1 : null]);

require 'header.php';
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Daily Round') ?> | <?php echo htmlspecialchars($labName); ?></title>
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
            <h4><i class="fas fa-clipboard-check"></i> <?= trh('Daily Round') ?> — <?= htmlspecialchars($date); ?><?= $isToday ? ' (' . trh('today') . ')' : ''; ?></h4>
            <div>
                <a href="qr_scan.php" class="btn btn-success btn-sm"><i class="fas fa-qrcode"></i> <?= trh('Check by scanning cage QR') ?></a>
                <form method="GET" class="d-inline-flex align-items-center ms-2">
                    <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($date); ?>" max="<?= $today; ?>">
                    <button class="btn btn-outline-secondary btn-sm ms-1" type="submit"><?= trh('View day') ?></button>
                </form>
            </div>
        </div>
        <p class="text-muted"><?= trh('Every active cage must be seen at least once a day. A cage counts as seen when it is ticked on this round, validated from its QR code, or has a health report on one of its animals. A cage under experiment is validated only once every one of its animals has its experimental follow-up for the day and every experimental act due on them is confirmed.') ?></p>

        <?php include('message.php'); ?>
        <?= training_procedure_banner($con, 'round') ?>

        <div class="alert <?= $totalSeen === $totalCages ? 'alert-success' : ($isToday ? 'alert-warning' : 'alert-danger'); ?>">
            <?= tr($isToday ? '{count} cage(s) seen so far today.' : '{count} cage(s) seen on {date}.', ['count' => '<strong>' . $totalSeen . ' / ' . $totalCages . '</strong>', 'date' => htmlspecialchars($date)]); ?>
            <?php if ($totalSeen < $totalCages) : ?>
                <?= trh($isToday ? '{n} still to check.' : '{n} missed.', ['n' => $totalCages - $totalSeen]); ?>
            <?php endif; ?>
            <?php if (!$isToday) : ?>
                <br><small><?= trh('Past days are read-only. Only cages in service that day are expected (created on or before it, not yet retired).') ?></small>
            <?php endif; ?>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
                <a href="daily_round.php?date=<?= $date; ?>" class="text-decoration-none">
                    <div class="card room-card text-center <?= $room === '' ? 'active-room' : ''; ?>">
                        <div class="card-body p-2"><strong><?= trh('All rooms') ?></strong><br><?= $totalSeen; ?> / <?= $totalCages; ?></div>
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
                                <span class="badge bg-<?= $done ? 'success' : 'warning text-dark'; ?>"><?= trh('{seen} / {total} seen', ['seen' => $st['seen'], 'total' => $st['total']]); ?></span>
                                <?php if ($st['issues']) : ?><span class="badge bg-danger"><?= trh('{n} issue(s)', ['n' => $st['issues']]); ?></span><?php endif; ?>
                                <?php if (!empty($temperatures[$r])) : ?><br><small class="text-muted"><i class="fas fa-thermometer-half"></i> <?= htmlspecialchars(room_temperature_text(end($temperatures[$r]))); ?></small><?php endif; ?>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($isToday) : ?>
            <div class="mb-2">
                <?php if ($hideSeen) : ?>
                    <a href="<?= htmlspecialchars('daily_round.php?' . http_build_query(['room' => $room])); ?>" class="btn btn-sm btn-outline-secondary"><?= trh('Show all cages') ?></a>
                <?php else : ?>
                    <a href="<?= htmlspecialchars('daily_round.php?' . http_build_query(['room' => $room, 'hide_seen' => 1])); ?>" class="btn btn-sm btn-outline-secondary"><?= trh('Show only cages still to check') ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($temperatureRooms) : ?>
            <div class="card mb-3" id="roomTemperatures">
                <div class="card-body py-2">
                    <h6 class="mb-2"><i class="fas fa-thermometer-half"></i> <?= trh('Room temperatures') ?>
                        <small class="text-muted fw-normal">— <?= trh(ROOM_TEMPERATURE_MODES[$temperatureMode]) ?></small></h6>
                    <?php foreach ($temperatureRooms as $r) :
                        $readings = $temperatures[$r] ?? [];
                        $last = $readings ? end($readings) : null; ?>
                        <div class="row g-2 align-items-center mb-1 room-temperature" data-room="<?= htmlspecialchars($r); ?>">
                            <div class="col-md-2"><strong><?= htmlspecialchars($roomLabel($r)); ?></strong></div>
                            <div class="col-md-4">
                                <?php if ($last) : ?>
                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars(room_temperature_text($last)); ?></span>
                                    <small class="text-muted"><?= htmlspecialchars(substr($last['recorded_at'], 11, 5) . ' · ' . ($last['recorded_by_name'] ?? '')); ?><?= count($readings) > 1 ? ' · ' . trh('{n} readings', ['n' => count($readings)]) : ''; ?></small>
                                <?php else : ?>
                                    <span class="text-<?= $isToday ? 'warning' : 'danger'; ?>"><?= trh($isToday ? 'Not entered yet' : 'Not entered'); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($isToday) : ?>
                                <div class="col-md-6">
                                    <form method="POST" class="d-flex gap-1 align-items-center">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="room" value="<?= htmlspecialchars($room); ?>">
                                        <input type="hidden" name="hide_seen" value="<?= $hideSeen ? 1 : ''; ?>">
                                        <input type="hidden" name="temp_room" value="<?= htmlspecialchars($r); ?>">
                                        <?php if ($temperatureMode === 'current') : ?>
                                            <input type="text" inputmode="decimal" class="form-control form-control-sm" style="max-width: 7em" name="temp_current" required placeholder="<?= trh('°C now') ?>" aria-label="<?= trh('Current temperature (°C)') ?>">
                                        <?php else : ?>
                                            <input type="text" inputmode="decimal" class="form-control form-control-sm" style="max-width: 7em" name="temp_min" required placeholder="<?= trh('min °C') ?>" aria-label="<?= trh('Minimum temperature (°C)') ?>">
                                            <input type="text" inputmode="decimal" class="form-control form-control-sm" style="max-width: 7em" name="temp_max" required placeholder="<?= trh('max °C') ?>" aria-label="<?= trh('Maximum temperature (°C)') ?>">
                                        <?php endif; ?>
                                        <button type="submit" name="room_temperature" class="btn btn-sm btn-outline-primary text-nowrap"><?= trh($last ? 'Correct' : 'Save') ?></button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
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
                                    <?php if ($isToday) : ?><th style="width: 30px;"><input type="checkbox" class="form-check-input select-room" title="<?= trh('Select all cages of this room') ?>"></th><?php endif; ?>
                                    <th><?= trh('Cage') ?></th>
                                    <th><?= trh('Rack') ?></th>
                                    <th><?= trh($isToday ? 'Animals' : 'Lifecycle'); ?></th>
                                    <th><?= trh($isToday ? 'Seen today' : 'Seen'); ?></th>
                                    <?php if ($isToday) : ?><th class="text-end"><?= trh('Actions') ?></th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php endif;
                        $cid = $c['cage_id'];
                        $cageChecks = $checks[$cid] ?? [];
                        $progress = $experimentalProgress[$cid] ?? null;
                        $view = $c['kind'] === 'breeding' ? 'bc_view.php' : 'hc_view.php';
                            ?>
                            <tr class="<?= $cageChecks ? 'seen' : ($isToday ? '' : 'missed'); ?>" data-cage="<?= htmlspecialchars($cid); ?>">
                                <?php if ($isToday) : ?>
                                    <td><input type="checkbox" class="form-check-input cage-box" name="cage_ids[]" value="<?= htmlspecialchars($cid); ?>" aria-label="<?= trh('Select cage {cage}', ['cage' => $cid]); ?>" <?= $progress ? 'disabled' : ''; ?>></td>
                                <?php endif; ?>
                                <td><a href="<?= $view; ?>?id=<?= rawurlencode($cid); ?>" class="fw-bold"><?= htmlspecialchars($cid); ?></a><br><small class="text-muted"><?= trh($c['kind']); ?></small><?php if ($progress) : ?><br><span class="badge bg-info text-dark"><i class="fas fa-flask"></i> <?= trh('Under experiment') ?></span><?php endif; ?></td>
                                <td><?= htmlspecialchars($c['rack'] ?? '-'); ?></td>
                                <td>
                                    <?php if (!$isToday) : ?>
                                        <small class="text-muted">
                                            <?= $c['created_at'] ? trh('in service since {date}', ['date' => substr($c['created_at'], 0, 10)]) : trh('legacy cage'); ?>
                                            <?= $c['retired_at'] ? '· ' . trh('retired {date}', ['date' => substr($c['retired_at'], 0, 10)]) : ''; ?>
                                        </small>
                                    <?php endif; ?>
                                    <?php foreach ($isToday ? ($animalsByCage[$cid] ?? []) : [] as $a) :
                                        $label = $a['animal_uid'] ?? $a['mouse_id'] ?? '#' . $a['id']; ?>
                                        <span class="text-nowrap me-2">
                                            <a href="animal_view.php?id=<?= (int) $a['id']; ?>"><?= htmlspecialchars($label); ?></a><?= $a['sex'] === 'male' ? '♂' : ($a['sex'] === 'female' ? '♀' : ''); ?><?php if (!empty($a['distinctive_mark'])) : ?><small class="text-muted mark" title="<?= trh('Distinctive mark') ?>"> [<?= htmlspecialchars($a['distinctive_mark']); ?>]</small><?php endif; ?>
                                            <?php if (!empty($openIssues[$a['id']])) : ?><span class="badge bg-danger" title="<?= trh('Open health issue') ?>">!</span><?php endif; ?>
                                            <?php if ($isToday) : ?>
                                                <a href="health_report.php?<?= htmlspecialchars(http_build_query(['animal_id' => $a['id'], 'via' => 'round', 'return' => $returnUrl])); ?>" class="btn btn-outline-warning btn-sm py-0 px-1" title="<?= trh('Report a health issue for {animal}', ['animal' => $label]); ?>"><?= trh('Report') ?></a>
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                    <?php if ($isToday && empty($animalsByCage[$cid])) : ?><span class="text-muted"><?= trh('No active animal') ?></span><?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ($cageChecks as $chk) echo cage_check_badge($chk); ?>
                                    <?php if (!$cageChecks) : ?><span class="badge bg-<?= $isToday ? 'secondary' : 'danger'; ?>"><?= trh($isToday ? 'not yet' : 'MISSED'); ?></span><?php endif; ?>
                                    <?php if ($progress) : ?><div class="small <?= $progress['done'] < $progress['total'] ? 'text-danger' : 'text-success'; ?>"><?= trh('{done}/{total} animal(s) evaluated', ['done' => $progress['done'], 'total' => $progress['total']]); ?></div><?php if ($progress['acts_pending']) : ?><div class="small text-danger"><?= trh('{n} experimental act(s) to confirm', ['n' => $progress['acts_pending']]); ?></div><?php endif; ?><?php endif; ?>
                                </td>
                                <?php if ($isToday) : ?>
                                    <td class="text-end">
                                        <?php if (!empty($treatmentsByCage[$cid])) : ?>
                                            <a href="medication_administration.php?cage_id=<?= rawurlencode($cid); ?>" class="btn btn-outline-primary btn-sm" title="Administrations actives"><i class="fas fa-syringe"></i> <?= $treatmentsByCage[$cid]; ?></a>
                                        <?php endif; ?>
                                        <?php if (!empty($actsDueByCage[$cid])) : ?>
                                            <a href="experiment_cage_followup.php?<?= htmlspecialchars(http_build_query(['cage_id' => $cid])); ?>#cageActs" class="btn btn-warning btn-sm" title="<?= trh('{n} experimental act(s) to confirm', ['n' => $actsDueByCage[$cid]]); ?>"><i class="fas fa-syringe"></i> <?= trh('{n} act(s)', ['n' => $actsDueByCage[$cid]]); ?></a>
                                        <?php endif; ?>
                                        <?php if ($progress) : ?>
                                            <a href="experiment_cage_followup.php?<?= htmlspecialchars(http_build_query(['cage_id' => $cid])); ?>" class="btn btn-<?= experimental_cage_complete($progress) ? 'outline-success' : 'primary'; ?> btn-sm"><i class="fas fa-flask"></i> <?= trh('Experimental follow-up ({done}/{total})', ['done' => $progress['done'], 'total' => $progress['total']]); ?></a>
                                        <?php else : ?>
                                            <button type="submit" name="cage_ids[]" value="<?= htmlspecialchars($cid); ?>" formnovalidate class="btn btn-success btn-sm single-ok"><?= trh('All OK') ?></button>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($currentRoom !== null) echo '</tbody></table></div>'; ?>
                        <?php if (!$shownCages) : ?>
                            <p class="text-muted mt-3"><?= trh($totalCages ? 'Every cage in this view has been seen.' : 'No active cage.'); ?></p>
                        <?php endif; ?>

                        <?php if ($isToday && $shownCages) : ?>
                            <div class="card card-body mt-3 sticky-bottom bg-light">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-8">
                                        <label class="form-label" for="note"><?= trh('Note for the selected cages (optional)') ?></label>
                                        <input type="text" class="form-control" id="note" name="note" placeholder="<?= trh('e.g. water and food checked') ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <button type="submit" id="bulkOk" class="btn btn-success w-100"><i class="fas fa-check-double"></i> <?= trh('Mark selected cages OK') ?></button>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
        </form>
    </div>

    <script>
        // A validation reloads this page to refresh all counters and badges.
        // Preserve the technician's place in a long round instead of returning
        // them to the top of the room list after every "All OK".
        const roundForm = document.getElementById('roundForm');
        const roundScrollKey = 'myvivarium.dailyRound.scrollY';
        const savedRoundScroll = sessionStorage.getItem(roundScrollKey);
        if (savedRoundScroll !== null) {
            sessionStorage.removeItem(roundScrollKey);
            requestAnimationFrame(() => window.scrollTo(0, Number(savedRoundScroll)));
        }
        roundForm?.addEventListener('submit', () => {
            sessionStorage.setItem(roundScrollKey, String(window.scrollY));
        });

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
