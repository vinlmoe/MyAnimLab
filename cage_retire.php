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
    $_SESSION['message'] = "Cage not found.";
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
    $_SESSION['message'] = 'Access denied. Only the assigned user or an admin can retire this cage.';
    header("Location: $dashboard");
    exit;
}

if ($cage['status'] !== 'active') {
    $_SESSION['message'] = "Cage $cageId is already retired.";
    header("Location: $dashboard");
    exit;
}

// Unweaned pups are not individual animals yet: wean or close their litter first.
if (cage_nursing_litters($con, $cageId) > 0) {
    $_SESSION['message'] = "Cage $cageId has a litter still nursing: wean it or close it before retiring the cage.";
    header("Location: litter_manage.php?cage_id=" . rawurlencode($cageId));
    exit;
}

$animalsStmt = $con->prepare("SELECT id, animal_uid, mouse_id, sex, distinctive_mark FROM mice WHERE cage_id = ? AND status = 'active' ORDER BY animal_uid");
$animalsStmt->bind_param("s", $cageId);
$animalsStmt->execute();
$animals = $animalsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$animalsStmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    $reason = trim($_POST['reason'] ?? '');
    $date = $_POST['exit_date'] ?: date('Y-m-d');
    $fates = $_POST['fate'] ?? [];
    $targets = $_POST['to_cage'] ?? [];
    $destinations = $_POST['destination'] ?? [];

    mysqli_begin_transaction($con);
    try {
        if ($reason === '') {
            throw new Exception("Please give the reason for retiring the cage.");
        }
        $moved = $exited = 0;
        foreach ($animals as $a) {
            $aid = (int) $a['id'];
            $label = $a['animal_uid'] ?? $a['mouse_id'] ?? "#$aid";
            $fate = $fates[$aid] ?? '';
            if ($fate === 'move') {
                $to = trim($targets[$aid] ?? '');
                if ($to === '') {
                    throw new Exception("Choose the destination cage for $label.");
                }
                move_animal($con, $aid, $to, "Cage $cageId retired: $reason", $currentUserId, $currentUserName, false);
                $moved++;
            } elseif (isset(ANIMAL_EXIT_CODES[$fate])) {
                exit_animal($con, $aid, $fate, $date, "Cage $cageId retired: $reason", $destinations[$aid] ?? null, $currentUserId, $currentUserName, false);
                $exited++;
            } else {
                throw new Exception("Give a fate to $label (move to another cage or an exit code).");
            }
        }

        // Fails in the database if any living animal is still in the cage.
        retire_cage($con, $cageId, $reason, $currentUserId, $currentUserName);

        mysqli_commit($con);
        $_SESSION['message'] = "Cage $cageId retired." . ($animals ? " $moved animal(s) moved, $exited exit(s) recorded." : '');
        header("Location: $dashboard");
        exit;
    } catch (Exception $e) {
        mysqli_rollback($con);
        $_SESSION['message'] = "Cage not retired: " . $e->getMessage();
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
<html lang="en">

<head>
    <title>Retire Cage <?= htmlspecialchars($cageId); ?> | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container {
            max-width: 1000px;
        }
    </style>
</head>

<body>
    <div class="container mt-4 content">
        <h4>Retire cage <?= htmlspecialchars($cageId); ?></h4>
        <p class="text-muted">
            <?= htmlspecialchars($cage['kind']); ?> cage<?= $cage['room'] ? ' · Room ' . htmlspecialchars($cage['room']) : ''; ?>.
            Every living animal must stay in an active cage: give each animal of this cage a fate — move it to another cage, or record its exit with an exit code. The cage is retired only when all animals are handled.
        </p>

        <?php include('message.php'); ?>

        <form method="POST" id="retireForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="cage_id" value="<?= htmlspecialchars($cageId); ?>">

            <?php if ($animals) : ?>
                <div class="card card-body bg-light mb-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label" for="allFate">Same fate for all animals</label>
                            <select class="form-select" id="allFate">
                                <option value="">—</option>
                                <option value="move">Move to another cage</option>
                                <optgroup label="Exit (motif de sortie)">
                                    <?php foreach (ANIMAL_EXIT_CODES as $key => [$text]) : ?>
                                        <option value="<?= $key; ?>"><?= htmlspecialchars($text); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="allCage">Destination cage (move)</label>
                            <select class="form-select" id="allCage">
                                <option value="">—</option>
                                <?php foreach ($otherCages as $oc) : ?>
                                    <option value="<?= htmlspecialchars($oc['cage_id']); ?>"><?= htmlspecialchars($oc['cage_id'] . ($oc['room'] ? " (room {$oc['room']})" : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="allDest">Destination facility (export)</label>
                            <input type="text" class="form-control" id="allDest">
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="applyAll">Apply to all animals</button>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Animal</th>
                                <th>Fate *</th>
                                <th>Destination cage / facility</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($animals as $a) :
                                $aid = (int) $a['id'];
                                $label = $a['animal_uid'] ?? $a['mouse_id'] ?? "#$aid"; ?>
                                <tr class="animal-row" data-animal="<?= $aid; ?>">
                                    <td><a href="animal_view.php?id=<?= $aid; ?>"><?= htmlspecialchars($label); ?></a> <?= $a['sex'] === 'male' ? '♂' : ($a['sex'] === 'female' ? '♀' : ''); ?><?php if (!empty($a['distinctive_mark'])) : ?><small class="text-muted mark" title="Distinctive mark"> [<?= htmlspecialchars($a['distinctive_mark']); ?>]</small><?php endif; ?></td>
                                    <td>
                                        <select class="form-select form-select-sm fate" name="fate[<?= $aid; ?>]" required>
                                            <option value="" selected disabled>Choose…</option>
                                            <option value="move">Move to another cage</option>
                                            <optgroup label="Exit (motif de sortie)">
                                                <?php foreach (ANIMAL_EXIT_CODES as $key => [$text]) : ?>
                                                    <option value="<?= $key; ?>"><?= htmlspecialchars($text); ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        </select>
                                    </td>
                                    <td>
                                        <select class="form-select form-select-sm to-cage" name="to_cage[<?= $aid; ?>]">
                                            <option value="">Destination cage…</option>
                                            <?php foreach ($otherCages as $oc) : ?>
                                                <option value="<?= htmlspecialchars($oc['cage_id']); ?>"><?= htmlspecialchars($oc['cage_id'] . ($oc['room'] ? " (room {$oc['room']})" : '')); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text" class="form-control form-control-sm destination" name="destination[<?= $aid; ?>]" placeholder="Destination facility">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$otherCages) : ?>
                    <div class="alert alert-info">No other active cage: animals can only exit, or <a href="hc_addn.php">create a new cage</a> first.</div>
                <?php endif; ?>
            <?php else : ?>
                <div class="alert alert-info">This cage has no living animal.</div>
            <?php endif; ?>

            <div class="row g-2">
                <div class="col-md-8 mb-3">
                    <label class="form-label" for="reason">Reason for retiring the cage *</label>
                    <input type="text" class="form-control" id="reason" name="reason" required placeholder="e.g. end of study, cage regrouping">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label" for="exit_date">Date of exits</label>
                    <input type="date" class="form-control" id="exit_date" name="exit_date" value="<?= date('Y-m-d'); ?>" max="<?= date('Y-m-d'); ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-danger" onclick="return confirm('Retire this cage and apply the chosen fates?');">Retire cage</button>
            <a href="<?= $cage['kind'] === 'breeding' ? 'bc_view.php' : 'hc_view.php'; ?>?id=<?= rawurlencode($cageId); ?>" class="btn btn-secondary">Cancel</a>
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
