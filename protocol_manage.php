<?php

/**
 * Manage Protocols for a given Trial, including their endpoint (point limite)
 * thresholds — the criteria applied to every experiment run under this protocol.
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

$trialId = (int) ($_GET['trial_id'] ?? $_POST['trial_id'] ?? 0);

$trialStmt = $con->prepare("SELECT * FROM trials WHERE id = ?");
$trialStmt->bind_param("i", $trialId);
$trialStmt->execute();
$trial = $trialStmt->get_result()->fetch_assoc();

if (!$trial) {
    require 'header.php';
    echo "<div class='container mt-4'><div class='alert alert-danger'>" . trh('Trial not found.') . "</div></div>";
    require 'footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(tr('CSRF token validation failed'));
    }

    if (isset($_POST['add_protocol'])) {
        $protocolNumber = trim($_POST['protocol_number']);
        $title = trim($_POST['title']);
        $methodology = trim($_POST['methodology']);
        $endpointCriteria = trim($_POST['endpoint_criteria']);
        $startDate = $_POST['start_date'] ?: null;
        $endDate = $_POST['end_date'] ?: null;

        $check = $con->prepare("SELECT COUNT(*) FROM protocols WHERE protocol_number = ?");
        $check->bind_param("s", $protocolNumber);
        $check->execute();
        $check->bind_result($count);
        $check->fetch();
        $check->close();

        if ($count > 0) {
            $_SESSION['message'] = tr('Error: Protocol number already exists.');
        } else {
            $stmt = $con->prepare("INSERT INTO protocols (trial_id, protocol_number, title, methodology, endpoint_criteria, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssss", $trialId, $protocolNumber, $title, $methodology, $endpointCriteria, $startDate, $endDate);
            $stmt->execute();
            $_SESSION['message'] = tr('Protocol created successfully.');
            $stmt->close();
        }
    } elseif (isset($_POST['set_grid'])) {
        $protocolId = (int) $_POST['protocol_id'];
        $gridId = (int) ($_POST['clinical_grid_id'] ?? 0) ?: null;
        // Item thresholds belong to the current grid: changing grid would orphan them.
        $itemThresholds = $con->prepare("SELECT COUNT(*) FROM protocol_endpoint_thresholds WHERE protocol_id = ? AND metric = 'item_score'");
        $itemThresholds->bind_param("i", $protocolId);
        $itemThresholds->execute();
        $itemThresholds->bind_result($nItem);
        $itemThresholds->fetch();
        $itemThresholds->close();
        if ($nItem > 0) {
            $_SESSION['message'] = tr('This protocol has endpoints on grid items: the grid can no longer be changed.');
        } else {
            $stmt = $con->prepare("UPDATE protocols SET clinical_grid_id = ? WHERE id = ? AND trial_id = ?");
            $stmt->bind_param("iii", $gridId, $protocolId, $trialId);
            $stmt->execute();
            $stmt->close();
            log_activity($con, 'set_grid', 'protocol', (string) $protocolId, tr('Clinical grid #{id}', ['id' => $gridId ?? tr('none')]), $currentUserId ?? null);
            $_SESSION['message'] = tr('Clinical grid saved for the protocol.');
        }
    } elseif (isset($_POST['add_obs_field'])) {
        $protocolId = (int) $_POST['protocol_id'];
        $label = trim($_POST['obs_label'] ?? '');
        $type = ($_POST['obs_type'] ?? '') === 'text' ? 'text' : 'number';
        $unit = $type === 'number' ? (trim($_POST['obs_unit'] ?? '') ?: null) : null;
        $required = !empty($_POST['obs_required']) ? 1 : 0;
        if ($label === '') {
            $_SESSION['message'] = tr('Give the observation a label.');
        } else {
            $pos = (int) $con->query("SELECT COALESCE(MAX(position), 0) + 1 FROM protocol_observation_fields WHERE protocol_id = $protocolId")->fetch_row()[0];
            $stmt = $con->prepare("INSERT INTO protocol_observation_fields (protocol_id, position, label, value_type, unit, required) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisssi", $protocolId, $pos, $label, $type, $unit, $required);
            $stmt->execute();
            $stmt->close();
            $_SESSION['message'] = tr('Observation "{label}" added to the daily follow-up.', ['label' => $label]);
        }
    } elseif (isset($_POST['delete_obs_field'])) {
        // Removing a field only stops asking for it (deactivated, never deleted,
        // so recorded values stay attached); an endpoint using it blocks removal.
        $fieldId = (int) $_POST['field_id'];
        $used = $con->prepare("SELECT COUNT(*) FROM protocol_endpoint_thresholds WHERE observation_field_id = ?");
        $used->bind_param("i", $fieldId);
        $used->execute();
        $used->bind_result($nUsed);
        $used->fetch();
        $used->close();
        if ($nUsed > 0) {
            $_SESSION['message'] = tr('An endpoint uses this observation: it cannot be removed.');
        } else {
            $stmt = $con->prepare("UPDATE protocol_observation_fields f JOIN protocols p ON p.id = f.protocol_id SET f.active = 0 WHERE f.id = ? AND p.trial_id = ?");
            $stmt->bind_param("ii", $fieldId, $trialId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['message'] = tr('Observation removed from the daily follow-up.');
        }
    } elseif (isset($_POST['add_threshold'])) {
        $protocolId = (int) $_POST['protocol_id'];
        $metric = $_POST['metric'];
        $operator = $_POST['operator'];
        $value = (float) $_POST['value'];
        $actionMessage = trim($_POST['action_message']);
        $requiredAction = isset(ENDPOINT_REQUIRED_ACTIONS[$_POST['required_action'] ?? '']) ? $_POST['required_action'] : 'none';
        $gridItemId = $metric === 'item_score' ? ((int) ($_POST['grid_item_id'] ?? 0) ?: null) : null;
        $obsFieldId = $metric === 'observation' ? ((int) ($_POST['observation_field_id'] ?? 0) ?: null) : null;

        if ($metric === 'item_score' && !$gridItemId) {
            $_SESSION['message'] = tr('Choose the grid item for this endpoint.');
        } elseif ($metric === 'observation' && !$obsFieldId) {
            $_SESSION['message'] = tr('Choose the numeric observation for this endpoint.');
        } else {
            $stmt = $con->prepare("INSERT INTO protocol_endpoint_thresholds (protocol_id, metric, grid_item_id, observation_field_id, operator, value, action_message, required_action) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isiisdss", $protocolId, $metric, $gridItemId, $obsFieldId, $operator, $value, $actionMessage, $requiredAction);
            $stmt->execute();
            $_SESSION['message'] = tr('Endpoint threshold added.');
            $stmt->close();
        }
    }

    header("Location: protocol_manage.php?trial_id=$trialId");
    exit();
}

$protocols = $con->prepare("SELECT * FROM protocols WHERE trial_id = ? ORDER BY id DESC");
$protocols->bind_param("i", $trialId);
$protocols->execute();
$protocols = $protocols->get_result();
$allGrids = $con->query("SELECT id, name FROM clinical_grids ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Render the shared header only after request handling. Including it before the
// POST redirect sends HTML output and prevents PHP from modifying headers.
require 'header.php';
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Protocols') ?> | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-4 content">
        <h4><?= trh('Protocols for Trial {trial}', ['trial' => $trial['trial_number']]); ?> — <?= htmlspecialchars($trial['title']); ?></h4>
        <a href="trial_manage.php">&laquo; <?= trh('Back to Trials') ?></a>

        <?php include('message.php'); ?>

        <div class="card my-4">
            <div class="card-body">
                <h5><?= trh('New Protocol') ?></h5>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="trial_id" value="<?= $trialId; ?>">
                    <div class="mb-3">
                        <label class="form-label"><?= trh('Protocol Number') ?> *</label>
                        <input type="text" class="form-control" name="protocol_number" required placeholder="e.g. P-2026-001">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= trh('Title') ?> *</label>
                        <input type="text" class="form-control" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= trh('Methodology') ?></label>
                        <textarea class="form-control" name="methodology"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= trh('Endpoint criteria — narrative description') ?></label>
                        <textarea class="form-control" name="endpoint_criteria"></textarea>
                    </div>
                    <div class="row">
                        <div class="col mb-3">
                            <label class="form-label"><?= trh('Start Date') ?></label>
                            <input type="date" class="form-control" name="start_date">
                        </div>
                        <div class="col mb-3">
                            <label class="form-label"><?= trh('End Date') ?></label>
                            <input type="date" class="form-control" name="end_date">
                        </div>
                    </div>
                    <button type="submit" name="add_protocol" class="btn btn-primary"><?= trh('Create Protocol') ?></button>
                </form>
            </div>
        </div>

        <h5><?= trh('Existing Protocols') ?></h5>
        <?php while ($p = $protocols->fetch_assoc()) :
            $grid = $p['clinical_grid_id'] ? load_clinical_grid($con, (int) $p['clinical_grid_id']) : null;
            $obsFields = protocol_observation_fields($con, (int) $p['id']);
            $allObsFields = protocol_observation_fields($con, (int) $p['id'], true);
            $numericFields = array_filter($obsFields, fn($f) => $f['value_type'] === 'number');
            $thresholds = $con->prepare("SELECT * FROM protocol_endpoint_thresholds WHERE protocol_id = ?");
            $thresholds->bind_param("i", $p['id']);
            $thresholds->execute();
            $thresholds = $thresholds->get_result();
        ?>
            <div class="card mb-3">
                <div class="card-body">
                    <h6><?= htmlspecialchars($p['protocol_number']); ?> — <?= htmlspecialchars($p['title']); ?>
                        <a class="btn btn-sm btn-secondary float-end" href="experiment_manage.php?protocol_id=<?= (int) $p['id']; ?>">Expérimentations &raquo;</a>
                        <a class="btn btn-sm btn-outline-dark float-end me-2" href="protocol_report.php?protocol_id=<?= (int) $p['id']; ?>"><i class="fas fa-file-alt"></i> Rapport du plan</a>
                    </h6>
                    <p class="text-muted"><?= nl2br(htmlspecialchars($p['endpoint_criteria'] ?? '')); ?></p>

                    <form method="POST" class="row g-2 align-items-end mb-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="trial_id" value="<?= $trialId; ?>">
                        <input type="hidden" name="protocol_id" value="<?= (int) $p['id']; ?>">
                        <div class="col-auto">
                            <label class="form-label mb-0"><?= trh('Clinical scoring grid for the daily follow-up') ?></label>
                            <select class="form-select" name="clinical_grid_id">
                                <option value=""><?= trh('No grid (free clinical score)') ?></option>
                                <?php foreach ($allGrids as $g) : ?>
                                    <option value="<?= (int) $g['id']; ?>" <?= (int) $p['clinical_grid_id'] === (int) $g['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($g['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" name="set_grid" class="btn btn-outline-primary"><?= trh('Save grid') ?></button>
                            <?php if ($grid) : ?><a href="clinical_grids.php?id=<?= (int) $grid['id']; ?>" class="ms-2"><?= trh('View grid') ?></a><?php endif; ?>
                        </div>
                    </form>

                    <h6><?= trh('Additional observations in the daily follow-up') ?></h6>
                    <table class="table table-sm obs-fields">
                        <?php foreach ($obsFields as $of) : ?>
                            <tr>
                                <td><?= htmlspecialchars($of['label']); ?></td>
                                <td><?= $of['value_type'] === 'number' ? trh('Number value') . ($of['unit'] ? ' (' . htmlspecialchars($of['unit']) . ')' : '') : trh('Text'); ?></td>
                                <td><?= $of['required'] ? trh('Required') : trh('Optional'); ?></td>
                                <td class="text-end">
                                    <form method="POST" class="d-inline" onsubmit="return confirm(<?= htmlspecialchars(trj('Stop asking for this observation?')) ?>);">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="trial_id" value="<?= $trialId; ?>">
                                        <input type="hidden" name="field_id" value="<?= (int) $of['id']; ?>">
                                        <button type="submit" name="delete_obs_field" class="btn btn-sm btn-outline-danger"><?= trh('Remove') ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$obsFields) : ?><tr><td class="text-muted"><?= trh('None — free observations can still be added to any entry.') ?></td></tr><?php endif; ?>
                    </table>
                    <form method="POST" class="row g-2 align-items-end mb-3 obs-field-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="trial_id" value="<?= $trialId; ?>">
                        <input type="hidden" name="protocol_id" value="<?= (int) $p['id']; ?>">
                        <div class="col-auto"><input type="text" class="form-control" name="obs_label" placeholder="<?= trh('e.g. Temperature, Tumour volume, Wound aspect') ?>" required></div>
                        <div class="col-auto">
                            <select class="form-select" name="obs_type">
                                <option value="number"><?= trh('Number value') ?></option>
                                <option value="text"><?= trh('Text') ?></option>
                            </select>
                        </div>
                        <div class="col-auto"><input type="text" class="form-control" name="obs_unit" placeholder="<?= trh('Unit (°C, mm³...)') ?>" style="width: 140px;"></div>
                        <div class="col-auto form-check ms-2">
                            <input class="form-check-input" type="checkbox" name="obs_required" value="1" id="obsReq<?= (int) $p['id']; ?>">
                            <label class="form-check-label" for="obsReq<?= (int) $p['id']; ?>"><?= trh('Required') ?></label>
                        </div>
                        <div class="col-auto"><button type="submit" name="add_obs_field" class="btn btn-outline-primary"><?= trh('Add observation') ?></button></div>
                    </form>

                    <h6><?= trh('Endpoints (points limites)') ?></h6>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th><?= trh('Condition') ?></th>
                                <th><?= trh('Required action') ?></th>
                                <th><?= trh('Message') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($th = $thresholds->fetch_assoc()) : ?>
                                <tr>
                                    <td><?= htmlspecialchars(threshold_label($th, threshold_target_label($th, $grid, $allObsFields))); ?></td>
                                    <td><?= $th['required_action'] !== 'none' ? '<span class="badge bg-danger">' . htmlspecialchars(endpoint_required_actions()[$th['required_action']]) . '</span>' : trh('Alert only'); ?></td>
                                    <td><?= htmlspecialchars($th['action_message'] ?? ''); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>

                    <form method="POST" class="row g-2 align-items-end threshold-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="trial_id" value="<?= $trialId; ?>">
                        <input type="hidden" name="protocol_id" value="<?= (int) $p['id']; ?>">
                        <div class="col-auto">
                            <select class="form-select metric" name="metric">
                                <option value="weight_loss_pct"><?= trh('Weight loss %') ?></option>
                                <option value="clinical_score"><?= trh('Total clinical score') ?></option>
                                <?php if ($grid && $grid['items']) : ?><option value="item_score"><?= trh('Score of one grid item') ?></option><?php endif; ?>
                                <?php if ($numericFields) : ?><option value="observation"><?= trh('Numeric observation') ?></option><?php endif; ?>
                            </select>
                        </div>
                        <?php if ($numericFields) : ?>
                            <div class="col-auto obs-field">
                                <select class="form-select" name="observation_field_id">
                                    <?php foreach ($numericFields as $nf) : ?>
                                        <option value="<?= (int) $nf['id']; ?>"><?= htmlspecialchars($nf['label'] . ($nf['unit'] ? " ({$nf['unit']})" : '')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <?php if ($grid && $grid['items']) : ?>
                            <div class="col-auto grid-item">
                                <select class="form-select" name="grid_item_id">
                                    <?php foreach ($grid['items'] as $item) : ?>
                                        <option value="<?= (int) $item['id']; ?>"><?= htmlspecialchars($item['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="col-auto">
                            <select class="form-select" name="operator">
                                <option value=">=">&gt;=</option>
                                <option value=">">&gt;</option>
                                <option value="<">&lt;</option>
                                <option value="<=">&lt;=</option>
                            </select>
                        </div>
                        <div class="col-auto">
                            <input type="number" step="0.01" class="form-control" name="value" placeholder="<?= trh('Value') ?>" required style="width: 100px;">
                        </div>
                        <div class="col-auto">
                            <select class="form-select" name="required_action">
                                <?php foreach (endpoint_required_actions() as $key => $text) : ?>
                                    <option value="<?= $key; ?>"><?= htmlspecialchars($text); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <input type="text" class="form-control" name="action_message" placeholder="<?= trh('Message to the experimenter') ?>">
                        </div>
                        <div class="col-auto">
                            <button type="submit" name="add_threshold" class="btn btn-warning"><?= trh('Add endpoint') ?></button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endwhile; ?>
    </div>

    <script>
        // The grid item selector only applies to "score of one grid item".
        document.querySelectorAll('.threshold-form').forEach(form => {
            const metric = form.querySelector('.metric');
            const item = form.querySelector('.grid-item');
            const obs = form.querySelector('.obs-field');
            const sync = () => {
                if (item) item.style.display = metric.value === 'item_score' ? '' : 'none';
                if (obs) obs.style.display = metric.value === 'observation' ? '' : 'none';
            };
            metric.addEventListener('change', sync);
            sync();
        });
    </script>

    <br>
    <?php require 'footer.php'; ?>
</body>

</html>
