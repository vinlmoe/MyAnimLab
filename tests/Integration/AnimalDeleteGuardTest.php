<?php

declare(strict_types=1);

/** An animal with a regulatory history is never deleted, only given an exit. */
final class AnimalDeleteGuardTest extends DatabaseTestCase
{
    private function deleted(int $animal): bool
    {
        try {
            $this->con->query("DELETE FROM mice WHERE id = $animal");
        } catch (mysqli_sql_exception) {
            return false;
        }
        return TestDatabase::value($this->con, "SELECT COUNT(*) FROM mice WHERE id = $animal") == 0;
    }

    public function testAnimalCreatedByMistakeCanBeDeleted(): void
    {
        $animal = TestDatabase::animal($this->con, TestDatabase::cage($this->con));
        log_animal_event($this->con, $animal, 'created');
        log_animal_movement($this->con, $animal, null, (string) TestDatabase::value($this->con, "SELECT cage_id FROM mice WHERE id = $animal"));
        $this->assertSame([], animal_regulatory_history($this->con, $animal));
        $this->assertTrue($this->deleted($animal));
    }

    public function testEachKindOfHistoryForbidsTheDeletion(): void
    {
        $cage = TestDatabase::cage($this->con);
        $other = TestDatabase::cage($this->con);
        $user = TestDatabase::user($this->con);
        $cases = [
            'exit' => fn(int $a) => $this->con->query("UPDATE mice SET status = 'dead' WHERE id = $a"),
            'experiment' => fn(int $a) => TestDatabase::enroll($this->con, TestDatabase::experiment($this->con), $a),
            'health report' => fn(int $a) => $this->con->query("INSERT INTO health_reports (animal_id, observed_at, category, severity, description) VALUES ($a, '2026-10-01', 'injury', 'minor', 'x')"),
            'transfer' => fn(int $a) => log_animal_movement($this->con, $a, $cage, $other, $user, 'x'),
            'register correction' => fn(int $a) => record_correction($this->con, 'animal', $a, 'entry', ['x' => 1], ['x' => 2], 'erreur', $user, 'Admin'),
            'breeder' => function (int $a) use ($cage) {
                $this->con->query("INSERT INTO breeding (cage_id, `cross`) VALUES ('$cage', 'x')");
                $this->con->query("INSERT INTO breeding_animals (breeding_id, animal_id, sex) VALUES ({$this->con->insert_id}, $a, 'female')");
            },
            'parent of a litter' => fn(int $a) => $this->con->query("INSERT INTO litters (cage_id, litter_dob, pups_alive, pups_dead, dam_id) VALUES ('$cage', '2026-09-01', 5, 0, $a)"),
            'event' => fn(int $a) => log_animal_event($this->con, $a, 'experiment_act', [], $user),
        ];
        foreach ($cases as $case => $record) {
            $animal = TestDatabase::animal($this->con, $cage);
            $record($animal);
            $this->assertNotSame([], animal_regulatory_history($this->con, $animal), $case);
            $this->assertFalse($this->deleted($animal), "$case: refused by the database");
        }
    }
}
