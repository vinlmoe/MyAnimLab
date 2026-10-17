<?php

/**
 * Shared checks of the sticky-note endpoints (nt_add.php, nt_edit.php,
 * nt_rmv.php): a signed-in user, a POST request and the session's CSRF token.
 * A note is changed or removed only by its author or an administrator.
 */

// Answer the request with a JSON error and stop.
function note_fail(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// Signed-in user id of a valid note request (POST with the CSRF token).
function note_request_user(): int
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['username'], $_SESSION['user_id'])) {
        note_fail(tr('You must be logged in to add a note.'), 401);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        note_fail(tr('Invalid request.'), 405);
    }
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        note_fail(tr('CSRF token validation failed'), 403);
    }
    return (int) $_SESSION['user_id'];
}

// Stop unless the note exists and the user is its author or an administrator.
function note_require_owner(mysqli $con, int $noteId, int $userId): void
{
    $stmt = $con->prepare('SELECT user_id FROM notes WHERE id = ?');
    $stmt->bind_param('i', $noteId);
    $stmt->execute();
    $note = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$note) {
        note_fail(tr('Invalid request.'), 404);
    }
    if ((int) $note['user_id'] !== $userId && ($_SESSION['role'] ?? '') !== 'admin') {
        note_fail(tr('Only the author of the note or an administrator can change it.'), 403);
    }
}
