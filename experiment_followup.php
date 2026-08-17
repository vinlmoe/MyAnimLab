<?php

/**
 * Daily follow-up form (formulaire de suivi journalier) for one animal
 * enrolled in one experiment. Evaluates the parent protocol's endpoint
 * (point limite) thresholds on every save and raises an alert when reached.
 */

session_start();
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

require 'header.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$currentUserId = $_SESSION['user_id'] ?? null;
$experimentAnimalId = (int) ($_GET['experiment_animal_id'] ?? $_POST['experiment_animal_id'] ?? 0);

$eaStmt = $con->prepare(
    "SELECT ea.*, m.animal_uid, m.id AS animal_id, e.experiment_number, e.title AS experiment_title,
            p.id AS protocol_id, p.protocol_number, p.endpoint_criteria
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
    echo "<div class='container mt-4'><div class='alert alert-danger'>Enrollment not found.</div></div>";
    require 'footer.php';
    exit;
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    $observedAt = $_POST['observed_at'];
    $weight = $_POST['weight_g'] !== '' ? (float) $_POST['weight_g'] : null;
    $clinicalScore = $_POST['clinical_score'] !== '' ? (int) $_POST['clinical_score'] : null;
    $observations = trim($_POST['observations']);

    // Evaluate the protocol's endpoint (point limite) thresholds against this entry.
    $breachedMessages = [];
    foreach ($thresholds as $t) {
        $actual = null;
        if ($t['metric'] === 'clinical_score' && $clinicalScore !== null) {
            $actual = $clinicalScore;
        } elseif ($t['metric'] === 'weight_loss_pct' && $weight !== null && !empty($ea['baseline_weight_g'])) {
            $actual = ($ea['baseline_weight_g'] - $weight) / $ea['baseline_weight_g'] * 100;
        }

        if ($actual !== null && threshold_breached($t['operator'], $actual, (float) $t['value'])) {
            $breachedMessages[] = ($t['action_message'] ?: "{$t['metric']} {$t['operator']} {$t['value']}") . " (measured: " . round($actual, 2) . ")";
        }
    }

    $endpointReached = !empty($breachedMessages) ? 1 : 0;

    $stmt = $con->prepare(
        "INSERT INTO experiment_followups (experiment_animal_id, observed_at, weight_g, clinical_score, observations, endpoint_reached, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE weight_g = VALUES(weight_g), clinical_score = VALUES(clinical_score),
            observations = VALUES(observations), endpoint_reached = VALUES(endpoint_reached), recorded_by = VALUES(recorded_by)"
    );
    $stmt->bind_param("isdisii", $experimentAnimalId, $observedAt, $weight, $clinicalScore, $observations, $endpointReached, $currentUserId);
    $stmt->execute();
    $stmt->close();

    if ($endpointReached) {
        log_animal_event($con, (int) $ea['animal_id'], 'endpoint_alert', [
            'experiment_animal_id' => $experimentAnimalId,
            'observed_at' => $observedAt,
            'reasons' => $breachedMessages,
        ], $currentUserId);
        log_activity($con, 'endpoint_alert', 'animal', (string) $ea['animal_id'], implode(' | ', $breachedMessages), $currentUserId);
        notify_admins(
            $con,
            "Endpoint reached: {$ea['animal_uid']}",
            "Experiment {$ea['experiment_number']} ({$ea['protocol_number']}): " . implode(' | ', $breachedMessages),
            "animal_view.php?id={$ea['animal_id']}",
            'endpoint_alert'
        );
        $_SESSION['message'] = "Saved. ENDPOINT REACHED: " . implode(' | ', $breachedMessages);
    } else {
        $_SESSION['message'] = "Follow-up saved.";
    }

    header("Location: experiment_followup.php?experiment_animal_id=$experimentAnimalId");
    exit();
}

$history = $con->prepare("SELECT * FROM experiment_followups WHERE experiment_animal_id = ? ORDER BY observed_at DESC");
$history->bind_param("i", $experimentAnimalId);
$history->execute();
$history = $history->get_result();
?>

<!doctype html>
<html lang="en">

<head>
    <title>Daily Follow-up | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-4 content">
        <h4>Daily Follow-up — <?= htmlspecialchars($ea['animal_uid']); ?></h4>
        <p class="text-muted">Experiment <?= htmlspecialchars($ea['experiment_number']); ?> — <?= htmlspecialchars($ea['experiment_title']); ?> (Protocol <?= htmlspecialchars($ea['protocol_number']); ?>)</p>
        <a href="animal_view.php?id=<?= (int) $ea['animal_id']; ?>">&laquo; Back to animal record</a>

        <?php include('message.php'); ?>

        <?php if (!empty($thresholds)) : ?>
            <div class="alert alert-secondary">
                <strong>Endpoint criteria (point limite) for this protocol:</strong>
                <ul class="mb-0">
                    <?php foreach ($thresholds as $t) : ?>
                        <li><?= htmlspecialchars($t['metric'] . ' ' . $t['operator'] . ' ' . $t['value']); ?> &mdash; <?= htmlspecialchars($t['action_message'] ?? ''); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card my-4">
            <div class="card-body">
                <h5>New / Update Entry (one per day)</h5>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="experiment_animal_id" value="<?= $experimentAnimalId; ?>">
                    <div class="mb-3">
                        <label class="form-label">Date *</label>
                        <input type="date" class="form-control" name="observed_at" required value="<?= date('Y-m-d'); ?>" max="<?= date('Y-m-d'); ?>">
                    </div>
                    <div class="row">
                        <div class="col mb-3">
                            <label class="form-label">Weight (g)</label>
                            <input type="number" step="0.01" class="form-control" name="weight_g">
                        </div>
                        <div class="col mb-3">
                            <label class="form-label">Clinical Score</label>
                            <input type="number" class="form-control" name="clinical_score">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Observations</label>
                        <textarea class="form-control" name="observations"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Follow-up</button>
                </form>
            </div>
        </div>

        <h5>History</h5>
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Weight (g)</th>
                    <th>Clinical Score</th>
                    <th>Observations</th>
                    <th>Endpoint reached</th>
                    <th>By</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($h = $history->fetch_assoc()) : ?>
                    <tr class="<?= $h['endpoint_reached'] ? 'table-danger' : ''; ?>">
                        <td><?= htmlspecialchars($h['observed_at']); ?></td>
                        <td><?= htmlspecialchars($h['weight_g'] ?? '-'); ?></td>
                        <td><?= htmlspecialchars($h['clinical_score'] ?? '-'); ?></td>
                        <td><?= htmlspecialchars($h['observations'] ?? ''); ?></td>
                        <td><?= $h['endpoint_reached'] ? 'YES' : 'No'; ?></td>
                        <td><?= htmlspecialchars($h['recorded_by'] ?? ''); ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <br>
    <?php require 'footer.php'; ?>
</body>

</html>
