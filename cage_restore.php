<?php

/**
 * Put an archived cage back in service: administrators only, with a reason
 * (restore_cage). Reached from the "Retired cage" banner of the cage page.
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

$id = (string) ($_POST['id'] ?? '');
if (($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['message'] = tr('Only an administrator can put a cage back in service.');
} elseif (!csrf_valid() || $id === '' || ($_POST['confirm'] ?? '') !== 'true') {
    $_SESSION['message'] = tr('Restore was not confirmed or ID parameter is missing.');
} else {
    try {
        restore_cage($con, $id, (string) ($_POST['reason'] ?? ''), $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? null);
        $_SESSION['message'] = tr('Cage {id} restored successfully.', ['id' => $id]);
    } catch (Exception $e) {
        $_SESSION['message'] = error_message($e);
    }
}

$redirectTo = $_POST['return'] ?? 'hc_dash.php';
$allowed = ['hc_view.php', 'bc_view.php', 'hc_dash.php', 'bc_dash.php'];
$page = in_array($redirectTo, $allowed, true) ? $redirectTo : 'hc_dash.php';
header('Location: ' . $page . (str_ends_with($page, '_view.php') && $id !== '' ? '?id=' . rawurlencode($id) : ''));
exit();
