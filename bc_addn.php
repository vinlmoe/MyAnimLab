<?php

/**
 * Add New Breeding Cage Script
 *
 * This script handles the creation of new breeding cages in a laboratory management system. It starts a session,
 * regenerates the session ID to prevent session fixation attacks, and checks if the user is logged in.
 * It generates a CSRF token for form submissions, retrieves a list of Principal Investigators (PIs),
 * and processes the form submission for adding a new breeding cage. The script also includes the functionality
 * to add litter data associated with the breeding cage.
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

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Query to retrieve users with initials and names
$userQuery = "SELECT id, initials, name FROM users WHERE status = 'approved'";
$userResult = $con->query($userQuery);

// Query to retrieve options where position is 'Principal Investigator'
$piQuery = "SELECT id, initials, name FROM users WHERE position = 'Principal Investigator' AND status = 'approved'";
$piResult = $con->query($piQuery);

// Query to retrieve IACUC values
$iacucQuery = "SELECT iacuc_id, iacuc_title FROM iacuc";
$iacucResult = $con->query($iacucQuery);

// Query to retrieve animal lines (used to assign the breeders' unique animal_uid)
$lineQuery = "SELECT id, code, species, name FROM animal_lines ORDER BY code";
$lineResult = $con->query($lineQuery);

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    // Retrieve and sanitize form data (cage_id is never submitted: it's auto-generated below)
    $pi_id = $_POST['pi_name'];
    $cross = $_POST['cross'];
    $iacuc_ids = $_POST['iacuc'] ?? [];
    $user_ids = $_POST['user'] ?? [];
    $line_id = !empty($_POST['line_id']) ? (int) $_POST['line_id'] : null;
    $male_count = max(0, (int) ($_POST['male_count'] ?? 0));
    $female_count = max(0, (int) ($_POST['female_count'] ?? 0));
    $remarks = $_POST['remarks'];

    if (!$line_id) {
        $_SESSION['message'] = "Please select an animal line.";
    } elseif ($male_count + $female_count === 0) {
        $_SESSION['message'] = "Enter at least one male or female breeder to create.";
    } else {
        mysqli_begin_transaction($con);

        try {
            // cage_id is fully automatic — never entered manually.
            $cage_id = allocate_cage_id($con);

            // Insert into the cages table
            $insert_cage_query = $con->prepare("INSERT INTO cages (`cage_id`, `pi_name`, `remarks`) VALUES (?, ?, ?)");
            $insert_cage_query->bind_param("sss", $cage_id, $pi_id, $remarks);
            $insert_cage_query->execute();

            // Room / rack locate the cage for the daily round.
            $room = trim($_POST['room'] ?? '') ?: null;
            $rack = trim($_POST['rack'] ?? '') ?: null;
            $stmtLoc = $con->prepare("UPDATE cages SET room = ?, rack = ? WHERE cage_id = ?");
            $stmtLoc->bind_param("sss", $room, $rack, $cage_id);
            $stmtLoc->execute();
            $stmtLoc->close();

            // Insert into the breeding table
            $insert_breeding_query = $con->prepare("INSERT INTO breeding (`cage_id`, `cross`) VALUES (?, ?)");
            $insert_breeding_query->bind_param("ss", $cage_id, $cross);
            $insert_breeding_query->execute();
            $breeding_id = $con->insert_id;

            // Generate the breeders as real animal records and link them to
            // this breeding cage via breeding_animals (a cage can hold
            // several males and females at once).
            $counts = ['male' => $male_count, 'female' => $female_count];
            foreach ($counts as $sex => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $animal_uid = allocate_animal_uid($con, $line_id);

                    $insert_mouse_query = $con->prepare("INSERT INTO mice (cage_id, line_id, animal_uid, sex) VALUES (?, ?, ?, ?)");
                    $insert_mouse_query->bind_param("siss", $cage_id, $line_id, $animal_uid, $sex);
                    $insert_mouse_query->execute();
                    $new_animal_id = $con->insert_id;
                    $insert_mouse_query->close();

                    $insert_breeding_animal_query = $con->prepare("INSERT INTO breeding_animals (breeding_id, animal_id, sex) VALUES (?, ?, ?)");
                    $insert_breeding_animal_query->bind_param("iis", $breeding_id, $new_animal_id, $sex);
                    $insert_breeding_animal_query->execute();
                    $insert_breeding_animal_query->close();

                    log_animal_movement($con, $new_animal_id, null, $cage_id, $_SESSION['user_id'] ?? null);
                    log_animal_event($con, $new_animal_id, 'created', ['cage_id' => $cage_id, 'animal_uid' => $animal_uid, 'sex' => $sex], $_SESSION['user_id'] ?? null);
                }
            }

            $cage_quantity = $male_count + $female_count;
            $update_qty_query = $con->prepare("UPDATE cages SET quantity = ? WHERE cage_id = ?");
            $update_qty_query->bind_param("is", $cage_quantity, $cage_id);
            $update_qty_query->execute();
            $update_qty_query->close();

            // Set a success message
            $_SESSION['message'] = "New breeding cage $cage_id added successfully.";

            // Insert IACUC associations
            foreach ($iacuc_ids as $iacuc_id) {
                $insert_cage_iacuc_query = $con->prepare("INSERT INTO cage_iacuc (`cage_id`, `iacuc_id`) VALUES (?, ?)");
                $insert_cage_iacuc_query->bind_param("ss", $cage_id, $iacuc_id);
                $insert_cage_iacuc_query->execute();
                $insert_cage_iacuc_query->close();
            }

            // Insert user associations
            foreach ($user_ids as $user_id) {
                $insert_cage_user_query = $con->prepare("INSERT INTO cage_users (`cage_id`, `user_id`) VALUES (?, ?)");
                $insert_cage_user_query->bind_param("ss", $cage_id, $user_id);
                $insert_cage_user_query->execute();
                $insert_cage_user_query->close();
            }

            // Start of the cage's life: from today it must be seen daily. Creating it
            // means its animals were just handled, so it counts as today's check.
            log_cage_lifecycle($con, $cage_id, 'created', null, $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? null);
            record_cage_check($con, $cage_id, 'creation', 'ok', 'auto', 'Cage created', null, $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? null);

            log_activity($con, 'create', 'cage', $cage_id, "Breeding cage created with $male_count male(s) and $female_count female(s).", $_SESSION['user_id'] ?? null);

            // Process litter data insertion if provided
            if (isset($_POST['dom'])) {
                $dom = $_POST['dom'];
                $litter_dob = $_POST['litter_dob'];
                $pups_alive = array_map(function ($value) {
                    return !empty($value) ? intval($value) : 0;
                }, $_POST['pups_alive']);
                $pups_dead = array_map(function ($value) {
                    return !empty($value) ? intval($value) : 0;
                }, $_POST['pups_dead']);
                $pups_male = array_map(function ($value) {
                    return !empty($value) ? intval($value) : 0;
                }, $_POST['pups_male']);
                $pups_female = array_map(function ($value) {
                    return !empty($value) ? intval($value) : 0;
                }, $_POST['pups_female']);
                $litter_remarks = $_POST['remarks_litter'];

                // Loop through each litter entry and insert into the database
                for ($i = 0; $i < count($dom); $i++) {
                    $insert_litter_query = $con->prepare("INSERT INTO litters (`cage_id`, `dom`, `litter_dob`, `pups_alive`, `pups_dead`, `pups_male`, `pups_female`, `remarks`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $insert_litter_query->bind_param("ssssssss", $cage_id, $dom[$i], $litter_dob[$i], $pups_alive[$i], $pups_dead[$i], $pups_male[$i], $pups_female[$i], $litter_remarks[$i]);

                    // Execute the statement and check if it was successful
                    if ($insert_litter_query->execute()) {
                        // Append success message for litter data
                        $_SESSION['message'] .= " Litter data added successfully.";
                    } else {
                        // Append error message for litter data
                        $_SESSION['message'] .= " Failed to add litter data: " . $insert_litter_query->error;
                    }

                    // Close the prepared statement for litter data
                    $insert_litter_query->close();
                }
            }

            mysqli_commit($con);

            $insert_cage_query->close();
            $insert_breeding_query->close();
        } catch (Exception $e) {
            mysqli_rollback($con);
            $_SESSION['message'] = "Failed to add new breeding cage.";
        }
    }

    // Redirect back to the main page
    header("Location: bc_dash.php");
    exit();
}

// Include the header file
require 'header.php';
?>

<!doctype html>
<html lang="en">

<head>
    <title>Add New Breeding Cage | <?php echo htmlspecialchars($labName); ?></title>

    <!-- Include Select2 CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/css/select2.min.css" rel="stylesheet" />

    <!-- Include Select2 JavaScript -->
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

            // Function to dynamically add new litter entry
            function addLitter() {
                const litterDiv = document.createElement('div');
                litterDiv.className = 'litter-entry';

                litterDiv.innerHTML = `
            <hr>
            <div class="mb-3">
                <label for="dom[]" class="form-label">DOM <span class="required-asterisk">*</span></label>
                <input type="date" class="form-control" name="dom[]" required min="1900-01-01">
            </div>
            <div class="mb-3">
                <label for="litter_dob[]" class="form-label">Litter DOB <span class="required-asterisk">*</span></label>
                <input type="date" class="form-control" name="litter_dob[]" required min="1900-01-01">
            </div>
            <div class="mb-3">
                <label for="pups_alive[]" class="form-label">Pups Alive <span class="required-asterisk">*</span></label>
                <input type="number" class="form-control" name="pups_alive[]" required min="0" step="1">
            </div>
            <div class="mb-3">
                <label for="pups_dead[]" class="form-label">Pups Dead <span class="required-asterisk">*</span></label>
                <input type="number" class="form-control" name="pups_dead[]" required min="0" step="1">
            </div>
            <div class="mb-3">
                <label for="pups_male[]" class="form-label">Pups Male</label>
                <input type="number" class="form-control" name="pups_male[]" min="0" step="1">
            </div>
            <div class="mb-3">
                <label for="pups_female[]" class="form-label">Pups Female</label>
                <input type="number" class="form-control" name="pups_female[]" min="0" step="1">
            </div>
            <div class="mb-3">
                <label for="remarks_litter[]" class="form-label">Remarks</label>
                <textarea class="form-control" name="remarks_litter[]" oninput="adjustTextareaHeight(this)"></textarea>
            </div>
            <button type="button" class="btn btn-danger" onclick="removeLitter(this)">Remove</button>
        `;

                document.getElementById('litterEntries').appendChild(litterDiv);

                // Apply max date to new date fields
                setMaxDate();
            }

            // Function to adjust the height of the textarea dynamically
            function adjustTextareaHeight(element) {
                element.style.height = "auto";
                element.style.height = (element.scrollHeight) + "px";
            }

            // Function to remove a litter entry dynamically
            function removeLitter(element) {
                element.parentElement.remove();
            }

            // Ensure the functions are available globally
            window.addLitter = addLitter;
            window.removeLitter = removeLitter;
        });


        // Function to navigate back to the previous page
        function goBack() {
            window.history.back();
        }

        // Function to validate date format & provide feedback
        document.addEventListener('DOMContentLoaded', function() {
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

            // Function to attach event listeners to date fields
            function attachDateValidation() {
                const dateFields = document.querySelectorAll('input[type="date"]');
                dateFields.forEach(field => {
                    if (!field.dataset.validated) { // Check if already validated
                        const warningText = document.createElement('span');
                        warningText.style.color = 'red';
                        warningText.style.display = 'none';
                        field.parentNode.appendChild(warningText);

                        field.addEventListener('input', function() {
                            const dateValue = field.value;
                            const isValidDate = validateDate(dateValue);
                            if (!isValidDate) {
                                warningText.textContent = 'Invalid Date. Please enter a valid date.';
                                warningText.style.display = 'block';
                            } else {
                                warningText.textContent = '';
                                warningText.style.display = 'none';
                            }
                        });

                        // Mark the field as validated
                        field.dataset.validated = 'true';
                    }
                });
            }

            // Initial call to validate existing date fields
            attachDateValidation();

            // Observe the form for changes (e.g., new nodes added dynamically)
            const form = document.querySelector('form');
            const observer = new MutationObserver(() => {
                attachDateValidation(); // Reattach validation to new nodes
            });

            // Start observing the form
            observer.observe(form, {
                childList: true,
                subtree: true
            });

            // Prevent form submission if dates are invalid
            form.addEventListener('submit', function(event) {
                let isValid = true;
                const dateFields = document.querySelectorAll('input[type="date"]');
                dateFields.forEach(field => {
                    const dateValue = field.value;
                    const warningText = field.nextElementSibling;
                    if (!validateDate(dateValue)) {
                        warningText.textContent = 'Invalid Date. Please enter a valid date.';
                        warningText.style.display = 'block';
                        isValid = false;
                    }
                });
                if (!isValid) {
                    event.preventDefault(); // Prevent form submission if any date is invalid
                    return;
                }

                const maleCount = parseInt(document.getElementById('male_count').value || '0', 10);
                const femaleCount = parseInt(document.getElementById('female_count').value || '0', 10);
                if (maleCount + femaleCount <= 0) {
                    event.preventDefault();
                    alert('Enter at least one male or female breeder to create.');
                }
            });
        });

        // Initialize Select2 for the user dropdown
        $(document).ready(function() {
            $('#user').select2({
                placeholder: "Select User(s)",
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

    <div class="container content mt-4">

        <h4>Add New Breeding Cage</h4>

        <?php include('message.php'); ?>

        <p class="warning-text">Fields marked with <span class="required-asterisk">*</span> are required.</p>
        <p class="text-muted">The Cage ID and each breeder's ID are generated automatically — nothing to type.</p>

        <form method="POST">

            <!-- CSRF token field -->
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

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
                <label for="cross" class="form-label">Cross <span class="required-asterisk">*</span></label>
                <input type="text" class="form-control" id="cross" name="cross" required>
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
                <label for="line_id" class="form-label">Line <span class="required-asterisk">*</span></label>
                <select class="form-control" id="line_id" name="line_id" required>
                    <option value="" disabled selected>Select Line</option>
                    <?php
                    while ($lineRow = $lineResult->fetch_assoc()) {
                        $lid = htmlspecialchars($lineRow['id']);
                        $lcode = htmlspecialchars($lineRow['code']);
                        $lname = htmlspecialchars($lineRow['name']);
                        echo "<option value='$lid'>$lcode - $lname</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="row">
                <div class="col mb-3">
                    <label for="male_count" class="form-label">Number of Male Breeders <span class="required-asterisk">*</span></label>
                    <input type="number" class="form-control" id="male_count" name="male_count" min="0" value="0" required>
                </div>
                <div class="col mb-3">
                    <label for="female_count" class="form-label">Number of Female Breeders <span class="required-asterisk">*</span></label>
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

            <!-- Litter Data Section -->
            <div class="mt-4">
                <h5>Litter Data</h5>
                <div id="litterEntries">
                    <!-- Litter entries will be added here dynamically -->
                </div>
                <button type="button" class="btn btn-success mt-3" onclick="addLitter()">Add Litter Entry</button>
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