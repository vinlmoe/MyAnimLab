<?php
// Create a batch of identified animals and distribute it over the minimum
// number of holding cages required by the requested capacity.
require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        die(tr('CSRF token validation failed'));
    }

    $animalCount = (int) ($_POST['animal_count'] ?? 0);
    $animalsPerCage = (int) ($_POST['animals_per_cage'] ?? 0);
    $minimumPerCage = (int) ($_POST['minimum_per_cage'] ?? 0);
    $piId = (int) ($_POST['pi_name'] ?? 0) ?: null;
    $lineId = (int) ($_POST['line_id'] ?? 0);
    $sex = $_POST['sex'] ?? '';
    $dob = $_POST['dob'] ?? null;
    $room = trim($_POST['room'] ?? '') ?: null;
    $rack = trim($_POST['rack'] ?? '') ?: null;
    $remarks = trim($_POST['remarks'] ?? '');
    $userIds = array_values(array_unique(array_filter(array_map('intval', $_POST['user_ids'] ?? []))));
    [$originError, $origin] = origin_from_input($_POST, $dob ?: null);

    if ($animalCount < 1 || $animalCount > 1000) {
        $_SESSION['message'] = tr('Choose between 1 and 1,000 animals.');
    } elseif ($animalsPerCage < 1 || $animalsPerCage > 50) {
        $_SESSION['message'] = tr('Choose a capacity between 1 and 50 animals per cage.');
    } elseif ($minimumPerCage < 1 || $minimumPerCage > $animalsPerCage) {
        $_SESSION['message'] = tr('The minimum per cage must be between 1 and the maximum capacity.');
    } elseif ((int) ceil($animalCount / $animalsPerCage) * $minimumPerCage > $animalCount) {
        $_SESSION['message'] = tr('This distribution is not possible: {count} animals cannot fill cages with at least {min} and at most {max} animals each.', ['count' => $animalCount, 'min' => $minimumPerCage, 'max' => $animalsPerCage]);
    } elseif (!$piId) {
        $_SESSION['message'] = tr('Select a PI.');
    } elseif (!$lineId || !in_array($sex, ['male', 'female'], true) || !$dob) {
        $_SESSION['message'] = tr('Select an animal line, sex and date of birth.');
    } elseif ($originError) {
        $_SESSION['message'] = $originError;
    } else {
        mysqli_begin_transaction($con);
        try {
            $created = [];
            $cageCount = (int) ceil($animalCount / $animalsPerCage);
            $basePerCage = intdiv($animalCount, $cageCount);
            $cagesWithOneExtra = $animalCount % $cageCount;
            for ($i = 0; $i < $cageCount; $i++) {
                $forThisCage = $basePerCage + ($i < $cagesWithOneExtra ? 1 : 0);
                $why = tr('Bulk creation: {n} {sex} animal(s)', ['n' => $forThisCage, 'sex' => tr($sex)]);
                $cageId = create_holding_cage($con, $piId, $room, $rack, $dob, $userIds, $why, $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? null);
                if ($remarks !== '') {
                    $update = $con->prepare('UPDATE cages SET remarks = ? WHERE cage_id = ?');
                    $update->bind_param('ss', $remarks, $cageId);
                    $update->execute();
                    $update->close();
                }
                for ($animal = 0; $animal < $forThisCage; $animal++) {
                    create_animal($con, $cageId, $lineId, $sex, $dob, null, tr('Bulk creation'), $_SESSION['user_id'] ?? null, $origin);
                }
                if (!empty($_POST['is_experimental'])) {
                    set_cages_experimental($con, [$cageId], true, $_SESSION['user_id'] ?? null);
                }
                $created[] = $cageId;
            }
            mysqli_commit($con);
            $_SESSION['message'] = tr('{n} {sex} animal(s) created and distributed across {cages} cage(s): {list}.', ['n' => $animalCount, 'sex' => tr($sex), 'cages' => count($created), 'list' => implode(', ', $created)]);
            header('Location: ' . (!empty($_POST['is_experimental']) ? 'ec_dash.php' : 'hc_dash.php'));
            exit;
        } catch (Exception $e) {
            mysqli_rollback($con);
            $_SESSION['message'] = tr('Bulk cage creation failed: {error}', ['error' => error_message($e)]);
        }
    }
}

$pis = $con->query("SELECT id, initials, name FROM users WHERE position = 'Principal Investigator' AND status = 'approved' ORDER BY name");
$users = $con->query("SELECT id, initials, name FROM users WHERE status = 'approved' ORDER BY name");
$lines = $con->query("SELECT id, code, species, name FROM animal_lines ORDER BY code");
require 'header.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">
<head>
    <title>Création groupée d’animaux et cages | <?= htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>.container { max-width: 720px; background: #f8f9fa; padding: 20px; border-radius: 8px; }</style>
</head>
<body>
<main class="container content mt-4">
    <h4>Création groupée d’animaux et cages de maintien</h4>
    <p class="text-muted">Crée les animaux demandés, calcule le nombre minimal de cages numérotées, puis répartit le lot entre le minimum et le maximum définis par cage.</p>
    <?php include 'message.php'; ?>
    <form method="post" id="bulk-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
        <div class="mb-3">
            <label class="form-label" for="animal_count">Nombre d’animaux (1 à 1 000)</label>
            <input class="form-control" type="number" id="animal_count" name="animal_count" min="1" max="1000" value="40" required>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label" for="line_id">Lignée animale</label>
                <select class="form-control" id="line_id" name="line_id" required><option value="" selected disabled>Sélectionner une lignée</option>
                    <?php while ($line = $lines->fetch_assoc()) : ?><option value="<?= (int) $line['id']; ?>"><?= htmlspecialchars($line['code'] . ' — ' . $line['name'] . ' (' . $line['species'] . ')'); ?></option><?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-3 mb-3"><label class="form-label" for="sex">Sexe</label><select class="form-control" id="sex" name="sex" required><option value="male">Mâle</option><option value="female">Femelle</option></select></div>
            <div class="col-md-3 mb-3"><label class="form-label" for="dob">Date de naissance</label><input class="form-control" type="date" id="dob" name="dob" max="<?= date('Y-m-d'); ?>" required></div>
        </div>
        <?= animal_origin_fields($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [], true); ?>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label" for="minimum_per_cage">Minimum d’animaux par cage</label>
                <input class="form-control" type="number" id="minimum_per_cage" name="minimum_per_cage" min="1" max="50" value="1" required>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label" for="animals_per_cage">Maximum d’animaux par cage</label>
                <input class="form-control" type="number" id="animals_per_cage" name="animals_per_cage" min="1" max="50" value="3" required>
            </div>
        </div>
        <div class="alert alert-info py-2" id="cage_calculation" role="status"></div>
        <div class="mb-3">
            <label class="form-label" for="pi_name">Responsable scientifique</label>
            <select class="form-control" id="pi_name" name="pi_name" required>
                <option value="" selected disabled>Sélectionner un responsable</option>
                <?php while ($pi = $pis->fetch_assoc()) : ?>
                    <option value="<?= (int) $pi['id']; ?>"><?= htmlspecialchars(($pi['initials'] ?: '') . ' [' . $pi['name'] . ']'); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="user_ids">Utilisateurs responsables</label>
            <select class="form-control" id="user_ids" name="user_ids[]" multiple size="5">
                <?php while ($user = $users->fetch_assoc()) : ?>
                    <option value="<?= (int) $user['id']; ?>"><?= htmlspecialchars(($user['initials'] ?: '') . ' [' . $user['name'] . ']'); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label" for="room">Salle</label><input class="form-control" id="room" name="room" list="roomList"></div>
            <div class="col-md-6 mb-3"><label class="form-label" for="rack">Rack</label><input class="form-control" id="rack" name="rack"></div>
            <div class="col-12 mb-3 form-check ms-2">
                <input class="form-check-input" type="checkbox" id="is_experimental" name="is_experimental" value="1" <?= !empty($_GET['experimental']) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="is_experimental">Cage d’expérimentation</label>
            </div>
        </div>
        <datalist id="roomList">
            <?php foreach ($con->query("SELECT DISTINCT room FROM cages WHERE room IS NOT NULL AND room <> '' ORDER BY room") as $row) : ?>
                <option value="<?= htmlspecialchars($row['room']); ?>">
            <?php endforeach; ?>
        </datalist>
        <div class="mb-3"><label class="form-label" for="remarks">Remarques communes</label><textarea class="form-control" id="remarks" name="remarks"></textarea></div>
        <button class="btn btn-primary" type="submit">Créer les animaux et cages</button>
        <a class="btn btn-secondary" href="hc_dash.php">Annuler</a>
    </form>
</main>
<script>
    const animalCount = document.getElementById('animal_count');
    const minimumPerCage = document.getElementById('minimum_per_cage');
    const animalsPerCage = document.getElementById('animals_per_cage');
    const calculation = document.getElementById('cage_calculation');
    function updateCageCalculation() {
        const count = Math.max(0, parseInt(animalCount.value, 10) || 0);
        const minimum = Math.max(1, parseInt(minimumPerCage.value, 10) || 1);
        const capacity = Math.max(1, parseInt(animalsPerCage.value, 10) || 1);
        const cages = Math.ceil(count / capacity);
        const feasible = minimum <= capacity && cages > 0 && cages * minimum <= count;
        calculation.className = `alert py-2 ${feasible ? 'alert-info' : 'alert-warning'}`;
        if (!feasible) {
            calculation.textContent = `Répartition impossible : ${count} animal(aux) ne peuvent pas être répartis dans des cages de ${minimum} à ${capacity}. Modifiez le minimum, le maximum ou la taille du lot.`;
            return false;
        }
        const base = Math.floor(count / cages);
        const extra = count % cages;
        calculation.textContent = `${cages} cage(s) seront créées : ${extra ? `${extra} cage(s) de ${base + 1} et ${cages - extra} cage(s) de ${base}.` : `${cages} cage(s) de ${base}.`} Chaque cage contiendra entre ${minimum} et ${capacity} animal(aux).`;
        return true;
    }
    animalCount.addEventListener('input', updateCageCalculation);
    minimumPerCage.addEventListener('input', updateCageCalculation);
    animalsPerCage.addEventListener('input', updateCageCalculation);
    document.getElementById('bulk-form').addEventListener('submit', event => { if (!updateCageCalculation()) event.preventDefault(); });
    updateCageCalculation();
</script>
<?php include 'footer.php'; ?>
</body>
</html>
