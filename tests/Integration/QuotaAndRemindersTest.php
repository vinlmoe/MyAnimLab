<?php

declare(strict_types=1);

require_once APP_ROOT . '/ethics_lib.php';

/**
 * Animals authorized per species by a project authorization, and the
 * morning reminders of the experimental acts.
 */
final class QuotaAndRemindersTest extends DatabaseTestCase
{
    /** Authorization + trial + protocol + experiment; returns [ethics number, experiment id]. */
    private function project(array $quotas): array
    {
        $number = TestDatabase::unique('APAFIS');
        $this->con->query("INSERT INTO iacuc (iacuc_id, iacuc_title, valid_from, valid_until) VALUES ('$number', 'Project', '2020-01-01', '2035-01-01')");
        foreach ($quotas as $species => $max) {
            $this->con->query("INSERT INTO iacuc_animal_quotas (iacuc_id, species, max_animals) VALUES ('$number', '$species', $max)");
        }
        $experimentId = TestDatabase::experiment($this->con);
        $this->con->query("UPDATE trials t JOIN protocols p ON p.trial_id = t.id JOIN experiments e ON e.protocol_id = p.id SET t.iacuc_id = '$number' WHERE e.id = $experimentId");
        return [$number, $experimentId];
    }

    private function animalOf(string $species): int
    {
        $code = substr(TestDatabase::unique('L'), -18);
        $this->con->query("INSERT INTO animal_lines (code, species, name) VALUES ('$code', '$species', 'Line')");
        $lineId = $this->con->insert_id;
        $animalId = TestDatabase::animal($this->con, TestDatabase::cage($this->con));
        $this->con->query("UPDATE mice SET line_id = $lineId WHERE id = $animalId");
        return $animalId;
    }

    public function testEnrollmentWithinTheQuota(): void
    {
        [$number, $exp] = $this->project(['Mouse' => 3]);
        $a = [$this->animalOf('mouse'), $this->animalOf('Mouse ')];
        $this->assertNull(quota_enrollment_error($this->con, $number, $a));
        foreach ($a as $id) {
            TestDatabase::enroll($this->con, $exp, $id);
        }
        $q = authorization_quotas($this->con, $number)['mouse'];
        $this->assertSame(2, $q['used']);
        $this->assertSame(66, $q['pct']);
    }

    public function testEnrollmentBeyondTheQuotaIsRefused(): void
    {
        [$number, $exp] = $this->project(['mouse' => 2]);
        TestDatabase::enroll($this->con, $exp, $this->animalOf('mouse'));
        $error = quota_enrollment_error($this->con, $number, [$this->animalOf('mouse'), $this->animalOf('mouse')]);
        $this->assertNotNull($error);
        $this->assertStringContainsString($number, $error);
    }

    public function testAnAnimalReusedInTheProjectCountsOnce(): void
    {
        [$number, $exp] = $this->project(['mouse' => 1]);
        $animal = $this->animalOf('mouse');
        TestDatabase::enroll($this->con, $exp, $animal);
        // Another experiment of the same trial.
        $protocolId = TestDatabase::value($this->con, "SELECT protocol_id FROM experiments WHERE id = $exp");
        $this->con->query("INSERT INTO experiments (protocol_id, experiment_number, title, status) VALUES ($protocolId, '" . TestDatabase::unique('EXP') . "', 'Second', 'active')");
        $this->assertNull(quota_enrollment_error($this->con, $number, [$animal]));
        $this->assertNotNull(quota_enrollment_error($this->con, $number, [$this->animalOf('mouse')]));
    }

    public function testSpeciesNotAuthorizedOrUnknown(): void
    {
        [$number] = $this->project(['mouse' => 10]);
        $this->assertStringContainsString('rat', (string) quota_enrollment_error($this->con, $number, [$this->animalOf('rat')]));
        $noLine = TestDatabase::animal($this->con, TestDatabase::cage($this->con));
        $this->assertNotNull(quota_enrollment_error($this->con, $number, [$noLine]));
    }

    public function testNoQuotaMeansNoLimit(): void
    {
        [$number] = $this->project([]);
        $this->assertNull(quota_enrollment_error($this->con, $number, [$this->animalOf('rat')]));
    }

    public function testAlertsAreSentOnceAt80And100Percent(): void
    {
        $admin = TestDatabase::user($this->con, ['role' => 'admin']);
        [$number, $exp] = $this->project(['mouse' => 5]);
        $count = fn() => (int) TestDatabase::value($this->con, "SELECT COUNT(*) FROM notifications WHERE user_id = $admin AND title LIKE '%$number%'");
        for ($i = 0; $i < 3; $i++) {
            TestDatabase::enroll($this->con, $exp, $this->animalOf('mouse'));
        }
        quota_send_alerts($this->con, $number);
        $this->assertSame(0, $count()); // 60 %
        TestDatabase::enroll($this->con, $exp, $this->animalOf('mouse'));
        quota_send_alerts($this->con, $number);
        quota_send_alerts($this->con, $number);
        $this->assertSame(1, $count()); // 80 %, once
        TestDatabase::enroll($this->con, $exp, $this->animalOf('mouse'));
        quota_send_alerts($this->con, $number);
        $this->assertSame(2, $count()); // 100 %
    }

    public function testMorningRemindersOfTheActs(): void
    {
        $planner = TestDatabase::user($this->con);
        $cageUser = TestDatabase::user($this->con);
        $admin = TestDatabase::user($this->con, ['role' => 'admin']);
        $experimentId = TestDatabase::experiment($this->con);
        $cage = TestDatabase::cage($this->con);
        $this->con->query("INSERT INTO cage_users (cage_id, user_id) VALUES ('$cage', $cageUser)");
        $enrollment = TestDatabase::enroll($this->con, $experimentId, TestDatabase::animal($this->con, $cage));
        $act = ['act_type' => 'bait', 'label' => 'Bait', 'substance' => null, 'dose_amount' => null, 'dose_unit' => null, 'route' => null,
            'product_id' => null, 'quantity_per_animal' => null, 'instructions' => null];
        plan_experiment_act($this->con, $experimentId, $act, [], ['2026-03-10', '2026-03-09'], [$enrollment], $planner, 'Planner');
        $this->con->query("DELETE FROM settings WHERE name = 'acts_reminders_last'");
        $this->con->query("UPDATE settings SET value = '07:30' WHERE name = 'acts_reminder_time'");
        $notes = fn(int $u) => (int) TestDatabase::value($this->con, "SELECT COUNT(*) FROM notifications WHERE user_id = $u AND link = 'daily_round.php'");

        $this->assertSame(0, acts_send_reminders($this->con, '2026-03-10 07:00'), 'Not before the time set');
        $this->assertGreaterThan(0, acts_send_reminders($this->con, '2026-03-10 07:45'));
        $this->assertSame(1, $notes($planner));   // today + overdue
        $this->assertSame(1, $notes($cageUser));  // act of the day in their cage
        $this->assertSame(1, $notes($admin));     // overdue act
        $this->assertSame(0, acts_send_reminders($this->con, '2026-03-10 12:00'), 'Once a day');
        $this->assertGreaterThan(0, (int) TestDatabase::value($this->con, "SELECT COUNT(*) FROM outbox WHERE recipient = (SELECT username FROM users WHERE id = $cageUser)"));
    }
}
