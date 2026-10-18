<?php
require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) { header('Location:index.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$userId = (int) $_SESSION['user_id'];
$userName = $_SESSION['name'] ?? '';
$cageId = trim($_GET['cage_id'] ?? $_POST['cage_id'] ?? '');

/** Return the still-eligible animals of an active prescription, optionally only from one cage. */
function pending_prescription_animals(mysqli $con, int $prescriptionId, string $cageId = ''): array {
    $sql = "SELECT pa.id AS prescription_animal_id, pa.animal_id, pa.planned_administrations, pr.product_id, pr.route, p.default_unit,
                   m.animal_uid, m.mouse_id, m.cage_id
            FROM prescription_animals pa
            JOIN prescriptions pr ON pr.id=pa.prescription_id AND pr.status='active'
            JOIN medication_products p ON p.id=pr.product_id
            JOIN mice m ON m.id=pa.animal_id AND m.status='active'
            WHERE pa.prescription_id=?" . ($cageId !== '' ? ' AND m.cage_id=?' : '') . ' FOR UPDATE';
    $stmt = $con->prepare($sql);
    if ($cageId !== '') $stmt->bind_param('is', $prescriptionId, $cageId); else $stmt->bind_param('i', $prescriptionId);
    $stmt->execute();
    $eligible = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $count = $con->prepare('SELECT COUNT(*) AS given_count FROM medication_administrations WHERE prescription_id=? AND animal_id=?');
        $count->bind_param('ii', $prescriptionId, $row['animal_id']);
        $count->execute();
        $given = (int) $count->get_result()->fetch_assoc()['given_count'];
        $count->close();
        if ($given < (int) $row['planned_administrations']) {
            $row['given_count'] = $given;
            $eligible[] = $row;
        }
    }
    $stmt->close();
    return $eligible;
}

/** Insert a separate trace for every animal and debit the lot once for the total. Must run in a transaction. */
function record_administration(mysqli $con, int $prescriptionId, array $animals, int $lotId, float $quantityEach, string $administeredAt, string $route, string $notes, int $userId, string $userName, string $reason): int {
    if (!$animals || !$lotId || $quantityEach <= 0 || !$administeredAt) throw new Exception('Aucun animal éligible, lot, quantité ou date manquant.');
    $productId = (int) $animals[0]['product_id'];
    $unit = $animals[0]['default_unit'];
    $lotStmt = $con->prepare('SELECT product_id,remaining_quantity,expiry_date FROM medication_lots WHERE id=? FOR UPDATE');
    $lotStmt->bind_param('i', $lotId); $lotStmt->execute(); $lot = $lotStmt->get_result()->fetch_assoc(); $lotStmt->close();
    $total = $quantityEach * count($animals);
    if (!$lot || (int) $lot['product_id'] !== $productId) throw new Exception('Lot incompatible avec l’ordonnance.');
    if ($lot['expiry_date'] < substr($administeredAt, 0, 10)) throw new Exception('Lot périmé : administration bloquée.');
    if ((float) $lot['remaining_quantity'] < $total) throw new Exception('Stock insuffisant pour les ' . count($animals) . ' animaux concernés.');

    $add = $con->prepare('INSERT INTO medication_administrations (prescription_id,animal_id,medication_lot_id,administered_at,quantity_used,unit,route,notes,administered_by,administered_by_name) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $lastAdministrationId = null;
    foreach ($animals as $animal) {
        $animalId = (int) $animal['animal_id'];
        $add->bind_param('iiisdsssis', $prescriptionId, $animalId, $lotId, $administeredAt, $quantityEach, $unit, $route, $notes, $userId, $userName);
        $add->execute();
        $lastAdministrationId = $con->insert_id;
    }
    $add->close();
    $update = $con->prepare('UPDATE medication_lots SET remaining_quantity=remaining_quantity-?, in_use_since=COALESCE(in_use_since, ?) WHERE id=?');
    $useDate = substr($administeredAt, 0, 10);
    $update->bind_param('dsi', $total, $useDate, $lotId); $update->execute(); $update->close();
    $movement = $con->prepare("INSERT INTO medication_stock_movements (medication_lot_id,movement_type,quantity_delta,reason,administration_id,performed_by) VALUES (?,'administration',?,?,?,?)");
    $delta = -$total;
    $movement->bind_param('idsii', $lotId, $delta, $reason, $lastAdministrationId, $userId); $movement->execute(); $movement->close();
    return count($animals);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die('CSRF token validation failed');
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
        $_SESSION['message'] = 'Erreur : ' . $e->getMessage();
    }
    header('Location:medication_administration.php' . ($cageId !== '' ? '?cage_id=' . rawurlencode($cageId) : '')); exit;
}

$lots = $con->query("SELECT l.*,p.name,p.default_unit FROM medication_lots l JOIN medication_products p ON p.id=l.product_id WHERE l.remaining_quantity>0 AND l.expiry_date>=CURDATE() ORDER BY l.expiry_date")->fetch_all(MYSQLI_ASSOC);
$pending = $con->query("SELECT pa.prescription_id,pa.animal_id,pa.planned_administrations,pr.product_id,pr.route,p.name,p.default_unit,m.animal_uid,m.mouse_id,m.cage_id,COUNT(ma.id) AS given_count FROM prescription_animals pa JOIN prescriptions pr ON pr.id=pa.prescription_id JOIN medication_products p ON p.id=pr.product_id JOIN mice m ON m.id=pa.animal_id LEFT JOIN medication_administrations ma ON ma.prescription_id=pa.prescription_id AND ma.animal_id=pa.animal_id WHERE pr.status='active' AND m.status='active' GROUP BY pa.id HAVING COUNT(ma.id)<pa.planned_administrations ORDER BY m.animal_uid")->fetch_all(MYSQLI_ASSOC);
$cageTreatments = [];
if ($cageId !== '') {
    foreach ($pending as $item) if ($item['cage_id'] === $cageId) $cageTreatments[$item['prescription_id']][] = $item;
}
$hist = $con->query("SELECT a.*,m.animal_uid,m.mouse_id,p.name,l.lot_number FROM medication_administrations a JOIN mice m ON m.id=a.animal_id JOIN medication_lots l ON l.id=a.medication_lot_id JOIN medication_products p ON p.id=l.product_id ORDER BY a.administered_at DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC);
require 'header.php';
?>
<!doctype html><html lang="fr"><head><title>Administrations | <?= htmlspecialchars($labName) ?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body><main class="container mt-4 content" style="max-width:1100px">
<h4><i class="fas fa-syringe"></i> Administrations<?= $cageId !== '' ? ' — cage '.htmlspecialchars($cageId) : '' ?></h4><p><a class="btn btn-outline-primary btn-sm" href="prescription_manage.php">Ordonnances</a> <a class="btn btn-outline-secondary btn-sm" href="pharmacy_manage.php">Pharmacie</a></p><?php include 'message.php'; ?>
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
<h5 class="mt-4">Dernières administrations</h5><div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Date</th><th>Animal</th><th>Médicament / lot</th><th>Quantité</th><th>Voie</th><th>Par</th></tr></thead><tbody><?php foreach ($hist as $h): ?><tr><td><?= htmlspecialchars($h['administered_at']) ?></td><td><?= htmlspecialchars($h['animal_uid'] ?: $h['mouse_id']) ?></td><td><?= htmlspecialchars($h['name'].' · '.$h['lot_number']) ?><?php if ($h['experiment_act_animal_id']): ?> <span class="badge bg-info text-dark" title="<?= htmlspecialchars($h['notes'] ?? '') ?>">Acte expérimental</span><?php endif; ?><?php if ($h['cancelled_at']): ?> <span class="badge bg-warning text-dark" title="<?= htmlspecialchars(($h['cancelled_by_name'] ?? '') . ' : ' . ($h['cancel_reason'] ?? '')) ?>">Annulée le <?= htmlspecialchars(substr($h['cancelled_at'], 0, 10)) ?> (quantité recréditée)</span><?php endif; ?></td><td><?= htmlspecialchars($h['quantity_used'].' '.$h['unit']) ?></td><td><?= htmlspecialchars($h['route']) ?></td><td><?= htmlspecialchars($h['administered_by_name']) ?></td></tr><?php endforeach; ?></tbody></table></div>
</main><script>
function filterLots(select,product){Array.from(select.options).forEach(o=>{o.hidden=!!o.dataset.product&&o.dataset.product!==product;});select.value='';}
document.querySelectorAll('.card[data-product] .lot-select').forEach(s=>filterLots(s,s.closest('.card').dataset.product));
const pendingSelect=document.querySelector('.pending-select'),singleAnimal=document.getElementById('single-animal'),singleLot=pendingSelect.closest('form').querySelector('.lot-select');
pendingSelect.addEventListener('change',()=>{const o=pendingSelect.options[pendingSelect.selectedIndex];singleAnimal.value=o.dataset.animal||'';filterLots(singleLot,o.dataset.product);});
</script><?php require 'footer.php'; ?></body></html>
