<?php

/**
 * Raw Log Export (admin only)
 *
 * Downloads the application's append-only logs exactly as stored (every
 * column, no translation or aggregation), for audits and archiving. One log
 * is exported as CSV, or every log at once as a ZIP of CSV files. An optional
 * date range filters each log on its own timestamp column. Every export is
 * itself recorded in the activity log.
 */

require 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['message'] = tr('Admin only.');
    header('Location: home.php');
    exit;
}

// Exportable logs: key => [label, table, timestamp column used by the date filter].
const RAW_LOGS = [
    'activity_log' => ['Activity log (all actions)', 'activity_log', 'created_at'],
    'animal_events' => ['Animal events', 'animal_events', 'created_at'],
    'animal_movements' => ['Animal movements between cages', 'animal_movements', 'moved_at'],
    'cage_checks' => ['Cage checks (daily round, QR, follow-ups)', 'cage_checks', 'checked_at'],
    'cage_lifecycle' => ['Cage lifecycle (creation, retirement, restoration)', 'cage_lifecycle', 'event_at'],
    'litter_events' => ['Litter events', 'litter_events', 'created_at'],
    'health_report_updates' => ['Health registry follow-up entries', 'health_report_updates', 'created_at'],
    'endpoint_actions' => ['Endpoint actions', 'endpoint_actions', 'created_at'],
    'notifications' => ['In-app notifications', 'notifications', 'created_at'],
    'outbox' => ['Email queue', 'outbox', 'created_at'],
];

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$validDate = fn(string $d): bool => $d === '' || (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
$error = null;
if (!$validDate($from) || !$validDate($to)) {
    $error = tr('Invalid date: use the date picker.');
    $from = $to = '';
} elseif ($from !== '' && $to !== '' && $from > $to) {
    $error = tr('The start date must be before the end date.');
}

// Run the filtered query of one log (or only count its rows).
function raw_log_query(mysqli $con, string $key, string $from, string $to, bool $countOnly = false): mysqli_result
{
    [, $table, $dateColumn] = RAW_LOGS[$key];
    $where = [];
    $params = [];
    if ($from !== '') {
        $where[] = "`$dateColumn` >= ?";
        $params[] = "$from 00:00:00";
    }
    if ($to !== '') {
        $where[] = "`$dateColumn` < ? + INTERVAL 1 DAY";
        $params[] = $to;
    }
    $sql = ($countOnly ? 'SELECT COUNT(*)' : 'SELECT *') . " FROM `$table`" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ($countOnly ? '' : ' ORDER BY id');
    $stmt = $con->prepare($sql);
    if ($params) {
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

// Write one log as CSV (UTF-8 with BOM, ';' separated like the other exports).
// Returns the number of rows written.
function write_raw_log_csv($out, mysqli_result $result): int
{
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_column($result->fetch_fields(), 'name'), ';');
    $rows = 0;
    while ($row = $result->fetch_row()) {
        // Neutralize spreadsheet formulas in free-text cells (CSV injection).
        fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $row), ';');
        $rows++;
    }
    return $rows;
}

$export = $_GET['export'] ?? '';
if ($export !== '' && $error === null && ($export === 'all' || isset(RAW_LOGS[$export]))) {
    $period = ($from !== '' ? $from : 'debut') . '_' . ($to !== '' ? $to : date('Y-m-d'));
    $range = trim(($from !== '' ? tr('from {date}', ['date' => $from]) : '') . ' ' . ($to !== '' ? tr('to {date}', ['date' => $to]) : ''));

    if ($export === 'all') {
        $zipPath = tempnam(sys_get_temp_dir(), 'logs');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $total = 0;
        foreach (array_keys(RAW_LOGS) as $key) {
            $csv = fopen('php://temp', 'w+');
            $total += write_raw_log_csv($csv, raw_log_query($con, $key, $from, $to));
            rewind($csv);
            $zip->addFromString("$key.csv", stream_get_contents($csv));
            fclose($csv);
        }
        $zip->close();
        log_activity($con, 'export', 'raw_logs', 'all', tr('Raw logs exported (ZIP, {n} rows) {range}', ['n' => $total, 'range' => $range]), $_SESSION['user_id'] ?? null);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="logs_bruts_' . $period . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        unlink($zipPath);
        exit;
    }

    $result = raw_log_query($con, $export, $from, $to);
    $rowCount = $result->num_rows;
    log_activity($con, 'export', 'raw_logs', $export, tr('Raw log exported ({n} rows) {range}', ['n' => $rowCount, 'range' => $range]), $_SESSION['user_id'] ?? null);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $export . '_' . $period . '.csv"');
    $out = fopen('php://output', 'w');
    write_raw_log_csv($out, $result);
    fclose($out);
    exit;
}

// Row counts for the chosen period, shown before downloading.
$counts = [];
if ($error === null) {
    foreach (array_keys(RAW_LOGS) as $key) {
        $counts[$key] = (int) raw_log_query($con, $key, $from, $to, true)->fetch_row()[0];
    }
}
$exportUrl = fn(array $extra): string => 'log_export.php?' . http_build_query(array_filter(['from' => $from, 'to' => $to] + $extra, fn($v) => $v !== ''));

require 'header.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= trh('Raw log export') ?> | <?= htmlspecialchars($labName); ?></title>
</head>

<body>
    <div class="container mt-4 content" style="max-width: 900px;">
        <h4><i class="fas fa-file-export"></i> <?= trh('Raw log export') ?></h4>
        <p class="text-muted"><?= trh('Downloads the logs exactly as stored in the database: every column, one row per entry, without translation. Use it for audits, inspections or archiving. Each export is itself recorded in the activity log.') ?></p>

        <?php include 'message.php'; ?>
        <?php if ($error) : ?><div class="alert alert-danger"><?= htmlspecialchars($error); ?></div><?php endif; ?>

        <form method="GET" class="row g-2 align-items-end mb-3">
            <div class="col-md-4">
                <label class="form-label" for="from"><?= trh('From') ?></label>
                <input type="date" class="form-control" id="from" name="from" value="<?= htmlspecialchars($from); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="to"><?= trh('To') ?></label>
                <input type="date" class="form-control" id="to" name="to" value="<?= htmlspecialchars($to); ?>">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter"></i> <?= trh('Apply the period') ?></button>
            </div>
            <div class="col-12"><small class="text-muted"><?= trh('Leave both dates empty to export the whole history.') ?></small></div>
        </form>

        <?php if ($error === null) : ?>
            <table class="table table-sm align-middle" id="rawLogs">
                <thead class="table-light">
                    <tr>
                        <th><?= trh('Log') ?></th>
                        <th><?= trh('Table') ?></th>
                        <th class="text-end"><?= trh('Rows') ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (RAW_LOGS as $key => [$label, $table]) : ?>
                        <tr>
                            <td><?= trh($label); ?></td>
                            <td><code><?= htmlspecialchars($table); ?></code></td>
                            <td class="text-end"><?= (int) $counts[$key]; ?></td>
                            <td class="text-end">
                                <a href="<?= htmlspecialchars($exportUrl(['export' => $key])); ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-csv"></i> CSV</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <a href="<?= htmlspecialchars($exportUrl(['export' => 'all'])); ?>" class="btn btn-success"><i class="fas fa-file-archive"></i> <?= trh('Export all logs (ZIP)') ?></a>
        <?php endif; ?>
    </div>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
