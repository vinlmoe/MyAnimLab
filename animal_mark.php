<?php

/**
 * Set or change an animal's distinctive mark (signe distinctif): ear punch,
 * tattoo, chip number, coat colour, scar... Every change is kept in the
 * animal's event log with the previous value.
 */

require_once 'session_config.php';
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

$animalId = (int) ($_POST['animal_id'] ?? 0);

if (!csrf_valid()) {
    die(tr('CSRF token validation failed'));
}

$mark = trim($_POST['distinctive_mark'] ?? '');
$mark = $mark === '' ? null : mb_substr($mark, 0, 255);

$stmt = $con->prepare("SELECT animal_uid, distinctive_mark FROM mice WHERE id = ?");
$stmt->bind_param("i", $animalId);
$stmt->execute();
$animal = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$animal) {
    $_SESSION['message'] = tr('Animal not found.');
    header("Location: animal_search.php?list=1");
    exit;
}

if ($mark !== $animal['distinctive_mark']) {
    $upd = $con->prepare("UPDATE mice SET distinctive_mark = ? WHERE id = ?");
    $upd->bind_param("si", $mark, $animalId);
    $upd->execute();
    $upd->close();
    log_animal_event($con, $animalId, 'note', ['distinctive_mark' => $mark, 'previous' => $animal['distinctive_mark']], $_SESSION['user_id'] ?? null);
    log_activity($con, 'distinctive_mark', 'animal', (string) $animalId, "{$animal['animal_uid']}: " . ($animal['distinctive_mark'] ?? '(none)') . " → " . ($mark ?? '(none)'), $_SESSION['user_id'] ?? null);
    $_SESSION['message'] = $mark ? tr('Distinctive mark saved.') : tr('Distinctive mark removed.');
}

header("Location: animal_view.php?id=$animalId");
exit;
