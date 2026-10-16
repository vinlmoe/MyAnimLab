<?php
session_start();
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) { header('Location:index.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die('CSRF token validation failed');

    $animalIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['animal_ids'] ?? [])))));
    $productId = (int) ($_POST['product_id'] ?? 0);
    $planned = (int) ($_POST['planned_administrations'] ?? 0);
    $prescriber = trim($_POST['prescriber_name'] ?? '');
    $prescribedOn = $_POST['prescribed_on'] ?? '';

    try {
        if (!$animalIds || !$productId || !$prescriber || !$prescribedOn || $planned < 1) {
            throw new Exception('Au moins un animal, le médicament, le prescripteur, la date et le nombre d’administrations sont obligatoires.');
        }
        $placeholders = implode(',', array_fill(0, count($animalIds), '?'));
        $types = str_repeat('i', count($animalIds));
        $check = $con->prepare("SELECT id FROM mice WHERE status = 'active' AND id IN ($placeholders)");
        $check->bind_param($types, ...$animalIds);
        $check->execute();
        $validIds = array_map('intval', array_column($check->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
        $check->close();
        if (count($validIds) !== count($animalIds)) throw new Exception('Un ou plusieurs animaux ne sont plus actifs.');

        mysqli_begin_transaction($con);
        $reference = trim($_POST['prescription_reference'] ?? '');
        $start = $_POST['start_date'] ?: null;
        $end = $_POST['end_date'] ?: null;
        $dose = ($_POST['dose_amount'] ?? '') !== '' ? (float) $_POST['dose_amount'] : null;
        $unit = trim($_POST['dose_unit'] ?? '');
        $route = trim($_POST['route'] ?? '');
        $frequency = trim($_POST['frequency'] ?? '');
        $instructions = trim($_POST['instructions'] ?? '');
        $userId = (int) $_SESSION['user_id'];
        // The old column is kept as an anchor for compatibility with legacy reports.
        $anchorAnimalId = $animalIds[0];
        $insert = $con->prepare('INSERT INTO prescriptions (animal_id,product_id,prescription_reference,prescriber_name,prescribed_on,start_date,end_date,dose_amount,dose_unit,route,frequency,instructions,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $insert->bind_param('iisssssdssssi', $anchorAnimalId, $productId, $reference, $prescriber, $prescribedOn, $start, $end, $dose, $unit, $route, $frequency, $instructions, $userId);
        $insert->execute();
        $prescriptionId = $con->insert_id;
        $insert->close();

        $link = $con->prepare('INSERT INTO prescription_animals (prescription_id,animal_id,planned_administrations) VALUES (?,?,?)');
        foreach ($animalIds as $animalId) {
            $link->bind_param('iii', $prescriptionId, $animalId, $planned);
            $link->execute();
        }
        $link->close();
        mysqli_commit($con);
        $_SESSION['message'] = 'Ordonnance enregistrée pour ' . count($animalIds) . ' animal(aux), avec ' . $planned . ' administration(s) prévue(s) par animal.';
    } catch (Exception $e) {
        @mysqli_rollback($con);
        $_SESSION['message'] = 'Erreur : ' . $e->getMessage();
    }
    header('Location:prescription_manage.php'); exit;
}

$animals = $con->query("SELECT m.id,m.animal_uid,m.mouse_id,m.cage_id,COALESCE(c.room,'') AS room FROM mice m LEFT JOIN cages c ON c.cage_id=m.cage_id WHERE m.status='active' ORDER BY c.room,m.cage_id,m.animal_uid")->fetch_all(MYSQLI_ASSOC);
$products = $con->query('SELECT id,name,default_unit FROM medication_products WHERE active=1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$rows = $con->query("SELECT pr.*,p.name,COUNT(pa.id) AS animal_count,SUM(pa.planned_administrations) AS planned_total,GROUP_CONCAT(CONCAT(COALESCE(m.animal_uid,m.mouse_id), ' (', COALESCE(m.cage_id,'—'), ')') ORDER BY m.animal_uid SEPARATOR ', ') AS animals FROM prescriptions pr JOIN medication_products p ON p.id=pr.product_id LEFT JOIN prescription_animals pa ON pa.prescription_id=pr.id LEFT JOIN mice m ON m.id=pa.animal_id GROUP BY pr.id ORDER BY pr.created_at DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC);
require 'header.php';
?>
<!doctype html><html lang="fr"><head><title>Ordonnances | <?= htmlspecialchars($labName) ?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body>
<main class="container mt-4 content" style="max-width:1100px">
    <h4><i class="fas fa-file-prescription"></i> Ordonnances</h4>
    <p><a class="btn btn-outline-primary btn-sm" href="pharmacy_manage.php">Pharmacie</a> <a class="btn btn-success btn-sm" href="medication_administration.php">Administrer</a></p>
    <?php include 'message.php'; ?>
    <div class="card card-body"><form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <div class="col-md-8"><label class="form-label">Animaux concernés *</label>
            <select class="form-select" id="animal_ids" name="animal_ids[]" multiple size="8" required>
                <?php foreach ($animals as $a): $label = ($a['animal_uid'] ?: $a['mouse_id'] ?: ('#'.$a['id'])); ?>
                    <option value="<?= (int) $a['id'] ?>" data-room="<?= htmlspecialchars($a['room']) ?>"><?= htmlspecialchars(($a['room'] ?: 'Sans salle').' · '.($a['cage_id'] ?: 'sans cage').' · '.$label) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="d-flex gap-2 mt-2 align-items-center"><select class="form-select form-select-sm" id="room_picker" style="max-width:270px"><option value="">Ajouter tous les animaux d’une salle…</option><?php foreach (array_values(array_unique(array_column($animals, 'room'))) as $room): ?><option value="<?= htmlspecialchars($room) ?>"><?= htmlspecialchars($room ?: 'Sans salle') ?></option><?php endforeach; ?></select><button type="button" class="btn btn-outline-secondary btn-sm" id="add_room">Ajouter la salle</button><button type="button" class="btn btn-outline-secondary btn-sm" id="clear_animals">Vider la sélection</button></div>
            <small class="text-muted">Utilisez Ctrl/Cmd pour sélectionner individuellement, ou ajoutez tous les animaux actifs d’une salle.</small>
        </div>
        <div class="col-md-4"><label class="form-label">Médicament *</label><select class="form-select" name="product_id" required><option value="">Choisir…</option><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?></select>
            <label class="form-label mt-2">Administrations prévues par animal *</label><input class="form-control" type="number" name="planned_administrations" min="1" value="1" required><small class="text-muted">Une administration ne pourra être validée que tant que ce nombre n’est pas atteint pour l’animal.</small></div>
        <div class="col-md-4"><label class="form-label">Vétérinaire / prescripteur *</label><input class="form-control" name="prescriber_name" required></div><div class="col-md-3"><label class="form-label">Référence ordonnance</label><input class="form-control" name="prescription_reference"></div><div class="col-md-2"><label class="form-label">Date *</label><input class="form-control" type="date" name="prescribed_on" value="<?= date('Y-m-d') ?>" required></div><div class="col-md-3"><label class="form-label">Début / fin</label><div class="d-flex gap-1"><input class="form-control" type="date" name="start_date"><input class="form-control" type="date" name="end_date"></div></div><div class="col-md-3"><label class="form-label">Dose</label><div class="d-flex gap-1"><input class="form-control" type="number" step="0.001" name="dose_amount"><input class="form-control" name="dose_unit" placeholder="mg/kg, mL…"></div></div><div class="col-md-4"><label class="form-label">Voie</label><input class="form-control" name="route" placeholder="orale, SC, IP…"></div><div class="col-md-4"><label class="form-label">Fréquence</label><input class="form-control" name="frequency" placeholder="2 fois/jour…"></div><div class="col-md-4"><label class="form-label">Consignes</label><input class="form-control" name="instructions"></div><div><button class="btn btn-primary">Enregistrer l’ordonnance</button></div>
    </form></div>
    <h5 class="mt-4">Historique</h5><div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Animaux</th><th>Médicament</th><th>Prescripteur</th><th>Période</th><th>Prévu</th><th>Statut</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><?= htmlspecialchars($r['animals'] ?: '—') ?><br><small class="text-muted"><?= (int) $r['animal_count'] ?> animal(aux)</small></td><td><?= htmlspecialchars($r['name']) ?></td><td><?= htmlspecialchars($r['prescriber_name']) ?></td><td><?= htmlspecialchars($r['start_date'] ?: $r['prescribed_on']) ?> → <?= htmlspecialchars($r['end_date'] ?: '…') ?></td><td><?= (int) $r['planned_total'] ?> administration(s)</td><td><?= htmlspecialchars(tr($r['status'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
</main><script>
const animalSelect=document.getElementById('animal_ids');
document.getElementById('add_room').addEventListener('click',()=>{const room=document.getElementById('room_picker').value;if(room==='')return;Array.from(animalSelect.options).forEach(o=>{if(o.dataset.room===room)o.selected=true;});});
document.getElementById('clear_animals').addEventListener('click',()=>{Array.from(animalSelect.options).forEach(o=>o.selected=false);});
</script><?php require 'footer.php'; ?></body></html>
