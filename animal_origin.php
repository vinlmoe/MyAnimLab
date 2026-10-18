<?php

/**
 * Record the entry into the facility of an existing animal (date and origin)
 * for the entry / exit register. Anyone can complete a missing origin; once
 * recorded, only an administrator can correct it, with a reason: the values
 * replaced are kept (corrections_lib.php).
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';
require_once 'corrections_lib.php';

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}
if (!csrf_valid()) {
    die(tr('CSRF token validation failed'));
}

$animalId = (int) ($_POST['animal_id'] ?? 0);
$back = safe_local_return($_POST['return'] ?? null, ['animal_origin.php']) ?? "animal_view.php?id=$animalId#register";

$stmt = $con->prepare("SELECT id, animal_uid, dob, origin_type, origin_detail, origin_reference, entry_date FROM mice WHERE id = ?");
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
    } elseif ($animal['origin_type']) {
        // Correction of a recorded entry (administrator): reason required.
        mysqli_begin_transaction($con);
        try {
            correct_animal_entry($con, $animal, $origin, (string) ($_POST['correction_reason'] ?? ''), $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? null);
            mysqli_commit($con);
            $_SESSION['message'] = tr('Entry of {animal} corrected; the values replaced are kept.', ['animal' => $animal['animal_uid'] ?? ('#' . $animalId)]);
        } catch (Exception $e) {
            mysqli_rollback($con);
            $_SESSION['message'] = tr('Entry not corrected: {error}', ['error' => error_message($e)]);
        }
    } else {
        set_animal_origin($con, $animalId, $origin, $_SESSION['user_id'] ?? null);
        $_SESSION['message'] = tr('Entry into the facility recorded for {animal}.', ['animal' => $animal['animal_uid'] ?? ('#' . $animalId)]);
    }
}
header("Location: $back");
exit;
