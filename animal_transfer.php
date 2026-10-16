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
        die(tr('CSRF token validation failed'));
    }

    $animalIds = $_POST['animal_ids'] ?? [];
    $toCageId = trim($_POST['to_cage_id']);
    $reason = trim($_POST['reason'] ?? '');

    mysqli_begin_transaction($con);
    try {
        $result = move_animals($con, $animalIds, $toCageId, $reason, $currentUserId, $_SESSION['name'] ?? null);

        mysqli_commit($con);
        $_SESSION['message'] = "{$result['count']} animal(s) transferred to $toCageId. Empty source cages were retired automatically.";
    } catch (Exception $e) {
        mysqli_rollback($con);
        $_SESSION['message'] = tr('Transfer failed: {error}', ['error' => $e->getMessage()]);
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
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title>Transférer des animaux | <?php echo htmlspecialchars($labName); ?></title>
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
        <h4>Transférer des animaux vers une autre cage</h4>
        <p class="text-muted">Déplacez un ou plusieurs animaux existants sans les recréer. Chaque mouvement est conservé dans l’historique.</p>

        <?php include('message.php'); ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="mb-3">
                <label for="animal_ids" class="form-label">Animaux <span class="required-asterisk">*</span></label>
                <input type="search" class="form-control mb-2" id="animal_search" placeholder="Rechercher par identifiant ou cage actuelle" autocomplete="off">
                <select class="form-control" id="animal_ids" name="animal_ids[]" multiple size="12" required>
                    <?php while ($row = $animals->fetch_assoc()) : ?>
                        <option value="<?= (int) $row['id']; ?>" <?= (int) $row['id'] === $preselectedAnimalId ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($row['animal_uid']); ?> (<?= trh('currently in cage {cage}', ['cage' => $row['cage_id']]); ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
                <small class="form-text text-muted">Utilisez Cmd/Ctrl-clic pour sélectionner plusieurs animaux.</small>
            </div>

            <div class="mb-3">
                <label for="to_cage_id" class="form-label">Cage de destination <span class="required-asterisk">*</span></label>
                <select class="form-control" id="to_cage_id" name="to_cage_id" required>
                    <option value="" disabled selected>Sélectionner une cage</option>
                    <?php while ($row = $cages->fetch_assoc()) : ?>
                        <option value="<?= htmlspecialchars($row['cage_id']); ?>"><?= htmlspecialchars($row['cage_id']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="reason" class="form-label">Motif / Notes</label>
                <textarea class="form-control" id="reason" name="reason"></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Transférer les animaux sélectionnés</button>
        </form>
    </div>

    <br>
    <?php include 'footer.php'; ?>
    <script>
        const animalSearch = document.getElementById('animal_search');
        const animalSelect = document.getElementById('animal_ids');
        animalSearch.addEventListener('input', () => {
            const search = animalSearch.value.trim().toLowerCase();
            for (const option of animalSelect.options) {
                option.hidden = search !== '' && !option.text.toLowerCase().includes(search);
            }
        });
    </script>
</body>

</html>
