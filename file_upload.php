<?php

/**
 * Add a file to a cage, from its page (form of render_cage_files()): a
 * user assigned to the cage or an administrator, by POST with the CSRF
 * token. The file is checked and stored by store_cage_file(), and the
 * upload is logged.
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require_once 'cage_files_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
// A request larger than post_max_size arrives without any field (nor the
// token): say the file is too large rather than refusing the token.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $_SESSION['message'] = tr('File size exceeds 10MB limit.');
    header('Location: ' . (safe_local_return($_SERVER['HTTP_REFERER'] ?? null, ['file_upload.php']) ?? 'hc_dash.php'));
    exit;
}
if (!csrf_valid()) {
    http_response_code(403);
    exit(trh('CSRF token validation failed'));
}

$cageId = trim((string) ($_POST['cage_id'] ?? ''));
$page = in_array($_POST['page'] ?? '', ['hc_view', 'bc_view'], true) ? $_POST['page'] : 'hc_view';

if (!db_value($con, 'SELECT 1 FROM cages WHERE cage_id = ?', 's', [$cageId])) {
    $_SESSION['message'] = tr('Cage not found.');
    header('Location: ' . ($page === 'bc_view' ? 'bc_dash.php' : 'hc_dash.php'));
    exit;
}

if (!cage_file_can_manage($con, $cageId)) {
    $_SESSION['message'] = tr('Only the users of the cage and the administrators can add or delete files.');
} else {
    [$fileId, $message] = store_cage_file($con, $cageId, $_FILES['fileUpload'] ?? []);
    if ($fileId !== null) {
        $name = (string) db_value($con, 'SELECT file_name FROM files WHERE id = ?', 'i', [$fileId]);
        log_activity($con, 'cage_file_added', 'cage', $cageId, $name, (int) $_SESSION['user_id']);
    }
    $_SESSION['message'] = $message;
}

header('Location: ' . $page . '.php?id=' . rawurlencode($cageId) . '#files');
exit;
