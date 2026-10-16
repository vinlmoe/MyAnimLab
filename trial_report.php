<?php
/** Printable report for one trial and all of its protocols / experiments. */
session_start();
require 'dbcon.php';
if (!isset($_SESSION['username'])) { header('Location:index.php?redirect=' . urlencode($_SERVER['REQUEST_URI'])); exit; }

$trialId = (int) ($_GET['trial_id'] ?? 0);
$trialStmt = $con->prepare('SELECT * FROM trials WHERE id=?');
$trialStmt->bind_param('i', $trialId); $trialStmt->execute(); $trial = $trialStmt->get_result()->fetch_assoc(); $trialStmt->close();
if (!$trial) { require 'header.php'; echo "<main class='container mt-4'><div class='alert alert-danger'>Essai introuvable.</div></main>"; require 'footer.php'; exit; }

$protocols = $con->prepare("SELECT p.*,
    (SELECT COUNT(*) FROM experiments e WHERE e.protocol_id=p.id) AS experiment_count,
    (SELECT COUNT(*) FROM experiment_animals ea JOIN experiments e ON e.id=ea.experiment_id WHERE e.protocol_id=p.id) AS animal_count,
    (SELECT COUNT(*) FROM experiment_animals ea JOIN experiments e ON e.id=ea.experiment_id WHERE e.protocol_id=p.id AND ea.removed_at IS NULL) AS active_animal_count
    FROM protocols p WHERE p.trial_id=? ORDER BY p.id");
$protocols->bind_param('i', $trialId); $protocols->execute(); $protocols = $protocols->get_result()->fetch_all(MYSQLI_ASSOC);
require 'header.php';
?>
<!doctype html><html lang="fr"><head><title>Rapport essai | <?= htmlspecialchars($labName) ?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"><style>.report{max-width:1100px}@media print{body>*:not(.report){display:none!important}.no-print{display:none!important}}</style></head><body>
<main class="container mt-4 content report"><div class="no-print mb-3"><a href="trial_manage.php">&laquo; Retour aux essais</a><button class="btn btn-primary btn-sm ms-3" onclick="window.print()"><i class="fas fa-print"></i> Imprimer / PDF</button></div>
<h4>Rapport d’essai — <?= htmlspecialchars($trial['trial_number']) ?></h4><p class="text-muted">Édité le <?= date('d/m/Y H:i') ?> · <?= htmlspecialchars($labName) ?></p>
<table class="table table-bordered table-sm"><tr><th style="width:25%">Titre</th><td><?= htmlspecialchars($trial['title']) ?></td></tr><tr><th>Autorisation</th><td><?= htmlspecialchars($trial['iacuc_id'] ?: '—') ?></td></tr><tr><th>Période</th><td><?= htmlspecialchars(($trial['start_date'] ?: '—').' → '.($trial['end_date'] ?: '—')) ?></td></tr><tr><th>Statut</th><td><?= htmlspecialchars(tr($trial['status'])) ?></td></tr><tr><th>Description</th><td><?= nl2br(htmlspecialchars($trial['description'] ?: '—')) ?></td></tr></table>
<h5>Plans / protocoles et expérimentations</h5><div class="table-responsive"><table class="table table-bordered table-sm align-middle"><thead class="table-light"><tr><th>Plan / protocole</th><th>Statut</th><th>Expériences</th><th>Animaux</th><th>Actifs</th><th class="no-print">Actions</th></tr></thead><tbody><?php foreach ($protocols as $p): ?><tr><td><strong><?= htmlspecialchars($p['protocol_number']) ?></strong><br><?= htmlspecialchars($p['title']) ?></td><td><?= htmlspecialchars(tr($p['status'])) ?></td><td><?= (int) $p['experiment_count'] ?></td><td><?= (int) $p['animal_count'] ?></td><td><?= (int) $p['active_animal_count'] ?></td><td class="no-print"><a class="btn btn-sm btn-outline-dark" href="protocol_report.php?protocol_id=<?= (int) $p['id'] ?>">Rapport du plan</a><a class="btn btn-sm btn-outline-primary" href="experiment_manage.php?protocol_id=<?= (int) $p['id'] ?>">Expériences</a></td></tr><?php endforeach; ?><?php if (!$protocols): ?><tr><td colspan="6" class="text-muted">Aucun plan/protocole.</td></tr><?php endif; ?></tbody></table></div>
</main><?php require 'footer.php'; ?></body></html>
