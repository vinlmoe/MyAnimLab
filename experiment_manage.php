<?php

/**
 * Manage Experiments for a given Protocol: create experiments and enroll
 * animals into them (with a baseline weight used for weight-loss threshold
 * calculations during daily follow-up).
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
$protocolId = (int) ($_GET['protocol_id'] ?? $_POST['protocol_id'] ?? 0);

$protocolStmt = $con->prepare("SELECT p.*, t.trial_number FROM protocols p JOIN trials t ON t.id = p.trial_id WHERE p.id = ?");
$protocolStmt->bind_param("i", $protocolId);
$protocolStmt->execute();
$protocol = $protocolStmt->get_result()->fetch_assoc();

if (!$protocol) {
    require 'header.php';
    echo "<div class='container mt-4'><div class='alert alert-danger'>Protocol not found.</div></div>";
    require 'footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    if (isset($_POST['add_experiment'])) {
        $experimentNumber = trim($_POST['experiment_number']);
        $title = trim($_POST['title']);
        $startDate = $_POST['start_date'] ?: null;
        $endDate = $_POST['end_date'] ?: null;

        $check = $con->prepare("SELECT COUNT(*) FROM experiments WHERE experiment_number = ?");
        $check->bind_param("s", $experimentNumber);
        $check->execute();
        $check->bind_result($count);
        $check->fetch();
        $check->close();

        if ($count > 0) {
            $_SESSION['message'] = "Error: Experiment number already exists.";
        } else {
            $stmt = $con->prepare("INSERT INTO experiments (protocol_id, experiment_number, title, start_date, end_date) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $protocolId, $experimentNumber, $title, $startDate, $endDate);
            $stmt->execute();
            $_SESSION['message'] = "Experiment created successfully.";
            $stmt->close();
        }
    } elseif (isset($_POST['enroll_animal'])) {
        $experimentId = (int) $_POST['experiment_id'];
        $animalId = (int) $_POST['animal_id'];
        $baselineWeight = $_POST['baseline_weight_g'] !== '' ? (float) $_POST['baseline_weight_g'] : null;

        $stmt = $con->prepare("INSERT INTO experiment_animals (experiment_id, animal_id, baseline_weight_g) VALUES (?, ?, ?)");
        $stmt->bind_param("iid", $experimentId, $animalId, $baselineWeight);
        // mysqli throws on the uq_experiment_animal duplicate (PHP >= 8.1 default report mode)
        try {
            $stmt->execute();
            log_animal_event($con, $animalId, 'experiment_start', ['experiment_id' => $experimentId], $currentUserId);
            $_SESSION['message'] = "Animal enrolled successfully.";
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() !== 1062) {
                throw $e;
            }
            $_SESSION['message'] = "Animal already enrolled in this experiment.";
        }
        $stmt->close();
    }

    header("Location: experiment_manage.php?protocol_id=$protocolId");
    exit();
}

$experiments = $con->prepare("SELECT * FROM experiments WHERE protocol_id = ? ORDER BY id DESC");
$experiments->bind_param("i", $protocolId);
$experiments->execute();
$experiments = $experiments->get_result();

$activeAnimals = $con->query("SELECT id, animal_uid FROM mice WHERE status = 'active' AND animal_uid IS NOT NULL ORDER BY animal_uid");
$activeAnimalsList = [];
while ($row = $activeAnimals->fetch_assoc()) {
    $activeAnimalsList[] = $row;
}
// Render the shared header only after request handling. Including it before the
// POST redirect sends HTML output and prevents PHP from modifying headers.
require 'header.php';
?>

<!doctype html>
<html lang="en">

<head>
    <title>Experiments | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-4 content">
        <h4>Experiments for Protocol <?= htmlspecialchars($protocol['protocol_number']); ?> (Trial <?= htmlspecialchars($protocol['trial_number']); ?>)</h4>
        <a href="protocol_manage.php?trial_id=<?= (int) $protocol['trial_id']; ?>">&laquo; Back to Protocols</a>

        <?php include('message.php'); ?>

        <div class="card my-4">
            <div class="card-body">
                <h5>New Experiment</h5>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="protocol_id" value="<?= $protocolId; ?>">
                    <div class="mb-3">
                        <label class="form-label">Experiment Number *</label>
                        <input type="text" class="form-control" name="experiment_number" required placeholder="e.g. E-2026-001">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Title *</label>
                        <input type="text" class="form-control" name="title" required>
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
                    <button type="submit" name="add_experiment" class="btn btn-primary">Create Experiment</button>
                </form>
            </div>
        </div>

        <h5>Existing Experiments</h5>
        <?php while ($e = $experiments->fetch_assoc()) :
            $enrolled = $con->prepare(
                "SELECT ea.*, m.animal_uid FROM experiment_animals ea JOIN mice m ON m.id = ea.animal_id WHERE ea.experiment_id = ?"
            );
            $enrolled->bind_param("i", $e['id']);
            $enrolled->execute();
            $enrolled = $enrolled->get_result();
        ?>
            <div class="card mb-3">
                <div class="card-body">
                    <h6><?= htmlspecialchars($e['experiment_number']); ?> — <?= htmlspecialchars($e['title']); ?></h6>

                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Animal</th>
                                <th>Baseline weight (g)</th>
                                <th>Enrolled</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($en = $enrolled->fetch_assoc()) : ?>
                                <tr>
                                    <td><a href="animal_view.php?id=<?= (int) $en['animal_id']; ?>"><?= htmlspecialchars($en['animal_uid']); ?></a></td>
                                    <td><?= htmlspecialchars($en['baseline_weight_g'] ?? '-'); ?></td>
                                    <td><?= htmlspecialchars($en['enrolled_at']); ?></td>
                                    <td><a class="btn btn-sm btn-outline-primary" href="experiment_followup.php?experiment_animal_id=<?= (int) $en['id']; ?>">Daily follow-up</a></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>

                    <form method="POST" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="protocol_id" value="<?= $protocolId; ?>">
                        <input type="hidden" name="experiment_id" value="<?= (int) $e['id']; ?>">
                        <div class="col-auto">
                            <select class="form-control" name="animal_id" required>
                                <option value="" disabled selected>Select animal</option>
                                <?php foreach ($activeAnimalsList as $a) : ?>
                                    <option value="<?= (int) $a['id']; ?>"><?= htmlspecialchars($a['animal_uid']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <input type="number" step="0.01" class="form-control" name="baseline_weight_g" placeholder="Baseline weight (g)">
                        </div>
                        <div class="col-auto">
                            <button type="submit" name="enroll_animal" class="btn btn-success">Enroll</button>
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
