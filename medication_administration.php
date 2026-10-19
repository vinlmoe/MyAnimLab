<?php
require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require_once 'training_lib.php';
require 'medication_lib.php';

if (!isset($_SESSION['username'])) { header('Location:index.php'); exit; }
$userId = (int) $_SESSION['user_id'];
$userName = $_SESSION['name'] ?? '';
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$cageId = trim($_GET['cage_id'] ?? $_POST['cage_id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) die('CSRF token validation failed');
    if (isset($_POST['cancel_administration'])) {
        try {
            mysqli_begin_transaction($con);
            $animalId = cancel_prescription_administration($con, (int) $_POST['cancel_administration'], (string) ($_POST['cancel_reason'] ?? ''), $userId, $userName, ($_SESSION['role'] ?? '') === 'admin');
            mysqli_commit($con);
            log_animal_event($con, $animalId, 'medication_administration_cancelled', ['administration_id' => (int) $_POST['cancel_administration'], 'reason' => trim((string) $_POST['cancel_reason'])], $userId);
            log_activity($con, 'administration_cancelled', 'animal', (string) $animalId, 'Administration #' . (int) $_POST['cancel_administration'] . ' annulée : ' . trim((string) $_POST['cancel_reason']), $userId);
            $_SESSION['message'] = 'Administration annulée : la quantité est recréditée sur le lot et l’animal est de nouveau à traiter.';
        } catch (Exception $e) {
            @mysqli_rollback($con);
            $_SESSION['message'] = 'Erreur : ' . error_message($e);
        }
        header('Location:medication_administration.php' . ($cageId !== '' ? '?cage_id=' . rawurlencode($cageId) : '')); exit;
    }
    $mode = $_POST['mode'] ?? 'single';
    $prescriptionId = (int) ($_POST['prescription_id'] ?? 0);
    $lotId = (int) ($_POST['medication_lot_id'] ?? 0);
    $quantity = (float) ($_POST['quantity_used'] ?? 0);
    $at = $_POST['administered_at'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    try {
        if (!$prescriptionId || !$at || $quantity <= 0) throw new Exception('Ordonnance, quantité et date sont obligatoires.');
        mysqli_begin_transaction($con);
        $animals = pending_prescription_animals($con, $prescriptionId, $mode === 'cage' ? $cageId : '');
        if ($mode === 'single') {
            $requestedAnimalId = (int) ($_POST['animal_id'] ?? 0);
            $animals = array_values(array_filter($animals, fn($a) => (int) $a['animal_id'] === $requestedAnimalId));
        }
        if (!$animals) throw new Exception('Aucun animal de cette ordonnance n’est encore à traiter.');
        $route = trim($_POST['route'] ?? '') ?: ($animals[0]['route'] ?? '');
        $reason = $mode === 'cage' ? 'Administration groupée cage ' . $cageId : 'Administration';
        $recorded = record_administration($con, $prescriptionId, $animals, $lotId, $quantity, $at, $route, $notes, $userId, $userName, $reason);
        mysqli_commit($con);
        foreach ($animals as $animal) {
            log_animal_event($con, (int) $animal['animal_id'], 'medication_administration', ['prescription_id' => $prescriptionId, 'lot_id' => $lotId, 'quantity' => $quantity, 'unit' => $animal['default_unit'], 'grouped_cage' => $mode === 'cage'], $userId);
        }
        $_SESSION['message'] = $recorded . ' administration(s) enregistrée(s) et stock débité.';
    } catch (Exception $e) {
        @mysqli_rollback($con);
        $_SESSION['message'] = 'Erreur : ' . error_message($e);
    }
    header('Location:medication_administration.php' . ($cageId !== '' ? '?cage_id=' . rawurlencode($cageId) : '')); exit;
}

$lots = $con->query("SELECT l.*,p.name,p.default_unit FROM medication_lots l JOIN medication_products p ON p.id=l.product_id WHERE l.remaining_quantity>0 AND l.expiry_date>=CURDATE() ORDER BY l.expiry_date")->fetch_all(MYSQLI_ASSOC);
$pending = $con->query("SELECT pa.prescription_id,pa.animal_id,pa.planned_administrations,pr.product_id,pr.route,p.name,p.default_unit,m.animal_uid,m.mouse_id,m.cage_id,COUNT(ma.id) AS given_count FROM prescription_animals pa JOIN prescriptions pr ON pr.id=pa.prescription_id JOIN medication_products p ON p.id=pr.product_id JOIN mice m ON m.id=pa.animal_id LEFT JOIN medication_administrations ma ON ma.prescription_id=pa.prescription_id AND ma.animal_id=pa.animal_id AND ma.cancelled_at IS NULL WHERE pr.status='active' AND m.status='active' GROUP BY pa.id HAVING COUNT(ma.id)<pa.planned_administrations ORDER BY m.animal_uid")->fetch_all(MYSQLI_ASSOC);
$cageTreatments = [];
if ($cageId !== '') {
    foreach ($pending as $item) if ($item['cage_id'] === $cageId) $cageTreatments[$item['prescription_id']][] = $item;
}
$hist = $con->query("SELECT a.*,m.animal_uid,m.mouse_id,p.name,l.lot_number FROM medication_administrations a JOIN mice m ON m.id=a.animal_id JOIN medication_lots l ON l.id=a.medication_lot_id JOIN medication_products p ON p.id=l.product_id ORDER BY a.administered_at DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC);
require 'header.php';
?>
<!doctype html><html lang="fr"><head><title>Administrations | <?= htmlspecialchars($labName) ?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body><main class="container mt-4 content" style="max-width:1100px">
<h4><i class="fas fa-syringe"></i> Administrations<?= $cageId !== '' ? ' — cage '.htmlspecialchars($cageId) : '' ?></h4><p><a class="btn btn-outline-primary btn-sm" href="prescription_manage.php">Ordonnances</a> <a class="btn btn-outline-secondary btn-sm" href="pharmacy_manage.php">Pharmacie</a></p><?php include 'message.php'; ?><?= training_procedure_banner($con, 'treatment') ?>
<?php if ($cageId !== ''): ?>
    <div class="alert alert-info">Chaque validation ci-dessous traite uniquement les animaux de la cage qui n’ont pas encore atteint le nombre d’administrations prévu. Une ligne est créée par animal.</div>
    <?php if (!$cageTreatments): ?><div class="alert alert-secondary">Aucun traitement actif restant pour cette cage.</div><?php endif; ?>
    <?php foreach ($cageTreatments as $prescriptionId => $animals): $first=$animals[0]; ?>
        <div class="card card-body mb-3" data-product="<?= (int) $first['product_id'] ?>"><h5><?= htmlspecialchars($first['name']) ?> <small class="text-muted">— ordonnance #<?= (int) $prescriptionId ?></small></h5><p class="mb-2"><?= count($animals) ?> animal(aux) à traiter : <?php foreach ($animals as $a): ?><span class="badge bg-secondary"><?= htmlspecialchars($a['animal_uid'] ?: $a['mouse_id']) ?> (<?= (int) $a['given_count'] ?>/<?= (int) $a['planned_administrations'] ?>)</span> <?php endforeach; ?></p>
            <form method="post" class="row g-2"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"><input type="hidden" name="mode" value="cage"><input type="hidden" name="cage_id" value="<?= htmlspecialchars($cageId) ?>"><input type="hidden" name="prescription_id" value="<?= (int) $prescriptionId ?>"><div class="col-md-4"><label class="form-label">Lot non périmé *</label><select class="form-select lot-select" name="medication_lot_id" required><option value="">Choisir…</option><?php foreach ($lots as $lot): ?><option value="<?= (int) $lot['id'] ?>" data-product="<?= (int) $lot['product_id'] ?>"><?= htmlspecialchars($lot['name'].' · lot '.$lot['lot_number'].' · '.$lot['remaining_quantity'].' '.$lot['default_unit'].' · exp. '.$lot['expiry_date']) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Date / heure *</label><input class="form-control" type="datetime-local" name="administered_at" value="<?= date('Y-m-d\TH:i') ?>" required></div><div class="col-md-2"><label class="form-label">Quantité / animal *</label><input class="form-control" type="number" step="0.001" min="0.001" name="quantity_used" required></div><div class="col-md-2"><label class="form-label">Voie</label><input class="form-control" name="route" value="<?= htmlspecialchars($first['route'] ?? '') ?>"></div><div class="col-md-1"><label class="form-label">&nbsp;</label><button class="btn btn-success w-100" title="Valider pour toute la cage">Valider</button></div><div class="col-12"><input class="form-control" name="notes" placeholder="Note facultative, appliquée à chaque animal"></div></form>
        </div>
    <?php endforeach; ?>
    <hr>
<?php endif; ?>
<div class="card card-body"><h5>Administration individuelle</h5><form method="post" class="row g-2"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"><input type="hidden" name="mode" value="single"><div class="col-md-6"><label class="form-label">Traitement actif *</label><select class="form-select pending-select" name="prescription_id" required><option value="">Choisir…</option><?php foreach ($pending as $p): ?><option value="<?= (int) $p['prescription_id'] ?>" data-animal="<?= (int) $p['animal_id'] ?>" data-product="<?= (int) $p['product_id'] ?>"><?= htmlspecialchars(($p['animal_uid'] ?: $p['mouse_id']).' · '.$p['cage_id'].' — '.$p['name'].' ('.$p['given_count'].'/'.$p['planned_administrations'].')') ?></option><?php endforeach; ?></select><input type="hidden" id="single-animal" name="animal_id"></div><div class="col-md-6"><label class="form-label">Lot non périmé *</label><select class="form-select lot-select" name="medication_lot_id" required><option value="">Choisir le traitement d’abord…</option><?php foreach ($lots as $lot): ?><option value="<?= (int) $lot['id'] ?>" data-product="<?= (int) $lot['product_id'] ?>"><?= htmlspecialchars($lot['name'].' · lot '.$lot['lot_number'].' · '.$lot['remaining_quantity'].' '.$lot['default_unit'].' · exp. '.$lot['expiry_date']) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Date / heure *</label><input class="form-control" type="datetime-local" name="administered_at" value="<?= date('Y-m-d\TH:i') ?>" required></div><div class="col-md-3"><label class="form-label">Quantité *</label><input class="form-control" type="number" step="0.001" min="0.001" name="quantity_used" required></div><div class="col-md-3"><label class="form-label">Voie</label><input class="form-control" name="route"></div><div class="col-md-3"><label class="form-label">Note</label><input class="form-control" name="notes"></div><div><button class="btn btn-success">Enregistrer l’administration</button></div></form></div>
<h5 class="mt-4">Dernières administrations</h5><div class="table-responsive" id="administrationHistory"><table class="table table-sm table-bordered"><thead><tr><th>Date</th><th>Animal</th><th>Médicament / lot</th><th>Quantité</th><th>Voie</th><th>Par</th><th>Annulation</th></tr></thead><tbody><?php foreach ($hist as $h): ?><tr><td><?= htmlspecialchars($h['administered_at']) ?></td><td><?= htmlspecialchars($h['animal_uid'] ?: $h['mouse_id']) ?></td><td><?= htmlspecialchars($h['name'].' · '.$h['lot_number']) ?><?php if ($h['experiment_act_animal_id']): ?> <span class="badge bg-info text-dark" title="<?= htmlspecialchars($h['notes'] ?? '') ?>">Acte expérimental</span><?php endif; ?><?php if ($h['cancelled_at']): ?> <span class="badge bg-warning text-dark" title="<?= htmlspecialchars(($h['cancelled_by_name'] ?? '') . ' : ' . ($h['cancel_reason'] ?? '')) ?>">Annulée le <?= htmlspecialchars(substr($h['cancelled_at'], 0, 10)) ?> (quantité recréditée)</span><?php endif; ?></td><td><?= htmlspecialchars($h['quantity_used'].' '.$h['unit']) ?></td><td><?= htmlspecialchars($h['route']) ?></td><td><?= htmlspecialchars($h['administered_by_name']) ?></td><td><?php if ($h['prescription_id'] && !$h['cancelled_at'] && ($isAdmin || (int) $h['administered_by'] === $userId)): ?><form method="post" class="d-flex gap-1" onsubmit="return confirm('Annuler cette administration ? La quantité sera recréditée sur le lot.')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"><input type="hidden" name="cage_id" value="<?= htmlspecialchars($cageId) ?>"><input class="form-control form-control-sm" name="cancel_reason" required maxlength="255" placeholder="Motif *" style="min-width:8rem"><button class="btn btn-outline-danger btn-sm" name="cancel_administration" value="<?= (int) $h['id'] ?>">Annuler</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
</main><script>
function filterLots(select,product){Array.from(select.options).forEach(o=>{o.hidden=!!o.dataset.product&&o.dataset.product!==product;});select.value='';}
document.querySelectorAll('.card[data-product] .lot-select').forEach(s=>filterLots(s,s.closest('.card').dataset.product));
const pendingSelect=document.querySelector('.pending-select'),singleAnimal=document.getElementById('single-animal'),singleLot=pendingSelect.closest('form').querySelector('.lot-select');
pendingSelect.addEventListener('change',()=>{const o=pendingSelect.options[pendingSelect.selectedIndex];singleAnimal.value=o.dataset.animal||'';filterLots(singleLot,o.dataset.product);});
</script><?php require 'footer.php'; ?></body></html>
