<?php

declare(strict_types=1);

/**
 * Experimental acts: planning, confirmation (values, stock debit,
 * administration), refusals, correction (re-credit) and the supervisor
 * required from a person not yet competent.
 */
final class ExperimentActsTest extends DatabaseTestCase
{
    private int $experimentId;
    private string $cageId;
    private int $enrollmentId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->experimentId = TestDatabase::experiment($this->con);
        $this->cageId = TestDatabase::cage($this->con);
        $this->enrollmentId = TestDatabase::enroll($this->con, $this->experimentId, TestDatabase::animal($this->con, $this->cageId), 24.0);
        // A competent person (validated initial training and competence).
        $this->userId = TestDatabase::user($this->con);
        TestDatabase::training($this->con, $this->userId, 'initial', '2020-01-10', 'procedures', 70);
        TestDatabase::training($this->con, $this->userId, 'competence', '2020-06-01', 'procedures');
    }

    private function plan(array $act = [], array $fields = [], ?array $dates = null): int
    {
        $act += ['act_type' => 'injection', 'label' => 'Injection test', 'substance' => null, 'dose_amount' => null, 'dose_unit' => null,
            'route' => 'IP', 'product_id' => null, 'quantity_per_animal' => null, 'instructions' => null];
        [$actId, $n] = plan_experiment_act($this->con, $this->experimentId, $act, $fields, $dates ?? [date('Y-m-d')], [$this->enrollmentId], $this->userId, 'Tester');
        $this->assertGreaterThan(0, $n);
        return $actId;
    }

    private function row(int $actId, ?string $date = null): array
    {
        $date ??= date('Y-m-d');
        return $this->con->query("SELECT * FROM experiment_act_animals WHERE act_id = $actId AND planned_date = '$date'")->fetch_assoc();
    }

    private function confirm(int $rowId, array $raw, ?int $userId = null): int
    {
        return confirm_experiment_act($this->con, $rowId, $raw + ['done_at' => date('Y-m-d') . ' 09:00:00'], null, $userId ?? $this->userId, 'Tester');
    }

    public function testPlanningCreatesOneRowPerAnimalAndDate(): void
    {
        $other = TestDatabase::enroll($this->con, $this->experimentId, TestDatabase::animal($this->con, $this->cageId));
        $act = ['act_type' => 'gavage', 'label' => 'Gavage', 'substance' => null, 'dose_amount' => null, 'dose_unit' => null, 'route' => null,
            'product_id' => null, 'quantity_per_animal' => null, 'instructions' => null];
        [$actId, $n] = plan_experiment_act($this->con, $this->experimentId, $act, [], ['2026-01-02', '2026-01-01', '2026-01-02', 'bad'], [$this->enrollmentId, $other], $this->userId, 'Tester');
        $this->assertSame(4, $n); // 2 animals × 2 distinct valid dates
        $this->assertSame(4, (int) TestDatabase::value($this->con, "SELECT COUNT(*) FROM experiment_act_animals WHERE act_id = $actId AND status = 'planned'"));
    }

    public function testPlanningRefusesAnimalsOutsideTheExperiment(): void
    {
        $stranger = TestDatabase::enroll($this->con, TestDatabase::experiment($this->con), TestDatabase::animal($this->con, $this->cageId));
        $act = ['act_type' => 'bait', 'label' => 'Bait', 'substance' => null, 'dose_amount' => null, 'dose_unit' => null, 'route' => null,
            'product_id' => null, 'quantity_per_animal' => null, 'instructions' => null];
        $this->expectException(Exception::class);
        plan_experiment_act($this->con, $this->experimentId, $act, [], [date('Y-m-d')], [$stranger], $this->userId, 'Tester');
    }

    public function testConfirmationDebitsTheStockAndRecordsTheAdministration(): void
    {
        [$productId, $lotId] = TestDatabase::medication($this->con, 10.0);
        $actId = $this->plan(['product_id' => $productId, 'quantity_per_animal' => 0.2], [['label' => 'Volume', 'value_type' => 'number', 'unit' => 'mL', 'required' => 1]]);
        $row = $this->row($actId);
        $fieldId = (int) TestDatabase::value($this->con, "SELECT id FROM experiment_act_fields WHERE act_id = $actId");

        $this->confirm((int) $row['id'], ['status' => 'done', 'values' => [$fieldId => '0,2'], 'lot_id' => $lotId, 'quantity' => '0.2']);

        $this->assertSame('done', $this->row($actId)['status']);
        $this->assertEqualsWithDelta(9.8, (float) TestDatabase::value($this->con, "SELECT remaining_quantity FROM medication_lots WHERE id = $lotId"), 1e-9);
        $this->assertSame(1, (int) TestDatabase::value($this->con, "SELECT COUNT(*) FROM medication_administrations WHERE experiment_act_animal_id = {$row['id']} AND prescription_id IS NULL"));
        $this->assertEqualsWithDelta(-0.2, (float) TestDatabase::value($this->con, "SELECT SUM(quantity_delta) FROM medication_stock_movements WHERE medication_lot_id = $lotId AND movement_type = 'administration'"), 1e-9);
        $this->assertSame('0.2000', TestDatabase::value($this->con, "SELECT value_number FROM experiment_act_values WHERE act_animal_id = {$row['id']}"));
    }

    public function testRequiredInformationIsEnforced(): void
    {
        $actId = $this->plan([], [['label' => 'Food left', 'value_type' => 'number', 'unit' => 'g', 'required' => 1]]);
        $this->expectException(Exception::class);
        $this->confirm((int) $this->row($actId)['id'], ['status' => 'done', 'values' => []]);
    }

    public function testNotDoneNeedsAReason(): void
    {
        $actId = $this->plan();
        $rowId = (int) $this->row($actId)['id'];
        try {
            $this->confirm($rowId, ['status' => 'not_done', 'note' => '']);
            $this->fail('A reason is required');
        } catch (Exception) {
        }
        $this->confirm($rowId, ['status' => 'not_done', 'note' => 'Animal absent']);
        $this->assertSame('not_done', $this->row($actId)['status']);
    }

    public function testCannotConfirmBeforeThePlannedDate(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $actId = $this->plan([], [], [$tomorrow]);
        $this->expectException(Exception::class);
        $this->confirm((int) $this->row($actId, $tomorrow)['id'], ['status' => 'done']);
    }

    public function testExpiredLotAndInsufficientStockAreRefused(): void
    {
        [$productId, $expiredLot] = TestDatabase::medication($this->con, 10.0, 100.0, '-1 day');
        $actId = $this->plan(['product_id' => $productId, 'quantity_per_animal' => 0.2]);
        $rowId = (int) $this->row($actId)['id'];
        try {
            $this->confirm($rowId, ['status' => 'done', 'lot_id' => $expiredLot, 'quantity' => '0.2']);
            $this->fail('Expired lot accepted');
        } catch (Exception) {
        }
        [, $smallLot] = TestDatabase::medication($this->con, 0.1);
        $this->con->query("UPDATE medication_lots SET product_id = $productId WHERE id = $smallLot");
        $this->expectException(Exception::class);
        $this->confirm($rowId, ['status' => 'done', 'lot_id' => $smallLot, 'quantity' => '0.2']);
    }

    public function testCorrectionReCreditsTheLotAndKeepsTheHistory(): void
    {
        [$productId, $lotId] = TestDatabase::medication($this->con, 10.0);
        $actId = $this->plan(['product_id' => $productId, 'quantity_per_animal' => 0.5]);
        $rowId = (int) $this->row($actId)['id'];
        $this->confirm($rowId, ['status' => 'done', 'lot_id' => $lotId, 'quantity' => '0.5']);

        correct_experiment_act($this->con, $rowId, 'Wrong animal', $this->userId, 'Tester', false);

        $row = $this->row($actId);
        $this->assertSame('planned', $row['status']);
        $this->assertNull($row['done_at']);
        $this->assertEqualsWithDelta(10.0, (float) TestDatabase::value($this->con, "SELECT remaining_quantity FROM medication_lots WHERE id = $lotId"), 1e-9);
        $this->assertNotNull(TestDatabase::value($this->con, "SELECT cancelled_at FROM medication_administrations WHERE experiment_act_animal_id = $rowId"));
        $this->assertSame('Wrong animal', TestDatabase::value($this->con, "SELECT reason FROM experiment_act_corrections WHERE act_animal_id = $rowId"));
        // The register still balances: receipt − administration + correction = stock.
        $this->assertEqualsWithDelta(10.0, (float) TestDatabase::value($this->con, "SELECT SUM(quantity_delta) FROM medication_stock_movements WHERE medication_lot_id = $lotId"), 1e-9);

        // The act can be confirmed again.
        $this->confirm($rowId, ['status' => 'done', 'lot_id' => $lotId, 'quantity' => '0.5']);
        $this->assertSame('done', $this->row($actId)['status']);
    }

    public function testOnlyTheConfirmingPersonOrAnAdministratorCorrects(): void
    {
        $actId = $this->plan();
        $rowId = (int) $this->row($actId)['id'];
        $this->confirm($rowId, ['status' => 'not_done', 'note' => 'x']);
        $other = TestDatabase::user($this->con);
        try {
            correct_experiment_act($this->con, $rowId, 'mistake', $other, 'Other', false);
            $this->fail('Another user corrected the act');
        } catch (Exception) {
        }
        correct_experiment_act($this->con, $rowId, 'mistake', $other, 'Admin', true);
        $this->assertSame('planned', $this->row($actId)['status']);
    }

    public function testAPersonNotCompetentNeedsACompetentSupervisor(): void
    {
        $trainee = TestDatabase::user($this->con); // no training record
        $actId = $this->plan();
        $rowId = (int) $this->row($actId)['id'];
        try {
            $this->confirm($rowId, ['status' => 'done'], $trainee);
            $this->fail('Confirmed without supervisor');
        } catch (Exception) {
        }
        try {
            $notCompetent = TestDatabase::user($this->con);
            $this->confirm($rowId, ['status' => 'done', 'supervisor' => (string) $notCompetent], $trainee);
            $this->fail('A non-competent supervisor was accepted');
        } catch (Exception) {
        }
        $this->confirm($rowId, ['status' => 'done', 'supervisor' => (string) $this->userId], $trainee);
        $row = $this->row($actId);
        $this->assertSame($this->userId, (int) $row['supervised_by']);
        $this->assertStringContainsString('(', act_done_by_text($row));
    }

    public function testCageIsValidatedOnlyOnceActsAreConfirmed(): void
    {
        $this->con->query("INSERT INTO experiment_followups (experiment_animal_id, observed_at, weight_g) VALUES ({$this->enrollmentId}, CURDATE(), 24)");
        $actId = $this->plan();
        $progress = cage_experimental_progress($this->con, $this->cageId);
        $this->assertSame(1, $progress['done']);
        $this->assertSame(1, $progress['acts_pending']);
        $this->assertFalse(experimental_cage_complete($progress));
        $this->assertFalse(record_experimental_cage_check_if_complete($this->con, $this->cageId, $this->userId, 'Tester'));

        $this->confirm((int) $this->row($actId)['id'], ['status' => 'not_done', 'note' => 'Refused']);
        $this->assertTrue(record_experimental_cage_check_if_complete($this->con, $this->cageId, $this->userId, 'Tester'));
        $this->assertSame(1, (int) TestDatabase::value($this->con, "SELECT COUNT(*) FROM cage_checks WHERE cage_id = '{$this->cageId}' AND check_type = 'experimental'"));
    }
}
