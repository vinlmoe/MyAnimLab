<?php

/**
 * Database migrations (tools/migrate.php): the files of database/migrations
 * are applied in the order of their number, once each; the table
 * schema_migrations records those already applied. database/schema.sql
 * creates a database that is up to date (every migration recorded).
 */

const MIGRATIONS_DIR = __DIR__ . '/../database/migrations';

/**
 * Run an SQL script as the mysql client does: statements end with the
 * current delimiter at the end of a line; DELIMITER lines change it (for
 * triggers and procedures).
 */
function run_sql_script(mysqli $con, string $sql): void
{
    $delimiter = ';';
    $buffer = '';
    foreach (preg_split('/\R/', $sql) as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m)) {
            $delimiter = $m[1];
            continue;
        }
        $buffer .= $line . "\n";
        $trimmed = rtrim($buffer);
        if (str_ends_with($trimmed, $delimiter)) {
            $statement = trim(substr($trimmed, 0, -strlen($delimiter)));
            $buffer = '';
            if ($statement !== '' && !preg_match('/^(\s*--[^\n]*\n?)*\s*$/', $statement)) {
                $con->query($statement);
            }
        }
    }
    if (trim(preg_replace('/^\s*--.*$/m', '', $buffer)) !== '') {
        throw new RuntimeException('Unterminated SQL statement at the end of the script.');
    }
}

/** Migration files by name (e.g. 035_followup_revisions.sql => path), in order. */
function migration_files(string $dir = MIGRATIONS_DIR): array
{
    $files = [];
    foreach (glob($dir . '/[0-9][0-9][0-9]_*.sql') ?: [] as $path) {
        $files[basename($path)] = $path;
    }
    ksort($files, SORT_STRING);
    return $files;
}

function migrations_table_exists(mysqli $con): bool
{
    return (bool) $con->query("SHOW TABLES LIKE 'schema_migrations'")->num_rows;
}

function create_migrations_table(mysqli $con): void
{
    $con->query("CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `version` varchar(255) NOT NULL,
        `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`version`)
    )");
}

/** Names of the migrations already applied. */
function applied_migrations(mysqli $con): array
{
    return array_column($con->query('SELECT version FROM schema_migrations ORDER BY version')->fetch_all(MYSQLI_ASSOC), 'version');
}

/** Migration files not applied yet, in order. */
function pending_migrations(mysqli $con, string $dir = MIGRATIONS_DIR): array
{
    return array_diff_key(migration_files($dir), array_flip(applied_migrations($con)));
}

function record_migration(mysqli $con, string $name): void
{
    $stmt = $con->prepare('INSERT IGNORE INTO schema_migrations (version) VALUES (?)');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->close();
}

/** Apply one migration and record it (a failure leaves it pending). */
function apply_migration(mysqli $con, string $name, string $path): void
{
    run_sql_script($con, (string) file_get_contents($path));
    record_migration($con, $name);
}

/**
 * For a database created before this tool: record as applied, without
 * running them, the migrations up to the given number (e.g. "034").
 * Returns the names recorded.
 */
function baseline_migrations(mysqli $con, string $upTo, string $dir = MIGRATIONS_DIR): array
{
    $recorded = [];
    foreach (migration_files($dir) as $name => $path) {
        if (strcmp(substr($name, 0, 3), str_pad($upTo, 3, '0', STR_PAD_LEFT)) <= 0) {
            record_migration($con, $name);
            $recorded[] = $name;
        }
    }
    return $recorded;
}
