<?php
/**
 * Annual animal use report (bilan annuel)
 *
 * Yearly summary of animal use, for the BEA annual review and the national
 * statistical survey: population movements, procedures ended during the year
 * by actual severity (retrospective assessment), use per project
 * authorization, exits, and animals killed without being used in a procedure.
 * Every table can be downloaded as CSV (semicolon separated, UTF-8 with BOM so
 * it opens directly in Excel), or all of them at once as a ZIP archive.
 * A procedure is counted in the year it ends (experiment_animals.removed_at).
 * Restricted to administrators and BEA responsibles.
 */
require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
$allowed = ($_SESSION['role'] ?? '') === 'admin';
if (!$allowed && !empty($_SESSION['user_id'])) {
    $beaStmt = $con->prepare('SELECT is_bea_responsible FROM users WHERE id = ?');
    $beaStmt->bind_param('i', $_SESSION['user_id']);
    $beaStmt->execute();
    $allowed = !empty($beaStmt->get_result()->fetch_assoc()['is_bea_responsible']);
    $beaStmt->close();
}
if (!$allowed) {
    http_response_code(403);
    exit('Accès réservé aux administrateurs et responsables BEA.');
}

$currentYear = (int) date('Y');
$year = (int) ($_GET['year'] ?? $currentYear - 1);
if ($year < 1990 || $year > $currentYear) {
    $year = $currentYear - 1;
}
$yearStart = sprintf('%04d-01-01', $year);
$yearEnd = sprintf('%04d-01-01', $year + 1);

$severities = ['non_recovery' => 'Sans réveil', 'mild' => 'Légère', 'moderate' => 'Modérée', 'severe' => 'Sévère', '' => 'Non évaluée'];
$unknown = 'Non renseignée';

// --- Data -------------------------------------------------------------------

// Every animal with its species, line and entry date (creation event, or birth
// date for animals imported without one).
$animals = $con->query("SELECT m.id, m.animal_uid, m.sex, m.dob, m.genotype, m.status, m.exit_code, m.exit_date,
        l.species, l.code AS line_code, l.name AS line_name,
        COALESCE((SELECT DATE(MIN(ae.created_at)) FROM animal_events ae WHERE ae.animal_id = m.id AND ae.event_type = 'created'), m.dob) AS entry_date,
        (SELECT COUNT(*) FROM experiment_animals ea WHERE ea.animal_id = m.id) AS use_count
    FROM mice m LEFT JOIN animal_lines l ON l.id = m.line_id
    ORDER BY l.species, l.code, m.animal_uid")->fetch_all(MYSQLI_ASSOC);

// Present on a given day: entered before it and not yet exited. An animal that
// left without an exit date (legacy data) is not counted as present.
$presentOn = function (array $a, string $day): bool {
    if ($a['entry_date'] !== null && $a['entry_date'] >= $day) {
        return false;
    }
    if ($a['exit_date'] === null) {
        return $a['status'] === 'active';
    }
    return $a['exit_date'] >= $day;
};
$inYear = fn(?string $date): bool => $date !== null && $date >= $yearStart && $date < $yearEnd;

// Experimental uses (procedures) ended during the year.
$usesStmt = $con->prepare("SELECT ea.id, ea.enrolled_at, ea.removed_at, ea.removal_reason,
        m.id AS animal_id, m.animal_uid, m.sex, m.dob, m.genotype, m.status, m.exit_code, m.exit_date,
        l.species, l.code AS line_code, l.name AS line_name,
        e.experiment_number, e.title AS experiment_title, p.protocol_number, t.trial_number,
        t.iacuc_id, i.iacuc_title, r.severity_classification, r.assessed_by_name
    FROM experiment_animals ea
    JOIN mice m ON m.id = ea.animal_id
    LEFT JOIN animal_lines l ON l.id = m.line_id
    JOIN experiments e ON e.id = ea.experiment_id
    JOIN protocols p ON p.id = e.protocol_id
    JOIN trials t ON t.id = p.trial_id
    LEFT JOIN iacuc i ON i.iacuc_id = t.iacuc_id
    LEFT JOIN experiment_retrospective_assessments r ON r.experiment_animal_id = ea.id
    WHERE ea.removed_at >= ? AND ea.removed_at < ?
    ORDER BY t.iacuc_id, e.experiment_number, m.animal_uid");
$usesStmt->bind_param('ss', $yearStart, $yearEnd);
$usesStmt->execute();
$uses = $usesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$usesStmt->close();

// Procedures still running on 31 December (reported the year they end).
$ongoingStmt = $con->prepare('SELECT COUNT(*) FROM experiment_animals WHERE enrolled_at < ? AND (removed_at IS NULL OR removed_at >= ?)');
$ongoingStmt->bind_param('ss', $yearEnd, $yearEnd);
$ongoingStmt->execute();
$ongoingCount = (int) $ongoingStmt->get_result()->fetch_row()[0];
$ongoingStmt->close();

// --- Tables -----------------------------------------------------------------

$species = fn(array $r): string => $r['species'] ?: $unknown;
$line = fn(array $r): string => $r['line_code'] ? $r['line_code'] . ' — ' . $r['line_name'] : $unknown;
$sexLabel = fn(?string $s): string => ['male' => 'Mâle', 'female' => 'Femelle'][$s] ?? $unknown;
$exitLabel = fn(array $r): string => exit_label($r['exit_code'], $r['status']);
$severityLabel = fn(array $r): string => $severities[$r['severity_classification'] ?? ''];
$fmtDate = fn(?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '';

$tables = [];

// 1. Population summary per species.
$summary = [];
foreach ($animals as $a) {
    $s = $species($a);
    $summary[$s] ??= ['present_start' => 0, 'entries' => 0, 'exits' => 0, 'present_end' => 0];
    $summary[$s]['present_start'] += $presentOn($a, $yearStart) ? 1 : 0;
    $summary[$s]['entries'] += $inYear($a['entry_date']) ? 1 : 0;
    $summary[$s]['exits'] += $inYear($a['exit_date']) ? 1 : 0;
    $summary[$s]['present_end'] += $presentOn($a, $yearEnd) ? 1 : 0;
}
$usedAnimals = [];
foreach ($uses as $u) {
    $usedAnimals[$species($u)][$u['animal_id']] = true;
}
$rows = [];
foreach ($summary as $s => $c) {
    if (!array_filter($c) && empty($usedAnimals[$s])) {
        continue; // species not present during the year
    }
    $usesOfSpecies = count(array_filter($uses, fn($u) => $species($u) === $s));
    $rows[] = [$s, $c['present_start'], $c['entries'], $c['exits'], $c['present_end'], count($usedAnimals[$s] ?? []), $usesOfSpecies];
}
$tables['synthese'] = [
    'title' => 'Synthèse des effectifs par espèce',
    'headers' => ['Espèce', 'Présents au 01/01', 'Entrées', 'Sorties', 'Présents au 31/12', 'Animaux utilisés (procédures terminées)', 'Utilisations'],
    'rows' => $rows,
];

// 2. Procedures ended in the year by species, line and actual severity.
$bySeverity = [];
foreach ($uses as $u) {
    $key = $species($u) . "\0" . $line($u);
    $bySeverity[$key] ??= array_fill_keys(array_keys($severities), 0);
    $bySeverity[$key][$u['severity_classification'] ?? '']++;
}
$rows = [];
foreach ($bySeverity as $key => $counts) {
    $rows[] = array_merge(explode("\0", $key), array_values($counts), [array_sum($counts)]);
}
$tables['severite'] = [
    'title' => 'Utilisations terminées par espèce, lignée et sévérité réelle',
    'headers' => array_merge(['Espèce', 'Lignée'], array_values($severities), ['Total']),
    'rows' => $rows,
];

// 3. Use per project authorization.
$byProject = [];
foreach ($uses as $u) {
    $key = ($u['iacuc_id'] ?: 'Sans autorisation') . "\0" . ($u['iacuc_title'] ?? '') . "\0" . $species($u);
    $byProject[$key] ??= ['animals' => [], 'counts' => array_fill_keys(array_keys($severities), 0)];
    $byProject[$key]['animals'][$u['animal_id']] = true;
    $byProject[$key]['counts'][$u['severity_classification'] ?? '']++;
}
$rows = [];
foreach ($byProject as $key => $p) {
    $rows[] = array_merge(explode("\0", $key), [count($p['animals'])], array_values($p['counts']), [array_sum($p['counts'])]);
}
$tables['projets'] = [
    'title' => 'Utilisation par autorisation de projet',
    'headers' => array_merge(['Numéro éthique', 'Intitulé', 'Espèce', 'Animaux'], array_values($severities), ['Utilisations']),
    'rows' => $rows,
];

// 4. Exits in the year by species and exit reason.
$byExit = [];
foreach ($animals as $a) {
    if ($inYear($a['exit_date'])) {
        $key = $species($a) . "\0" . $exitLabel($a);
        $byExit[$key] = ($byExit[$key] ?? 0) + 1;
    }
}
$rows = [];
foreach ($byExit as $key => $n) {
    $rows[] = array_merge(explode("\0", $key), [$n]);
}
$tables['sorties'] = [
    'title' => 'Sorties par espèce et motif',
    'headers' => ['Espèce', 'Motif de sortie', 'Nombre'],
    'rows' => $rows,
];

// 5. Animals that left during the year without ever entering a procedure
// (bred, killed and not used).
$notUsed = [];
foreach ($animals as $a) {
    if ($inYear($a['exit_date']) && (int) $a['use_count'] === 0) {
        $key = $species($a) . "\0" . $line($a) . "\0" . $exitLabel($a);
        $notUsed[$key] = ($notUsed[$key] ?? 0) + 1;
    }
}
$rows = [];
foreach ($notUsed as $key => $n) {
    $rows[] = array_merge(explode("\0", $key), [$n]);
}
$tables['non_utilises'] = [
    'title' => 'Animaux sortis sans avoir été utilisés en procédure',
    'headers' => ['Espèce', 'Lignée', 'Motif de sortie', 'Nombre'],
    'rows' => $rows,
];

// 6. Detail of every procedure ended in the year (CSV only).
$tables['detail_utilisations'] = [
    'title' => 'Détail des utilisations terminées',
    'detail' => true,
    'headers' => ['Animal', 'Espèce', 'Lignée', 'Sexe', 'Naissance', 'Génotype', 'Numéro éthique', 'Essai', 'Protocole', 'Expérience', 'Intitulé expérience', 'Début', 'Fin', 'Motif de fin', 'Sévérité réelle', 'Évaluée par', 'Devenir'],
    'rows' => array_map(fn($u) => [
        $u['animal_uid'], $species($u), $line($u), $sexLabel($u['sex']), $fmtDate($u['dob']), $u['genotype'] ?? '',
        $u['iacuc_id'] ?? '', $u['trial_number'], $u['protocol_number'], $u['experiment_number'], $u['experiment_title'],
        $fmtDate($u['enrolled_at']), $fmtDate($u['removed_at']), $u['removal_reason'] ?? '', $severityLabel($u), $u['assessed_by_name'] ?? '',
        $u['status'] === 'active' ? 'Vivant' : $exitLabel($u) . ($u['exit_date'] ? ' (' . $fmtDate($u['exit_date']) . ')' : ''),
    ], $uses),
];

// 7. Detail of every animal present at some point during the year (CSV only).
$rows = [];
foreach ($animals as $a) {
    if ($presentOn($a, $yearStart) || $inYear($a['entry_date']) || $inYear($a['exit_date'])) {
        $rows[] = [
            $a['animal_uid'], $species($a), $line($a), $sexLabel($a['sex']), $fmtDate($a['dob']), $a['genotype'] ?? '',
            $fmtDate($a['entry_date']), $fmtDate($a['exit_date']), $inYear($a['exit_date']) ? $exitLabel($a) : '',
            (int) $a['use_count'],
        ];
    }
}
$tables['detail_animaux'] = [
    'title' => 'Détail des animaux présents dans l\'année',
    'detail' => true,
    'headers' => ['Animal', 'Espèce', 'Lignée', 'Sexe', 'Naissance', 'Génotype', 'Entrée', 'Sortie', 'Motif de sortie', 'Nombre d\'utilisations (total)'],
    'rows' => $rows,
];

// --- Exports ----------------------------------------------------------------

function annual_csv(array $table): string
{
    $out = fopen('php://temp', 'r+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $table['headers'], ';');
    foreach ($table['rows'] as $row) {
        fputcsv($out, $row, ';');
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv;
}

$export = $_GET['export'] ?? '';
if ($export === 'zip') {
    $zipPath = tempnam(sys_get_temp_dir(), 'bilan');
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    foreach ($tables as $key => $table) {
        $zip->addFromString("bilan_{$year}_{$key}.csv", annual_csv($table));
    }
    $zip->close();
    header('Content-Type: application/zip');
    header("Content-Disposition: attachment; filename=\"bilan_annuel_{$year}.zip\"");
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    unlink($zipPath);
    exit;
}
if (isset($tables[$export])) {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"bilan_{$year}_{$export}.csv\"");
    echo annual_csv($tables[$export]);
    exit;
}

require 'header.php';
?>
<!doctype html>
<html lang="fr">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bilan annuel <?= $year ?> | <?= htmlspecialchars($labName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .report { max-width: 1200px; }
        @media print { body>*:not(.report) { display: none !important; } .no-print { display: none !important; } }
    </style>
</head>

<body>
    <main class="container mt-4 content report">
        <div class="d-flex flex-wrap align-items-end gap-2 mb-3 no-print">
            <form method="get" class="d-flex align-items-end gap-2">
                <div>
                    <label for="year" class="form-label mb-0">Année</label>
                    <input type="number" class="form-control" id="year" name="year" min="1990" max="<?= $currentYear ?>" value="<?= $year ?>" style="width:7rem">
                </div>
                <button type="submit" class="btn btn-primary">Afficher</button>
            </form>
            <a class="btn btn-success" href="?year=<?= $year ?>&amp;export=zip"><i class="fas fa-file-archive"></i> Tout exporter (ZIP)</a>
            <button class="btn btn-outline-dark" onclick="window.print()"><i class="fas fa-print"></i> Imprimer / PDF</button>
        </div>

        <h4>Bilan annuel d'utilisation des animaux — <?= $year ?></h4>
        <p class="text-muted">Du 01/01/<?= $year ?> au 31/12/<?= $year ?> · édité le <?= date('d/m/Y H:i') ?>.
            Une procédure est comptée l'année où elle se termine ; la sévérité est celle de l'analyse rétrospective BEA.</p>
        <?php if ($ongoingCount > 0) { ?>
            <div class="alert alert-info"><?= $ongoingCount ?> utilisation(s) encore en cours au 31/12/<?= $year ?> : elles seront comptées l'année de leur fin.</div>
        <?php } ?>
        <?php $unassessed = count(array_filter($uses, fn($u) => $u['severity_classification'] === null)); ?>
        <?php if ($unassessed > 0) { ?>
            <div class="alert alert-warning no-print"><?= $unassessed ?> utilisation(s) terminée(s) sans sévérité réelle :
                <a href="experiment_retrospective.php">compléter l'analyse rétrospective</a>.</div>
        <?php } ?>

        <?php foreach ($tables as $key => $table) { ?>
            <div class="d-flex justify-content-between align-items-center mt-4">
                <h5 class="mb-0"><?= htmlspecialchars($table['title']) ?></h5>
                <a class="btn btn-sm btn-outline-secondary no-print" href="?year=<?= $year ?>&amp;export=<?= $key ?>"><i class="fas fa-file-csv"></i> CSV</a>
            </div>
            <?php if (!empty($table['detail'])) { ?>
                <p class="text-muted mb-0"><?= count($table['rows']) ?> ligne(s) — détail disponible en CSV.</p>
            <?php } else { ?>
                <div class="table-responsive mt-2">
                    <table class="table table-bordered table-sm align-middle">
                        <thead class="table-light">
                            <tr><?php foreach ($table['headers'] as $h) { ?><th><?= htmlspecialchars($h) ?></th><?php } ?></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($table['rows'] as $row) { ?>
                                <tr><?php foreach ($row as $cell) { ?><td><?= htmlspecialchars((string) $cell) ?></td><?php } ?></tr>
                            <?php } ?>
                            <?php if (!$table['rows']) { ?>
                                <tr><td colspan="<?= count($table['headers']) ?>" class="text-muted">Aucune donnée pour <?= $year ?>.</td></tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>
        <?php } ?>
    </main>
    <?php require 'footer.php'; ?>
</body>

</html>
