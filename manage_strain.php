<?php

/**
 * Manage Strains
 * 
 * This script provides functionality for managing strains in a database. It allows users to add new strains,
 * edit existing strains, and delete strains. The interface includes a responsive popup form for data entry and 
 * a table for displaying existing strains. The script uses PHP sessions for message handling and includes basic 
 * input sanitization for security.
 * 
 */

session_start(); // Start the session to use session variables
require 'dbcon.php'; // Include database connection

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
require 'header.php'; // Include the header for consistent page structure

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize variables for strain data
$strainId = $strainName = $strainAka = $strainUrl = $strainRrid = $strainNotes = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(tr('CSRF token validation failed'));
    }

    if (isset($_POST['add'])) {
        // Add new strain
        $strainId = htmlspecialchars($_POST['strain_id']); // Sanitize input
        $strainName = htmlspecialchars($_POST['strain_name']); // Sanitize input
        $strainAka = htmlspecialchars($_POST['strain_aka']); // Sanitize input
        $strainUrl = htmlspecialchars($_POST['strain_url']); // Sanitize input
        $strainRrid = htmlspecialchars($_POST['strain_rrid']); // Sanitize input
        $strainNotes = htmlspecialchars($_POST['strain_notes']); // Sanitize input

        // Check if strain ID already exists
        $checkStmt = $con->prepare("SELECT COUNT(*) FROM strains WHERE str_id = ?");
        $checkStmt->bind_param("s", $strainId);
        $checkStmt->execute();
        $checkStmt->bind_result($count);
        $checkStmt->fetch();
        $checkStmt->close();

        if ($count > 0) {
            $_SESSION['message'] = tr('Error: Strain ID already exists.'); // Error message for duplicate ID
        } else {
            $stmt = $con->prepare("INSERT INTO strains (str_id, str_name, str_aka, str_url, str_rrid, str_notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $strainId, $strainName, $strainAka, $strainUrl, $strainRrid, $strainNotes);
            if ($stmt->execute()) {
                $_SESSION['message'] = tr('Strain added successfully.'); // Success message
            } else {
                $_SESSION['message'] = tr('Error adding strain.'); // Error message
            }
            $stmt->close(); // Close the statement
        }
    } elseif (isset($_POST['edit'])) {
        // Update existing strain
        $strainId = htmlspecialchars($_POST['strain_id']); // Sanitize input
        $strainName = htmlspecialchars($_POST['strain_name']); // Sanitize input
        $strainAka = htmlspecialchars($_POST['strain_aka']); // Sanitize input
        $strainUrl = htmlspecialchars($_POST['strain_url']); // Sanitize input
        $strainRrid = htmlspecialchars($_POST['strain_rrid']); // Sanitize input
        $strainNotes = htmlspecialchars($_POST['strain_notes']); // Sanitize input
        $stmt = $con->prepare("UPDATE strains SET str_name = ?, str_aka = ?, str_url = ?, str_rrid = ?, str_notes = ? WHERE str_id = ?");
        $stmt->bind_param("ssssss", $strainName, $strainAka, $strainUrl, $strainRrid, $strainNotes, $strainId);
        if ($stmt->execute()) {
            $_SESSION['message'] = tr('Strain updated successfully.'); // Success message
        } else {
            $_SESSION['message'] = tr('Error updating strain.'); // Error message
        }
        $stmt->close(); // Close the statement
    } elseif (isset($_POST['delete'])) {
        // Delete strain
        $strainId = htmlspecialchars($_POST['strain_id']); // Sanitize input
        $stmt = $con->prepare("DELETE FROM strains WHERE str_id = ?");
        $stmt->bind_param("s", $strainId);
        if ($stmt->execute()) {
            $_SESSION['message'] = tr('Strain deleted successfully.'); // Success message
        } else {
            $_SESSION['message'] = tr('Error deleting strain.'); // Error message
        }
        $stmt->close(); // Close the statement
    }
}

// Fetch all strains for display
$strainQuery = "SELECT * FROM strains";
$strainResult = $con->query($strainQuery);
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= trh('Manage Strains') ?></title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Popup Form Styles */
        .popup-form,
        .view-popup-form {
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
            max-width: 800px;
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

        /* Button and Form Layout */
        .table-actions,
        .action-buttons,
        .form-buttons {
            display: flex;
            gap: 10px;
        }

        .table-actions {
            justify-content: flex-start;
        }

        .form-buttons {
            justify-content: space-between;
        }

        .add-button {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 20px;
        }

        .add-button .btn {
            margin-bottom: 20px;
        }

        .required-asterisk {
            color: red;
        }

        .extra-space {
            margin-bottom: 50px;
        }

        /* Responsive Styles */
        @media (max-width: 767px) {
            .form-buttons {
                flex-direction: column;
            }

            .form-buttons button {
                width: 100%;
                margin-bottom: 10px;
            }
        }

        @media (max-width: 576px) {
            .table thead {
                display: none;
            }

            .table tr {
                display: flex;
                flex-direction: column;
                margin-bottom: 20px;
            }

            .table td {
                display: flex;
                justify-content: space-between;
                padding: 10px;
                border: 1px solid #dee2e6;
            }

            .table td::before {
                content: attr(data-label);
                font-weight: bold;
                text-transform: uppercase;
                margin-bottom: 5px;
                display: block;
            }

            .table-actions,
            .action-buttons {
                flex-direction: column;
            }

            .table-actions button,
            .action-buttons .btn {
                width: 100%;
                margin-bottom: 10px;
            }

            .table-actions {
                gap: 10px;
                flex-wrap: wrap;
            }
        }
    </style>
</head>

<body>
    <div class="container content mt-5">
        <h2><?= trh('Manage Strains') ?></h2>
        <?php if (isset($_SESSION['message'])) : ?>
            <div class="alert alert-info">
                <?= htmlspecialchars($_SESSION['message']); ?>
                <?php unset($_SESSION['message']); ?>
            </div>
        <?php endif; ?>

        <!-- Button to open the popup form -->
        <div class="add-button">
            <button onclick="openForm()" class="btn btn-primary"><i class="fas fa-plus"></i> <?= trh('Add New Strain') ?></button>
        </div>

        <!-- Popup form for adding and editing strains -->
        <div class="popup-overlay" id="popupOverlay"></div>
        <div class="popup-form" id="popupForm">
            <h4 id="formTitle"><?= trh('Add New Strain') ?></h4>
            <form action="manage_strain.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="form-group">
                    <label for="strain_id"><?= trh('Strain ID') ?> <span class="required-asterisk">*</span></label>
                    <input type="text" name="strain_id" id="strain_id" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="strain_name"><?= trh('Strain Name') ?> <span class="required-asterisk">*</span></label>
                    <input type="text" name="strain_name" id="strain_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="strain_aka"><?= trh('Common Names (comma separated)') ?></label>
                    <input type="text" name="strain_aka" id="strain_aka" class="form-control">
                </div>
                <div class="form-group">
                    <label for="strain_url"><?= trh('Strain URL') ?></label>
                    <input type="url" name="strain_url" id="strain_url" class="form-control">
                </div>
                <div class="form-group">
                    <label for="strain_rrid"><?= trh('Strain RRID') ?></label>
                    <input type="text" name="strain_rrid" id="strain_rrid" class="form-control">
                </div>
                <div class="form-group">
                    <label for="strain_notes"><?= trh('Notes') ?></label>
                    <textarea name="strain_notes" id="strain_notes" class="form-control" rows="3"></textarea>
                </div>
                <div class="form-buttons">
                    <button type="submit" name="add" id="addButton" class="btn btn-primary"><i class="fas fa-plus"></i> <?= trh('Add Strain') ?></button>
                    <button type="submit" name="edit" id="editButton" class="btn btn-success" style="display: none;"><i class="fas fa-save"></i> <?= trh('Update Strain') ?></button>
                    <button type="button" class="btn btn-secondary" onclick="closeForm()"><?= trh('Cancel') ?></button>
                </div>
            </form>
        </div>

        <!-- Popup form for viewing strain details -->
        <div class="popup-overlay" id="viewPopupOverlay"></div>
        <div class="view-popup-form" id="viewPopupForm">
            <h4 id="viewFormTitle"><?= trh('View Strain') ?></h4>
            <div class="form-group">
                <strong for="view_strain_id"><?= trh('Strain ID:') ?></strong>
                <p id="view_strain_id"></p>
            </div>
            <div class="form-group">
                <strong for="view_strain_name"><?= trh('Strain Name:') ?></strong>
                <p id="view_strain_name"></p>
            </div>
            <div class="form-group">
                <strong for="view_strain_aka"><?= trh('Common Names:') ?></strong>
                <p id="view_strain_aka"></p>
            </div>
            <div class="form-group">
                <strong for="view_strain_url"><?= trh('Strain URL:') ?></strong>
                <p><a href="#" id="view_strain_url" target="_blank"></a></p>
            </div>
            <div class="form-group">
                <strong for="view_strain_rrid"><?= trh('Strain RRID:') ?></strong>
                <p id="view_strain_rrid"></p>
            </div>
            <div class="form-group">
                <strong for="view_strain_notes"><?= trh('Notes:') ?></strong>
                <p id="view_strain_notes"></p>
            </div>
            <div class="form-buttons">
                <button type="button" class="btn btn-secondary" onclick="closeViewForm()"><?= trh('Close') ?></button>
            </div>
        </div>

        <!-- Display existing strains -->
        <h3><?= trh('Existing Strains') ?></h3>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th><?= trh('ID') ?></th>
                    <th><?= trh('Name') ?></th>
                    <th><?= trh('URL') ?></th>
                    <th><?= trh('RRID') ?></th>
                    <th><?= trh('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Arguments of the view / edit buttons as JavaScript literals (safe in the onclick attribute).
                $jsArgs = fn(array $r): string => htmlspecialchars(implode(', ', array_map(
                    fn($k) => json_encode((string) ($r[$k] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE),
                    ['str_id', 'str_name', 'str_aka', 'str_url', 'str_rrid', 'str_notes']
                )));
                while ($row = $strainResult->fetch_assoc()) : ?>
                    <tr>
                        <td data-label="<?= trh('ID') ?>"><?= htmlspecialchars($row['str_id']); ?></td>
                        <td data-label="<?= trh('Name') ?>"><?= htmlspecialchars((string) $row['str_name']); ?></td>
                        <td data-label="<?= trh('URL') ?>"><?php if (preg_match('#^https?://#i', (string) $row['str_url'])) : ?><a href="<?= htmlspecialchars($row['str_url']); ?>" target="_blank" rel="noopener"><?= htmlspecialchars($row['str_url']); ?></a><?php else : ?><?= htmlspecialchars((string) $row['str_url']); ?><?php endif; ?></td>
                        <td data-label="<?= trh('RRID') ?>"><?= htmlspecialchars((string) $row['str_rrid']); ?></td>
                        <td data-label="<?= trh('Actions') ?>" class="table-actions">
                            <div class="action-buttons">
                                <button class="btn btn-info btn-sm" title="<?= trh('View') ?>" onclick="viewStrain(<?= $jsArgs($row) ?>)"><i class="fas fa-eye"></i></button>
                                <button class="btn btn-warning btn-sm" title="<?= trh('Edit') ?>" onclick="editStrain(<?= $jsArgs($row) ?>)"><i class="fas fa-edit"></i></button>
                                <form action="manage_strain.php" method="post" style="display:inline-block;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="strain_id" value="<?= htmlspecialchars($row['str_id']); ?>">
                                    <button type="submit" name="delete" class="btn btn-danger btn-sm" title="<?= trh('Delete') ?>" onclick="return confirm(<?= htmlspecialchars(trj('Are you sure you want to delete this strain?')) ?>);"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <script>
        // Function to open the popup form
        function openForm() {
            document.getElementById('popupOverlay').style.display = 'block';
            document.getElementById('popupForm').style.display = 'block';
            document.getElementById('formTitle').innerText = <?= trj('Add New Strain') ?>;
            document.getElementById('addButton').style.display = 'block';
            document.getElementById('editButton').style.display = 'none';
            document.getElementById('strain_id').readOnly = false; // Ensure field is editable when adding
            document.getElementById('strain_id').value = '';
            document.getElementById('strain_name').value = '';
            document.getElementById('strain_aka').value = '';
            document.getElementById('strain_url').value = '';
            document.getElementById('strain_rrid').value = '';
            document.getElementById('strain_notes').value = ''; // Clear notes field
        }

        // Function to close the popup form
        function closeForm() {
            document.getElementById('popupOverlay').style.display = 'none';
            document.getElementById('popupForm').style.display = 'none';
        }

        // Function to populate the form for editing
        function editStrain(id, name, aka, url, rrid, notes) {
            openForm();
            document.getElementById('formTitle').innerText = <?= trj('Edit Strain') ?>;
            document.getElementById('addButton').style.display = 'none';
            document.getElementById('editButton').style.display = 'block';
            document.getElementById('strain_id').readOnly = true; // Make field read-only when editing
            document.getElementById('strain_id').value = id;
            document.getElementById('strain_name').value = name;
            document.getElementById('strain_aka').value = aka;
            document.getElementById('strain_url').value = url;
            document.getElementById('strain_rrid').value = rrid;
            document.getElementById('strain_notes').value = notes.replace(/\\n/g, '\n'); // Set notes value with new lines
        }

        // Function to open the view popup form
        function viewStrain(id, name, aka, url, rrid, notes) {
            document.getElementById('viewPopupOverlay').style.display = 'block';
            document.getElementById('viewPopupForm').style.display = 'block';
            document.getElementById('view_strain_id').innerText = id;
            document.getElementById('view_strain_name').innerText = name;
            document.getElementById('view_strain_aka').innerText = aka;
            document.getElementById('view_strain_url').innerText = url;
            document.getElementById('view_strain_url').href = url; // Set the href for the URL link
            document.getElementById('view_strain_rrid').innerText = rrid;
            document.getElementById('view_strain_notes').innerHTML = notes.replace(/\n/g, '<br>'); // Replace \n with <br> for display
        }

        // Function to close the view popup form
        function closeViewForm() {
            document.getElementById('viewPopupOverlay').style.display = 'none';
            document.getElementById('viewPopupForm').style.display = 'none';
        }
    </script>

    <div class="extra-space"></div> <!-- Add extra space before the footer -->
    <?php require 'footer.php'; // Include the footer 
    ?>
</body>

</html>
