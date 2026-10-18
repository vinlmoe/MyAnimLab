<?php

declare(strict_types=1);

require_once APP_ROOT . '/ethics_lib.php';
require_once APP_ROOT . '/terrarium_lib.php';

/**
 * Terrarium populations: estimate from censuses and journal, group
 * treatments debiting the stock, health monitoring, captures creating
 * identified animals enrolled in an experiment, reminders.
 */
final class TerrariumTest extends DatabaseTestCase
{
    private function terrarium(array $over = []): array
    {
        $code = substr(TestDatabase::unique('L'), -18);
        $this->con->query("INSERT INTO animal_lines (code, species, name) VALUES ('$code', 'Xenopus', 'Wild type')");
        $lineId = (int) $this->con->insert_id;
        $id = terrarium_save($this->con, null, $over + [
            'code' => substr(TestDatabase::unique('T'), -28), 'name' => 'Tank', 'line_id' => $lineId, 'room' => 'A1',
            'established_on' => '2026-01-01', 'census_every_days' => 30, 'health_every_days' => 90,
        ], null);
        return load_terrarium($this->con, $id);
    }

    private function event(array $t, string $type, string $date, array $data): int
    {
        return terrarium_add_event($this->con, (int) $t['id'], $type, $date, $data, null, 'Tester');
    }

    public function testEstimateFromLastCensusAndJournal(): void
    {
        $t = $this->terrarium();
        $this->assertNull(terrarium_population($this->con, $t)['estimate']);
        $this->event($t, 'census', '2026-02-01', ['estimate' => 100, 'estimate_min' => 80, 'estimate_max' => 120]);
        $this->event($t, 'death', '2026-02-02', ['quantity' => 5]);
        $this->event($t, 'census', '2026-03-01', ['estimate' => 60]);
        $this->event($t, 'introduction', '2026-03-02', ['quantity' => 20, 'reference' => 'Batch 12']);
        $this->event($t, 'transfer_out', '2026-03-03', ['quantity' => 4]);
        $this->event($t, 'note', '2026-03-04', ['note' => 'Water changed']);
        $pop = terrarium_population($this->con, $t, '2026-03-10');
        $this->assertSame(76, $pop['estimate']);
        $this->assertSame('2026-03-31', $pop['census_due']);
        $this->assertFalse($pop['overdue']);
        $this->assertTrue(terrarium_population($this->con, $t, '2026-04-01')['overdue']);
    }

    public function testInvalidEntriesAreRefused(): void
    {
        $t = $this->terrarium();
        foreach ([
            ['census', ['estimate' => 50, 'estimate_min' => 60]],
            ['census', []],
            ['death', ['quantity' => 0]],
            ['note', ['note' => ' ']],
        ] as [$type, $data]) {
            try {
                $this->event($t, $type, '2026-02-01', $data);
                $this->fail("$type accepted");
            } catch (Exception $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->expectException(Exception::class);
        $this->event($t, 'census', date('Y-m-d', strtotime('+2 days')), ['estimate' => 3]);
    }

    public function testDuplicateCodeIsRefused(): void
    {
        $t = $this->terrarium();
        $this->expectExceptionMessage($t['code']);
        terrarium_save($this->con, null, ['code' => $t['code'], 'name' => 'Other', 'species' => 'Xenopus'], null);
    }

    public function testTreatmentDebitsTheLotAndSetsTheWithdrawal(): void
    {
        $t = $this->terrarium();
        [$productId, $lotId] = TestDatabase::medication($this->con, 10.0);
        $id = record_terrarium_treatment($this->con, $t, [
            'kind' => 'antiparasitic', 'treated_at' => date('Y-m-d') . ' 09:00', 'product_id' => $productId, 'lot_id' => $lotId,
            'quantity' => '2,5', 'dose' => '10 mg/L', 'withdrawal_until' => date('Y-m-d', strtotime('+10 days')),
        ], null, 'Tester');
        $this->assertSame('7.500', TestDatabase::value($this->con, "SELECT remaining_quantity FROM medication_lots WHERE id = $lotId"));
        $this->assertSame('-2.500', TestDatabase::value($this->con, "SELECT quantity_delta FROM medication_stock_movements WHERE terrarium_treatment_id = $id"));
        $this->assertSame(date('Y-m-d', strtotime('+10 days')), terrarium_withdrawal_until($this->con, (int) $t['id']));

        $this->expectExceptionMessage('lot');
        record_terrarium_treatment($this->con, $t, ['kind' => 'antibiotic', 'treated_at' => date('Y-m-d H:i'), 'product_id' => $productId, 'lot_id' => $lotId, 'quantity' => '50'], null, null);
    }

    public function testTreatmentOutsideTheStock(): void
    {
        $t = $this->terrarium();
        record_terrarium_treatment($this->con, $t, ['kind' => 'disinfection', 'treated_at' => date('Y-m-d H:i'), 'substance' => 'Virkon'], null, null);
        $this->assertNull(terrarium_withdrawal_until($this->con, (int) $t['id']));
        $this->expectException(Exception::class);
        record_terrarium_treatment($this->con, $t, ['kind' => 'other', 'treated_at' => date('Y-m-d H:i')], null, null);
    }

    public function testCaptureCreatesAnimalsAndEnrolls(): void
    {
        $user = TestDatabase::user($this->con);
        $t = $this->terrarium();
        $this->event($t, 'census', date('Y-m-d', strtotime('-1 day')), ['estimate' => 40]);
        $experimentId = TestDatabase::experiment($this->con);
        $r = capture_from_terrarium($this->con, $t, ['count' => 3, 'date' => date('Y-m-d'), 'cage' => 'new', 'sex' => 'female',
            'experiment_id' => $experimentId, 'baseline_weight' => '12,5'], $user, 'Tester');
        $this->assertCount(3, $r['animal_ids']);
        $ids = implode(',', $r['animal_ids']);
        $this->assertSame(3, (int) TestDatabase::value($this->con, "SELECT COUNT(*) FROM mice WHERE id IN ($ids) AND origin_type = 'population_capture' AND cage_id = '{$r['cage_id']}' AND sex = 'female'"));
        $this->assertSame(3, (int) TestDatabase::value($this->con, "SELECT COUNT(*) FROM experiment_animals WHERE experiment_id = $experimentId AND animal_id IN ($ids) AND baseline_weight_g = 12.5"));
        $this->assertSame('A1', TestDatabase::value($this->con, "SELECT room FROM cages WHERE cage_id = '{$r['cage_id']}'"));
        $this->assertSame(37, terrarium_population($this->con, $t)['estimate']);
    }

    public function testCaptureSpreadOverSeveralCages(): void
    {
        $t = $this->terrarium();
        $existing = TestDatabase::cage($this->con, false);
        $before = (int) TestDatabase::value($this->con, "SELECT next_seq FROM animal_lines WHERE id = {$t['line_id']}");
        $r = capture_from_terrarium($this->con, $t, ['date' => date('Y-m-d'), 'groups' => [
            ['cage' => '', 'count' => 4, 'sex' => 'male'],
            ['cage' => 'new', 'count' => 4, 'sex' => 'female'],
            ['cage' => $existing, 'count' => 2, 'sex' => ''],
            ['cage' => '', 'count' => ''], // empty row of the form
        ]], null, 'Tester');
        $this->assertCount(10, $r['animal_ids']);
        $this->assertCount(3, $r['cage_ids']);
        $this->assertSame($existing, $r['cage_ids'][2]);
        $this->assertSame(4, (int) TestDatabase::value($this->con, "SELECT COUNT(*) FROM mice WHERE cage_id = '{$r['cage_ids'][0]}' AND sex = 'male'"));
        $this->assertSame(4, (int) TestDatabase::value($this->con, "SELECT COUNT(*) FROM mice WHERE cage_id = '{$r['cage_ids'][1]}' AND sex = 'female'"));
        // Identifiers numbered automatically and consecutively from the line.
        $uids = array_column($this->con->query('SELECT animal_uid FROM mice WHERE id IN (' . implode(',', $r['animal_ids']) . ') ORDER BY id')->fetch_all(MYSQLI_ASSOC), 'animal_uid');
        $code = TestDatabase::value($this->con, "SELECT code FROM animal_lines WHERE id = {$t['line_id']}");
        $this->assertSame($code . '-' . str_pad((string) $before, 4, '0', STR_PAD_LEFT), $uids[0]);
        $this->assertSame($code . '-' . str_pad((string) ($before + 9), 4, '0', STR_PAD_LEFT), $uids[9]);
        $this->assertSame(10, (int) TestDatabase::value($this->con, "SELECT quantity FROM terrarium_events WHERE id = {$r['event_id']}"));

        $this->expectException(Exception::class);
        capture_from_terrarium($this->con, $t, ['groups' => [['cage' => 'P-NOPE', 'count' => 1]]], null, null);
    }

    public function testWithdrawalBlocksCaptureForAnExperiment(): void
    {
        $t = $this->terrarium();
        record_terrarium_treatment($this->con, $t, ['kind' => 'antibiotic', 'treated_at' => date('Y-m-d H:i'), 'substance' => 'Enrofloxacin',
            'withdrawal_until' => date('Y-m-d', strtotime('+5 days'))], null, null);
        $experimentId = TestDatabase::experiment($this->con);
        try {
            capture_from_terrarium($this->con, $t, ['count' => 1, 'cage' => 'new', 'experiment_id' => $experimentId], null, null);
            $this->fail('Capture for an experiment during the withdrawal period');
        } catch (Exception $e) {
            $this->assertStringContainsString(date('Y-m-d', strtotime('+5 days')), $e->getMessage());
        }
        try {
            capture_from_terrarium($this->con, $t, ['count' => 1, 'cage' => 'new'], null, null);
            $this->fail('Capture without confirmation during the withdrawal period');
        } catch (Exception $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $r = capture_from_terrarium($this->con, $t, ['count' => 2, 'cage' => 'new', 'ignore_withdrawal' => 1], null, null);
        $this->assertCount(2, $r['animal_ids']);
    }

    public function testHealthChecksAndScreeningResult(): void
    {
        $admin = TestDatabase::user($this->con, ['role' => 'admin']);
        $t = $this->terrarium();
        $alerts = fn() => (int) TestDatabase::value($this->con, "SELECT COUNT(*) FROM notifications WHERE user_id = $admin AND link LIKE 'terrarium_view.php?id={$t['id']}%' AND title LIKE '%{$t['code']}%'");
        record_terrarium_health_check($this->con, $t, ['check_type' => 'environment', 'checked_on' => date('Y-m-d'), 'temperature_c' => '21,5', 'humidity_pct' => 70], null, 'Tester');
        $this->assertSame(0, $alerts());
        $id = record_terrarium_health_check($this->con, $t, ['check_type' => 'screening', 'checked_on' => date('Y-m-d'), 'sample_type' => 'Swab',
            'agents_screened' => 'Batrachochytrium dendrobatidis', 'laboratory' => 'Lab'], null, 'Tester');
        $h = terrarium_health_summary($this->con, $t);
        $this->assertSame(1, $h['pending']);
        $this->assertNull($h['alert']);
        record_terrarium_screening_result($this->con, $t, $id, 'positive', 'qPCR positive', date('Y-m-d'), 'Vet');
        $h = terrarium_health_summary($this->con, $t);
        $this->assertSame(0, $h['pending']);
        $this->assertSame('positive', $h['alert']['result']);
        $this->assertSame(1, $alerts());
        record_terrarium_health_check($this->con, $t, ['check_type' => 'environment', 'checked_on' => date('Y-m-d'), 'humidity_pct' => 75], null, null);
        $this->assertNotNull(terrarium_health_summary($this->con, $t)['alert'], 'An environment check does not clear the alert');
        record_terrarium_health_check($this->con, $t, ['check_type' => 'observation', 'checked_on' => date('Y-m-d'), 'status' => 'normal'], null, null);
        $this->assertNull(terrarium_health_summary($this->con, $t)['alert']);
        $this->expectException(Exception::class);
        record_terrarium_screening_result($this->con, $t, $id, 'negative', null, null, null);
    }

    public function testAbnormalCheckNeedsSignsAndAlerts(): void
    {
        $owner = TestDatabase::user($this->con);
        $t = $this->terrarium(['responsible_user_id' => $owner]);
        try {
            record_terrarium_health_check($this->con, $t, ['check_type' => 'observation', 'checked_on' => date('Y-m-d'), 'status' => 'abnormal'], null, null);
            $this->fail('Abnormal check without signs');
        } catch (Exception $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        record_terrarium_health_check($this->con, $t, ['check_type' => 'observation', 'checked_on' => date('Y-m-d'), 'status' => 'abnormal', 'signs' => 'Red legs', 'sick_count' => 3], null, null);
        $this->assertSame(1, (int) TestDatabase::value($this->con, "SELECT COUNT(*) FROM notifications WHERE user_id = $owner AND message LIKE '%Red legs%'"));
    }

    public function testRemindersOfOverdueCensusAndHealthCheck(): void
    {
        $owner = TestDatabase::user($this->con);
        $t = $this->terrarium(['responsible_user_id' => $owner, 'established_on' => '2025-01-01']);
        $this->con->query("DELETE FROM settings WHERE name = 'terrarium_reminders_last'");
        $count = fn() => (int) TestDatabase::value($this->con, "SELECT COUNT(*) FROM notifications WHERE user_id = $owner AND link LIKE 'terrarium_view.php?id={$t['id']}%'");
        $this->assertGreaterThanOrEqual(2, terrarium_send_reminders($this->con));
        $this->assertSame(2, $count()); // census and health check
        $this->assertSame(0, terrarium_send_reminders($this->con), 'Once a day');
        $this->con->query("DELETE FROM settings WHERE name = 'terrarium_reminders_last'");
        terrarium_send_reminders($this->con);
        $this->assertSame(2, $count(), 'Not again within the week');
    }
}
