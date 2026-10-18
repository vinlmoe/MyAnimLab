<?php

declare(strict_types=1);

/**
 * Corrections of the entry / exit register (reason required, values
 * replaced kept) and protection of prescriptions and administrations.
 */
final class RegisterCorrectionsTest extends DatabaseTestCase
{
    private function animalRow(int $id): array
    {
        return db_row($this->con, 'SELECT * FROM mice WHERE id = ?', 'i', [$id]);
    }

    private function assertRefused(string $sql): void
    {
        try {
            $this->con->query($sql);
            $this->fail("Accepted: $sql");
        } catch (mysqli_sql_exception $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function testEntryCorrectionKeepsTheReplacedValues(): void
    {
        $admin = TestDatabase::user($this->con, ['role' => 'admin']);
        $animal = TestDatabase::animal($this->con, TestDatabase::cage($this->con));
        set_animal_origin($this->con, $animal, ['origin_type' => 'supplier', 'origin_detail' => 'Janvier', 'origin_reference' => 'BL-1', 'entry_date' => '2026-09-01'], $admin);
        $new = ['origin_type' => 'supplier', 'origin_detail' => 'Charles River', 'origin_reference' => 'BL-1', 'entry_date' => '2026-09-02'];

        try {
            correct_animal_entry($this->con, $this->animalRow($animal), $new, ' ', $admin, 'Admin');
            $this->fail('A correction without reason was accepted');
        } catch (Exception $e) {
            $this->assertSame('Janvier', $this->animalRow($animal)['origin_detail']);
        }

        correct_animal_entry($this->con, $this->animalRow($animal), $new, 'wrong supplier', $admin, 'Admin');
        $this->assertSame('Charles River', $this->animalRow($animal)['origin_detail']);
        $corrections = record_corrections($this->con, 'animal', $animal);
        $this->assertCount(1, $corrections);
        $this->assertSame('Janvier', $corrections[0]['previous']['origin_detail']);
        $this->assertSame('2026-09-01', $corrections[0]['previous']['entry_date']);
        $text = correction_changes_text($corrections[0]);
        $this->assertStringContainsString('Janvier → Charles River', $text);
        $this->assertStringContainsString('2026-09-01 → 2026-09-02', $text);
        $this->assertStringNotContainsString('BL-1', $text, 'unchanged values are not listed');

        $this->expectException(Exception::class);
        correct_animal_entry($this->con, $this->animalRow($animal), $new, 'again', $admin, 'Admin'); // nothing changed
    }

    public function testExitCorrectionUpdatesStatusAndClosedEnrollments(): void
    {
        $admin = TestDatabase::user($this->con, ['role' => 'admin']);
        $animal = TestDatabase::animal($this->con, TestDatabase::cage($this->con));
        $enrollment = TestDatabase::enroll($this->con, TestDatabase::experiment($this->con), $animal);
        exit_animal($this->con, $animal, 'retrouve_mort', '2026-10-01', 'found in the morning', null, $admin, 'Admin');
        $this->assertSame('dead', $this->animalRow($animal)['status']);

        correct_animal_exit($this->con, $animal, 'point_limite', '2026-09-30', 'endpoint reached', null, 'wrong code entered', $admin, 'Admin');
        $row = $this->animalRow($animal);
        $this->assertSame('sacrificed', $row['status']);
        $this->assertSame('point_limite', $row['exit_code']);
        $this->assertSame('2026-09-30', $row['exit_date']);
        $this->assertSame('2026-09-30 00:00:00', TestDatabase::value($this->con, "SELECT removed_at FROM experiment_animals WHERE id = $enrollment"));
        $this->assertSame(ANIMAL_EXIT_CODES['point_limite'][0], TestDatabase::value($this->con, "SELECT removal_reason FROM experiment_animals WHERE id = $enrollment"));

        $c = record_corrections($this->con, 'animal', $animal)[0];
        $this->assertSame('exit', $c['record']);
        $this->assertSame('retrouve_mort', $c['previous']['exit_code']);
        $this->assertSame('wrong code entered', $c['reason']);
        $this->assertStringContainsString('Retrouvé mort → Atteinte de point limite', correction_changes_text($c));
        $this->assertRefused('DELETE FROM record_corrections WHERE id = ' . (int) $c['id']);
    }

    public function testExitCorrectionIsRefusedWhenInvalid(): void
    {
        $animal = TestDatabase::animal($this->con, TestDatabase::cage($this->con));
        $attempts = [
            'still active' => fn() => correct_animal_exit($this->con, $animal, 'euthanasie', '2026-10-01', 'x', null, 'reason', null, null),
        ];
        exit_animal($this->con, TestDatabase::animal($this->con, TestDatabase::cage($this->con)), 'euthanasie', '2026-10-01', 'x', null, null, null);
        $exited = (int) TestDatabase::value($this->con, "SELECT MAX(id) FROM mice WHERE status = 'sacrificed'");
        $attempts += [
            'no reason' => fn() => correct_animal_exit($this->con, $exited, 'euthanasie', '2026-09-01', 'x', null, '', null, null),
            'export without destination' => fn() => correct_animal_exit($this->con, $exited, 'export', '2026-10-01', 'x', '', 'reason', null, null),
            'future date' => fn() => correct_animal_exit($this->con, $exited, 'euthanasie', date('Y-m-d', strtotime('+2 days')), 'x', null, 'reason', null, null),
            'nothing changed' => fn() => correct_animal_exit($this->con, $exited, 'euthanasie', '2026-10-01', 'x', null, 'reason', null, null),
        ];
        foreach ($attempts as $case => $attempt) {
            try {
                $attempt();
                $this->fail("Accepted: $case");
            } catch (Exception $e) {
                $this->assertNotInstanceOf(mysqli_sql_exception::class, $e, $case);
            }
        }
        $this->assertSame([], record_corrections($this->con, 'animal', $exited));
    }

    public function testPrescriptionsAndAdministrationsCannotBeRewritten(): void
    {
        $animal = TestDatabase::animal($this->con, TestDatabase::cage($this->con));
        [$product, $lot] = TestDatabase::medication($this->con);
        $this->con->query("INSERT INTO prescriptions (animal_id, product_id, prescriber_name, prescribed_on, dose_amount, dose_unit) VALUES ($animal, $product, 'Dr Vet', '2026-10-01', 2, 'mL')");
        $prescription = (int) $this->con->insert_id;
        $this->con->query("INSERT INTO prescription_animals (prescription_id, animal_id) VALUES ($prescription, $animal)");
        $this->assertRefused("UPDATE prescriptions SET dose_amount = 5 WHERE id = $prescription");
        $this->assertRefused("DELETE FROM prescriptions WHERE id = $prescription");
        $this->assertRefused("DELETE FROM prescription_animals WHERE prescription_id = $prescription");
        $this->con->query("UPDATE prescriptions SET status = 'completed' WHERE id = $prescription");
        $this->assertSame('completed', TestDatabase::value($this->con, "SELECT status FROM prescriptions WHERE id = $prescription"));

        $this->con->query("INSERT INTO medication_administrations (prescription_id, animal_id, medication_lot_id, administered_at, quantity_used, unit) VALUES ($prescription, $animal, $lot, NOW(), 1, 'mL')");
        $admin = (int) $this->con->insert_id;
        $this->assertRefused("UPDATE medication_administrations SET quantity_used = 0.5 WHERE id = $admin");
        $this->assertRefused("DELETE FROM medication_administrations WHERE id = $admin");
        $this->con->query("UPDATE medication_administrations SET cancelled_at = NOW(), cancelled_by_name = 'Admin', cancel_reason = 'wrong animal' WHERE id = $admin");
        $this->assertRefused("UPDATE medication_administrations SET cancel_reason = 'other' WHERE id = $admin");
    }
}
