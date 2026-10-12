<?php

/**
 * Record an animal leaving the facility: sacrificed, died / found dead, or
 * exported to another facility (destination required). The record stays
 * permanently accessible; the animal's cage is retired automatically if it
 * is left without animals.
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
// On submit, the fate chosen in the form wins over the ?type= preselection.
$type = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['exit_type'] ?? '') : ($_GET['type'] ?? 'sacrificed');
if (!isset(ANIMAL_EXIT_TYPES[$type])) {
    $type = 'sacrificed';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    $date = $_POST['exit_date'] ?: date('Y-m-d');
    $reason = trim($_POST['reason'] ?? '');
    $destination = trim($_POST['destination'] ?? '');

    mysqli_begin_transaction($con);
    try {
        $retiredCage = exit_animal($con, $animalId, $type, $date, $reason, $destination, $currentUserId, $_SESSION['name'] ?? null);
        mysqli_commit($con);
        $_SESSION['message'] = "Exit recorded: " . ANIMAL_EXIT_TYPES[$type] . "."
            . ($retiredCage ? " Cage $retiredCage is now empty and was retired automatically." : '');
    } catch (Exception $e) {
        mysqli_rollback($con);
        $_SESSION['message'] = "Exit not recorded: " . $e->getMessage();
        header("Location: animal_exit.php?" . http_build_query(['id' => $animalId, 'type' => $type]));
        exit;
    }

    header("Location: animal_view.php?id=$animalId");
    exit();
}

require 'header.php';

$stmt = $con->prepare("SELECT id, animal_uid, mouse_id, status, cage_id FROM mice WHERE id = ?");
$stmt->bind_param("i", $animalId);
$stmt->execute();
$animal = $stmt->get_result()->fetch_assoc();

if (!$animal) {
    echo "<div class='container mt-4'><div class='alert alert-danger'>Animal not found.</div></div>";
    require 'footer.php';
    exit;
}
$label = $animal['animal_uid'] ?? $animal['mouse_id'] ?? '#' . $animal['id'];
?>

<!doctype html>
<html lang="en">

<head>
    <title>Animal Exit | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-4 content" style="max-width: 600px;">
        <h4>Exit of animal <?= htmlspecialchars($label); ?></h4>
        <p class="text-muted">Records the animal leaving the facility (cage <?= htmlspecialchars($animal['cage_id'] ?? 'N/A'); ?>). Its full record (parentage, cage history, experiments, health) remains permanently accessible. To keep it in the facility, <a href="animal_transfer.php?animal_id=<?= (int) $animal['id']; ?>">move it to another cage</a> instead.</p>

        <?php include('message.php'); ?>

        <?php if ($animal['status'] !== 'active') : ?>
            <div class="alert alert-warning">This animal has already left the facility (status: <?= htmlspecialchars($animal['status']); ?>).</div>
        <?php else : ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="animal_id" value="<?= (int) $animal['id']; ?>">
                <div class="mb-3">
                    <label class="form-label" for="exit_type">Fate</label>
                    <select class="form-select" id="exit_type" name="exit_type">
                        <?php foreach (ANIMAL_EXIT_TYPES as $key => $text) : ?>
                            <option value="<?= $key; ?>" <?= $key === $type ? 'selected' : ''; ?>><?= htmlspecialchars($text); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3" id="destinationGroup">
                    <label class="form-label" for="destination">Destination facility (for an export)</label>
                    <input type="text" class="form-control" id="destination" name="destination" placeholder="e.g. Animalerie Institut X, Lyon">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="exit_date">Date</label>
                    <input type="date" class="form-control" id="exit_date" name="exit_date" value="<?= date('Y-m-d'); ?>" max="<?= date('Y-m-d'); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="reason">Reason / circumstances</label>
                    <textarea class="form-control" id="reason" name="reason" required></textarea>
                </div>
                <button type="submit" class="btn btn-danger" onclick="return confirm('Confirm this animal leaves the facility?');">Record exit</button>
                <a href="animal_view.php?id=<?= (int) $animal['id']; ?>" class="btn btn-secondary">Cancel</a>
            </form>
        <?php endif; ?>
    </div>

    <script>
        // The destination is only meaningful (and required) for an export.
        (function() {
            const type = document.getElementById('exit_type');
            const dest = document.getElementById('destination');
            if (!type || !dest) return;
            const sync = () => {
                const exp = type.value === 'transferred_out';
                document.getElementById('destinationGroup').style.display = exp ? '' : 'none';
                dest.required = exp;
            };
            type.addEventListener('change', sync);
            sync();
        })();
    </script>

    <br>
    <?php require 'footer.php'; ?>
</body>

</html>
