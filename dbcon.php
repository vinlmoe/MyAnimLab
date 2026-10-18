<?php

/**
 * Database Connection Script
 *
 * This script loads environment variables from a .env file using the Dotenv library and establishes a connection
 * to a MySQL database using the credentials provided in the .env file. If the .env file is not found or the
 * connection fails, appropriate error messages are logged or displayed.
 *
 */

// Already connected (session_config.php loads this file first): nothing to redo.
if (isset($con) && $con instanceof mysqli) {
    return;
}

// Load Composer's autoload file
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Interface translations (t(), tr()) are available to every page.
require_once __DIR__ . '/i18n.php';

// Check if the .env file exists in the current directory
if (file_exists(__DIR__ . '/.env')) {
    // Load the .env file. Immutable: variables already set in the environment
    // (e.g. by the automated tests or a container) are kept.
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} elseif (getenv('DB_DATABASE') === false) {
    // Terminate the script with an error message if no configuration is found
    die('.env file not found. Please create the file and add your database credentials.');
}

// Database credentials from the environment (.env, or real environment variables).
$envValue = fn(string $name, string $default): string => (string) ($_ENV[$name] ?? (getenv($name) !== false ? getenv($name) : $default));
$servername = $envValue('DB_HOST', 'localhost');
$username = $envValue('DB_USERNAME', 'root');
$password = $envValue('DB_PASSWORD', '');
$dbname = $envValue('DB_DATABASE', 'myvivarium');
$dbport = (int) $envValue('DB_PORT', '3306');

$demo = $envValue('DEMO', '');

// Create a new connection to the database using the object-oriented style
$con = new mysqli($servername, $username, $password, $dbname, $dbport);

// Check the connection to the database
if ($con->connect_error) {
    // Log the error message (for production, avoid displaying detailed errors to users)
    error_log('Connection Failed: ' . $con->connect_error);
    die(tr('Connection Failed. Please try again later.'));
}

// Use the lab's timezone (Manage Lab) for both PHP and MySQL, so "today"
// (daily round, follow-ups, alerts) means the same day everywhere.
$tzResult = $con->query("SELECT value FROM settings WHERE name = 'timezone'");
$labTimezone = $tzResult ? ($tzResult->fetch_assoc()['value'] ?? '') : '';
if ($labTimezone !== '' && in_array($labTimezone, timezone_identifiers_list(), true)) {
    date_default_timezone_set($labTimezone);
    $con->query("SET time_zone = '" . (new DateTime())->format('P') . "'");
}
