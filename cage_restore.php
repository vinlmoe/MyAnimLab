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

    // A cage without animals is never in service (it is retired automatically),
    // so only an archived cage that still houses active animals can be restored.
    if (cage_active_animal_count($con, $id) === 0) {
        $_SESSION['message'] = tr('Cage {id} has no active animal: an empty cage cannot be put back in service.', ['id' => $id]);
        $stmt = null;
    } else {
        $stmt = $con->prepare("UPDATE cages SET status = 'active' WHERE cage_id = ? AND status = 'archived'");
        $stmt->bind_param("s", $id);
    }
    if ($stmt && $stmt->execute() && $stmt->affected_rows === 1) {
        log_cage_lifecycle($con, $id, 'restored', null, $currentUserId, $_SESSION['name'] ?? null);
        log_activity($con, 'restore', 'cage', $id, tr('Cage restored from archive.'), $currentUserId);
        $_SESSION['message'] = tr('Cage {id} restored successfully.', ['id' => $id]);
    } elseif ($stmt) {
        $_SESSION['message'] = tr('Cage {id} is not archived; nothing to restore.', ['id' => $id]);
    }
    $stmt?->close();
} else {
    $_SESSION['message'] = tr('Restore was not confirmed or ID parameter is missing.');
}

$redirectTo = $_GET['return'] ?? 'hc_dash.php';
$allowed = ['hc_dash.php', 'bc_dash.php'];
header("Location: " . (in_array($redirectTo, $allowed, true) ? $redirectTo : 'hc_dash.php'));
exit();
