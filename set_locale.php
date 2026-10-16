<?php

require 'session_config.php';
require 'dbcon.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !hash_equals($_SESSION['locale_csrf_token'] ?? '', (string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid language change request.');
}

$locale = (string) ($_POST['locale'] ?? APP_DEFAULT_LOCALE);
if (!in_array($locale, APP_SUPPORTED_LOCALES, true)) {
    $locale = APP_DEFAULT_LOCALE;
}

set_current_locale($locale);

if (!empty($_SESSION['user_id'])) {
    $statement = $con->prepare('UPDATE users SET preferred_locale = ? WHERE id = ?');
    if ($statement) {
        $userId = (int) $_SESSION['user_id'];
        $statement->bind_param('si', $locale, $userId);
        $statement->execute();
        $statement->close();
    }
}

$returnTo = (string) ($_POST['return_to'] ?? 'home.php');
if (!preg_match('#^[a-zA-Z0-9][a-zA-Z0-9_./?=&%-]*$#', $returnTo)
    || str_contains($returnTo, '//') || str_contains($returnTo, '..')) {
    $returnTo = 'home.php';
}

header('Location: ' . $returnTo);
exit;
