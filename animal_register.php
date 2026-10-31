<?php

/**
 * Entry / exit register of the animals (registre des entrées et sorties):
 * every entry into the facility with its date and origin (born here,
 * supplier, other establishment...) and every exit with its date, code,
 * reason and destination, in chronological order over a period, with the
 * number of animals present at the start and at the end. Exportable as CSV
 * and PDF; every export is recorded in the activity log. Animals whose
 * origin is still to be completed are listed separately.
 */
require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require_once 'corrections_lib.php';
require 'pdf_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$validDate = fn($d): bool => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$from = $validDate($_GET['from'] ?? null) ? $_GET['from'] : date('Y-01-01');
$to = $validDate($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d');
$error = null;
if ($from > $to) {
    $error = tr('The start of the period is after its end.');
    [$from, $to] = [$to, $from];
}
$movement = in_array($_GET['movement'] ?? '', ['entries', 'exits'], true) ? $_GET['movement'] : 'all';
$species = trim((string) ($_GET['species'] ?? ''));
$lineId = (int) ($_GET['line_id'] ?? 0);
$format = $_GET['format'] ?? '';

// Line / species filter shared by every query (alias m for mice, al for lines).
$filterSql = '';
$filterTypes = '';
$filterParams = [];
if ($lineId) {
    $filterSql .= ' AND m.line_id = ?';
    $filterTypes .= 'i';
    $filterParams[] = $lineId;
}
if ($species !== '') {
    $filterSql .= ' AND al.species = ?';
    $filterTypes .= 's';
    $filterParams[] = $species;
}
$query = function (string $sql, string $types = '', array $params = []) use ($con, $filterTypes, $filterParams): array {
    $stmt = $con->prepare($sql);
    $types .= $filterTypes;
    $params = array_merge($params, $filterParams);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
};
$base = "SELECT m.id, m.animal_uid, m.mouse_id, m.sex, m.dob, m.cage_id, m.status, m.entry_date, m.origin_type, m.origin_detail, m.origin_reference,
                m.exit_date, m.exit_code, m.exit_reason, m.exit_destination, al.code AS line_code, al.name AS line_name, al.species
         FROM mice m LEFT JOIN animal_lines al ON al.id = m.line_id";
$exited = "m.status <> 'active'";

// Register lines of the period, oldest first, entries before exits on a day.
$lines = [];
if ($movement !== 'exits') {
    foreach ($query("$base WHERE m.entry_date BETWEEN ? AND ? $filterSql", 'ss', [$from, $to]) as $a) {
        $lines[] = ['date' => $a['entry_date'], 'kind' => 'entry'] + $a;
    }
}
if ($movement !== 'entries') {
    foreach ($query("$base WHERE $exited AND m.exit_date BETWEEN ? AND ? $filterSql", 'ss', [$from, $to]) as $a) {
        $lines[] = ['date' => $a['exit_date'], 'kind' => 'exit'] + $a;
    }
}
$correctedAnimals = animal_correction_counts($con, array_column($lines, 'id'));
usort($lines, fn($x, $y) => [$x['date'], $x['kind'] === 'exit', $x['animal_uid'] ?? '', $x['id']] <=> [$y['date'], $y['kind'] === 'exit', $y['animal_uid'] ?? '', $y['id']]);

// Animals present at the start and at the end of the period.
$count = fn(string $sql, string $types, array $params): int => (int) ($query("SELECT COUNT(*) AS n FROM mice m LEFT JOIN animal_lines al ON al.id = m.line_id WHERE $sql $filterSql", $types, $params)[0]['n'] ?? 0);
$presentAtStart = $count("m.entry_date < ? AND NOT ($exited AND m.exit_date < ?)", 'ss', [$from, $from]);
$presentAtEnd = $count("m.entry_date <= ? AND NOT ($exited AND m.exit_date <= ?)", 'ss', [$to, $to]);
$entriesCount = $count('m.entry_date BETWEEN ? AND ?', 'ss', [$from, $to]);
$exitsCount = $count("$exited AND m.exit_date BETWEEN ? AND ?", 'ss', [$from, $to]);
$byOrigin = $query("SELECT m.origin_type, COUNT(*) AS n FROM mice m LEFT JOIN animal_lines al ON al.id = m.line_id WHERE m.entry_date BETWEEN ? AND ? $filterSql GROUP BY m.origin_type", 'ss', [$from, $to]);
$byExit = $query("SELECT m.exit_code, m.status, COUNT(*) AS n FROM mice m LEFT JOIN animal_lines al ON al.id = m.line_id WHERE $exited AND m.exit_date BETWEEN ? AND ? $filterSql GROUP BY m.exit_code, m.status", 'ss', [$from, $to]);
$toComplete = $query("$base WHERE (m.origin_type IS NULL OR m.entry_date IS NULL OR ($exited AND m.exit_date IS NULL)) $filterSql ORDER BY m.entry_date IS NULL DESC, m.entry_date, m.id LIMIT 500");

// Text of the register columns for one line.
$origins = animal_origins();
$labelOf = fn(array $a): string => $a['animal_uid'] ?: ($a['mouse_id'] ?: '#' . $a['id']);
$lineOf = fn(array $a): string => trim(($a['line_code'] ?? '') . ' ' . ($a['line_name'] ?? ''));
$sexOf = fn(?string $s): string => $s === 'male' ? tr('M') : ($s === 'female' ? tr('F') : '—');
$provenance = function (array $l) use ($origins): string {
    if ($l['kind'] === 'entry') {
        return $l['origin_type'] ? $origins[$l['origin_type']] . ($l['origin_detail'] ? ' — ' . $l['origin_detail'] : '') : tr('Origin to complete');
    }
    return $l['exit_destination'] ?: '—';
};
$reason = function (array $l): string {
    if ($l['kind'] === 'entry') {
        return (string) ($l['origin_reference'] ?? '');
    }
    return exit_label($l['exit_code'], $l['status']) . ($l['exit_reason'] ? ' — ' . $l['exit_reason'] : '');
};
$kindLabel = fn(array $l): string => $l['kind'] === 'entry' ? tr('Entry') : tr('Exit');
$headers = ['No.', 'Date', 'Movement', 'Animal', 'Species', 'Line', 'Sex', 'Date of birth', 'Origin / destination', 'Reference / reason', 'Cage'];
$registerRows = [];
foreach ($lines as $i => $l) {
    $registerRows[] = [$i + 1, $l['date'], $kindLabel($l), $labelOf($l), $l['species'] ?? '', $lineOf($l), $sexOf($l['sex']), $l['dob'] ?? '', $provenance($l), $reason($l), $l['cage_id'] ?? ''];
}
$periodText = tr('Period: {from} to {to}', ['from' => $from, 'to' => $to])
    . ($movement !== 'all' ? ' · ' . ($movement === 'entries' ? tr('entries only') : tr('exits only')) : '')
    . ($species !== '' ? ' · ' . $species : '')
    . ($lineId ? ' · ' . tr('line #{id}', ['id' => $lineId]) : '');

if ($format === 'csv' || $format === 'pdf') {
    // The header (which loads the lab name) is not included for a download.
    $labName = $con->query("SELECT value FROM settings WHERE name = 'lab_name'")->fetch_row()[0] ?? 'MyAnimLab';
    log_activity($con, 'export', 'animal_register', null, strtoupper($format) . " $from → $to ($movement" . ($species !== '' ? ", $species" : '') . ($lineId ? ", line $lineId" : '') . ')', $_SESSION['user_id'] ?? null);
    $filename = 'registre_entrees_sorties_' . $from . '_' . $to . '.' . $format;
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map('tr', $headers), ';');
        foreach ($registerRows as $row) {
            fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $row), ';');
        }
        fclose($out);
        exit;
    }
    $pdf = new SimplePdf(tr('Entry / exit register'), $labName . ' — ' . tr('printed on {date} by {user}', ['date' => date('Y-m-d H:i'), 'user' => $_SESSION['name'] ?? $_SESSION['username']]));
    $pdf->heading(tr('Entry / exit register of the animals'), [$labName, $periodText]);
    $pdf->keyValues([
        [tr('Present on {date}', ['date' => $from]), $presentAtStart],
        [tr('Entries'), $entriesCount],
        [tr('Exits'), $exitsCount],
        [tr('Present on {date}', ['date' => $to]), $presentAtEnd],
    ]);
    $widths = [3, 6, 6, 7, 6, 10, 3, 6, 18, 21, 6];
    $columns = [];
    foreach ($headers as $i => $h) {
        $columns[] = ['label' => tr($h), 'width' => $widths[$i], 'align' => in_array($i, [0, 6], true) ? 'C' : 'L'];
    }
    $pdfRows = [];
    foreach ($registerRows as $i => $row) {
        $row['fill'] = $lines[$i]['kind'] === 'exit' ? '0.94' : null;
        $pdfRows[] = $row;
    }
    $pdf->table($columns, $pdfRows);
    $pdf->output($filename);
    exit;
}

$speciesList = array_column($con->query("SELECT DISTINCT species FROM animal_lines WHERE species <> '' ORDER BY species")->fetch_all(MYSQLI_ASSOC), 'species');
$lineList = $con->query("SELECT id, code, name FROM animal_lines ORDER BY code")->fetch_all(MYSQLI_ASSOC);
$params = array_filter(['from' => $from, 'to' => $to, 'movement' => $movement !== 'all' ? $movement : null, 'species' => $species ?: null, 'line_id' => $lineId ?: null]);
$exportUrl = fn(string $f): string => 'animal_register.php?' . http_build_query($params + ['format' => $f]);

// On-screen pagination of the register table only: the CSV / PDF exports and
// the summary counts above cover the whole period. Keeps the page from
// building a multi-megabyte table when the period holds thousands of rows.
$perPage = 500;
$totalRows = count($registerRows);
$pageCount = max(1, (int) ceil($totalRows / $perPage));
$page = min($pageCount, max(1, (int) ($_GET['page'] ?? 1)));
$pageStart = ($page - 1) * $perPage;
$pageLines = array_slice($lines, $pageStart, $perPage, true);
$pageUrl = fn(int $p): string => 'animal_register.php?' . http_build_query($params + ['page' => $p]);

require 'header.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Entry / exit register') ?> | <?= htmlspecialchars($labName) ?></title>
</head>

<body>
    <main class="container-fluid mt-4 content" style="max-width: 1400px">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <h4 class="mb-1"><i class="fas fa-book"></i> <?= trh('Entry / exit register of the animals') ?></h4>
                <p class="text-muted mb-2"><?= trh('Every entry into the facility (date and origin) and every exit (date, reason, destination), in chronological order.') ?></p>
            </div>
            <div class="text-nowrap">
                <a class="btn btn-outline-success" href="<?= htmlspecialchars($exportUrl('csv')) ?>"><i class="fas fa-file-csv"></i> CSV</a>
                <a class="btn btn-outline-danger" href="<?= htmlspecialchars($exportUrl('pdf')) ?>"><i class="fas fa-file-pdf"></i> PDF</a>
            </div>
        </div>

        <?php include 'message.php'; ?>
        <?php if ($error) : ?><div class="alert alert-warning"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="GET" class="row g-2 align-items-end mb-3 p-2 border rounded bg-light" id="registerFilters">
            <div class="col-6 col-md-2"><label class="form-label small mb-0" for="from"><?= trh('From (date)') ?></label><input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-0" for="to"><?= trh('To (date)') ?></label><input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-0" for="movement"><?= trh('Movement') ?></label>
                <select class="form-select form-select-sm" id="movement" name="movement">
                    <option value="all"><?= trh('Entries and exits') ?></option>
                    <option value="entries" <?= $movement === 'entries' ? 'selected' : '' ?>><?= trh('Entries only') ?></option>
                    <option value="exits" <?= $movement === 'exits' ? 'selected' : '' ?>><?= trh('Exits only') ?></option>
                </select></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-0" for="species"><?= trh('Species') ?></label>
                <select class="form-select form-select-sm" id="species" name="species">
                    <option value=""><?= trh('All') ?></option>
                    <?php foreach ($speciesList as $sp) : ?><option value="<?= htmlspecialchars($sp) ?>" <?= $species === $sp ? 'selected' : '' ?>><?= htmlspecialchars($sp) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-0" for="line_id"><?= trh('Line') ?></label>
                <select class="form-select form-select-sm" id="line_id" name="line_id">
                    <option value=""><?= trh('All') ?></option>
                    <?php foreach ($lineList as $ln) : ?><option value="<?= (int) $ln['id'] ?>" <?= $lineId === (int) $ln['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ln['code'] . ' — ' . $ln['name']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-6 col-md-2 d-flex gap-1">
                <button class="btn btn-sm btn-primary flex-fill" type="submit"><i class="fas fa-filter"></i> <?= trh('Apply') ?></button>
                <a class="btn btn-sm btn-outline-secondary" href="animal_register.php"><?= trh('Reset') ?></a>
            </div>
        </form>

        <div class="row g-2 mb-3 text-center" id="registerSummary">
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted"><?= trh('Present on {date}', ['date' => $from]) ?></div><div class="fs-4 fw-bold"><?= $presentAtStart ?></div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded p-2 border-success"><div class="small text-muted"><?= trh('Entries') ?></div><div class="fs-4 fw-bold text-success">+<?= $entriesCount ?></div>
                <div class="small"><?php foreach ($byOrigin as $o) : ?><span class="me-2"><?= htmlspecialchars($o['origin_type'] ? $origins[$o['origin_type']] : tr('Origin to complete')) ?> : <?= (int) $o['n'] ?></span><?php endforeach; ?></div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded p-2 border-danger"><div class="small text-muted"><?= trh('Exits') ?></div><div class="fs-4 fw-bold text-danger">−<?= $exitsCount ?></div>
                <div class="small"><?php foreach ($byExit as $x) : ?><span class="me-2"><?= htmlspecialchars(exit_label($x['exit_code'], $x['status'])) ?> : <?= (int) $x['n'] ?></span><?php endforeach; ?></div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted"><?= trh('Present on {date}', ['date' => $to]) ?></div><div class="fs-4 fw-bold"><?= $presentAtEnd ?></div></div></div>
        </div>

        <?php if ($toComplete) : ?>
            <div class="alert alert-warning">
                <details>
                    <summary><i class="fas fa-exclamation-triangle"></i> <?= trh('{n} animal(s) with an incomplete register entry (origin, entry date or exit date missing)', ['n' => count($toComplete)]) ?></summary>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm table-bordered bg-white mb-0" id="toComplete">
                            <thead class="table-light"><tr><th><?= trh('Animal') ?></th><th><?= trh('Line') ?></th><th><?= trh('Date of birth') ?></th><th><?= trh('Entry date') ?></th><th><?= trh('Origin') ?></th><th><?= trh('Status') ?></th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($toComplete as $a) : ?>
                                    <tr>
                                        <td><?= htmlspecialchars($labelOf($a)) ?></td>
                                        <td><?= htmlspecialchars($lineOf($a)) ?></td>
                                        <td><?= htmlspecialchars($a['dob'] ?? '—') ?></td>
                                        <td><?= $a['entry_date'] ? htmlspecialchars($a['entry_date']) : '<span class="badge bg-warning text-dark">' . trh('missing') . '</span>' ?></td>
                                        <td><?= $a['origin_type'] ? htmlspecialchars($origins[$a['origin_type']]) : '<span class="badge bg-warning text-dark">' . trh('missing') . '</span>' ?></td>
                                        <td><?= htmlspecialchars(tr(str_replace('_', ' ', $a['status']))) ?><?= $a['status'] !== 'active' && !$a['exit_date'] ? ' <span class="badge bg-warning text-dark">' . trh('exit date missing') . '</span>' : '' ?></td>
                                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="animal_view.php?id=<?= (int) $a['id'] ?>#register"><?= trh('Complete') ?></a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        <?php endif; ?>

        <?php if ($totalRows > 0) : ?>
            <p class="small text-muted mb-1"><?= trh('Lines {start}–{end} of {total}', ['start' => $pageStart + 1, 'end' => min($pageStart + $perPage, $totalRows), 'total' => $totalRows]) ?><?php if ($pageCount > 1) : ?> · <?= trh('the CSV and PDF exports cover the whole period') ?><?php endif; ?></p>
        <?php endif; ?>
        <?php if ($pageCount > 1) : ?>
            <nav><ul class="pagination pagination-sm">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($pageUrl(max(1, $page - 1))) ?>">&laquo;</a></li>
                <li class="page-item disabled"><span class="page-link"><?= trh('Page {n} of {total}', ['n' => $page, 'total' => $pageCount]) ?></span></li>
                <li class="page-item <?= $page >= $pageCount ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($pageUrl(min($pageCount, $page + 1))) ?>">&raquo;</a></li>
            </ul></nav>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle" id="registerTable">
                <thead class="table-light">
                    <tr><?php foreach ($headers as $h) : ?><th><?= trh($h) ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <?php foreach ($pageLines as $i => $l) : ?>
                        <tr class="<?= $l['kind'] === 'exit' ? 'table-light' : '' ?>">
                            <td class="text-muted small"><?= $i + 1 ?></td>
                            <td class="text-nowrap"><?= htmlspecialchars($l['date']) ?></td>
                            <td><?= $l['kind'] === 'entry' ? '<span class="badge bg-success"><i class="fas fa-sign-in-alt"></i> ' . trh('Entry') . '</span>' : '<span class="badge bg-danger"><i class="fas fa-sign-out-alt"></i> ' . trh('Exit') . '</span>' ?></td>
                            <td class="text-nowrap"><a href="animal_view.php?id=<?= (int) $l['id'] ?>#register"><?= htmlspecialchars($labelOf($l)) ?></a><?php if (!empty($correctedAnimals[(int) $l['id']])) : ?> <span class="badge bg-warning text-dark" title="<?= trh('Corrected after recording: see the corrections on the animal\'s record') ?>"><?= trh('corrected') ?></span><?php endif; ?></td>
                            <td><?= htmlspecialchars($l['species'] ?? '') ?></td>
                            <td class="small"><?= htmlspecialchars($lineOf($l)) ?></td>
                            <td class="text-center"><?= htmlspecialchars($sexOf($l['sex'])) ?></td>
                            <td class="text-nowrap"><?= htmlspecialchars($l['dob'] ?? '') ?></td>
                            <td class="small"><?= $l['kind'] === 'entry' && !$l['origin_type'] ? '<span class="badge bg-warning text-dark">' . trh('Origin to complete') . '</span>' : htmlspecialchars($provenance($l)) ?></td>
                            <td class="small"><?= htmlspecialchars($reason($l)) ?></td>
                            <td class="text-nowrap"><?= htmlspecialchars($l['cage_id'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$lines) : ?><tr><td colspan="<?= count($headers) ?>" class="text-muted"><?= trh('No entry or exit over this period.') ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pageCount > 1) : ?>
            <nav><ul class="pagination pagination-sm">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($pageUrl(max(1, $page - 1))) ?>">&laquo;</a></li>
                <li class="page-item disabled"><span class="page-link"><?= trh('Page {n} of {total}', ['n' => $page, 'total' => $pageCount]) ?></span></li>
                <li class="page-item <?= $page >= $pageCount ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($pageUrl(min($pageCount, $page + 1))) ?>">&raquo;</a></li>
            </ul></nav>
        <?php endif; ?>
    </main>
    <?php require 'footer.php'; ?>
</body>

</html>
