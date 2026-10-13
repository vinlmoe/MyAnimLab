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
 */

session_start();
require 'dbcon.php';
require 'animal_lib.php';

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

$eaStmt = $con->prepare(
    "SELECT ea.*, m.animal_uid, m.id AS animal_id, m.status AS animal_status, m.distinctive_mark, e.experiment_number, e.title AS experiment_title,
            p.id AS protocol_id, p.protocol_number, p.endpoint_criteria, p.clinical_grid_id
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
    echo "<div class='container mt-4'><div class='alert alert-danger'>Enrollment not found.</div></div>";
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

$selfUrl = "experiment_followup.php?experiment_animal_id=$experimentAnimalId";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    // Completing a pending endpoint action (other than euthanasia) with a note.
    if (isset($_POST['complete_action'])) {
        $actionId = (int) $_POST['action_id'];
        $note = trim($_POST['done_note'] ?? '');
        if ($note === '') {
            $_SESSION['message'] = "Describe what was done (e.g. vet called, decision taken).";
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
                log_activity($con, 'endpoint_action_done', 'animal', (string) $ea['animal_id'], "Action #$actionId: $note", $currentUserId);
            }
            $_SESSION['message'] = $done ? "Action recorded as done." : "This action cannot be completed here.";
        }
        header("Location: $selfUrl");
        exit;
    }

    if ($ea['animal_status'] !== 'active' || $ea['removed_at'] !== null) {
        $_SESSION['message'] = "This animal is no longer followed in this experiment.";
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
                $_SESSION['message'] = "Score every item of the clinical grid (missing: {$item['label']}).";
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
                $fail("Observation \"{$field['label']}\" is required.");
            }
            continue;
        }
        $number = $field['value_type'] === 'number' ? parse_number($raw) : null;
        if ($field['value_type'] === 'number' && $number === null) {
            $fail("Observation \"{$field['label']}\" must be a number.");
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
            $fail("Each free observation needs a label and a value.");
        }
        $type = ($_POST['extra_type'][$i] ?? '') === 'number' ? 'number' : 'text';
        $number = $type === 'number' ? parse_number($raw) : null;
        if ($type === 'number' && $number === null) {
            $fail("Free observation \"$label\" must be a number.");
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
                'text' => ($t['action_message'] ?: $condition) . " ($condition, measured: " . round($actual, 2) . ")",
            ];
        }
    }
    $endpointReached = $breaches ? 1 : 0;
    $breachedMessages = array_column($breaches, 'text');

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
            $newActions[] = ENDPOINT_REQUIRED_ACTIONS[$t['required_action']];
        }

        // The experimental daily follow-up counts as the cage's daily check.
        $cageStmt = $con->prepare("SELECT cage_id FROM mice WHERE id = ?");
        $cageStmt->bind_param("i", $ea['animal_id']);
        $cageStmt->execute();
        $animalCage = $cageStmt->get_result()->fetch_assoc()['cage_id'] ?? null;
        $cageStmt->close();
        if ($animalCage && $observedAt === date('Y-m-d')) {
            record_cage_check($con, $animalCage, 'experimental', $endpointReached ? 'issue' : 'ok', 'auto', "{$ea['animal_uid']} — experiment {$ea['experiment_number']}", null, $currentUserId, $currentUserName);
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
                ($newActions ? "ACTION REQUIRED (" . implode(', ', $newActions) . "): " : "Endpoint reached: ") . $ea['animal_uid'],
                "Experiment {$ea['experiment_number']} ({$ea['protocol_number']}): " . implode(' | ', $breachedMessages),
                $selfUrl,
                'endpoint_alert'
            );
        }
        mysqli_commit($con);
    } catch (Exception $e) {
        mysqli_rollback($con);
        $_SESSION['message'] = "Follow-up not saved: " . $e->getMessage();
        header("Location: $selfUrl");
        exit;
    }

    $_SESSION['message'] = $endpointReached
        ? "Saved. ENDPOINT REACHED: " . implode(' | ', $breachedMessages) . ($newActions ? " — ACTION REQUIRED: " . implode(', ', $newActions) : '')
        : "Follow-up saved." . ($hasGrid ? " Clinical score: $clinicalScore / {$grid['max_total']}." : '');

    header("Location: $selfUrl");
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

$actions = $con->prepare("SELECT * FROM endpoint_actions WHERE experiment_animal_id = ? ORDER BY status = 'pending' DESC, created_at DESC");
$actions->bind_param("i", $experimentAnimalId);
$actions->execute();
$actions = $actions->get_result()->fetch_all(MYSQLI_ASSOC);
$pendingActions = array_filter($actions, fn($a) => $a['status'] === 'pending');

$isFollowed = $ea['animal_status'] === 'active' && $ea['removed_at'] === null;

// Render the shared header only after request handling. Including it before the
// POST redirect sends HTML output and prevents PHP from modifying headers.
require 'header.php';
?>

<!doctype html>
<html lang="en">

<head>
    <title>Daily Follow-up | <?php echo htmlspecialchars($labName); ?></title>
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
        <h4>Daily Follow-up — <?= htmlspecialchars($ea['animal_uid']); ?>
            <?php if ($ea['distinctive_mark']) : ?><small class="text-muted" id="animalMark">[<?= htmlspecialchars($ea['distinctive_mark']); ?>]</small><?php endif; ?></h4>
        <p class="text-muted">Experiment <?= htmlspecialchars($ea['experiment_number']); ?> — <?= htmlspecialchars($ea['experiment_title']); ?> (Protocol <?= htmlspecialchars($ea['protocol_number']); ?>)
            · baseline weight <?= htmlspecialchars($ea['baseline_weight_g'] ?? 'N/A'); ?> g</p>
        <a href="animal_view.php?id=<?= (int) $ea['animal_id']; ?>">&laquo; Back to animal record</a>

        <?php include('message.php'); ?>

        <?php foreach ($pendingActions as $a) : ?>
            <div class="alert alert-danger" id="pendingAction<?= (int) $a['id']; ?>">
                <h5 class="alert-heading"><i class="fas fa-exclamation-triangle"></i> ACTION REQUIRED: <?= htmlspecialchars(ENDPOINT_REQUIRED_ACTIONS[$a['required_action']]); ?></h5>
                <p class="mb-2"><?= htmlspecialchars($a['message'] ?? ''); ?> — since <?= htmlspecialchars($a['created_at']); ?></p>
                <?php if ($a['required_action'] === 'euthanasia') : ?>
                    <a href="animal_exit.php?<?= htmlspecialchars(http_build_query(['id' => $ea['animal_id'], 'code' => 'point_limite'])); ?>" class="btn btn-danger">Record the euthanasia (exit: Atteinte de point limite)</a>
                <?php else : ?>
                    <form method="POST" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="experiment_animal_id" value="<?= $experimentAnimalId; ?>">
                        <input type="hidden" name="action_id" value="<?= (int) $a['id']; ?>">
                        <div class="col-md-9">
                            <input type="text" class="form-control" name="done_note" required placeholder="What was done (e.g. Dr X called at 10:30 — analgesia, re-check tonight)">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" name="complete_action" class="btn btn-outline-light bg-danger w-100">Mark as done</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($thresholds || $ea['endpoint_criteria']) : ?>
            <div class="alert alert-secondary">
                <strong>Endpoints (points limites) of this protocol:</strong>
                <?php if ($ea['endpoint_criteria']) : ?><div><?= nl2br(htmlspecialchars($ea['endpoint_criteria'])); ?></div><?php endif; ?>
                <ul class="mb-0">
                    <?php foreach ($thresholds as $t) : ?>
                        <li><?= htmlspecialchars(threshold_label($t, threshold_target_label($t, $grid, $allObsFields))); ?>
                            <?php if ($t['required_action'] !== 'none') : ?> → <strong><?= htmlspecialchars(ENDPOINT_REQUIRED_ACTIONS[$t['required_action']]); ?></strong><?php endif; ?>
                            <?= $t['action_message'] ? '— ' . htmlspecialchars($t['action_message']) : ''; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($isFollowed) : ?>
            <div class="card my-4">
                <div class="card-body">
                    <h5>New / Update Entry (one per day)</h5>
                    <form method="POST" id="followupForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="experiment_animal_id" value="<?= $experimentAnimalId; ?>">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Date *</label>
                                <input type="date" class="form-control" name="observed_at" required value="<?= date('Y-m-d'); ?>" max="<?= date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Weight (g)</label>
                                <input type="number" step="0.01" class="form-control" name="weight_g" id="weight_g">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Clinical score<?= $hasGrid ? ' (grid total)' : ''; ?></label>
                                <?php if ($hasGrid) : ?>
                                    <div class="form-control-plaintext fs-5"><strong id="gridTotal">–</strong> / <?= (int) $grid['max_total']; ?></div>
                                <?php else : ?>
                                    <input type="number" class="form-control" name="clinical_score">
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
                            <h6>Observations of the protocol</h6>
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

                        <h6>Free observations <small class="text-muted">(written or numeric, for this entry only)</small></h6>
                        <div id="extraObs"></div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="addExtraObs"><i class="fas fa-plus"></i> Add an observation</button>
                        <template id="extraObsTpl">
                            <div class="row g-2 mb-2 extra-obs">
                                <div class="col-md-3"><input type="text" class="form-control form-control-sm" name="extra_label[]" placeholder="Label (e.g. Food intake)"></div>
                                <div class="col-md-2">
                                    <select class="form-select form-select-sm extra-type" name="extra_type[]">
                                        <option value="text">Text</option>
                                        <option value="number">Number</option>
                                    </select>
                                </div>
                                <div class="col-md-4"><input type="text" class="form-control form-control-sm" name="extra_value[]" placeholder="Value"></div>
                                <div class="col-md-2"><input type="text" class="form-control form-control-sm extra-unit" name="extra_unit[]" placeholder="Unit"></div>
                                <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger remove-extra" title="Remove">&times;</button></div>
                            </div>
                        </template>

                        <div class="mb-3">
                            <label class="form-label">General observations</label>
                            <textarea class="form-control" name="observations"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Save Follow-up</button>
                    </form>
                </div>
            </div>
        <?php else : ?>
            <div class="alert alert-secondary my-3">This animal is no longer followed in this experiment<?= $ea['removed_at'] ? ' (since ' . htmlspecialchars($ea['removed_at']) . ')' : ''; ?>.</div>
        <?php endif; ?>

        <h5>History</h5>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Weight (g)</th>
                        <th>Loss %</th>
                        <?php foreach ($historyLabels as $label) : ?><th><?= htmlspecialchars($label); ?></th><?php endforeach; ?>
                        <th>Clinical score</th>
                        <?php foreach ($obsColumns as $label) : ?><th><?= htmlspecialchars($label); ?></th><?php endforeach; ?>
                        <th>Other observations</th>
                        <th>Observations</th>
                        <th>Endpoint</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $h) : ?>
                        <tr class="<?= $h['endpoint_reached'] ? 'table-danger' : ''; ?>">
                            <td><?= htmlspecialchars($h['observed_at']); ?></td>
                            <td><?= htmlspecialchars($h['weight_g'] ?? '-'); ?></td>
                            <td><?= ($h['weight_g'] !== null && !empty($ea['baseline_weight_g'])) ? round(($ea['baseline_weight_g'] - $h['weight_g']) / $ea['baseline_weight_g'] * 100, 1) : '-'; ?></td>
                            <?php foreach ($historyLabels as $label) : ?><td><?= htmlspecialchars((string) ($scoresByFollowup[$h['id']][$label] ?? '-')); ?></td><?php endforeach; ?>
                            <td><strong><?= htmlspecialchars($h['clinical_score'] ?? '-'); ?></strong></td>
                            <?php foreach ($obsColumns as $label) : $o = $obsByFollowup[$h['id']]['fields'][$label] ?? null; ?>
                                <td><?= $o ? htmlspecialchars(observation_value($o)) : '-'; ?></td>
                            <?php endforeach; ?>
                            <td><?= implode('<br>', array_map(fn($o) => '<em>' . htmlspecialchars($o['label']) . ':</em> ' . htmlspecialchars(observation_value($o)), $obsByFollowup[$h['id']]['free'] ?? [])); ?></td>
                            <td><?= htmlspecialchars($h['observations'] ?? ''); ?></td>
                            <td><?= $h['endpoint_reached'] ? 'YES' : 'No'; ?></td>
                            <td><?= htmlspecialchars($h['recorded_by_name'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($actions) : ?>
            <h5>Endpoint actions</h5>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Raised</th>
                        <th>Required action</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Done</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($actions as $a) : ?>
                        <tr>
                            <td><?= htmlspecialchars($a['created_at']); ?></td>
                            <td><?= htmlspecialchars(ENDPOINT_REQUIRED_ACTIONS[$a['required_action']]); ?></td>
                            <td><?= htmlspecialchars($a['message'] ?? ''); ?></td>
                            <td><span class="badge bg-<?= $a['status'] === 'pending' ? 'danger' : 'success'; ?>"><?= $a['status']; ?></span></td>
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
            add.addEventListener('click', () => {
                const row = tpl.content.firstElementChild.cloneNode(true);
                const type = row.querySelector('.extra-type');
                const unit = row.querySelector('.extra-unit');
                const sync = () => unit.style.visibility = type.value === 'number' ? 'visible' : 'hidden';
                type.addEventListener('change', sync);
                sync();
                row.querySelector('.remove-extra').addEventListener('click', () => row.remove());
                box.appendChild(row);
                row.querySelector('input').focus();
            });
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
                total.textContent = scored === rows.length ? sum : sum + ' (' + scored + '/' + rows.length + ' items)';
            };
            document.querySelectorAll('.grid-score').forEach(i => i.addEventListener('change', update));
            update();
        })();
    </script>

    <br>
    <?php require 'footer.php'; ?>
</body>

</html>
