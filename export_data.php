<?php

/**
 * Database Table Exporter
 * 
 * This PHP script allows administrators to export all database tables into CSV files and package them into a ZIP file for download.
 * The script first verifies if the user is logged in and has admin privileges. It then retrieves all table names from the database,
 * creates CSV files for each table, and stores these CSV files in a ZIP archive. The ZIP file is then provided for download.
 * 
 */

// Start the session to use session variables
require_once 'session_config.php';

// Include the database connection file
include 'dbcon.php';

// Check if the user is not logged in, redirect them to index.php with the current URL for redirection after login
if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit; // Exit to ensure no further code is executed
}

// Check if the user has admin privileges
if ($_SESSION['role'] != 'admin') {
    // Set an error message and redirect to the index page if not an admin
    $_SESSION['message'] = tr('Access Denied. Contact Admin');
    header("Location: index.php");
    exit();
}

/**
 * Export a single table to CSV format.
 * 
 * @param object $con Database connection object.
 * @param string $tableName Name of the table to export.
 * @return string CSV content as a string.
 */
function exportTableToCSV($con, $tableName)
{
    // Define a specific query for the 'users' table; otherwise, select all columns
    // Table names come from SHOW TABLES (identifiers cannot be bound): quoted.
    $table = '`' . str_replace('`', '``', $tableName) . '`';
    $query = ($tableName == 'users') ? "SELECT id, name, username, position, role, status FROM $table" : "SELECT * FROM $table";
    $result = $con->query($query);

    // Check if the query execution was successful
    if (!$result) {
        echo htmlspecialchars(db_error_message($con->error));
        return '';
    }

    // Fetch column names
    $columns = $result->fetch_fields();
    $csvContent = '';

    // Start output buffering to store CSV content
    ob_start();
    $output = fopen('php://output', 'w');

    // Write column headers to the CSV
    $headers = [];
    foreach ($columns as $column) {
        $headers[] = $column->name;
    }
    // Neutralise spreadsheet formula injection: a cell whose text starts with
    // =, +, - or @ is run as a formula by Excel / LibreOffice when the file is
    // opened, so it is prefixed with an apostrophe (security review S7).
    $csvSafe = fn($v) => is_string($v) && $v !== '' && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
    fputcsv($output, array_map($csvSafe, $headers));

    // Write data rows to the CSV
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, array_map($csvSafe, $row));
    }

    // Close the output stream
    fclose($output);
    $csvContent = ob_get_clean();

    return $csvContent;
}

// Query to get all table names from the database
$tableQuery = "SHOW TABLES";
$tableResult = $con->query($tableQuery);

// Check if the table names retrieval was successful
if (!$tableResult) {
    die(htmlspecialchars(db_error_message($con->error)));
}

// Set headers to prompt the browser to download the ZIP file
header('Content-Type: application/zip');
header('Content-Disposition: attachment;filename="exported_data.zip"');

// Create a new ZIP archive
$zip = new ZipArchive();
$zipFilename = tempnam(sys_get_temp_dir(), 'zip');
$zip->open($zipFilename, ZipArchive::OVERWRITE);

// Loop through each table and add the corresponding CSV to the ZIP archive
while ($tableRow = $tableResult->fetch_row()) {
    $tableName = $tableRow[0];
    $csvContent = exportTableToCSV($con, $tableName);
    $zip->addFromString($tableName . '.csv', $csvContent);
}

// Close the ZIP archive
$zip->close();

// Output the ZIP file for download
readfile($zipFilename);

// Delete the temporary ZIP file after download
unlink($zipFilename);

// Set a session message for successful export confirmation
$_SESSION['message'] = tr('Data exported successfully!');

// Close the database connection
$con->close();
exit();
