<?php

/** Route opened by printed cage QR codes: experimental cage follow-up first. */
require_once 'session_config.php';
require 'dbcon.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$cageId = trim($_GET['id'] ?? '');
$stmt = $con->prepare("SELECT 1 FROM experiment_animals ea JOIN mice m ON m.id = ea.animal_id JOIN experiments e ON e.id = ea.experiment_id WHERE m.cage_id = ? AND m.status = 'active' AND ea.removed_at IS NULL AND e.status IN ('planned', 'active') LIMIT 1");
$stmt->bind_param('s', $cageId);
$stmt->execute();
$hasExperimentalAnimals = $stmt->get_result()->num_rows > 0;
$stmt->close();

header('Location: ' . ($hasExperimentalAnimals
    ? 'experiment_cage_followup.php?' . http_build_query(['cage_id' => $cageId])
    : 'cage_check.php?' . http_build_query(['id' => $cageId, 'via' => 'qr'])));
exit;
