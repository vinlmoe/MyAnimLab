<?php

/**
 * Email Confirmation Page
 * 
 * This script handles email confirmation for the lab management system. 
 * It verifies the email token from the URL, updates the user's email 
 * verification status, and displays an appropriate message.
 * 
 */

require 'dbcon.php'; // Include your database connection file

// Query to fetch the lab name from the settings table
$labQuery = "SELECT value FROM settings WHERE name = 'lab_name' LIMIT 1";
$labResult = mysqli_query($con, $labQuery);

// Default value if the query fails or returns no result
$labName = "MyAnimLab";
if ($row = mysqli_fetch_assoc($labResult)) {
    $labName = $row['value'];
}
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= trh('Email Confirmation') ?> | <?php echo htmlspecialchars($labName); ?></title>

    <!-- Favicon and icons for different devices -->
    <link rel="icon" type="image/svg+xml" href="images/logo-mark.svg">
    <link rel="icon" href="/icons/favicon.ico" type="image/x-icon">
    <link rel="apple-touch-icon" sizes="180x180" href="/icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16x16.png">
    <link rel="icon" sizes="192x192" href="/icons/android-chrome-192x192.png">
    <link rel="icon" sizes="512x512" href="/icons/android-chrome-512x512.png">
    <link rel="manifest" href="manifest.json" crossorigin="use-credentials">

    <!-- Bootstrap and Google Font -->
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        .container {
            max-width: 600px;
            margin-top: 200px;
            margin-bottom: 200px;
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

        .note {
            color: #888;
            font-size: 12px;
        }





        @media (max-width: 576px) {


            .container {
                max-width: 350px;
                margin: 0 auto;
                padding: 20px;
                border: 1px solid #ccc;
                border-radius: 5px;
                background-color: #f9f9f9;
            }
        }
    </style>
</head>

<body>
    <!-- Header Section -->
    <?php if ($demo === "yes") include('demo/demo-banner.php'); ?>
    <?php require 'brand_banner.php'; ?>
    <br>
    <div class="container content">
        <h2><?= trh('Email Confirmation') ?></h2>
        <br>

        <?php
        if (isset($_GET['token'])) {
            $token = $_GET['token'];
            $stmt = $con->prepare("SELECT username FROM users WHERE email_token = ? AND email_verified = 0");
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $stmt->bind_result($username);
                $stmt->fetch();

                $updateStmt = $con->prepare("UPDATE users SET email_verified = 1, email_token = NULL WHERE username = ?");
                $updateStmt->bind_param("s", $username);
                if ($updateStmt->execute()) {
                    echo tr('Email successfully confirmed. You can now {link}log in{/link}.', ['link' => "<a href='index.php'>", '/link' => '</a>']);
                } else {
                    echo trh('Error confirming email. Please try again.');
                }
                $updateStmt->close();
            } else {
                echo trh('Invalid or expired token.');
            }
            $stmt->close();
        } else {
            echo trh('No token provided.');
        }
        $con->close();
        ?>
        <br>
    </div>
    <br>
    <?php include 'footer.php'; ?>
</body>

</html>