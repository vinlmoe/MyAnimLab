<?php

/**
 * Retire a cage (take it out of service).
 *
 * Every living animal must be in an active cage, so a cage can only be
 * retired once each of its living animals has been given a fate: moved to
 * another cage, or an exit with its exit code (mise à mort, euthanasie, point
 * limite, retrouvé mort, export...).
 * The whole operation is atomic: if any animal's fate fails, nothing changes.
 * Replaces the old dashboard deletion (hc_drop.php / bc_drop.php), which
 * silently archived the animals.
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require_once 'training_lib.php';

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

$currentUserId = $_SESSION['user_id'] ?? null;
$currentUserName = $_SESSION['name'] ?? null;
$cageId = trim($_GET['id'] ?? $_POST['cage_id'] ?? '');

$stmt = $con->prepare("SELECT cage_id, status, room, rack, IF(EXISTS (SELECT 1 FROM breeding b WHERE b.cage_id = c.cage_id), 'breeding', 'holding') AS kind FROM cages c WHERE cage_id = ?");
$stmt->bind_param("s", $cageId);
$stmt->execute();
$cage = $stmt->get_result()->fetch_assoc();
$stmt->close();

$dashboard = ($cage['kind'] ?? 'holding') === 'breeding' ? 'bc_dash.php' : 'hc_dash.php';

if (!$cage) {
    $_SESSION['message'] = tr('Cage not found.');
    header("Location: $dashboard");
    exit;
}

// Only an admin or a user assigned to the cage may retire it.
$assigned = $con->prepare("SELECT 1 FROM cage_users WHERE cage_id = ? AND user_id = ?");
$assigned->bind_param("si", $cageId, $currentUserId);
$assigned->execute();
$isAssigned = $assigned->get_result()->num_rows > 0;
$assigned->close();
if (($_SESSION['role'] ?? '') !== 'admin' && !$isAssigned) {
    $_SESSION['message'] = tr('Access denied. Only the assigned user or an admin can retire this cage.');
    header("Location: $dashboard");
    exit;
}

if ($cage['status'] !== 'active') {
    $_SESSION['message'] = tr('Cage {cage} is already retired.', ['cage' => $cageId]);
    header("Location: $dashboard");
    exit;
}

// Unweaned pups are not individual animals yet: wean or close their litter first.
if (cage_nursing_litters($con, $cageId) > 0) {
    $_SESSION['message'] = tr('Cage {cage} has a litter still nursing: wean it or close it before retiring the cage.', ['cage' => $cageId]);
    header("Location: litter_manage.php?cage_id=" . rawurlencode($cageId));
    exit;
}

$animalsStmt = $con->prepare("SELECT id, animal_uid, mouse_id, sex, distinctive_mark FROM mice WHERE cage_id = ? AND status = 'active' ORDER BY animal_uid");
$animalsStmt->bind_param("s", $cageId);
$animalsStmt->execute();
$animals = $animalsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$animalsStmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        die(tr('CSRF token validation failed'));
    }

    $reason = trim($_POST['reason'] ?? '');
    $date = $_POST['exit_date'] ?: date('Y-m-d');
    $fates = $_POST['fate'] ?? [];
    $targets = $_POST['to_cage'] ?? [];
    $destinations = $_POST['destination'] ?? [];

    mysqli_begin_transaction($con);
    try {
        if ($reason === '') {
            throw new Exception(tr('Please give the reason for retiring the cage.'));
        }
        // Killing an animal: a person not competent alone names the supervisor present.
        $exitReason = tr('Cage {cage} retired: {reason}', ['cage' => $cageId, 'reason' => $reason]);
        $killReason = $exitReason;
        if (array_intersect(array_map('strval', $fates), TRAINING_KILLING_EXIT_CODES) && $currentUserId) {
            $supervisor = training_posted_supervisor($con, (int) $currentUserId, 'euthanasia', (string) ($_POST['act_supervisor'] ?? ''), (string) ($_POST['act_supervisor_other'] ?? ''));
            if ($supervisor !== null) {
                $killReason .= ' — ' . tr('under the supervision of {name}', ['name' => $supervisor]);
            }
        }
        $moved = $exited = 0;
        foreach ($animals as $a) {
            $aid = (int) $a['id'];
            $label = $a['animal_uid'] ?? $a['mouse_id'] ?? "#$aid";
            $fate = $fates[$aid] ?? '';
            if ($fate === 'move') {
                $to = trim($targets[$aid] ?? '');
                if ($to === '') {
                    throw new Exception(tr('Choose the destination cage for {animal}.', ['animal' => $label]));
                }
                move_animal($con, $aid, $to, tr('Cage {cage} retired: {reason}', ['cage' => $cageId, 'reason' => $reason]), $currentUserId, $currentUserName, false);
                $moved++;
            } elseif (isset(ANIMAL_EXIT_CODES[$fate])) {
                exit_animal($con, $aid, $fate, $date, in_array($fate, TRAINING_KILLING_EXIT_CODES, true) ? $killReason : $exitReason, $destinations[$aid] ?? null, $currentUserId, $currentUserName, false);
                $exited++;
            } else {
                throw new Exception(tr('Give a fate to {animal} (move to another cage or an exit code).', ['animal' => $label]));
            }
        }

        // Fails in the database if any living animal is still in the cage.
        retire_cage($con, $cageId, $reason, $currentUserId, $currentUserName);

        mysqli_commit($con);
        $_SESSION['message'] = tr('Cage {cage} retired.', ['cage' => $cageId]) . ($animals ? ' ' . tr('{moved} animal(s) moved, {exited} exit(s) recorded.', ['moved' => $moved, 'exited' => $exited]) : '');
        header("Location: $dashboard");
        exit;
    } catch (Exception $e) {
        mysqli_rollback($con);
        $_SESSION['message'] = tr('Cage not retired: {error}', ['error' => error_message($e)]);
        header("Location: cage_retire.php?id=" . rawurlencode($cageId));
        exit;
    }
}

$otherCages = $con->prepare("SELECT cage_id, room, rack FROM cages WHERE status = 'active' AND cage_id <> ? ORDER BY room, rack, cage_id");
$otherCages->bind_param("s", $cageId);
$otherCages->execute();
$otherCages = $otherCages->get_result()->fetch_all(MYSQLI_ASSOC);

require 'header.php';
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Retire cage {cage}', ['cage' => $cageId]); ?> | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container {
            max-width: 1000px;
        }
    </style>
</head>

<body>
    <div class="container mt-4 content">
        <h4><?= trh('Retire cage {cage}', ['cage' => $cageId]); ?></h4>
        <p class="text-muted">
            <?= trh(ucfirst($cage['kind']) . ' cage'); ?><?= $cage['room'] ? ' · ' . trh('Room {room}', ['room' => $cage['room']]) : ''; ?>.
            <?= trh('Every living animal must stay in an active cage: give each animal of this cage a fate — move it to another cage, or record its exit with an exit code. The cage is retired only when all animals are handled.') ?>
        </p>

        <?php include('message.php'); ?>
        <?= training_procedure_banner($con, 'killing') ?>

        <form method="POST" id="retireForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="cage_id" value="<?= htmlspecialchars($cageId); ?>">

            <?php if ($animals) : ?>
                <div class="card card-body bg-light mb-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label" for="allFate"><?= trh('Same fate for all animals') ?></label>
                            <select class="form-select" id="allFate">
                                <option value="">—</option>
                                <option value="move"><?= trh('Move to another cage') ?></option>
                                <optgroup label="<?= trh('Exit (exit code)') ?>">
                                    <?php foreach (ANIMAL_EXIT_CODES as $key => [$text]) : ?>
                                        <option value="<?= $key; ?>"><?= htmlspecialchars($text); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="allCage"><?= trh('Destination cage (move)') ?></label>
                            <select class="form-select" id="allCage">
                                <option value="">—</option>
                                <?php foreach ($otherCages as $oc) : ?>
                                    <option value="<?= htmlspecialchars($oc['cage_id']); ?>"><?= htmlspecialchars($oc['cage_id'] . ($oc['room'] ? ' (' . tr('room {room}', ['room' => $oc['room']]) . ')' : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="allDest"><?= trh('Destination facility (export)') ?></label>
                            <input type="text" class="form-control" id="allDest">
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="applyAll"><?= trh('Apply to all animals') ?></button>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th><?= trh('Animal') ?></th>
                                <th><?= trh('Fate') ?> *</th>
                                <th><?= trh('Destination cage / facility') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($animals as $a) :
                                $aid = (int) $a['id'];
                                $label = $a['animal_uid'] ?? $a['mouse_id'] ?? "#$aid"; ?>
                                <tr class="animal-row" data-animal="<?= $aid; ?>">
                                    <td><a href="animal_view.php?id=<?= $aid; ?>"><?= htmlspecialchars($label); ?></a> <?= $a['sex'] === 'male' ? '♂' : ($a['sex'] === 'female' ? '♀' : ''); ?><?php if (!empty($a['distinctive_mark'])) : ?><small class="text-muted mark" title="<?= trh('Distinctive mark') ?>"> [<?= htmlspecialchars($a['distinctive_mark']); ?>]</small><?php endif; ?></td>
                                    <td>
                                        <select class="form-select form-select-sm fate" name="fate[<?= $aid; ?>]" required>
                                            <option value="" selected disabled><?= trh('Choose…') ?></option>
                                            <option value="move"><?= trh('Move to another cage') ?></option>
                                            <optgroup label="<?= trh('Exit (exit code)') ?>">
                                                <?php foreach (ANIMAL_EXIT_CODES as $key => [$text]) : ?>
                                                    <option value="<?= $key; ?>"><?= htmlspecialchars($text); ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        </select>
                                    </td>
                                    <td>
                                        <select class="form-select form-select-sm to-cage" name="to_cage[<?= $aid; ?>]">
                                            <option value=""><?= trh('Destination cage…') ?></option>
                                            <?php foreach ($otherCages as $oc) : ?>
                                                <option value="<?= htmlspecialchars($oc['cage_id']); ?>"><?= htmlspecialchars($oc['cage_id'] . ($oc['room'] ? ' (' . tr('room {room}', ['room' => $oc['room']]) . ')' : '')); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text" class="form-control form-control-sm destination" name="destination[<?= $aid; ?>]" placeholder="<?= trh('Destination facility') ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$otherCages) : ?>
                    <div class="alert alert-info"><?= tr('No other active cage: animals can only exit, or {link}create a new cage{/link} first.', ['link' => '<a href="hc_addn.php">', '/link' => '</a>']) ?></div>
                <?php endif; ?>
            <?php else : ?>
                <div class="alert alert-info"><?= trh('This cage has no living animal.') ?></div>
            <?php endif; ?>

            <div class="row g-2">
                <div class="col-md-8 mb-3">
                    <label class="form-label" for="reason"><?= trh('Reason for retiring the cage') ?> *</label>
                    <input type="text" class="form-control" id="reason" name="reason" required placeholder="<?= trh('e.g. end of study, cage regrouping') ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label" for="exit_date"><?= trh('Date of exits') ?></label>
                    <input type="date" class="form-control" id="exit_date" name="exit_date" value="<?= date('Y-m-d'); ?>" max="<?= date('Y-m-d'); ?>">
                </div>
            </div>

            <?= training_supervisor_field($con, 'Killing', 'euthanasia', true) ?>
            <button type="submit" class="btn btn-danger" onclick="return confirm(<?= htmlspecialchars(trj('Retire this cage and apply the chosen fates?')) ?>);"><?= trh('Retire cage') ?></button>
            <a href="<?= $cage['kind'] === 'breeding' ? 'bc_view.php' : 'hc_view.php'; ?>?id=<?= rawurlencode($cageId); ?>" class="btn btn-secondary"><?= trh('Cancel') ?></a>
        </form>
    </div>

    <script>
        // Show only the field relevant to each animal's fate.
        function syncRow(row) {
            const fate = row.querySelector('.fate').value;
            const cage = row.querySelector('.to-cage');
            const dest = row.querySelector('.destination');
            cage.style.display = fate === 'move' ? '' : 'none';
            cage.required = fate === 'move';
            dest.style.display = fate === 'export' ? '' : 'none';
            dest.required = fate === 'export';
            syncSupervisor();
        }
        // The supervisor is only asked for when an animal is killed.
        function syncSupervisor() {
            const box = document.getElementById('supervisorBoxKilling');
            if (!box) return;
            const killing = <?= json_encode(TRAINING_KILLING_EXIT_CODES) ?>;
            box.hidden = ![...document.querySelectorAll('.fate')].some(f => killing.includes(f.value));
            box.dispatchEvent(new Event('change', { bubbles: true }));
        }
        document.querySelectorAll('.animal-row').forEach(row => {
            row.querySelector('.fate').addEventListener('change', () => syncRow(row));
            syncRow(row);
        });
        const applyAll = document.getElementById('applyAll');
        if (applyAll) applyAll.addEventListener('click', () => {
            const fate = document.getElementById('allFate').value;
            document.querySelectorAll('.animal-row').forEach(row => {
                if (fate) row.querySelector('.fate').value = fate;
                if (document.getElementById('allCage').value) row.querySelector('.to-cage').value = document.getElementById('allCage').value;
                if (document.getElementById('allDest').value) row.querySelector('.destination').value = document.getElementById('allDest').value;
                syncRow(row);
            });
        });
    </script>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
