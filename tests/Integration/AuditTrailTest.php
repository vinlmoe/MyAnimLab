<?php

declare(strict_types=1);

/**
 * Traceability: audit tables are append-only in the database, and a
 * correction of a daily follow-up keeps the values it replaced.
 */
final class AuditTrailTest extends DatabaseTestCase
{
    private function assertRefused(string $sql): void
    {
        try {
            $this->con->query($sql);
            $this->fail("Accepted: $sql");
        } catch (mysqli_sql_exception $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function testAuditTablesRefuseUpdatesAndDeletes(): void
    {
        $user = TestDatabase::user($this->con);
        $animal = TestDatabase::animal($this->con, TestDatabase::cage($this->con));
        log_activity($this->con, 'test', 'animal', (string) $animal, 'first', $user);
        $logId = $this->con->insert_id;
        log_animal_event($this->con, $animal, 'note', ['text' => 'first'], $user);
        $eventId = $this->con->insert_id;

        $this->assertRefused("UPDATE activity_log SET details = 'changed' WHERE id = $logId");
        $this->assertRefused("DELETE FROM activity_log WHERE id = $logId");
        $this->assertRefused("UPDATE animal_events SET created_by = NULL WHERE id = $eventId");
        $this->assertRefused("DELETE FROM animal_events WHERE id = $eventId");
        $this->assertSame('first', TestDatabase::value($this->con, "SELECT details FROM activity_log WHERE id = $logId"));
    }

    public function testRemovingAnAnimalOrAUserStillWorks(): void
    {
        // Foreign key cascades are not blocked by the append-only triggers:
        // the administrator's hard delete of an animal and the deletion of a
        // user keep working.
        $user = TestDatabase::user($this->con);
        $animal = TestDatabase::animal($this->con, TestDatabase::cage($this->con));
        log_animal_event($this->con, $animal, 'note', ['text' => 'x'], $user);
        log_activity($this->con, 'test', 'animal', (string) $animal, 'x', $user);
        $logId = $this->con->insert_id;

        $this->con->query("DELETE FROM mice WHERE id = $animal");
        $this->assertSame(0, (int) TestDatabase::value($this->con, "SELECT COUNT(*) FROM animal_events WHERE animal_id = $animal"));
        $this->con->query("DELETE FROM users WHERE id = $user");
        $this->assertNull(TestDatabase::value($this->con, "SELECT user_id FROM activity_log WHERE id = $logId"));
    }

    public function testFollowupCorrectionKeepsTheReplacedValues(): void
    {
        $user = TestDatabase::user($this->con, ['name' => 'Alice Martin']);
        $animal = TestDatabase::animal($this->con, TestDatabase::cage($this->con));
        $enrollment = TestDatabase::enroll($this->con, TestDatabase::experiment($this->con), $animal);
        $this->con->query("INSERT INTO experiment_followups (experiment_animal_id, observed_at, weight_g, clinical_score, observations, recorded_by)
                           VALUES ($enrollment, '2026-10-01', 24.5, 2, 'calm', $user)");
        $followupId = (int) $this->con->insert_id;
        $this->con->query("INSERT INTO experiment_followup_scores (followup_id, item_label, score) VALUES ($followupId, 'Posture', 2)");
        $this->con->query("INSERT INTO experiment_followup_observations (followup_id, label, value_type, unit, value_number) VALUES ($followupId, 'Temperature', 'number', '°C', 38.2)");

        $previous = followup_snapshot($this->con, $followupId);
        $this->assertSame('Alice Martin', $previous['recorded_by_name']);
        record_followup_revision($this->con, $followupId, $previous, 'wrong animal weighed', $user, 'Alice Martin');
        $this->con->query("UPDATE experiment_followups SET weight_g = 22 WHERE id = $followupId");

        $revisions = followup_revisions($this->con, $followupId);
        $this->assertCount(1, $revisions);
        $this->assertSame('wrong animal weighed', $revisions[0]['reason']);
        $this->assertSame('24.50', $revisions[0]['previous']['weight_g']);
        $this->assertSame([$followupId => 1], followup_revision_counts($this->con, [$followupId]));

        $summary = followup_revision_summary($revisions[0]['previous']);
        foreach (['24.5', 'Posture 2', 'Temperature', '38.2 °C', 'calm', 'Alice Martin'] as $part) {
            $this->assertStringContainsString($part, $summary);
        }
        $this->assertRefused('DELETE FROM experiment_followup_revisions WHERE id = ' . (int) $revisions[0]['id']);
    }
}
