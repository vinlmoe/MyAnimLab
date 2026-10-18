<?php

/**
 * Manage Lab Page
 * 
 * This script allows logged-in users to view and update lab details, including the lab name, URL, timezone, IoT sensor links for two rooms,
 * and Cloudflare Turnstile secret and site keys.
 * 
 */

// Start a new session or resume the existing session
require_once 'session_config.php';

// Include the database connection file
require 'dbcon.php';
require 'animal_lib.php';

// Check if the user is logged in and has admin role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    // Redirect non-admin users to the index page
    header("Location: index.php");
    exit; // Ensure no further code is executed
}

// CSRF token generation

// Fetch lab details from the database
$query = "SELECT * FROM settings";
$result = mysqli_query($con, $query);
$labData = [];
while ($row = mysqli_fetch_assoc($result)) {
    $labData[$row['name']] = $row['value'];
}

// Provide default values if no data is found
$defaultLabData = [
    'lab_name' => '',
    'url' => '',
    'timezone' => '',
    'round_alert_time' => '18:00',
    'weaning_age_days' => '21',
    'session_timeout_minutes' => (string) SESSION_TIMEOUT_DEFAULT_MINUTES,
    'acts_reminder_time' => '07:30',
    'require_2fa_privileged' => '1',
    'r1_temp' => '',
    'r1_humi' => '',
    'r1_illu' => '',
    'r1_pres' => '',
    'r2_temp' => '',
    'r2_humi' => '',
    'r2_illu' => '',
    'r2_pres' => '',
    'cf-turnstile-secretKey' => '',
    'cf-turnstile-sitekey' => ''
];

$labData = array_merge($defaultLabData, $labData);

$updateMessage = '';

// Handle form submission for lab data update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_lab'])) {

    if (!csrf_valid()) {
        die(tr('CSRF token validation failed'));
    }

    // Sanitize and fetch form inputs
    $inputFields = ['lab_name', 'url', 'timezone', 'round_alert_time', 'weaning_age_days', 'session_timeout_minutes', 'acts_reminder_time', 'require_2fa_privileged', 'r1_temp', 'r1_humi', 'r1_illu', 'r1_pres', 'r2_temp', 'r2_humi', 'r2_illu', 'r2_pres', 'cf-turnstile-secretKey', 'cf-turnstile-sitekey'];
    $inputData = [];
    foreach ($inputFields as $field) {
        // Stored as typed (every output escapes it); FILTER_SANITIZE_STRING is deprecated.
        $inputData[$field] = trim(strip_tags((string) ($_POST[$field] ?? '')));
    }
    $inputData['acts_reminder_time'] = preg_match('/^\d{2}:\d{2}$/', $inputData['acts_reminder_time']) ? $inputData['acts_reminder_time'] : '07:30';
    $inputData['require_2fa_privileged'] = !empty($_POST['require_2fa_privileged']) ? '1' : '0';
    if ($inputData['require_2fa_privileged'] !== $labData['require_2fa_privileged']) {
        log_activity($con, 'settings_changed', 'settings', 'require_2fa_privileged', $labData['require_2fa_privileged'] . ' → ' . $inputData['require_2fa_privileged'], $_SESSION['user_id'] ?? null);
    }
    // Inactivity before sign-out: whole minutes within the allowed range.
    $minutes = (int) $inputData['session_timeout_minutes'];
    $inputData['session_timeout_minutes'] = (string) max(SESSION_TIMEOUT_MIN_MINUTES, min(SESSION_TIMEOUT_MAX_MINUTES, $minutes ?: SESSION_TIMEOUT_DEFAULT_MINUTES));
    if ($inputData['session_timeout_minutes'] !== $labData['session_timeout_minutes']) {
        log_activity($con, 'settings_changed', 'settings', 'session_timeout_minutes', $labData['session_timeout_minutes'] . ' → ' . $inputData['session_timeout_minutes'] . ' min', $_SESSION['user_id'] ?? null);
    }

    // Update or insert new data
    foreach ($inputData as $name => $value) {
        $checkQuery = "SELECT COUNT(*) as count FROM settings WHERE name = ?";
        $checkStmt = $con->prepare($checkQuery);
        $checkStmt->bind_param("s", $name);
        $checkStmt->execute();
        $checkStmt->bind_result($count);
        $checkStmt->fetch();
        $checkStmt->close();

        if ($count > 0) {
            // Update existing setting
            $updateQuery = "UPDATE settings SET value = ? WHERE name = ?";
            $updateStmt = $con->prepare($updateQuery);
            $updateStmt->bind_param("ss", $value, $name);
        } else {
            // Insert new setting
            $insertQuery = "INSERT INTO settings (name, value) VALUES (?, ?)";
            $updateStmt = $con->prepare($insertQuery);
            $updateStmt->bind_param("ss", $name, $value);
        }
        $updateStmt->execute();
        $updateStmt->close();
    }

    // Refresh lab data
    $result = mysqli_query($con, $query);
    $labData = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $labData[$row['name']] = $row['value'];
    }

    $labData = array_merge($defaultLabData, $labData);

    $updateMessage = trh('Lab information updated successfully.');
}

// Include the header file
require 'header.php';
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">

<head>
    <!-- Meta tags for character encoding and responsive design -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= trh('Manage Lab') ?></title>

    <!-- Inline CSS for styling -->
    <style>
        .container {
            max-width: 800px;
            margin-top: 50px;
            margin-bottom: 50px;
            padding: 20px;
            border: 1px solid #ccc;
            border-radius: 5px;
            background-color: #f9f9f9;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .btn1 {
            display: block;
            width: 100%;
            padding: 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }

        .btn1:hover {
            background-color: #0056b3;
        }

        .update-message {
            text-align: center;
            margin-top: 15px;
            padding: 10px;
            background-color: #dff0d8;
            border: 1px solid #3c763d;
            color: #3c763d;
            border-radius: 5px;
        }
    </style>
</head>

<body>
    <div class="container content">
        <h2><?= trh('Manage Lab') ?></h2>
        <!-- Form for updating lab information -->
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="form-group">
                <label for="lab_name"><?= trh('Lab Name') ?></label>
                <textarea class="form-control" id="lab_name" name="lab_name" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['lab_name']); ?></textarea>
            </div>
            <div class="form-group">
                <label for="url"><?= trh('URL (domain name only, e.g. domain.com)') ?></label>
                <textarea class="form-control" id="url" name="url" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['url']); ?></textarea>
            </div>
            <div class="form-group">
                <label for="timezone"><?= trh('Timezone') ?></label><br>
                <a href="https://www.php.net/manual/en/timezones.php" target="_blank" style="margin-bottom: 10px; display: inline-block;"><?= trh('List of Supported Timezones') ?></a>
                <input type="text" class="form-control" id="timezone" name="timezone" value="<?php echo htmlspecialchars($labData['timezone']); ?>">
            </div>
            <div class="form-group">
                <label for="round_alert_time"><?= trh('Daily round alert time') ?></label>
                <small class="form-text text-muted d-block"><?= trh('After this time (lab timezone), admins are alerted once about cages not yet seen today. Requires the daily_round_alert.php cron job.') ?></small>
                <input type="time" class="form-control" id="round_alert_time" name="round_alert_time" value="<?php echo htmlspecialchars($labData['round_alert_time']); ?>">
            </div>
            <div class="form-group">
                <label for="session_timeout_minutes"><?= trh('Sign-out after inactivity (minutes)') ?></label>
                <small class="form-text text-muted d-block"><?= trh('A user who does nothing for this time is signed out and must sign in again ({min} to {max} minutes; default {default}).', ['min' => SESSION_TIMEOUT_MIN_MINUTES, 'max' => SESSION_TIMEOUT_MAX_MINUTES, 'default' => SESSION_TIMEOUT_DEFAULT_MINUTES]) ?></small>
                <input type="number" min="<?= SESSION_TIMEOUT_MIN_MINUTES ?>" max="<?= SESSION_TIMEOUT_MAX_MINUTES ?>" step="1" class="form-control" id="session_timeout_minutes" name="session_timeout_minutes" value="<?php echo htmlspecialchars($labData['session_timeout_minutes']); ?>" required>
            </div>
            <div class="form-group">
                <label for="acts_reminder_time"><?= trh('Reminder of the experimental acts of the day') ?></label>
                <small class="form-text text-muted d-block"><?= trh('From this time (lab timezone), the acts of the day are sent once to the people who planned them and to the users of the cages; overdue acts also go to the administrators and BEA responsibles. Requires the process_reminders.php cron job.') ?></small>
                <input type="time" class="form-control" id="acts_reminder_time" name="acts_reminder_time" value="<?php echo htmlspecialchars($labData['acts_reminder_time']); ?>">
            </div>
            <div class="form-group form-check">
                <input type="hidden" name="require_2fa_privileged" value="0">
                <input type="checkbox" class="form-check-input" id="require_2fa_privileged" name="require_2fa_privileged" value="1" <?= $labData['require_2fa_privileged'] === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="require_2fa_privileged"><?= trh('Two-factor authentication required for administrators and BEA responsibles') ?></label>
                <small class="form-text text-muted d-block"><?= trh('They set it up at their next sign-in (authenticator application) before reaching any other page.') ?></small>
            </div>
            <div class="form-group">
                <label for="weaning_age_days"><?= trh('Weaning age (days)') ?></label>
                <small class="form-text text-muted d-block"><?= trh('Used to compute each litter’s expected weaning date from its date of birth.') ?></small>
                <input type="number" min="1" class="form-control" id="weaning_age_days" name="weaning_age_days" value="<?php echo htmlspecialchars($labData['weaning_age_days']); ?>">
            </div>
            <div class="form-group">
                <label for="r1_temp"><?= trh('Room 1 Temperature Sensor') ?></label>
                <textarea class="form-control" id="r1_temp" name="r1_temp" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['r1_temp']); ?></textarea>
            </div>
            <div class="form-group">
                <label for="r1_humi"><?= trh('Room 1 Humidity Sensor') ?></label>
                <textarea class="form-control" id="r1_humi" name="r1_humi" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['r1_humi']); ?></textarea>
            </div>
            <div class="form-group">
                <label for="r1_illu"><?= trh('Room 1 Illumination Sensor') ?></label>
                <textarea class="form-control" id="r1_illu" name="r1_illu" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['r1_illu']); ?></textarea>
            </div>
            <div class="form-group">
                <label for="r1_pres"><?= trh('Room 1 Pressure Sensor') ?></label>
                <textarea class="form-control" id="r1_pres" name="r1_pres" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['r1_pres']); ?></textarea>
            </div>
            <div class="form-group">
                <label for="r2_temp"><?= trh('Room 2 Temperature Sensor') ?></label>
                <textarea class="form-control" id="r2_temp" name="r2_temp" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['r2_temp']); ?></textarea>
            </div>
            <div class="form-group">
                <label for="r2_humi"><?= trh('Room 2 Humidity Sensor') ?></label>
                <textarea class="form-control" id="r2_humi" name="r2_humi" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['r2_humi']); ?></textarea>
            </div>
            <div class="form-group">
                <label for="r2_illu"><?= trh('Room 2 Illumination Sensor') ?></label>
                <textarea class="form-control" id="r2_illu" name="r2_illu" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['r2_illu']); ?></textarea>
            </div>
            <div class="form-group">
                <label for="r2_pres"><?= trh('Room 2 Pressure Sensor') ?></label>
                <textarea class="form-control" id="r2_pres" name="r2_pres" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['r2_pres']); ?></textarea>
            </div>
            <div class="form-group">
                <label for="cf-turnstile-sitekey"><?= trh('Cloudflare Turnstile Site Key') ?></label>
                <input type="text" class="form-control" id="cf-turnstile-sitekey" name="cf-turnstile-sitekey" value="<?php echo htmlspecialchars($labData['cf-turnstile-sitekey']); ?>">
            </div>
            <div class="form-group">
                <label for="cf-turnstile-secretKey"><?= trh('Cloudflare Turnstile Secret Key') ?></label>
                <input type="text" class="form-control" id="cf-turnstile-secretKey" name="cf-turnstile-secretKey" value="<?php echo htmlspecialchars($labData['cf-turnstile-secretKey']); ?>">
            </div>
            <button type="submit" class="btn1 btn-primary" name="update_lab"><?= trh('Update Lab Information') ?></button>
        </form>
        <?php if ($updateMessage) {
            echo "<p class='update-message'>$updateMessage</p>";
        } ?>
    </div>

    <!-- Include the footer -->
    <?php include 'footer.php'; ?>

    <!-- JavaScript for adjusting textarea height dynamically -->
    <script>
        function adjustTextareaHeight(textarea) {
            textarea.style.height = 'auto';
            textarea.style.height = (textarea.scrollHeight) + 'px';
        }
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('textarea').forEach(function(textarea) {
                adjustTextareaHeight(textarea);
            });
        });
    </script>
</body>

</html>

<?php mysqli_close($con); ?>
