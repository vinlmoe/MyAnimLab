<?php

/**
 * Serve a regulatory PDF of a project authorization (authorization request,
 * ethics committee opinion, authorization decision) to signed-in users.
 * The files live in a private folder and are never linked directly.
 */

session_start();
require 'dbcon.php';
require 'ethics_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$doc = (string) ($_GET['doc'] ?? '');
$auth = load_authorization($con, (string) ($_GET['id'] ?? ''));
$stored = $auth && isset(ETHICS_DOCUMENTS[$doc]) ? ($auth[ETHICS_DOCUMENTS[$doc][1]] ?? null) : null;
$path = $stored && preg_match('/^[0-9a-f]{32}\.pdf$/', $stored) ? ethics_storage_dir() . '/' . $stored : null;

if (!$path || !is_file($path)) {
    http_response_code(404);
    exit(tr('Document not found.'));
}

$downloadName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $auth['iacuc_id'] . '_' . $doc) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
