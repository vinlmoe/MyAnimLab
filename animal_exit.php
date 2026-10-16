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
// On submit, the code chosen in the form wins over the ?code= preselection.
// No default: the exit code must be chosen explicitly.
$code = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['exit_code'] ?? '') : ($_GET['code'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(tr('CSRF token validation failed'));
    }

    $date = $_POST['exit_date'] ?: date('Y-m-d');
    $reason = trim($_POST['reason'] ?? '');
    $destination = trim($_POST['destination'] ?? '');

    mysqli_begin_transaction($con);
    try {
        $retiredCage = exit_animal($con, $animalId, $code, $date, $reason, $destination, $currentUserId, $_SESSION['name'] ?? null);
        mysqli_commit($con);
        $_SESSION['message'] = tr('Exit recorded: {label}.', ['label' => ANIMAL_EXIT_CODES[$code][0]])
            . ($retiredCage ? ' ' . tr('Cage {cage} is now empty and was retired automatically.', ['cage' => $retiredCage]) : '');
    } catch (Exception $e) {
        mysqli_rollback($con);
        $_SESSION['message'] = tr('Exit not recorded: {error}', ['error' => $e->getMessage()]);
        header("Location: animal_exit.php?" . http_build_query(['id' => $animalId, 'code' => $code]));
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
    echo "<div class='container mt-4'><div class='alert alert-danger'>" . trh('Animal not found.') . "</div></div>";
    require 'footer.php';
    exit;
}
$label = $animal['animal_uid'] ?? $animal['mouse_id'] ?? '#' . $animal['id'];
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Animal Exit') ?> | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-4 content" style="max-width: 600px;">
        <h4><?= trh('Exit of animal {uid}', ['uid' => $label]); ?></h4>
        <p class="text-muted"><?= trh('Records the animal leaving the facility (cage {cage}). Its full record (parentage, cage history, experiments, health) remains permanently accessible.', ['cage' => $animal['cage_id'] ?? tr('N/A')]); ?> <?= trh('To keep it in the facility, move it to another cage instead:') ?> <a href="animal_transfer.php?animal_id=<?= (int) $animal['id']; ?>"><?= trh('Transfer') ?></a>.</p>

        <?php include('message.php'); ?>

        <?php if ($animal['status'] !== 'active') : ?>
            <div class="alert alert-warning"><?= trh('This animal has already left the facility (status: {status}).', ['status' => tr(str_replace('_', ' ', $animal['status']))]); ?></div>
        <?php else : ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="animal_id" value="<?= (int) $animal['id']; ?>">
                <div class="mb-3">
                    <label class="form-label" for="exit_code"><?= trh('Exit code') ?></label>
                    <select class="form-select" id="exit_code" name="exit_code" required>
                        <option value="" disabled <?= isset(ANIMAL_EXIT_CODES[$code]) ? '' : 'selected'; ?>><?= trh('Choose…') ?></option>
                        <?php foreach (ANIMAL_EXIT_CODES as $key => [$text]) : ?>
                            <option value="<?= $key; ?>" <?= $key === $code ? 'selected' : ''; ?>><?= htmlspecialchars($text); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3" id="destinationGroup">
                    <label class="form-label" for="destination"><?= trh('Destination facility (for an export)') ?></label>
                    <input type="text" class="form-control" id="destination" name="destination" placeholder="<?= trh('e.g. Animalerie Institut X, Lyon') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="exit_date"><?= trh('Date') ?></label>
                    <input type="date" class="form-control" id="exit_date" name="exit_date" value="<?= date('Y-m-d'); ?>" max="<?= date('Y-m-d'); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="reason"><?= trh('Reason / circumstances') ?></label>
                    <textarea class="form-control" id="reason" name="reason" required></textarea>
                </div>
                <button type="submit" class="btn btn-danger" onclick="return confirm(<?= htmlspecialchars(trj('Confirm this animal leaves the facility?')) ?>);"><?= trh('Record exit') ?></button>
                <a href="animal_view.php?id=<?= (int) $animal['id']; ?>" class="btn btn-secondary"><?= trh('Cancel') ?></a>
            </form>
        <?php endif; ?>
    </div>

    <script>
        // The destination is only meaningful (and required) for an export.
        (function() {
            const type = document.getElementById('exit_code');
            const dest = document.getElementById('destination');
            if (!type || !dest) return;
            const sync = () => {
                const exp = type.value === 'export';
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
