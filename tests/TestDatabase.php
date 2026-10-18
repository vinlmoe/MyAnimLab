<?php

declare(strict_types=1);

/**
 * Throw-away test database, created once per test run from
 * database/schema.sql, with small fixture builders. Every fixture gets new
 * ids, so tests never depend on each other.
 */
final class TestDatabase
{
    private static ?mysqli $con = null;
    private static ?string $unavailable = null;
    private static int $seq = 0;

    /** Connection settings from the environment. */
    public static function config(): array
    {
        $env = fn(string $k, string $d): string => getenv($k) !== false ? (string) getenv($k) : $d;
        return [
            'host' => $env('TEST_DB_HOST', '127.0.0.1'),
            'port' => (int) $env('TEST_DB_PORT', '3306'),
            'user' => $env('TEST_DB_USER', 'root'),
            'password' => $env('TEST_DB_PASSWORD', ''),
            'name' => $env('TEST_DB_NAME', 'myanimlab_test'),
        ];
    }

    /** The connection, or null when no database server is reachable (reason in unavailableReason()). */
    public static function connection(): ?mysqli
    {
        if (self::$con || self::$unavailable !== null) {
            return self::$con;
        }
        $c = self::config();
        if (!str_contains($c['name'], 'test')) {
            self::$unavailable = 'TEST_DB_NAME must contain "test" (the database is dropped and recreated).';
            return null;
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $con = new mysqli($c['host'], $c['user'], $c['password'], '', $c['port']);
        } catch (mysqli_sql_exception $e) {
            self::$unavailable = 'No test database server: ' . $e->getMessage();
            return null;
        }
        $con->set_charset('utf8mb4');
        $con->query("SET time_zone = '+00:00'"); // the tests run in UTC (phpunit.xml)
        $con->query('DROP DATABASE IF EXISTS `' . $c['name'] . '`');
        $con->query('CREATE DATABASE `' . $c['name'] . '` CHARACTER SET utf8mb4');
        $con->select_db($c['name']);
        self::runSql($con, (string) file_get_contents(APP_ROOT . '/database/schema.sql'));
        return self::$con = $con;
    }

    public static function unavailableReason(): string
    {
        return self::$unavailable ?? '';
    }

    /**
     * Run an SQL script as the mysql client does: statements end with the
     * current delimiter at the end of a line; DELIMITER lines change it (for
     * triggers and procedures).
     */
    public static function runSql(mysqli $con, string $sql): void
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
    }

    public static function unique(string $prefix): string
    {
        return $prefix . '-' . getmypid() . '-' . (++self::$seq);
    }

    // ----- fixtures -----

    public static function user(mysqli $con, array $over = []): int
    {
        $u = $over + [
            'name' => self::unique('User'), 'username' => self::unique('user') . '@test.local', 'position' => 'Research Scientist',
            'role' => 'user', 'password' => password_hash('secret-pass', PASSWORD_DEFAULT), 'status' => 'approved', 'email_verified' => 1,
            'is_bea_responsible' => 0,
        ];
        $s = $con->prepare('INSERT INTO users (name, username, position, role, password, status, email_verified, is_bea_responsible) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $s->bind_param('ssssssii', $u['name'], $u['username'], $u['position'], $u['role'], $u['password'], $u['status'], $u['email_verified'], $u['is_bea_responsible']);
        $s->execute();
        return (int) $con->insert_id;
    }

    public static function cage(mysqli $con, bool $experimental = true): string
    {
        $id = self::unique('C');
        $s = $con->prepare("INSERT INTO cages (cage_id, room, status, is_experimental) VALUES (?, 'A', 'active', ?)");
        $flag = (int) $experimental;
        $s->bind_param('si', $id, $flag);
        $s->execute();
        return $id;
    }

    public static function animal(mysqli $con, string $cageId): int
    {
        $uid = self::unique('M');
        $s = $con->prepare("INSERT INTO mice (cage_id, animal_uid, sex, status) VALUES (?, ?, 'female', 'active')");
        $s->bind_param('ss', $cageId, $uid);
        $s->execute();
        return (int) $con->insert_id;
    }

    /** An active experiment (with its trial and protocol). Returns the experiment id. */
    public static function experiment(mysqli $con): int
    {
        $trial = self::unique('T');
        $con->query("INSERT INTO trials (trial_number, title, status) VALUES ('$trial', 'Trial', 'active')");
        $trialId = $con->insert_id;
        $proto = self::unique('P');
        $con->query("INSERT INTO protocols (trial_id, protocol_number, title, status) VALUES ($trialId, '$proto', 'Protocol', 'active')");
        $protocolId = $con->insert_id;
        $exp = self::unique('EXP');
        $con->query("INSERT INTO experiments (protocol_id, experiment_number, title, status) VALUES ($protocolId, '$exp', 'Experiment', 'active')");
        return (int) $con->insert_id;
    }

    public static function enroll(mysqli $con, int $experimentId, int $animalId, ?float $baseline = 25.0): int
    {
        $s = $con->prepare('INSERT INTO experiment_animals (experiment_id, animal_id, baseline_weight_g) VALUES (?, ?, ?)');
        $s->bind_param('iid', $experimentId, $animalId, $baseline);
        $s->execute();
        return (int) $con->insert_id;
    }

    /** A medication (mL) and one lot. Returns [product id, lot id]. */
    public static function medication(mysqli $con, float $stock = 10.0, ?float $concentration = 100.0, string $expiry = '+1 year', bool $narcotic = false): array
    {
        $name = self::unique('Drug');
        $unit = $concentration !== null ? 'mg/mL' : null;
        $narc = (int) $narcotic;
        $s = $con->prepare("INSERT INTO medication_products (name, default_unit, is_narcotic, concentration, concentration_unit) VALUES (?, 'mL', ?, ?, ?)");
        $s->bind_param('sids', $name, $narc, $concentration, $unit);
        $s->execute();
        $productId = (int) $con->insert_id;
        $lot = self::unique('L');
        $exp = date('Y-m-d', strtotime($expiry));
        $today = date('Y-m-d');
        $s = $con->prepare('INSERT INTO medication_lots (product_id, lot_number, expiry_date, received_at, initial_quantity, remaining_quantity) VALUES (?, ?, ?, ?, ?, ?)');
        $s->bind_param('isssdd', $productId, $lot, $exp, $today, $stock, $stock);
        $s->execute();
        $lotId = (int) $con->insert_id;
        $con->query("INSERT INTO medication_stock_movements (medication_lot_id, movement_type, quantity_delta, reason) VALUES ($lotId, 'receipt', $stock, 'test')");
        return [$productId, $lotId];
    }

    /** A validated training record entry. */
    public static function training(mysqli $con, int $userId, string $category, string $date, ?string $function = null, ?float $hours = null, string $status = 'validated', ?string $level = null): int
    {
        $title = self::unique('Training');
        $s = $con->prepare('INSERT INTO training_records (user_id, category, function_code, diploma_level, title, start_date, duration_hours, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $s->bind_param('isssssds', $userId, $category, $function, $level, $title, $date, $hours, $status);
        $s->execute();
        return (int) $con->insert_id;
    }

    public static function value(mysqli $con, string $sql): mixed
    {
        $row = $con->query($sql)->fetch_row();
        return $row[0] ?? null;
    }
}
