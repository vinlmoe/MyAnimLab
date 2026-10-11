<?php

/**
 * Restore an archived cage back to active status.
 */

session_start();
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

if (isset($_GET['id'], $_GET['confirm']) && $_GET['confirm'] === 'true') {
    $id = $_GET['id'];
    $currentUserId = $_SESSION['user_id'] ?? null;

    // Only an archived cage can be restored (keeps the lifecycle log consistent).
    $stmt = $con->prepare("UPDATE cages SET status = 'active' WHERE cage_id = ? AND status = 'archived'");
    $stmt->bind_param("s", $id);
    if ($stmt->execute() && $stmt->affected_rows === 1) {
        log_cage_lifecycle($con, $id, 'restored', null, $currentUserId, $_SESSION['name'] ?? null);
        log_activity($con, 'restore', 'cage', $id, 'Cage restored from archive.', $currentUserId);
        $_SESSION['message'] = "Cage $id restored successfully.";
    } else {
        $_SESSION['message'] = "Error restoring cage $id.";
    }
    $stmt->close();
} else {
    $_SESSION['message'] = 'Restore was not confirmed or ID parameter is missing.';
}

$redirectTo = $_GET['return'] ?? 'hc_dash.php';
$allowed = ['hc_dash.php', 'bc_dash.php'];
header("Location: " . (in_array($redirectTo, $allowed, true) ? $redirectTo : 'hc_dash.php'));
exit();
