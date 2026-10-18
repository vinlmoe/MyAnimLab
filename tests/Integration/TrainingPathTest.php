<?php

declare(strict_types=1);

/** Training path shown in the training record, and certificates attached afterwards. */
final class TrainingPathTest extends DatabaseTestCase
{
    /** @return array<string, array> steps by title */
    private function path(int $userId): array
    {
        $records = training_records($this->con, [$userId])[$userId] ?? [];
        $steps = [];
        foreach (training_path($records, training_status($this->con, $records, '2026-10-17')) as $step) {
            $steps[$step['title']] = $step;
        }
        return $steps;
    }

    public function testNewcomerIsGuidedToTheFirstSteps(): void
    {
        $steps = $this->path(TestDatabase::user($this->con));
        $this->assertCount(6, $steps);
        $this->assertSame('todo', $steps[tr('Initial diploma')]['state']);
        $this->assertSame('diploma', $steps[tr('Initial diploma')]['action']['category']);
        $this->assertSame('initial', $steps[tr('Initial regulatory training')]['action']['category']);
        $this->assertSame('waiting', $steps[tr('Supervised period and competence')]['state']);
        $this->assertSame('waiting', $steps[tr('Continuing training')]['state']);
    }

    public function testSupervisedPersonIsAskedForTheCompetenceAndTheCertificates(): void
    {
        $u = TestDatabase::user($this->con);
        TestDatabase::training($this->con, $u, 'diploma', '2020-07-01', null, null, 'validated', 'bac3');
        TestDatabase::training($this->con, $u, 'initial', '2025-03-01', 'procedures', 70);
        TestDatabase::training($this->con, $u, 'continuing', '2026-05-01', null, 7, 'pending');
        $steps = $this->path($u);
        $this->assertSame('done', $steps[tr('Initial diploma')]['state']);
        $competence = $steps[tr('Supervised period and competence')];
        $this->assertSame('warning', $competence['state']);
        $this->assertSame(['competence', 'procedures'], [$competence['action']['category'], $competence['action']['function']]);
        $this->assertSame('todo', $steps[tr('Continuing training')]['state']);
        $this->assertSame('warning', $steps[tr('Certificates')]['state'], 'no certificate attached');
        $this->assertSame('waiting', $steps[tr('Validation of the entries')]['state']);
    }

    public function testCompetentPersonUpToDateHasNothingLeft(): void
    {
        $u = TestDatabase::user($this->con);
        foreach ([
            TestDatabase::training($this->con, $u, 'diploma', '2015-07-01', null, null, 'validated', 'bac5'),
            TestDatabase::training($this->con, $u, 'initial', '2016-03-01', 'procedures', 70),
            TestDatabase::training($this->con, $u, 'competence', '2016-09-01', 'procedures'),
            TestDatabase::training($this->con, $u, 'continuing', '2025-01-15', null, 21),
        ] as $id) {
            $this->con->query("UPDATE training_records SET certificate_file = 'x.pdf' WHERE id = $id");
        }
        foreach ($this->path($u) as $title => $step) {
            $this->assertSame('done', $step['state'], $title);
        }
    }

    public function testCertificateIsAttachedOnceToAnEntryOfItsOwner(): void
    {
        $u = TestDatabase::user($this->con);
        $entry = TestDatabase::training($this->con, $u, 'initial', '2025-03-01', 'procedures', 70);
        $rejected = TestDatabase::training($this->con, $u, 'continuing', '2025-04-01', null, 7, 'rejected');
        $other = TestDatabase::user($this->con);

        training_attach_certificate($this->con, $entry, $u, str_repeat('a', 32) . '.pdf', 'ATT-42', false, $u);
        $this->assertSame('ATT-42', TestDatabase::value($this->con, "SELECT certificate_ref FROM training_records WHERE id = $entry"));
        $this->assertSame(1, (int) TestDatabase::value($this->con, "SELECT COUNT(*) FROM activity_log WHERE action = 'training_certificate_attached' AND entity_id = '$u'"));
        foreach ([
            'already attached' => fn() => training_attach_certificate($this->con, $entry, $u, str_repeat('b', 32) . '.pdf', null, true, $u),
            'entry of another person' => fn() => training_attach_certificate($this->con, $entry, $other, str_repeat('c', 32) . '.pdf', null, false, $other),
            'rejected entry' => fn() => training_attach_certificate($this->con, $rejected, $u, str_repeat('d', 32) . '.pdf', null, false, $u),
        ] as $case => $attempt) {
            try {
                $attempt();
                $this->fail("Accepted: $case");
            } catch (Exception $e) {
                $this->assertNotInstanceOf(mysqli_sql_exception::class, $e, $case);
            }
        }
    }
}
