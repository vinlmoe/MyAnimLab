<?php

/**
 * Personal training records (livret de formation / de compétences) of the
 * people working with the animals, and the check of their obligations under
 * Directive 2010/63/EU (art. 23 and 24) and its French transposition
 * (arrêté du 1er février 2013):
 *  - a person carries out a function (design of procedures and projects,
 *    application of procedures, care, killing) once trained for it, and
 *    works under supervision until the competence is validated;
 *  - continuing training: at least N days over a rolling period of Y years
 *    (3 days over 6 years by default, adjustable by an administrator),
 *    counted from validated entries only. During the first Y years after the
 *    first initial training the person is "to complete" before the deadline.
 * Requires animal_lib.php.
 */

const TRAINING_MAX_PDF_BYTES = 20 * 1024 * 1024;
// Warn this many days before the continuing training deadline.
const TRAINING_WARNING_DAYS = 180;

// Regulatory functions (Directive 2010/63/EU, art. 23(2)).
function training_functions(): array
{
    return [
        'design' => tr('Design of procedures and projects'),
        'procedures' => tr('Application of procedures to animals'),
        'care' => tr('Care of animals'),
        'euthanasia' => tr('Killing of animals'),
    ];
}

function training_categories(): array
{
    return [
        'initial' => tr('Initial regulatory training'),
        'competence' => tr('Competence validated (end of supervision)'),
        'continuing' => tr('Continuing training'),
        'other' => tr('Other training or diploma'),
    ];
}

function training_entry_statuses(): array
{
    return ['pending' => tr('To validate'), 'validated' => tr('Validated'), 'rejected' => tr('Rejected')];
}

// Continuing training requirement from the settings: [days, years, hours per day].
function training_requirement(mysqli $con): array
{
    static $req = null;
    if ($req === null) {
        $s = [];
        foreach ($con->query("SELECT name, value FROM settings WHERE name IN ('training_cpd_days', 'training_cpd_years', 'training_hours_per_day')") as $row) {
            $s[$row['name']] = (float) $row['value'];
        }
        $req = [
            ($s['training_cpd_days'] ?? 0) > 0 ? $s['training_cpd_days'] : 3.0,
            ($s['training_cpd_years'] ?? 0) > 0 ? (int) $s['training_cpd_years'] : 6,
            ($s['training_hours_per_day'] ?? 0) > 0 ? $s['training_hours_per_day'] : 7.0,
        ];
    }
    return $req;
}

function training_hours_text(float $hours, float $hoursPerDay): string
{
    $h = rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
    $d = rtrim(rtrim(number_format($hours / $hoursPerDay, 1, '.', ''), '0'), '.');
    return tr('{hours} h ({days} d)', ['hours' => $h, 'days' => $d]);
}

// Records of users, by user id, most recent first.
function training_records(mysqli $con, array $userIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$ids) {
        return [];
    }
    $out = array_fill_keys($ids, []);
    foreach ($con->query('SELECT * FROM training_records WHERE user_id IN (' . implode(',', $ids) . ') ORDER BY start_date DESC, id DESC') as $r) {
        $out[(int) $r['user_id']][] = $r;
    }
    return $out;
}

/**
 * Compliance of one person from their records:
 *  functions:   [code => ['state' => none|supervised|competent, 'trained_on', 'competent_on']]
 *  cpd:         ['hours', 'required_hours', 'window_from', 'state' => ok|expiring|to_complete|late|not_applicable,
 *                'deadline', 'first_initial']
 *  pending:     number of entries waiting for validation
 *  ok:          true when nothing is to fix
 */
function training_status(mysqli $con, array $records, ?string $today = null): array
{
    [$days, $years, $hoursPerDay] = training_requirement($con);
    $today ??= date('Y-m-d');
    $windowFrom = date('Y-m-d', strtotime("$today -$years years"));
    $requiredHours = $days * $hoursPerDay;

    $functions = [];
    foreach (array_keys(training_functions()) as $code) {
        $functions[$code] = ['state' => 'none', 'trained_on' => null, 'competent_on' => null];
    }
    $firstInitial = null;
    $continuing = [];
    $pending = 0;
    foreach ($records as $r) {
        if ($r['status'] === 'pending') {
            $pending++;
        }
        if ($r['status'] !== 'validated') {
            continue;
        }
        $date = $r['end_date'] ?: $r['start_date'];
        $code = $r['function_code'];
        if ($r['category'] === 'initial' && isset($functions[$code])) {
            $functions[$code]['trained_on'] = min($functions[$code]['trained_on'] ?? $date, $date);
            $firstInitial = min($firstInitial ?? $date, $date);
        } elseif ($r['category'] === 'competence' && isset($functions[$code])) {
            $functions[$code]['competent_on'] = min($functions[$code]['competent_on'] ?? $date, $date);
        } elseif ($r['category'] === 'continuing' && $date > $windowFrom && $date <= $today) {
            $continuing[] = ['date' => $date, 'hours' => (float) $r['duration_hours']];
        }
    }
    foreach ($functions as &$f) {
        $f['state'] = $f['competent_on'] ? 'competent' : ($f['trained_on'] ? 'supervised' : 'none');
    }
    unset($f);

    $hours = array_sum(array_column($continuing, 'hours'));
    $cpd = ['hours' => $hours, 'required_hours' => $requiredHours, 'window_from' => $windowFrom, 'deadline' => null, 'first_initial' => $firstInitial];
    if (!$firstInitial) {
        $cpd['state'] = 'not_applicable';
    } elseif ($hours >= $requiredHours) {
        // Still compliant until enough of the counted training leaves the window.
        usort($continuing, fn($a, $b) => strcmp($a['date'], $b['date']));
        $left = $hours;
        foreach ($continuing as $c) {
            $left -= $c['hours'];
            if ($left < $requiredHours) {
                $cpd['deadline'] = date('Y-m-d', strtotime($c['date'] . " +$years years"));
                break;
            }
        }
        $cpd['state'] = $cpd['deadline'] && $cpd['deadline'] <= date('Y-m-d', strtotime("$today +" . TRAINING_WARNING_DAYS . ' days')) ? 'expiring' : 'ok';
    } else {
        $deadline = date('Y-m-d', strtotime("$firstInitial +$years years"));
        $cpd['deadline'] = $deadline;
        $cpd['state'] = $deadline > $today ? 'to_complete' : 'late';
    }
    $ok = $cpd['state'] !== 'late' && !in_array('supervised', array_column($functions, 'state'), true);
    return ['functions' => $functions, 'cpd' => $cpd, 'pending' => $pending, 'ok' => $ok];
}

function training_status_for_user(mysqli $con, int $userId): array
{
    return training_status($con, training_records($con, [$userId])[$userId] ?? []);
}

function training_cpd_badge(array $cpd): string
{
    [$cls, $label] = match ($cpd['state']) {
        'ok' => ['success', tr('Up to date')],
        'expiring' => ['warning text-dark', tr('Up to date until {date}', ['date' => $cpd['deadline']])],
        'to_complete' => ['info text-dark', tr('To complete before {date}', ['date' => $cpd['deadline']])],
        'late' => ['danger', tr('Not compliant since {date}', ['date' => $cpd['deadline']])],
        default => ['secondary', tr('No regulatory training')],
    };
    return '<span class="badge bg-' . $cls . '">' . htmlspecialchars($label) . '</span>';
}

function training_function_badge(array $f): string
{
    return match ($f['state']) {
        'competent' => '<span class="badge bg-success">' . trh('Competent since {date}', ['date' => $f['competent_on']]) . '</span>',
        'supervised' => '<span class="badge bg-warning text-dark">' . trh('Trained {date} — under supervision', ['date' => $f['trained_on']]) . '</span>',
        default => '<span class="text-muted small">—</span>',
    };
}

/**
 * Warning shown to the current user on the experimental pages when their
 * training record does not allow them to apply procedures alone (not
 * trained, still under supervision) or the continuing training is overdue.
 * Returns '' when nothing is to report.
 */
function training_warning_banner(mysqli $con): string
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if (!$userId) {
        return '';
    }
    $status = training_status_for_user($con, $userId);
    $issues = [];
    $proc = $status['functions']['procedures'];
    if ($proc['state'] === 'none') {
        $issues[] = tr('no validated regulatory training to apply procedures to animals: you may only work under the supervision of a trained person');
    } elseif ($proc['state'] === 'supervised') {
        $issues[] = tr('competence to apply procedures not yet validated: you work under supervision');
    }
    if ($status['cpd']['state'] === 'late') {
        $issues[] = tr('continuing training below the regulatory minimum since {date}', ['date' => $status['cpd']['deadline']]);
    } elseif ($status['cpd']['state'] === 'expiring') {
        $issues[] = tr('continuing training to renew before {date}', ['date' => $status['cpd']['deadline']]);
    }
    if (!$issues) {
        return '';
    }
    return '<div class="alert alert-warning py-2 small"><i class="fas fa-user-graduate"></i> ' . trh('Your training record:') . ' '
        . htmlspecialchars(implode(' · ', $issues)) . '. <a href="training_book.php">' . trh('Open my training record') . '</a></div>';
}

// Private folder of the certificates (served by training_file.php).
function training_storage_dir(): string
{
    return __DIR__ . '/storage/training';
}

// Store an uploaded certificate (PDF). Returns the stored name, null when none.
function store_training_pdf(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception(tr('The file "{name}" could not be uploaded.', ['name' => $file['name']]));
    }
    if ($file['size'] > TRAINING_MAX_PDF_BYTES) {
        throw new Exception(tr('"{name}" exceeds the 20 MB limit.', ['name' => $file['name']]));
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if ($mime !== 'application/pdf' || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') {
        throw new Exception(tr('"{name}" is not a PDF file.', ['name' => $file['name']]));
    }
    $dir = training_storage_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        throw new Exception(tr('Failed to create directory.'));
    }
    if (!is_file($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    $stored = bin2hex(random_bytes(16)) . '.pdf';
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        throw new Exception(tr('Error uploading file.'));
    }
    return $stored;
}

/**
 * Once a day (called by process_reminders.php): notify each person whose
 * continuing training is overdue or due within TRAINING_WARNING_DAYS, at
 * most once every 30 days, and tell the administrators and BEA responsibles
 * about the entries waiting for validation.
 */
function training_send_reminders(mysqli $con): void
{
    $today = date('Y-m-d');
    $last = $con->query("SELECT value FROM settings WHERE name = 'training_reminders_last'")->fetch_row()[0] ?? '';
    if ($last === $today) {
        return;
    }
    $set = $con->prepare("INSERT INTO settings (name, value) VALUES ('training_reminders_last', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
    $set->bind_param('s', $today);
    $set->execute();
    $set->close();

    $users = $con->query("SELECT id FROM users WHERE status = 'approved'")->fetch_all(MYSQLI_ASSOC);
    $records = training_records($con, array_column($users, 'id'));
    $recent = $con->prepare("SELECT 1 FROM notifications WHERE user_id = ? AND link = 'training_book.php#cpdStatus' AND created_at > NOW() - INTERVAL 30 DAY");
    foreach ($records as $userId => $list) {
        $cpd = training_status($con, $list)['cpd'];
        if (!in_array($cpd['state'], ['late', 'expiring', 'to_complete'], true)
            || ($cpd['state'] === 'to_complete' && $cpd['deadline'] > date('Y-m-d', strtotime('+' . TRAINING_WARNING_DAYS . ' days')))) {
            continue;
        }
        $recent->bind_param('i', $userId);
        $recent->execute();
        if ($recent->get_result()->num_rows) {
            continue;
        }
        create_notification($con, $userId,
            $cpd['state'] === 'late' ? tr('Continuing training overdue') : tr('Continuing training to plan'),
            tr('Your continuing training totals {done} over the last period; the minimum is {required} (deadline {date}).', [
                'done' => training_hours_text($cpd['hours'], training_requirement($con)[2]),
                'required' => training_hours_text($cpd['required_hours'], training_requirement($con)[2]),
                'date' => $cpd['deadline'],
            ]), 'training_book.php#cpdStatus', 'reminder');
    }
    $recent->close();

    $pending = (int) $con->query("SELECT COUNT(*) FROM training_records WHERE status = 'pending'")->fetch_row()[0];
    if ($pending) {
        foreach ($con->query("SELECT id FROM users WHERE status = 'approved' AND (role = 'admin' OR is_bea_responsible = 1)") as $u) {
            $check = $con->prepare("SELECT 1 FROM notifications WHERE user_id = ? AND link = 'training_overview.php' AND is_read = 0");
            $check->bind_param('i', $u['id']);
            $check->execute();
            $already = $check->get_result()->num_rows > 0;
            $check->close();
            if (!$already) {
                create_notification($con, (int) $u['id'], tr('Training entries to validate'), tr('{n} training record entry(ies) wait for validation.', ['n' => $pending]), 'training_overview.php', 'reminder');
            }
        }
    }
}
