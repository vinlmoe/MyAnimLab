<?php

declare(strict_types=1);

/** Procedures of the application linked to the functions of the training record. */
final class TrainingProcedureTest extends DatabaseTestCase
{
    public function testEveryProcedureNeedsARegulatoryFunction(): void
    {
        foreach (training_procedures() as $code => $p) {
            $this->assertArrayHasKey($p['function'], training_functions(), $code);
            $this->assertNotSame('', $p['url'], $code);
        }
        $this->assertSame('euthanasia', training_procedures()['killing']['function']);
        $this->assertSame([], array_diff(TRAINING_KILLING_EXIT_CODES, array_keys(ANIMAL_EXIT_CODES)));
    }

    public function testSupervisionIsCheckedFunctionByFunction(): void
    {
        $experimenter = TestDatabase::user($this->con);
        TestDatabase::training($this->con, $experimenter, 'initial', '2020-03-01', 'procedures', 70);
        TestDatabase::training($this->con, $experimenter, 'competence', '2020-09-01', 'procedures');
        $killer = TestDatabase::user($this->con, ['name' => 'Compétente Mise à mort']);
        TestDatabase::training($this->con, $killer, 'initial', '2020-03-01', 'euthanasia', 14);
        TestDatabase::training($this->con, $killer, 'competence', '2020-06-01', 'euthanasia');

        $this->assertFalse(training_needs_supervisor($this->con, $experimenter, 'procedures'));
        $this->assertTrue(training_needs_supervisor($this->con, $experimenter, 'euthanasia'));
        $this->assertFalse(training_needs_supervisor($this->con, $killer, 'euthanasia'));
        $this->assertArrayHasKey($killer, training_competent_supervisors($this->con, $experimenter, 'euthanasia'));
        $this->assertArrayNotHasKey($killer, training_competent_supervisors($this->con, $experimenter, 'procedures'));

        // Not competent to kill: the supervisor present is required.
        $this->assertSame('Compétente Mise à mort', training_posted_supervisor($this->con, $experimenter, 'euthanasia', (string) $killer, ''));
        $this->assertSame('Dr Externe', training_posted_supervisor($this->con, $experimenter, 'euthanasia', 'other', ' Dr Externe '));
        $this->assertNull(training_posted_supervisor($this->con, $killer, 'euthanasia', '', ''));
        $this->expectException(Exception::class);
        training_posted_supervisor($this->con, $experimenter, 'euthanasia', '', '');
    }

    public function testProcedurePagesRecallTheTrainingRecord(): void
    {
        $u = TestDatabase::user($this->con);
        TestDatabase::training($this->con, $u, 'initial', '2025-03-01', 'care', 35);
        $_SESSION['user_id'] = $u;
        $this->assertStringContainsString('training_book.php', training_procedure_banner($this->con, 'round'), 'trained, under supervision');
        $this->assertStringContainsString('training_book.php', training_procedure_banner($this->con, 'killing'), 'not trained');
        TestDatabase::training($this->con, $u, 'competence', '2025-09-01', 'care');
        $_SESSION['user_id'] = TestDatabase::user($this->con);
        $this->assertNotSame('', training_procedure_banner($this->con, 'round'));
        $_SESSION['user_id'] = $u;
        $this->assertSame('', training_procedure_banner($this->con, 'round'), 'competent alone');
    }
}
