<?php

/** BEA-only actual-severity classification after experimental participation. */
session_start();
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
$isBea = ($_SESSION['role'] ?? '') === 'admin';
if (!$isBea && !empty($_SESSION['user_id'])) {
    $beaStmt = $con->prepare('SELECT is_bea_responsible FROM users WHERE id = ?');
    $beaStmt->bind_param('i', $_SESSION['user_id']);
    $beaStmt->execute();
    $isBea = !empty($beaStmt->get_result()->fetch_assoc()['is_bea_responsible']);
    $beaStmt->close();
}
if (!$isBea) {
    http_response_code(403);
    exit('Accès réservé au responsable BEA.');
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$classes = ['non_recovery' => 'Sans réveil', 'mild' => 'Léger', 'moderate' => 'Modéré', 'severe' => 'Sévère'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        die('CSRF token validation failed');
    }
    $enrollmentId = (int) ($_POST['experiment_animal_id'] ?? 0);
    $classification = $_POST['severity_classification'] ?? '';
    $notes = trim($_POST['assessment_notes'] ?? '');
    if (!isset($classes[$classification]) || $notes === '') {
        $_SESSION['message'] = 'Choisissez une classification et justifiez la décision.';
    } else {
        $eligible = $con->prepare('SELECT id FROM experiment_animals WHERE id = ? AND removed_at IS NOT NULL');
        $eligible->bind_param('i', $enrollmentId);
        $eligible->execute();
        $isEligible = $eligible->get_result()->num_rows === 1;
        $eligible->close();
        if (!$isEligible) {
            $_SESSION['message'] = 'Cette participation n’est pas encore clôturée.';
        } else {
            $userId = (int) $_SESSION['user_id'];
            $userName = $_SESSION['name'] ?? '';
            $stmt = $con->prepare("INSERT INTO experiment_retrospective_assessments (experiment_animal_id, severity_classification, assessment_notes, assessed_by, assessed_by_name)
                                   VALUES (?, ?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE severity_classification = VALUES(severity_classification), assessment_notes = VALUES(assessment_notes), assessed_by = VALUES(assessed_by), assessed_by_name = VALUES(assessed_by_name)");
            $stmt->bind_param('issis', $enrollmentId, $classification, $notes, $userId, $userName);
            $stmt->execute();
            $stmt->close();
            log_activity($con, 'retrospective_assessment', 'experiment_animal', (string) $enrollmentId, 'Actual severity: ' . $classification, $userId);
            $_SESSION['message'] = 'Évaluation rétrospective enregistrée.';
        }
    }
    header('Location: experiment_retrospective.php');
    exit;
}

$rows = $con->query("SELECT ea.id AS experiment_animal_id, ea.removed_at, ea.removal_reason, m.animal_uid, m.mouse_id, m.status AS animal_status, m.exit_date, m.exit_code,
                            e.experiment_number, e.title AS experiment_title, p.protocol_number,
                            ra.severity_classification, ra.assessment_notes, ra.assessed_at, ra.assessed_by_name
                     FROM experiment_animals ea
                     JOIN mice m ON m.id = ea.animal_id
                     JOIN experiments e ON e.id = ea.experiment_id
                     JOIN protocols p ON p.id = e.protocol_id
                     LEFT JOIN experiment_retrospective_assessments ra ON ra.experiment_animal_id = ea.id
                     WHERE ea.removed_at IS NOT NULL
                     ORDER BY ra.id IS NULL DESC, ea.removed_at DESC")->fetch_all(MYSQLI_ASSOC);
require 'header.php';
?>
<!doctype html><html lang="fr"><head><title>Analyse rétrospective | <?= htmlspecialchars($labName) ?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body>
<main class="container mt-4 content" style="max-width:1200px">
<h4>Analyse rétrospective — classification de sévérité réelle</h4>
<p class="text-muted">Accès responsable BEA. Les classifications sont attribuées après sortie de l’expérimentation : sans réveil, léger, modéré ou sévère.</p>
<?php include 'message.php'; ?>
<div class="table-responsive"><table class="table table-bordered align-middle"><thead class="table-light"><tr><th>Animal</th><th>Expérience</th><th>Clôture</th><th>Évaluation BEA</th></tr></thead><tbody>
<?php foreach ($rows as $row) : $label = $row['animal_uid'] ?: $row['mouse_id'] ?: ('#' . $row['experiment_animal_id']); ?>
<tr><td><?= htmlspecialchars($label) ?></td><td><?= htmlspecialchars($row['experiment_number'] . ' — ' . $row['experiment_title']) ?><br><small><?= htmlspecialchars($row['protocol_number']) ?></small></td><td><?= htmlspecialchars($row['removed_at']) ?><br><small><?= htmlspecialchars($row['removal_reason'] ?: $row['exit_code'] ?: 'Fin de participation') ?></small></td><td>
<form method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"><input type="hidden" name="experiment_animal_id" value="<?= (int) $row['experiment_animal_id'] ?>">
<select class="form-select form-select-sm mb-1" name="severity_classification" required><option value="" disabled <?= !$row['severity_classification'] ? 'selected' : '' ?>>Classification…</option><?php foreach ($classes as $key => $labelClass) : ?><option value="<?= $key ?>" <?= $row['severity_classification'] === $key ? 'selected' : '' ?>><?= $labelClass ?></option><?php endforeach; ?></select>
<textarea class="form-control form-control-sm mb-1" name="assessment_notes" rows="2" required placeholder="Justification, 3R, éléments à améliorer…"><?= htmlspecialchars($row['assessment_notes'] ?? '') ?></textarea><button class="btn btn-primary btn-sm" type="submit"><?= $row['severity_classification'] ? 'Mettre à jour' : 'Valider' ?></button>
<?php if ($row['severity_classification']) : ?><small class="d-block text-muted mt-1"><?= htmlspecialchars($classes[$row['severity_classification']]) ?> · <?= htmlspecialchars($row['assessed_by_name']) ?> · <?= htmlspecialchars($row['assessed_at']) ?></small><?php endif; ?></form>
</td></tr><?php endforeach; ?>
<?php if (!$rows) : ?><tr><td colspan="4" class="text-muted">Aucune participation expérimentale clôturée à évaluer.</td></tr><?php endif; ?></tbody></table></div>
</main><?php require 'footer.php'; ?></body></html>
