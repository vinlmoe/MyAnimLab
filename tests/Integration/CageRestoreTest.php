<?php

declare(strict_types=1);

/** Putting an archived cage back in service (administrators, with a reason). */
final class CageRestoreTest extends DatabaseTestCase
{
    public function testArchivedCageWithActiveAnimalsIsRestoredWithItsReason(): void
    {
        // A living animal in an archived cage exists only in data older than
        // the housing triggers (migration 007): recreate it with the trigger
        // dropped for a moment (DDL commits, as on a migrated database).
        $cage = TestDatabase::cage($this->con, false);
        $animal = TestDatabase::animal($this->con, $cage);
        $this->con->query("UPDATE mice SET status = 'dead' WHERE id = $animal");
        $this->assertTrue(retire_cage($this->con, $cage, 'Vide'));
        $this->con->commit();
        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        preg_match('/CREATE TRIGGER `mice_active_needs_cage_update`.*?END(?=\$\$)/s', $schema, $trigger);
        $this->con->query('DROP TRIGGER mice_active_needs_cage_update');
        try {
            $this->con->query("UPDATE mice SET status = 'active' WHERE id = $animal");
        } finally {
            $this->con->query($trigger[0]);
        }
        $this->con->begin_transaction();

        restore_cage($this->con, $cage, 'Sortie enregistrée par erreur', null, 'Admin');
        $this->assertSame('active', TestDatabase::value($this->con, "SELECT status FROM cages WHERE cage_id = '$cage'"));
        $this->assertSame('Sortie enregistrée par erreur', TestDatabase::value($this->con, "SELECT reason FROM cage_lifecycle WHERE cage_id = '$cage' AND event = 'restored'"));
        $this->assertStringContainsString('Sortie enregistrée par erreur', (string) TestDatabase::value($this->con, "SELECT details FROM activity_log WHERE action = 'restore' AND entity_id = '$cage'"));
    }

    public function testRestoreIsRefusedWithoutReasonEmptyOrNotArchived(): void
    {
        $empty = TestDatabase::cage($this->con, false);
        retire_cage($this->con, $empty, 'Vide');
        $active = TestDatabase::cage($this->con, false);
        TestDatabase::animal($this->con, $active);
        foreach ([
            'no reason' => fn() => restore_cage($this->con, $active, '  '),
            'empty cage' => fn() => restore_cage($this->con, $empty, 'Raison'),
            'not archived' => fn() => restore_cage($this->con, $active, 'Raison'),
        ] as $case => $attempt) {
            try {
                $attempt();
                $this->fail("Accepted: $case");
            } catch (Exception $e) {
                $this->assertNotInstanceOf(mysqli_sql_exception::class, $e, $case);
            }
        }
        $this->assertSame('archived', TestDatabase::value($this->con, "SELECT status FROM cages WHERE cage_id = '$empty'"));
    }
}
