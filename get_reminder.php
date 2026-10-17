<?php
/**
 * Get Reminder
 * 
 * This script handles AJAX requests to fetch a single reminder's data based on its ID.
 */

session_start();
require 'dbcon.php';

header('Content-Type: application/json');
if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => tr('You must be logged in.')]);
    exit;
}

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $con->prepare("SELECT * FROM reminders WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $reminder = $result->fetch_assoc();
        if ($reminder) {
            // Return the reminder details as JSON
            echo json_encode($reminder);
        } else {
            echo json_encode(['error' => tr('Reminder not found')]);
        }
    } else {
        echo json_encode(['error' => tr('Error executing query')]);
    }
    $stmt->close();
} else {
    echo json_encode(['error' => tr('Invalid request')]);
}
?>
