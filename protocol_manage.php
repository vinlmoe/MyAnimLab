<?php

/**
 * Manage Protocols for a given Trial, including their endpoint (point limite)
 * thresholds — the criteria applied to every experiment run under this protocol.
 */

session_start();
require 'dbcon.php';

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
    } elseif (isset($_POST['add_threshold'])) {
        $protocolId = (int) $_POST['protocol_id'];
        $metric = $_POST['metric'];
        $operator = $_POST['operator'];
        $value = (float) $_POST['value'];
        $actionMessage = trim($_POST['action_message']);

        $stmt = $con->prepare("INSERT INTO protocol_endpoint_thresholds (protocol_id, metric, operator, value, action_message) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issds", $protocolId, $metric, $operator, $value, $actionMessage);
        $stmt->execute();
        $_SESSION['message'] = "Endpoint threshold added.";
        $stmt->close();
    }

    header("Location: protocol_manage.php?trial_id=$trialId");
    exit();
}

$protocols = $con->prepare("SELECT * FROM protocols WHERE trial_id = ? ORDER BY id DESC");
$protocols->bind_param("i", $trialId);
$protocols->execute();
$protocols = $protocols->get_result();

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

                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Metric</th>
                                <th>Condition</th>
                                <th>Action Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($th = $thresholds->fetch_assoc()) : ?>
                                <tr>
                                    <td><?= htmlspecialchars($th['metric']); ?></td>
                                    <td><?= htmlspecialchars($th['operator'] . ' ' . $th['value']); ?></td>
                                    <td><?= htmlspecialchars($th['action_message'] ?? ''); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>

                    <form method="POST" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="trial_id" value="<?= $trialId; ?>">
                        <input type="hidden" name="protocol_id" value="<?= (int) $p['id']; ?>">
                        <div class="col-auto">
                            <select class="form-control" name="metric">
                                <option value="weight_loss_pct">Weight loss %</option>
                                <option value="clinical_score">Clinical score</option>
                                <option value="custom">Custom</option>
                            </select>
                        </div>
                        <div class="col-auto">
                            <select class="form-control" name="operator">
                                <option value=">">&gt;</option>
                                <option value=">=">&gt;=</option>
                                <option value="<">&lt;</option>
                                <option value="<=">&lt;=</option>
                            </select>
                        </div>
                        <div class="col-auto">
                            <input type="number" step="0.01" class="form-control" name="value" placeholder="Value" required>
                        </div>
                        <div class="col-auto">
                            <input type="text" class="form-control" name="action_message" placeholder="Alert message">
                        </div>
                        <div class="col-auto">
                            <button type="submit" name="add_threshold" class="btn btn-warning">Add Threshold</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endwhile; ?>
    </div>

    <br>
    <?php require 'footer.php'; ?>
</body>

</html>
