<?php

/**
 * Transfer an existing animal to a different cage.
 *
 * Unlike hc_addn.php / bc_addn.php (which create a cage + animal together),
 * this page moves an already-existing, active animal from its current cage
 * to another one without re-creating it, and keeps a full movement history.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    $animalId = (int) $_POST['animal_id'];
    $toCageId = trim($_POST['to_cage_id']);
    $reason = trim($_POST['reason'] ?? '');

    mysqli_begin_transaction($con);
    try {
        $uidStmt = $con->prepare("SELECT animal_uid, cage_id FROM mice WHERE id = ?");
        $uidStmt->bind_param("i", $animalId);
        $uidStmt->execute();
        $animal = $uidStmt->get_result()->fetch_assoc() ?? ['animal_uid' => "#$animalId", 'cage_id' => null];
        $uidStmt->close();
        $fromCageId = $animal['cage_id'];

        $retired = move_animal($con, $animalId, $toCageId, $reason, $currentUserId, $_SESSION['name'] ?? null);

        mysqli_commit($con);
        $_SESSION['message'] = "Animal {$animal['animal_uid']} transferred from $fromCageId to $toCageId."
            . ($retired ? " Cage $fromCageId is now empty and was retired automatically." : '');
    } catch (Exception $e) {
        mysqli_rollback($con);
        $_SESSION['message'] = "Transfer failed: " . $e->getMessage();
    }

    header("Location: animal_transfer.php");
    exit();
}

require 'header.php';

$preselectedAnimalId = (int) ($_GET['animal_id'] ?? 0);
$animals = $con->query("SELECT id, animal_uid, mouse_id, cage_id FROM mice WHERE status = 'active' AND animal_uid IS NOT NULL ORDER BY animal_uid");
$cages = $con->query("SELECT cage_id FROM cages WHERE status = 'active' ORDER BY cage_id");
?>

<!doctype html>
<html lang="en">

<head>
    <title>Transfer Animal | <?php echo htmlspecialchars($labName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container {
            max-width: 700px;
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: auto;
        }

        .form-label {
            font-weight: bold;
        }

        .required-asterisk {
            color: red;
        }
    </style>
</head>

<body>
    <div class="container mt-4 content">
        <h4>Transfer Animal to Another Cage</h4>
        <p class="text-muted">Move an existing animal without re-creating it. The full cage history is preserved.</p>

        <?php include('message.php'); ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="mb-3">
                <label for="animal_id" class="form-label">Animal <span class="required-asterisk">*</span></label>
                <select class="form-control" id="animal_id" name="animal_id" required>
                    <option value="" disabled <?= $preselectedAnimalId ? '' : 'selected'; ?>>Select Animal</option>
                    <?php while ($row = $animals->fetch_assoc()) : ?>
                        <option value="<?= (int) $row['id']; ?>" <?= (int) $row['id'] === $preselectedAnimalId ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($row['animal_uid']); ?> (currently in cage <?= htmlspecialchars($row['cage_id']); ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="to_cage_id" class="form-label">Destination Cage <span class="required-asterisk">*</span></label>
                <select class="form-control" id="to_cage_id" name="to_cage_id" required>
                    <option value="" disabled selected>Select Cage</option>
                    <?php while ($row = $cages->fetch_assoc()) : ?>
                        <option value="<?= htmlspecialchars($row['cage_id']); ?>"><?= htmlspecialchars($row['cage_id']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="reason" class="form-label">Reason / Notes</label>
                <textarea class="form-control" id="reason" name="reason"></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Transfer Animal</button>
        </form>
    </div>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
