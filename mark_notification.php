<?php

/**
 * Mark one (or all) of the current user's notifications as read.
 */

require_once 'session_config.php';
require 'dbcon.php';

header('Content-Type: application/json');

if (!isset($_SESSION['username'], $_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => tr('Not authenticated')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => tr('Method not allowed')]);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (!empty($input['all'])) {
    $stmt = $con->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
} else {
    $id = (int) ($input['id'] ?? 0);
    $stmt = $con->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id, $userId);
}

$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);
