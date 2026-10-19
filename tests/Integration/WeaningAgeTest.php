<?php

declare(strict_types=1);

/**
 * Weaning age of a litter: its line's, else its species', else the
 * laboratory's; the line is the dam's, else the sire's.
 */
final class WeaningAgeTest extends DatabaseTestCase
{
    private function line(string $species, ?int $days = null): int
    {
        db_execute($this->con, 'INSERT INTO animal_lines (code, species, name, weaning_age_days) VALUES (?, ?, ?, ?)', 'sssi', [TestDatabase::unique('L'), $species, 'Line', $days]);
        return (int) $this->con->insert_id;
    }

    public function testLineThenSpeciesThenLaboratory(): void
    {
        db_execute($this->con, "INSERT INTO settings (name, value) VALUES ('weaning_age_days', '23') ON DUPLICATE KEY UPDATE value = '23'");
        $species = TestDatabase::unique('Gerbil');
        $plain = $this->line($species);
        $own = $this->line($species, 30);

        $this->assertSame([23, 'lab'], weaning_age_source($this->con, $plain));
        $this->assertSame([23, 'lab'], weaning_age_source($this->con, null));

        db_execute($this->con, 'INSERT INTO species_weaning_ages (species, weaning_age_days) VALUES (?, 26)', 's', [$species]);
        $this->assertSame([26, 'species'], weaning_age_source($this->con, $plain));
        $this->assertSame([30, 'line'], weaning_age_source($this->con, $own));
        $this->assertSame(30, weaning_age_days($this->con, $own));
    }

    public function testLitterLineIsTheDamsElseTheSires(): void
    {
        $cage = TestDatabase::cage($this->con, false);
        $dam = TestDatabase::animal($this->con, $cage);
        $sire = TestDatabase::animal($this->con, $cage);
        $damLine = $this->line('Mouse');
        $sireLine = $this->line('Mouse');
        db_execute($this->con, 'UPDATE mice SET line_id = ? WHERE id = ?', 'ii', [$sireLine, $sire]);

        $this->assertSame($sireLine, litter_line_id($this->con, $dam, $sire));
        db_execute($this->con, 'UPDATE mice SET line_id = ? WHERE id = ?', 'ii', [$damLine, $dam]);
        $this->assertSame($damLine, litter_line_id($this->con, $dam, $sire));
        $this->assertNull(litter_line_id($this->con, null, null));
    }
}
