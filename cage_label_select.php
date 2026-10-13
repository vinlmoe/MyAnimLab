<?php

session_start();
require 'dbcon.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$requestedType = $_GET['type'] ?? '';
$types = ['holding', 'breeding'];
$where = in_array($requestedType, $types, true) ? " WHERE cage_type = '" . $requestedType . "'" : '';
$query = "SELECT cage_id, cage_type FROM (
            SELECT h.cage_id, 'holding' AS cage_type FROM holding h
            INNER JOIN cages c ON c.cage_id = h.cage_id AND c.status = 'active'
            UNION ALL
            SELECT b.cage_id, 'breeding' AS cage_type FROM breeding b
            INNER JOIN cages c ON c.cage_id = b.cage_id AND c.status = 'active'
          ) available_cages" . $where . ' ORDER BY cage_id';
$result = $con->query($query);
$cages = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

require 'header.php';
?>
<!doctype html>
<html lang="fr">
<head>
    <title>Imprimer des étiquettes de cages</title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        .label-select-container { max-width: 800px; margin: 50px auto; padding: 24px; background: #f8f9fa; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,.1); }
    </style>
</head>
<body>
    <main class="content label-select-container">
        <h4>Imprimer des étiquettes de cages</h4>
        <p class="text-muted">Sélectionnez une ou plusieurs cages. Chaque étiquette occupe un quart de feuille A4 en portrait.</p>
        <form method="GET" action="cage_labels.php" target="_blank">
            <label for="cageIds" class="form-label">Cages à imprimer</label>
            <select id="cageIds" name="id[]" class="form-select" multiple required>
                <?php foreach ($cages as $cage) : ?>
                    <option value="<?= htmlspecialchars($cage['cage_id']) ?>">
                        <?= htmlspecialchars($cage['cage_id']) ?> — <?= $cage['cage_type'] === 'breeding' ? 'reproduction' : 'hébergement' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="fas fa-print"></i> Ouvrir les étiquettes</button>
                <button type="button" class="btn btn-secondary" onclick="history.back()">Retour</button>
            </div>
        </form>
    </main>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>$('#cageIds').select2({ placeholder: 'Rechercher et sélectionner des cages', width: '100%' });</script>
    <?php include 'footer.php'; ?>
</body>
</html>
