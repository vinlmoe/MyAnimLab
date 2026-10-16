<?php

/**
 * Health registry (registre sanitaire).
 *
 * Single place gathering every health report ever made and its follow-up.
 * Read-only by design: entries are appended from health_report.php and
 * health_report_view.php and protected against UPDATE/DELETE by database
 * triggers. Can be exported to CSV (one row per follow-up entry).
 */

session_start();
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

$status = $_GET['status'] ?? 'unresolved';
$severity = $_GET['severity'] ?? '';
$category = $_GET['category'] ?? '';
$animalQ = trim($_GET['animal'] ?? '');
$cageQ = trim($_GET['cage'] ?? '');
$lineQ = trim($_GET['line'] ?? '');
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

$where = [];
$types = '';
$params = [];

if ($status === 'unresolved') {
    $where[] = "s.current_status <> 'resolved'";
} elseif (isset(HEALTH_STATUSES[$status])) {
    $where[] = "s.current_status = ?";
    $params[] = $status;
    $types .= 's';
}
if (isset(HEALTH_SEVERITIES[$severity])) {
    $where[] = "s.current_severity = ?";
    $params[] = $severity;
    $types .= 's';
}
if (isset(HEALTH_CATEGORIES[$category])) {
    $where[] = "s.category = ?";
    $params[] = $category;
    $types .= 's';
}
if ($animalQ !== '') {
    $where[] = "s.animal_uid LIKE ?";
    $params[] = "%$animalQ%";
    $types .= 's';
}
if ($cageQ !== '') {
    $where[] = "s.cage_id LIKE ?";
    $params[] = "%$cageQ%";
    $types .= 's';
}
if ($lineQ !== '') {
    $where[] = "s.line_code = ?";
    $params[] = $lineQ;
    $types .= 's';
}
if ($from !== '') {
    $where[] = "s.observed_at >= ?";
    $params[] = $from;
    $types .= 's';
}
if ($to !== '') {
    $where[] = "s.observed_at <= ?";
    $params[] = $to;
    $types .= 's';
}

$whereSql = $where ? " WHERE " . implode(' AND ', $where) : '';

function run_query(mysqli $con, string $sql, string $types, array $params): array
{
    $stmt = $con->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// CSV export: the full follow-up history of every matching report.
if (($_GET['export'] ?? '') === 'csv') {
    $rows = run_query(
        $con,
        "SELECT s.id AS report_id, s.animal_uid, s.line_code, s.cage_id, s.observed_at, s.category,
                s.severity AS initial_severity, s.description, s.reported_by_name, s.reported_at,
                u.created_at AS entry_at, u.status AS entry_status, u.severity AS entry_severity,
                u.note AS entry_note, u.created_by_name AS entry_by
         FROM (" . HEALTH_REPORTS_WITH_STATE_SQL . ") s
         JOIN health_report_updates u ON u.report_id = s.id"
            . $whereSql . " ORDER BY s.id, u.id",
        $types,
        $params
    );
    log_activity($con, 'export', 'health_registry', null, tr('{n} rows exported', ['n' => count($rows)]), $_SESSION['user_id'] ?? null);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="health_registry_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so spreadsheet software reads accents correctly
    fputcsv($out, array_keys($rows[0] ?? [
        'report_id' => 0, 'animal_uid' => 0, 'line_code' => 0, 'cage_id' => 0, 'observed_at' => 0, 'category' => 0,
        'initial_severity' => 0, 'description' => 0, 'reported_by_name' => 0, 'reported_at' => 0,
        'entry_at' => 0, 'entry_status' => 0, 'entry_severity' => 0, 'entry_note' => 0, 'entry_by' => 0,
    ]));
    foreach ($rows as $row) {
        // Neutralize spreadsheet formulas in free-text cells (CSV injection).
        $row = array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $row);
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$reports = run_query(
    $con,
    "SELECT s.*, (SELECT COUNT(*) FROM health_report_updates x WHERE x.report_id = s.id) AS n_entries
     FROM (" . HEALTH_REPORTS_WITH_STATE_SQL . ") s" . $whereSql . " ORDER BY s.reported_at DESC, s.id DESC LIMIT 500",
    $types,
    $params
);

$totals = $con->query(
    "SELECT current_status, COUNT(*) AS n FROM (" . HEALTH_REPORTS_WITH_STATE_SQL . ") s GROUP BY current_status"
)->fetch_all(MYSQLI_ASSOC);
$totals = array_column($totals, 'n', 'current_status');

$lines = $con->query("SELECT code FROM animal_lines ORDER BY code")->fetch_all(MYSQLI_ASSOC);

require 'header.php';
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Health Registry') ?> | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container {
            max-width: 1200px;
        }
    </style>
</head>

<body>
    <div class="container mt-4 content">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <h4><i class="fas fa-book-medical"></i> <?= trh('Health Registry') ?></h4>
            <div>
                <a href="health_report.php" class="btn btn-warning btn-sm"><i class="fas fa-notes-medical"></i> <?= trh('Report a health issue') ?></a>
                <a href="health_registry.php?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-file-csv"></i> <?= trh('Export CSV') ?></a>
            </div>
        </div>
        <p class="text-muted"><?= trh('Every health report and its follow-up, kept permanently. Entries cannot be edited or deleted; corrections are made by adding a follow-up entry.') ?></p>

        <?php include('message.php'); ?>

        <div class="mb-3">
            <a href="health_registry.php?status=open" class="badge bg-danger text-decoration-none fs-6"><?= trh('Open: {n}', ['n' => (int) ($totals['open'] ?? 0)]); ?></a>
            <a href="health_registry.php?status=monitoring" class="badge bg-warning text-dark text-decoration-none fs-6"><?= trh('Monitoring: {n}', ['n' => (int) ($totals['monitoring'] ?? 0)]); ?></a>
            <a href="health_registry.php?status=resolved" class="badge bg-success text-decoration-none fs-6"><?= trh('Resolved: {n}', ['n' => (int) ($totals['resolved'] ?? 0)]); ?></a>
        </div>

        <form method="GET" class="card card-body mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label" for="status"><?= trh('Status') ?></label>
                    <select class="form-select" id="status" name="status">
                        <option value="unresolved" <?= $status === 'unresolved' ? 'selected' : ''; ?>><?= trh('Unresolved') ?></option>
                        <option value="all" <?= $status === 'all' ? 'selected' : ''; ?>><?= trh('All') ?></option>
                        <?php foreach (array_keys(HEALTH_STATUSES) as $s) : ?>
                            <option value="<?= $s; ?>" <?= $status === $s ? 'selected' : ''; ?>><?= htmlspecialchars(ucfirst(tr($s))); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="severity"><?= trh('Severity') ?></label>
                    <select class="form-select" id="severity" name="severity">
                        <option value=""><?= trh('All') ?></option>
                        <?php foreach (array_keys(HEALTH_SEVERITIES) as $s) : ?>
                            <option value="<?= $s; ?>" <?= $severity === $s ? 'selected' : ''; ?>><?= htmlspecialchars(ucfirst(tr($s))); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="category"><?= trh('Category') ?></label>
                    <select class="form-select" id="category" name="category">
                        <option value=""><?= trh('All') ?></option>
                        <?php foreach (health_categories() as $k => $label) : ?>
                            <option value="<?= $k; ?>" <?= $category === $k ? 'selected' : ''; ?>><?= htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="animal"><?= trh('Animal') ?></label>
                    <input type="text" class="form-control" id="animal" name="animal" value="<?= htmlspecialchars($animalQ); ?>" placeholder="RSD-0002">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="cage"><?= trh('Cage') ?></label>
                    <input type="text" class="form-control" id="cage" name="cage" value="<?= htmlspecialchars($cageQ); ?>" placeholder="P-00012">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="line"><?= trh('Line') ?></label>
                    <select class="form-select" id="line" name="line">
                        <option value=""><?= trh('All') ?></option>
                        <?php foreach ($lines as $l) : ?>
                            <option value="<?= htmlspecialchars($l['code']); ?>" <?= $lineQ === $l['code'] ? 'selected' : ''; ?>><?= htmlspecialchars($l['code']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="from"><?= trh('Observed from') ?></label>
                    <input type="date" class="form-control" id="from" name="from" value="<?= htmlspecialchars($from); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="to"><?= trh('to') ?></label>
                    <input type="date" class="form-control" id="to" name="to" value="<?= htmlspecialchars($to); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter"></i> <?= trh('Filter') ?></button>
                </div>
                <div class="col-md-2">
                    <a href="health_registry.php?status=all" class="btn btn-outline-secondary w-100"><?= trh('Show all') ?></a>
                </div>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th><?= trh('Observed') ?></th>
                        <th><?= trh('Animal') ?></th>
                        <th><?= trh('Line') ?></th>
                        <th><?= trh('Cage') ?></th>
                        <th><?= trh('Category') ?></th>
                        <th><?= trh('Severity') ?></th>
                        <th><?= trh('Status') ?></th>
                        <th><?= trh('Last update') ?></th>
                        <th><?= trh('Entries') ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)) : ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted"><?= trh('No health report matches these criteria.') ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($reports as $r) : ?>
                        <tr>
                            <td><?= (int) $r['id']; ?></td>
                            <td><?= htmlspecialchars($r['observed_at']); ?></td>
                            <td><a href="animal_view.php?id=<?= (int) $r['animal_id']; ?>"><?= htmlspecialchars($r['animal_uid']); ?></a></td>
                            <td><?= htmlspecialchars($r['line_code'] ?? '-'); ?></td>
                            <td><?= htmlspecialchars($r['cage_id'] ?? '-'); ?></td>
                            <td><?= htmlspecialchars(health_categories()[$r['category']] ?? $r['category']); ?></td>
                            <td><?= health_badge($r['current_severity'], HEALTH_SEVERITIES); ?></td>
                            <td><?= health_badge($r['current_status'], HEALTH_STATUSES); ?></td>
                            <td><small><?= htmlspecialchars($r['last_update_at']); ?></small></td>
                            <td><?= (int) $r['n_entries']; ?></td>
                            <td><a href="health_report_view.php?id=<?= (int) $r['id']; ?>" class="btn btn-sm btn-outline-primary"><?= trh('Follow-up') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
