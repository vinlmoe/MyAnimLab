<?php

/**
 * Serve the certificate (PDF) of a training record entry to its owner, an
 * administrator or a BEA responsible. The files live in a private folder.
 */

session_start();
require 'dbcon.php';
require 'animal_lib.php';
require_once 'training_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$stmt = $con->prepare('SELECT r.user_id, r.certificate_file, r.title, u.name FROM training_records r JOIN users u ON u.id = r.user_id WHERE r.id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$entry = $stmt->get_result()->fetch_assoc();
$stmt->close();

$allowed = $entry && ((int) $entry['user_id'] === (int) ($_SESSION['user_id'] ?? 0) || is_bea_user($con));
$stored = $allowed ? $entry['certificate_file'] : null;
$path = $stored && preg_match('/^[0-9a-f]{32}\.pdf$/', $stored) ? training_storage_dir() . '/' . $stored : null;
if (!$path || !is_file($path)) {
    http_response_code(404);
    exit(tr('Document not found.'));
}

$downloadName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $entry['name'] . '_' . $entry['title']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
