<?php

/**
 * Session Security Configuration
 *
 * Every page starts its session through this file (require_once
 * 'session_config.php'), so that the same rules apply everywhere:
 *  - HttpOnly session cookie (not readable from JavaScript), SameSite=Lax
 *    (sent when following a link — QR code, e-mail — but not on cross-site
 *    form posts), Secure when the site is served over HTTPS (also behind a
 *    reverse proxy setting X-Forwarded-Proto);
 *  - strict mode and cookies only (no session id in URLs);
 *  - sign-out after a period of inactivity set by an administrator in Manage
 *    Lab (setting session_timeout_minutes, 30 minutes by default); the next
 *    page shows that the session expired;
 *  - the session id is renewed every 30 minutes (session fixation);
 *  - a user who must set up two-factor authentication reaches no other page
 *    than two_factor.php until it is done.
 * A request that must not count as activity (the notifications polled by
 * every page) defines SESSION_PASSIVE_REQUEST before including this file.
 *
 * It loads dbcon.php (database, settings, translations) before starting the
 * session; dbcon.php may still be required afterwards.
 */

require_once __DIR__ . '/dbcon.php';

const SESSION_TIMEOUT_DEFAULT_MINUTES = 30;
const SESSION_TIMEOUT_MIN_MINUTES = 5;
const SESSION_TIMEOUT_MAX_MINUTES = 720;
// PASSWORD_MIN_LENGTH is defined in db_lib.php (loaded via dbcon.php above),
// so it is available to the public pages that do not include this file.

// Inactivity allowed before sign-out, in minutes (Manage Lab setting).
function session_timeout_minutes(mysqli $con): int
{
    static $minutes = null;
    if ($minutes === null) {
        $result = $con->query("SELECT value FROM settings WHERE name = 'session_timeout_minutes'");
        $value = $result ? (int) ($result->fetch_row()[0] ?? 0) : 0;
        $minutes = $value > 0 ? max(SESSION_TIMEOUT_MIN_MINUTES, min(SESSION_TIMEOUT_MAX_MINUTES, $value)) : SESSION_TIMEOUT_DEFAULT_MINUTES;
    }
    return $minutes;
}

$sessionTimeout = session_timeout_minutes($con) * 60;

// CSRF protection: one token per session, sent by every form that changes
// data (hidden field csrf_token) and checked with csrf_valid().
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// True when the request is a POST carrying the session's CSRF token.
function csrf_valid(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''));
}

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    // Keep the data on the server at least as long as the allowed inactivity.
    ini_set('session.gc_maxlifetime', (string) max($sessionTimeout, 1440));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (PHP_SAPI !== 'cli') {
    // Baseline security headers are sent from dbcon.php, which every page
    // (public ones included) loads — see there (security review S8).

    // Sign out after the allowed inactivity.
    if (isset($_SESSION['LAST_ACTIVITY']) && time() - $_SESSION['LAST_ACTIVITY'] > $sessionTimeout) {
        $wasSignedIn = isset($_SESSION['username']);
        session_unset();
        session_destroy();
        session_start();
        session_regenerate_id(true);
        if ($wasSignedIn) {
            $_SESSION['message'] = tr('Your session expired after {n} minutes of inactivity: please sign in again.', ['n' => intdiv($sessionTimeout, 60)]);
        }
    }
    if (!defined('SESSION_PASSIVE_REQUEST')) {
        $_SESSION['LAST_ACTIVITY'] = time();
    } else {
        $_SESSION['LAST_ACTIVITY'] ??= time();
    }

    // Live account state: a change made by an administrator (role lowered,
    // account set back to pending, or deleted) must take effect at once, not
    // only after the idle timeout. The role and status are re-read from the
    // database on each real page load (security review S6): a disabled or
    // removed account is signed out immediately, and the session role is
    // refreshed so a demotion is enforced on the very next request. Passive
    // requests (the notifications poll) are skipped so the JSON endpoints are
    // not turned into redirects; the next page load enforces the change.
    if (isset($_SESSION['user_id']) && !defined('SESSION_PASSIVE_REQUEST')) {
        $liveStmt = $con->prepare('SELECT role, status, is_bea_responsible FROM users WHERE id = ?');
        $liveStmt->bind_param('i', $_SESSION['user_id']);
        $liveStmt->execute();
        $liveUser = $liveStmt->get_result()->fetch_assoc();
        $liveStmt->close();
        if (!$liveUser || $liveUser['status'] !== 'approved') {
            session_unset();
            session_destroy();
            session_start();
            session_regenerate_id(true);
            $_SESSION['message'] = tr('Your account is no longer active. Please sign in again or contact an administrator.');
            if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'index.php') {
                header('Location: index.php');
                exit;
            }
        } else {
            $_SESSION['role'] = $liveUser['role'];
            $_SESSION['is_bea_responsible'] = !empty($liveUser['is_bea_responsible']);
        }
    }

    // Two-factor authentication required but not set up yet: nothing else
    // until it is (see auth_lib.php).
    if (!empty($_SESSION['must_setup_2fa'])
        && !in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), ['two_factor.php', 'logout.php', 'index.php', 'set_locale.php', 'get_notifications.php'], true)) {
        header('Location: two_factor.php');
        exit;
    }

    // The session's CSRF token exists before any form is shown.
    csrf_token();

    // Renew the session id periodically (session fixation).
    if (!isset($_SESSION['CREATED'])) {
        $_SESSION['CREATED'] = time();
    } elseif (time() - $_SESSION['CREATED'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['CREATED'] = time();
    }
}
