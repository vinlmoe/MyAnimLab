<?php
// Planning view for animal uses, modelled on JCMS MouseUsage.
require_once 'session_config.php';
require 'dbcon.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die(tr('CSRF token validation failed'));
    $animalId = (int) ($_POST['animal_id'] ?? 0);
    $label = trim($_POST['use_label'] ?? '');
    $projected = $_POST['projected_date'] ?: null;
    $actual = $_POST['actual_date'] ?: null;
    $completed = isset($_POST['completed']) ? 1 : 0;
    $notes = trim($_POST['notes'] ?? '');
    if (!$animalId || $label === '') {
        $_SESSION['message'] = tr('Select an animal and enter the planned use.');
    } else {
        $stmt = $con->prepare('INSERT INTO animal_use_schedule (animal_id, use_label, projected_date, actual_date, completed, notes) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssis', $animalId, $label, $projected, $actual, $completed, $notes);
        $stmt->execute();
        $stmt->close();
        $_SESSION['message'] = tr('Animal use scheduled.');
    }
    header('Location: animal_use_schedule.php');
    exit;
}

$animals = $con->query("SELECT id, animal_uid, mouse_id, cage_id FROM mice WHERE status = 'active' ORDER BY animal_uid");
$uses = $con->query("SELECT aus.*, m.animal_uid, m.mouse_id, m.cage_id FROM animal_use_schedule aus JOIN mice m ON m.id = aus.animal_id ORDER BY aus.completed, COALESCE(aus.projected_date, aus.actual_date), aus.id DESC");
require 'header.php';
?>
<!doctype html><html lang="<?= htmlspecialchars(current_locale()) ?>"><head>
<title>Planification des utilisations | <?= htmlspecialchars($labName); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>.container { max-width: 1100px; background:#f8f9fa; padding:20px; border-radius:8px; }</style>
</head><body><main class="container content mt-4">
<h4>Planification des utilisations animales</h4><p class="text-muted">Planifiez les procédures et enregistrez leur réalisation sans perdre l’historique d’hébergement ni d’expérimentation.</p>
<?php include 'message.php'; ?>
<form method="post" class="row g-3 mb-4"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
<div class="col-md-4"><label class="form-label">Animal</label><select class="form-control" name="animal_id" required><option value=""><?= trh('Select animal') ?></option><?php while ($a = $animals->fetch_assoc()): ?><option value="<?= (int) $a['id']; ?>"><?= htmlspecialchars(($a['animal_uid'] ?: $a['mouse_id']) . ' — ' . tr('cage {cage}', ['cage' => $a['cage_id']])); ?></option><?php endwhile; ?></select></div>
<div class="col-md-4"><label class="form-label"><?= trh('Planned use') ?></label><input class="form-control" name="use_label" required></div>
<div class="col-md-2"><label class="form-label"><?= trh('Scheduled date') ?></label><input class="form-control" type="date" name="projected_date"></div>
<div class="col-md-2"><label class="form-label"><?= trh('Completion date') ?></label><input class="form-control" type="date" name="actual_date"></div>
<div class="col-12"><label class="form-label"><?= trh('Notes') ?></label><input class="form-control" name="notes"></div>
<div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" id="completed" name="completed"><label class="form-check-label" for="completed"><?= trh('Already completed') ?></label></div>
<div class="col-12"><button class="btn btn-primary"><?= trh('Save scheduled use') ?></button></div></form>
<div class="table-responsive"><table class="table table-bordered table-sm"><thead><tr><th><?= trh('Animal') ?></th><th><?= trh('Cage') ?></th><th><?= trh('Use') ?></th><th><?= trh('Scheduled') ?></th><th><?= trh('Done') ?></th><th><?= trh('Notes') ?></th></tr></thead><tbody>
<?php while ($use = $uses->fetch_assoc()): ?><tr><td><?= htmlspecialchars($use['animal_uid'] ?: $use['mouse_id']); ?></td><td><?= htmlspecialchars($use['cage_id']); ?></td><td><?= htmlspecialchars($use['use_label']); ?></td><td><?= htmlspecialchars($use['projected_date'] ?? ''); ?></td><td><?= $use['completed'] ? htmlspecialchars($use['actual_date'] ?: tr('Yes')) : trh('No'); ?></td><td><?= htmlspecialchars($use['notes'] ?? ''); ?></td></tr><?php endwhile; ?>
</tbody></table></div></main><?php include 'footer.php'; ?></body></html>
