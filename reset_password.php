<?php

/**
 * Password Reset Page
 * 
 * This script handles the password reset functionality for users. 
 * Users receive a token to reset their password. The script verifies 
 * the token, allows the user to enter a new password, and updates 
 * the database with the new password if the token is valid.
 * 
 */

// Include the database connection file
require 'dbcon.php';

// Query to fetch the lab name from the settings table
$labQuery = "SELECT value FROM settings WHERE name = 'lab_name' LIMIT 1";
$labResult = mysqli_query($con, $labQuery);

// Default value if the query fails or returns no result
$labName = "MyAnimLab";
if ($row = mysqli_fetch_assoc($labResult)) {
    $labName = $row['value'];
}

$resultMessage = "";
$updateStmt = null; // Initialize $updateStmt for later use

// Check if the form is submitted via POST and the reset button was clicked
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reset'])) {
    $token = $_POST['token'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];

    // Check if new password and confirm password are the same
    if ($newPassword === $confirmPassword) {
        // Prepare SQL to check if the token exists and is valid
        $query = "SELECT * FROM users WHERE reset_token = ? AND reset_token_expiration >= NOW()";
        $stmt = $con->prepare($query);
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        // Check if the token is valid
        if ($result->num_rows == 1) {
            // Fetch user data
            $row = $result->fetch_assoc();
            $username = $row['username'];
            $uid = (int) $row['id'];

            // Prepare SQL to update the user's password. Keyed on the account
            // id, not the username, so a reset can only ever change the one
            // account the token belongs to (security review S2).
            $updateQuery = "UPDATE users SET password = ?, reset_token = NULL, reset_token_expiration = NULL WHERE id = ?";
            $updateStmt = $con->prepare($updateQuery);
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            $updateStmt->bind_param("si", $hashedPassword, $uid);

            // Execute the update and set a success or failure message
            if ($updateStmt->execute()) {
                $resultMessage = tr('Password reset successfully. You can now {link}log in{/link} with your new password.', ['link' => "<a href='index.php'>", '/link' => '</a>']);
            } else {
                $resultMessage = trh('Password reset failed. Please try again.');
            }
        } else {
            $resultMessage = trh('Invalid or expired token. Please request a new password reset.');
        }

        // Close the statement
        $stmt->close();
        if (isset($updateStmt)) {
            $updateStmt->close();
        }
    } else {
        $resultMessage = trh('New Password and Confirm Password do not match.');
    }

    // Close the database connection
    $con->close();
}
?>

<!DOCTYPE html>
<html>

<head>
    <!-- Page Metadata -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= trh('Reset Password') ?> | <?php echo htmlspecialchars($labName); ?></title>

    <!-- Favicon and icons for different devices -->
    <link rel="icon" type="image/svg+xml" href="images/logo-mark.svg">
    <link rel="icon" href="/icons/favicon.ico" type="image/x-icon">
    <link rel="apple-touch-icon" sizes="180x180" href="/icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16x16.png">
    <link rel="icon" sizes="192x192" href="/icons/android-chrome-192x192.png">
    <link rel="icon" sizes="512x512" href="/icons/android-chrome-512x512.png">
    <link rel="manifest" href="manifest.json" crossorigin="use-credentials">

    <!-- Bootstrap CSS -->
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Font: Poppins -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;700&display=swap" rel="stylesheet">

    <style>
        .container {
            max-width: 600px;
            margin-top: 300px;
            margin-bottom: 300px;
            padding: 20px;
            border: 1px solid #ccc;
            border-radius: 5px;
            background-color: #f9f9f9;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .btn {
            display: block;
            width: 100%;
            padding: 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }

        .btn:hover {
            background-color: #0056b3;
        }

        .result-message {
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
    <!-- Header Section -->
    <?php if ($demo === "yes") include('demo/demo-banner.php'); ?>
    <?php require 'brand_banner.php'; ?>
    <br>
    <br>
    <div class="container content">
        <h2><?= trh('Reset Password') ?></h2>
        <br>
        <form method="POST" action="">
            <!-- Hidden field for the token -->
            <input type="hidden" id="token" name="token" value="<?= htmlspecialchars($_GET['token'] ?? ''); ?>">

            <!-- New Password Field -->
            <div class="form-group">
                <label for="new_password"><?= trh('New Password') ?></label>
                <input type="password" class="form-control" id="new_password" name="new_password" required>
            </div>

            <!-- Confirm Password Field -->
            <div class="form-group">
                <label for="confirm_password"><?= trh('Confirm Password') ?></label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn btn-primary" name="reset"><?= trh('Reset Password') ?></button>
        </form>

        <!-- Display Result Message -->
        <?php if (!empty($resultMessage)) {
            echo "<p class='result-message'>$resultMessage</p>";
        } ?>
        <br>

        <a href="index.php" class="btn btn-secondary"><?= trh('Go Back') ?></a>
    </div>

    <!-- Footer Section -->
    <br>
    <?php include 'footer.php'; ?>

</body>

</html>