<?php

/**
 * Lightweight application localisation.
 *
 * Translation files return a flat key/value array.  French is deliberately
 * the default language for a new installation; a signed-in user's choice is
 * kept in both the session and their profile.
 *
 * Two lookups are available:
 *  - t('nav.home')            keyed messages from lang/<locale>.php;
 *  - tr('Animal not found.')  the English source text is the key, and
 *    lang/<locale>_text.php maps it to the translation (English needs no
 *    catalogue: an untranslated text is shown as written).
 *
 * This file does not start the session itself: pages start it (through
 * session_config.php) before it is loaded by dbcon.php, and CLI scripts
 * (reminders, alerts) simply use the default locale.
 */

const APP_DEFAULT_LOCALE = 'fr';
const APP_SUPPORTED_LOCALES = ['fr', 'en'];

function current_locale(): string
{
    $locale = $_SESSION['locale'] ?? $_COOKIE['myvivarium_locale'] ?? APP_DEFAULT_LOCALE;
    if (PHP_SAPI === 'cli') {
        $locale = APP_DEFAULT_LOCALE;
    }
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

/**
 * Translate an English source text into the current locale.
 *
 * {name} placeholders are replaced after translation, so the same catalogue
 * entry serves every value.
 */
function tr(string $text, array $replace = []): string
{
    $locale = current_locale();
    if ($locale !== 'en') {
        $catalogue = translation_catalogue($locale . '_text');
        if (isset($catalogue[$text]) && $catalogue[$text] !== '') {
            $text = $catalogue[$text];
        }
    }

    foreach ($replace as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }
    return $text;
}

/** tr() escaped for HTML text and attribute values. */
function trh(string $text, array $replace = []): string
{
    return htmlspecialchars(tr($text, $replace), ENT_QUOTES, 'UTF-8');
}

/** tr() as a JavaScript string literal (quotes included). */
function trj(string $text, array $replace = []): string
{
    return json_encode(tr($text, $replace), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}
