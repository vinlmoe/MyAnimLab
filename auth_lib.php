<?php

/**
 * Sign-in and two-factor authentication.
 *
 * Two-factor authentication uses time-based one-time codes (TOTP, RFC 6238:
 * HMAC-SHA1, 30-second steps, 6 digits) from any authenticator application,
 * and ten one-time recovery codes (stored hashed). A code is accepted one
 * step before or after the current one, and never twice (last step kept).
 * When the setting require_2fa_privileged is on (Manage Lab), administrators
 * and BEA responsibles who have not set it up are sent to two_factor.php
 * right after signing in, before any other page (session_config.php).
 */

const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;
const TOTP_RECOVERY_CODES = 10;
// Seconds allowed to type the code after the password, and attempts.
const TOTP_PENDING_TTL = 300;
const TOTP_MAX_ATTEMPTS = 5;

function base32_encode_bytes(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode_string(string $text): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $text = strtoupper(preg_replace('/[\s=-]/', '', $text));
    $bits = '';
    foreach (str_split($text) as $c) {
        $v = strpos($alphabet, $c);
        if ($v === false) {
            return '';
        }
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

// A new random secret (160 bits), base32.
function totp_new_secret(): string
{
    return base32_encode_bytes(random_bytes(20));
}

// Code of a secret (base32) for a time step.
function totp_code(string $secret, int $step, int $digits = TOTP_DIGITS): string
{
    $hash = hash_hmac('sha1', pack('N2', $step >> 32, $step & 0xFFFFFFFF), base32_decode_string($secret), true);
    $offset = ord($hash[19]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/**
 * Step matched by a typed code (current step ± 1) and later than the last
 * step used, or null.
 */
function totp_verify(string $secret, string $code, ?int $lastStep, ?int $time = null): ?int
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{' . TOTP_DIGITS . '}$/', $code)) {
        return null;
    }
    $current = intdiv($time ?? time(), TOTP_PERIOD);
    foreach ([0, -1, 1] as $delta) {
        $step = $current + $delta;
        if (($lastStep === null || $step > $lastStep) && hash_equals(totp_code($secret, $step), $code)) {
            return $step;
        }
    }
    return null;
}

// URI read by authenticator applications (shown as a QR code).
function totp_uri(string $secret, string $account, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?' . http_build_query([
        'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => TOTP_DIGITS, 'period' => TOTP_PERIOD,
    ], '', '&', PHP_QUERY_RFC3986);
}

// New recovery codes: [plain codes to show once, JSON of their hashes to store].
function totp_new_recovery_codes(): array
{
    $plain = [];
    for ($i = 0; $i < TOTP_RECOVERY_CODES; $i++) {
        $raw = strtolower(base32_encode_bytes(random_bytes(5)));
        $plain[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    }
    return [$plain, json_encode(array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $plain))];
}

// Remaining recovery codes after using one, or null when the code is not valid.
function totp_use_recovery_code(?string $storedJson, string $code): ?string
{
    $code = strtolower(trim($code));
    $hashes = json_decode((string) $storedJson, true) ?: [];
    foreach ($hashes as $i => $hash) {
        if (password_verify($code, $hash)) {
            unset($hashes[$i]);
            return json_encode(array_values($hashes));
        }
    }
    return null;
}

function totp_recovery_count(?string $storedJson): int
{
    return count(json_decode((string) $storedJson, true) ?: []);
}

// True when this user must use two-factor authentication.
function two_factor_required(mysqli $con, array $user): bool
{
    $setting = $con->query("SELECT value FROM settings WHERE name = 'require_2fa_privileged'")->fetch_row()[0] ?? '1';
    return $setting === '1' && (($user['role'] ?? '') === 'admin' || !empty($user['is_bea_responsible']));
}

// Local page to go to after signing in (validated ?redirect=), else home.php.
function login_redirect_target(?string $redirect): string
{
    $rurl = $redirect !== null ? urldecode($redirect) : '';
    // Only relative URLs to .php pages of this application (no open redirect).
    if ($rurl !== '' && preg_match('/^[a-zA-Z0-9_\-\.\/\?=&]+\.php/', $rurl) && !preg_match('/^(https?:)?\/\//', $rurl)) {
        return $rurl;
    }
    return 'home.php';
}

/**
 * Open the session of a user whose password (and second factor) are checked:
 * session variables, new session id, failed attempts reset. A user who must
 * use two-factor authentication without having set it up is flagged so that
 * every page sends them to two_factor.php first.
 */
function complete_login(mysqli $con, array $row): void
{
    unset($_SESSION['pending_2fa']);
    $_SESSION['name'] = $row['name'];
    $_SESSION['username'] = $row['username'];
    $_SESSION['role'] = $row['role'];
    $_SESSION['position'] = $row['position'];
    $_SESSION['user_id'] = $row['id'];
    $_SESSION['is_bea_responsible'] = !empty($row['is_bea_responsible']);
    $_SESSION['locale'] = in_array($row['preferred_locale'] ?? '', ['fr', 'en'], true) ? $row['preferred_locale'] : 'fr';
    $_SESSION['LAST_ACTIVITY'] = time();
    if (empty($row['totp_enabled']) && two_factor_required($con, $row)) {
        $_SESSION['must_setup_2fa'] = true;
    } else {
        unset($_SESSION['must_setup_2fa']);
    }

    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    // Reset login attempts and unlock the account
    $reset = $con->prepare('UPDATE users SET login_attempts = 0, account_locked = NULL WHERE id = ?');
    $reset->bind_param('i', $row['id']);
    $reset->execute();
    $reset->close();
}
