<?php

/**
 * Former edit page of a holding cage, retired: cage changes are made from the
 * cage workflow pages (hc_view.php and the pages it links to). The URL is
 * kept so that bookmarks lead to the cage instead of an error.
 */

require_once 'session_config.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$cageId = trim((string) ($_GET['id'] ?? ''));
header('Location: ' . ($cageId !== '' ? 'hc_view.php?id=' . rawurlencode($cageId) : 'hc_dash.php'));
exit;
