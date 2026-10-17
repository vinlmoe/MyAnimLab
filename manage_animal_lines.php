<?php

/**
 * Manage Animal Lines
 *
 * CRUD for animal lines (lineage code + species), the reference data used to
 * build unique animal IDs such as RSD-0002 (Rat Sprague Dawley) or
 * RY139-0023 (Rat Y139). Modeled on manage_strain.php.
 */

session_start();
require 'dbcon.php';

if (!isset($_SESSION['username'])) {
    header('Location: index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
// Reference data shared by the whole facility: administrators only (as in the menu).
if (($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['message'] = tr('Admin only.');
    header('Location: home.php');
    exit;
}
require 'header.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(tr('CSRF token validation failed'));
    }

    if (isset($_POST['add'])) {
        $code = strtoupper(trim($_POST['code']));
        $species = trim($_POST['species']);
        $name = trim($_POST['name']);
        $notes = trim($_POST['notes']);

        $checkStmt = $con->prepare("SELECT COUNT(*) FROM animal_lines WHERE code = ?");
        $checkStmt->bind_param("s", $code);
        $checkStmt->execute();
        $checkStmt->bind_result($count);
        $checkStmt->fetch();
        $checkStmt->close();

        if ($count > 0) {
            $_SESSION['message'] = tr('Error: Line code already exists.');
        } else {
            $stmt = $con->prepare("INSERT INTO animal_lines (code, species, name, notes) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $code, $species, $name, $notes);
            if ($stmt->execute()) {
                $_SESSION['message'] = tr('Animal line added successfully.');
            } else {
                $_SESSION['message'] = tr('Error adding animal line.');
            }
            $stmt->close();
        }
    } elseif (isset($_POST['edit'])) {
        $id = (int) $_POST['id'];
        $species = trim($_POST['species']);
        $name = trim($_POST['name']);
        $notes = trim($_POST['notes']);

        $stmt = $con->prepare("UPDATE animal_lines SET species = ?, name = ?, notes = ? WHERE id = ?");
        $stmt->bind_param("sssi", $species, $name, $notes, $id);
        if ($stmt->execute()) {
            $_SESSION['message'] = tr('Animal line updated successfully.');
        } else {
            $_SESSION['message'] = tr('Error updating animal line.');
        }
        $stmt->close();
    } elseif (isset($_POST['delete'])) {
        $id = (int) $_POST['id'];

        $checkStmt = $con->prepare("SELECT COUNT(*) FROM mice WHERE line_id = ?");
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $checkStmt->bind_result($count);
        $checkStmt->fetch();
        $checkStmt->close();

        if ($count > 0) {
            $_SESSION['message'] = tr('Error: This line is used by existing animals and cannot be deleted.');
        } else {
            $stmt = $con->prepare("DELETE FROM animal_lines WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $_SESSION['message'] = tr('Animal line deleted successfully.');
            } else {
                $_SESSION['message'] = tr('Error deleting animal line.');
            }
            $stmt->close();
        }
    }
}

$linesResult = $con->query("SELECT * FROM animal_lines ORDER BY code");
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= trh('Manage Animal Lines') ?> | <?php echo htmlspecialchars($labName); ?></title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .popup-form {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: white;
            padding: 20px;
            border: 2px solid #000;
            z-index: 1000;
            box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.5);
            width: 80%;
            max-width: 600px;
        }

        .popup-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .form-buttons {
            display: flex;
            gap: 10px;
            justify-content: space-between;
        }

        .required-asterisk {
            color: red;
        }

        .add-button {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 20px;
        }
    </style>
</head>

<body>
    <div class="container content mt-5">
        <h2><?= trh('Manage Animal Lines') ?></h2>
        <p class="text-muted"><?= tr('Each line’s code is the prefix used to build unique animal IDs (e.g. code {code} → {uid}).', ['code' => '<code>RSD</code>', 'uid' => '<code>RSD-0002</code>']) ?></p>

        <?php if (isset($_SESSION['message'])) : ?>
            <div class="alert alert-info">
                <?= htmlspecialchars($_SESSION['message']); ?>
                <?php unset($_SESSION['message']); ?>
            </div>
        <?php endif; ?>

        <div class="add-button">
            <button onclick="openForm()" class="btn btn-primary"><i class="fas fa-plus"></i> <?= trh('Add New Line') ?></button>
        </div>

        <div class="popup-overlay" id="popupOverlay"></div>
        <div class="popup-form" id="popupForm">
            <h4 id="formTitle"><?= trh('Add New Animal Line') ?></h4>
            <form action="manage_animal_lines.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="id" id="line_id_field">
                <div class="form-group">
                    <label for="code"><?= trh('Code') ?> <span class="required-asterisk">*</span></label>
                    <input type="text" name="code" id="code" class="form-control" required maxlength="20" style="text-transform: uppercase;">
                    <small class="form-text text-muted"><?= trh('Short prefix, e.g. RSD, RY139, RN, CGN.') ?></small>
                </div>
                <div class="form-group">
                    <label for="species"><?= trh('Species') ?> <span class="required-asterisk">*</span></label>
                    <input type="text" name="species" id="species" class="form-control" required placeholder="<?= trh('e.g. Rat, Souris, Rat noir (Rattus rattus), Campagnol') ?>">
                </div>
                <div class="form-group">
                    <label for="name"><?= trh('Line Name') ?> <span class="required-asterisk">*</span></label>
                    <input type="text" name="name" id="name" class="form-control" required placeholder="<?= trh('e.g. Rat Sprague Dawley') ?>">
                </div>
                <div class="form-group">
                    <label for="notes"><?= trh('Notes') ?></label>
                    <textarea name="notes" id="notes" class="form-control" rows="3"></textarea>
                </div>
                <div class="form-buttons">
                    <button type="submit" name="add" id="addButton" class="btn btn-primary"><i class="fas fa-plus"></i> <?= trh('Add Line') ?></button>
                    <button type="submit" name="edit" id="editButton" class="btn btn-success" style="display: none;"><i class="fas fa-save"></i> <?= trh('Update Line') ?></button>
                    <button type="button" class="btn btn-secondary" onclick="closeForm()"><?= trh('Cancel') ?></button>
                </div>
            </form>
        </div>

        <h3><?= trh('Existing Lines') ?></h3>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th><?= trh('Code') ?></th>
                    <th><?= trh('Species') ?></th>
                    <th><?= trh('Name') ?></th>
                    <th><?= trh('Next ID') ?></th>
                    <th><?= trh('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $linesResult->fetch_assoc()) : ?>
                    <tr>
                        <td><?= htmlspecialchars($row['code']); ?></td>
                        <td><?= htmlspecialchars($row['species']); ?></td>
                        <td><?= htmlspecialchars($row['name']); ?></td>
                        <td><code><?= htmlspecialchars($row['code'] . '-' . str_pad($row['next_seq'], 4, '0', STR_PAD_LEFT)); ?></code></td>
                        <td>
                            <button class="btn btn-warning btn-sm" title="<?= trh('Edit') ?>" onclick="editLine(<?= (int) $row['id']; ?>, '<?= htmlspecialchars($row['code'], ENT_QUOTES); ?>', '<?= htmlspecialchars($row['species'], ENT_QUOTES); ?>', '<?= htmlspecialchars($row['name'], ENT_QUOTES); ?>', `<?= htmlspecialchars($row['notes'] ?? '', ENT_QUOTES); ?>`)"><i class="fas fa-edit"></i></button>
                            <form action="manage_animal_lines.php" method="post" style="display:inline-block;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="id" value="<?= (int) $row['id']; ?>">
                                <button type="submit" name="delete" class="btn btn-danger btn-sm" title="<?= trh('Delete') ?>" onclick="return confirm(<?= htmlspecialchars(trj('Delete this line? Lines used by existing animals cannot be deleted.')) ?>);"><i class="fas fa-trash-alt"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <script>
        function openForm() {
            document.getElementById('popupOverlay').style.display = 'block';
            document.getElementById('popupForm').style.display = 'block';
            document.getElementById('formTitle').innerText = <?= trj('Add New Animal Line') ?>;
            document.getElementById('addButton').style.display = 'inline-block';
            document.getElementById('editButton').style.display = 'none';
            document.getElementById('code').readOnly = false;
            document.getElementById('code').value = '';
            document.getElementById('species').value = '';
            document.getElementById('name').value = '';
            document.getElementById('notes').value = '';
            document.getElementById('line_id_field').value = '';
        }

        function closeForm() {
            document.getElementById('popupOverlay').style.display = 'none';
            document.getElementById('popupForm').style.display = 'none';
        }

        function editLine(id, code, species, name, notes) {
            openForm();
            document.getElementById('formTitle').innerText = <?= trj('Edit Animal Line') ?>;
            document.getElementById('addButton').style.display = 'none';
            document.getElementById('editButton').style.display = 'inline-block';
            document.getElementById('code').readOnly = true;
            document.getElementById('code').value = code;
            document.getElementById('species').value = species;
            document.getElementById('name').value = name;
            document.getElementById('notes').value = notes.replace(/\\n/g, '\n');
            document.getElementById('line_id_field').value = id;
        }
    </script>

    <?php require 'footer.php'; ?>
</body>

</html>
