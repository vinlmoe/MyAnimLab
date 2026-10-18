<?php
/**
 * Select cages for label printing (cage_labels.php), for every category.
 * Filters by category and room; "select all" adds every listed cage.
 */
require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$type = $_GET['type'] ?? '';
if (!isset(CAGE_KINDS[$type])) {
    $type = '';
}
$room = $_GET['room'] ?? '';

$where = ["c.status = 'active'"];
$types = '';
$params = [];
if ($type !== '') {
    $where[] = '(' . CAGE_KIND_SQL . ') = ?';
    $types .= 's';
    $params[] = $type;
}
if ($room === '__none__') {
    $where[] = "(c.room IS NULL OR c.room = '')";
} elseif ($room !== '') {
    $where[] = 'c.room = ?';
    $types .= 's';
    $params[] = $room;
}
$stmt = $con->prepare("SELECT c.cage_id, c.room, c.rack, " . CAGE_KIND_SQL . " AS kind FROM cages c WHERE " . implode(' AND ', $where) . " ORDER BY c.cage_id");
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$cages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$rooms = array_column($con->query("SELECT DISTINCT room FROM cages WHERE status = 'active' AND room IS NOT NULL AND room <> '' ORDER BY room")->fetch_all(MYSQLI_ASSOC), 'room');

require 'header.php';
?>
<!doctype html>
<html lang="fr">
<head>
    <title>Imprimer des étiquettes de cages | <?= htmlspecialchars($labName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        .label-select-container { max-width: 800px; margin: 50px auto; padding: 24px; background: #f8f9fa; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,.1); }
    </style>
</head>
<body>
    <main class="content label-select-container">
        <h4>Imprimer des étiquettes de cages</h4>
        <p class="text-muted">Même étiquette pour toutes les catégories de cages : un quart de feuille A4 en portrait.
            Vous pouvez aussi cocher des cages dans un tableau de bord et cliquer sur « Imprimer les étiquettes ».</p>

        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-6">
                <label class="form-label mb-0" for="type">Catégorie</label>
                <select id="type" name="type" class="form-select" onchange="this.form.submit()">
                    <option value="">Toutes les catégories</option>
                    <?php foreach (CAGE_KINDS as $k => [$label]) : ?>
                        <option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label mb-0" for="room">Salle</label>
                <select id="room" name="room" class="form-select" onchange="this.form.submit()">
                    <option value="">Toutes les salles</option>
                    <?php foreach ($rooms as $r) : ?>
                        <option value="<?= htmlspecialchars($r) ?>" <?= $room === $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                    <?php endforeach; ?>
                    <option value="__none__" <?= $room === '__none__' ? 'selected' : '' ?>>Sans salle</option>
                </select>
            </div>
        </form>

        <form method="GET" action="cage_labels.php" target="_blank">
            <label for="cageIds" class="form-label">Cages à imprimer (<?= count($cages) ?> disponible(s))</label>
            <select id="cageIds" name="id[]" class="form-select" multiple required>
                <?php foreach ($cages as $cage) : ?>
                    <option value="<?= htmlspecialchars($cage['cage_id']) ?>">
                        <?= htmlspecialchars($cage['cage_id']) ?> — <?= htmlspecialchars(CAGE_KINDS[$cage['kind']][0]) ?><?= $cage['room'] ? ' · salle ' . htmlspecialchars($cage['room']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="mt-3 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary"><i class="fas fa-print"></i> Ouvrir les étiquettes</button>
                <button type="button" class="btn btn-outline-secondary" id="selectAllCages">Tout sélectionner</button>
                <button type="button" class="btn btn-outline-secondary" id="clearCages">Vider</button>
                <button type="button" class="btn btn-secondary" onclick="history.back()">Retour</button>
            </div>
        </form>
    </main>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        const cageSelect = $('#cageIds').select2({ placeholder: 'Rechercher et sélectionner des cages', width: '100%' });
        $('#selectAllCages').on('click', () => { cageSelect.find('option').prop('selected', true); cageSelect.trigger('change'); });
        $('#clearCages').on('click', () => { cageSelect.val(null).trigger('change'); });
    </script>
    <?php include 'footer.php'; ?>
</body>
</html>
