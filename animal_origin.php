<?php

/**
 * Record the entry into the facility of an existing animal (date and origin)
 * for the entry / exit register. Anyone can complete a missing origin; once
 * recorded, only an administrator can correct it. Every change is kept in
 * the animal's event log.
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? null)) {
    die(tr('CSRF token validation failed'));
}

$animalId = (int) ($_POST['animal_id'] ?? 0);
$back = safe_local_return($_POST['return'] ?? null, ['animal_origin.php']) ?? "animal_view.php?id=$animalId#register";

$stmt = $con->prepare("SELECT id, animal_uid, dob, origin_type FROM mice WHERE id = ?");
$stmt->bind_param("i", $animalId);
$stmt->execute();
$animal = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$animal) {
    $_SESSION['message'] = tr('Animal not found.');
} elseif ($animal['origin_type'] && ($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['message'] = tr('The origin is already recorded: only an administrator can correct it.');
} else {
    [$error, $origin] = origin_from_input($_POST, $animal['dob']);
    if ($error) {
        $_SESSION['message'] = $error;
    } else {
        set_animal_origin($con, $animalId, $origin, $_SESSION['user_id'] ?? null);
        $_SESSION['message'] = tr('Entry into the facility recorded for {animal}.', ['animal' => $animal['animal_uid'] ?? ('#' . $animalId)]);
    }
}
header("Location: $back");
exit;
