<?php

declare(strict_types=1);

/**
 * Room temperatures of the daily round: minimum and maximum of the day, or
 * the current temperature; checked values, append-only readings, the last
 * reading of the day counts.
 */
final class RoomTemperatureTest extends DatabaseTestCase
{
    public function testMinMaxReadingsAreCheckedAndKept(): void
    {
        $room = TestDatabase::unique('Room');
        record_room_temperature($this->con, $room, 'min_max', ['min' => '19,5', 'max' => '22'], null, 'Tester');
        record_room_temperature($this->con, $room, 'min_max', ['min' => '19.8', 'max' => '22.4'], null, 'Tester');

        $readings = room_temperatures_for_date($this->con, date('Y-m-d'))[$room];
        $this->assertCount(2, $readings, 'a correction is a new reading');
        $this->assertSame('min 19,8 °C · max 22,4 °C', room_temperature_text(end($readings)));
        $this->assertSame('19.5', $readings[0]['temp_min']);

        foreach ([['min' => '23', 'max' => '21'], ['min' => '', 'max' => '21'], ['min' => '20', 'max' => '95']] as $bad) {
            try {
                record_room_temperature($this->con, $room, 'min_max', $bad, null, 'Tester');
                $this->fail('Refused: ' . json_encode($bad));
            } catch (Exception $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertCount(2, room_temperatures_for_date($this->con, date('Y-m-d'))[$room]);

        $this->expectException(mysqli_sql_exception::class);
        $this->con->query("UPDATE room_temperatures SET temp_min = 10 WHERE room = '$room'");
    }

    public function testCurrentTemperatureAndMode(): void
    {
        $room = TestDatabase::unique('Room');
        record_room_temperature($this->con, $room, 'current', ['current' => '21,3'], null, 'Tester');
        $reading = room_temperatures_for_date($this->con, date('Y-m-d'))[$room][0];
        $this->assertSame('current', $reading['mode']);
        $this->assertSame('21,3 °C', room_temperature_text($reading));

        db_execute($this->con, "INSERT INTO settings (name, value) VALUES ('room_temperature_mode', 'current') ON DUPLICATE KEY UPDATE value = 'current'");
        $this->assertSame('current', room_temperature_mode($this->con));
        db_execute($this->con, "UPDATE settings SET value = 'unknown' WHERE name = 'room_temperature_mode'");
        $this->assertSame('min_max', room_temperature_mode($this->con));
    }
}
