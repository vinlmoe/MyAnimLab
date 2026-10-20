<?php

/**
 * Add Note Script
 * 
 * This script handles the addition of a note to the database. It checks if the user is logged in, processes the form submission,
 * and inserts the note into the 'nt_data' table. The response is returned as JSON.
 * 
 */

require_once __DIR__ . '/session_config.php';
// Include the database connection file
include_once("dbcon.php");
require 'notes_lib.php';

// Signed-in user, POST request and CSRF token.
note_request_user();

// Handle form submission
$response = ['success' => false, 'message' => tr('Invalid request.')];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['note_text'])) {
    $note_text = $_POST['note_text'];
    $user_id = $_SESSION['user_id']; // Assuming 'username' is the user's identifier
    $cage = isset($_POST['cage_id']) ? $_POST['cage_id'] : null;

    // Prepare the SQL statement
    $sql = "INSERT INTO notes (user_id, note_text, cage_id) VALUES (?, ?, ?)";
    $stmt = $con->prepare($sql);
    if ($stmt === false) {
        $response['message'] = db_error_message($con->error);
        echo json_encode($response);
        exit;
    }

    // Bind parameters
    $stmt->bind_param("sss", $user_id, $note_text, $cage);

    // Execute the statement
    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = tr('Note added successfully.');
    } else {
        $response['message'] = db_error_message($stmt->error);
    }

    // Close the statement
    $stmt->close();
}

// Close the database connection
$con->close();

// Return the response as JSON
echo json_encode($response);
