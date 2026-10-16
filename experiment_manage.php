<?php

/**
 * Manage Experiments for a given Protocol: create experiments and enroll
 * animals into them (with a baseline weight used for weight-loss threshold
 * calculations during daily follow-up).
 */

session_start();
require 'dbcon.php';
require 'animal_lib.php';
require 'ethics_lib.php';

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$currentUserId = $_SESSION['user_id'] ?? null;
$protocolId = (int) ($_GET['protocol_id'] ?? $_POST['protocol_id'] ?? 0);

$protocolStmt = $con->prepare("SELECT p.*, t.trial_number, t.title AS trial_title, t.iacuc_id, t.status AS trial_status, t.start_date AS trial_start, t.end_date AS trial_end FROM protocols p JOIN trials t ON t.id = p.trial_id WHERE p.id = ?");
$protocolStmt->bind_param("i", $protocolId);
$protocolStmt->execute();
$protocol = $protocolStmt->get_result()->fetch_assoc();

if (!$protocol) {
    require 'header.php';
    echo "<div class='container mt-4'><div class='alert alert-danger'>" . trh('Protocol not found.') . "</div></div>";
    require 'footer.php';
    exit;
}

$trial = ['trial_number' => $protocol['trial_number'], 'start_date' => $protocol['trial_start'], 'end_date' => $protocol['trial_end']];
$auth = load_authorization($con, $protocol['iacuc_id']);
$enrollmentBlock = trial_enrollment_block($trial, $auth);
// Experiments must fit in the protocol period, or in the trial period when the protocol has no dates.
$experimentFrame = ($protocol['start_date'] || $protocol['end_date'])
    ? ['protocol_number' => $protocol['protocol_number'], 'start_date' => $protocol['start_date'], 'end_date' => $protocol['end_date']]
    : $trial;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(tr('CSRF token validation failed'));
    }

    if (isset($_POST['add_experiment'])) {
        $title = trim($_POST['title']);
        $startDate = $_POST['start_date'] ?: null;
        $endDate = $_POST['end_date'] ?: null;
        $periodError = protocol_period_error($experimentFrame, $startDate, $endDate);
        if ($title === '') {
            $_SESSION['message'] = 'Le titre de l’expérience est obligatoire.';
        } elseif ($periodError) {
            $_SESSION['message'] = $periodError;
        } else {
            mysqli_begin_transaction($con);
            try {
                $experimentNumber = allocate_experiment_number($con);
                $stmt = $con->prepare("INSERT INTO experiments (protocol_id, experiment_number, title, start_date, end_date) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("issss", $protocolId, $experimentNumber, $title, $startDate, $endDate);
                $stmt->execute();
                $stmt->close();
                mysqli_commit($con);
                $_SESSION['message'] = "Expérience $experimentNumber créée.";
            } catch (Exception $e) {
                mysqli_rollback($con);
                $_SESSION['message'] = 'Impossible de créer l’expérience : ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['enroll_animals'])) {
        $experimentId = (int) $_POST['experiment_id'];
        $baselineWeight = $_POST['baseline_weight_g'] !== '' ? (float) $_POST['baseline_weight_g'] : null;
        $animalIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['animal_ids'] ?? [])))));
        $validExperiment = $con->prepare('SELECT id FROM experiments WHERE id = ? AND protocol_id = ?');
        $validExperiment->bind_param('ii', $experimentId, $protocolId);
        $validExperiment->execute();
        $valid = $validExperiment->get_result()->num_rows === 1;
        $validExperiment->close();
        $added = $skipped = 0;
        if ($enrollmentBlock) {
            $_SESSION['message'] = $enrollmentBlock;
        } elseif (!$valid || !$animalIds) {
            $_SESSION['message'] = 'Sélectionnez au moins un animal et une expérience valide.';
        } else {
            $activeCheck = $con->prepare("SELECT id FROM mice WHERE id = ? AND status = 'active'");
            $insert = $con->prepare("INSERT INTO experiment_animals (experiment_id, animal_id, baseline_weight_g) VALUES (?, ?, ?)");
            foreach ($animalIds as $animalId) {
                $activeCheck->bind_param('i', $animalId);
                $activeCheck->execute();
                if ($activeCheck->get_result()->num_rows !== 1) { $skipped++; continue; }
                try {
                    $insert->bind_param('iid', $experimentId, $animalId, $baselineWeight);
                    $insert->execute();
                    log_animal_event($con, $animalId, 'experiment_start', ['experiment_id' => $experimentId], $currentUserId);
                    $added++;
                } catch (mysqli_sql_exception $e) {
                    if ($e->getCode() !== 1062) { throw $e; }
                    $skipped++;
                }
            }
            $activeCheck->close();
            $insert->close();
            // Their cages become experimental cages (same cage number).
            $newExperimentalCages = mark_cages_experimental_for_animals($con, $animalIds, $currentUserId);
            $_SESSION['message'] = "$added animal(s) ajouté(s) à l’expérience" . ($skipped ? "; $skipped déjà inclus ou inactif(s)." : '.')
                . ($newExperimentalCages ? ' Cage(s) passée(s) en expérimentation : ' . implode(', ', $newExperimentalCages) . '.' : '');
        }
    } elseif (isset($_POST['end_enrollment'])) {
        $enrollmentId = (int) $_POST['experiment_animal_id'];
        $reason = trim($_POST['removal_reason'] ?? '') ?: 'Fin de participation à l’expérimentation';
        $stmt = $con->prepare("UPDATE experiment_animals ea JOIN experiments e ON e.id = ea.experiment_id SET ea.removed_at = NOW(), ea.removal_reason = ? WHERE ea.id = ? AND e.protocol_id = ? AND ea.removed_at IS NULL");
        $stmt->bind_param('sii', $reason, $enrollmentId, $protocolId);
        $stmt->execute();
        $_SESSION['message'] = $stmt->affected_rows ? 'Participation clôturée : classification rétrospective BEA requise.' : 'Participation déjà clôturée.';
        $stmt->close();
    } elseif (isset($_POST['complete_action'])) {
        // Endpoint management: record what was done for a pending action. A
        // required euthanasia is closed by recording the animal's exit instead.
        $actionId = (int) ($_POST['action_id'] ?? 0);
        $note = trim((string) ($_POST['done_note'] ?? ''));
        if ($note === '') {
            $_SESSION['message'] = tr('Describe what was done (e.g. vet called, decision taken).');
        } else {
            $userName = $_SESSION['name'] ?? null;
            $stmt = $con->prepare(
                "UPDATE endpoint_actions a JOIN experiment_animals ea ON ea.id = a.experiment_animal_id JOIN experiments e ON e.id = ea.experiment_id
                 SET a.status = 'done', a.done_at = NOW(), a.done_by = ?, a.done_by_name = ?, a.done_note = ?
                 WHERE a.id = ? AND e.protocol_id = ? AND a.status = 'pending' AND a.required_action <> 'euthanasia'"
            );
            $stmt->bind_param("issii", $currentUserId, $userName, $note, $actionId, $protocolId);
            $stmt->execute();
            $done = $stmt->affected_rows === 1;
            $stmt->close();
            if ($done) {
                log_activity($con, 'endpoint_action_done', 'endpoint_action', (string) $actionId, $note, $currentUserId);
            }
            $_SESSION['message'] = $done ? tr('Action recorded as done.') : tr('This action cannot be completed here.');
        }
        header("Location: experiment_manage.php?protocol_id=$protocolId#endpointManagement");
        exit();
    }

    header("Location: experiment_manage.php?protocol_id=$protocolId");
    exit();
}

// Endpoint management: actions still to handle, recent alerts that required no
// action, and the actions resolved in the last 30 days.
$endpointBase = "FROM endpoint_actions a
    JOIN experiment_animals ea ON ea.id = a.experiment_animal_id
    JOIN experiments e ON e.id = ea.experiment_id
    JOIN mice m ON m.id = ea.animal_id
    LEFT JOIN experiment_followups f ON f.id = a.followup_id
    WHERE e.protocol_id = ?";
$endpointFields = "SELECT a.*, ea.animal_id, e.experiment_number, m.animal_uid, m.cage_id, m.status AS animal_status,
    f.observed_at, f.weight_g, f.clinical_score ";
$stmt = $con->prepare($endpointFields . $endpointBase . " AND a.status = 'pending' ORDER BY a.required_action = 'euthanasia' DESC, a.created_at");
$stmt->bind_param("i", $protocolId);
$stmt->execute();
$pendingActions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$stmt = $con->prepare($endpointFields . $endpointBase . " AND a.status = 'done' AND a.done_at >= NOW() - INTERVAL 30 DAY ORDER BY a.done_at DESC");
$stmt->bind_param("i", $protocolId);
$stmt->execute();
$resolvedActions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$stmt = $con->prepare(
    "SELECT f.*, ea.id AS experiment_animal_id, ea.animal_id, e.experiment_number, m.animal_uid, m.cage_id
     FROM experiment_followups f
     JOIN experiment_animals ea ON ea.id = f.experiment_animal_id
     JOIN experiments e ON e.id = ea.experiment_id
     JOIN mice m ON m.id = ea.animal_id
     WHERE e.protocol_id = ? AND f.endpoint_reached = 1 AND f.observed_at >= CURDATE() - INTERVAL 7 DAY
       AND NOT EXISTS (SELECT 1 FROM endpoint_actions a WHERE a.followup_id = f.id)
     ORDER BY f.observed_at DESC"
);
$stmt->bind_param("i", $protocolId);
$stmt->execute();
$alertOnlyFollowups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$pendingByEnrollment = array_count_values(array_map('intval', array_column($pendingActions, 'experiment_animal_id')));
$followupLink = fn(int $enrollmentId): string => 'experiment_followup.php?' . http_build_query(['experiment_animal_id' => $enrollmentId, 'return' => 'experiment_manage.php?protocol_id=' . $protocolId]);

$experiments = $con->prepare("SELECT * FROM experiments WHERE protocol_id = ? ORDER BY id DESC");
$experiments->bind_param("i", $protocolId);
$experiments->execute();
$experiments = $experiments->get_result()->fetch_all(MYSQLI_ASSOC);

// Enrollments of every experiment of the protocol, grouped by experiment.
$enrolledByExperiment = [];
$enrolledStmt = $con->prepare(
    "SELECT ea.*, m.animal_uid, m.cage_id, m.sex FROM experiment_animals ea JOIN mice m ON m.id = ea.animal_id
     JOIN experiments e ON e.id = ea.experiment_id WHERE e.protocol_id = ? ORDER BY ea.removed_at IS NOT NULL, m.animal_uid"
);
$enrolledStmt->bind_param("i", $protocolId);
$enrolledStmt->execute();
foreach ($enrolledStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $en) {
    $enrolledByExperiment[(int) $en['experiment_id']][] = $en;
}
$enrolledStmt->close();

// Daily clinical evaluations of the protocol's enrollments, for the
// animals × days overview of each experiment.
$followupsByEnrollment = [];
$followupStmt = $con->prepare(
    "SELECT f.id, f.experiment_animal_id, f.observed_at, f.weight_g, f.clinical_score, f.endpoint_reached, f.observations
     FROM experiment_followups f JOIN experiment_animals ea ON ea.id = f.experiment_animal_id
     JOIN experiments e ON e.id = ea.experiment_id WHERE e.protocol_id = ? ORDER BY f.observed_at"
);
$followupStmt->bind_param("i", $protocolId);
$followupStmt->execute();
foreach ($followupStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
    $followupsByEnrollment[(int) $f['experiment_animal_id']][$f['observed_at']] = $f;
}
$followupStmt->close();
$followupItems = [];
$itemStmt = $con->prepare(
    "SELECT s.followup_id, s.item_label, s.score FROM experiment_followup_scores s JOIN experiment_followups f ON f.id = s.followup_id
     JOIN experiment_animals ea ON ea.id = f.experiment_animal_id JOIN experiments e ON e.id = ea.experiment_id
     WHERE e.protocol_id = ? ORDER BY s.id"
);
$itemStmt->bind_param("i", $protocolId);
$itemStmt->execute();
foreach ($itemStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $s) {
    $followupItems[(int) $s['followup_id']][] = $s['item_label'] . ' : ' . $s['score'];
}
$itemStmt->close();
$protocolGrid = $protocol['clinical_grid_id'] ? load_clinical_grid($con, (int) $protocol['clinical_grid_id']) : null;
$gridMax = (int) ($protocolGrid['max_total'] ?? 0);
// Colour of a clinical score: relative to the grid maximum when there is one.
$scoreClass = function (?int $score, bool $endpoint) use ($gridMax): string {
    if ($endpoint) {
        return 'bg-danger text-white';
    }
    if ($score === null) {
        return 'bg-light';
    }
    $ratio = $gridMax > 0 ? $score / $gridMax : $score / 6;
    return $score === 0 ? 'score-0' : ($ratio <= 1 / 3 ? 'score-1' : ($ratio <= 2 / 3 ? 'score-2' : 'score-3'));
};
// Days shown in the overview of an experiment: from the first enrollment to
// today (or the last day of participation), the most recent days last.
$overviewDays = function (array $enrolled): array {
    if (!$enrolled) {
        return [];
    }
    $from = min(array_map(fn($en) => substr($en['enrolled_at'], 0, 10), $enrolled));
    $to = max(array_map(fn($en) => $en['removed_at'] ? substr($en['removed_at'], 0, 10) : date('Y-m-d'), $enrolled));
    $from = max($from, date('Y-m-d', strtotime($to . ' -365 days')));
    $days = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $days[] = $d;
    }
    return $days;
};

$activeAnimals = $con->query("SELECT m.id, m.animal_uid, m.mouse_id, m.cage_id, m.sex, al.code AS line_code FROM mice m LEFT JOIN animal_lines al ON al.id = m.line_id WHERE m.status = 'active' ORDER BY m.cage_id, m.animal_uid");
$activeAnimalsList = [];
while ($row = $activeAnimals->fetch_assoc()) {
    $activeAnimalsList[] = $row;
}
$animalCages = array_values(array_unique(array_filter(array_column($activeAnimalsList, 'cage_id'))));
$showNewExperiment = isset($_GET['new']) || !$experiments;
// Render the shared header only after request handling. Including it before the
// POST redirect sends HTML output and prevents PHP from modifying headers.
require 'header.php';
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Experiments') ?> | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        .clinical-matrix { font-size: .8rem; }
        .clinical-matrix th, .clinical-matrix td { text-align: center; white-space: nowrap; padding: .2rem .3rem; }
        .clinical-matrix .sticky-col { position: sticky; left: 0; background: #fff; z-index: 1; text-align: left; }
        .clinical-matrix thead .sticky-col { background: #f8f9fa; }
        .clinical-matrix td.cell a { display: block; color: inherit; text-decoration: none; min-width: 2.2rem; }
        .clinical-matrix td.out { background: repeating-linear-gradient(45deg, #f1f3f5, #f1f3f5 4px, #fff 4px, #fff 8px); }
        .clinical-matrix td.missing, .clinical-legend .missing { background: #fff; color: #adb5bd; outline: 1px dashed #adb5bd; outline-offset: -3px; }
        .clinical-matrix th.weekend { background: #eef1f4; }
        .score-0 { background: #d1e7dd; }
        .score-1 { background: #fff3cd; }
        .score-2 { background: #ffd8a8; }
        .score-3 { background: #f5c2c7; }
        .clinical-legend span { display: inline-block; padding: 0 .4rem; border-radius: .2rem; margin-right: .25rem; }
    </style>
</head>

<body>
    <div class="container mt-4 content">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="trial_manage.php"><i class="fas fa-flask"></i> <?= trh('Trials') ?></a></li>
                <li class="breadcrumb-item"><a href="protocol_manage.php?trial_id=<?= (int) $protocol['trial_id']; ?>"><?= htmlspecialchars($protocol['trial_number']); ?></a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($protocol['protocol_number']); ?></li>
            </ol>
        </nav>

        <div class="card mb-3 bg-light" id="protocolSummary">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h4 class="mb-1"><?= htmlspecialchars($protocol['protocol_number']); ?> — <?= htmlspecialchars($protocol['title']); ?></h4>
                        <div class="small text-muted">
                            <?= trh('Trial') ?> <?= htmlspecialchars($protocol['trial_number'] . ' — ' . $protocol['trial_title']); ?> ·
                            <?= trh('Period') ?> : <?= htmlspecialchars(($experimentFrame['start_date'] ?: '?') . ' → ' . ($experimentFrame['end_date'] ?: '?')); ?>
                        </div>
                        <div class="small mt-1">
                            <?php if ($auth) : ?>
                                <strong><?= trh('Ethics number') ?> :</strong> <?= htmlspecialchars($auth['iacuc_id']); ?>
                                <?php if ($auth['authorization_number']) : ?> · <strong><?= trh('Authorization number') ?> :</strong> <?= htmlspecialchars($auth['authorization_number']); ?><?php endif; ?>
                                · <?= trh('valid {period}', ['period' => authorization_period($auth)]); ?> <?= authorization_badge($auth); ?>
                            <?php else : ?>
                                <?= authorization_badge(null); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" href="protocol_manage.php?trial_id=<?= (int) $protocol['trial_id']; ?>#protocol<?= $protocolId; ?>"><i class="fas fa-cog"></i> <?= trh('Protocol settings') ?></a>
                        <a class="btn btn-sm btn-outline-dark" href="protocol_report.php?protocol_id=<?= $protocolId; ?>"><i class="fas fa-file-alt"></i> <?= trh('Printable report') ?></a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($enrollmentBlock) : ?>
            <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($enrollmentBlock); ?></div>
        <?php endif; ?>

        <?php include('message.php'); ?>

        <?php if ($pendingActions || $alertOnlyFollowups || $resolvedActions) : ?>
            <div class="card mb-4 <?= $pendingActions ? 'border-danger' : 'border-secondary'; ?>" id="endpointManagement">
                <div class="card-header <?= $pendingActions ? 'bg-danger text-white' : 'bg-light'; ?> d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <strong><i class="fas fa-exclamation-triangle"></i> <?= trh('Endpoint management') ?></strong>
                    <span>
                        <span class="badge bg-light text-danger"><?= trh('{n} action(s) to handle', ['n' => count($pendingActions)]); ?></span>
                        <?php if ($alertOnlyFollowups) : ?><span class="badge bg-warning text-dark"><?= trh('{n} recent alert(s)', ['n' => count($alertOnlyFollowups)]); ?></span><?php endif; ?>
                    </span>
                </div>
                <div class="card-body">
                    <?php if ($pendingActions) : ?>
                        <?php foreach ($pendingActions as $pa) :
                            $isEuthanasia = $pa['required_action'] === 'euthanasia';
                            $alive = $pa['animal_status'] === 'active'; ?>
                            <div class="border rounded p-2 mb-2 <?= $isEuthanasia ? 'border-danger bg-danger bg-opacity-10' : 'border-warning'; ?>" id="endpointAction<?= (int) $pa['id']; ?>">
                                <div class="d-flex justify-content-between flex-wrap gap-2">
                                    <div>
                                        <span class="badge bg-<?= $isEuthanasia ? 'danger' : 'warning text-dark'; ?>"><?= htmlspecialchars(endpoint_required_actions()[$pa['required_action']] ?? $pa['required_action']); ?></span>
                                        <a href="animal_view.php?id=<?= (int) $pa['animal_id']; ?>" class="fw-bold"><?= htmlspecialchars($pa['animal_uid']); ?></a>
                                        · <?= htmlspecialchars($pa['experiment_number']); ?> · <?= trh('cage {cage}', ['cage' => $pa['cage_id'] ?? '—']); ?>
                                        <div class="small"><?= htmlspecialchars($pa['message'] ?? ''); ?></div>
                                        <div class="small text-muted">
                                            <?= trh('Raised on {date}', ['date' => substr($pa['created_at'], 0, 16)]); ?>
                                            <?php if ($pa['observed_at']) : ?> · <?= trh('follow-up of {date}: weight {weight} g, score {score}', ['date' => $pa['observed_at'], 'weight' => $pa['weight_g'] ?? '—', 'score' => $pa['clinical_score'] ?? '—']); ?><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="text-nowrap">
                                        <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($followupLink((int) $pa['experiment_animal_id'])); ?>"><i class="fas fa-clipboard-check"></i> <?= trh('Follow-up') ?></a>
                                        <?php if ($alive) : ?>
                                            <a class="btn btn-sm btn-danger" href="animal_exit.php?<?= htmlspecialchars(http_build_query(['id' => $pa['animal_id'], 'code' => 'point_limite', 'return' => 'experiment_manage.php?protocol_id=' . (int) $protocolId . '#endpointManagement'])); ?>"><i class="fas fa-sign-out-alt"></i> <?= trh('Record the euthanasia') ?></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!$isEuthanasia) : ?>
                                    <form method="POST" class="row g-2 mt-1">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="protocol_id" value="<?= $protocolId; ?>">
                                        <input type="hidden" name="action_id" value="<?= (int) $pa['id']; ?>">
                                        <div class="col-md-9"><input type="text" class="form-control form-control-sm" name="done_note" required placeholder="<?= trh('What was done (e.g. Dr X called at 10:30 — analgesia, re-check tonight)') ?>"></div>
                                        <div class="col-md-3"><button type="submit" name="complete_action" class="btn btn-sm btn-success w-100"><i class="fas fa-check"></i> <?= trh('Mark as done') ?></button></div>
                                    </form>
                                <?php else : ?>
                                    <div class="small text-danger mt-1"><?= trh('Closed automatically when the animal’s exit is recorded.') ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p class="text-success mb-2"><i class="fas fa-check-circle"></i> <?= trh('No endpoint action to handle.') ?></p>
                    <?php endif; ?>

                    <?php if ($alertOnlyFollowups) : ?>
                        <h6 class="mt-3"><?= trh('Endpoints reached without required action (last 7 days)') ?></h6>
                        <ul class="small mb-0">
                            <?php foreach ($alertOnlyFollowups as $af) : ?>
                                <li>
                                    <?= htmlspecialchars($af['observed_at']); ?> — <a href="animal_view.php?id=<?= (int) $af['animal_id']; ?>"><?= htmlspecialchars($af['animal_uid']); ?></a>
                                    (<?= htmlspecialchars($af['experiment_number']); ?>) · <?= trh('weight {weight} g · score {score}', ['weight' => $af['weight_g'] ?? '—', 'score' => $af['clinical_score'] ?? '—']); ?>
                                    · <a href="<?= htmlspecialchars($followupLink((int) $af['experiment_animal_id'])); ?>"><?= trh('Follow-up') ?></a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($resolvedActions) : ?>
                        <details class="mt-3">
                            <summary class="small"><?= trh('Actions handled in the last 30 days ({n})', ['n' => count($resolvedActions)]); ?></summary>
                            <table class="table table-sm small mt-2 mb-0">
                                <thead><tr><th><?= trh('Animal') ?></th><th><?= trh('Required action') ?></th><th><?= trh('Raised') ?></th><th><?= trh('Resolution') ?></th></tr></thead>
                                <tbody>
                                    <?php foreach ($resolvedActions as $ra) : ?>
                                        <tr>
                                            <td><?= htmlspecialchars($ra['animal_uid']); ?> <span class="text-muted">(<?= htmlspecialchars($ra['experiment_number']); ?>)</span></td>
                                            <td><?= htmlspecialchars(endpoint_required_actions()[$ra['required_action']] ?? $ra['required_action']); ?></td>
                                            <td><?= htmlspecialchars(substr($ra['created_at'], 0, 16)); ?></td>
                                            <td><?= htmlspecialchars(substr((string) $ra['done_at'], 0, 16) . ' — ' . ($ra['done_by_name'] ?? '') . ' : ' . ($ra['done_note'] ?? '')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="mb-0"><?= trh('Experiments') ?> <span class="badge bg-secondary"><?= count($experiments); ?></span></h5>
            <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#newExperiment"><i class="fas fa-plus"></i> <?= trh('New Experiment') ?></button>
        </div>

        <div class="collapse <?= $showNewExperiment ? 'show' : ''; ?>" id="newExperiment">
            <div class="card mb-4 border-primary">
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="protocol_id" value="<?= $protocolId; ?>">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label"><?= trh('Title') ?> *</label>
                                <input type="text" class="form-control" name="title" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label"><?= trh('Start Date') ?></label>
                                <input type="date" class="form-control" name="start_date" min="<?= htmlspecialchars($experimentFrame['start_date'] ?? ''); ?>" max="<?= htmlspecialchars($experimentFrame['end_date'] ?? ''); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label"><?= trh('End Date') ?></label>
                                <input type="date" class="form-control" name="end_date" min="<?= htmlspecialchars($experimentFrame['start_date'] ?? ''); ?>" max="<?= htmlspecialchars($experimentFrame['end_date'] ?? ''); ?>">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" name="add_experiment" class="btn btn-primary w-100"><i class="fas fa-save"></i> <?= trh('Create') ?></button>
                            </div>
                        </div>
                        <small class="text-muted">Le numéro est attribué automatiquement à la validation (format <strong>EXP-00001</strong>).</small>
                    </form>
                </div>
            </div>
        </div>

        <?php foreach ($experiments as $e) :
            $eid = (int) $e['id'];
            $enrolled = $enrolledByExperiment[$eid] ?? [];
            $ongoing = count(array_filter($enrolled, fn($en) => !$en['removed_at']));
        ?>
            <div class="card mb-3" id="experiment<?= $eid; ?>">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <strong><?= htmlspecialchars($e['experiment_number']); ?></strong> — <?= htmlspecialchars($e['title']); ?>
                        <div class="small text-muted">
                            <?= htmlspecialchars(($e['start_date'] ?: '?') . ' → ' . ($e['end_date'] ?: '?')); ?> ·
                            <?= trh('{n} ongoing, {m} closed', ['n' => $ongoing, 'm' => count($enrolled) - $ongoing]); ?>
                        </div>
                    </div>
                    <div class="text-nowrap">
                        <?php if (!$enrollmentBlock) : ?>
                            <button class="btn btn-sm btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#enroll<?= $eid; ?>"><i class="fas fa-user-plus"></i> Ajouter des animaux</button>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-outline-dark" href="experiment_report.php?experiment_id=<?= $eid; ?>"><i class="fas fa-file-alt"></i> <?= trh('Report (with grids)') ?></a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!$enrollmentBlock) : ?>
                        <div class="collapse <?= !$enrolled ? 'show' : ''; ?> mb-3" id="enroll<?= $eid; ?>">
                            <form method="POST" class="row g-2 align-items-end experiment-enroll-form p-2 border rounded bg-light">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="protocol_id" value="<?= $protocolId; ?>">
                                <input type="hidden" name="experiment_id" value="<?= $eid; ?>">
                                <div class="col-md-6">
                                    <label class="form-label">Animaux à ajouter (recherche et multi-sélection)</label>
                                    <select class="form-control animal-multi-select" name="animal_ids[]" multiple required>
                                        <?php foreach ($activeAnimalsList as $a) : ?>
                                            <option value="<?= (int) $a['id']; ?>" data-cage="<?= htmlspecialchars($a['cage_id'] ?? ''); ?>"><?= htmlspecialchars(($a['animal_uid'] ?: $a['mouse_id'] ?: '#' . $a['id']) . ' — cage ' . ($a['cage_id'] ?? 'sans cage') . ' · ' . ($a['line_code'] ?? '')); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2"><label class="form-label">Ajouter toute la cage</label><select class="form-select cage-picker"><option value="">Choisir…</option><?php foreach ($animalCages as $cage) : ?><option value="<?= htmlspecialchars($cage); ?>"><?= htmlspecialchars($cage); ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-2">
                                    <label class="form-label"><?= trh('Baseline weight (g)') ?></label>
                                    <input type="number" step="0.01" class="form-control" name="baseline_weight_g">
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" name="enroll_animals" class="btn btn-success w-100">Ajouter à l’expérience</button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if ($enrolled) :
                        $days = $overviewDays($enrolled);
                        $nEvaluations = array_sum(array_map(fn($en) => count($followupsByEnrollment[(int) $en['id']] ?? []), $enrolled));
                        $defaultTab = $ongoing && $nEvaluations ? 'clinical' : 'animals'; ?>
                        <ul class="nav nav-tabs mb-2" role="tablist">
                            <li class="nav-item" role="presentation"><button class="nav-link <?= $defaultTab === 'animals' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#animals<?= $eid; ?>" type="button" role="tab"><i class="fas fa-paw"></i> <?= trh('Enrolled animals') ?> <span class="badge bg-secondary"><?= count($enrolled); ?></span></button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link <?= $defaultTab === 'clinical' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#clinical<?= $eid; ?>" type="button" role="tab"><i class="fas fa-th"></i> <?= trh('Daily clinical evaluations') ?> <span class="badge bg-secondary"><?= $nEvaluations; ?></span></button></li>
                        </ul>
                        <div class="tab-content">
                        <div class="tab-pane fade <?= $defaultTab === 'animals' ? 'show active' : ''; ?>" id="animals<?= $eid; ?>" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th><?= trh('Animal') ?></th>
                                        <th><?= trh('Cage') ?></th>
                                        <th><?= trh('Baseline weight (g)') ?></th>
                                        <th><?= trh('Enrolled') ?></th>
                                        <th>Statut</th>
                                        <th class="text-end"><?= trh('Actions') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enrolled as $en) : ?>
                                        <tr class="<?= $en['removed_at'] ? 'text-muted' : ''; ?>">
                                            <td><a href="animal_view.php?id=<?= (int) $en['animal_id']; ?>"><?= htmlspecialchars($en['animal_uid']); ?></a> <?= $en['sex'] === 'male' ? '♂' : ($en['sex'] === 'female' ? '♀' : ''); ?></td>
                                            <td><?= htmlspecialchars($en['cage_id'] ?? '—'); ?></td>
                                            <td><?= htmlspecialchars($en['baseline_weight_g'] ?? '-'); ?></td>
                                            <td><?= htmlspecialchars(substr($en['enrolled_at'], 0, 10)); ?></td>
                                            <td><?= $en['removed_at'] ? '<span class="badge bg-secondary">Clôturée le ' . htmlspecialchars(substr($en['removed_at'], 0, 10)) . '</span>' : '<span class="badge bg-primary">En cours</span>'; ?><?php if (!empty($pendingByEnrollment[(int) $en['id']])) : ?> <a href="#endpointManagement" class="badge bg-danger text-decoration-none"><i class="fas fa-exclamation-triangle"></i> <?= trh('Endpoint') ?></a><?php endif; ?></td>
                                            <td class="text-end text-nowrap">
                                                <?php if (!$en['removed_at']) : ?>
                                                    <a class="btn btn-sm btn-outline-primary" href="experiment_followup.php?<?= htmlspecialchars(http_build_query(['experiment_animal_id' => $en['id'], 'return' => 'experiment_manage.php?protocol_id=' . $protocolId])); ?>"><i class="fas fa-clipboard-check"></i> Suivi quotidien</a>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="protocol_id" value="<?= $protocolId; ?>">
                                                        <input type="hidden" name="experiment_animal_id" value="<?= (int) $en['id']; ?>">
                                                        <input type="hidden" name="removal_reason" value="Fin de participation à l’expérimentation">
                                                        <button class="btn btn-sm btn-outline-secondary" name="end_enrollment" onclick="return confirm('Clôturer la participation expérimentale ?')">Clôturer</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        </div>
                        <div class="tab-pane fade <?= $defaultTab === 'clinical' ? 'show active' : ''; ?>" id="clinical<?= $eid; ?>" role="tabpanel">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <div class="btn-group btn-group-sm" role="group" aria-label="<?= trh('Value shown') ?>">
                                    <button type="button" class="btn btn-outline-primary active" data-matrix-mode="score"><?= trh('Clinical score') ?></button>
                                    <button type="button" class="btn btn-outline-primary" data-matrix-mode="weight"><?= trh('Weight (g)') ?></button>
                                    <button type="button" class="btn btn-outline-primary" data-matrix-mode="change"><?= trh('Weight change (%)') ?></button>
                                </div>
                                <div class="small clinical-legend">
                                    <span class="score-0">0</span><span class="score-1"><?= trh('low') ?></span><span class="score-2"><?= trh('medium') ?></span><span class="score-3"><?= trh('high') ?></span><span class="bg-danger text-white"><i class="fas fa-exclamation-triangle"></i> <?= trh('Endpoint') ?></span><span class="missing"><?= trh('not entered') ?></span>
                                    <?= $gridMax ? trh('(grid out of {max})', ['max' => $gridMax]) : ''; ?>
                                </div>
                            </div>
                            <div class="table-responsive clinical-scroll">
                                <table class="table table-bordered clinical-matrix mb-1">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="sticky-col"><?= trh('Animal') ?></th>
                                            <th title="<?= trh('Last score / highest score') ?>"><?= trh('Last / max') ?></th>
                                            <?php foreach ($days as $d) : $dow = (int) date('N', strtotime($d)); ?>
                                                <th class="<?= $dow >= 6 ? 'weekend' : ''; ?>" title="<?= htmlspecialchars($d); ?>"><?= htmlspecialchars(date('d/m', strtotime($d))); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($enrolled as $en) :
                                            $enId = (int) $en['id'];
                                            $fus = $followupsByEnrollment[$enId] ?? [];
                                            $from = substr($en['enrolled_at'], 0, 10);
                                            $to = $en['removed_at'] ? substr($en['removed_at'], 0, 10) : date('Y-m-d');
                                            $scored = array_filter(array_column($fus, 'clinical_score'), fn($v) => $v !== null);
                                            $baseline = (float) ($en['baseline_weight_g'] ?? 0);
                                            $followupUrl = 'experiment_followup.php?' . http_build_query(['experiment_animal_id' => $enId, 'return' => 'experiment_manage.php?protocol_id=' . $protocolId]); ?>
                                            <tr class="<?= $en['removed_at'] ? 'text-muted' : ''; ?>">
                                                <td class="sticky-col"><a href="<?= htmlspecialchars($followupUrl); ?>" class="fw-bold"><?= htmlspecialchars($en['animal_uid'] ?? ('#' . $en['animal_id'])); ?></a>
                                                    <?= !empty($pendingByEnrollment[$enId]) ? '<i class="fas fa-exclamation-triangle text-danger" title="' . trh('Endpoint') . '"></i>' : ''; ?></td>
                                                <td><?= $scored ? (int) end($scored) : '—'; ?> / <?= $scored ? max($scored) : '—'; ?></td>
                                                <?php foreach ($days as $d) :
                                                    if ($d < $from || $d > $to) : ?>
                                                        <td class="out"></td>
                                                    <?php elseif (!isset($fus[$d])) : ?>
                                                        <td class="cell missing"><?php if (!$en['removed_at']) : ?><a href="<?= htmlspecialchars($followupUrl . '&date=' . $d); ?>" title="<?= trh('{date}: no evaluation — enter', ['date' => $d]); ?>">·</a><?php else : ?>·<?php endif; ?></td>
                                                    <?php else :
                                                        $f = $fus[$d];
                                                        $score = $f['clinical_score'] !== null ? (int) $f['clinical_score'] : null;
                                                        $change = ($baseline > 0 && $f['weight_g'] !== null) ? ((float) $f['weight_g'] - $baseline) * 100 / $baseline : null;
                                                        $tip = [$d];
                                                        $tip[] = tr('Clinical score') . ' : ' . ($score ?? '—');
                                                        $tip[] = tr('Weight (g)') . ' : ' . ($f['weight_g'] ?? '—') . ($change !== null ? sprintf(' (%+.1f %%)', $change) : '');
                                                        foreach ($followupItems[(int) $f['id']] ?? [] as $item) {
                                                            $tip[] = '• ' . $item;
                                                        }
                                                        if ($f['observations']) {
                                                            $tip[] = $f['observations'];
                                                        }
                                                        if ($f['endpoint_reached']) {
                                                            $tip[] = tr('Endpoint reached');
                                                        } ?>
                                                        <td class="cell <?= $scoreClass($score, (bool) $f['endpoint_reached']); ?>"
                                                            data-score="<?= $score ?? '—'; ?>" data-weight="<?= $f['weight_g'] !== null ? htmlspecialchars(rtrim(rtrim((string) $f['weight_g'], '0'), '.')) : '—'; ?>" data-change="<?= $change !== null ? htmlspecialchars(sprintf('%+.1f', $change)) : '—'; ?>">
                                                            <a href="<?= htmlspecialchars('experiment_followup_view.php?' . http_build_query(['id' => $f['id'], 'return' => 'experiment_manage.php?protocol_id=' . $protocolId])); ?>" title="<?= htmlspecialchars(implode("\n", $tip)); ?>"><?= $f['endpoint_reached'] ? '<i class="fas fa-exclamation-triangle"></i> ' : ''; ?><span class="v"><?= $score ?? '—'; ?></span></a>
                                                        </td>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="small text-muted mb-0"><?= trh('Hover a cell for a summary of the evaluation; click it to read the full evaluation (level chosen for each grid item, observations). Click a missing day to enter it. Hatched: outside the participation.') ?></p>
                        </div>
                        </div>
                    <?php else : ?>
                        <p class="text-muted mb-0">Aucun animal inclus pour l’instant.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$experiments) : ?><p class="text-muted">Aucune expérience pour l’instant : créez la première ci-dessus.</p><?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $('.animal-multi-select').select2({ width: '100%', placeholder: 'Rechercher un animal…' });
        $('.cage-picker').on('change', function() {
            const form = $(this).closest('form');
            const cage = this.value;
            const select = form.find('.animal-multi-select');
            if (cage) {
                select.find('option').filter(function() { return $(this).data('cage') === cage; }).prop('selected', true);
                select.trigger('change');
            }
        });
        // Clinical overview: switch the value shown in the cells, and start
        // scrolled on the most recent days.
        document.querySelectorAll('[data-matrix-mode]').forEach(button => button.addEventListener('click', () => {
            const pane = button.closest('.tab-pane');
            pane.querySelectorAll('[data-matrix-mode]').forEach(b => b.classList.toggle('active', b === button));
            pane.querySelectorAll('td.cell[data-score]').forEach(td => {
                td.querySelector('.v').textContent = td.dataset[button.dataset.matrixMode];
            });
        }));
        const scrollToLatest = pane => pane.querySelectorAll('.clinical-scroll').forEach(box => { box.scrollLeft = box.scrollWidth; });
        document.querySelectorAll('[id^="clinical"]').forEach(pane => scrollToLatest(pane));
        document.querySelectorAll('[data-bs-target^="#clinical"]').forEach(tab => tab.addEventListener('shown.bs.tab', () => scrollToLatest(document.querySelector(tab.dataset.bsTarget))));
        // select2 needs a visible width: refresh it when an enroll panel opens.
        document.querySelectorAll('[id^="enroll"]').forEach(panel => panel.addEventListener('shown.bs.collapse', () => {
            $(panel).find('.animal-multi-select').select2({ width: '100%', placeholder: 'Rechercher un animal…' });
        }));
    </script>
    <br>
    <?php require 'footer.php'; ?>
</body>

</html>
