<?php

/**
 * Database Connection Script
 *
 * This script loads environment variables from a .env file using the Dotenv library and establishes a connection
 * to a MySQL database using the credentials provided in the .env file. If the .env file is not found or the
 * connection fails, appropriate error messages are logged or displayed.
 *
 */

// Load Composer's autoload file
require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Interface translations (t(), tr()) are available to every page.
require_once __DIR__ . '/i18n.php';

// Check if the .env file exists in the current directory
if (file_exists(__DIR__ . '/.env')) {
    // Create an instance of Dotenv and load the .env file
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} else {
    // Terminate the script with an error message if the .env file is not found
    die('.env file not found. Please create the file and add your database credentials.');
}

// Retrieve database connection credentials from environment variables
$servername = $_ENV['DB_HOST'] ?? 'localhost'; // Default to 'localhost' if not set
$username = $_ENV['DB_USERNAME'] ?? 'root'; // Default to 'root' if not set
$password = $_ENV['DB_PASSWORD'] ?? ''; // Default to an empty string if not set
$dbname = $_ENV['DB_DATABASE'] ?? 'myvivarium'; // Default to 'database' if not set

$demo = $_ENV['DEMO'] ?? ''; // Default to an empty string if not set

// Create a new connection to the database using the object-oriented style
$con = new mysqli($servername, $username, $password, $dbname);

// Check the connection to the database
if ($con->connect_error) {
    // Log the error message (for production, avoid displaying detailed errors to users)
    error_log('Connection Failed: ' . $con->connect_error);
    die('Connection Failed. Please try again later.');
}

// Use the lab's timezone (Manage Lab) for both PHP and MySQL, so "today"
// (daily round, follow-ups, alerts) means the same day everywhere.
$tzResult = $con->query("SELECT value FROM settings WHERE name = 'timezone'");
$labTimezone = $tzResult ? ($tzResult->fetch_assoc()['value'] ?? '') : '';
if ($labTimezone !== '' && in_array($labTimezone, timezone_identifiers_list(), true)) {
    date_default_timezone_set($labTimezone);
    $con->query("SET time_zone = '" . (new DateTime())->format('P') . "'");
}
