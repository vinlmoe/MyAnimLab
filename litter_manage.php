<?php

/**
 * Litters (portées) of a breeding cage, from birth to weaning.
 *
 * ?cage_id=  : the cage's litters and the "record a birth" form (date of
 *              birth, optional mating date, dam and sire among the cage's
 *              breeders, pups born alive / dead).
 * ?litter_id=: one litter with its journal and, while nursing, its actions:
 *              record deaths, sexing, weaning, or closing without weaning.
 * Weaning turns the pups into identified animals (line UID, sex, date of
 * birth, parentage sire/dam/litter) placed in new holding cages or existing
 * active cages; pups not weaned must be accounted for. A weaned or closed
 * litter is read-only.
 */

require_once 'session_config.php';
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

$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['name'] ?? null;
$today = date('Y-m-d');
$litterId = (int) ($_GET['litter_id'] ?? $_POST['litter_id'] ?? 0);
$cageId = trim($_GET['cage_id'] ?? $_POST['cage_id'] ?? '');

$litter = null;
if ($litterId) {
    $stmt = $con->prepare("SELECT * FROM litters WHERE id = ?");
    $stmt->bind_param("i", $litterId);
    $stmt->execute();
    $litter = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cageId = $litter['cage_id'] ?? $cageId;
}

$stmt = $con->prepare("SELECT c.*, b.`cross` FROM cages c LEFT JOIN breeding b ON b.cage_id = c.cage_id WHERE c.cage_id = ?");
$stmt->bind_param("s", $cageId);
$stmt->execute();
$cage = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$cage || ($litterId && !$litter)) {
    require 'header.php';
    echo "<div class='container mt-4'><div class='alert alert-danger'>" . trh('Cage or litter not found.') . "</div></div>";
    require 'footer.php';
    exit;
}

// Breeders currently housed in the cage (candidates for dam / sire).
$breeders = $con->prepare("SELECT id, animal_uid, sex, line_id FROM mice WHERE cage_id = ? AND status = 'active' ORDER BY sex, animal_uid");
$breeders->bind_param("s", $cageId);
$breeders->execute();
$breeders = $breeders->get_result()->fetch_all(MYSQLI_ASSOC);
$breederById = array_column($breeders, null, 'id');

$weaningAge = weaning_age_days($con);
$selfUrl = $litter ? "litter_manage.php?litter_id={$litter['id']}" : "litter_manage.php?cage_id=" . rawurlencode($cageId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(tr('CSRF token validation failed'));
    }
    $action = $_POST['action'] ?? '';

    mysqli_begin_transaction($con);
    try {
        if ($action === 'birth') {
            if ($cage['status'] !== 'active') {
                throw new Exception(tr('This cage is retired.'));
            }
            $dob = $_POST['litter_dob'] ?? '';
            $dom = ($_POST['dom'] ?? '') ?: null;
            $alive = (int) ($_POST['pups_alive'] ?? -1);
            $dead = (int) ($_POST['pups_dead'] ?? 0);
            $damId = (int) ($_POST['dam_id'] ?? 0) ?: null;
            $sireId = (int) ($_POST['sire_id'] ?? 0) ?: null;
            $remarks = trim($_POST['remarks'] ?? '') ?: null;

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || $dob > $today) {
                throw new Exception(tr('Give a valid date of birth (not in the future).'));
            }
            if ($dom !== null && $dom > $dob) {
                throw new Exception(tr('The mating date cannot be after the date of birth.'));
            }
            if ($alive < 0 || $dead < 0 || $alive + $dead === 0) {
                throw new Exception(tr('Enter the number of pups born alive and dead (at least one pup).'));
            }
            if (!$damId || ($breederById[$damId]['sex'] ?? '') !== 'female') {
                throw new Exception(tr('Choose the dam among the cage’s females.'));
            }
            if ($sireId && ($breederById[$sireId]['sex'] ?? '') !== 'male') {
                throw new Exception(tr('The sire must be one of the cage’s males.'));
            }
            $expected = date('Y-m-d', strtotime("$dob +$weaningAge days"));
            $ins = $con->prepare("INSERT INTO litters (cage_id, dom, litter_dob, pups_alive, pups_dead, remarks, sire_id, dam_id, status, expected_weaning_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'nursing', ?)");
            $ins->bind_param("sssiisiis", $cageId, $dom, $dob, $alive, $dead, $remarks, $sireId, $damId, $expected);
            $ins->execute();
            $newId = $con->insert_id;
            $ins->close();
            log_litter_event($con, $newId, 'birth', $dob, $alive, null, null, tr('{alive} alive, {dead} dead at birth', ['alive' => $alive, 'dead' => $dead]) . ($remarks ? " — $remarks" : ''), $userId, $userName);
            log_activity($con, 'litter_birth', 'cage', $cageId, tr('Litter #{id}: {alive} alive, {dead} dead', ['id' => $newId, 'alive' => $alive, 'dead' => $dead]), $userId);
            $selfUrl = "litter_manage.php?litter_id=$newId";
            $_SESSION['message'] = tr('Litter recorded: {n} pup(s) alive. Expected weaning on {date}.', ['n' => $alive, 'date' => $expected]);
        } elseif (in_array($action, ['death', 'sexing', 'wean', 'close'], true)) {
            if (!$litter || $litter['status'] !== 'nursing') {
                throw new Exception(tr('This litter is no longer nursing.'));
            }
            $aliveNow = litter_alive_now($con, $litter);
            $date = $_POST['event_date'] ?? $today;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $today || $date < $litter['litter_dob']) {
                throw new Exception(tr('The date must be between the birth ({date}) and today.', ['date' => $litter['litter_dob']]));
            }

            if ($action === 'death') {
                $count = (int) ($_POST['count'] ?? 0);
                $note = trim($_POST['note'] ?? '');
                if ($count < 1 || $count > $aliveNow) {
                    throw new Exception(tr('Number of dead pups must be between 1 and {n}.', ['n' => $aliveNow]));
                }
                if ($note === '') {
                    throw new Exception(tr('Describe the circumstances (found dead, culled, cannibalism...).'));
                }
                log_litter_event($con, $litter['id'], 'death', $date, $count, null, null, $note, $userId, $userName);
                $_SESSION['message'] = tr('{n} pup death(s) recorded. {alive} pup(s) alive.', ['n' => $count, 'alive' => $aliveNow - $count]);
                if ($aliveNow - $count === 0) {
                    $upd = $con->prepare("UPDATE litters SET status = 'closed', closed_reason = ? WHERE id = ?");
                    $allDied = tr('All pups died before weaning');
                    $upd->bind_param("si", $allDied, $litter['id']);
                    $upd->execute();
                    log_litter_event($con, $litter['id'], 'closed', $date, 0, null, null, $allDied, $userId, $userName);
                    $_SESSION['message'] .= ' ' . tr('The litter is closed.');
                }
            } elseif ($action === 'sexing') {
                $males = (int) ($_POST['males'] ?? -1);
                $females = (int) ($_POST['females'] ?? -1);
                if ($males < 0 || $females < 0 || $males + $females !== $aliveNow) {
                    throw new Exception(tr('Males + females must equal the {n} pup(s) alive.', ['n' => $aliveNow]));
                }
                $upd = $con->prepare("UPDATE litters SET pups_male = ?, pups_female = ? WHERE id = ?");
                $upd->bind_param("iii", $males, $females, $litter['id']);
                $upd->execute();
                log_litter_event($con, $litter['id'], 'sexing', $date, $aliveNow, $males, $females, null, $userId, $userName);
                $_SESSION['message'] = tr('Sexing recorded: {males} male(s), {females} female(s).', ['males' => $males, 'females' => $females]);
            } elseif ($action === 'close') {
                $reason = trim($_POST['reason'] ?? '');
                if ($reason === '') {
                    throw new Exception(tr('Give the reason for closing the litter.'));
                }
                if ($aliveNow > 0) {
                    log_litter_event($con, $litter['id'], 'death', $date, $aliveNow, null, null, tr('Not weaned: {reason}', ['reason' => $reason]), $userId, $userName);
                }
                $upd = $con->prepare("UPDATE litters SET status = 'closed', closed_reason = ? WHERE id = ?");
                $upd->bind_param("si", $reason, $litter['id']);
                $upd->execute();
                log_litter_event($con, $litter['id'], 'closed', $date, $aliveNow, null, null, $reason, $userId, $userName);
                $_SESSION['message'] = tr('Litter closed without weaning.');
            } else { // wean
                $lineId = (int) ($_POST['line_id'] ?? 0);
                $lineOk = $con->query("SELECT COUNT(*) FROM animal_lines WHERE id = $lineId")->fetch_row()[0];
                if (!$lineOk) {
                    throw new Exception(tr('Choose the line of the weaned animals.'));
                }
                $groups = [];
                $total = 0;
                foreach ((array) ($_POST['group_count'] ?? []) as $i => $rawCount) {
                    $count = (int) $rawCount;
                    if ($count <= 0) {
                        continue;
                    }
                    $sex = ($_POST['group_sex'][$i] ?? '') === 'female' ? 'female' : (($_POST['group_sex'][$i] ?? '') === 'male' ? 'male' : null);
                    if ($sex === null) {
                        throw new Exception(tr('Give the sex of each weaning group.'));
                    }
                    $dest = $_POST['group_dest'][$i] ?? 'new';
                    if ($dest !== 'new') {
                        $chk = $con->prepare("SELECT 1 FROM cages WHERE cage_id = ? AND status = 'active'");
                        $chk->bind_param("s", $dest);
                        $chk->execute();
                        if ($chk->get_result()->num_rows === 0) {
                            throw new Exception(tr('Destination cage {cage} does not exist or is retired.', ['cage' => $dest]));
                        }
                        $chk->close();
                    }
                    $groups[] = ['sex' => $sex, 'count' => $count, 'dest' => $dest, 'room' => trim($_POST['group_room'][$i] ?? '') ?: null, 'rack' => trim($_POST['group_rack'][$i] ?? '') ?: null];
                    $total += $count;
                }
                if ($total === 0) {
                    throw new Exception(tr('Wean at least one pup (or close the litter if none survived).'));
                }
                if ($total > $aliveNow) {
                    throw new Exception(tr('You are weaning {total} pups but only {alive} are alive.', ['total' => $total, 'alive' => $aliveNow]));
                }
                $remainder = $aliveNow - $total;
                $remainderNote = trim($_POST['remainder_note'] ?? '');
                if ($remainder > 0 && $remainderNote === '') {
                    throw new Exception(tr('{n} pup(s) are not weaned: say what happened to them.', ['n' => $remainder]));
                }

                $usersStmt = $con->prepare("SELECT user_id FROM cage_users WHERE cage_id = ?");
                $usersStmt->bind_param("s", $cageId);
                $usersStmt->execute();
                $cageUsers = array_column($usersStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'user_id');
                $usersStmt->close();

                $parentage = ['litter_id' => (int) $litter['id'], 'sire_id' => $litter['sire_id'] ? (int) $litter['sire_id'] : null, 'dam_id' => $litter['dam_id'] ? (int) $litter['dam_id'] : null];
                $why = tr('Weaned from litter #{id} (cage {cage})', ['id' => $litter['id'], 'cage' => $cageId]);
                $summary = [];
                $males = $females = 0;
                foreach ($groups as $g) {
                    $dest = $g['dest'] === 'new'
                        ? create_holding_cage($con, $cage['pi_name'] ? (int) $cage['pi_name'] : null, $g['room'] ?? $cage['room'], $g['rack'], $litter['litter_dob'], $cageUsers, $why, $userId, $userName)
                        : $g['dest'];
                    $uids = [];
                    for ($k = 0; $k < $g['count']; $k++) {
                        $aid = create_animal($con, $dest, $lineId, $g['sex'], $litter['litter_dob'], $parentage, $why, $userId);
                        $uids[] = $con->query("SELECT animal_uid FROM mice WHERE id = $aid")->fetch_row()[0];
                    }
                    $summary[] = tr('{n} {sex}(s) → {cage}', ['n' => $g['count'], 'sex' => tr($g['sex']), 'cage' => $dest]) . ' (' . reset($uids) . ($g['count'] > 1 ? '…' . end($uids) : '') . ")";
                    $g['sex'] === 'male' ? $males += $g['count'] : $females += $g['count'];
                }
                if ($remainder > 0) {
                    log_litter_event($con, $litter['id'], 'death', $date, $remainder, null, null, tr('Not weaned: {reason}', ['reason' => $remainderNote]), $userId, $userName);
                }
                $upd = $con->prepare("UPDATE litters SET status = 'weaned', weaned_at = ?, weaned_males = ?, weaned_females = ? WHERE id = ?");
                $upd->bind_param("siii", $date, $males, $females, $litter['id']);
                $upd->execute();
                log_litter_event($con, $litter['id'], 'weaning', $date, $total, $males, $females, implode('; ', $summary), $userId, $userName);
                log_activity($con, 'weaning', 'cage', $cageId, tr('Litter #{id}', ['id' => $litter['id']]) . ' : ' . implode('; ', $summary), $userId);
                $_SESSION['message'] = tr('Litter weaned: {summary}.', ['summary' => implode('; ', $summary)]);
            }
        }
        // A breeding cage left without animals nor nursing litter is retired.
        if (in_array($action, ['wean', 'close', 'death'], true) && retire_cage_if_empty($con, $cageId, 'litter ' . ($action === 'wean' ? 'weaned' : 'closed'), $userId, $userName)) {
            $_SESSION['message'] .= " Cage $cageId has no animal left and was retired automatically.";
        }
        mysqli_commit($con);
    } catch (Exception $e) {
        mysqli_rollback($con);
        $_SESSION['message'] = tr('Not saved: {error}', ['error' => $e->getMessage()]);
    }
    header("Location: $selfUrl");
    exit;
}

$litters = $con->prepare("SELECT l.*, d.animal_uid AS dam_uid, s.animal_uid AS sire_uid FROM litters l LEFT JOIN mice d ON d.id = l.dam_id LEFT JOIN mice s ON s.id = l.sire_id WHERE l.cage_id = ? ORDER BY l.litter_dob DESC, l.id DESC");
$litters->bind_param("s", $cageId);
$litters->execute();
$litters = $litters->get_result()->fetch_all(MYSQLI_ASSOC);

if ($litter) {
    $litter = current(array_filter($litters, fn($l) => (int) $l['id'] === (int) $litter['id']));
    $aliveNow = litter_alive_now($con, $litter);
    $events = $con->prepare("SELECT * FROM litter_events WHERE litter_id = ? ORDER BY event_date, id");
    $events->bind_param("i", $litter['id']);
    $events->execute();
    $events = $events->get_result()->fetch_all(MYSQLI_ASSOC);
    $offspring = $con->prepare("SELECT m.id, m.animal_uid, m.sex, m.cage_id, m.status FROM animal_parentage ap JOIN mice m ON m.id = ap.animal_id WHERE ap.litter_id = ? ORDER BY m.animal_uid");
    $offspring->bind_param("i", $litter['id']);
    $offspring->execute();
    $offspring = $offspring->get_result()->fetch_all(MYSQLI_ASSOC);
    $damLine = $litter['dam_id'] ? $con->query("SELECT line_id FROM mice WHERE id = " . (int) $litter['dam_id'])->fetch_row()[0] : null;
    $lines = $con->query("SELECT id, code, name FROM animal_lines ORDER BY code")->fetch_all(MYSQLI_ASSOC);
    $destCages = $con->query("SELECT cage_id, room FROM cages c WHERE status = 'active' AND NOT EXISTS (SELECT 1 FROM breeding b WHERE b.cage_id = c.cage_id) ORDER BY room, cage_id")->fetch_all(MYSQLI_ASSOC);
}

$statusBadge = ['nursing' => 'warning text-dark', 'weaned' => 'success', 'closed' => 'secondary'];
require 'header.php';
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Litters {cage}', ['cage' => $cageId]); ?> | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container {
            max-width: 1100px;
        }
    </style>
</head>

<body>
    <div class="container mt-4 content">
        <a href="bc_view.php?id=<?= rawurlencode($cageId); ?>">&laquo; <?= trh('Breeding cage {cage}', ['cage' => $cageId]); ?></a>

        <?php if (!$litter) : ?>
            <h4 class="mt-2"><?= trh('Litters of cage {cage}', ['cage' => $cageId]); ?> <small class="text-muted"><?= htmlspecialchars($cage['cross'] ?? ''); ?></small></h4>
            <?php include('message.php'); ?>

            <table class="table table-sm align-middle" id="litterList">
                <thead class="table-light">
                    <tr><th>#</th><th><?= trh('Born') ?></th><th><?= trh('Dam / Sire') ?></th><th><?= trh('Born alive / dead') ?></th><th><?= trh('Status') ?></th><th><?= trh('Weaning') ?></th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($litters as $l) :
                        $late = $l['status'] === 'nursing' && $l['expected_weaning_date'] && $l['expected_weaning_date'] < $today; ?>
                        <tr>
                            <td><?= (int) $l['id']; ?></td>
                            <td><?= htmlspecialchars($l['litter_dob'] ?? '?'); ?></td>
                            <td><?= htmlspecialchars(($l['dam_uid'] ?? '?') . ' / ' . ($l['sire_uid'] ?? '?')); ?></td>
                            <td><?= (int) $l['pups_alive']; ?> / <?= (int) $l['pups_dead']; ?></td>
                            <td><span class="badge bg-<?= $statusBadge[$l['status']]; ?>"><?= trh($l['status']); ?></span></td>
                            <td class="<?= $late ? 'text-danger fw-bold' : ''; ?>">
                                <?= $l['status'] === 'weaned' ? trh('weaned {date}', ['date' => $l['weaned_at']]) : ($l['status'] === 'nursing' ? trh('expected {date}', ['date' => $l['expected_weaning_date'] ?? '?']) . ($late ? ' (' . trh('overdue') . ')' : '') : '-'); ?>
                            </td>
                            <td><a href="litter_manage.php?litter_id=<?= (int) $l['id']; ?>" class="btn btn-sm btn-outline-primary"><?= trh('Open') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$litters) : ?><tr><td colspan="7" class="text-muted"><?= trh('No litter yet.') ?></td></tr><?php endif; ?>
                </tbody>
            </table>

            <?php if ($cage['status'] === 'active') : ?>
                <div class="card card-body">
                    <h5><?= trh('Record a birth') ?></h5>
                    <form method="POST" id="birthForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="cage_id" value="<?= htmlspecialchars($cageId); ?>">
                        <div class="row g-2">
                            <div class="col-md-3"><label class="form-label"><?= trh('Date of birth') ?> *</label><input type="date" class="form-control" name="litter_dob" required max="<?= $today; ?>" value="<?= $today; ?>"></div>
                            <div class="col-md-3"><label class="form-label"><?= trh('Mating date') ?></label><input type="date" class="form-control" name="dom" max="<?= $today; ?>"></div>
                            <div class="col-md-3">
                                <label class="form-label"><?= trh('Dam') ?> *</label>
                                <select class="form-select" name="dam_id" required>
                                    <?php foreach ($breeders as $b) if ($b['sex'] === 'female') : ?>
                                        <option value="<?= (int) $b['id']; ?>"><?= htmlspecialchars($b['animal_uid']); ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label"><?= trh('Sire') ?></label>
                                <select class="form-select" name="sire_id">
                                    <option value=""><?= trh('Unknown') ?></option>
                                    <?php foreach ($breeders as $b) if ($b['sex'] === 'male') : ?>
                                        <option value="<?= (int) $b['id']; ?>" <?= count(array_filter($breeders, fn($x) => $x['sex'] === 'male')) === 1 ? 'selected' : ''; ?>><?= htmlspecialchars($b['animal_uid']); ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-3"><label class="form-label"><?= trh('Pups born alive') ?> *</label><input type="number" class="form-control" name="pups_alive" min="0" required></div>
                            <div class="col-md-3"><label class="form-label"><?= trh('Pups born dead') ?></label><input type="number" class="form-control" name="pups_dead" min="0" value="0"></div>
                            <div class="col-md-6"><label class="form-label"><?= trh('Remarks') ?></label><input type="text" class="form-control" name="remarks"></div>
                        </div>
                        <p class="text-muted small mt-2 mb-2"><?= trh('Expected weaning: date of birth + {n} days (Manage Lab).', ['n' => $weaningAge]); ?></p>
                        <button name="action" value="birth" class="btn btn-primary"><?= trh('Record birth') ?></button>
                    </form>
                </div>
            <?php endif; ?>

        <?php else : ?>
            <h4 class="mt-2"><?= trh('Litter #{id}', ['id' => (int) $litter['id']]); ?> — <?= trh('cage {cage}', ['cage' => $cageId]); ?>
                <span class="badge bg-<?= $statusBadge[$litter['status']]; ?>" id="litterStatus"><?= trh($litter['status']); ?></span>
            </h4>
            <?php include('message.php'); ?>

            <div class="card card-body mb-3" id="litterSummary">
                <div class="row">
                    <div class="col-md-4"><strong><?= trh('Born:') ?></strong> <?= htmlspecialchars($litter['litter_dob'] ?? '?'); ?><?= $litter['dom'] ? ' (' . trh('mating {date}', ['date' => $litter['dom']]) . ')' : ''; ?></div>
                    <div class="col-md-4"><strong><?= trh('Dam / Sire:') ?></strong> <?= htmlspecialchars(($litter['dam_uid'] ?? '?') . ' / ' . ($litter['sire_uid'] ?? tr('unknown'))); ?></div>
                    <div class="col-md-4"><strong><?= trh('Pups alive now:') ?></strong> <span id="aliveNow"><?= $aliveNow; ?></span> <small class="text-muted">(<?= trh('born {alive} alive, {dead} dead', ['alive' => (int) $litter['pups_alive'], 'dead' => (int) $litter['pups_dead']]); ?>)</small></div>
                    <div class="col-md-4"><strong><?= trh('Sexing:') ?></strong> <?= $litter['pups_male'] !== null ? (int) $litter['pups_male'] . ' ♂ / ' . (int) $litter['pups_female'] . ' ♀' : trh('not done'); ?></div>
                    <div class="col-md-4"><strong><?= trh('Weaning:') ?></strong>
                        <?php if ($litter['status'] === 'weaned') : ?>
                            <?= trh('done on {date}', ['date' => $litter['weaned_at']]); ?> (<?= (int) $litter['weaned_males']; ?> ♂, <?= (int) $litter['weaned_females']; ?> ♀)
                        <?php elseif ($litter['status'] === 'nursing') : ?>
                            <?= trh('expected {date}', ['date' => $litter['expected_weaning_date'] ?? '?']); ?>
                            <?php if (($litter['expected_weaning_date'] ?? '9999') < $today) : ?><span class="badge bg-danger"><?= trh('overdue') ?></span><?php endif; ?>
                        <?php else : ?>
                            <?= trh('closed') ?> — <?= htmlspecialchars($litter['closed_reason'] ?? ''); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($litter['status'] === 'nursing') : ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="card card-body h-100">
                            <h6><?= trh('Record pup deaths') ?></h6>
                            <form method="POST" class="row g-2" id="deathForm">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="litter_id" value="<?= (int) $litter['id']; ?>">
                                <div class="col-4"><input type="date" class="form-control" name="event_date" value="<?= $today; ?>" max="<?= $today; ?>" min="<?= htmlspecialchars($litter['litter_dob']); ?>"></div>
                                <div class="col-3"><input type="number" class="form-control" name="count" min="1" max="<?= $aliveNow; ?>" placeholder="<?= trh('Nb') ?>" required></div>
                                <div class="col-5"><input type="text" class="form-control" name="note" placeholder="<?= trh('Found dead, culled...') ?>" required></div>
                                <div class="col-12"><button name="action" value="death" class="btn btn-outline-danger btn-sm"><?= trh('Record deaths') ?></button></div>
                            </form>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card card-body h-100">
                            <h6><?= trh('Sexing') ?></h6>
                            <form method="POST" class="row g-2" id="sexingForm">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="litter_id" value="<?= (int) $litter['id']; ?>">
                                <div class="col-4"><input type="date" class="form-control" name="event_date" value="<?= $today; ?>" max="<?= $today; ?>" min="<?= htmlspecialchars($litter['litter_dob']); ?>"></div>
                                <div class="col-4"><input type="number" class="form-control" name="males" min="0" placeholder="<?= trh('Males') ?> ♂" required></div>
                                <div class="col-4"><input type="number" class="form-control" name="females" min="0" placeholder="<?= trh('Females') ?> ♀" required></div>
                                <div class="col-12"><button name="action" value="sexing" class="btn btn-outline-primary btn-sm"><?= trh('Record sexing') ?></button> <small class="text-muted"><?= trh('must total {n}', ['n' => $aliveNow]); ?></small></div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="card card-body mb-3 border-success">
                    <h5><?= trh('Weaning') ?></h5>
                    <p class="text-muted small"><?= trh('Each pup becomes an identified animal (line number, sex, date of birth {date}, parentage). Put them in new holding cages or existing ones.', ['date' => $litter['litter_dob']]); ?></p>
                    <form method="POST" id="weanForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="litter_id" value="<?= (int) $litter['id']; ?>">
                        <div class="row g-2 mb-2">
                            <div class="col-md-3"><label class="form-label"><?= trh('Weaning date') ?></label><input type="date" class="form-control" name="event_date" value="<?= $today; ?>" max="<?= $today; ?>" min="<?= htmlspecialchars($litter['litter_dob']); ?>"></div>
                            <div class="col-md-4">
                                <label class="form-label"><?= trh('Line') ?></label>
                                <select class="form-select" name="line_id">
                                    <?php foreach ($lines as $ln) : ?>
                                        <option value="<?= (int) $ln['id']; ?>" <?= (int) $ln['id'] === (int) $damLine ? 'selected' : ''; ?>><?= htmlspecialchars($ln['code'] . ' - ' . $ln['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <table class="table table-sm align-middle" id="weanGroups">
                            <thead><tr><th><?= trh('Sex') ?></th><th><?= trh('Count') ?></th><th><?= trh('Destination') ?></th><th><?= trh('Room') ?></th><th><?= trh('Rack') ?></th><th></th></tr></thead>
                            <tbody>
                                <?php foreach (['male' => $litter['pups_male'], 'female' => $litter['pups_female']] as $sex => $n) : ?>
                                    <tr class="wean-group">
                                        <td><select class="form-select form-select-sm" name="group_sex[]"><option value="male" <?= $sex === 'male' ? 'selected' : ''; ?>><?= trh('Male') ?></option><option value="female" <?= $sex === 'female' ? 'selected' : ''; ?>><?= trh('Female') ?></option></select></td>
                                        <td><input type="number" class="form-control form-control-sm group-count" name="group_count[]" min="0" value="<?= (int) $n; ?>" style="width: 80px;"></td>
                                        <td>
                                            <select class="form-select form-select-sm group-dest" name="group_dest[]">
                                                <option value="new"><?= trh('New holding cage') ?></option>
                                                <?php foreach ($destCages as $dc) : ?>
                                                    <option value="<?= htmlspecialchars($dc['cage_id']); ?>"><?= htmlspecialchars($dc['cage_id'] . ($dc['room'] ? ' (' . tr('room {room}', ['room' => $dc['room']]) . ')' : '')); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td><input type="text" class="form-control form-control-sm group-room" name="group_room[]" value="<?= htmlspecialchars($cage['room'] ?? ''); ?>" style="width: 90px;"></td>
                                        <td><input type="text" class="form-control form-control-sm group-rack" name="group_rack[]" style="width: 90px;"></td>
                                        <td><button type="button" class="btn btn-sm btn-outline-danger remove-group">&times;</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <button type="button" class="btn btn-sm btn-outline-secondary mb-2" id="addGroup">+ <?= trh('Add a group (split into several cages)') ?></button>
                        <div class="mb-2"><strong><?= tr('Weaned: {total} / {alive} alive', ['total' => '<span id="weanTotal">0</span>', 'alive' => $aliveNow]); ?></strong> <span id="weanRemainderInfo" class="text-danger"></span></div>
                        <div class="mb-2" id="remainderBox">
                            <input type="text" class="form-control" name="remainder_note" placeholder="<?= trh('Pups not weaned: what happened (died, culled...)') ?>">
                        </div>
                        <button name="action" value="wean" class="btn btn-success"><?= trh('Wean the litter') ?></button>
                    </form>
                </div>

                <details class="mb-3">
                    <summary><?= trh('Close the litter without weaning') ?></summary>
                    <form method="POST" class="row g-2 mt-2">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="litter_id" value="<?= (int) $litter['id']; ?>">
                        <div class="col-md-3"><input type="date" class="form-control" name="event_date" value="<?= $today; ?>" max="<?= $today; ?>" min="<?= htmlspecialchars($litter['litter_dob']); ?>"></div>
                        <div class="col-md-6"><input type="text" class="form-control" name="reason" placeholder="<?= trh('Reason (litter lost, culled...)') ?>" required></div>
                        <div class="col-md-3"><button name="action" value="close" class="btn btn-outline-secondary w-100" onclick="return confirm(<?= htmlspecialchars(trj('Close this litter? Remaining pups are recorded as not weaned.')) ?>);"><?= trh('Close litter') ?></button></div>
                    </form>
                </details>
            <?php endif; ?>

            <?php if ($offspring) : ?>
                <h5><?= trh('Weaned animals') ?></h5>
                <table class="table table-sm" id="offspring">
                    <thead><tr><th><?= trh('Animal') ?></th><th><?= trh('Sex') ?></th><th><?= trh('Cage') ?></th><th><?= trh('Status') ?></th></tr></thead>
                    <?php foreach ($offspring as $o) : ?>
                        <tr>
                            <td><a href="animal_view.php?id=<?= (int) $o['id']; ?>"><?= htmlspecialchars($o['animal_uid']); ?></a></td>
                            <td><?= trh((string) $o['sex']); ?></td>
                            <td><a href="hc_view.php?id=<?= rawurlencode($o['cage_id']); ?>"><?= htmlspecialchars($o['cage_id']); ?></a></td>
                            <td><?= trh(str_replace('_', ' ', $o['status'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <h5><?= trh('Journal') ?></h5>
            <table class="table table-sm" id="litterJournal">
                <thead><tr><th><?= trh('Date') ?></th><th><?= trh('Event') ?></th><th><?= trh('Count') ?></th><th><?= trh('Details') ?></th><th><?= trh('By') ?></th></tr></thead>
                <?php foreach ($events as $ev) : ?>
                    <tr>
                        <td><?= htmlspecialchars($ev['event_date']); ?></td>
                        <td><?= trh($ev['event']); ?></td>
                        <td><?= $ev['count'] !== null ? (int) $ev['count'] : ''; ?><?= $ev['males'] !== null ? " ({$ev['males']} ♂ / {$ev['females']} ♀)" : ''; ?></td>
                        <td><?= htmlspecialchars($ev['note'] ?? ''); ?></td>
                        <td><?= htmlspecialchars($ev['user_name'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <script>
        // Weaning groups: add/remove rows, room/rack only for a new cage, live total.
        (function() {
            const body = document.querySelector('#weanGroups tbody');
            if (!body) return;
            const alive = <?= (int) ($aliveNow ?? 0); ?>;
            const sync = () => {
                let total = 0;
                body.querySelectorAll('.wean-group').forEach(row => {
                    total += Number(row.querySelector('.group-count').value || 0);
                    const isNew = row.querySelector('.group-dest').value === 'new';
                    row.querySelector('.group-room').style.visibility = isNew ? 'visible' : 'hidden';
                    row.querySelector('.group-rack').style.visibility = isNew ? 'visible' : 'hidden';
                });
                document.getElementById('weanTotal').textContent = total;
                const rest = alive - total;
                document.getElementById('weanRemainderInfo').textContent = rest > 0 ? <?= trj('{n} pup(s) not weaned: explain below') ?>.replace('{n}', rest) : (rest < 0 ? <?= trj('more than the pups alive!') ?> : '');
                document.getElementById('remainderBox').style.display = rest > 0 ? '' : 'none';
            };
            const wire = row => {
                row.querySelectorAll('input, select').forEach(el => el.addEventListener('input', sync));
                row.querySelector('.remove-group').addEventListener('click', () => { row.remove(); sync(); });
            };
            body.querySelectorAll('.wean-group').forEach(wire);
            document.getElementById('addGroup').addEventListener('click', () => {
                const row = body.querySelector('.wean-group').cloneNode(true);
                row.querySelector('.group-count').value = 0;
                body.appendChild(row);
                wire(row);
                sync();
            });
            sync();
        })();
    </script>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
