<?php

/**
 * Delete a file of a cage (button of render_cage_files()): a user assigned
 * to the cage or an administrator, by POST with the CSRF token. The stored
 * file and its record are removed and the deletion is logged; then back to
 * the cage page (?url=hc_view / bc_view).
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require_once 'cage_files_lib.php';

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

// Deleting changes data: only by POST with the session's CSRF token.
if (!csrf_valid()) {
    http_response_code(403);
    exit(trh('CSRF token validation failed'));
}

$file = db_row($con, 'SELECT * FROM files WHERE id = ?', 'i', [(int) ($_GET['id'] ?? 0)]);
if (!$file) {
    http_response_code(404);
    exit(trh('File record not found.'));
}
$cageId = (string) $file['cage_id'];
$page = in_array($_GET['url'] ?? '', ['hc_view', 'bc_view'], true) ? $_GET['url'] : 'hc_view';

if (!cage_file_can_manage($con, $cageId)) {
    $_SESSION['message'] = tr('Only the users of the cage and the administrators can add or delete files.');
} else {
    // The stored file is deleted only inside uploads/ (cage_files_lib.php).
    $diskPath = cage_file_disk_path($file['file_path']);
    if ($diskPath !== null) {
        unlink($diskPath);
    }
    db_execute($con, 'DELETE FROM files WHERE id = ?', 'i', [(int) $file['id']]);
    log_activity($con, 'cage_file_deleted', 'cage', $cageId, $file['file_name'], (int) $_SESSION['user_id']);
    $_SESSION['message'] = tr('File deleted.');
}

header('Location: ' . $page . '.php?id=' . rawurlencode($cageId) . '#files');
exit;
