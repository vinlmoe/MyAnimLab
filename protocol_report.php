<?php
/** Printable report for one protocol (plan) and its experiments. */
session_start();
require 'dbcon.php';
require 'ethics_lib.php';
require 'animal_lib.php';
if (!isset($_SESSION['username'])) { header('Location:index.php?redirect=' . urlencode($_SERVER['REQUEST_URI'])); exit; }

$protocolId = (int) ($_GET['protocol_id'] ?? 0);
$stmt = $con->prepare('SELECT p.*,t.id AS trial_id,t.trial_number,t.title AS trial_title,t.iacuc_id FROM protocols p JOIN trials t ON t.id=p.trial_id WHERE p.id=?');
$stmt->bind_param('i', $protocolId); $stmt->execute(); $protocol = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$protocol) { require 'header.php'; echo "<main class='container mt-4'><div class='alert alert-danger'>Plan/protocole introuvable.</div></main>"; require 'footer.php'; exit; }
$grid = $protocol['clinical_grid_id'] ? load_clinical_grid($con, (int) $protocol['clinical_grid_id']) : null;
$thresholds = $con->query('SELECT * FROM protocol_endpoint_thresholds WHERE protocol_id=' . $protocolId . ' ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$experiments = $con->prepare("SELECT e.*, COUNT(ea.id) AS animal_count, SUM(ea.id IS NOT NULL AND ea.removed_at IS NULL) AS active_animal_count,
    (SELECT COUNT(*) FROM experiment_followups f JOIN experiment_animals ea2 ON ea2.id=f.experiment_animal_id WHERE ea2.experiment_id=e.id) AS followup_count
    FROM experiments e LEFT JOIN experiment_animals ea ON ea.experiment_id=e.id WHERE e.protocol_id=? GROUP BY e.id ORDER BY e.id");
$experiments->bind_param('i', $protocolId); $experiments->execute(); $experiments = $experiments->get_result()->fetch_all(MYSQLI_ASSOC);
require 'header.php';
?>
<!doctype html><html lang="fr"><head><title>Rapport plan/protocole | <?= htmlspecialchars($labName) ?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"><style>.report{max-width:1100px}@media print{body>*:not(.report){display:none!important}.no-print{display:none!important}}</style></head><body>
<main class="container mt-4 content report"><div class="no-print mb-3"><a href="protocol_manage.php?trial_id=<?= (int) $protocol['trial_id'] ?>">&laquo; Retour aux plans / protocoles</a><button class="btn btn-primary btn-sm ms-3" onclick="window.print()"><i class="fas fa-print"></i> Imprimer / PDF</button></div>
<h4>Rapport du plan / protocole — <?= htmlspecialchars($protocol['protocol_number']) ?></h4><p class="text-muted">Essai <?= htmlspecialchars($protocol['trial_number'].' — '.$protocol['trial_title']) ?><?php $auth = load_authorization($con, $protocol['iacuc_id']); ?><?= $auth ? ' · n° éthique '.htmlspecialchars($auth['iacuc_id']).($auth['authorization_number'] ? ' · autorisation '.htmlspecialchars($auth['authorization_number']) : '').' (validité '.htmlspecialchars(authorization_period($auth)).')' : '' ?> · édité le <?= date('d/m/Y H:i') ?></p>
<table class="table table-bordered table-sm"><tr><th style="width:25%">Titre</th><td><?= htmlspecialchars($protocol['title']) ?></td></tr><tr><th>Période</th><td><?= htmlspecialchars(($protocol['start_date'] ?: '—').' → '.($protocol['end_date'] ?: '—')) ?></td></tr><tr><th>Statut</th><td><?= htmlspecialchars(tr($protocol['status'])) ?></td></tr><tr><th>Méthodologie</th><td><?= nl2br(htmlspecialchars($protocol['methodology'] ?: '—')) ?></td></tr><tr><th>Critères de point limite</th><td><?= nl2br(htmlspecialchars($protocol['endpoint_criteria'] ?: '—')) ?></td></tr></table>
<h5>Grille clinique et critères opérationnels</h5><?php if ($grid): ?><p><strong><?= htmlspecialchars($grid['name']) ?></strong> — <?= htmlspecialchars($grid['description'] ?? '') ?></p><?php else: ?><p class="text-muted">Aucune grille clinique associée.</p><?php endif; ?><ul><?php foreach ($thresholds as $t): ?><li><?= htmlspecialchars(threshold_label($t, threshold_target_label($t, $grid, protocol_observation_fields($con, $protocolId, true)))) ?><?= $t['action_message'] ? ' — '.htmlspecialchars($t['action_message']) : '' ?></li><?php endforeach; ?><?php if (!$thresholds): ?><li class="text-muted">Aucun seuil opérationnel configuré.</li><?php endif; ?></ul>
<h5>Expérimentations</h5><div class="table-responsive"><table class="table table-bordered table-sm align-middle"><thead class="table-light"><tr><th>Expérience</th><th>Période</th><th>Statut</th><th>Animaux</th><th>Actifs</th><th>Suivis</th><th class="no-print">Rapport</th></tr></thead><tbody><?php foreach ($experiments as $e): ?><tr><td><strong><?= htmlspecialchars($e['experiment_number']) ?></strong><br><?= htmlspecialchars($e['title']) ?></td><td><?= htmlspecialchars(($e['start_date'] ?: '—').' → '.($e['end_date'] ?: '—')) ?></td><td><?= htmlspecialchars(tr($e['status'])) ?></td><td><?= (int) $e['animal_count'] ?></td><td><?= (int) $e['active_animal_count'] ?></td><td><?= (int) $e['followup_count'] ?></td><td class="no-print"><a class="btn btn-sm btn-outline-dark" href="experiment_report.php?experiment_id=<?= (int) $e['id'] ?>">Rapport</a></td></tr><?php endforeach; ?><?php if (!$experiments): ?><tr><td colspan="7" class="text-muted">Aucune expérimentation.</td></tr><?php endif; ?></tbody></table></div>
</main><?php require 'footer.php'; ?></body></html>
