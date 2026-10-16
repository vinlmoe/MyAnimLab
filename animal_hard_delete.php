<?php

/**
 * Admin-only, controlled permanent deletion of an animal record.
 *
 * This is the one deliberate exception to "never physically delete an
 * animal": requires the admin role, requires retyping the animal_uid to
 * confirm, and writes to activity_log *before* the DELETE so the audit
 * trail survives even though the row itself won't.
 */

session_start();
require 'dbcon.php';
require 'animal_lib.php';

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['message'] = tr('Access denied. Only admins can permanently delete animal records.');
    header("Location: home.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$currentUserId = $_SESSION['user_id'] ?? null;
$animalId = (int) ($_GET['id'] ?? $_POST['animal_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(tr('CSRF token validation failed'));
    }

    $confirmUid = trim($_POST['confirm_uid'] ?? '');

    $stmt = $con->prepare("SELECT animal_uid, cage_id FROM mice WHERE id = ?");
    $stmt->bind_param("i", $animalId);
    $stmt->execute();
    $animal = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$animal) {
        $_SESSION['message'] = tr('Animal not found.');
        header("Location: home.php");
        exit;
    }

    if ($confirmUid !== $animal['animal_uid']) {
        $_SESSION['message'] = tr('Confirmation text did not match the animal ID. Nothing was deleted.');
        header("Location: animal_hard_delete.php?id=$animalId");
        exit;
    }

    mysqli_begin_transaction($con);
    try {
        // Written before the delete so the audit trail survives it.
        log_activity($con, 'hard_delete', 'animal', (string) $animalId, tr('Permanently deleted {uid}', ['uid' => $animal['animal_uid']]), $currentUserId);

        $del = $con->prepare("DELETE FROM mice WHERE id = ?");
        $del->bind_param("i", $animalId);
        $del->execute();
        $del->close();

        $retired = retire_cage_if_empty($con, $animal['cage_id'], "last animal permanently deleted", $currentUserId, $_SESSION['name'] ?? null);

        mysqli_commit($con);
        $_SESSION['message'] = tr('Animal {uid} permanently deleted.', ['uid' => $animal['animal_uid']])
            . ($retired ? " Cage {$animal['cage_id']} is now empty and was retired automatically." : '');
    } catch (Exception $e) {
        mysqli_rollback($con);
        $_SESSION['message'] = tr('Error deleting animal: {error}', ['error' => $e->getMessage()]);
    }

    header("Location: home.php");
    exit;
}

require 'header.php';

$stmt = $con->prepare("SELECT id, animal_uid, status FROM mice WHERE id = ?");
$stmt->bind_param("i", $animalId);
$stmt->execute();
$animal = $stmt->get_result()->fetch_assoc();

if (!$animal) {
    echo "<div class='container mt-4'><div class='alert alert-danger'>" . trh('Animal not found.') . "</div></div>";
    require 'footer.php';
    exit;
}
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Permanently Delete Animal') ?> | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-4 content" style="max-width: 600px;">
        <h4 class="text-danger"><?= trh('Permanently Delete Animal') ?> <?= htmlspecialchars($animal['animal_uid']); ?></h4>
        <div class="alert alert-danger">
            <?= trh('This action is irreversible and bypasses the normal traceability rule (records are otherwise never deleted, only archived/sacrificed). Only use this for genuine data-entry errors, not for normal end-of-life tracking — use the exit page for that:') ?>

            <a href="animal_exit.php?id=<?= (int) $animal['id']; ?>"><?= trh('Exit (sacrifice / death / export)') ?></a>.
        </div>

        <?php include('message.php'); ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="animal_id" value="<?= (int) $animal['id']; ?>">
            <div class="mb-3">
                <label class="form-label"><?= tr('Type {uid} to confirm', ['uid' => '<code>' . htmlspecialchars($animal['animal_uid']) . '</code>']) ?></label>
                <input type="text" class="form-control" name="confirm_uid" required autocomplete="off">
            </div>
            <button type="submit" class="btn btn-danger" onclick="return confirm(<?= htmlspecialchars(trj('This is permanent. Continue?')) ?>);"><?= trh('Permanently Delete') ?></button>
            <a href="animal_view.php?id=<?= (int) $animal['id']; ?>" class="btn btn-secondary"><?= trh('Cancel') ?></a>
        </form>
    </div>

    <br>
    <?php require 'footer.php'; ?>
</body>

</html>
