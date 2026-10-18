<?php

/**
 * SMTP Configuration Script
 *
 * This script loads environment variables from a .env file using the Dotenv library and defines constants
 * for SMTP server settings and sender information. These constants are used for configuring the SMTP
 * server for sending emails.
 *
 */

// Load Composer's autoload file
require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables from the .env file (variables already set in
// the environment are kept; without a .env file the environment is used).
if (file_exists(__DIR__ . '/.env')) {
    Dotenv::createImmutable(__DIR__)->load();
}
$smtpSetting = fn(string $name): string => (string) ($_ENV[$name] ?? (getenv($name) !== false ? getenv($name) : ''));

// Define constants for SMTP server settings and sender information (empty
// when not configured: sending then fails and is reported, without warnings).
define('SMTP_HOST', $smtpSetting('SMTP_HOST')); // SMTP server hostname
define('SMTP_PORT', $smtpSetting('SMTP_PORT')); // SMTP server port
define('SMTP_USERNAME', $smtpSetting('SMTP_USERNAME')); // SMTP server username
define('SMTP_PASSWORD', $smtpSetting('SMTP_PASSWORD')); // SMTP server password
define('SMTP_ENCRYPTION', $smtpSetting('SMTP_ENCRYPTION')); // SMTP server encryption type (tls or ssl)
define('SENDER_EMAIL', $smtpSetting('SENDER_EMAIL')); // Sender's email address
define('SENDER_NAME', $smtpSetting('SENDER_NAME')); // Sender's name
