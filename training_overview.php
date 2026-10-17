<?php

/**
 * Training records of the staff (administrators and BEA responsibles): for
 * every approved user, the functions held (trained / competence validated),
 * the continuing training over the rolling period against the regulatory
 * minimum with its deadline, and the entries waiting for validation.
 * Non-compliant people first; CSV export. Administrators set the continuing
 * training requirement (days over years, hours per day).
 */

session_start();
require 'dbcon.php';
require 'animal_lib.php';
require 'training_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
if (!is_bea_user($con)) {
    http_response_code(403);
    exit(trh('Access restricted to the BEA responsible.'));
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_requirement'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die(tr('CSRF token validation failed'));
    }
    $days = parse_number($_POST['cpd_days'] ?? '');
    $years = (int) ($_POST['cpd_years'] ?? 0);
    $hpd = parse_number($_POST['hours_per_day'] ?? '');
    if (!$isAdmin) {
        $_SESSION['message'] = tr('Admin only.');
    } elseif ($days === null || $days <= 0 || $years < 1 || $years > 20 || $hpd === null || $hpd <= 0 || $hpd > 24) {
        $_SESSION['message'] = tr('Give a number of days, a number of years (1 to 20) and hours per day (up to 24).');
    } else {
        $set = $con->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        foreach (['training_cpd_days' => $days, 'training_cpd_years' => $years, 'training_hours_per_day' => $hpd] as $name => $value) {
            $value = (string) $value;
            $set->bind_param('ss', $name, $value);
            $set->execute();
        }
        $set->close();
        log_activity($con, 'training_requirement_changed', 'settings', 'training', tr('{days} days over {years} years, {hours} h per day', ['days' => $days, 'years' => $years, 'hours' => $hpd]), $_SESSION['user_id'] ?? null);
        $_SESSION['message'] = tr('Continuing training requirement saved.');
    }
    header('Location: training_overview.php');
    exit;
}

[$reqDays, $reqYears, $hoursPerDay] = training_requirement($con);
$users = $con->query("SELECT id, name, username, position, role FROM users WHERE status = 'approved' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$records = training_records($con, array_column($users, 'id'));
$stateRank = ['late' => 0, 'expiring' => 1, 'to_complete' => 2, 'ok' => 3, 'not_applicable' => 4];
$rows = [];
foreach ($users as $u) {
    $status = training_status($con, $records[(int) $u['id']] ?? []);
    $rows[] = ['user' => $u, 'status' => $status];
}
usort($rows, fn($a, $b) => [$stateRank[$a['status']['cpd']['state']], $a['status']['ok'], $a['user']['name']] <=> [$stateRank[$b['status']['cpd']['state']], $b['status']['ok'], $b['user']['name']]);
$filter = $_GET['filter'] ?? '';
if ($filter === 'issues') {
    $rows = array_values(array_filter($rows, fn($r) => !$r['status']['ok'] || $r['status']['pending'] || in_array($r['status']['cpd']['state'], ['expiring', 'to_complete'], true)));
}
$counts = array_count_values(array_map(fn($r) => $r['status']['cpd']['state'], $rows));
$pendingTotal = array_sum(array_map(fn($r) => $r['status']['pending'], $rows));
$functionShort = ['design' => tr('Design'), 'procedures' => tr('Procedures'), 'care' => tr('Care'), 'euthanasia' => tr('Killing')];
$stateText = [
    'ok' => tr('Up to date'), 'expiring' => tr('To renew soon'), 'to_complete' => tr('To complete'),
    'late' => tr('Not compliant'), 'not_applicable' => tr('No regulatory training'),
];

if (($_GET['format'] ?? '') === 'csv') {
    log_activity($con, 'export', 'training_records', 'all', tr('Training records overview (CSV)'), $_SESSION['user_id'] ?? null);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="livrets_formation_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $safe = fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
    fputcsv($out, array_merge(['name', 'email', 'position'], array_map(fn($c) => "function_$c", array_keys(training_functions())),
        ['first_initial_training', 'cpd_hours_in_period', 'cpd_required_hours', 'period_start', 'cpd_status', 'deadline', 'entries_to_validate']), ';');
    foreach ($rows as $r) {
        $s = $r['status'];
        $fn = array_map(fn($f) => $f['state'] === 'competent' ? 'competent ' . $f['competent_on'] : ($f['state'] === 'supervised' ? 'supervised ' . $f['trained_on'] : ''), $s['functions']);
        fputcsv($out, array_map($safe, array_merge([$r['user']['name'], $r['user']['username'], $r['user']['position']], array_values($fn),
            [$s['cpd']['first_initial'], $s['cpd']['hours'], $s['cpd']['required_hours'], $s['cpd']['window_from'], $s['cpd']['state'], $s['cpd']['deadline'], $s['pending']])), ';');
    }
    fclose($out);
    exit;
}

require 'header.php';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Training records of the staff') ?> | <?= htmlspecialchars($labName) ?></title>
</head>

<body>
    <main class="container mt-4 content" style="max-width: 1250px">
        <h4><i class="fas fa-user-graduate"></i> <?= trh('Training records of the staff') ?></h4>
        <p class="text-muted small"><?= trh('Regulatory training per function and continuing training of every person (Directive 2010/63/EU, art. 23 and 24). Continuing training is counted from validated entries over the last {years} years; minimum: {days} days ({hours} h).', ['years' => $reqYears, 'days' => rtrim(rtrim(number_format($reqDays, 1, '.', ''), '0'), '.'), 'hours' => rtrim(rtrim(number_format($reqDays * $hoursPerDay, 2, '.', ''), '0'), '.')]) ?></p>
        <?php include 'message.php'; ?>

        <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
            <span class="badge bg-danger fs-6"><?= trh('{n} not compliant', ['n' => $counts['late'] ?? 0]) ?></span>
            <span class="badge bg-warning text-dark fs-6"><?= trh('{n} to renew soon', ['n' => $counts['expiring'] ?? 0]) ?></span>
            <span class="badge bg-info text-dark fs-6"><?= trh('{n} to complete', ['n' => $counts['to_complete'] ?? 0]) ?></span>
            <span class="badge bg-success fs-6"><?= trh('{n} up to date', ['n' => $counts['ok'] ?? 0]) ?></span>
            <?php if ($pendingTotal) : ?><span class="badge bg-secondary fs-6"><?= trh('{n} entry(ies) to validate', ['n' => $pendingTotal]) ?></span><?php endif; ?>
            <span class="ms-auto">
                <a class="btn btn-sm btn-outline-<?= $filter === 'issues' ? 'secondary' : 'primary' ?>" href="training_overview.php<?= $filter === 'issues' ? '' : '?filter=issues' ?>"><?= $filter === 'issues' ? trh('Show everyone') : trh('Only what needs attention') ?></a>
                <a class="btn btn-sm btn-outline-secondary" href="training_overview.php?format=csv"><i class="fas fa-file-csv"></i> CSV</a>
            </span>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle" id="trainingOverview">
                <thead class="table-light">
                    <tr>
                        <th><?= trh('Person') ?></th>
                        <?php foreach ($functionShort as $code => $label) : ?><th title="<?= htmlspecialchars(training_functions()[$code]) ?>"><?= htmlspecialchars($label) ?></th><?php endforeach; ?>
                        <th><?= trh('Continuing training') ?></th>
                        <th><?= trh('Status') ?></th>
                        <th><?= trh('To validate') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r) : $s = $r['status']; $u = $r['user']; ?>
                        <tr class="<?= $s['cpd']['state'] === 'late' ? 'table-danger' : '' ?>">
                            <td><a href="training_book.php?user_id=<?= (int) $u['id'] ?>" class="fw-bold"><?= htmlspecialchars($u['name']) ?></a><br><small class="text-muted"><?= htmlspecialchars($u['position']) ?></small></td>
                            <?php foreach (array_keys($functionShort) as $code) : $f = $s['functions'][$code]; ?>
                                <td class="text-center">
                                    <?php if ($f['state'] === 'competent') : ?><i class="fas fa-check-circle text-success" title="<?= trh('Competent since {date}', ['date' => $f['competent_on']]) ?>"></i>
                                    <?php elseif ($f['state'] === 'supervised') : ?><i class="fas fa-user-friends text-warning" title="<?= trh('Trained {date} — under supervision', ['date' => $f['trained_on']]) ?>"></i>
                                    <?php else : ?><span class="text-muted">—</span><?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="small">
                                <?php if ($s['cpd']['state'] !== 'not_applicable') : ?>
                                    <?= htmlspecialchars(training_hours_text($s['cpd']['hours'], $hoursPerDay)) ?> / <?= htmlspecialchars(training_hours_text($s['cpd']['required_hours'], $hoursPerDay)) ?>
                                <?php else : ?>—<?php endif; ?>
                            </td>
                            <td><?= training_cpd_badge($s['cpd']) ?></td>
                            <td class="text-center"><?= $s['pending'] ? '<a href="training_book.php?user_id=' . (int) $u['id'] . '" class="badge bg-warning text-dark text-decoration-none">' . (int) $s['pending'] . '</a>' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="small text-muted"><i class="fas fa-check-circle text-success"></i> <?= trh('competence validated') ?> · <i class="fas fa-user-friends text-warning"></i> <?= trh('trained, working under supervision') ?> · — <?= trh('not trained for this function') ?></p>

        <?php if ($isAdmin) : ?>
            <form method="POST" class="card card-body mt-3" style="max-width: 700px">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <h6><?= trh('Continuing training requirement') ?></h6>
                <p class="small text-muted"><?= trh('Default: 3 days over 6 years (French transposition, arrêté of 1 February 2013). Adjust it to the rules applying to your establishment.') ?></p>
                <div class="row g-2 align-items-end">
                    <div class="col-md-3"><label class="form-label small"><?= trh('Minimum (days)') ?></label><input class="form-control form-control-sm" name="cpd_days" value="<?= htmlspecialchars(rtrim(rtrim(number_format($reqDays, 2, '.', ''), '0'), '.')) ?>" inputmode="decimal"></div>
                    <div class="col-md-3"><label class="form-label small"><?= trh('Rolling period (years)') ?></label><input class="form-control form-control-sm" type="number" min="1" max="20" name="cpd_years" value="<?= (int) $reqYears ?>"></div>
                    <div class="col-md-3"><label class="form-label small"><?= trh('Hours per day') ?></label><input class="form-control form-control-sm" name="hours_per_day" value="<?= htmlspecialchars(rtrim(rtrim(number_format($hoursPerDay, 2, '.', ''), '0'), '.')) ?>" inputmode="decimal"></div>
                    <div class="col-md-3"><button class="btn btn-sm btn-primary w-100" name="save_requirement"><?= trh('Save') ?></button></div>
                </div>
            </form>
        <?php endif; ?>
    </main>
    <?php require 'footer.php'; ?>
</body>

</html>
