<?php

/** Printable A6 cage labels: four labels on one portrait A4 sheet. */
session_start();
require 'dbcon.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$rawIds = $_GET['id'] ?? [];
if (!is_array($rawIds)) {
    $rawIds = explode(',', (string) $rawIds);
}
$ids = [];
foreach ($rawIds as $rawId) {
    foreach (explode(',', (string) $rawId) as $id) {
        $id = trim($id);
        if ($id !== '' && strlen($id) <= 255 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
}
if (!$ids) {
    http_response_code(400);
    exit('Aucune cage sélectionnée.');
}

$settingsResult = $con->query("SELECT value FROM settings WHERE name = 'url' LIMIT 1");
$settingsRow = $settingsResult ? $settingsResult->fetch_assoc() : null;
$baseUrl = trim((string) ($settingsRow['value'] ?? ''));
if ($baseUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
} elseif (!preg_match('#^https?://#i', $baseUrl)) {
    $baseUrl = 'https://' . $baseUrl;
}
$baseUrl = rtrim($baseUrl, '/');

$labelData = [];
foreach ($ids as $id) {
    $cageSql = "SELECT c.cage_id, c.room, c.rack, c.remarks, pi.name AS pi_name,
                       h.cage_id AS holding_id, h.sex, h.strain, s.str_name,
                       b.cage_id AS breeding_id, b.`cross`
                FROM cages c
                LEFT JOIN users pi ON pi.id = c.pi_name
                LEFT JOIN holding h ON h.cage_id = c.cage_id
                LEFT JOIN strains s ON s.str_id = h.strain
                LEFT JOIN breeding b ON b.cage_id = c.cage_id
                WHERE c.cage_id = ? AND c.status = 'active'";
    $cageStmt = $con->prepare($cageSql);
    $cageStmt->bind_param('s', $id);
    $cageStmt->execute();
    $cage = $cageStmt->get_result()->fetch_assoc();
    $cageStmt->close();
    if (!$cage || (!$cage['holding_id'] && !$cage['breeding_id'])) {
        continue;
    }

    $animalsStmt = $con->prepare("SELECT m.id, m.animal_uid, m.mouse_id, m.sex, al.code AS line_code
                                  FROM mice m
                                  LEFT JOIN animal_lines al ON al.id = m.line_id
                                  WHERE m.cage_id = ? AND m.status = 'active'
                                  ORDER BY COALESCE(NULLIF(m.animal_uid, ''), NULLIF(m.mouse_id, ''), CAST(m.id AS CHAR)), m.id");
    $animalsStmt->bind_param('s', $id);
    $animalsStmt->execute();
    $animals = $animalsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $animalsStmt->close();

    $experimentsStmt = $con->prepare("SELECT DISTINCT e.experiment_number, e.title
                                      FROM experiment_animals ea
                                      INNER JOIN mice m ON m.id = ea.animal_id
                                      INNER JOIN experiments e ON e.id = ea.experiment_id
                                      WHERE m.cage_id = ? AND m.status = 'active'
                                        AND ea.removed_at IS NULL AND e.status IN ('planned', 'active')
                                      ORDER BY e.experiment_number");
    $experimentsStmt->bind_param('s', $id);
    $experimentsStmt->execute();
    $experiments = $experimentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $experimentsStmt->close();

    $cage['animals'] = $animals;
    $cage['experiments'] = $experiments;
    $cage['type_label'] = $cage['breeding_id'] ? 'Reproduction' : 'Hébergement';
    $cage['target'] = $baseUrl . '/cage_scan_route.php?id=' . rawurlencode($id);
    $labelData[] = $cage;
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Étiquettes de cages</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; color: #111; background: #eee; }
        .print-toolbar { max-width: 210mm; margin: 12px auto; text-align: center; }
        .sheet { width: 210mm; height: 297mm; margin: 0 auto 12px; background: white; display: grid; grid-template-columns: repeat(2, 105mm); grid-template-rows: repeat(2, 148.5mm); page-break-after: always; }
        .label { width: 105mm; height: 148.5mm; border: .25mm dashed #888; padding: 4mm; overflow: hidden; display: flex; flex-direction: column; gap: 2mm; }
        .label-header { display: grid; grid-template-columns: 1fr 27mm; gap: 2mm; min-height: 27mm; }
        h1 { font-size: 15pt; margin: 0; line-height: 1.15; }
        .cage-type { font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: .3pt; }
        .location { font-size: 8pt; margin-top: 1mm; }
        .qr { width: 26mm; height: 26mm; }
        .summary { display: grid; grid-template-columns: 1fr 1fr; border: .25mm solid #111; font-size: 7.5pt; }
        .summary div { padding: 1.2mm; border-right: .25mm solid #111; border-bottom: .25mm solid #111; min-height: 7mm; }
        .summary div:nth-child(2n) { border-right: 0; }
        .summary div:nth-last-child(-n+2) { border-bottom: 0; }
        .summary strong, .section-title { text-transform: uppercase; font-size: 6.5pt; letter-spacing: .2pt; }
        .experiments { border: .25mm solid #111; padding: 1.4mm; font-size: 6.5pt; line-height: 1.25; }
        .animals-wrap { border: .25mm solid #111; padding: 1.4mm; flex: 1; min-height: 0; overflow: hidden; }
        .animals { margin: 1mm 0 0; padding: 0; list-style: none; columns: 2; column-gap: 2mm; line-height: 1.22; }
        .animals.many { columns: 3; }
        .animals li { break-inside: avoid; white-space: nowrap; }
        .animal-sex { font-weight: bold; }
        .empty { font-size: 7pt; font-style: italic; margin-top: 2mm; }
        @media print {
            body { background: white; }
            .print-toolbar { display: none; }
            .sheet { margin: 0; break-after: page; }
            .sheet:last-child { break-after: auto; }
        }
    </style>
</head>
<body>
    <div class="print-toolbar"><button type="button" onclick="window.print()">Imprimer les <?= count($labelData) ?> étiquette(s)</button></div>
    <?php foreach (array_chunk($labelData, 4) as $sheet) : ?>
        <section class="sheet">
            <?php foreach ($sheet as $cage) :
                $animalCount = count($cage['animals']);
                $animalFontSize = max(3.8, min(8.0, 260 / max(1, $animalCount)));
            ?>
                <article class="label">
                    <div class="label-header">
                        <div>
                            <div class="cage-type"><?= htmlspecialchars($cage['type_label']) ?></div>
                            <h1>Cage <?= htmlspecialchars($cage['cage_id']) ?></h1>
                            <div class="location">Salle <?= htmlspecialchars($cage['room'] ?: '—') ?> · Rack <?= htmlspecialchars($cage['rack'] ?: '—') ?></div>
                        </div>
                        <img class="qr" alt="QR code de la cage" src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&amp;data=<?= rawurlencode($cage['target']) ?>&amp;choe=UTF-8">
                    </div>
                    <div class="summary">
                        <div><strong>Animaux</strong><br><?= $animalCount ?></div>
                        <div><strong>Sexe</strong><br><?= htmlspecialchars($cage['sex'] ?: 'Mixte / N.A.') ?></div>
                        <div><strong>Lignée / souche</strong><br><?= htmlspecialchars($cage['str_name'] ?: $cage['strain'] ?: $cage['cross'] ?: '—') ?></div>
                        <div><strong>Responsable</strong><br><?= htmlspecialchars($cage['pi_name'] ?: '—') ?></div>
                    </div>
                    <?php if ($cage['experiments']) : ?>
                        <div class="experiments"><span class="section-title">Expérimentation en cours</span><br>
                            <?php foreach ($cage['experiments'] as $experiment) : ?>
                                <?= htmlspecialchars($experiment['experiment_number'] . ' — ' . $experiment['title']) ?><br>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="animals-wrap">
                        <div class="section-title">Identité des animaux contenus</div>
                        <?php if ($cage['animals']) : ?>
                            <ul class="animals <?= $animalCount > 24 ? 'many' : '' ?>" style="font-size: <?= $animalFontSize ?>pt">
                                <?php foreach ($cage['animals'] as $animal) :
                                    $animalIdentity = $animal['animal_uid'] ?: $animal['mouse_id'] ?: ('#' . $animal['id']);
                                ?>
                                    <li><?= htmlspecialchars($animalIdentity) ?> <span class="animal-sex">(<?= $animal['sex'] === 'male' ? 'M' : ($animal['sex'] === 'female' ? 'F' : '—') ?>)</span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else : ?>
                            <div class="empty">Aucun animal actif dans cette cage.</div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</body>
</html>
