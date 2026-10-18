<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Base of the tests that need the throw-away database: skipped when no
 * database server is reachable. Each test runs in a transaction rolled back
 * afterwards, unless it commits on purpose (the act functions expect the
 * caller's transaction, which this provides).
 */
abstract class DatabaseTestCase extends TestCase
{
    protected mysqli $con;

    protected function setUp(): void
    {
        $con = TestDatabase::connection();
        if (!$con) {
            $this->markTestSkipped(TestDatabase::unavailableReason());
        }
        $this->con = $con;
        $_SESSION = [];
        $this->con->begin_transaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->con)) {
            $this->con->rollback();
        }
        $_SESSION = [];
    }
}
