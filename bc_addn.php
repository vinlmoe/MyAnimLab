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

// Breeding cages are built from animals already housed elsewhere. Do not offer
// animals already assigned to an active breeding cage.
$eligibleBreederSql = "SELECT m.id, m.animal_uid, m.mouse_id, m.cage_id, m.dob, al.code AS line_code
    FROM mice m
    LEFT JOIN animal_lines al ON al.id = m.line_id
    WHERE m.status = 'active' AND m.sex = ? AND m.cage_id IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM breeding_animals ba
          JOIN breeding b ON b.id = ba.breeding_id
          JOIN cages bc ON bc.cage_id = b.cage_id
          WHERE ba.animal_id = m.id AND bc.status = 'active'
      )
    ORDER BY m.animal_uid";

function eligible_breeders(mysqli $con, string $sex): array
{
    global $eligibleBreederSql;
    $stmt = $con->prepare($eligibleBreederSql);
    $stmt->bind_param('s', $sex);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(tr('CSRF token validation failed'));
    }

    // Retrieve and sanitize form data (cage_id is never submitted: it's auto-generated below)
    $pi_id = $_POST['pi_name'];
    $cross = $_POST['cross'];
    $iacuc_ids = $_POST['iacuc'] ?? [];
    $user_ids = $_POST['user'] ?? [];
    $maleIds = array_values(array_unique(array_filter(array_map('intval', $_POST['male_ids'] ?? []))));
    $femaleIds = array_values(array_unique(array_filter(array_map('intval', $_POST['female_ids'] ?? []))));
    $remarks = $_POST['remarks'];

    if (!$maleIds || !$femaleIds) {
        $_SESSION['message'] = tr('Select at least one existing male and one existing female breeder.');
    } else {
        mysqli_begin_transaction($con);

        try {
            // cage_id is fully automatic — never entered manually.
            $cage_id = allocate_cage_id($con);

            $breederIds = array_merge($maleIds, $femaleIds);
            if (count($breederIds) !== count(array_unique($breederIds))) {
                throw new Exception(tr('A breeder cannot be selected twice.'));
            }
            $verifyBreeder = $con->prepare("SELECT m.sex FROM mice m WHERE m.id = ? AND m.status = 'active' AND NOT EXISTS (
                SELECT 1 FROM breeding_animals ba JOIN breeding b ON b.id = ba.breeding_id JOIN cages bc ON bc.cage_id = b.cage_id
                WHERE ba.animal_id = m.id AND bc.status = 'active'
            ) FOR UPDATE");
            foreach (array_merge($maleIds, $femaleIds) as $animalId) {
                $verifyBreeder->bind_param('i', $animalId);
                $verifyBreeder->execute();
                $breeder = $verifyBreeder->get_result()->fetch_assoc();
                $expectedSex = in_array($animalId, $maleIds, true) ? 'male' : 'female';
                if (!$breeder || $breeder['sex'] !== $expectedSex) {
                    $verifyBreeder->close();
                    throw new Exception(tr('One selected breeder is no longer available with the expected sex. Refresh and try again.'));
                }
            }
            $verifyBreeder->close();

            // Insert the empty cage first; selected animals are then moved in.
            $insert_cage_query = $con->prepare("INSERT INTO cages (`cage_id`, `pi_name`, `quantity`, `remarks`) VALUES (?, ?, 0, ?)");
            $insert_cage_query->bind_param("sis", $cage_id, $pi_id, $remarks);
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

            // Link the existing breeders and transfer them into the new cage.
            // move_animals validates every selected animal before changing one.
            $sexByAnimal = [];
            foreach ($maleIds as $animalId) $sexByAnimal[$animalId] = 'male';
            foreach ($femaleIds as $animalId) $sexByAnimal[$animalId] = 'female';
            $linkBreeder = $con->prepare("INSERT INTO breeding_animals (breeding_id, animal_id, sex) VALUES (?, ?, ?)");
            foreach ($sexByAnimal as $animalId => $sex) {
                $linkBreeder->bind_param("iis", $breeding_id, $animalId, $sex);
                $linkBreeder->execute();
            }
            $linkBreeder->close();
            move_animals($con, $breederIds, $cage_id, tr('Assigned to new breeding cage'), $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? null);

            // Set a success message
            $_SESSION['message'] = tr('New breeding cage {cage} added with {males} male(s) and {females} female(s) transferred from existing cages.', ['cage' => $cage_id, 'males' => count($maleIds), 'females' => count($femaleIds)]);

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
            record_cage_check($con, $cage_id, 'creation', 'ok', 'auto', tr('Cage created'), null, $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? null);

            log_activity($con, 'create', 'cage', $cage_id, tr('Breeding cage created with existing breeders: {males} male(s), {females} female(s).', ['males' => count($maleIds), 'females' => count($femaleIds)]), $_SESSION['user_id'] ?? null);

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
                    $expected = $litter_dob[$i] ? date('Y-m-d', strtotime($litter_dob[$i] . ' +' . weaning_age_days($con) . ' days')) : null;
                    $insert_litter_query = $con->prepare("INSERT INTO litters (`cage_id`, `dom`, `litter_dob`, `pups_alive`, `pups_dead`, `pups_male`, `pups_female`, `remarks`, `expected_weaning_date`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $insert_litter_query->bind_param("sssssssss", $cage_id, $dom[$i], $litter_dob[$i], $pups_alive[$i], $pups_dead[$i], $pups_male[$i], $pups_female[$i], $litter_remarks[$i], $expected);

                    // Execute the statement and check if it was successful
                    if ($insert_litter_query->execute()) {
                        // Append success message for litter data
                        $_SESSION['message'] .= ' ' . tr('Litter data added successfully.');
                    } else {
                        // Append error message for litter data
                        $_SESSION['message'] .= ' ' . tr('Failed to add litter data: {error}', ['error' => $insert_litter_query->error]);
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
            $_SESSION['message'] = tr('Failed to add new breeding cage: {error}', ['error' => $e->getMessage()]);
        }
    }

    // Redirect back to the main page
    header("Location: bc_dash.php");
    exit();
}

$maleBreeders = eligible_breeders($con, 'male');
$femaleBreeders = eligible_breeders($con, 'female');

// Include the header file
require 'header.php';
?>

<!doctype html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <title><?= trh('Add New Breeding Cage') ?> | <?php echo htmlspecialchars($labName); ?></title>

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
                <label for="dom[]" class="form-label"><?= trh('DOM') ?> <span class="required-asterisk">*</span></label>
                <input type="date" class="form-control" name="dom[]" required min="1900-01-01">
            </div>
            <div class="mb-3">
                <label for="litter_dob[]" class="form-label"><?= trh('Litter DOB') ?> <span class="required-asterisk">*</span></label>
                <input type="date" class="form-control" name="litter_dob[]" required min="1900-01-01">
            </div>
            <div class="mb-3">
                <label for="pups_alive[]" class="form-label"><?= trh('Pups Alive') ?> <span class="required-asterisk">*</span></label>
                <input type="number" class="form-control" name="pups_alive[]" required min="0" step="1">
            </div>
            <div class="mb-3">
                <label for="pups_dead[]" class="form-label"><?= trh('Pups Dead') ?> <span class="required-asterisk">*</span></label>
                <input type="number" class="form-control" name="pups_dead[]" required min="0" step="1">
            </div>
            <div class="mb-3">
                <label for="pups_male[]" class="form-label"><?= trh('Pups Male') ?></label>
                <input type="number" class="form-control" name="pups_male[]" min="0" step="1">
            </div>
            <div class="mb-3">
                <label for="pups_female[]" class="form-label"><?= trh('Pups Female') ?></label>
                <input type="number" class="form-control" name="pups_female[]" min="0" step="1">
            </div>
            <div class="mb-3">
                <label for="remarks_litter[]" class="form-label"><?= trh('Remarks') ?></label>
                <textarea class="form-control" name="remarks_litter[]" oninput="adjustTextareaHeight(this)"></textarea>
            </div>
            <button type="button" class="btn btn-danger" onclick="removeLitter(this)"><?= trh('Remove') ?></button>
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
                                warningText.textContent = <?= trj('Invalid Date. Please enter a valid date.') ?>;
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
                        warningText.textContent = <?= trj('Invalid Date. Please enter a valid date.') ?>;
                        warningText.style.display = 'block';
                        isValid = false;
                    }
                });
                if (!isValid) {
                    event.preventDefault(); // Prevent form submission if any date is invalid
                    return;
                }

                const maleCount = document.getElementById('male_ids').selectedOptions.length;
                const femaleCount = document.getElementById('female_ids').selectedOptions.length;
                if (maleCount === 0 || femaleCount === 0) {
                    event.preventDefault();
                    alert(<?= trj('Select at least one existing male and one existing female breeder.') ?>);
                }
            });
        });

        // Initialize Select2 for the user dropdown
        $(document).ready(function() {
            $('#user').select2({
                placeholder: <?= trj('Select User(s)') ?>,
                allowClear: true
            });

            $('#male_ids, #female_ids').select2({
                placeholder: "Sélectionner des animaux existants",
                allowClear: true,
                width: '100%'
            });

            $('#iacuc').select2({
                placeholder: <?= trj('Select IACUC') ?>,
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

        <h4><?= trh('Add New Breeding Cage') ?></h4>

        <?php include('message.php'); ?>

        <p class="warning-text"><?= tr('Fields marked with {asterisk} are required.', ['asterisk' => '<span class="required-asterisk">*</span>']) ?></p>
        <p class="text-muted">L’identifiant de cage est généré automatiquement. Les reproducteurs sont sélectionnés parmi les animaux actifs d’autres cages et transférés avec leur historique complet.</p>

        <form method="POST">

            <!-- CSRF token field -->
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div class="mb-3">
                <label for="pi_name" class="form-label"><?= trh('PI Name') ?> <span class="required-asterisk">*</span></label>
                <select class="form-control" id="pi_name" name="pi_name" required>
                    <option value="" disabled selected><?= trh('Select PI') ?></option>
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
                <label for="cross" class="form-label"><?= trh('Cross') ?> <span class="required-asterisk">*</span></label>
                <input type="text" class="form-control" id="cross" name="cross" required>
            </div>

            <div class="mb-3">
                <label for="iacuc" class="form-label"><?= trh('IACUC') ?></label>
                <select class="form-control" id="iacuc" name="iacuc[]" multiple>
                    <option value="" disabled><?= trh('Select IACUC') ?></option>
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
                <label for="user" class="form-label"><?= trh('User') ?> <span class="required-asterisk">*</span></label>
                <select class="form-control" id="user" name="user[]" multiple required>
                    <?php
                    // Populate the dropdown with options from the database
                    while ($userRow = $userResult->fetch_assoc()) {
                        $user_id = htmlspecialchars($userRow['id']);
                        $initials = htmlspecialchars($userRow['initials'] ?? '');
                        $name = htmlspecialchars($userRow['name']);
                        echo "<option value='$user_id'>$initials [$name]</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="row">
                <div class="col mb-3">
                    <label for="male_ids" class="form-label">Reproducteurs mâles existants <span class="required-asterisk">*</span></label>
                    <select class="form-control" id="male_ids" name="male_ids[]" multiple required>
                        <?php foreach ($maleBreeders as $animal) : ?>
                            <option value="<?= (int) $animal['id']; ?>">
                                <?= htmlspecialchars(($animal['animal_uid'] ?: $animal['mouse_id']) . ' — ' . ($animal['line_code'] ?: 'no line') . ' — cage ' . $animal['cage_id'] . ($animal['dob'] ? ' — born ' . $animal['dob'] : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col mb-3">
                    <label for="female_ids" class="form-label">Reproductrices existantes <span class="required-asterisk">*</span></label>
                    <select class="form-control" id="female_ids" name="female_ids[]" multiple required>
                        <?php foreach ($femaleBreeders as $animal) : ?>
                            <option value="<?= (int) $animal['id']; ?>">
                                <?= htmlspecialchars(($animal['animal_uid'] ?: $animal['mouse_id']) . ' — ' . ($animal['line_code'] ?: 'no line') . ' — cage ' . $animal['cage_id'] . ($animal['dob'] ? ' — born ' . $animal['dob'] : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="room" class="form-label"><?= trh('Room') ?></label>
                    <input type="text" class="form-control" id="room" name="room" list="roomList" value="" placeholder="<?= trh('Used to organise the daily round') ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="rack" class="form-label"><?= trh('Rack') ?></label>
                    <input type="text" class="form-control" id="rack" name="rack" value="">
                </div>
                <datalist id="roomList">
                    <?php foreach ($con->query("SELECT DISTINCT room FROM cages WHERE room IS NOT NULL AND room <> '' ORDER BY room") as $roomRow) : ?>
                        <option value="<?= htmlspecialchars($roomRow['room']); ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="mb-3">
                <label for="remarks" class="form-label"><?= trh('Remarks') ?></label>
                <textarea class="form-control" id="remarks" name="remarks" oninput="adjustTextareaHeight(this)"></textarea>
            </div>

            <!-- Litter Data Section -->
            <div class="mt-4">
                <h5><?= trh('Litter Data') ?></h5>
                <div id="litterEntries">
                    <!-- Litter entries will be added here dynamically -->
                </div>
                <button type="button" class="btn btn-success mt-3" onclick="addLitter()"><?= trh('Add Litter Entry') ?></button>
            </div>

            <br>

            <button type="submit" class="btn btn-primary"><?= trh('Add Cage') ?></button>
            <button type="button" class="btn btn-secondary" onclick="goBack()"><?= trh('Go Back') ?></button>

        </form>
    </div>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
