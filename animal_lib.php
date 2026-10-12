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
    if ($cageId === null || $cageId === '' || cage_active_animal_count($con, $cageId) > 0) {
        return false;
    }
    return retire_cage($con, $cageId, "Automatically retired: no animal left ($why).", $userId, $userName, 'auto_retire');
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

// Label of an animal's exit: its exit code, or its status for exits
// recorded before exit codes existed.
function exit_label(?string $code, string $status): string
{
    return ANIMAL_EXIT_CODES[$code][0] ?? str_replace('_', ' ', $status);
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
        throw new Exception("Animal not found.");
    }
    if ($animal['status'] !== 'active') {
        throw new Exception("Only living animals can be moved.");
    }
    $check = $con->prepare("SELECT 1 FROM cages WHERE cage_id = ? AND status = 'active'");
    $check->bind_param("s", $toCageId);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();
    if (!$exists) {
        throw new Exception("Destination cage does not exist or is retired.");
    }
    $fromCageId = $animal['cage_id'];
    if ($fromCageId === $toCageId) {
        throw new Exception("The animal is already in that cage.");
    }

    $update = $con->prepare("UPDATE mice SET cage_id = ? WHERE id = ?");
    $update->bind_param("si", $toCageId, $animalId);
    $update->execute();
    $update->close();

    log_animal_movement($con, $animalId, $fromCageId, $toCageId, $userId, $reason ?: null);
    log_animal_event($con, $animalId, 'transferred', ['from_cage_id' => $fromCageId, 'to_cage_id' => $toCageId, 'reason' => $reason], $userId);

    return $retireEmptySource && retire_cage_if_empty($con, $fromCageId, "last animal transferred to $toCageId", $userId, $userName);
}

// Record an animal leaving the facility with an exit code (see
// ANIMAL_EXIT_CODES; an export needs the destination facility). The animal
// keeps its last cage_id for traceability and its open experiment
// enrollments are closed. Retires the cage if it is left empty unless
// $retireEmptyCage is false. Returns the cage id when it was retired.
function exit_animal(mysqli $con, int $animalId, string $code, string $date, string $reason, ?string $destination, ?int $userId, ?string $userName, bool $retireEmptyCage = true): ?string
{
    if (!isset(ANIMAL_EXIT_CODES[$code])) {
        throw new Exception("Choose an exit code.");
    }
    [$label, $status] = ANIMAL_EXIT_CODES[$code];
    if ($code === 'export' && trim((string) $destination) === '') {
        throw new Exception("An export needs the destination facility.");
    }
    $stmt = $con->prepare("SELECT cage_id, status, animal_uid FROM mice WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $animalId);
    $stmt->execute();
    $animal = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$animal) {
        throw new Exception("Animal not found.");
    }
    if ($animal['status'] !== 'active') {
        throw new Exception("Animal {$animal['animal_uid']} has already left the facility.");
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

    log_animal_event($con, $animalId, 'status_change', ['status' => $status, 'exit_code' => $code, 'date' => $date, 'reason' => $reason, 'destination' => $destination], $userId);
    log_activity($con, 'exit', 'animal', (string) $animalId, "{$animal['animal_uid']}: $label" . ($destination ? " → $destination" : '') . ($reason !== '' ? " ($reason)" : ''), $userId);

    return ($retireEmptyCage && retire_cage_if_empty($con, $animal['cage_id'], "last animal exit: $label", $userId, $userName)) ? $animal['cage_id'] : null;
}
