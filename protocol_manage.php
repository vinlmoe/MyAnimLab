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
    echo "<div class='container mt-4'><div class='alert alert-danger'>Trial not found.</div></div>";
    require 'footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
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
            $_SESSION['message'] = "Error: Protocol number already exists.";
        } else {
            $stmt = $con->prepare("INSERT INTO protocols (trial_id, protocol_number, title, methodology, endpoint_criteria, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssss", $trialId, $protocolNumber, $title, $methodology, $endpointCriteria, $startDate, $endDate);
            $stmt->execute();
            $_SESSION['message'] = "Protocol created successfully.";
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
            $_SESSION['message'] = "This protocol has endpoints on grid items: the grid can no longer be changed.";
        } else {
            $stmt = $con->prepare("UPDATE protocols SET clinical_grid_id = ? WHERE id = ? AND trial_id = ?");
            $stmt->bind_param("iii", $gridId, $protocolId, $trialId);
            $stmt->execute();
            $stmt->close();
            log_activity($con, 'set_grid', 'protocol', (string) $protocolId, "Clinical grid #" . ($gridId ?? 'none'), $currentUserId ?? null);
            $_SESSION['message'] = "Clinical grid saved for the protocol.";
        }
    } elseif (isset($_POST['add_threshold'])) {
        $protocolId = (int) $_POST['protocol_id'];
        $metric = $_POST['metric'];
        $operator = $_POST['operator'];
        $value = (float) $_POST['value'];
        $actionMessage = trim($_POST['action_message']);
        $requiredAction = isset(ENDPOINT_REQUIRED_ACTIONS[$_POST['required_action'] ?? '']) ? $_POST['required_action'] : 'none';
        $gridItemId = $metric === 'item_score' ? ((int) ($_POST['grid_item_id'] ?? 0) ?: null) : null;

        if ($metric === 'item_score' && !$gridItemId) {
            $_SESSION['message'] = "Choose the grid item for this endpoint.";
        } else {
            $stmt = $con->prepare("INSERT INTO protocol_endpoint_thresholds (protocol_id, metric, grid_item_id, operator, value, action_message, required_action) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isisdss", $protocolId, $metric, $gridItemId, $operator, $value, $actionMessage, $requiredAction);
            $stmt->execute();
            $_SESSION['message'] = "Endpoint threshold added.";
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
<html lang="en">

<head>
    <title>Protocols | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-4 content">
        <h4>Protocols for Trial <?= htmlspecialchars($trial['trial_number']); ?> — <?= htmlspecialchars($trial['title']); ?></h4>
        <a href="trial_manage.php">&laquo; Back to Trials</a>

        <?php include('message.php'); ?>

        <div class="card my-4">
            <div class="card-body">
                <h5>New Protocol</h5>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="trial_id" value="<?= $trialId; ?>">
                    <div class="mb-3">
                        <label class="form-label">Protocol Number *</label>
                        <input type="text" class="form-control" name="protocol_number" required placeholder="e.g. P-2026-001">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Title *</label>
                        <input type="text" class="form-control" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Methodology</label>
                        <textarea class="form-control" name="methodology"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Endpoint Criteria (point limite) — narrative description</label>
                        <textarea class="form-control" name="endpoint_criteria"></textarea>
                    </div>
                    <div class="row">
                        <div class="col mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date">
                        </div>
                        <div class="col mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date">
                        </div>
                    </div>
                    <button type="submit" name="add_protocol" class="btn btn-primary">Create Protocol</button>
                </form>
            </div>
        </div>

        <h5>Existing Protocols</h5>
        <?php while ($p = $protocols->fetch_assoc()) :
            $grid = $p['clinical_grid_id'] ? load_clinical_grid($con, (int) $p['clinical_grid_id']) : null;
            $thresholds = $con->prepare("SELECT * FROM protocol_endpoint_thresholds WHERE protocol_id = ?");
            $thresholds->bind_param("i", $p['id']);
            $thresholds->execute();
            $thresholds = $thresholds->get_result();
        ?>
            <div class="card mb-3">
                <div class="card-body">
                    <h6><?= htmlspecialchars($p['protocol_number']); ?> — <?= htmlspecialchars($p['title']); ?>
                        <a class="btn btn-sm btn-secondary float-end" href="experiment_manage.php?protocol_id=<?= (int) $p['id']; ?>">Experiments &raquo;</a>
                    </h6>
                    <p class="text-muted"><?= nl2br(htmlspecialchars($p['endpoint_criteria'] ?? '')); ?></p>

                    <form method="POST" class="row g-2 align-items-end mb-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="trial_id" value="<?= $trialId; ?>">
                        <input type="hidden" name="protocol_id" value="<?= (int) $p['id']; ?>">
                        <div class="col-auto">
                            <label class="form-label mb-0">Clinical scoring grid for the daily follow-up</label>
                            <select class="form-select" name="clinical_grid_id">
                                <option value="">No grid (free clinical score)</option>
                                <?php foreach ($allGrids as $g) : ?>
                                    <option value="<?= (int) $g['id']; ?>" <?= (int) $p['clinical_grid_id'] === (int) $g['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($g['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" name="set_grid" class="btn btn-outline-primary">Save grid</button>
                            <?php if ($grid) : ?><a href="clinical_grids.php?id=<?= (int) $grid['id']; ?>" class="ms-2">View grid</a><?php endif; ?>
                        </div>
                    </form>

                    <h6>Endpoints (points limites)</h6>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Condition</th>
                                <th>Required action</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($th = $thresholds->fetch_assoc()) : ?>
                                <tr>
                                    <td><?= htmlspecialchars(threshold_label($th, $grid['items'][(int) $th['grid_item_id']]['label'] ?? null)); ?></td>
                                    <td><?= $th['required_action'] !== 'none' ? '<span class="badge bg-danger">' . htmlspecialchars(ENDPOINT_REQUIRED_ACTIONS[$th['required_action']]) . '</span>' : 'Alert only'; ?></td>
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
                                <option value="weight_loss_pct">Weight loss %</option>
                                <option value="clinical_score">Total clinical score</option>
                                <?php if ($grid && $grid['items']) : ?><option value="item_score">Score of one grid item</option><?php endif; ?>
                            </select>
                        </div>
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
                            <input type="number" step="0.01" class="form-control" name="value" placeholder="Value" required style="width: 100px;">
                        </div>
                        <div class="col-auto">
                            <select class="form-select" name="required_action">
                                <?php foreach (ENDPOINT_REQUIRED_ACTIONS as $key => $text) : ?>
                                    <option value="<?= $key; ?>"><?= htmlspecialchars($text); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <input type="text" class="form-control" name="action_message" placeholder="Message to the experimenter">
                        </div>
                        <div class="col-auto">
                            <button type="submit" name="add_threshold" class="btn btn-warning">Add endpoint</button>
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
            if (!item) return;
            const sync = () => item.style.display = metric.value === 'item_score' ? '' : 'none';
            metric.addEventListener('change', sync);
            sync();
        });
    </script>

    <br>
    <?php require 'footer.php'; ?>
</body>

</html>
