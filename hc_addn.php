<?php

/**
 * Add New Holding Cage Script
 *
 * Creates a new holding cage and bulk-generates its animals from a male/female
 * count on a single shared line. cage_id and each animal's animal_uid are
 * both fully automatic (never entered manually).
 */

// Start a new session or resume the existing session
session_start();

// Include the database connection file
require 'dbcon.php';
require 'animal_lib.php';

// Check if the user is not logged in, redirect them to index.php with the current URL for redirection after login
if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit; // Exit to ensure no further code is executed
}

// Generate a CSRF token if it doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Query to retrieve users with initials and names
$userQuery = "SELECT id, initials, name FROM users WHERE status = 'approved'";
$userResult = $con->query($userQuery);

// Query to retrieve options where role is 'Principal Investigator'
$piQuery = "SELECT id, initials, name FROM users WHERE position = 'Principal Investigator' AND status = 'approved'";
$piResult = $con->query($piQuery);

// Query to retrieve IACUC values
$iacucQuery = "SELECT iacuc_id, iacuc_title FROM iacuc";
$iacucResult = $con->query($iacucQuery);

// Query to retrieve strain details including common names
$strainQuery = "SELECT str_id, str_name, str_aka FROM strains";
$strainResult = $con->query($strainQuery);

// Query to retrieve animal lines (used to assign the whole cage's animals a unique animal_uid)
$lineQuery = "SELECT id, code, species, name FROM animal_lines ORDER BY code";
$lineResult = $con->query($lineQuery);

// Initialize an array to hold all options
$strainOptions = [];

// Process each row to generate options
while ($strainRow = $strainResult->fetch_assoc()) {
    $str_id = htmlspecialchars($strainRow['str_id'] ?? 'Unknown');
    $str_name = htmlspecialchars($strainRow['str_name'] ?? 'Unnamed Strain');
    $str_aka = $strainRow['str_aka'] ? htmlspecialchars($strainRow['str_aka']) : '';

    // Add the main strain option
    $strainOptions[] = "$str_id | $str_name";

    // Explode the common names if they exist
    if (!empty($str_aka)) {
        $akaNames = explode(', ', $str_aka);
        foreach ($akaNames as $aka) {
            $strainOptions[] = "$str_id | " . htmlspecialchars(trim($aka));
        }
    }
}

// Sort the options based on str_id
sort($strainOptions, SORT_STRING);

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    // Retrieve form data (cage_id is never submitted: it's auto-generated below)
    $pi_name = mysqli_real_escape_string($con, $_POST['pi_name']);
    $strain = mysqli_real_escape_string($con, $_POST['strain']);
    $iacuc = isset($_POST['iacuc']) ? mysqli_real_escape_string($con, implode(',', $_POST['iacuc'])) : '';
    $user = isset($_POST['user']) ? implode(',', array_map('trim', $_POST['user'])) : '';
    $dob = mysqli_real_escape_string($con, $_POST['dob']);
    $line_id = !empty($_POST['line_id']) ? (int) $_POST['line_id'] : null;
    $genotype = mysqli_real_escape_string($con, $_POST['genotype'] ?? '');
    $remarks = mysqli_real_escape_string($con, $_POST['remarks']);
    $male_count = max(0, (int) ($_POST['male_count'] ?? 0));
    $female_count = max(0, (int) ($_POST['female_count'] ?? 0));

    $qty = $male_count + $female_count;

    if (!$line_id) {
        $_SESSION['message'] = "Please select an animal line.";
    } elseif ($qty === 0) {
        $_SESSION['message'] = "Enter at least one male or female to create.";
    } else {
        // Start a transaction
        mysqli_begin_transaction($con);

        try {
            // cage_id is fully automatic — never entered manually.
            $cage_id = allocate_cage_id($con);

            // Insert data into cages table
            $query1 = "INSERT INTO cages (cage_id, pi_name, quantity, remarks) VALUES (?, ?, ?, ?)";
            $stmt = $con->prepare($query1);
            $stmt->bind_param("siss", $cage_id, $pi_name, $qty, $remarks);
            $stmt->execute();

            // Room / rack locate the cage for the daily round.
            $room = trim($_POST['room'] ?? '') ?: null;
            $rack = trim($_POST['rack'] ?? '') ?: null;
            $stmtLoc = $con->prepare("UPDATE cages SET room = ?, rack = ? WHERE cage_id = ?");
            $stmtLoc->bind_param("sss", $room, $rack, $cage_id);
            $stmtLoc->execute();
            $stmtLoc->close();

            // Experimental cages are holding cages listed in their own category.
            if (!empty($_POST['is_experimental'])) {
                set_cages_experimental($con, [$cage_id], true, $_SESSION['user_id'] ?? null);
            }

            // Insert data into holding table (parent_cg is no longer collected:
            // real lineage, when it exists, is tracked via animal_parentage instead)
            $query2 = "INSERT INTO holding (cage_id, strain, dob) VALUES (?, ?, ?)";
            $stmt2 = $con->prepare($query2);
            $stmt2->bind_param("sss", $cage_id, $strain, $dob);
            $stmt2->execute();

            // Bulk-generate the animals: each gets its own unique animal_uid
            // from the chosen line, and the sex assigned by the requested counts.
            $counts = ['male' => $male_count, 'female' => $female_count];
            foreach ($counts as $sex => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $animal_uid = allocate_animal_uid($con, $line_id);

                    $query3 = "INSERT INTO mice (cage_id, line_id, animal_uid, sex, genotype, dob) VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt3 = $con->prepare($query3);
                    $stmt3->bind_param("sissss", $cage_id, $line_id, $animal_uid, $sex, $genotype, $dob);
                    $stmt3->execute();
                    $new_animal_id = $con->insert_id;

                    log_animal_movement($con, $new_animal_id, null, $cage_id, $_SESSION['user_id'] ?? null);
                    log_animal_event($con, $new_animal_id, 'created', ['cage_id' => $cage_id, 'animal_uid' => $animal_uid, 'sex' => $sex], $_SESSION['user_id'] ?? null);
                }
            }

            // Insert data into cage_iacuc table
            if (!empty($iacuc)) {
                $iacuc_codes = explode(',', $iacuc);
                foreach ($iacuc_codes as $iacuc_code) {
                    $query4 = "INSERT INTO cage_iacuc (cage_id, iacuc_id) VALUES (?, ?)";
                    $stmt4 = $con->prepare($query4);
                    $stmt4->bind_param("ss", $cage_id, $iacuc_code);
                    $stmt4->execute();
                }
            }

            // Insert data into cage_users table
            if (!empty($user)) {
                $user_ids = explode(',', $user);
                foreach ($user_ids as $user_id) {
                    $query5 = "INSERT INTO cage_users (cage_id, user_id) VALUES (?, ?)";
                    $stmt5 = $con->prepare($query5);
                    $stmt5->bind_param("si", $cage_id, $user_id);
                    $stmt5->execute();
                }
            }

            // Start of the cage's life: from today it must be seen daily. Creating it
            // means its animals were just handled, so it counts as today's check.
            log_cage_lifecycle($con, $cage_id, 'created', null, $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? null);
            record_cage_check($con, $cage_id, 'creation', 'ok', 'auto', 'Cage created', null, $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? null);

            log_activity($con, 'create', 'cage', $cage_id, "Holding cage created with $male_count male(s) and $female_count female(s).", $_SESSION['user_id'] ?? null);

            // Commit the transaction
            mysqli_commit($con);

            $_SESSION['message'] = "New holding cage $cage_id added successfully.";
        } catch (Exception $e) {
            // Rollback the transaction in case of an error
            mysqli_rollback($con);
            $_SESSION['message'] = "Failed to add new holding cage.";
        }

        // Close the prepared statement
        if (isset($stmt)) $stmt->close();
        if (isset($stmt2)) $stmt2->close();
        if (isset($stmt3)) $stmt3->close();
        if (isset($stmt4)) $stmt4->close();
        if (isset($stmt5)) $stmt5->close();
    }

    // Redirect back to the main page
    header("Location: " . (!empty($_POST['is_experimental']) ? "ec_dash.php" : "hc_dash.php"));
    exit();
}

require 'header.php';
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title>Add New Holding Cage | <?php echo htmlspecialchars($labName); ?></title>

    <!-- Include Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">

    <!-- Include Select2 CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/css/select2.min.css" rel="stylesheet">

    <!-- Include Select2 JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/js/select2.min.js"></script>

    <style>
        .container {
            max-width: 800px;
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

        .warning-text {
            color: #dc3545;
            font-size: 14px;
        }

        .select2-container .select2-selection--single {
            height: 35px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            padding-right: 10px;
            padding-left: 10px;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 35px;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Function to get today's date in YYYY-MM-DD format
            function getCurrentDate() {
                const today = new Date();
                const yyyy = today.getFullYear();
                const mm = String(today.getMonth() + 1).padStart(2, '0');
                const dd = String(today.getDate()).padStart(2, '0');
                return `${yyyy}-${mm}-${dd}`;
            }

            // Function to set the max date to today for all date input fields
            function setMaxDate() {
                const currentDate = getCurrentDate();
                const dateFields = document.querySelectorAll('input[type="date"]');
                dateFields.forEach(field => {
                    field.setAttribute('max', currentDate);
                });
            }

            // Initial call to set max date on page load
            setMaxDate();
        });

        // Function to go back to the previous page
        function goBack() {
            window.history.back();
        }

        // Function to adjust the height of textareas dynamically
        function adjustTextareaHeight(element) {
            element.style.height = "auto";
            element.style.height = (element.scrollHeight) + "px";
        }

        // Function to validate date format & provide feedback
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            const dobInput = document.getElementById('dob');
            const warningText = document.createElement('span');
            warningText.style.color = 'red';
            warningText.style.display = 'none';
            dobInput.parentNode.appendChild(warningText);

            // Function to validate date
            function validateDate(dateString) {
                const regex = /^\d{4}-\d{2}-\d{2}$/;
                if (!dateString.match(regex)) return false;

                const date = new Date(dateString);
                const now = new Date();
                const year = date.getFullYear();

                // Check if the date is valid and within the range 1900-2099 and not in the future
                return date && !isNaN(date) && year >= 1900 && date <= now;
            }

            // Listen for input changes to provide immediate feedback
            dobInput.addEventListener('input', function() {
                const dobValue = dobInput.value;
                const isValidDate = validateDate(dobValue);
                if (!isValidDate) {
                    warningText.textContent = 'Invalid Date. Please enter a valid date.';
                    warningText.style.display = 'block';
                } else {
                    warningText.textContent = '';
                    warningText.style.display = 'none';
                }
            });

            // Prevent form submission if the date is invalid, or if both counts are zero
            form.addEventListener('submit', function(event) {
                const dobValue = dobInput.value;
                if (!validateDate(dobValue)) {
                    event.preventDefault();
                    warningText.textContent = 'Invalid Date. Please enter a valid date.';
                    warningText.style.display = 'block';
                    dobInput.focus();
                    return;
                }

                const maleCount = parseInt(document.getElementById('male_count').value || '0', 10);
                const femaleCount = parseInt(document.getElementById('female_count').value || '0', 10);
                if (maleCount + femaleCount <= 0) {
                    event.preventDefault();
                    alert('Enter at least one male or female to create.');
                }
            });
        });

        $(document).ready(function() {
            $('#user').select2({
                placeholder: "Select User(s)",
                allowClear: true
            });

            $('#strain').select2({
                placeholder: "Select Strain",
                allowClear: true
            });

            $('#line_id').select2({
                placeholder: "Select Line",
                allowClear: true
            });

            $('#iacuc').select2({
                placeholder: "Select IACUC",
                allowClear: true,
                templateResult: function(data) {
                    if (!data.id) {
                        return data.text;
                    }
                    var $result = $('<span>' + data.text + '</span>');
                    $result.attr('title', data.element.title);
                    return $result;
                }
            });
        });
    </script>

</head>

<body>
    <div class="container mt-4 content">

        <h4>Add New Holding Cage</h4>

        <?php include('message.php'); ?>

        <p class="warning-text">Fields marked with <span class="required-asterisk">*</span> are required.</p>
        <p class="text-muted">The Cage ID and each animal's ID are generated automatically — nothing to type.</p>

        <form method="POST">

            <!-- CSRF token field -->
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="mb-3">
                <label for="pi_name" class="form-label">PI Name <span class="required-asterisk">*</span></label>
                <select class="form-control" id="pi_name" name="pi_name" required>
                    <option value="" disabled selected>Select PI</option>
                    <?php
                    // Populate dropdown with options from the database
                    while ($row = $piResult->fetch_assoc()) {
                        $pi_id = htmlspecialchars($row['id']);
                        $pi_initials = htmlspecialchars($row['initials']);
                        $pi_name = htmlspecialchars($row['name']);
                        echo "<option value='$pi_id'>$pi_initials [$pi_name]</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="line_id" class="form-label">Line <span class="required-asterisk">*</span></label>
                <select class="form-control" id="line_id" name="line_id" required>
                    <option value="" disabled selected>Select Line</option>
                    <?php
                    $lineResult->data_seek(0);
                    while ($lineRow = $lineResult->fetch_assoc()) {
                        $lid = htmlspecialchars($lineRow['id']);
                        $lcode = htmlspecialchars($lineRow['code']);
                        $lname = htmlspecialchars($lineRow['name']);
                        echo "<option value='$lid'>$lcode - $lname</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="strain" class="form-label">Strain <span class="required-asterisk">*</span></label>
                <select class="form-control" id="strain" name="strain" required>
                    <option value="" disabled selected>Select Strain</option>
                    <?php
                    // Populate the dropdown with all the options generated
                    foreach ($strainOptions as $option) {
                        echo "<option value='" . explode(" | ", $option)[0] . "'>$option</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="iacuc" class="form-label">IACUC</label>
                <select class="form-control" id="iacuc" name="iacuc[]" multiple>
                    <option value="" disabled>Select IACUC</option>
                    <?php
                    // Populate the dropdown with IACUC values from the database
                    while ($iacucRow = $iacucResult->fetch_assoc()) {
                        $iacuc_id = htmlspecialchars($iacucRow['iacuc_id']);
                        $iacuc_title = htmlspecialchars($iacucRow['iacuc_title']);
                        $truncated_title = strlen($iacuc_title) > 40 ? substr($iacuc_title, 0, 40) . '...' : $iacuc_title;
                        echo "<option value='$iacuc_id' title='$iacuc_title'>$iacuc_id | $truncated_title</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="user" class="form-label">User <span class="required-asterisk">*</span></label>
                <select class="form-control" id="user" name="user[]" multiple required>
                    <?php
                    // Populate the dropdown with options from the database
                    while ($userRow = $userResult->fetch_assoc()) {
                        $user_id = htmlspecialchars($userRow['id']);
                        $initials = htmlspecialchars($userRow['initials']);
                        $name = htmlspecialchars($userRow['name']);
                        echo "<option value='$user_id'>$initials [$name]</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="dob" class="form-label">DOB <span class="required-asterisk">*</span></label>
                <input type="date" class="form-control" id="dob" name="dob" required min="1900-01-01">
            </div>

            <div class="mb-3">
                <label for="genotype" class="form-label">Genotype</label>
                <input type="text" class="form-control" id="genotype" name="genotype" placeholder="Applies to all animals created in this cage">
            </div>

            <div class="row">
                <div class="col mb-3">
                    <label for="male_count" class="form-label">Number of Males <span class="required-asterisk">*</span></label>
                    <input type="number" class="form-control" id="male_count" name="male_count" min="0" value="0" required>
                </div>
                <div class="col mb-3">
                    <label for="female_count" class="form-label">Number of Females <span class="required-asterisk">*</span></label>
                    <input type="number" class="form-control" id="female_count" name="female_count" min="0" value="0" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="room" class="form-label">Room</label>
                    <input type="text" class="form-control" id="room" name="room" list="roomList" value="" placeholder="Used to organise the daily round">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="rack" class="form-label">Rack</label>
                    <input type="text" class="form-control" id="rack" name="rack" value="">
                </div>
                <div class="col-12 mb-3 form-check ms-2">
                    <input class="form-check-input" type="checkbox" id="is_experimental" name="is_experimental" value="1" <?= !empty($_GET['experimental']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="is_experimental">Cage d’expérimentation</label>
                </div>
                <datalist id="roomList">
                    <?php foreach ($con->query("SELECT DISTINCT room FROM cages WHERE room IS NOT NULL AND room <> '' ORDER BY room") as $roomRow) : ?>
                        <option value="<?= htmlspecialchars($roomRow['room']); ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="mb-3">
                <label for="remarks" class="form-label">Remarks</label>
                <textarea class="form-control" id="remarks" name="remarks" oninput="adjustTextareaHeight(this)"></textarea>
            </div>

            <br>

            <button type="submit" class="btn btn-primary">Add Cage</button>
            <button type="button" class="btn btn-secondary" onclick="goBack()">Go Back</button>

        </form>
    </div>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
