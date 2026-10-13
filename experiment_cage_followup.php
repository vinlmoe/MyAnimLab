<?php

/** One-page daily experimental-follow-up dashboard for every enrolled animal in a cage. */
session_start();
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$cageId = trim($_GET['cage_id'] ?? '');
$today = date('Y-m-d');
$cageStmt = $con->prepare('SELECT cage_id, room, rack, status FROM cages WHERE cage_id = ?');
$cageStmt->bind_param('s', $cageId);
$cageStmt->execute();
$cage = $cageStmt->get_result()->fetch_assoc();
$cageStmt->close();

if (!$cage) {
    $_SESSION['message'] = 'Cage introuvable.';
    header('Location: qr_scan.php');
    exit;
}

$enrollStmt = $con->prepare(
    "SELECT ea.id AS experiment_animal_id, m.id AS animal_id, m.animal_uid, m.mouse_id, m.sex, m.distinctive_mark,
            e.experiment_number, e.title AS experiment_title, p.protocol_number,
            f.id AS followup_id, f.weight_g, f.clinical_score, f.endpoint_reached
     FROM experiment_animals ea
     JOIN mice m ON m.id = ea.animal_id
     JOIN experiments e ON e.id = ea.experiment_id
     JOIN protocols p ON p.id = e.protocol_id
     LEFT JOIN experiment_followups f ON f.experiment_animal_id = ea.id AND f.observed_at = ?
     WHERE m.cage_id = ? AND m.status = 'active' AND ea.removed_at IS NULL
       AND e.status IN ('planned', 'active')
     ORDER BY e.experiment_number, m.animal_uid"
);
$enrollStmt->bind_param('ss', $today, $cageId);
$enrollStmt->execute();
$enrollments = $enrollStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$enrollStmt->close();

require 'header.php';
?>
<!doctype html>
<html lang="fr"><head>
    <title>Suivi expérimental de cage | <?= htmlspecialchars($labName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body>
<main class="container mt-4 content" style="max-width:1100px">
    <h4><i class="fas fa-flask"></i> Suivi expérimental — cage <?= htmlspecialchars($cageId) ?></h4>
    <p class="text-muted"><?= htmlspecialchars($today) ?> · Salle <?= htmlspecialchars($cage['room'] ?: 'non attribuée') ?> · Rack <?= htmlspecialchars($cage['rack'] ?: '—') ?></p>
    <p><a class="btn btn-outline-primary btn-sm" href="medication_administration.php?cage_id=<?= rawurlencode($cageId) ?>"><i class="fas fa-syringe"></i> Administrations de la cage</a></p>
    <?php include 'message.php'; ?>
    <?php if (!$enrollments) : ?>
        <div class="alert alert-secondary">Aucun animal actuellement en expérimentation dans cette cage.</div>
        <a class="btn btn-outline-secondary" href="cage_check.php?<?= htmlspecialchars(http_build_query(['id' => $cageId, 'via' => 'qr'])) ?>">Visite quotidienne de cage</a>
    <?php else : ?>
        <div class="alert alert-info">Tous les animaux en expérimentation de cette cage sont regroupés ici. Enregistrez le suivi de chacun ; chaque saisie conserve les critères du protocole associé.</div>
        <div class="table-responsive"><table class="table table-bordered align-middle">
            <thead class="table-light"><tr><th>Animal</th><th>Expérience / protocole</th><th>Suivi du jour</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($enrollments as $en) : $label = $en['animal_uid'] ?: $en['mouse_id'] ?: ('#' . $en['animal_id']); ?>
                <tr class="<?= $en['endpoint_reached'] ? 'table-danger' : ($en['followup_id'] ? 'table-success' : '') ?>">
                    <td><a href="animal_view.php?id=<?= (int) $en['animal_id'] ?>" class="fw-bold"><?= htmlspecialchars($label) ?></a> <?= $en['sex'] === 'male' ? '♂' : ($en['sex'] === 'female' ? '♀' : '') ?><br><small><?= htmlspecialchars($en['distinctive_mark'] ?? '') ?></small></td>
                    <td><?= htmlspecialchars($en['experiment_number']) ?> — <?= htmlspecialchars($en['experiment_title']) ?><br><small class="text-muted"><?= htmlspecialchars($en['protocol_number']) ?></small></td>
                    <td><?php if ($en['followup_id']) : ?><span class="badge bg-success">Saisi</span> poids <?= htmlspecialchars($en['weight_g'] ?? '—') ?> g · score <?= htmlspecialchars($en['clinical_score'] ?? '—') ?><?= $en['endpoint_reached'] ? ' · <span class="badge bg-danger">Point limite</span>' : '' ?><?php else : ?><span class="badge bg-warning text-dark">À saisir</span><?php endif; ?></td>
                    <td><a class="btn btn-primary btn-sm" href="experiment_followup.php?<?= htmlspecialchars(http_build_query(['experiment_animal_id' => $en['experiment_animal_id'], 'return' => 'experiment_cage_followup.php?cage_id=' . $cageId])) ?>">Saisir / modifier</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
    <?php endif; ?>
    <a href="<?= htmlspecialchars('daily_round.php?' . http_build_query(['room' => $cage['room'] ?: '__none'])) ?>" class="btn btn-outline-secondary">Retour à la visite journalière</a>
</main>
<?php require 'footer.php'; ?>
</body></html>
