<?php

/**
 * Apply the database migrations that are not applied yet.
 *
 *   php tools/migrate.php                 apply the pending migrations, in order
 *   php tools/migrate.php --status        list the applied and pending migrations
 *   php tools/migrate.php --baseline=034  database created before this tool:
 *                                         record 001 to 034 as already applied
 *
 * Connection: the .env database settings. Migrations create tables and
 * triggers, which the application's account may not do (setup/setup.sh gives
 * it data rights only): set MIGRATION_DB_USERNAME and MIGRATION_DB_PASSWORD
 * (in .env or the environment) to an administrator account of the database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line. / Ce script doit être exécuté en ligne de commande.');
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/migrations_lib.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}
$env = fn(string $name, string $default = ''): string => (string) ($_ENV[$name] ?? getenv($name) ?: $default);

$options = getopt('', ['status', 'baseline:']);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $con = new mysqli(
        $env('DB_HOST', 'localhost'),
        $env('MIGRATION_DB_USERNAME', $env('DB_USERNAME', 'root')),
        $env('MIGRATION_DB_USERNAME') !== '' ? $env('MIGRATION_DB_PASSWORD') : $env('DB_PASSWORD'),
        $env('DB_DATABASE', 'myvivarium'),
        (int) $env('DB_PORT', '3306')
    );
    $con->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    fwrite(STDERR, 'Connexion à la base impossible : ' . $e->getMessage() . "\n");
    exit(1);
}

if (!migrations_table_exists($con)) {
    $hasTables = (bool) $con->query("SHOW TABLES LIKE 'users'")->num_rows;
    if (!$hasTables) {
        fwrite(STDERR, "Base vide : importez d'abord database/schema.sql (il enregistre toutes les migrations).\n");
        exit(1);
    }
    if (!isset($options['baseline'])) {
        fwrite(STDERR, "Cette base a été créée avant l'outil de migrations : indiquez la dernière migration déjà\n"
            . "appliquée, par exemple : php tools/migrate.php --baseline=034\n");
        exit(1);
    }
    create_migrations_table($con);
}

if (isset($options['baseline'])) {
    if (!preg_match('/^\d{1,3}$/', (string) $options['baseline'])) {
        fwrite(STDERR, "--baseline attend un numéro de migration (ex. 034).\n");
        exit(1);
    }
    foreach (baseline_migrations($con, (string) $options['baseline']) as $name) {
        echo "Enregistrée comme déjà appliquée : $name\n";
    }
}

$pending = pending_migrations($con);
if (isset($options['status'])) {
    foreach (applied_migrations($con) as $name) {
        echo "appliquée   $name\n";
    }
    foreach (array_keys($pending) as $name) {
        echo "en attente  $name\n";
    }
    exit(0);
}

if (!$pending) {
    echo "La base est à jour.\n";
    exit(0);
}
foreach ($pending as $migrationName => $path) {
    echo "Application de {$migrationName}… ";
    try {
        apply_migration($con, $migrationName, $path);
        echo "ok\n";
    } catch (Throwable $e) {
        echo "échec\n";
        fwrite(STDERR, $e->getMessage() . "\nLes migrations suivantes n'ont pas été appliquées.\n");
        exit(1);
    }
}
echo count($pending) . " migration(s) appliquée(s).\n";
