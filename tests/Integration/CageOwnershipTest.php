<?php

declare(strict_types=1);

/** Bulk change of the responsible and users of cages (administrators). */
final class CageOwnershipTest extends DatabaseTestCase
{
    private function users(string $cage): array
    {
        $ids = array_map('intval', array_column(db_rows($this->con, 'SELECT user_id FROM cage_users WHERE cage_id = ? ORDER BY user_id', 's', [$cage]), 'user_id'));
        sort($ids);
        return $ids;
    }

    public function testResponsibleAndUsersChangeOnEverySelectedCage(): void
    {
        $pi = TestDatabase::user($this->con, ['position' => 'Principal Investigator']);
        [$a, $b, $c] = [TestDatabase::user($this->con), TestDatabase::user($this->con), TestDatabase::user($this->con)];
        $cage1 = TestDatabase::cage($this->con, false);
        $cage2 = TestDatabase::cage($this->con, false);
        $this->con->query("INSERT INTO cage_users (cage_id, user_id) VALUES ('$cage1', $a), ('$cage2', $b)");

        $this->assertSame(2, set_cages_ownership($this->con, [$cage1, $cage2], $pi, [$c], 'add', null));
        $this->assertSame($pi, (int) TestDatabase::value($this->con, "SELECT pi_name FROM cages WHERE cage_id = '$cage1'"));
        $this->assertSame([$a, $c], $this->users($cage1));
        $this->assertSame([$b, $c], $this->users($cage2));

        set_cages_ownership($this->con, [$cage1, $cage2], null, [$c], 'remove', null);
        $this->assertSame([$a], $this->users($cage1));
        set_cages_ownership($this->con, [$cage1, $cage2], null, [$b], 'replace', null);
        $this->assertSame([$b], $this->users($cage1));
        $this->assertSame([$b], $this->users($cage2));

        // Nothing to change: not counted, not logged again.
        $this->assertSame(0, set_cages_ownership($this->con, [$cage1, $cage2], null, [$b], 'replace', null));
        $log = (string) TestDatabase::value($this->con, "SELECT details FROM activity_log WHERE action = 'cage_ownership' AND entity_id = '$cage1' ORDER BY id DESC LIMIT 1");
        $this->assertStringContainsString('→', $log);
    }

    public function testInvalidChangesAreRefused(): void
    {
        $cage = TestDatabase::cage($this->con, false);
        $notPi = TestDatabase::user($this->con);
        $pending = TestDatabase::user($this->con, ['status' => 'pending']);
        foreach ([
            'responsible without PI position' => fn() => set_cages_ownership($this->con, [$cage], $notPi, [], 'keep', null),
            'user not approved' => fn() => set_cages_ownership($this->con, [$cage], null, [$pending], 'add', null),
            'nothing chosen' => fn() => set_cages_ownership($this->con, [$cage], null, [], 'keep', null),
            'add without users' => fn() => set_cages_ownership($this->con, [$cage], null, [], 'add', null),
        ] as $case => $attempt) {
            try {
                $attempt();
                $this->fail("Accepted: $case");
            } catch (Exception $e) {
                $this->assertNotInstanceOf(mysqli_sql_exception::class, $e, $case);
            }
        }
        $this->assertSame([], $this->users($cage));
    }
}
