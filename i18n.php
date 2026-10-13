<?php

/**
 * Lightweight application localisation.
 *
 * Translation files return a flat key/value array.  French is deliberately
 * the default language for a new installation; a signed-in user's choice is
 * kept in both the session and their profile.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const APP_DEFAULT_LOCALE = 'fr';
const APP_SUPPORTED_LOCALES = ['fr', 'en'];

function current_locale(): string
{
    $locale = $_SESSION['locale'] ?? $_COOKIE['myvivarium_locale'] ?? APP_DEFAULT_LOCALE;
    return in_array($locale, APP_SUPPORTED_LOCALES, true) ? $locale : APP_DEFAULT_LOCALE;
}

function set_current_locale(string $locale): void
{
    if (!in_array($locale, APP_SUPPORTED_LOCALES, true)) {
        $locale = APP_DEFAULT_LOCALE;
    }

    $_SESSION['locale'] = $locale;
    if (!headers_sent()) {
        setcookie('myvivarium_locale', $locale, [
            'expires' => time() + 31536000,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function translation_catalogue(string $locale): array
{
    static $catalogues = [];
    if (!isset($catalogues[$locale])) {
        $path = __DIR__ . '/lang/' . $locale . '.php';
        $catalogues[$locale] = is_file($path) ? require $path : [];
    }
    return $catalogues[$locale];
}

function t(string $key, array $replace = []): string
{
    $messages = translation_catalogue(current_locale());
    $fallback = translation_catalogue(APP_DEFAULT_LOCALE);
    $message = $messages[$key] ?? $fallback[$key] ?? $key;

    foreach ($replace as $name => $value) {
        $message = str_replace('{' . $name . '}', (string) $value, $message);
    }
    return $message;
}

function locale_csrf_token(): string
{
    if (empty($_SESSION['locale_csrf_token'])) {
        $_SESSION['locale_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['locale_csrf_token'];
}
