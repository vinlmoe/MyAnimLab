<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * tools/migrate.php: pending migrations are applied once, in order, and
 * recorded; a database created before the tool is baselined. Runs in its own
 * throw-away database (migrations change the schema, outside transactions).
 */
final class MigrationsTest extends TestCase
{
    private ?mysqli $con = null;
    private string $db = '';
    private string $dir = '';

    protected function setUp(): void
    {
        if (!TestDatabase::connection()) {
            $this->markTestSkipped(TestDatabase::unavailableReason());
        }
        $c = TestDatabase::config();
        $this->db = $c['name'] . '_migrations';
        $this->con = new mysqli($c['host'], $c['user'], $c['password'], '', $c['port']);
        $this->con->query("DROP DATABASE IF EXISTS `{$this->db}`");
        $this->con->query("CREATE DATABASE `{$this->db}`");
        $this->con->select_db($this->db);
        $this->dir = sys_get_temp_dir() . '/migrations-' . getmypid();
        @mkdir($this->dir);
        file_put_contents($this->dir . '/001_first.sql', "CREATE TABLE notes_log (id int NOT NULL AUTO_INCREMENT, txt varchar(20), PRIMARY KEY (id));\n");
        file_put_contents($this->dir . '/002_second.sql', "-- a trigger, as in the real migrations\nCREATE TRIGGER notes_log_no_delete BEFORE DELETE ON notes_log\n  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only';\nINSERT INTO notes_log (txt) VALUES ('a');\n");
    }

    protected function tearDown(): void
    {
        if ($this->con) {
            $this->con->query("DROP DATABASE IF EXISTS `{$this->db}`");
            $this->con->close();
        }
        array_map('unlink', glob($this->dir . '/*.sql') ?: []);
        @rmdir($this->dir);
    }

    public function testPendingMigrationsAreAppliedOnceInOrder(): void
    {
        create_migrations_table($this->con);
        $this->assertSame(['001_first.sql', '002_second.sql'], array_keys(pending_migrations($this->con, $this->dir)));
        foreach (pending_migrations($this->con, $this->dir) as $name => $path) {
            apply_migration($this->con, $name, $path);
        }
        $this->assertSame(['001_first.sql', '002_second.sql'], applied_migrations($this->con));
        $this->assertSame([], pending_migrations($this->con, $this->dir));
        $this->assertSame(1, (int) TestDatabase::value($this->con, 'SELECT COUNT(*) FROM notes_log'));
    }

    public function testFailedMigrationStaysPending(): void
    {
        create_migrations_table($this->con);
        file_put_contents($this->dir . '/003_broken.sql', "ALTER TABLE missing_table ADD COLUMN x int;\n");
        foreach (pending_migrations($this->con, $this->dir) as $name => $path) {
            try {
                apply_migration($this->con, $name, $path);
            } catch (mysqli_sql_exception) {
                break;
            }
        }
        $this->assertSame(['001_first.sql', '002_second.sql'], applied_migrations($this->con));
        $this->assertSame(['003_broken.sql'], array_keys(pending_migrations($this->con, $this->dir)));
    }

    public function testBaselineRecordsWithoutRunning(): void
    {
        create_migrations_table($this->con);
        $this->assertSame(['001_first.sql'], baseline_migrations($this->con, '1', $this->dir));
        $this->assertSame(['002_second.sql'], array_keys(pending_migrations($this->con, $this->dir)));
        $this->assertSame(0, (int) $this->con->query("SHOW TABLES LIKE 'notes_log'")->num_rows, 'baseline runs nothing');
    }

    public function testSchemaIsUpToDate(): void
    {
        // database/schema.sql creates a database where no migration is pending.
        $this->assertSame([], pending_migrations(TestDatabase::connection()));
    }
}
