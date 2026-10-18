<?php

declare(strict_types=1);

/** Cancelling an administration of a prescription, with its reason. */
final class PrescriptionCancelTest extends DatabaseTestCase
{
    /** @return array{int, int, int, int} animal, prescription, lot, author */
    private function prescribed(int $planned = 2): array
    {
        $animal = TestDatabase::animal($this->con, TestDatabase::cage($this->con));
        [$product, $lot] = TestDatabase::medication($this->con);
        $this->con->query("INSERT INTO prescriptions (animal_id, product_id, prescriber_name, prescribed_on, dose_amount, dose_unit) VALUES ($animal, $product, 'Dr Vet', '2026-10-01', 1, 'mL')");
        $prescription = (int) $this->con->insert_id;
        $this->con->query("INSERT INTO prescription_animals (prescription_id, animal_id, planned_administrations) VALUES ($prescription, $animal, $planned)");
        return [$animal, $prescription, $lot, TestDatabase::user($this->con)];
    }

    private function stock(int $lot): float
    {
        return (float) TestDatabase::value($this->con, "SELECT remaining_quantity FROM medication_lots WHERE id = $lot");
    }

    public function testCancelledAdministrationIsKeptReCreditedAndNoLongerCounted(): void
    {
        [$animal, $prescription, $lot, $author] = $this->prescribed(1);
        $before = $this->stock($lot);
        $animals = pending_prescription_animals($this->con, $prescription);
        record_administration($this->con, $prescription, $animals, $lot, 0.5, date('Y-m-d H:i:s'), 'SC', '', $author, 'Auteur', 'Administration');
        $this->assertSame([], pending_prescription_animals($this->con, $prescription), 'all planned administrations given');
        $id = (int) TestDatabase::value($this->con, "SELECT id FROM medication_administrations WHERE prescription_id = $prescription");

        $this->assertSame($animal, cancel_prescription_administration($this->con, $id, 'Mauvais animal', $author, 'Auteur', false));
        $this->assertEqualsWithDelta($before, $this->stock($lot), 0.0001);
        $this->assertSame('Mauvais animal', TestDatabase::value($this->con, "SELECT cancel_reason FROM medication_administrations WHERE id = $id"));
        $this->assertSame(1, (int) TestDatabase::value($this->con, "SELECT COUNT(*) FROM medication_stock_movements WHERE administration_id = $id AND movement_type = 'correction' AND quantity_delta > 0"));
        $pending = pending_prescription_animals($this->con, $prescription);
        $this->assertCount(1, $pending, 'the animal is to treat again');
        $this->assertSame(0, $pending[0]['given_count']);
    }

    public function testCancellationIsRefusedWithoutReasonTwiceOrByAnotherUser(): void
    {
        [, $prescription, $lot, $author] = $this->prescribed();
        record_administration($this->con, $prescription, pending_prescription_animals($this->con, $prescription), $lot, 0.5, date('Y-m-d H:i:s'), 'SC', '', $author, 'Auteur', 'Administration');
        $id = (int) TestDatabase::value($this->con, "SELECT id FROM medication_administrations WHERE prescription_id = $prescription");
        $other = TestDatabase::user($this->con);
        foreach ([
            'no reason' => fn() => cancel_prescription_administration($this->con, $id, ' ', $author, 'Auteur', false),
            'another user' => fn() => cancel_prescription_administration($this->con, $id, 'Erreur', $other, 'Autre', false),
        ] as $case => $attempt) {
            try {
                $attempt();
                $this->fail("Accepted: $case");
            } catch (Exception $e) {
                $this->assertNotInstanceOf(mysqli_sql_exception::class, $e, $case);
            }
        }
        // An administrator may cancel the administration of someone else, once.
        cancel_prescription_administration($this->con, $id, 'Erreur de saisie', $other, 'Admin', true);
        $this->expectExceptionMessage('déjà annulée');
        cancel_prescription_administration($this->con, $id, 'Encore', $other, 'Admin', true);
    }
}
