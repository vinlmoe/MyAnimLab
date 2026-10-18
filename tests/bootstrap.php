<?php

/**
 * Bootstrap of the automated tests (vendor/bin/phpunit, or composer test).
 *
 * Unit tests need nothing else. Integration and HTTP tests need a MySQL /
 * MariaDB server where they may create a throw-away database; they are
 * skipped when none is reachable. Connection, from the environment:
 *   TEST_DB_HOST (127.0.0.1), TEST_DB_PORT (3306), TEST_DB_USER (root),
 *   TEST_DB_PASSWORD (empty), TEST_DB_NAME (myanimlab_test — must contain
 *   "test": it is dropped and recreated from database/schema.sql).
 * The application's own .env database is never used.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/i18n.php';
require_once APP_ROOT . '/db_lib.php';
require_once APP_ROOT . '/animal_lib.php';
require_once APP_ROOT . '/experiment_acts_lib.php';
require_once APP_ROOT . '/training_lib.php';
require_once APP_ROOT . '/followup_lib.php';
require_once APP_ROOT . '/cage_files_lib.php';
require_once APP_ROOT . '/tools/migrations_lib.php';

require __DIR__ . '/TestDatabase.php';
require __DIR__ . '/DatabaseTestCase.php';
