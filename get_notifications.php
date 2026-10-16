<?php

/**
 * Return the current user's most recent notifications as JSON, for the
 * notification bell in header.php.
 */

session_start();
require 'dbcon.php';

header('Content-Type: application/json');

if (!isset($_SESSION['username'], $_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => tr('Not authenticated')]);
    exit;
}

$userId = (int) $_SESSION['user_id'];

$stmt = $con->prepare("SELECT id, title, message, link, type, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
$unread = 0;
while ($row = $result->fetch_assoc()) {
    $row['id'] = (int) $row['id'];
    $row['is_read'] = (int) $row['is_read'];
    if (!$row['is_read']) {
        $unread++;
    }
    $notifications[] = $row;
}

echo json_encode(['notifications' => $notifications, 'unread_count' => $unread]);
