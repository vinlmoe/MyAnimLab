<?php
/**
 * Task Detail Fetcher
 * 
 * This script fetches the details of a specific task from the database based on the provided task ID.
 * It first checks if the user is logged in, then retrieves the task details from the database, and finally
 * returns the task information as a JSON response.
 * 
 */

// Start the session to use session variables
session_start();

// Include the database connection file
require 'dbcon.php';

// Initialize the response array
$response = [];

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => tr('You must be logged in.')]);
    exit;
}
$currentUserId = (string) ($_SESSION['user_id'] ?? '');
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

// Check if a valid task ID is provided via GET request
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    // Validate and sanitize the task ID
    $taskId = intval($_GET['id']);

    // Prepare the SQL statement to prevent SQL injection
    $stmt = $con->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->bind_param("i", $taskId);

    // Execute the query and get the result
    if ($stmt->execute()) {
        $result = $stmt->get_result();

        $task = $result->fetch_assoc();
        // Same visibility as manage_tasks.php: administrators, or the people
        // who assigned the task or are assigned to it.
        if ($task && !$isAdmin && (string) $task['assigned_by'] !== $currentUserId
            && !in_array($currentUserId, array_map('trim', explode(',', (string) $task['assigned_to'])), true)) {
            $task = null;
        }

        // Check if a matching task is found
        if ($task) {

            // Populate the response array with task data
            $response = [
                'id' => $task['id'],
                'title' => $task['title'],
                'description' => $task['description'],
                'assigned_by' => $task['assigned_by'],
                'assigned_to' => $task['assigned_to'],
                'status' => $task['status'],
                'completion_date' => $task['completion_date'],
                'creation_date' => $task['creation_date'],
                'cage_id' => $task['cage_id']
            ];
        } else {
            // Set error response if no matching task is found
            $response = ['error' => tr('Task not found.')];
        }

        // Close the statement
        $stmt->close();
    } else {
        // Set error response if the query execution fails
        $response = ['error' => tr('Error executing query: {error}', ['error' => $stmt->error])];
    }
} else {
    // Set error response if the task ID is invalid
    $response = ['error' => tr('Invalid task ID.')];
}

// Output the response as JSON
header('Content-Type: application/json');
echo json_encode($response);
?>
