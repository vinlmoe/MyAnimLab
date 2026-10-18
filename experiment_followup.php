<?php

/**
 * Daily follow-up form (formulaire de suivi journalier) for one animal
 * enrolled in one experiment.
 *
 * When the protocol has a clinical scoring grid, each item is scored and the
 * total becomes the clinical score. Every save evaluates the protocol's
 * endpoints (points limites) on weight loss, total score or one item's score.
 * An endpoint that requires an action (euthanasia, calling the vet...) opens
 * a pending action shown to the experimenter until it is done: euthanasia is
 * completed by recording the animal's exit, other actions by a written note.
 * The experimental acts planned for the animal up to the chosen day are
 * confirmed here too (done / not done, with the information they ask for and,
 * for a medication of the stock, the lot used).
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require 'experiment_acts_lib.php';
require_once 'training_lib.php';

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$currentUserId = $_SESSION['user_id'] ?? null;
$currentUserName = $_SESSION['name'] ?? null;
$experimentAnimalId = (int) ($_GET['experiment_animal_id'] ?? $_POST['experiment_animal_id'] ?? 0);
$returnUrl = safe_check_return($_GET['return'] ?? $_POST['return'] ?? null);

$eaStmt = $con->prepare(
    "SELECT ea.*, m.animal_uid, m.id AS animal_id, m.status AS animal_status, m.distinctive_mark, e.id AS experiment_id, e.experiment_number, e.title AS experiment_title,
            p.id AS protocol_id, p.trial_id, p.protocol_number, p.endpoint_criteria, p.clinical_grid_id
     FROM experiment_animals ea
     JOIN mice m ON m.id = ea.animal_id
     JOIN experiments e ON e.id = ea.experiment_id
     JOIN protocols p ON p.id = e.protocol_id
     WHERE ea.id = ?"
);
$eaStmt->bind_param("i", $experimentAnimalId);
$eaStmt->execute();
$ea = $eaStmt->get_result()->fetch_assoc();

if (!$ea) {
    require 'header.php';
    echo "<div class='container mt-4'><div class='alert alert-danger'>" . trh('Enrollment not found.') . "</div></div>";
    require 'footer.php';
    exit;
}

$grid = $ea['clinical_grid_id'] ? load_clinical_grid($con, (int) $ea['clinical_grid_id']) : null;
$hasGrid = $grid && $grid['items'];
$obsFields = protocol_observation_fields($con, (int) $ea['protocol_id']);
$allObsFields = protocol_observation_fields($con, (int) $ea['protocol_id'], true);

$thresholdsStmt = $con->prepare("SELECT * FROM protocol_endpoint_thresholds WHERE protocol_id = ?");
$thresholdsStmt->bind_param("i", $ea['protocol_id']);
$thresholdsStmt->execute();
$thresholds = $thresholdsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

function threshold_breached(string $operator, float $actual, float $limit): bool
{
    return match ($operator) {
        '>' => $actual > $limit,
        '>=' => $actual >= $limit,
        '<' => $actual < $limit,
        '<=' => $actual <= $limit,
        default => false,
    };
}

$selfUrl = "experiment_followup.php?experiment_animal_id=$experimentAnimalId" . ($returnUrl ? '&return=' . rawurlencode($returnUrl) : '');
$experimentUrl = 'experiment_manage.php?protocol_id=' . (int) $ea['protocol_id'];
$backUrl = $returnUrl ?: $experimentUrl;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(tr('CSRF token validation failed'));
    }

    // Correcting a confirmed act: what was recorded is kept with the reason,
    // a medication given is cancelled and re-credited, the act is to do again.
    if (isset($_POST['correct_act'])) {
        mysqli_begin_transaction($con);
        try {
            $actAnimalId = (int) ($_POST['act_animal_id'] ?? 0);
            $reason = trim((string) ($_POST['correction_reason'] ?? ''));
            $animalId = correct_experiment_act($con, $actAnimalId, $reason, $currentUserId, $currentUserName, ($_SESSION['role'] ?? '') === 'admin', $experimentAnimalId);
            log_animal_event($con, $animalId, 'experiment_act', ['act_animal_id' => $actAnimalId, 'status' => 'corrected', 'reason' => $reason], $currentUserId);
            log_activity($con, 'experiment_act_corrected', 'animal', (string) $animalId, tr('Act #{id}: {reason}', ['id' => $actAnimalId, 'reason' => $reason]), $currentUserId);
            mysqli_commit($con);
            $_SESSION['message'] = tr('Confirmation cancelled: the act is to do again (the correction is kept in the history).');
        } catch (Exception $e) {
            mysqli_rollback($con);
            $_SESSION['message'] = tr('Act not corrected: {error}', ['error' => $e->getMessage()]);
        }
        header("Location: $selfUrl#actsHistory");
        exit;
    }

    // Completing a pending endpoint action (other than euthanasia) with a note.
    if (isset($_POST['complete_action'])) {
        $actionId = (int) $_POST['action_id'];
        $note = trim($_POST['done_note'] ?? '');
        if ($note === '') {
            $_SESSION['message'] = tr('Describe what was done (e.g. vet called, decision taken).');
        } else {
            $stmt = $con->prepare(
                "UPDATE endpoint_actions SET status = 'done', done_at = NOW(), done_by = ?, done_by_name = ?, done_note = ?
                 WHERE id = ? AND experiment_animal_id = ? AND status = 'pending' AND required_action <> 'euthanasia'"
            );
            $stmt->bind_param("issii", $currentUserId, $currentUserName, $note, $actionId, $experimentAnimalId);
            $stmt->execute();
            $done = $stmt->affected_rows === 1;
            $stmt->close();
            if ($done) {
                log_activity($con, 'endpoint_action_done', 'animal', (string) $ea['animal_id'], tr('Action #{id}: {note}', ['id' => $actionId, 'note' => $note]), $currentUserId);
            }
            $_SESSION['message'] = $done ? tr('Action recorded as done.') : tr('This action cannot be completed here.');
        }
        header("Location: $selfUrl");
        exit;
    }

    if ($ea['animal_status'] !== 'active' || $ea['removed_at'] !== null) {
        $_SESSION['message'] = tr('This animal is no longer followed in this experiment.');
        header("Location: $selfUrl");
        exit;
    }

    $observedAt = $_POST['observed_at'];
    $weight = $_POST['weight_g'] !== '' ? (float) $_POST['weight_g'] : null;
    $observations = trim($_POST['observations'] ?? '');

    // Grid scores: every item must be scored with one of its levels.
    $itemScores = [];
    if ($hasGrid) {
        foreach ($grid['items'] as $itemId => $item) {
            $raw = $_POST['score'][$itemId] ?? '';
            $level = null;
            foreach ($item['levels'] as $lv) {
                if ($raw !== '' && (int) $raw === (int) $lv['score']) {
                    $level = $lv;
                }
            }
            if ($level === null) {
                $_SESSION['message'] = tr('Score every item of the clinical grid (missing: {item}).', ['item' => $item['label']]);
                header("Location: $selfUrl");
                exit;
            }
            $itemScores[$itemId] = $level;
        }
        $clinicalScore = array_sum(array_map(fn($l) => (int) $l['score'], $itemScores));
    } else {
        $clinicalScore = ($_POST['clinical_score'] ?? '') !== '' ? (int) $_POST['clinical_score'] : null;
    }

    // Additional observations: protocol fields, then free ones (label + value).
    $observationRows = [];
    $fail = function (string $msg) use ($selfUrl) {
        $_SESSION['message'] = $msg;
        header("Location: $selfUrl");
        exit;
    };
    foreach ($obsFields as $fieldId => $field) {
        $raw = trim($_POST['obs'][$fieldId] ?? '');
        if ($raw === '') {
            if ($field['required']) {
                $fail(tr('Observation "{label}" is required.', ['label' => $field['label']]));
            }
            continue;
        }
        $number = $field['value_type'] === 'number' ? parse_number($raw) : null;
        if ($field['value_type'] === 'number' && $number === null) {
            $fail(tr('Observation "{label}" must be a number.', ['label' => $field['label']]));
        }
        $observationRows[] = ['field_id' => $fieldId, 'label' => $field['label'], 'value_type' => $field['value_type'], 'unit' => $field['unit'], 'number' => $number, 'text' => $field['value_type'] === 'text' ? $raw : null];
    }
    foreach ((array) ($_POST['extra_label'] ?? []) as $i => $label) {
        $label = trim($label);
        $raw = trim($_POST['extra_value'][$i] ?? '');
        if ($label === '' && $raw === '') {
            continue;
        }
        if ($label === '' || $raw === '') {
            $fail(tr('Each free observation needs a label and a value.'));
        }
        $type = ($_POST['extra_type'][$i] ?? '') === 'number' ? 'number' : 'text';
        $number = $type === 'number' ? parse_number($raw) : null;
        if ($type === 'number' && $number === null) {
            $fail(tr('Free observation "{label}" must be a number.', ['label' => $label]));
        }
        $unit = $type === 'number' ? (trim($_POST['extra_unit'][$i] ?? '') ?: null) : null;
        $observationRows[] = ['field_id' => null, 'label' => $label, 'value_type' => $type, 'unit' => $unit, 'number' => $number, 'text' => $type === 'text' ? $raw : null];
    }
    $obsValueByField = [];
    foreach ($observationRows as $row) {
        if ($row['field_id'] !== null && $row['number'] !== null) {
            $obsValueByField[$row['field_id']] = $row['number'];
        }
    }

    // Evaluate the protocol's endpoints against this entry.
    $breaches = [];
    foreach ($thresholds as $t) {
        $actual = null;
        if ($t['metric'] === 'clinical_score' && $clinicalScore !== null) {
            $actual = $clinicalScore;
        } elseif ($t['metric'] === 'weight_loss_pct' && $weight !== null && !empty($ea['baseline_weight_g'])) {
            $actual = ($ea['baseline_weight_g'] - $weight) / $ea['baseline_weight_g'] * 100;
        } elseif ($t['metric'] === 'item_score' && isset($itemScores[(int) $t['grid_item_id']])) {
            $actual = (int) $itemScores[(int) $t['grid_item_id']]['score'];
        } elseif ($t['metric'] === 'observation' && isset($obsValueByField[(int) $t['observation_field_id']])) {
            $actual = $obsValueByField[(int) $t['observation_field_id']];
        }
        if ($actual !== null && threshold_breached($t['operator'], $actual, (float) $t['value'])) {
            $condition = threshold_label($t, threshold_target_label($t, $grid, $allObsFields));
            $breaches[] = [
                'threshold' => $t,
                'actual' => $actual,
                'text' => ($t['action_message'] ?: $condition) . ' (' . tr('{condition}, measured: {value}', ['condition' => $condition, 'value' => round($actual, 2)]) . ')',
            ];
        }
    }
    $endpointReached = $breaches ? 1 : 0;
    $breachedMessages = array_column($breaches, 'text');

    // Experimental acts confirmed with this entry (left empty: still to do).
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $observedAt) || $observedAt > date('Y-m-d')) {
        $fail(tr('Invalid date.'));
    }
    $actConfirmations = posted_act_confirmations($_POST, $observedAt);
    $confirmedActs = [];

    mysqli_begin_transaction($con);
    try {
        $stmt = $con->prepare(
            "INSERT INTO experiment_followups (experiment_animal_id, observed_at, weight_g, clinical_score, observations, endpoint_reached, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE weight_g = VALUES(weight_g), clinical_score = VALUES(clinical_score),
                observations = VALUES(observations), endpoint_reached = VALUES(endpoint_reached), recorded_by = VALUES(recorded_by)"
        );
        $stmt->bind_param("isdisii", $experimentAnimalId, $observedAt, $weight, $clinicalScore, $observations, $endpointReached, $currentUserId);
        $stmt->execute();
        $stmt->close();

        $fid = $con->prepare("SELECT id FROM experiment_followups WHERE experiment_animal_id = ? AND observed_at = ?");
        $fid->bind_param("is", $experimentAnimalId, $observedAt);
        $fid->execute();
        $followupId = (int) $fid->get_result()->fetch_assoc()['id'];
        $fid->close();

        // One entry per day: re-saving the day replaces its grid scores.
        $del = $con->prepare("DELETE FROM experiment_followup_scores WHERE followup_id = ?");
        $del->bind_param("i", $followupId);
        $del->execute();
        $del->close();
        foreach ($itemScores as $itemId => $level) {
            $label = $grid['items'][$itemId]['label'];
            $ins = $con->prepare("INSERT INTO experiment_followup_scores (followup_id, grid_item_id, item_label, score, level_description) VALUES (?, ?, ?, ?, ?)");
            $ins->bind_param("iisis", $followupId, $itemId, $label, $level['score'], $level['description']);
            $ins->execute();
            $ins->close();
        }

        // Re-saving the day replaces its additional observations too.
        $delObs = $con->prepare("DELETE FROM experiment_followup_observations WHERE followup_id = ?");
        $delObs->bind_param("i", $followupId);
        $delObs->execute();
        $delObs->close();
        foreach ($observationRows as $row) {
            $ins = $con->prepare("INSERT INTO experiment_followup_observations (followup_id, field_id, label, value_type, unit, value_number, value_text) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $ins->bind_param("iisssds", $followupId, $row['field_id'], $row['label'], $row['value_type'], $row['unit'], $row['number'], $row['text']);
            $ins->execute();
            $ins->close();
        }

        // Endpoints requiring an action open a pending action (one per endpoint at a time).
        $newActions = [];
        foreach ($breaches as $b) {
            $t = $b['threshold'];
            if ($t['required_action'] === 'none') {
                continue;
            }
            $pending = $con->prepare("SELECT 1 FROM endpoint_actions WHERE experiment_animal_id = ? AND threshold_id = ? AND status = 'pending'");
            $pending->bind_param("ii", $experimentAnimalId, $t['id']);
            $pending->execute();
            $alreadyPending = $pending->get_result()->num_rows > 0;
            $pending->close();
            if ($alreadyPending) {
                continue;
            }
            $ins = $con->prepare("INSERT INTO endpoint_actions (experiment_animal_id, followup_id, threshold_id, required_action, message, measured_value) VALUES (?, ?, ?, ?, ?, ?)");
            $ins->bind_param("iiissd", $experimentAnimalId, $followupId, $t['id'], $t['required_action'], $b['text'], $b['actual']);
            $ins->execute();
            $ins->close();
            $newActions[] = endpoint_required_actions()[$t['required_action']];
        }

        foreach ($actConfirmations as $actAnimalId => $raw) {
            confirm_experiment_act($con, $actAnimalId, $raw, $followupId, $currentUserId, $currentUserName, $experimentAnimalId);
            $confirmedActs[$actAnimalId] = $raw['status'];
        }

        // The cage's daily check is recorded once every animal under experiment in it has today's follow-up.
        $cageStmt = $con->prepare("SELECT cage_id FROM mice WHERE id = ?");
        $cageStmt->bind_param("i", $ea['animal_id']);
        $cageStmt->execute();
        $animalCage = $cageStmt->get_result()->fetch_assoc()['cage_id'] ?? null;
        $cageStmt->close();
        if ($animalCage && $observedAt === date('Y-m-d')) {
            record_experimental_cage_check_if_complete($con, $animalCage, $currentUserId, $currentUserName);
        }

        if ($endpointReached) {
            log_animal_event($con, (int) $ea['animal_id'], 'endpoint_alert', [
                'experiment_animal_id' => $experimentAnimalId,
                'observed_at' => $observedAt,
                'reasons' => $breachedMessages,
                'required_actions' => $newActions,
            ], $currentUserId);
            log_activity($con, 'endpoint_alert', 'animal', (string) $ea['animal_id'], implode(' | ', $breachedMessages), $currentUserId);
            notify_admins(
                $con,
                ($newActions ? tr('ACTION REQUIRED ({actions}): ', ['actions' => implode(', ', $newActions)]) : tr('Endpoint reached: ')) . $ea['animal_uid'],
                tr('Experiment {number} ({protocol}): ', ['number' => $ea['experiment_number'], 'protocol' => $ea['protocol_number']]) . implode(' | ', $breachedMessages),
                $selfUrl,
                'endpoint_alert'
            );
        }
        foreach ($confirmedActs as $actAnimalId => $status) {
            log_animal_event($con, (int) $ea['animal_id'], 'experiment_act', ['act_animal_id' => $actAnimalId, 'status' => $status, 'experiment_animal_id' => $experimentAnimalId], $currentUserId);
        }
        mysqli_commit($con);
    } catch (Exception $e) {
        mysqli_rollback($con);
        $_SESSION['message'] = tr('Follow-up not saved: {error}', ['error' => $e->getMessage()]);
        header("Location: $selfUrl");
        exit;
    }

    $_SESSION['message'] = $endpointReached
        ? tr('Saved. ENDPOINT REACHED: {details}', ['details' => implode(' | ', $breachedMessages)]) . ($newActions ? ' — ' . tr('ACTION REQUIRED: {actions}', ['actions' => implode(', ', $newActions)]) : '')
        : tr('Follow-up saved.') . ($hasGrid ? ' ' . tr('Clinical score: {score} / {max}.', ['score' => $clinicalScore, 'max' => $grid['max_total']]) : '');
    if ($confirmedActs) {
        $_SESSION['message'] .= ' ' . tr('{n} act(s) confirmed.', ['n' => count($confirmedActs)]);
    }

    header("Location: " . ($returnUrl ?? $selfUrl));
    exit();
}

$history = $con->prepare(
    "SELECT f.*, u.name AS recorded_by_name FROM experiment_followups f
     LEFT JOIN users u ON u.id = f.recorded_by
     WHERE f.experiment_animal_id = ? ORDER BY f.observed_at DESC"
);
$history->bind_param("i", $experimentAnimalId);
$history->execute();
$history = $history->get_result()->fetch_all(MYSQLI_ASSOC);

// Item scores of the history, keyed by follow-up then item label (snapshots).
$scoresByFollowup = [];
$historyLabels = [];
if ($history) {
    $ids = implode(',', array_map('intval', array_column($history, 'id')));
    foreach ($con->query("SELECT followup_id, item_label, score FROM experiment_followup_scores WHERE followup_id IN ($ids) ORDER BY id") as $sc) {
        $scoresByFollowup[$sc['followup_id']][$sc['item_label']] = $sc['score'];
        $historyLabels[$sc['item_label']] = true;
    }
}
$historyLabels = array_keys($historyLabels);

// Additional observations of the history: protocol fields as columns (by
// label snapshot), free observations listed together.
$obsByFollowup = [];
$obsColumns = array_map(fn($f) => $f['label'], $obsFields);
if ($history) {
    foreach ($con->query("SELECT * FROM experiment_followup_observations WHERE followup_id IN ($ids) ORDER BY id") as $o) {
        if ($o['field_id'] !== null) {
            $obsByFollowup[$o['followup_id']]['fields'][$o['label']] = $o;
            if (!in_array($o['label'], $obsColumns, true)) {
                $obsColumns[] = $o['label'];
            }
        } else {
            $obsByFollowup[$o['followup_id']]['free'][] = $o;
        }
    }
}

// Entries already recorded, by date, so that choosing a date already entered
// loads its current values into the form (re-saving replaces the day).
$entriesByDate = [];
$trimNumber = fn($n): string => $n === null ? '' : rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
foreach ($history as $h) {
    $entriesByDate[$h['observed_at']] = [
        'weight' => $trimNumber($h['weight_g']),
        'clinical_score' => $h['clinical_score'] === null ? '' : (string) $h['clinical_score'],
        'observations' => (string) ($h['observations'] ?? ''),
        'by' => (string) ($h['recorded_by_name'] ?? ''),
        'at' => substr((string) $h['created_at'], 0, 16),
        'endpoint' => (bool) $h['endpoint_reached'],
        'scores' => new stdClass(),
        'fields' => new stdClass(),
        'free' => [],
    ];
}
if ($history) {
    $idToDate = array_column($history, 'observed_at', 'id');
    foreach ($con->query("SELECT followup_id, grid_item_id, score FROM experiment_followup_scores WHERE followup_id IN ($ids) AND grid_item_id IS NOT NULL") as $sc) {
        $entriesByDate[$idToDate[$sc['followup_id']]]['scores']->{$sc['grid_item_id']} = (int) $sc['score'];
    }
    foreach ($con->query("SELECT * FROM experiment_followup_observations WHERE followup_id IN ($ids) ORDER BY id") as $o) {
        $value = $o['value_type'] === 'number' ? $trimNumber($o['value_number']) : (string) $o['value_text'];
        if ($o['field_id'] !== null) {
            $entriesByDate[$idToDate[$o['followup_id']]]['fields']->{$o['field_id']} = $value;
        } else {
            $entriesByDate[$idToDate[$o['followup_id']]]['free'][] = ['label' => $o['label'], 'type' => $o['value_type'], 'value' => $value, 'unit' => (string) ($o['unit'] ?? '')];
        }
    }
}

$actions = $con->prepare("SELECT * FROM endpoint_actions WHERE experiment_animal_id = ? ORDER BY status = 'pending' DESC, created_at DESC");
$actions->bind_param("i", $experimentAnimalId);
$actions->execute();
$actions = $actions->get_result()->fetch_all(MYSQLI_ASSOC);
$pendingActions = array_filter($actions, fn($a) => $a['status'] === 'pending');

$isFollowed = $ea['animal_status'] === 'active' && $ea['removed_at'] === null;

// Experimental acts: still to do up to today, and those already confirmed.
$acts = enrollment_acts($con, $experimentAnimalId, date('Y-m-d'));
$actFields = experiment_act_fields($con, array_column($acts, 'act_id'));
$actValues = experiment_act_values($con, array_column(array_filter($acts, fn($a) => $a['status'] === 'done'), 'id'));
$lotsByProduct = array_filter(array_column($acts, 'product_id')) ? usable_lots_by_product($con) : [];
$plannedActs = array_filter($acts, fn($a) => $a['status'] === 'planned');
$confirmedActList = array_reverse(array_filter($acts, fn($a) => $a['status'] !== 'planned'));
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$allActRowIds = $con->query('SELECT id FROM experiment_act_animals WHERE experiment_animal_id = ' . (int) $experimentAnimalId)->fetch_all(MYSQLI_ASSOC);
$actCorrections = experiment_act_corrections($con, array_column($allActRowIds, 'id'));
// Weight used to suggest the volume of a dose by weight: the one typed in the
// form, else the latest known weight.
$latestWeight = enrollment_latest_weights($con, [$experimentAnimalId])[$experimentAnimalId] ?? null;

// Render the shared header only after request handling. Including it before the
// POST redirect sends HTML output and prevents PHP from modifying headers.
require 'header.php';
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Daily Follow-up') ?> | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container {
            max-width: 1100px;
        }

        .grid-item .btn-check+label {
            text-align: left;
        }
    </style>
</head>

<body>
    <div class="container mt-4 content">
        <h4><?= trh('Daily Follow-up') ?> — <?= htmlspecialchars($ea['animal_uid']); ?>
            <?php if ($ea['distinctive_mark']) : ?><small class="text-muted" id="animalMark">[<?= htmlspecialchars($ea['distinctive_mark']); ?>]</small><?php endif; ?></h4>
        <p class="text-muted"><?= trh('Experiment {number}', ['number' => $ea['experiment_number']]); ?> — <?= htmlspecialchars($ea['experiment_title']); ?> (<?= trh('Protocol {number}', ['number' => $ea['protocol_number']]); ?>)
            · <?= trh('baseline weight {weight} g', ['weight' => $ea['baseline_weight_g'] ?? tr('N/A')]); ?></p>
        <div class="d-flex gap-2 mb-3">
            <a href="<?= htmlspecialchars($backUrl); ?>">&laquo; Retour à l’expérimentation</a>
            <a href="animal_view.php?id=<?= (int) $ea['animal_id']; ?>" class="text-muted">Voir la fiche de l’animal</a>
        </div>

        <?php include('message.php'); ?>
        <?= training_warning_banner($con) ?>

        <?php foreach ($pendingActions as $a) : ?>
            <div class="alert alert-danger" id="pendingAction<?= (int) $a['id']; ?>">
                <h5 class="alert-heading"><i class="fas fa-exclamation-triangle"></i> <?= trh('ACTION REQUIRED: {actions}', ['actions' => endpoint_required_actions()[$a['required_action']]]); ?></h5>
                <p class="mb-2"><?= htmlspecialchars($a['message'] ?? ''); ?> — <?= trh('since {date}', ['date' => $a['created_at']]); ?></p>
                <?php if ($a['required_action'] === 'euthanasia') : ?>
                    <a href="animal_exit.php?<?= htmlspecialchars(http_build_query(['id' => $ea['animal_id'], 'code' => 'point_limite', 'return' => $backUrl])); ?>" class="btn btn-danger"><?= trh('Record the euthanasia (exit: endpoint reached)') ?></a>
                <?php else : ?>
                    <form method="POST" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="experiment_animal_id" value="<?= $experimentAnimalId; ?>">
                        <input type="hidden" name="return" value="<?= htmlspecialchars($returnUrl ?? '') ?>">
                        <input type="hidden" name="action_id" value="<?= (int) $a['id']; ?>">
                        <div class="col-md-9">
                            <input type="text" class="form-control" name="done_note" required placeholder="<?= trh('What was done (e.g. Dr X called at 10:30 — analgesia, re-check tonight)') ?>">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" name="complete_action" class="btn btn-outline-light bg-danger w-100"><?= trh('Mark as done') ?></button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($thresholds || $ea['endpoint_criteria']) : ?>
            <div class="alert alert-secondary">
                <strong><?= trh('Endpoints (points limites) of this protocol:') ?></strong>
                <?php if ($ea['endpoint_criteria']) : ?><div><?= nl2br(htmlspecialchars($ea['endpoint_criteria'])); ?></div><?php endif; ?>
                <ul class="mb-0">
                    <?php foreach ($thresholds as $t) : ?>
                        <li><?= htmlspecialchars(threshold_label($t, threshold_target_label($t, $grid, $allObsFields))); ?>
                            <?php if ($t['required_action'] !== 'none') : ?> → <strong><?= htmlspecialchars(endpoint_required_actions()[$t['required_action']]); ?></strong><?php endif; ?>
                            <?= $t['action_message'] ? '— ' . htmlspecialchars($t['action_message']) : ''; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($isFollowed) : ?>
            <div class="card my-4">
                <div class="card-body">
                    <h5><?= trh('New / Update Entry (one per day)') ?></h5>
                    <div class="alert py-2 d-none" id="entryMode"></div>
                    <form method="POST" id="followupForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="experiment_animal_id" value="<?= $experimentAnimalId; ?>">
                        <input type="hidden" name="return" value="<?= htmlspecialchars($returnUrl ?? '') ?>">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label"><?= trh('Date') ?> *</label>
                                <input type="date" class="form-control" name="observed_at" id="observed_at" required value="<?= htmlspecialchars(preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') && $_GET['date'] <= date('Y-m-d') ? $_GET['date'] : date('Y-m-d')); ?>" max="<?= date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label"><?= trh('Weight (g)') ?></label>
                                <input type="number" step="0.01" class="form-control" name="weight_g" id="weight_g">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label"><?= trh('Clinical score') ?><?= $hasGrid ? ' (' . trh('grid total') . ')' : ''; ?></label>
                                <?php if ($hasGrid) : ?>
                                    <div class="form-control-plaintext fs-5"><strong id="gridTotal">–</strong> / <?= (int) $grid['max_total']; ?></div>
                                <?php else : ?>
                                    <input type="number" class="form-control" name="clinical_score" id="clinical_score">
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($hasGrid) : ?>
                            <h6><?= htmlspecialchars($grid['name']); ?></h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered align-middle">
                                    <?php foreach ($grid['items'] as $itemId => $item) : ?>
                                        <tr class="grid-item" data-item="<?= (int) $itemId; ?>">
                                            <th style="width: 20%;"><?= htmlspecialchars($item['label']); ?> *</th>
                                            <td>
                                                <?php foreach ($item['levels'] as $lv) : $rid = "s{$itemId}_{$lv['score']}"; ?>
                                                    <input type="radio" class="btn-check grid-score" name="score[<?= (int) $itemId; ?>]" id="<?= $rid; ?>" value="<?= (int) $lv['score']; ?>" required>
                                                    <label class="btn btn-sm btn-outline-secondary mb-1" for="<?= $rid; ?>"><strong><?= (int) $lv['score']; ?></strong> <?= htmlspecialchars($lv['description']); ?></label>
                                                <?php endforeach; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        <?php endif; ?>

                        <?php if ($obsFields) : ?>
                            <h6><?= trh('Observations of the protocol') ?></h6>
                            <div class="row">
                                <?php foreach ($obsFields as $fieldId => $field) : ?>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label" for="obs<?= (int) $fieldId; ?>"><?= htmlspecialchars($field['label']); ?><?= $field['unit'] ? ' (' . htmlspecialchars($field['unit']) . ')' : ''; ?><?= $field['required'] ? ' *' : ''; ?></label>
                                        <?php if ($field['value_type'] === 'number') : ?>
                                            <input type="text" inputmode="decimal" pattern="-?[0-9]+([.,][0-9]+)?" class="form-control" id="obs<?= (int) $fieldId; ?>" name="obs[<?= (int) $fieldId; ?>]" <?= $field['required'] ? 'required' : ''; ?>>
                                        <?php else : ?>
                                            <textarea class="form-control" id="obs<?= (int) $fieldId; ?>" name="obs[<?= (int) $fieldId; ?>]" rows="1" <?= $field['required'] ? 'required' : ''; ?>></textarea>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <h6><?= trh('Free observations') ?> <small class="text-muted">(<?= trh('written or numeric, for this entry only') ?>)</small></h6>
                        <div id="extraObs"></div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="addExtraObs"><i class="fas fa-plus"></i> <?= trh('Add an observation') ?></button>
                        <template id="extraObsTpl">
                            <div class="row g-2 mb-2 extra-obs">
                                <div class="col-md-3"><input type="text" class="form-control form-control-sm" name="extra_label[]" placeholder="<?= trh('Label (e.g. Food intake)') ?>"></div>
                                <div class="col-md-2">
                                    <select class="form-select form-select-sm extra-type" name="extra_type[]">
                                        <option value="text"><?= trh('Text') ?></option>
                                        <option value="number"><?= trh('Number value') ?></option>
                                    </select>
                                </div>
                                <div class="col-md-4"><input type="text" class="form-control form-control-sm" name="extra_value[]" placeholder="<?= trh('Value') ?>"></div>
                                <div class="col-md-2"><input type="text" class="form-control form-control-sm extra-unit" name="extra_unit[]" placeholder="<?= trh('Unit') ?>"></div>
                                <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger remove-extra" title="<?= trh('Remove') ?>">&times;</button></div>
                            </div>
                        </template>

                        <?php if ($plannedActs || $confirmedActList) : ?>
                            <div id="actsSection" class="mb-3">
                                <h6><i class="fas fa-syringe"></i> <?= trh('Experimental acts') ?> <small class="text-muted">(<?= trh('tick each act done; leave “later” if it is not done yet') ?>)</small></h6>
                                <?php if ($plannedActs) : ?><?= training_supervisor_field($con) ?><?php endif; ?>
                                <?php foreach ($plannedActs as $act) :
                                    $aid = (int) $act['id'];
                                    $fields = $actFields[(int) $act['act_id']] ?? []; ?>
                                    <div class="border rounded p-2 mb-2 act-row" data-date="<?= htmlspecialchars($act['planned_date']) ?>" data-status="planned">
                                        <div class="d-flex justify-content-between flex-wrap gap-2">
                                            <div>
                                                <span class="badge bg-primary"><?= htmlspecialchars(experiment_act_types()[$act['act_type']] ?? $act['act_type']) ?></span>
                                                <strong><?= htmlspecialchars($act['label']) ?></strong>
                                                <?php if ($summary = experiment_act_summary($act)) : ?><span class="text-muted">— <?= htmlspecialchars($summary) ?></span><?php endif; ?>
                                                <span class="badge bg-warning text-dark act-overdue d-none"><?= trh('planned on {date}', ['date' => $act['planned_date']]) ?></span>
                                                <?php if ($act['instructions']) : ?><div class="small"><i class="fas fa-info-circle"></i> <?= htmlspecialchars($act['instructions']) ?></div><?php endif; ?>
                                            </div>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <input type="radio" class="btn-check act-status" name="act_status[<?= $aid ?>]" id="act<?= $aid ?>_later" value="" checked>
                                                <label class="btn btn-outline-secondary" for="act<?= $aid ?>_later"><?= trh('Later') ?></label>
                                                <input type="radio" class="btn-check act-status" name="act_status[<?= $aid ?>]" id="act<?= $aid ?>_done" value="done">
                                                <label class="btn btn-outline-success" for="act<?= $aid ?>_done"><i class="fas fa-check"></i> <?= trh('Act done') ?></label>
                                                <input type="radio" class="btn-check act-status" name="act_status[<?= $aid ?>]" id="act<?= $aid ?>_not" value="not_done">
                                                <label class="btn btn-outline-danger" for="act<?= $aid ?>_not"><?= trh('Not done') ?></label>
                                            </div>
                                        </div>
                                        <div class="row g-2 mt-1 act-details d-none">
                                            <div class="col-md-2">
                                                <label class="form-label small mb-0"><?= trh('Time') ?></label>
                                                <input type="time" class="form-control form-control-sm" name="act_time[<?= $aid ?>]" value="<?= date('H:i') ?>">
                                            </div>
                                            <?php foreach ($fields as $fieldId => $f) : ?>
                                                <div class="col-md-3 act-done-only">
                                                    <label class="form-label small mb-0"><?= htmlspecialchars($f['label']) ?><?= $f['unit'] ? ' (' . htmlspecialchars($f['unit']) . ')' : '' ?><?= $f['required'] ? ' *' : '' ?></label>
                                                    <input type="text" class="form-control form-control-sm" name="act_value[<?= $aid ?>][<?= (int) $fieldId ?>]" <?= $f['value_type'] === 'number' ? 'inputmode="decimal" pattern="-?[0-9]+([.,][0-9]+)?"' : '' ?> data-required="<?= (int) $f['required'] ?>">
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if ($act['product_id']) : ?>
                                                <div class="col-md-4 act-done-only">
                                                    <label class="form-label small mb-0"><?= trh('Lot of {product}', ['product' => $act['product_name']]) ?> *</label>
                                                    <select class="form-select form-select-sm" name="act_lot[<?= $aid ?>]" data-required="1"><?= act_lot_options($lotsByProduct, (int) $act['product_id'], isset($lotsByProduct[(int) $act['product_id']]) ? (int) $lotsByProduct[(int) $act['product_id']][0]['id'] : null) ?></select>
                                                </div>
                                                <div class="col-md-2 act-done-only">
                                                    <label class="form-label small mb-0"><?= trh('Quantity ({unit})', ['unit' => $act['product_unit']]) ?> *</label>
                                                    <?php $perGram = act_quantity_per_gram($act);
                                                    $suggested = $perGram ? act_suggested_quantity($act, $latestWeight['weight'] ?? null) : null; ?>
                                                    <input type="text" class="form-control form-control-sm act-qty" name="act_qty[<?= $aid ?>]" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?" value="<?= htmlspecialchars($suggested !== null ? act_number((string) $suggested) : act_number($act['quantity_per_animal'])) ?>" data-required="1"<?= $perGram ? ' data-per-gram="' . htmlspecialchars((string) $perGram[0]) . '"' : '' ?>>
                                                </div>
                                                <?php if ($perGram) : ?>
                                                    <div class="col-12 act-done-only small text-success act-weight-hint" data-formula="<?= htmlspecialchars($perGram[1]) ?>" data-latest="<?= htmlspecialchars($latestWeight ? act_number((string) $latestWeight['weight']) : '') ?>" data-latest-date="<?= htmlspecialchars($latestWeight['date'] ?? tr('baseline')) ?>"></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <div class="<?= $fields || $act['product_id'] ? 'col-12' : 'col' ?>">
                                                <label class="form-label small mb-0 act-note-label" data-done="<?= trh('Note') ?>" data-not="<?= trh('Reason why it was not done') ?> *"><?= trh('Note') ?></label>
                                                <input type="text" class="form-control form-control-sm act-note" name="act_note[<?= $aid ?>]">
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php foreach ($confirmedActList as $act) : ?>
                                    <div class="border rounded p-2 mb-2 act-row <?= $act['status'] === 'done' ? 'border-success' : 'border-danger' ?>" data-date="<?= htmlspecialchars($act['planned_date']) ?>" data-status="<?= htmlspecialchars($act['status']) ?>">
                                        <span class="badge bg-<?= $act['status'] === 'done' ? 'success' : 'danger' ?>"><?= htmlspecialchars(experiment_act_statuses()[$act['status']]) ?></span>
                                        <strong><?= htmlspecialchars($act['label']) ?></strong>
                                        <?php if ($summary = experiment_act_summary($act)) : ?><span class="text-muted">— <?= htmlspecialchars($summary) ?></span><?php endif; ?>
                                        <span class="small text-muted">· <?= htmlspecialchars(substr((string) $act['done_at'], 0, 16) . ' — ' . act_done_by_text($act)) ?></span>
                                        <div class="small">
                                            <?php if ($act['medication_lot_id']) : ?><?= trh('{qty} {unit}, lot {lot}', ['qty' => act_number($act['quantity_used']), 'unit' => $act['product_unit'], 'lot' => $act['lot_number']]) ?> · <?php endif; ?>
                                            <?= implode(' · ', array_map(fn($v) => htmlspecialchars($v['label'] . ' : ' . observation_value($v)), $actValues[(int) $act['id']] ?? [])) ?>
                                            <?= $act['note'] ? '<em>' . htmlspecialchars($act['note']) . '</em>' : '' ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label"><?= trh('General observations') ?></label>
                            <textarea class="form-control" name="observations" id="general_observations"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary" id="followupSubmit"><?= trh('Save Follow-up') ?></button>
                    </form>
                </div>
            </div>
        <?php else : ?>
            <div class="alert alert-secondary my-3"><?= trh('This animal is no longer followed in this experiment') ?><?= $ea['removed_at'] ? ' (' . trh('since {date}', ['date' => $ea['removed_at']]) . ')' : ''; ?>.</div>
        <?php endif; ?>

        <h5><?= trh('History') ?></h5>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th><?= trh('Date') ?></th>
                        <th><?= trh('Weight (g)') ?></th>
                        <th><?= trh('Loss %') ?></th>
                        <?php foreach ($historyLabels as $label) : ?><th><?= htmlspecialchars($label); ?></th><?php endforeach; ?>
                        <th><?= trh('Clinical score') ?></th>
                        <?php foreach ($obsColumns as $label) : ?><th><?= htmlspecialchars($label); ?></th><?php endforeach; ?>
                        <th><?= trh('Other observations') ?></th>
                        <th><?= trh('Observations') ?></th>
                        <th><?= trh('Endpoint') ?></th>
                        <th><?= trh('By') ?></th>
                        <?php if ($isFollowed) : ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $h) : ?>
                        <tr class="<?= $h['endpoint_reached'] ? 'table-danger' : ''; ?>">
                            <td class="text-nowrap"><a href="<?= htmlspecialchars('experiment_followup_view.php?' . http_build_query(['id' => $h['id'], 'return' => $selfUrl])); ?>" title="<?= trh('Read the full evaluation') ?>"><?= htmlspecialchars($h['observed_at']); ?> <i class="fas fa-search small"></i></a></td>
                            <td><?= htmlspecialchars($h['weight_g'] ?? '-'); ?></td>
                            <td><?= ($h['weight_g'] !== null && !empty($ea['baseline_weight_g'])) ? round(($ea['baseline_weight_g'] - $h['weight_g']) / $ea['baseline_weight_g'] * 100, 1) : '-'; ?></td>
                            <?php foreach ($historyLabels as $label) : ?><td><?= htmlspecialchars((string) ($scoresByFollowup[$h['id']][$label] ?? '-')); ?></td><?php endforeach; ?>
                            <td><strong><?= htmlspecialchars($h['clinical_score'] ?? '-'); ?></strong></td>
                            <?php foreach ($obsColumns as $label) : $o = $obsByFollowup[$h['id']]['fields'][$label] ?? null; ?>
                                <td><?= $o ? htmlspecialchars(observation_value($o)) : '-'; ?></td>
                            <?php endforeach; ?>
                            <td><?= implode('<br>', array_map(fn($o) => '<em>' . htmlspecialchars($o['label']) . ':</em> ' . htmlspecialchars(observation_value($o)), $obsByFollowup[$h['id']]['free'] ?? [])); ?></td>
                            <td><?= htmlspecialchars($h['observations'] ?? ''); ?></td>
                            <td><?= $h['endpoint_reached'] ? trh('YES') : trh('No'); ?></td>
                            <td><?= htmlspecialchars($h['recorded_by_name'] ?? ''); ?></td>
                            <?php if ($isFollowed) : ?><td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="#followupForm" data-edit-date="<?= htmlspecialchars($h['observed_at']); ?>"><i class="fas fa-pen"></i> <?= trh('Edit') ?></a></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($confirmedActList) : ?>
            <h5 id="actsHistory"><?= trh('Experimental acts confirmed') ?></h5>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr><th><?= trh('Planned') ?></th><th><?= trh('Act') ?></th><th><?= trh('Status') ?></th><th><?= trh('Done on') ?></th><th><?= trh('Medication') ?></th><th><?= trh('Information') ?></th><th><?= trh('By') ?></th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($confirmedActList as $act) : $canCorrect = $isAdmin || (int) $act['done_by'] === (int) $currentUserId; ?>
                            <tr class="<?= $act['status'] === 'not_done' ? 'table-danger' : '' ?>">
                                <td class="text-nowrap"><?= htmlspecialchars($act['planned_date']) ?></td>
                                <td><?= htmlspecialchars($act['label']) ?><?php if ($summary = experiment_act_summary($act)) : ?> <small class="text-muted">(<?= htmlspecialchars($summary) ?>)</small><?php endif; ?></td>
                                <td><?= htmlspecialchars(experiment_act_statuses()[$act['status']]) ?></td>
                                <td class="text-nowrap"><?= htmlspecialchars(substr((string) $act['done_at'], 0, 16)) ?></td>
                                <td><?= $act['medication_lot_id'] ? trh('{qty} {unit}, lot {lot}', ['qty' => act_number($act['quantity_used']), 'unit' => $act['product_unit'], 'lot' => $act['lot_number']]) : '' ?></td>
                                <td><?= implode('<br>', array_map(fn($v) => htmlspecialchars($v['label'] . ' : ' . observation_value($v)), $actValues[(int) $act['id']] ?? [])) ?><?= $act['note'] ? ' <em>' . htmlspecialchars($act['note']) . '</em>' : '' ?></td>
                                <td><?= htmlspecialchars(act_done_by_text($act)) ?></td>
                                <td class="text-nowrap">
                                    <?php if ($canCorrect) : ?>
                                        <details>
                                            <summary class="btn btn-sm btn-outline-secondary"><i class="fas fa-undo"></i> <?= trh('Correct') ?></summary>
                                            <form method="POST" class="mt-1" style="min-width: 18rem" onsubmit="return confirm(<?= htmlspecialchars(trj('Cancel this confirmation? The act will be to do again; a medication given is re-credited to its lot.')) ?>)">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="experiment_animal_id" value="<?= $experimentAnimalId ?>">
                                                <input type="hidden" name="return" value="<?= htmlspecialchars($returnUrl ?? '') ?>">
                                                <input type="hidden" name="act_animal_id" value="<?= (int) $act['id'] ?>">
                                                <input type="text" class="form-control form-control-sm mb-1" name="correction_reason" required placeholder="<?= trh('Reason of the correction (e.g. wrong animal, wrong volume)') ?>">
                                                <button class="btn btn-sm btn-warning" name="correct_act"><?= trh('Cancel the confirmation') ?></button>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($actCorrections) : ?>
            <h6><?= trh('Corrections of act confirmations') ?></h6>
            <ul class="small">
                <?php foreach ($actCorrections as $actAnimalId => $list) : foreach ($list as $c) : ?>
                    <li><?= trh('Act #{id}', ['id' => $actAnimalId]) ?> — <?= htmlspecialchars(act_correction_text($c)) ?></li>
                <?php endforeach; endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($actions) : ?>
            <h5><?= trh('Endpoint actions') ?></h5>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th><?= trh('Raised') ?></th>
                        <th><?= trh('Required action') ?></th>
                        <th><?= trh('Reason') ?></th>
                        <th><?= trh('Status') ?></th>
                        <th><?= trh('Done') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($actions as $a) : ?>
                        <tr>
                            <td><?= htmlspecialchars($a['created_at']); ?></td>
                            <td><?= htmlspecialchars(endpoint_required_actions()[$a['required_action']]); ?></td>
                            <td><?= htmlspecialchars($a['message'] ?? ''); ?></td>
                            <td><span class="badge bg-<?= $a['status'] === 'pending' ? 'danger' : 'success'; ?>"><?= trh($a['status']); ?></span></td>
                            <td><?= $a['done_at'] ? htmlspecialchars($a['done_at'] . ' — ' . ($a['done_by_name'] ?? '') . ': ' . ($a['done_note'] ?? '')) : ''; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <script>
        // Free observation rows: add / remove, unit only for numbers.
        (function() {
            const box = document.getElementById('extraObs');
            const tpl = document.getElementById('extraObsTpl');
            const add = document.getElementById('addExtraObs');
            if (!box || !tpl || !add) return;
            window.addExtraObsRow = (values, focus) => {
                const row = tpl.content.firstElementChild.cloneNode(true);
                const type = row.querySelector('.extra-type');
                const unit = row.querySelector('.extra-unit');
                if (values) {
                    row.querySelector('[name="extra_label[]"]').value = values.label;
                    type.value = values.type;
                    row.querySelector('[name="extra_value[]"]').value = values.value;
                    unit.value = values.unit;
                }
                const sync = () => unit.style.visibility = type.value === 'number' ? 'visible' : 'hidden';
                type.addEventListener('change', sync);
                sync();
                row.querySelector('.remove-extra').addEventListener('click', () => row.remove());
                box.appendChild(row);
                if (focus) row.querySelector('input').focus();
            };
            add.addEventListener('click', () => window.addExtraObsRow(null, true));
        })();

        // Live total of the grid scores.
        (function() {
            const total = document.getElementById('gridTotal');
            if (!total) return;
            const update = () => {
                const rows = document.querySelectorAll('.grid-item');
                let sum = 0, scored = 0;
                rows.forEach(r => {
                    const c = r.querySelector('.grid-score:checked');
                    if (c) { sum += Number(c.value); scored++; }
                });
                total.textContent = scored === rows.length ? sum : sum + ' (' + <?= trj('{scored}/{total} items') ?>.replace('{scored}', scored).replace('{total}', rows.length) + ')';
            };
            document.querySelectorAll('.grid-score').forEach(i => i.addEventListener('change', update));
            update();
            window.updateGridTotal = update;
        })();

        // Dose by weight: the quantity follows the weight typed in the form (or
        // the latest known weight) until it is changed by hand.
        (function() {
            const weightInput = document.getElementById('weight_g');
            const qtys = [...document.querySelectorAll('.act-qty[data-per-gram]')];
            if (!qtys.length) return;
            const texts = {
                typed: <?= trj('{formula} = {qty} {unit} for {weight} g (weight entered)') ?>,
                latest: <?= trj('{formula} = {qty} {unit} for {weight} g (weight of {date})') ?>,
                none: <?= trj('{formula}: enter the weight to calculate the volume.') ?>
            };
            const unit = 'mL';
            qtys.forEach(q => q.addEventListener('input', () => q.dataset.manual = '1'));
            const sync = () => {
                const typed = parseFloat((weightInput && weightInput.value || '').replace(',', '.'));
                qtys.forEach(q => {
                    const row = q.closest('.act-details');
                    const hint = row.querySelector('.act-weight-hint');
                    const latest = parseFloat(hint.dataset.latest);
                    const weight = typed > 0 ? typed : (latest > 0 ? latest : null);
                    if (!weight) { hint.textContent = texts.none.replace('{formula}', hint.dataset.formula); return; }
                    const qty = Math.round(parseFloat(q.dataset.perGram) * weight * 1000) / 1000;
                    if (!q.dataset.manual) q.value = qty;
                    hint.textContent = (typed > 0 ? texts.typed : texts.latest.replace('{date}', hint.dataset.latestDate))
                        .replace('{formula}', hint.dataset.formula).replace('{qty}', qty).replace('{unit}', unit).replace('{weight}', weight);
                });
            };
            if (weightInput) weightInput.addEventListener('input', sync);
            document.getElementById('observed_at')?.addEventListener('change', () => setTimeout(sync));
            document.querySelectorAll('[data-edit-date]').forEach(a => a.addEventListener('click', () => setTimeout(sync)));
            setTimeout(sync);
        })();

        // Experimental acts: the ones still to do up to the chosen day (overdue
        // flagged) and the ones confirmed for that day; details once ticked.
        (function() {
            const section = document.getElementById('actsSection');
            const dateInput = document.getElementById('observed_at');
            if (!section || !dateInput) return;
            const rows = [...section.querySelectorAll('.act-row')];
            const syncRow = row => {
                const visible = !row.hidden;
                const checked = row.querySelector('.act-status:checked');
                const status = checked ? checked.value : '';
                const details = row.querySelector('.act-details');
                if (!details) return;
                details.classList.toggle('d-none', !visible || status === '');
                details.querySelectorAll('.act-done-only').forEach(el => el.classList.toggle('d-none', status !== 'done'));
                details.querySelectorAll('input, select').forEach(el => {
                    const inDone = el.closest('.act-done-only');
                    el.disabled = !visible || status === '' || (inDone && status !== 'done');
                    el.required = !el.disabled && el.dataset.required === '1';
                });
                const note = row.querySelector('.act-note');
                const label = row.querySelector('.act-note-label');
                note.required = visible && status === 'not_done';
                label.textContent = status === 'not_done' ? label.dataset.not : label.dataset.done;
                row.querySelectorAll('.act-status').forEach(r => r.disabled = !visible);
            };
            const sync = () => {
                const date = dateInput.value;
                let shown = 0;
                rows.forEach(row => {
                    const planned = row.dataset.status === 'planned';
                    row.hidden = planned ? row.dataset.date > date : row.dataset.date !== date;
                    const overdue = row.querySelector('.act-overdue');
                    if (overdue) overdue.classList.toggle('d-none', row.dataset.date >= date);
                    if (!row.hidden) shown++;
                    syncRow(row);
                });
                section.hidden = shown === 0;
            };
            section.querySelectorAll('.act-status').forEach(r => r.addEventListener('change', () => syncRow(r.closest('.act-row'))));
            dateInput.addEventListener('change', sync);
            document.querySelectorAll('[data-edit-date]').forEach(a => a.addEventListener('click', () => setTimeout(sync)));
            sync();
        })();

        // Choosing a date already entered loads that day's current values (the
        // form then updates it); another date starts from an empty form.
        (function() {
            const form = document.getElementById('followupForm');
            if (!form) return;
            const entries = <?= json_encode($entriesByDate, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const dateInput = document.getElementById('observed_at');
            const mode = document.getElementById('entryMode');
            const submit = document.getElementById('followupSubmit');
            const texts = {
                edit: <?= trj('Editing the follow-up of {date}, entered by {user} on {at}: its current values are loaded; saving replaces them.') ?>,
                endpoint: <?= trj('An endpoint was reached on that day.') ?>,
                create: <?= trj('New follow-up for {date}.') ?>,
                saveEdit: <?= trj('Save the changes') ?>,
                saveNew: <?= trj('Save Follow-up') ?>
            };
            const setValue = (el, value) => { if (el) el.value = value ?? ''; };
            const load = () => {
                const date = dateInput.value;
                const entry = entries[date] || null;
                setValue(document.getElementById('weight_g'), entry ? entry.weight : '');
                setValue(document.getElementById('clinical_score'), entry ? entry.clinical_score : '');
                setValue(document.getElementById('general_observations'), entry ? entry.observations : '');
                form.querySelectorAll('.grid-score').forEach(r => {
                    const item = r.closest('.grid-item').dataset.item;
                    r.checked = !!entry && entry.scores[item] !== undefined && String(entry.scores[item]) === r.value;
                });
                form.querySelectorAll('[name^="obs["]').forEach(f => {
                    const id = f.name.match(/^obs\[(\d+)\]$/)[1];
                    f.value = entry && entry.fields[id] !== undefined ? entry.fields[id] : '';
                });
                document.querySelectorAll('#extraObs .extra-obs').forEach(r => r.remove());
                if (entry && window.addExtraObsRow) entry.free.forEach(o => window.addExtraObsRow(o, false));
                if (window.updateGridTotal) window.updateGridTotal();
                mode.classList.remove('d-none', 'alert-info', 'alert-warning');
                if (entry) {
                    mode.classList.add('alert-warning');
                    mode.textContent = texts.edit.replace('{date}', date).replace('{user}', entry.by || '—').replace('{at}', entry.at)
                        + (entry.endpoint ? ' ' + texts.endpoint : '');
                    submit.textContent = texts.saveEdit;
                } else {
                    mode.classList.add('alert-info');
                    mode.textContent = texts.create.replace('{date}', date);
                    submit.textContent = texts.saveNew;
                }
            };
            dateInput.addEventListener('change', load);
            document.querySelectorAll('[data-edit-date]').forEach(a => a.addEventListener('click', () => {
                dateInput.value = a.dataset.editDate;
                load();
            }));
            load();
        })();
    </script>

    <br>
    <?php require 'footer.php'; ?>
</body>

</html>
