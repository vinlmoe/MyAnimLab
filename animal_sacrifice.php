<?php

/**
 * Mark an active animal as sacrificed — a dedicated action independent of
 * cage removal (unlike hc_drop.php/bc_drop.php, which only archive animals
 * as a side effect of deleting their cage).
 */

session_start();
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$currentUserId = $_SESSION['user_id'] ?? null;
$animalId = (int) ($_GET['id'] ?? $_POST['animal_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    $sacrificeDate = $_POST['sacrifice_date'] ?: date('Y-m-d');
    $reason = trim($_POST['reason'] ?? '');

    $stmt = $con->prepare("SELECT status, animal_uid FROM mice WHERE id = ?");
    $stmt->bind_param("i", $animalId);
    $stmt->execute();
    $animal = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$animal) {
        $_SESSION['message'] = "Animal not found.";
    } elseif ($animal['status'] !== 'active') {
        $_SESSION['message'] = "Only active animals can be sacrificed.";
    } else {
        $update = $con->prepare("UPDATE mice SET status = 'sacrificed', exit_date = ?, exit_reason = ? WHERE id = ?");
        $update->bind_param("ssi", $sacrificeDate, $reason, $animalId);
        $update->execute();
        $update->close();

        log_animal_event($con, $animalId, 'status_change', ['status' => 'sacrificed', 'date' => $sacrificeDate, 'reason' => $reason], $currentUserId);
        log_activity($con, 'sacrifice', 'animal', (string) $animalId, "{$animal['animal_uid']}: $reason", $currentUserId);

        $_SESSION['message'] = "Animal {$animal['animal_uid']} marked as sacrificed.";
    }

    header("Location: animal_view.php?id=$animalId");
    exit();
}

require 'header.php';

$stmt = $con->prepare("SELECT id, animal_uid, status FROM mice WHERE id = ?");
$stmt->bind_param("i", $animalId);
$stmt->execute();
$animal = $stmt->get_result()->fetch_assoc();

if (!$animal) {
    echo "<div class='container mt-4'><div class='alert alert-danger'>Animal not found.</div></div>";
    require 'footer.php';
    exit;
}
?>

<!doctype html>
<html lang="en">

<head>
    <title>Sacrifice Animal | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-4 content" style="max-width: 600px;">
        <h4>Sacrifice Animal <?= htmlspecialchars($animal['animal_uid']); ?></h4>
        <p class="text-muted">This records the animal as sacrificed. Its full record (parentage, cage history, experiments) remains permanently accessible.</p>

        <?php include('message.php'); ?>

        <?php if ($animal['status'] !== 'active') : ?>
            <div class="alert alert-warning">This animal is not currently active (status: <?= htmlspecialchars($animal['status']); ?>).</div>
        <?php else : ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="animal_id" value="<?= (int) $animal['id']; ?>">
                <div class="mb-3">
                    <label class="form-label">Sacrifice Date</label>
                    <input type="date" class="form-control" name="sacrifice_date" value="<?= date('Y-m-d'); ?>" max="<?= date('Y-m-d'); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Reason</label>
                    <textarea class="form-control" name="reason" required></textarea>
                </div>
                <button type="submit" class="btn btn-danger" onclick="return confirm('Confirm this animal was sacrificed?');">Confirm Sacrifice</button>
                <a href="animal_view.php?id=<?= (int) $animal['id']; ?>" class="btn btn-secondary">Cancel</a>
            </form>
        <?php endif; ?>
    </div>

    <br>
    <?php require 'footer.php'; ?>
</body>

</html>
