<?php
/**
 * Cage room and category changes
 *
 * Receives one or many cage_ids[] (from a cage page or the cage dashboards'
 * group selection) and applies one action:
 *   - move:         move the cages to a room (and rack; empty keeps the rack)
 *   - experimental: classify holding cages as experimental cages
 *   - holding:      classify experimental cages back as holding cages
 * Then returns to the page that sent the form.
 */
require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
if (!csrf_valid()) {
    http_response_code(400);
    exit('Requête invalide.');
}

// Only return to a page of this application.
$return = (string) ($_POST['return'] ?? '');
if (!preg_match('/^[a-z_]+\.php(\?.*)?$/', $return)) {
    $return = 'hc_dash.php';
}

$cageIds = array_values(array_filter(array_map('trim', (array) ($_POST['cage_ids'] ?? [])), 'strlen'));
$action = $_POST['location_action'] ?? 'move';
$userId = $_SESSION['user_id'] ?? null;

if (!$cageIds) {
    $_SESSION['message'] = 'Sélectionnez au moins une cage.';
} elseif ($action === 'move') {
    $room = trim((string) ($_POST['room'] ?? ''));
    $rack = trim((string) ($_POST['rack'] ?? ''));
    if ($room === '') {
        $_SESSION['message'] = 'Indiquez la salle de destination.';
    } else {
        $con->begin_transaction();
        $n = move_cages_to_room($con, $cageIds, $room, $rack, $userId);
        $con->commit();
        $_SESSION['message'] = "$n cage(s) déplacée(s) vers la salle $room" . ($rack !== '' ? ", rack $rack" : '') . '.';
    }
} elseif ($action === 'experimental' || $action === 'holding') {
    $con->begin_transaction();
    $n = set_cages_experimental($con, $cageIds, $action === 'experimental', $userId);
    $con->commit();
    $_SESSION['message'] = "$n cage(s) classée(s) en " . ($action === 'experimental' ? 'cages d’expérimentation' : 'cages d’hébergement')
        . ($n < count($cageIds) ? ' (les cages d’accouplement et celles déjà classées sont ignorées)' : '') . '.';
} else {
    $_SESSION['message'] = 'Action inconnue.';
}

header('Location: ' . $return);
exit;
