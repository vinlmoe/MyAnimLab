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
        throw new Exception("Unknown animal line.");
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
        throw new Exception("Cage sequence not initialized.");
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
    return '<span class="badge bg-' . $color . '">' . htmlspecialchars($value) . '</span>';
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

// All checks of the given day, grouped by cage_id.
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
    return $byCage;
}

// Active cages (holding and breeding) with their room/rack and kind.
function active_cages(mysqli $con): array
{
    return $con->query(
        "SELECT c.cage_id, c.room, c.rack,
                IF(EXISTS (SELECT 1 FROM breeding b WHERE b.cage_id = c.cage_id), 'breeding', 'holding') AS kind
         FROM cages c WHERE c.status = 'active' ORDER BY c.room, c.rack, c.cage_id"
    )->fetch_all(MYSQLI_ASSOC);
}

// Short badge describing one cage check.
function cage_check_badge(array $check): string
{
    $color = $check['result'] === 'ok' ? 'success' : 'danger';
    $label = ['routine' => 'round', 'experimental' => 'exp. follow-up', 'health_report' => 'health report'][$check['check_type']] ?? $check['check_type'];
    $text = date('H:i', strtotime($check['checked_at'])) . ' ' . $label . ($check['method'] === 'qr' ? ' (QR)' : '')
        . ' · ' . ($check['checked_by_name'] ?? '?');
    return '<span class="badge bg-' . $color . ' me-1" title="' . htmlspecialchars($check['note'] ?? '') . '">' . htmlspecialchars($text) . '</span>';
}

// Only allow redirecting back to the daily-check pages (avoids open redirects).
function safe_check_return(?string $return): ?string
{
    return ($return && preg_match('/^(daily_round|cage_check)\.php(\?[A-Za-z0-9_\-=&%.]*)?$/', $return)) ? $return : null;
}
