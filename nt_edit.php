<?php

/**
 * Edit Note Script
 *
 * Updates the text of a note (POST note_id, note_text, csrf_token). Only the
 * author of the note or an administrator can edit it. Answers in JSON.
 */

session_start();
require 'dbcon.php';
require 'notes_lib.php';

$userId = note_request_user();
$noteId = (int) ($_POST['note_id'] ?? 0);
$noteText = trim((string) ($_POST['note_text'] ?? ''));
if ($noteText === '') {
    note_fail(tr('Invalid request.'));
}
note_require_owner($con, $noteId, $userId);

$stmt = $con->prepare('UPDATE notes SET note_text = ? WHERE id = ?');
$stmt->bind_param('si', $noteText, $noteId);
echo json_encode($stmt->execute()
    ? ['success' => true, 'message' => tr('Note updated successfully.')]
    : ['success' => false, 'message' => tr('Failed to update note.')]);
$stmt->close();
