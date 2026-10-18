<?php

declare(strict_types=1);

/**
 * Daily weaning reminders (process_reminders.php): litters to wean today or
 * tomorrow go to the users of the cage, overdue ones also to the
 * administrators and BEA responsibles; once a day, after the morning time.
 */
final class WeaningRemindersTest extends DatabaseTestCase
{
    private function litter(string $cage, string $expected, string $status = 'nursing'): int
    {
        db_execute($this->con, "INSERT INTO litters (cage_id, litter_dob, pups_alive, pups_dead, status, expected_weaning_date) VALUES (?, '2026-09-20', 6, 0, ?, ?)", 'sss', [$cage, $status, $expected]);
        return (int) $this->con->insert_id;
    }

    private function notifications(int $userId): array
    {
        return db_rows($this->con, "SELECT title, message, link FROM notifications WHERE user_id = ? AND type = 'reminder'", 'i', [$userId]);
    }

    public function testRemindersGoToCageUsersAndOverdueToAdministrators(): void
    {
        $this->con->query("DELETE FROM settings WHERE name = 'weaning_reminders_last'");
        $this->con->query("UPDATE litters SET status = 'closed' WHERE status = 'nursing'"); // only this test's litters
        $userA = TestDatabase::user($this->con);
        $userB = TestDatabase::user($this->con);
        $bea = TestDatabase::user($this->con, ['is_bea_responsible' => 1]);
        $cageA = TestDatabase::cage($this->con, false);
        $cageB = TestDatabase::cage($this->con, false);
        $this->con->query("INSERT INTO cage_users (cage_id, user_id) VALUES ('$cageA', $userA), ('$cageB', $userB)");
        $this->litter($cageA, '2026-10-19');           // due tomorrow
        $this->litter($cageA, '2026-10-30');           // not yet
        $this->litter($cageB, '2026-10-15');           // overdue
        $this->litter($cageB, '2026-10-01', 'weaned'); // already weaned

        $this->assertSame(0, litters_send_weaning_reminders($this->con, '2026-10-18 06:00'), 'not before the morning time');
        $this->assertGreaterThanOrEqual(3, litters_send_weaning_reminders($this->con, '2026-10-18 08:00'));

        $a = $this->notifications($userA);
        $this->assertCount(1, $a);
        $this->assertStringContainsString('2026-10-19', $a[0]['message']);
        $this->assertStringNotContainsString('2026-10-30', $a[0]['message']);
        $this->assertSame('litter_manage.php?cage_id=' . rawurlencode($cageA), $a[0]['link']);

        $b = $this->notifications($userB);
        $this->assertCount(1, $b);
        $this->assertStringContainsString('2026-10-15', $b[0]['message']);
        $this->assertStringNotContainsString('2026-10-01', $b[0]['message']);

        $this->assertCount(1, $this->notifications($bea), 'overdue weaning reaches the BEA responsibles');
        $this->assertStringNotContainsString('2026-10-19', $this->notifications($bea)[0]['message'], 'a weaning due goes to the cage users only');
        $email = TestDatabase::value($this->con, "SELECT username FROM users WHERE id = $userA");
        $this->assertSame(1, (int) db_value($this->con, 'SELECT COUNT(*) FROM outbox WHERE recipient = ?', 's', [$email]));

        $this->assertSame(0, litters_send_weaning_reminders($this->con, '2026-10-18 12:00'), 'once a day');
    }
}
