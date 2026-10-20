<?php

/**
 * Delete Note Script
 *
 * Deletes a note (POST note_id, csrf_token). Only the author of the note or
 * an administrator can delete it. Answers in JSON.
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'notes_lib.php';

$userId = note_request_user();
$noteId = (int) ($_POST['note_id'] ?? 0);
note_require_owner($con, $noteId, $userId);

$stmt = $con->prepare('DELETE FROM notes WHERE id = ?');
$stmt->bind_param('i', $noteId);
echo json_encode($stmt->execute()
    ? ['success' => true, 'message' => tr('Note deleted successfully.')]
    : ['success' => false, 'message' => db_error_message($stmt->error)]);
$stmt->close();
