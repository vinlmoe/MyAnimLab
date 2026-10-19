<?php

/**
 * Shared helpers for animal traceability: unique ID generation, movement
 * history and the audit log. Requires $con (mysqli) to already be set up.
 */

// Allocate the next animal_uid for a given line (e.g. RSD-0002) and bump the
// line's counter. Must be called inside a transaction already started by the caller.
function allocate_animal_uid(mysqli $con, int $lineId): string
{
    $stmt = $con->prepare("SELECT code, next_seq FROM animal_lines WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $lineId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$result) {
        throw new Exception(tr('Unknown animal line.'));
    }

    $seq = (int) $result['next_seq'];
    $uid = $result['code'] . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);

    $update = $con->prepare("UPDATE animal_lines SET next_seq = next_seq + 1 WHERE id = ?");
    $update->bind_param("i", $lineId);
    $update->execute();
    $update->close();

    return $uid;
}

// Allocate the next cage_id (e.g. P-00001) and bump the singleton counter.
// Must be called inside a transaction already started by the caller. Cage
// IDs are never entered manually.
function allocate_cage_id(mysqli $con): string
{
    $stmt = $con->prepare("SELECT next_seq FROM cage_sequence WHERE id = 1 FOR UPDATE");
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$result) {
        throw new Exception(tr('Cage sequence not initialized.'));
    }

    $seq = (int) $result['next_seq'];
    $cageId = 'P-' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);

    $update = $con->prepare("UPDATE cage_sequence SET next_seq = next_seq + 1 WHERE id = 1");
    $update->execute();
    $update->close();

    return $cageId;
}

// Record an audit trail entry for an animal.
function log_animal_event(mysqli $con, int $animalId, string $eventType, array $payload = [], ?int $userId = null): void
{
    $stmt = $con->prepare("INSERT INTO animal_events (animal_id, event_type, payload, created_by) VALUES (?, ?, ?, ?)");
    $json = json_encode($payload);
    $stmt->bind_param("issi", $animalId, $eventType, $json, $userId);
    $stmt->execute();
    $stmt->close();
}

// Record a cage-to-cage movement (NULL $fromCageId for the initial placement at creation).
function log_animal_movement(mysqli $con, int $animalId, ?string $fromCageId, string $toCageId, ?int $userId = null, ?string $reason = null): void
{
    $stmt = $con->prepare("INSERT INTO animal_movements (animal_id, from_cage_id, to_cage_id, moved_by, reason) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issis", $animalId, $fromCageId, $toCageId, $userId, $reason);
    $stmt->execute();
    $stmt->close();
}

// Generic audit trail entry, covering any entity (animal, cage, trial,
// protocol, experiment, user...), not just animals.
function log_activity(mysqli $con, string $action, string $entityType, ?string $entityId, ?string $details = null, ?int $userId = null): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $con->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssss", $userId, $action, $entityType, $entityId, $details, $ip);
    $stmt->execute();
    $stmt->close();
}

// Actual severity classes of the BEA retrospective assessment.
const RETROSPECTIVE_SEVERITIES = [
    'non_recovery' => 'Non-recovery',
    'mild' => 'Mild',
    'moderate' => 'Moderate',
    'severe' => 'Severe',
];

// RETROSPECTIVE_SEVERITIES in the interface language.
function retrospective_severities(): array
{
    return array_map('tr', RETROSPECTIVE_SEVERITIES);
}

// Whether the logged-in user may do the BEA retrospective review: an admin or
// a user flagged as BEA responsible.
function is_bea_user(mysqli $con): bool
{
    if (($_SESSION['role'] ?? '') === 'admin') {
        return true;
    }
    if (empty($_SESSION['user_id'])) {
        return false;
    }
    $stmt = $con->prepare('SELECT is_bea_responsible FROM users WHERE id = ?');
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $isBea = !empty($stmt->get_result()->fetch_assoc()['is_bea_responsible']);
    $stmt->close();
    return $isBea;
}

// Create an in-app notification for a user (e.g. an endpoint/point-limite alert).
function create_notification(mysqli $con, int $userId, string $title, ?string $message = null, ?string $link = null, string $type = 'system'): void
{
    $stmt = $con->prepare("INSERT INTO notifications (user_id, title, message, link, type) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $userId, $title, $message, $link, $type);
    $stmt->execute();
    $stmt->close();
}

// Notify every admin user (used for endpoint alerts when there's no specific
// experiment-assignee list to target).
function notify_admins(mysqli $con, string $title, ?string $message = null, ?string $link = null, string $type = 'system'): void
{
    $result = $con->query("SELECT id FROM users WHERE role = 'admin' AND status = 'approved'");
    while ($row = $result->fetch_assoc()) {
        create_notification($con, (int) $row['id'], $title, $message, $link, $type);
    }
}

// Health (sanitary) registry vocabularies, shared by the report, follow-up
// and registry pages.
const HEALTH_CATEGORIES = [
    'injury' => 'Injury / wound',
    'weight_loss' => 'Weight loss / poor condition',
    'behavior' => 'Abnormal behavior',
    'respiratory' => 'Respiratory signs',
    'digestive' => 'Digestive signs (diarrhea...)',
    'skin_fur' => 'Skin / fur / dermatitis',
    'ocular' => 'Ocular',
    'mass' => 'Tumor / mass',
    'dental' => 'Dental / malocclusion',
    'neurological' => 'Neurological',
    'infection' => 'Suspected infection',
    'found_dead' => 'Found dead',
    'other' => 'Other',
];

// HEALTH_CATEGORIES in the interface language.
function health_categories(): array
{
    return array_map('tr', HEALTH_CATEGORIES);
}

const HEALTH_SEVERITIES = [
    'minor' => 'success',
    'moderate' => 'warning',
    'severe' => 'danger',
    'critical' => 'dark',
];

const HEALTH_STATUSES = [
    'open' => 'danger',
    'monitoring' => 'warning',
    'resolved' => 'success',
];

// SELECT fragment giving each health report with its current state, i.e. the
// status/severity of its latest (append-only) follow-up entry.
const HEALTH_REPORTS_WITH_STATE_SQL = "
    SELECT r.*, u.status AS current_status, u.severity AS current_severity,
           u.created_at AS last_update_at
    FROM health_reports r
    JOIN health_report_updates u ON u.id = (
        SELECT MAX(u2.id) FROM health_report_updates u2 WHERE u2.report_id = r.id
    )";

// Bootstrap badge for a health severity or status value.
function health_badge(string $value, array $palette): string
{
    $color = $palette[$value] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . htmlspecialchars(tr($value)) . '</span>';
}

// Number of not-yet-resolved health reports per animal id, for the given ids.
function open_health_counts(mysqli $con, array $animalIds): array
{
    if (empty($animalIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($animalIds), '?'));
    $stmt = $con->prepare(
        "SELECT s.animal_id, COUNT(*) AS n FROM (" . HEALTH_REPORTS_WITH_STATE_SQL . ") s
         WHERE s.current_status <> 'resolved' AND s.animal_id IN ($placeholders)
         GROUP BY s.animal_id"
    );
    $stmt->bind_param(str_repeat('i', count($animalIds)), ...array_map('intval', $animalIds));
    $stmt->execute();
    $counts = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $counts[(int) $row['animal_id']] = (int) $row['n'];
    }
    $stmt->close();
    return $counts;
}

// Record that a cage was seen on its daily check (append-only log). Called by
// the daily round, the QR/cage page, and automatically by the experimental
// follow-up and health reports, so any of them satisfies "seen today".
function record_cage_check(mysqli $con, string $cageId, string $type, string $result, string $method, ?string $note = null, ?int $healthReportId = null, ?int $userId = null, ?string $userName = null): void
{
    $today = date('Y-m-d');
    $stmt = $con->prepare(
        "INSERT INTO cage_checks (cage_id, check_date, check_type, result, method, note, health_report_id, checked_by, checked_by_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("ssssssiis", $cageId, $today, $type, $result, $method, $note, $healthReportId, $userId, $userName);
    $stmt->execute();
    $stmt->close();
}

// Room temperatures of the daily round: the minimum and maximum of the day,
// or the current temperature, as chosen in Manage Lab.
const ROOM_TEMPERATURE_MODES = ['min_max' => 'Minimum and maximum of the day', 'current' => 'Current temperature'];

function room_temperature_mode(mysqli $con): string
{
    $mode = (string) db_value($con, "SELECT value FROM settings WHERE name = 'room_temperature_mode'");
    return isset(ROOM_TEMPERATURE_MODES[$mode]) ? $mode : 'min_max';
}

// Record a reading (today only); $values: ['min' => .., 'max' => ..] or ['current' => ..], as typed.
function record_room_temperature(mysqli $con, string $room, string $mode, array $values, ?int $userId, ?string $userName): void
{
    if ($room === '') {
        throw new Exception(tr('Choose a room.'));
    }
    $read = function (string $key) use ($values): float {
        $value = parse_number($values[$key] ?? '');
        if ($value === null || $value < -30 || $value > 60) {
            throw new Exception(tr('Give a temperature between -30 and 60 °C.'));
        }
        return round($value, 1);
    };
    if ($mode === 'current') {
        $current = $read('current');
        $min = $max = null;
    } else {
        $mode = 'min_max';
        [$min, $max, $current] = [$read('min'), $read('max'), null];
        if ($min > $max) {
            throw new Exception(tr('The minimum temperature cannot be above the maximum.'));
        }
    }
    db_execute($con, "INSERT INTO room_temperatures (room, reading_date, mode, temp_min, temp_max, temp_current, recorded_by, recorded_by_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        'sssdddis', [$room, date('Y-m-d'), $mode, $min, $max, $current, $userId, $userName]);
    log_activity($con, 'room_temperature', 'room', $room, room_temperature_text(['mode' => $mode, 'temp_min' => $min, 'temp_max' => $max, 'temp_current' => $current]), $userId);
}

// Readings of a day by room, oldest first (the last one is the day's value).
function room_temperatures_for_date(mysqli $con, string $date): array
{
    $byRoom = [];
    foreach (db_rows($con, "SELECT * FROM room_temperatures WHERE reading_date = ? ORDER BY recorded_at, id", 's', [$date]) as $row) {
        $byRoom[$row['room']][] = $row;
    }
    return $byRoom;
}

// "min 19.5 °C · max 22.0 °C", or "21.0 °C" for a current temperature.
function room_temperature_text(array $reading): string
{
    $deg = fn($v): string => number_format((float) $v, 1, ',', '') . ' °C';
    return $reading['mode'] === 'current'
        ? $deg($reading['temp_current'])
        : tr('min {min} · max {max}', ['min' => $deg($reading['temp_min']), 'max' => $deg($reading['temp_max'])]);
}

// All checks of the given day, grouped by cage_id.
//
// A cage holding animals under experiment (cage "en essai") is only seen once
// every one of those animals has its experimental follow-up for the day and
// every experimental act due on them is confirmed: its
// other checks (round, QR, health report) do not count, and the cage shows as
// not seen until the last follow-up is entered. The rule applies from the
// date stored in the experimental_round_since setting (migration 024), so
// days recorded before it are left as they were.
function cage_checks_for_date(mysqli $con, string $date): array
{
    $stmt = $con->prepare("SELECT * FROM cage_checks WHERE check_date = ? ORDER BY checked_at, id");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $byCage = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $byCage[$row['cage_id']][] = $row;
    }
    $stmt->close();

    if ($date < experimental_round_since($con)) {
        return $byCage;
    }
    foreach (experimental_followup_by_cage($con, $date) as $cageId => $progress) {
        if (!experimental_cage_complete($progress)) {
            unset($byCage[$cageId]);
            continue;
        }
        // Complete: keep the day's checks, or show the follow-up itself when
        // the cage became complete without a recorded check (e.g. the last
        // pending animal left the experiment).
        $byCage[$cageId] ??= [[
            'cage_id' => $cageId, 'check_date' => $date, 'check_type' => 'experimental',
            'result' => $progress['issues'] ? 'issue' : 'ok', 'method' => 'auto',
            'note' => tr('{done}/{total} animal(s) evaluated', ['done' => $progress['done'], 'total' => $progress['total']]),
            'checked_at' => $progress['last_at'] ?? "$date 00:00:00", 'checked_by_name' => null,
        ]];
    }
    return $byCage;
}

// First day on which cages under experiment need every animal's follow-up.
function experimental_round_since(mysqli $con): string
{
    static $since = null;
    if ($since === null) {
        $row = $con->query("SELECT value FROM settings WHERE name = 'experimental_round_since'")->fetch_row();
        $since = $row[0] ?? date('Y-m-d');
    }
    return $since;
}

// Animals under experiment per cage on $date, how many have their follow-up
// that day, and how many experimental acts planned up to that day were still
// unconfirmed at its end: [cage_id => [total, done, issues, last_at,
// acts_pending]]. Only cages holding such animals are returned. Today uses
// the current cages and ongoing experiments; a past day uses the enrollments
// open that day and the cage each animal was in at the end of that day.
function experimental_followup_by_cage(mysqli $con, string $date, ?string $onlyCage = null): array
{
    $isToday = $date >= date('Y-m-d');
    $cageExpr = $isToday ? 'm.cage_id' : "COALESCE((SELECT mv.to_cage_id FROM animal_movements mv
        WHERE mv.animal_id = m.id AND mv.moved_at < ? + INTERVAL 1 DAY ORDER BY mv.moved_at DESC, mv.id DESC LIMIT 1), m.cage_id)";
    $openOnDate = $isToday
        ? "m.status = 'active' AND ea.removed_at IS NULL AND e.status IN ('planned', 'active')"
        : "ea.enrolled_at < ? + INTERVAL 1 DAY AND (ea.removed_at IS NULL OR ea.removed_at >= ? + INTERVAL 1 DAY)";
    // An act counts as pending that day if it was planned up to then and not
    // confirmed (done, not done or cancelled) before the end of the day.
    $actsExpr = "(SELECT COUNT(*) FROM experiment_act_animals aa WHERE aa.experiment_animal_id = ea.id AND aa.planned_date <= ?
                  AND (aa.status = 'planned' OR aa.done_at >= ? + INTERVAL 1 DAY))";
    $sql = "SELECT cage_id, COUNT(*) AS total, SUM(followup_id IS NOT NULL) AS done,
                   SUM(COALESCE(endpoint_reached, 0)) AS issues, MAX(followup_at) AS last_at, SUM(acts_pending) AS acts_pending
            FROM (SELECT $cageExpr AS cage_id, f.id AS followup_id, f.endpoint_reached, f.created_at AS followup_at, $actsExpr AS acts_pending
                  FROM experiment_animals ea
                  JOIN mice m ON m.id = ea.animal_id
                  JOIN experiments e ON e.id = ea.experiment_id
                  LEFT JOIN experiment_followups f ON f.experiment_animal_id = ea.id AND f.observed_at = ?
                  WHERE $openOnDate) x
            WHERE cage_id IS NOT NULL" . ($onlyCage !== null ? ' AND cage_id = ?' : '') . "
            GROUP BY cage_id";
    $params = $isToday ? [$date, $date, $date] : [$date, $date, $date, $date, $date, $date];
    if ($onlyCage !== null) {
        $params[] = $onlyCage;
    }
    $stmt = $con->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $byCage = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $byCage[$row['cage_id']] = ['total' => (int) $row['total'], 'done' => (int) $row['done'], 'issues' => (int) $row['issues'], 'last_at' => $row['last_at'], 'acts_pending' => (int) $row['acts_pending']];
    }
    $stmt->close();
    return $byCage;
}

// A cage under experiment is validated for the day once every animal has its
// follow-up and every experimental act due (overdue included) is confirmed.
function experimental_cage_complete(array $progress): bool
{
    return $progress['done'] >= $progress['total'] && $progress['acts_pending'] === 0;
}

// Follow-up progress of one cage today, or null when it holds no animal under experiment.
function cage_experimental_progress(mysqli $con, string $cageId): ?array
{
    return experimental_followup_by_cage($con, date('Y-m-d'), $cageId)[$cageId] ?? null;
}

// Once every animal under experiment in the cage has today's follow-up and
// every act due is confirmed, record the cage's daily check (once a day, by
// whoever completed it).
function record_experimental_cage_check_if_complete(mysqli $con, string $cageId, ?int $userId, ?string $userName): bool
{
    $progress = cage_experimental_progress($con, $cageId);
    if (!$progress || !experimental_cage_complete($progress)) {
        return false;
    }
    $today = date('Y-m-d');
    $exists = $con->prepare("SELECT 1 FROM cage_checks WHERE cage_id = ? AND check_date = ? AND check_type = 'experimental'");
    $exists->bind_param("ss", $cageId, $today);
    $exists->execute();
    $already = $exists->get_result()->num_rows > 0;
    $exists->close();
    if (!$already) {
        record_cage_check($con, $cageId, 'experimental', $progress['issues'] ? 'issue' : 'ok', 'auto',
            tr('{done}/{total} animal(s) evaluated', ['done' => $progress['done'], 'total' => $progress['total']]), null, $userId, $userName);
    }
    return true;
}

// Record a cage entering or leaving service (append-only cage_lifecycle) and
// keep cages.created_at / retired_at in sync with the latest transition.
function log_cage_lifecycle(mysqli $con, string $cageId, string $event, ?string $reason = null, ?int $userId = null, ?string $userName = null): void
{
    $stmt = $con->prepare("INSERT INTO cage_lifecycle (cage_id, event, reason, user_id, user_name) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssis", $cageId, $event, $reason, $userId, $userName);
    $stmt->execute();
    $stmt->close();

    $sql = [
        'created' => "UPDATE cages SET created_at = NOW(), retired_at = NULL WHERE cage_id = ?",
        'retired' => "UPDATE cages SET retired_at = NOW() WHERE cage_id = ?",
        'restored' => "UPDATE cages SET retired_at = NULL WHERE cage_id = ?",
    ][$event];
    $upd = $con->prepare($sql);
    $upd->bind_param("s", $cageId);
    $upd->execute();
    $upd->close();
}

// Cages (holding and breeding) that had to be seen on $date, i.e. that were in
// service that day according to their lifecycle: due from the day they were
// created or restored, no longer due from the day they were retired. Cages
// with no recorded creation (legacy data) are treated as existing since
// before records began.
function cages_due_on(mysqli $con, string $date): array
{
    $cages = $con->query(
        "SELECT c.cage_id, c.room, c.rack, c.status, c.created_at, c.retired_at,
                IF(EXISTS (SELECT 1 FROM breeding b WHERE b.cage_id = c.cage_id), 'breeding', 'holding') AS kind
         FROM cages c ORDER BY c.room, c.rack, c.cage_id"
    )->fetch_all(MYSQLI_ASSOC);

    $events = [];
    foreach ($con->query("SELECT cage_id, event, DATE(event_at) AS day FROM cage_lifecycle ORDER BY event_at, id") as $e) {
        $events[$e['cage_id']][] = $e;
    }

    $due = [];
    foreach ($cages as $c) {
        // Build in-service periods [start, end): start/end are dates, null = open.
        $periods = [];
        $start = null;
        $open = true; // legacy cage: in service since before any record
        foreach ($events[$c['cage_id']] ?? [] as $e) {
            if ($e['event'] === 'created' || $e['event'] === 'restored') {
                if (!$open || $e['event'] === 'created') {
                    $start = $e['day'];
                    $open = true;
                }
            } elseif ($e['event'] === 'retired' && $open) {
                $periods[] = [$start, $e['day']];
                $open = false;
            }
        }
        if (!isset($events[$c['cage_id']]) && $c['status'] !== 'active') {
            $open = false; // archived legacy cage with no history: never expected
        }
        if ($open) {
            $periods[] = [$start, null];
        }

        foreach ($periods as [$from, $to]) {
            if (($from === null || $from <= $date) && ($to === null || $to > $date)) {
                $due[] = $c;
                break;
            }
        }
    }
    return $due;
}

// Short badge describing one cage check.
function cage_check_badge(array $check): string
{
    $color = $check['result'] === 'ok' ? 'success' : 'danger';
    $label = ['routine' => tr('round'), 'experimental' => tr('exp. follow-up'), 'health_report' => tr('health report')][$check['check_type']] ?? tr($check['check_type']);
    $text = date('H:i', strtotime($check['checked_at'])) . ' ' . $label . ($check['method'] === 'qr' ? ' (QR)' : '')
        . ' · ' . ($check['checked_by_name'] ?? '?');
    return '<span class="badge bg-' . $color . ' me-1" title="' . htmlspecialchars($check['note'] ?? '') . '">' . htmlspecialchars($text) . '</span>';
}

// Only allow redirecting back to the daily-check pages (avoids open redirects).
function safe_check_return(?string $return): ?string
{
    // Explicit allow-list: callers may return to the daily workflow, a cage
    // view, or the experimentation workflow, never to an external URL or an
    // arbitrary application route.
    return ($return && preg_match('/^(daily_round|cage_check|experiment_cage_followup|experiment_manage|hc_view|bc_view)\.php(\?[A-Za-z0-9_\-=&%.]*)?$/', $return)) ? $return : null;
}

// A page of the application to come back to after an action: a relative
// "page.php?query#fragment" of an existing top-level page, or this site's
// own URL (e.g. the referer), reduced to that form. Anything else is refused.
function safe_local_return(?string $url, array $exclude = []): ?string
{
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }
    $parts = parse_url($url);
    if ($parts === false || isset($parts['user']) || isset($parts['scheme']) !== isset($parts['host'])) {
        return null;
    }
    if (isset($parts['host']) && strcasecmp($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''), $_SERVER['HTTP_HOST'] ?? '') !== 0) {
        return null;
    }
    $page = basename($parts['path'] ?? '');
    if (!isset($parts['host']) && ($parts['path'] ?? '') !== $page) {
        return null;
    }
    $exclude = array_merge($exclude, ['index.php', 'logout.php', 'set_locale.php']);
    if (!preg_match('/^[A-Za-z0-9_]+\.php$/', $page) || in_array($page, $exclude, true) || !is_file(__DIR__ . '/' . $page)) {
        return null;
    }
    $query = $parts['query'] ?? '';
    $fragment = $parts['fragment'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_\-=&%.+\[\]]*$/', $query) || !preg_match('/^[A-Za-z0-9_\-]*$/', $fragment)) {
        return null;
    }
    return $page . ($query !== '' ? '?' . $query : '') . ($fragment !== '' ? '#' . $fragment : '');
}

// Number of active animals currently housed in a cage.
function cage_active_animal_count(mysqli $con, string $cageId): int
{
    $stmt = $con->prepare("SELECT COUNT(*) FROM mice WHERE cage_id = ? AND status = 'active'");
    $stmt->bind_param("s", $cageId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int) $count;
}

// A cage without any active animal is taken out of service automatically
// (after a transfer, sacrifice or deletion of its last animal). Its
// recurring reminders stop; assigned users, files and notes are kept so it
// stays fully traceable. Returns true when the cage was retired.
function retire_cage_if_empty(mysqli $con, ?string $cageId, string $why, ?int $userId = null, ?string $userName = null): bool
{
    // A cage still nursing a litter stays in service (pups are not animals yet).
    if ($cageId === null || $cageId === '' || cage_active_animal_count($con, $cageId) > 0 || cage_nursing_litters($con, $cageId) > 0) {
        return false;
    }
    return retire_cage($con, $cageId, tr('Automatically retired: no animal left ({why}).', ['why' => $why]), $userId, $userName, 'auto_retire');
}

// Take an (empty) active cage out of service: status archived, lifecycle
// 'retired', recurring reminders removed, activity logged. The database
// refuses it while living animals remain (cages_no_retire_with_animals).
function retire_cage(mysqli $con, string $cageId, string $reason, ?int $userId = null, ?string $userName = null, string $action = 'archive'): bool
{
    $stmt = $con->prepare("UPDATE cages SET status = 'archived' WHERE cage_id = ? AND status = 'active'");
    $stmt->bind_param("s", $cageId);
    $stmt->execute();
    $retired = $stmt->affected_rows === 1;
    $stmt->close();
    if (!$retired) {
        return false;
    }

    $rem = $con->prepare("DELETE FROM reminders WHERE cage_id = ?");
    $rem->bind_param("s", $cageId);
    $rem->execute();
    $rem->close();
    log_cage_lifecycle($con, $cageId, 'retired', $reason, $userId, $userName);
    log_activity($con, $action, 'cage', $cageId, $reason, $userId);
    return true;
}

// Put an archived cage back in service (administrators). Only a cage that
// still houses active animals can be restored, e.g. after an exit recorded
// by mistake was corrected; the reason is kept in the cage lifecycle and the
// activity log.
function restore_cage(mysqli $con, string $cageId, string $reason, ?int $userId = null, ?string $userName = null): void
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new Exception(tr('Give the reason for putting the cage back in service.'));
    }
    if (cage_active_animal_count($con, $cageId) === 0) {
        throw new Exception(tr('Cage {id} has no active animal: an empty cage cannot be put back in service.', ['id' => $cageId]));
    }
    $stmt = $con->prepare("UPDATE cages SET status = 'active' WHERE cage_id = ? AND status = 'archived'");
    $stmt->bind_param("s", $cageId);
    $stmt->execute();
    $restored = $stmt->affected_rows === 1;
    $stmt->close();
    if (!$restored) {
        throw new Exception(tr('Cage {id} is not archived; nothing to restore.', ['id' => $cageId]));
    }
    log_cage_lifecycle($con, $cageId, 'restored', $reason, $userId, $userName);
    log_activity($con, 'restore', 'cage', $cageId, tr('Cage restored from archive.') . ' ' . $reason, $userId);
}

// Cages due on $date that have not been seen, grouped by room ('' = no room).
function unseen_cages_by_room(mysqli $con, string $date): array
{
    $checks = cage_checks_for_date($con, $date);
    $byRoom = [];
    foreach (cages_due_on($con, $date) as $c) {
        if (empty($checks[$c['cage_id']])) {
            $byRoom[$c['room'] ?? ''][] = $c['cage_id'];
        }
    }
    ksort($byRoom);
    return $byRoom;
}

// Exit codes (motifs de sortie): how an animal leaves the facility when it
// does not move to another cage. code => [label, resulting mice.status].
const ANIMAL_EXIT_CODES = [
    'mam_population' => ['Mis à mort – gestion de population', 'sacrificed'],
    'mam_experimentation' => ['Mis à mort en expérimentation', 'sacrificed'],
    'euthanasie' => ['Euthanasie', 'sacrificed'],
    'point_limite' => ['Atteinte de point limite', 'sacrificed'],
    'retrouve_mort' => ['Retrouvé mort', 'dead'],
    'retrouve_mort_experimentation' => ['Retrouvé mort en expérimentation', 'dead'],
    'export' => ['Export vers une autre animalerie', 'transferred_out'],
];

// Origin of an animal on the entry / exit register.
const ANIMAL_ORIGINS = [
    'internal_birth' => 'Born in the facility',
    'supplier' => 'Supplier / authorised breeder',
    'other_facility' => 'Other user establishment',
    'other' => 'Other origin',
    // Set only by a capture in a population (terrarium_view.php).
    'population_capture' => 'Captured from a facility population',
];

// ANIMAL_ORIGINS in the interface language.
function animal_origins(): array
{
    return array_map('tr', ANIMAL_ORIGINS);
}

// Origin and entry date read from a form (fields origin_type, origin_detail,
// origin_reference, entry_date). Returns [error message or null, origin].
// The supplier or establishment is required for an animal coming from
// outside; the entry date defaults to the date of birth for an animal born
// here, and to today otherwise.
function origin_from_input(array $in, ?string $dob): array
{
    $type = (string) ($in['origin_type'] ?? '');
    $origin = [
        'origin_type' => $type,
        'origin_detail' => trim((string) ($in['origin_detail'] ?? '')) ?: null,
        'origin_reference' => trim((string) ($in['origin_reference'] ?? '')) ?: null,
        'entry_date' => trim((string) ($in['entry_date'] ?? '')) ?: ($type === 'internal_birth' && $dob ? $dob : date('Y-m-d')),
    ];
    if (!isset(ANIMAL_ORIGINS[$type]) || $type === 'population_capture') {
        return [tr('Choose the origin of the animal(s).'), $origin];
    }
    if ($type !== 'internal_birth' && $origin['origin_detail'] === null) {
        return [tr('Give the supplier or establishment the animal(s) come from.'), $origin];
    }
    $date = DateTime::createFromFormat('Y-m-d', $origin['entry_date']);
    if (!$date || $date->format('Y-m-d') !== $origin['entry_date'] || $origin['entry_date'] > date('Y-m-d')) {
        return [tr('The entry date is not valid.'), $origin];
    }
    if ($dob && $origin['entry_date'] < $dob) {
        return [tr('The entry date cannot be before the date of birth.'), $origin];
    }
    return [null, $origin];
}

// Record the origin of existing animals (completing the register).
function set_animal_origin(mysqli $con, int $animalId, array $origin, ?int $userId): void
{
    $stmt = $con->prepare("UPDATE mice SET origin_type = ?, origin_detail = ?, origin_reference = ?, entry_date = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $origin['origin_type'], $origin['origin_detail'], $origin['origin_reference'], $origin['entry_date'], $animalId);
    $stmt->execute();
    $stmt->close();
    log_animal_event($con, $animalId, 'note', ['origin' => $origin], $userId);
    log_activity($con, 'set_origin', 'animal', (string) $animalId, 'Origin: ' . $origin['origin_type'] . ($origin['origin_detail'] ? ' — ' . $origin['origin_detail'] : '') . ', entry ' . $origin['entry_date'], $userId);
}

// "Origin — detail (reference)" of an animal, or null when not recorded.
function origin_label(array $animal): ?string
{
    if (empty($animal['origin_type'])) {
        return null;
    }
    return (animal_origins()[$animal['origin_type']] ?? $animal['origin_type'])
        . (!empty($animal['origin_detail']) ? ' — ' . $animal['origin_detail'] : '')
        . (!empty($animal['origin_reference']) ? ' (' . $animal['origin_reference'] . ')' : '');
}

// Form fields for the origin and entry date of new animals. $values holds
// previously submitted values; $allowInternal offers "born in the facility".
function animal_origin_fields(array $values = [], bool $allowInternal = false): string
{
    $type = $values['origin_type'] ?? '';
    $options = '<option value="" ' . ($type === '' ? 'selected' : '') . ' disabled>' . trh('Choose…') . '</option>';
    foreach (animal_origins() as $key => $label) {
        if (($key === 'internal_birth' && !$allowInternal) || $key === 'population_capture') {
            continue;
        }
        $options .= '<option value="' . $key . '"' . ($type === $key ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
    }
    $v = fn(string $k): string => htmlspecialchars((string) ($values[$k] ?? ''));
    return '<fieldset class="border rounded p-2 mb-3 origin-fields">'
        . '<legend class="float-none w-auto px-2 fs-6 mb-0"><i class="fas fa-sign-in-alt"></i> ' . trh('Entry into the facility (register)') . '</legend>'
        . '<div class="row g-2">'
        . '<div class="col-md-6"><label class="form-label" for="origin_type">' . trh('Origin') . ' <span class="text-danger">*</span></label>'
        . '<select class="form-select" id="origin_type" name="origin_type" required>' . $options . '</select></div>'
        . '<div class="col-md-6"><label class="form-label" for="origin_detail">' . trh('Supplier / establishment') . ' <span class="text-danger origin-detail-required">*</span></label>'
        . '<input class="form-control" id="origin_detail" name="origin_detail" maxlength="255" value="' . $v('origin_detail') . '" placeholder="' . trh('e.g. Janvier Labs, Inserm U123') . '"></div>'
        . '<div class="col-md-6"><label class="form-label" for="origin_reference">' . trh('Reference') . '</label>'
        . '<input class="form-control" id="origin_reference" name="origin_reference" maxlength="100" value="' . $v('origin_reference') . '" placeholder="' . trh('delivery note…') . '"></div>'
        . '<div class="col-md-6"><label class="form-label" for="entry_date">' . trh('Entry date') . ' <span class="text-danger">*</span></label>'
        . '<input class="form-control" type="date" id="entry_date" name="entry_date" max="' . date('Y-m-d') . '" value="' . ($v('entry_date') ?: date('Y-m-d')) . '" required></div>'
        . '</div></fieldset>'
        . '<script>(function () {'
        . 'var t = document.getElementById("origin_type"), d = document.getElementById("origin_detail"), mark = document.querySelector(".origin-detail-required");'
        . 'function sync() { var ext = t.value && t.value !== "internal_birth"; d.required = !!ext; mark.style.visibility = ext ? "visible" : "hidden"; }'
        . 't.addEventListener("change", sync); sync();'
        . '})();</script>';
}

// Label of an animal's exit: its exit code, or its status for exits
// recorded before exit codes existed.
function exit_label(?string $code, string $status): string
{
    return ANIMAL_EXIT_CODES[$code][0] ?? tr(str_replace('_', ' ', $status));
}

// Move an active animal to another active cage, with its movement history.
// Throws on invalid moves. Retires the source cage if it is left empty unless
// $retireEmptySource is false. Returns true when the source cage was retired.
function move_animal(mysqli $con, int $animalId, string $toCageId, ?string $reason, ?int $userId, ?string $userName, bool $retireEmptySource = true): bool
{
    $stmt = $con->prepare("SELECT cage_id, status, animal_uid FROM mice WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $animalId);
    $stmt->execute();
    $animal = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$animal) {
        throw new Exception(tr('Animal not found.'));
    }
    if ($animal['status'] !== 'active') {
        throw new Exception(tr('Only living animals can be moved.'));
    }
    $check = $con->prepare("SELECT 1 FROM cages WHERE cage_id = ? AND status = 'active'");
    $check->bind_param("s", $toCageId);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();
    if (!$exists) {
        throw new Exception(tr('Destination cage does not exist or is retired.'));
    }
    $fromCageId = $animal['cage_id'];
    if ($fromCageId === $toCageId) {
        throw new Exception(tr('The animal is already in that cage.'));
    }

    $update = $con->prepare("UPDATE mice SET cage_id = ? WHERE id = ?");
    $update->bind_param("si", $toCageId, $animalId);
    $update->execute();
    $update->close();

    log_animal_movement($con, $animalId, $fromCageId, $toCageId, $userId, $reason ?: null);
    log_animal_event($con, $animalId, 'transferred', ['from_cage_id' => $fromCageId, 'to_cage_id' => $toCageId, 'reason' => $reason], $userId);
    // An animal under experiment makes its new cage an experimental cage.
    mark_cages_experimental_for_animals($con, [$animalId], $userId);

    return $retireEmptySource && retire_cage_if_empty($con, $fromCageId, tr('last animal transferred to {cage}', ['cage' => $toCageId]), $userId, $userName);
}

// Recalculate the cached number of living animals in a cage. Call this after
// any bulk operation; individual movements also use this through the bulk
// helper below.
function refresh_cage_quantity(mysqli $con, string $cageId): void
{
    $stmt = $con->prepare("UPDATE cages SET quantity = (SELECT COUNT(*) FROM mice WHERE cage_id = ? AND status = 'active') WHERE cage_id = ?");
    $stmt->bind_param("ss", $cageId, $cageId);
    $stmt->execute();
    $stmt->close();
}

// Allocate an experiment number while the caller holds a transaction.
// The row lock prevents duplicate numbers when several users create an
// experiment at the same time.
function allocate_experiment_number(mysqli $con): string
{
    $stmt = $con->prepare('SELECT next_seq FROM experiment_sequence WHERE id = 1 FOR UPDATE');
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new Exception(tr('Experiment number sequence is not initialized.'));
    }
    $number = sprintf('EXP-%05d', (int) $row['next_seq']);
    $update = $con->prepare('UPDATE experiment_sequence SET next_seq = next_seq + 1 WHERE id = 1');
    $update->execute();
    $update->close();
    return $number;
}

// Move several living animals as one operation. The caller must already have
// opened a transaction. Validation happens before the first update, so a bad
// selection cannot leave a partially moved group. Empty source cages are
// retired only after every selected animal has moved.
function move_animals(mysqli $con, array $animalIds, string $toCageId, ?string $reason, ?int $userId, ?string $userName): array
{
    $animalIds = array_values(array_unique(array_filter(array_map('intval', $animalIds), static fn($id) => $id > 0)));
    if (!$animalIds) {
        throw new Exception(tr('Select at least one animal.'));
    }

    $destination = $con->prepare("SELECT 1 FROM cages WHERE cage_id = ? AND status = 'active'");
    $destination->bind_param("s", $toCageId);
    $destination->execute();
    $destinationExists = $destination->get_result()->num_rows > 0;
    $destination->close();
    if (!$destinationExists) {
        throw new Exception(tr('Destination cage does not exist or is retired.'));
    }

    $sources = [];
    $labels = [];
    $check = $con->prepare("SELECT cage_id, status, animal_uid FROM mice WHERE id = ? FOR UPDATE");
    foreach ($animalIds as $animalId) {
        $check->bind_param("i", $animalId);
        $check->execute();
        $animal = $check->get_result()->fetch_assoc();
        if (!$animal || $animal['status'] !== 'active') {
            $check->close();
            throw new Exception(tr('Every selected animal must be living and active.'));
        }
        if ($animal['cage_id'] === $toCageId) {
            $check->close();
            throw new Exception(tr('An animal is already in the destination cage.'));
        }
        $sources[$animal['cage_id']] = true;
        $labels[] = $animal['animal_uid'] ?: ('#' . $animalId);
    }
    $check->close();

    foreach ($animalIds as $animalId) {
        move_animal($con, $animalId, $toCageId, $reason, $userId, $userName, false);
    }
    refresh_cage_quantity($con, $toCageId);
    foreach (array_keys($sources) as $sourceCageId) {
        refresh_cage_quantity($con, $sourceCageId);
        retire_cage_if_empty($con, $sourceCageId, tr('all remaining animals transferred to {cage}', ['cage' => $toCageId]), $userId, $userName);
    }

    return ['count' => count($animalIds), 'labels' => $labels, 'source_cages' => array_keys($sources)];
}

// Record an animal leaving the facility with an exit code (see
// ANIMAL_EXIT_CODES; an export needs the destination facility). The animal
// keeps its last cage_id for traceability and its open experiment
// enrollments are closed. Retires the cage if it is left empty unless
/**
 * What makes an animal part of the regulatory record, as a list of reasons
 * (empty for an animal created by mistake, with nothing recorded after its
 * creation). An animal with a history is never deleted, only given an exit;
 * the database enforces the same rule (trigger mice_no_delete_with_history).
 */
function animal_regulatory_history(mysqli $con, int $animalId): array
{
    $exists = fn(string $sql, string $types, array $params): bool => db_value($con, "SELECT EXISTS ($sql)", $types, $params) == 1;
    $one = [$animalId];
    $checks = [
        tr('an exit is recorded') => ["SELECT 1 FROM mice WHERE id = ? AND status <> 'active'", 'i', $one],
        tr('included in an experiment') => ['SELECT 1 FROM experiment_animals WHERE animal_id = ?', 'i', $one],
        tr('health report') => ['SELECT 1 FROM health_reports WHERE animal_id = ?', 'i', $one],
        tr('prescription or administration of a medication') => ['SELECT 1 FROM prescription_animals WHERE animal_id = ? UNION SELECT 1 FROM medication_administrations WHERE animal_id = ?', 'ii', [$animalId, $animalId]],
        tr('breeder') => ['SELECT 1 FROM breeding_animals WHERE animal_id = ?', 'i', $one],
        tr('parent of a litter or of other animals') => ['SELECT 1 FROM litters WHERE sire_id = ? OR dam_id = ? UNION SELECT 1 FROM animal_parentage WHERE sire_id = ? OR dam_id = ?', 'iiii', [$animalId, $animalId, $animalId, $animalId]],
        tr('born from a litter weaned in the facility') => ['SELECT 1 FROM animal_parentage WHERE animal_id = ? AND litter_id IS NOT NULL', 'i', $one],
        tr('moved to another cage') => ['SELECT 1 FROM animal_movements WHERE animal_id = ? AND from_cage_id IS NOT NULL', 'i', $one],
        tr('register entry corrected') => ["SELECT 1 FROM record_corrections WHERE entity_type = 'animal' AND entity_id = ?", 'i', $one],
        tr('captured from a population') => ['SELECT 1 FROM terrarium_capture_animals WHERE animal_id = ?', 'i', $one],
        tr('events in its history (acts, alerts, status changes…)') => ["SELECT 1 FROM animal_events WHERE animal_id = ? AND event_type NOT IN ('created', 'note')", 'i', $one],
    ];
    $reasons = [];
    foreach ($checks as $label => [$sql, $types, $params]) {
        if ($exists($sql, $types, $params)) {
            $reasons[] = $label;
        }
    }
    return $reasons;
}

// $retireEmptyCage is false. Returns the cage id when it was retired.
function exit_animal(mysqli $con, int $animalId, string $code, string $date, string $reason, ?string $destination, ?int $userId, ?string $userName, bool $retireEmptyCage = true): ?string
{
    if (!isset(ANIMAL_EXIT_CODES[$code])) {
        throw new Exception(tr('Choose an exit code.'));
    }
    [$label, $status] = ANIMAL_EXIT_CODES[$code];
    if ($code === 'export' && trim((string) $destination) === '') {
        throw new Exception(tr('An export needs the destination facility.'));
    }
    $stmt = $con->prepare("SELECT cage_id, status, animal_uid FROM mice WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $animalId);
    $stmt->execute();
    $animal = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$animal) {
        throw new Exception(tr('Animal not found.'));
    }
    if ($animal['status'] !== 'active') {
        throw new Exception(tr('Animal {uid} has already left the facility.', ['uid' => $animal['animal_uid']]));
    }

    $destination = $code === 'export' ? trim($destination) : null;
    $update = $con->prepare("UPDATE mice SET status = ?, exit_code = ?, exit_date = ?, exit_reason = ?, exit_destination = ? WHERE id = ?");
    $update->bind_param("sssssi", $status, $code, $date, $reason, $destination, $animalId);
    $update->execute();
    $update->close();

    // The animal leaves any experiment it is still enrolled in.
    $open = $con->prepare("SELECT id, experiment_id FROM experiment_animals WHERE animal_id = ? AND removed_at IS NULL");
    $open->bind_param("i", $animalId);
    $open->execute();
    $enrollments = $open->get_result()->fetch_all(MYSQLI_ASSOC);
    $open->close();
    $close = $con->prepare("UPDATE experiment_animals SET removed_at = ?, removal_reason = ? WHERE id = ?");
    foreach ($enrollments as $en) {
        $removedAt = "$date 00:00:00";
        $close->bind_param("ssi", $removedAt, $label, $en['id']);
        $close->execute();
        log_animal_event($con, $animalId, 'experiment_end', ['experiment_id' => (int) $en['experiment_id'], 'reason' => $label], $userId);
    }
    $close->close();

    resolve_endpoint_actions_on_exit($con, $animalId, $label, $userId, $userName);

    log_animal_event($con, $animalId, 'status_change', ['status' => $status, 'exit_code' => $code, 'date' => $date, 'reason' => $reason, 'destination' => $destination], $userId);
    log_activity($con, 'exit', 'animal', (string) $animalId, "{$animal['animal_uid']}: $label" . ($destination ? " → $destination" : '') . ($reason !== '' ? " ($reason)" : ''), $userId);

    return ($retireEmptyCage && retire_cage_if_empty($con, $animal['cage_id'], tr('last animal exit: {label}', ['label' => $label]), $userId, $userName)) ? $animal['cage_id'] : null;
}

// Actions an endpoint (point limite) can require from the experimenter.
const ENDPOINT_REQUIRED_ACTIONS = [
    'none' => 'Alert only',
    'euthanasia' => 'Euthanasia (mise à mort)',
    'call_vet' => 'Call the veterinarian',
    'increase_monitoring' => 'Increase monitoring',
    'other' => 'Other action (see message)',
];

// ENDPOINT_REQUIRED_ACTIONS in the interface language.
function endpoint_required_actions(): array
{
    return array_map('tr', ENDPOINT_REQUIRED_ACTIONS);
}

// A clinical grid with its items (ordered) and each item's levels.
function load_clinical_grid(mysqli $con, int $gridId): ?array
{
    $stmt = $con->prepare("SELECT * FROM clinical_grids WHERE id = ?");
    $stmt->bind_param("i", $gridId);
    $stmt->execute();
    $grid = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$grid) {
        return null;
    }
    $items = $con->prepare("SELECT * FROM clinical_grid_items WHERE grid_id = ? ORDER BY position, id");
    $items->bind_param("i", $gridId);
    $items->execute();
    $grid['items'] = [];
    foreach ($items->get_result()->fetch_all(MYSQLI_ASSOC) as $item) {
        $levels = $con->prepare("SELECT score, description FROM clinical_grid_levels WHERE item_id = ? ORDER BY score");
        $levels->bind_param("i", $item['id']);
        $levels->execute();
        $item['levels'] = $levels->get_result()->fetch_all(MYSQLI_ASSOC);
        $levels->close();
        $grid['items'][(int) $item['id']] = $item;
    }
    $items->close();
    $grid['max_total'] = array_sum(array_map(fn($i) => $i['levels'] ? max(array_column($i['levels'], 'score')) : 0, $grid['items']));
    return $grid;
}

// A grid is read-only once a follow-up scored it or a protocol threshold
// points at one of its items (changes go to a duplicate).
function clinical_grid_locked(mysqli $con, int $gridId): bool
{
    $stmt = $con->prepare(
        "SELECT (EXISTS (SELECT 1 FROM experiment_followup_scores s JOIN clinical_grid_items i ON i.id = s.grid_item_id WHERE i.grid_id = ?)
              OR EXISTS (SELECT 1 FROM protocol_endpoint_thresholds t JOIN clinical_grid_items i ON i.id = t.grid_item_id WHERE i.grid_id = ?))"
    );
    $stmt->bind_param("ii", $gridId, $gridId);
    $stmt->execute();
    $stmt->bind_result($locked);
    $stmt->fetch();
    $stmt->close();
    return (bool) $locked;
}

// Human-readable condition of an endpoint threshold.
function threshold_label(array $t, ?string $itemLabel = null): string
{
    // $itemLabel: the grid item (item_score) or observation field (observation).
    $metric = [
        'weight_loss_pct' => tr('Weight loss %'),
        'clinical_score' => tr('Total clinical score'),
        'item_score' => tr('Score "{item}"', ['item' => $itemLabel ?? '?']),
        'observation' => '"' . ($itemLabel ?? '?') . '"',
        'custom' => tr('Custom'),
    ][$t['metric']] ?? $t['metric'];
    return $metric . ' ' . $t['operator'] . ' ' . rtrim(rtrim((string) $t['value'], '0'), '.');
}

// Close the pending endpoint actions of an animal that left the facility
// (euthanasia done, or the action became moot).
function resolve_endpoint_actions_on_exit(mysqli $con, int $animalId, string $exitLabel, ?int $userId, ?string $userName): void
{
    $note = tr('Animal left the facility: {label}', ['label' => $exitLabel]);
    $stmt = $con->prepare(
        "UPDATE endpoint_actions a JOIN experiment_animals ea ON ea.id = a.experiment_animal_id
         SET a.status = 'done', a.done_at = NOW(), a.done_by = ?, a.done_by_name = ?, a.done_note = ?
         WHERE ea.animal_id = ? AND a.status = 'pending'"
    );
    $stmt->bind_param("issi", $userId, $userName, $note, $animalId);
    $stmt->execute();
    $stmt->close();
}

// Observation fields a protocol asks for at every daily follow-up, by id
// (only active ones unless $includeInactive, e.g. for reports and endpoints).
function protocol_observation_fields(mysqli $con, int $protocolId, bool $includeInactive = false): array
{
    $stmt = $con->prepare("SELECT * FROM protocol_observation_fields WHERE protocol_id = ?" . ($includeInactive ? '' : ' AND active = 1') . " ORDER BY position, id");
    $stmt->bind_param("i", $protocolId);
    $stmt->execute();
    $fields = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
        $fields[(int) $f['id']] = $f;
    }
    $stmt->close();
    return $fields;
}

// Parse a typed number, accepting a decimal comma ("37,5"); null if not a number.
function parse_number(?string $raw): ?float
{
    $raw = str_replace([' ', ','], ['', '.'], trim((string) $raw));
    return is_numeric($raw) ? (float) $raw : null;
}

// Display value of a follow-up observation (number trimmed, with its unit).
function observation_value(array $o): string
{
    if ($o['value_type'] === 'number') {
        $n = $o['value_number'] === null ? '' : rtrim(rtrim(number_format((float) $o['value_number'], 4, '.', ''), '0'), '.');
        return trim($n . ' ' . ($o['unit'] ?? ''));
    }
    return (string) ($o['value_text'] ?? '');
}

// Label of the grid item or observation field a threshold refers to.
function threshold_target_label(array $t, ?array $grid, array $obsFields): ?string
{
    if ($t['metric'] === 'item_score') {
        return $grid['items'][(int) $t['grid_item_id']]['label'] ?? null;
    }
    if ($t['metric'] === 'observation' && isset($obsFields[(int) $t['observation_field_id']])) {
        $f = $obsFields[(int) $t['observation_field_id']];
        return $f['label'] . ($f['unit'] ? " ({$f['unit']})" : '');
    }
    return null;
}

// Litters (portées): birth -> nursing -> weaning.

// Weaning age in days (Manage Lab setting, default 21).
function weaning_age_days(mysqli $con, ?int $lineId = null): int
{
    return weaning_age_source($con, $lineId)[0];
}

// Weaning age of a line and where it comes from: [days, 'line' | 'species' |
// 'lab']. The line's own age, else its species', else the laboratory's.
function weaning_age_source(mysqli $con, ?int $lineId = null): array
{
    if ($lineId) {
        $row = db_row(
            $con,
            "SELECT l.weaning_age_days AS line_days, s.weaning_age_days AS species_days
             FROM animal_lines l LEFT JOIN species_weaning_ages s ON s.species = l.species WHERE l.id = ?",
            'i',
            [$lineId]
        );
        if ((int) ($row['line_days'] ?? 0) > 0) {
            return [(int) $row['line_days'], 'line'];
        }
        if ((int) ($row['species_days'] ?? 0) > 0) {
            return [(int) $row['species_days'], 'species'];
        }
    }
    $row = $con->query("SELECT value FROM settings WHERE name = 'weaning_age_days'")->fetch_assoc();
    $days = (int) ($row['value'] ?? 0);
    return [$days > 0 ? $days : 21, 'lab'];
}

// Line of a litter's pups for its weaning age: the dam's, else the sire's.
function litter_line_id(mysqli $con, ?int $damId, ?int $sireId): ?int
{
    foreach ([$damId, $sireId] as $parentId) {
        $line = $parentId ? db_value($con, "SELECT line_id FROM mice WHERE id = ?", 'i', [$parentId]) : null;
        if ($line) {
            return (int) $line;
        }
    }
    return null;
}

// Pups of a litter still alive: born alive minus the deaths recorded since.
function litter_alive_now(mysqli $con, array $litter): int
{
    $stmt = $con->prepare("SELECT COALESCE(SUM(count), 0) FROM litter_events WHERE litter_id = ? AND event = 'death'");
    $stmt->bind_param("i", $litter['id']);
    $stmt->execute();
    $stmt->bind_result($deaths);
    $stmt->fetch();
    $stmt->close();
    return max(0, (int) $litter['pups_alive'] - (int) $deaths);
}

function log_litter_event(mysqli $con, int $litterId, string $event, string $date, ?int $count, ?int $males, ?int $females, ?string $note, ?int $userId, ?string $userName): void
{
    $stmt = $con->prepare("INSERT INTO litter_events (litter_id, event, event_date, count, males, females, note, user_id, user_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issiiisis", $litterId, $event, $date, $count, $males, $females, $note, $userId, $userName);
    $stmt->execute();
    $stmt->close();
}

// Nursing litters of a cage: such a cage must not be retired (the pups are
// not individual animals yet).
function cage_nursing_litters(mysqli $con, string $cageId): int
{
    $stmt = $con->prepare("SELECT COUNT(*) FROM litters WHERE cage_id = ? AND status = 'nursing'");
    $stmt->bind_param("s", $cageId);
    $stmt->execute();
    $stmt->bind_result($n);
    $stmt->fetch();
    $stmt->close();
    return (int) $n;
}

/**
 * Weaning reminders (called by process_reminders.php, once a day after the
 * morning reminder time of Manage Lab, 07:30 by default):
 *  - litters to wean today or tomorrow: to the users of their cage (to the
 *    administrators when the cage has none);
 *  - litters whose weaning is overdue: to the users of their cage and to
 *    the administrators and BEA responsibles.
 * One notification and one e-mail per person. Returns the number of people
 * notified.
 */
function litters_send_weaning_reminders(mysqli $con, ?string $now = null): int
{
    $now ??= date('Y-m-d H:i');
    $today = substr($now, 0, 10);
    $time = (string) (db_value($con, "SELECT value FROM settings WHERE name = 'acts_reminder_time'") ?? '07:30');
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        $time = '07:30';
    }
    $last = (string) (db_value($con, "SELECT value FROM settings WHERE name = 'weaning_reminders_last'") ?? '');
    if ($last === $today || substr($now, 11, 5) < $time) {
        return 0;
    }
    db_execute($con, "INSERT INTO settings (name, value) VALUES ('weaning_reminders_last', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", 's', [$today]);

    $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
    $litters = db_rows(
        $con,
        "SELECT l.id, l.cage_id, l.litter_dob, l.expected_weaning_date FROM litters l JOIN cages c ON c.cage_id = l.cage_id
         WHERE l.status = 'nursing' AND l.expected_weaning_date IS NOT NULL AND l.expected_weaning_date <= ? AND c.status = 'active'
         ORDER BY l.expected_weaning_date, l.cage_id",
        's',
        [$tomorrow]
    );
    if (!$litters) {
        return 0;
    }
    $cageUsers = [];
    foreach (db_rows($con, 'SELECT cu.cage_id, cu.user_id FROM cage_users cu JOIN users u ON u.id = cu.user_id WHERE u.status = ?', 's', ['approved']) as $cu) {
        $cageUsers[$cu['cage_id']][] = (int) $cu['user_id'];
    }
    $admins = array_map('intval', array_column(db_rows($con, "SELECT id FROM users WHERE status = 'approved' AND role = 'admin'"), 'id'));
    $privileged = array_map('intval', array_column(db_rows($con, "SELECT id FROM users WHERE status = 'approved' AND (role = 'admin' OR is_bea_responsible = 1)"), 'id'));

    // [user id => ['due' => [lines], 'overdue' => [lines], 'cages' => [cage ids]]]
    $byUser = [];
    foreach ($litters as $l) {
        $kind = $l['expected_weaning_date'] < $today ? 'overdue' : 'due';
        $line = tr('cage {cage}, born {dob}, weaning expected {date}', ['cage' => $l['cage_id'], 'dob' => $l['litter_dob'] ?? '?', 'date' => $l['expected_weaning_date']]);
        $recipients = $cageUsers[$l['cage_id']] ?? [];
        if ($kind === 'overdue') {
            $recipients = array_merge($recipients, $privileged);
        } elseif (!$recipients) {
            $recipients = $admins;
        }
        foreach (array_unique($recipients) as $userId) {
            $byUser[$userId][$kind][] = $line;
            $byUser[$userId]['cages'][$l['cage_id']] = true;
        }
    }

    $emails = array_column(db_rows($con, "SELECT id, username FROM users WHERE status = 'approved'"), 'username', 'id');
    $notified = 0;
    foreach ($byUser as $userId => $kinds) {
        if (!isset($emails[$userId])) {
            continue;
        }
        $nDue = count($kinds['due'] ?? []);
        $nOverdue = count($kinds['overdue'] ?? []);
        $title = $nOverdue
            ? tr('{n} litter(s): weaning overdue', ['n' => $nOverdue]) . ($nDue ? ' · ' . tr('{n} to wean today or tomorrow', ['n' => $nDue]) : '')
            : tr('{n} litter(s) to wean today or tomorrow', ['n' => $nDue]);
        $lines = [];
        foreach (['overdue' => tr('Weaning overdue'), 'due' => tr('To wean today or tomorrow')] as $kind => $heading) {
            if (!empty($kinds[$kind])) {
                $lines[] = $heading . ' :';
                foreach ($kinds[$kind] as $line) {
                    $lines[] = '• ' . $line;
                }
            }
        }
        $cages = array_keys($kinds['cages']);
        $link = count($cages) === 1 ? 'litter_manage.php?cage_id=' . rawurlencode((string) $cages[0]) : 'home.php#littersToWean';
        create_notification($con, (int) $userId, $title, implode("\n", array_slice($lines, 0, 12)), $link, 'reminder');
        $body = nl2br(htmlspecialchars(implode("\n", $lines))) . '<br><br>' . htmlspecialchars(tr('Record the weaning on the litter page (breeding cage → Litters: birth → weaning).'));
        db_execute($con, 'INSERT INTO outbox (recipient, subject, body, scheduled_at) VALUES (?, ?, ?, NOW())', 'sss', [$emails[$userId], $title, $body]);
        $notified++;
    }
    return $notified;
}

// Create an active holding cage (auto cage_id) with its lifecycle, users and
// creation check. Returns the new cage_id. Caller handles the transaction.
function create_holding_cage(mysqli $con, ?int $piId, ?string $room, ?string $rack, ?string $dob, array $userIds, string $why, ?int $userId, ?string $userName): string
{
    $cageId = allocate_cage_id($con);
    $stmt = $con->prepare("INSERT INTO cages (cage_id, pi_name, quantity, room, rack) VALUES (?, ?, 0, ?, ?)");
    $stmt->bind_param("siss", $cageId, $piId, $room, $rack);
    $stmt->execute();
    $stmt->close();
    $h = $con->prepare("INSERT INTO holding (cage_id, dob) VALUES (?, ?)");
    $dob = $dob ?: date('Y-m-d');
    $h->bind_param("ss", $cageId, $dob);
    $h->execute();
    $h->close();
    foreach ($userIds as $uid) {
        $u = $con->prepare("INSERT INTO cage_users (cage_id, user_id) VALUES (?, ?)");
        $uid = (int) $uid;
        $u->bind_param("si", $cageId, $uid);
        $u->execute();
        $u->close();
    }
    log_cage_lifecycle($con, $cageId, 'created', $why, $userId, $userName);
    record_cage_check($con, $cageId, 'creation', 'ok', 'auto', $why, null, $userId, $userName);
    log_activity($con, 'create', 'cage', $cageId, $why, $userId);
    return $cageId;
}

// Create one identified animal (line UID) in an active cage, with optional
// parentage, its initial placement and creation event. Returns its id.
// $origin (see origin_from_input()) feeds the entry / exit register; an
// animal weaned from a litter is recorded as born in the facility.
function create_animal(mysqli $con, string $cageId, int $lineId, ?string $sex, ?string $dob, ?array $parentage, string $why, ?int $userId, ?array $origin = null): int
{
    if (!$origin && !empty($parentage['litter_id'])) {
        $origin = ['origin_type' => 'internal_birth', 'origin_detail' => null, 'origin_reference' => tr('Litter #{id}', ['id' => $parentage['litter_id']]), 'entry_date' => $dob ?: date('Y-m-d')];
    }
    $uid = allocate_animal_uid($con, $lineId);
    $originType = $origin['origin_type'] ?? null;
    $originDetail = $origin['origin_detail'] ?? null;
    $originReference = $origin['origin_reference'] ?? null;
    $entryDate = $origin['entry_date'] ?? date('Y-m-d');
    $stmt = $con->prepare("INSERT INTO mice (cage_id, line_id, animal_uid, sex, dob, entry_date, origin_type, origin_detail, origin_reference) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sisssssss", $cageId, $lineId, $uid, $sex, $dob, $entryDate, $originType, $originDetail, $originReference);
    $stmt->execute();
    $animalId = $con->insert_id;
    $stmt->close();
    if ($parentage) {
        $p = $con->prepare("INSERT INTO animal_parentage (animal_id, litter_id, sire_id, dam_id) VALUES (?, ?, ?, ?)");
        $p->bind_param("iiii", $animalId, $parentage['litter_id'], $parentage['sire_id'], $parentage['dam_id']);
        $p->execute();
        $p->close();
    }
    log_animal_movement($con, $animalId, null, $cageId, $userId, $why);
    log_animal_event($con, $animalId, 'created', ['cage_id' => $cageId, 'animal_uid' => $uid, 'sex' => $sex, 'reason' => $why], $userId);
    $q = $con->prepare("UPDATE cages SET quantity = (SELECT COUNT(*) FROM mice WHERE cage_id = ? AND status = 'active') WHERE cage_id = ?");
    $q->bind_param("ss", $cageId, $cageId);
    $q->execute();
    $q->close();
    return $animalId;
}

// Cage categories: label, dashboard, cage page. A breeding cage has a breeding
// record; an experimental cage is a holding cage flagged is_experimental.
const CAGE_KINDS = [
    'holding' => ['Cages d’hébergement', 'hc_dash.php', 'hc_view.php'],
    'breeding' => ['Cages d’accouplement', 'bc_dash.php', 'bc_view.php'],
    'experimental' => ['Cages d’expérimentation', 'ec_dash.php', 'hc_view.php'],
];

// SQL expression giving the category of the cage aliased c.
const CAGE_KIND_SQL = "CASE WHEN EXISTS (SELECT 1 FROM breeding b WHERE b.cage_id = c.cage_id) THEN 'breeding'
    WHEN c.is_experimental = 1 THEN 'experimental' ELSE 'holding' END";

function cage_kind(mysqli $con, string $cageId): string
{
    $stmt = $con->prepare("SELECT " . CAGE_KIND_SQL . " FROM cages c WHERE c.cage_id = ?");
    $stmt->bind_param("s", $cageId);
    $stmt->execute();
    $kind = $stmt->get_result()->fetch_row()[0] ?? 'holding';
    $stmt->close();
    return $kind;
}

// Move cages to another room (and rack). An empty rack keeps each cage's
// current rack. Returns the number of cages actually changed.
function move_cages_to_room(mysqli $con, array $cageIds, string $room, ?string $rack, ?int $userId): int
{
    $select = $con->prepare("SELECT room, rack FROM cages WHERE cage_id = ? AND status = 'active'");
    $update = $con->prepare("UPDATE cages SET room = ?, rack = ? WHERE cage_id = ?");
    $changed = 0;
    foreach (array_unique($cageIds) as $cageId) {
        $select->bind_param("s", $cageId);
        $select->execute();
        $current = $select->get_result()->fetch_assoc();
        if (!$current) {
            continue;
        }
        $newRack = ($rack === null || $rack === '') ? $current['rack'] : $rack;
        if ($current['room'] === $room && $current['rack'] === $newRack) {
            continue;
        }
        $update->bind_param("sss", $room, $newRack, $cageId);
        $update->execute();
        $from = trim(($current['room'] ?? '—') . ' / ' . ($current['rack'] ?? '—'));
        log_activity($con, 'move_room', 'cage', $cageId, "Salle/rack : $from → $room / " . ($newRack ?? '—'), $userId);
        $changed++;
    }
    $select->close();
    $update->close();
    return $changed;
}

/**
 * Change the ownership of active cages (administrators, from the cage
 * dashboards' group selection): their responsible (PI) and/or their users.
 *  - $piId: new responsible, null to leave it unchanged;
 *  - $userIds with $usersMode: 'add' (added to the current users),
 *    'replace' (become the only users), 'remove' (taken off), 'keep'.
 * The responsible must be an approved user with the Principal Investigator
 * position; the users must be approved. Each changed cage is logged with
 * its responsible and users before and after. Returns the number of cages
 * changed. Throws on invalid input.
 */
function set_cages_ownership(mysqli $con, array $cageIds, ?int $piId, array $userIds, string $usersMode, ?int $byUserId): int
{
    if (!in_array($usersMode, ['keep', 'add', 'replace', 'remove'], true)) {
        throw new Exception(tr('Unknown action.'));
    }
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if ($usersMode !== 'keep' && $usersMode !== 'replace' && !$userIds) {
        throw new Exception(tr('Choose the users to add or remove.'));
    }
    if ($piId === null && $usersMode === 'keep') {
        throw new Exception(tr('Choose a new responsible or the users to change.'));
    }
    if ($piId !== null && !db_value($con, "SELECT 1 FROM users WHERE id = ? AND status = 'approved' AND position = 'Principal Investigator'", 'i', [$piId])) {
        throw new Exception(tr('The responsible must be an approved user with the Principal Investigator position.'));
    }
    if ($userIds) {
        $valid = array_map('intval', array_column(db_rows($con, "SELECT id FROM users WHERE status = 'approved' AND id IN (" . db_placeholders(count($userIds)) . ")", str_repeat('i', count($userIds)), $userIds), 'id'));
        if (count($valid) !== count($userIds)) {
            throw new Exception(tr('Only approved users can be assigned to a cage.'));
        }
    }
    $names = array_column(db_rows($con, 'SELECT id, name FROM users'), 'name', 'id');
    $describe = function (?int $pi, array $users) use ($names): string {
        sort($users);
        return tr('responsible: {pi}; users: {users}', [
            'pi' => $pi ? ($names[$pi] ?? "#$pi") : '—',
            'users' => $users ? implode(', ', array_map(fn($u) => $names[$u] ?? "#$u", $users)) : '—',
        ]);
    };

    $changed = 0;
    foreach (array_unique($cageIds) as $cageId) {
        $cage = db_row($con, "SELECT pi_name FROM cages WHERE cage_id = ? AND status = 'active' FOR UPDATE", 's', [$cageId]);
        if (!$cage) {
            continue;
        }
        $oldPi = $cage['pi_name'] !== null ? (int) $cage['pi_name'] : null;
        $oldUsers = array_map('intval', array_column(db_rows($con, 'SELECT user_id FROM cage_users WHERE cage_id = ?', 's', [$cageId]), 'user_id'));
        $newPi = $piId ?? $oldPi;
        $newUsers = match ($usersMode) {
            'add' => array_values(array_unique(array_merge($oldUsers, $userIds))),
            'replace' => $userIds,
            'remove' => array_values(array_diff($oldUsers, $userIds)),
            default => $oldUsers,
        };
        $sortedOld = $oldUsers;
        $sortedNew = $newUsers;
        sort($sortedOld);
        sort($sortedNew);
        if ($newPi === $oldPi && $sortedOld === $sortedNew) {
            continue;
        }
        if ($newPi !== $oldPi) {
            db_execute($con, 'UPDATE cages SET pi_name = ? WHERE cage_id = ?', 'is', [$newPi, $cageId]);
        }
        foreach (array_diff($oldUsers, $newUsers) as $u) {
            db_execute($con, 'DELETE FROM cage_users WHERE cage_id = ? AND user_id = ?', 'si', [$cageId, $u]);
        }
        foreach (array_diff($newUsers, $oldUsers) as $u) {
            db_execute($con, 'INSERT INTO cage_users (cage_id, user_id) VALUES (?, ?)', 'si', [$cageId, $u]);
        }
        log_activity($con, 'cage_ownership', 'cage', $cageId, $describe($oldPi, $oldUsers) . ' → ' . $describe($newPi, $newUsers), $byUserId);
        $changed++;
    }
    return $changed;
}

// Switch holding cages between the holding and experimental categories.
// Breeding cages are left untouched. Returns the number of cages changed.
function set_cages_experimental(mysqli $con, array $cageIds, bool $experimental, ?int $userId): int
{
    $flag = $experimental ? 1 : 0;
    $stmt = $con->prepare("UPDATE cages c SET c.is_experimental = ? WHERE c.cage_id = ? AND c.status = 'active' AND c.is_experimental <> ?
        AND NOT EXISTS (SELECT 1 FROM breeding b WHERE b.cage_id = c.cage_id)");
    $changed = 0;
    foreach (array_unique($cageIds) as $cageId) {
        $stmt->bind_param("isi", $flag, $cageId, $flag);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            log_activity($con, 'set_category', 'cage', $cageId, $experimental ? 'Cage d’expérimentation' : 'Cage d’hébergement', $userId);
            $changed++;
        }
    }
    $stmt->close();
    return $changed;
}

// A cage becomes an experimental cage as soon as one of its living animals is
// in an ongoing experiment (enrollment not closed). It keeps its cage number;
// breeding cages are left as they are. Returns the cages reclassified.
function mark_cages_experimental_for_animals(mysqli $con, array $animalIds, ?int $userId): array
{
    $animalIds = array_values(array_unique(array_filter(array_map('intval', $animalIds))));
    if (!$animalIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($animalIds), '?'));
    $stmt = $con->prepare("SELECT DISTINCT m.cage_id FROM mice m
        JOIN experiment_animals ea ON ea.animal_id = m.id AND ea.removed_at IS NULL
        JOIN cages c ON c.cage_id = m.cage_id AND c.status = 'active' AND c.is_experimental = 0
        WHERE m.id IN ($in) AND m.status = 'active'");
    $stmt->bind_param(str_repeat('i', count($animalIds)), ...$animalIds);
    $stmt->execute();
    $cageIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'cage_id');
    $stmt->close();
    $changed = [];
    foreach ($cageIds as $cageId) {
        if (set_cages_experimental($con, [$cageId], true, $userId)) {
            $changed[] = $cageId;
        }
    }
    return $changed;
}
