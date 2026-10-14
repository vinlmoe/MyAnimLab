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
        $title = trim($_POST['title']);
        $startDate = $_POST['start_date'] ?: null;
        $endDate = $_POST['end_date'] ?: null;
        if ($title === '') {
            $_SESSION['message'] = 'Le titre de l’expérience est obligatoire.';
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
        if (!$valid || !$animalIds) {
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
    }

    header("Location: experiment_manage.php?protocol_id=$protocolId");
    exit();
}

$experiments = $con->prepare("SELECT * FROM experiments WHERE protocol_id = ? ORDER BY id DESC");
$experiments->bind_param("i", $protocolId);
$experiments->execute();
$experiments = $experiments->get_result();

$activeAnimals = $con->query("SELECT m.id, m.animal_uid, m.mouse_id, m.cage_id, m.sex, al.code AS line_code FROM mice m LEFT JOIN animal_lines al ON al.id = m.line_id WHERE m.status = 'active' ORDER BY m.cage_id, m.animal_uid");
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
        <nav class="mb-3"><a href="trial_manage.php">Essais</a> <span class="text-muted">/</span> <a href="protocol_manage.php?trial_id=<?= (int) $protocol['trial_id']; ?>">Plan / protocole <?= htmlspecialchars($protocol['protocol_number']); ?></a> <span class="text-muted">/</span> <a href="protocol_report.php?protocol_id=<?= $protocolId; ?>">Rapport du plan</a> <span class="text-muted">/</span> Expérimentations</nav>

        <?php include('message.php'); ?>

        <div class="card my-4">
            <div class="card-body">
                <h5>New Experiment</h5>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="protocol_id" value="<?= $protocolId; ?>">
                    <div class="alert alert-secondary py-2">Le numéro est attribué automatiquement à la validation (format <strong>EXP-00001</strong>).</div>
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
                    <h6><?= htmlspecialchars($e['experiment_number']); ?> — <?= htmlspecialchars($e['title']); ?>
                        <a class="btn btn-sm btn-outline-dark float-end" href="experiment_report.php?experiment_id=<?= (int) $e['id']; ?>"><i class="fas fa-file-alt"></i> Report (with grids)</a>
                    </h6>

                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Animal</th>
                                <th>Baseline weight (g)</th>
                                <th>Enrolled</th>
                                <th>Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($en = $enrolled->fetch_assoc()) : ?>
                                <tr>
                                    <td><a href="animal_view.php?id=<?= (int) $en['animal_id']; ?>"><?= htmlspecialchars($en['animal_uid']); ?></a></td>
                                    <td><?= htmlspecialchars($en['baseline_weight_g'] ?? '-'); ?></td>
                                    <td><?= htmlspecialchars($en['enrolled_at']); ?></td>
                                    <td><?php if (!$en['removed_at']) : ?><a class="btn btn-sm btn-outline-primary" href="experiment_followup.php?<?= htmlspecialchars(http_build_query(['experiment_animal_id' => $en['id'], 'return' => 'experiment_manage.php?protocol_id=' . $protocolId])); ?>">Suivi quotidien</a><form method="POST" class="d-inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>"><input type="hidden" name="protocol_id" value="<?= $protocolId; ?>"><input type="hidden" name="experiment_animal_id" value="<?= (int) $en['id']; ?>"><input type="hidden" name="removal_reason" value="Fin de participation à l’expérimentation"><button class="btn btn-sm btn-outline-secondary" name="end_enrollment" onclick="return confirm('Clôturer la participation expérimentale ?')">Clôturer</button></form><?php else : ?><span class="badge bg-secondary">Clôturée</span><?php endif; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>

                    <form method="POST" class="row g-2 align-items-end experiment-enroll-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="protocol_id" value="<?= $protocolId; ?>">
                        <input type="hidden" name="experiment_id" value="<?= (int) $e['id']; ?>">
                        <div class="col-md-7">
                            <label class="form-label">Animaux à ajouter (recherche et multi-sélection)</label>
                            <select class="form-control animal-multi-select" name="animal_ids[]" multiple required>
                                <?php foreach ($activeAnimalsList as $a) : ?>
                                    <option value="<?= (int) $a['id']; ?>" data-cage="<?= htmlspecialchars($a['cage_id'] ?? ''); ?>"><?= htmlspecialchars(($a['animal_uid'] ?: $a['mouse_id'] ?: '#' . $a['id']) . ' — cage ' . ($a['cage_id'] ?? 'sans cage') . ' · ' . ($a['line_code'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2"><label class="form-label">Ajouter toute la cage</label><select class="form-select cage-picker"><option value="">Choisir…</option><?php foreach (array_unique(array_filter(array_column($activeAnimalsList, 'cage_id'))) as $cage) : ?><option value="<?= htmlspecialchars($cage); ?>"><?= htmlspecialchars($cage); ?></option><?php endforeach; ?></select></div>
                        <div class="col-auto">
                            <input type="number" step="0.01" class="form-control" name="baseline_weight_g" placeholder="Baseline weight (g)">
                        </div>
                        <div class="col-auto">
                            <button type="submit" name="enroll_animals" class="btn btn-success">Ajouter à l’expérience</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endwhile; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script><script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script><link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script>$('.animal-multi-select').select2({width:'100%',placeholder:'Rechercher un animal…'}); $('.cage-picker').on('change', function(){ const form=$(this).closest('form'); const cage=this.value; const select=form.find('.animal-multi-select'); if(cage){ select.find('option').filter(function(){return $(this).data('cage')===cage;}).prop('selected',true); select.trigger('change'); } });</script>
    <br>
    <?php require 'footer.php'; ?>
</body>

</html>
