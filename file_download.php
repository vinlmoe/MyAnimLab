<?php

/**
 * Download of a file attached to a cage, for a signed-in user (the files are
 * not reachable by URL, see cage_files_lib.php). The file is always sent as
 * an attachment with the content type of its extension, never displayed in
 * the application's pages.
 */

require_once 'session_config.php';
require_once 'cage_files_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$fileId = (int) ($_GET['id'] ?? 0);
$stmt = $con->prepare("SELECT file_name, file_path FROM files WHERE id = ?");
$stmt->bind_param("i", $fileId);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

$path = $file ? cage_file_disk_path($file['file_path']) : null;
if ($path === null) {
    http_response_code(404);
    exit(trh('File record not found.'));
}

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$name = $file['file_name'] !== '' ? $file['file_name'] : basename($path);
$asciiName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);

header('Content-Type: ' . (CAGE_FILE_TYPES[$extension] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, no-store');
readfile($path);
