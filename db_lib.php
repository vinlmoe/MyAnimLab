<?php

/**
 * Prepared queries in one call. Values are always bound, never written into
 * the SQL text, even when they are known to be integers.
 *
 *   db_rows($con, "SELECT * FROM mice WHERE cage_id = ?", 's', [$cageId])
 *   db_rows($con, "... WHERE id IN (" . db_placeholders(count($ids)) . ")", str_repeat('i', count($ids)), $ids)
 */

// Minimum length of a password, checked on the server wherever one is set
// (manage_users.php, reset_password.php) — security review S8. Defined here
// (loaded through dbcon.php on every page, including the public reset page).
if (!defined('PASSWORD_MIN_LENGTH')) {
    define('PASSWORD_MIN_LENGTH', 8);
}

/** All rows of a prepared query (associative arrays). */
function db_rows(mysqli $con, string $sql, string $types = '', array $params = []): array
{
    $stmt = $con->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...array_values($params));
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** First row of a prepared query, or null. */
function db_row(mysqli $con, string $sql, string $types = '', array $params = []): ?array
{
    return db_rows($con, $sql, $types, $params)[0] ?? null;
}

/** First column of the first row of a prepared query, or null. */
function db_value(mysqli $con, string $sql, string $types = '', array $params = []): mixed
{
    $row = db_row($con, $sql, $types, $params);
    return $row === null ? null : reset($row);
}

/** Run a prepared INSERT / UPDATE / DELETE; returns the number of affected rows. */
function db_execute(mysqli $con, string $sql, string $types = '', array $params = []): int
{
    $stmt = $con->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...array_values($params));
    }
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

/**
 * Message shown for a database error: the error itself (table and column
 * names, SQL) goes to the server log only.
 */
function db_error_message(string $error): string
{
    error_log('Database error in ' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'cli')) . ': ' . $error);
    return tr('A database error occurred; it has been recorded in the server log.');
}

/**
 * Message shown for an exception: the application's own messages as they
 * are, a database error replaced by a generic message (db_error_message).
 */
function error_message(Throwable $e): string
{
    return $e instanceof mysqli_sql_exception ? db_error_message($e->getMessage()) : $e->getMessage();
}

/** "?, ?, ?" for an IN (...) list of $count values (at least one). */
function db_placeholders(int $count): string
{
    return implode(', ', array_fill(0, max(1, $count), '?'));
}
