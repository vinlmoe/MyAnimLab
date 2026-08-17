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
