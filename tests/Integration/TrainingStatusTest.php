<?php

declare(strict_types=1);

/**
 * Training record compliance: functions held, continuing training over the
 * rolling period (3 days / 6 years by default), deadlines, initial diploma.
 */
final class TrainingStatusTest extends DatabaseTestCase
{
    private function trainingStatus(int $userId, string $today = '2026-10-17'): array
    {
        return training_status($this->con, training_records($this->con, [$userId])[$userId] ?? [], $today);
    }

    public function testNoRegulatoryTraining(): void
    {
        $s = $this->trainingStatus(TestDatabase::user($this->con));
        $this->assertSame('not_applicable', $s['cpd']['state']);
        $this->assertSame('none', $s['functions']['procedures']['state']);
        $this->assertNull($s['diploma']);
    }

    public function testTrainedButNotYetCompetentWorksUnderSupervision(): void
    {
        $u = TestDatabase::user($this->con);
        TestDatabase::training($this->con, $u, 'initial', '2024-03-01', 'procedures', 70);
        $s = $this->trainingStatus($u);
        $this->assertSame('supervised', $s['functions']['procedures']['state']);
        $this->assertFalse($s['ok']);
        $this->assertTrue(training_needs_supervisor($this->con, $u));
    }

    public function testFirstYearsAreToComplete(): void
    {
        $u = TestDatabase::user($this->con);
        TestDatabase::training($this->con, $u, 'initial', '2024-03-01', 'procedures', 70);
        $s = $this->trainingStatus($u);
        $this->assertSame('to_complete', $s['cpd']['state']);
        $this->assertSame('2030-03-01', $s['cpd']['deadline']);
    }

    public function testLateAfterTheFirstPeriodWithoutEnoughTraining(): void
    {
        $u = TestDatabase::user($this->con);
        TestDatabase::training($this->con, $u, 'initial', '2018-03-16', 'procedures', 70);
        TestDatabase::training($this->con, $u, 'continuing', '2023-06-01', null, 7);
        $s = $this->trainingStatus($u);
        $this->assertSame('late', $s['cpd']['state']);
        $this->assertSame(7.0, $s['cpd']['hours']);
        $this->assertSame(21.0, $s['cpd']['required_hours']);
    }

    public function testUpToDateAndItsDeadline(): void
    {
        $u = TestDatabase::user($this->con);
        TestDatabase::training($this->con, $u, 'initial', '2018-03-16', 'procedures', 70);
        TestDatabase::training($this->con, $u, 'competence', '2018-09-01', 'procedures');
        TestDatabase::training($this->con, $u, 'continuing', '2021-01-10', null, 14);
        TestDatabase::training($this->con, $u, 'continuing', '2023-06-01', null, 7);
        $s = $this->trainingStatus($u);
        // 21 h counted; the 2021 module leaves the period on 2027-01-10 (within 180 days).
        $this->assertSame('expiring', $s['cpd']['state']);
        $this->assertSame('2027-01-10', $s['cpd']['deadline']);
        $this->assertSame('ok', $this->trainingStatus($u, '2026-01-01')['cpd']['state']);
        $this->assertSame('competent', $s['functions']['procedures']['state']);
        $this->assertTrue($s['ok']);
    }

    public function testPendingAndRejectedEntriesDoNotCount(): void
    {
        $u = TestDatabase::user($this->con);
        TestDatabase::training($this->con, $u, 'initial', '2018-03-16', 'procedures', 70);
        TestDatabase::training($this->con, $u, 'continuing', '2025-01-10', null, 21, 'pending');
        TestDatabase::training($this->con, $u, 'continuing', '2025-02-10', null, 21, 'rejected');
        $s = $this->trainingStatus($u);
        $this->assertSame('late', $s['cpd']['state']);
        $this->assertSame(1, $s['pending']);
    }

    public function testInitialDiplomaIsTheMostRecentValidatedOne(): void
    {
        $u = TestDatabase::user($this->con);
        TestDatabase::training($this->con, $u, 'diploma', '2012-07-01', null, null, 'validated', 'bac2');
        TestDatabase::training($this->con, $u, 'diploma', '2015-07-01', null, null, 'validated', 'bac5');
        TestDatabase::training($this->con, $u, 'diploma', '2019-07-01', null, null, 'pending', 'phd');
        $this->assertSame('bac5', $this->trainingStatus($u)['diploma']['diploma_level']);
    }

    public function testCompetentSupervisorsExcludeTheUserAndTheNotCompetent(): void
    {
        $competent = TestDatabase::user($this->con);
        TestDatabase::training($this->con, $competent, 'initial', '2019-01-01', 'procedures', 70);
        TestDatabase::training($this->con, $competent, 'competence', '2019-06-01', 'procedures');
        $trainee = TestDatabase::user($this->con);
        $list = training_competent_supervisors($this->con, $trainee);
        $this->assertArrayHasKey($competent, $list);
        $this->assertArrayNotHasKey($trainee, $list);
    }
}
