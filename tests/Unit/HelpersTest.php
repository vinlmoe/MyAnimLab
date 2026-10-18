<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure helpers: number parsing, display, doses, redirects, translations. */
final class HelpersTest extends TestCase
{
    public static function numbers(): array
    {
        return [
            'dot' => ['37.5', 37.5],
            'comma' => ['37,5', 37.5],
            'spaces' => [' 1 250,5 ', 1250.5],
            'negative' => ['-2', -2.0],
            'empty' => ['', null],
            'text' => ['abc', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('numbers')]
    public function testParseNumber(?string $raw, ?float $expected): void
    {
        $this->assertSame($expected, parse_number($raw));
    }

    public function testObservationValue(): void
    {
        $this->assertSame('37.5 °C', observation_value(['value_type' => 'number', 'value_number' => '37.5000', 'unit' => '°C']));
        $this->assertSame('12', observation_value(['value_type' => 'number', 'value_number' => '12.0000', 'unit' => null]));
        $this->assertSame('red', observation_value(['value_type' => 'text', 'value_text' => 'red']));
    }

    public function testActNumberTrimsZeros(): void
    {
        $this->assertSame('0.25', act_number('0.250'));
        $this->assertSame('3', act_number('3.000'));
        $this->assertSame('', act_number(null));
    }

    public function testActSummary(): void
    {
        $act = ['substance' => 'Ketamine', 'dose_amount' => '75.000', 'dose_unit' => 'mg/kg', 'route' => 'IP'];
        $this->assertSame('Ketamine · 75 mg/kg · IP', experiment_act_summary($act));
        $this->assertSame('', experiment_act_summary(['substance' => null, 'dose_amount' => null, 'dose_unit' => null, 'route' => null]));
    }

    private static function doseAct(array $over = []): array
    {
        return $over + ['product_id' => 1, 'dose_amount' => '75', 'dose_unit' => 'mg/kg', 'product_unit' => 'mL', 'concentration' => '100', 'concentration_unit' => 'mg/mL'];
    }

    public function testQuantityFromWeightMgPerKg(): void
    {
        // 75 mg/kg × 23 g ÷ 100 mg/mL = 0.01725 mL
        $this->assertEqualsWithDelta(0.00075, act_quantity_per_gram(self::doseAct())[0], 1e-12);
        $this->assertSame(0.017, act_suggested_quantity(self::doseAct(), 23.0));
    }

    public function testQuantityFromWeightConvertsUnits(): void
    {
        // 500 µg/kg × 30 g ÷ 1 mg/mL = 0.015 mL
        $act = self::doseAct(['dose_amount' => '500', 'dose_unit' => 'µg/kg', 'concentration' => '1']);
        $this->assertSame(0.015, act_suggested_quantity($act, 30.0));
        // 10 mL/kg × 25 g = 0.25 mL, without concentration
        $act = self::doseAct(['dose_amount' => '10', 'dose_unit' => 'mL/kg', 'concentration' => null, 'concentration_unit' => null]);
        $this->assertSame(0.25, act_suggested_quantity($act, 25.0));
    }

    public function testNoQuantityFromWeightWhenNotComputable(): void
    {
        $this->assertNull(act_quantity_per_gram(self::doseAct(['dose_unit' => 'mg/animal'])));
        $this->assertNull(act_quantity_per_gram(self::doseAct(['product_unit' => 'tablet'])));
        $this->assertNull(act_quantity_per_gram(self::doseAct(['concentration' => null])));
        $this->assertNull(act_quantity_per_gram(self::doseAct(['product_id' => null])));
        $this->assertNull(act_suggested_quantity(self::doseAct(), null));
    }

    public function testSafeLocalReturnRejectsOtherSites(): void
    {
        $this->assertSame('experiment_manage.php?protocol_id=3', safe_local_return('experiment_manage.php?protocol_id=3'));
        $this->assertNull(safe_local_return('https://evil.example/x.php'));
        $this->assertNull(safe_local_return('//evil.example/x.php'));
        $this->assertNull(safe_local_return('javascript:alert(1)'));
        $this->assertNull(safe_local_return(''));
    }

    public function testTranslationPlaceholders(): void
    {
        $this->assertSame('{n} acte(s) confirmé(s).', tr('{n} act(s) confirmed.'));
        $this->assertSame('3 acte(s) confirmé(s).', tr('{n} act(s) confirmed.', ['n' => 3]));
        $this->assertSame('&lt;b&gt;', trh('{x}', ['x' => '<b>']));
        $this->assertSame('"a\\u0027b"', trj('{x}', ['x' => "a'b"]));
    }

    public function testTrainingHelpers(): void
    {
        $this->assertSame('21 h (3 j)', training_hours_text(21.0, 7.0));
        $this->assertSame('3.5 h (0.5 j)', training_hours_text(3.5, 7.0));
        $diploma = ['title' => 'BTSA Anabiotec', 'diploma_level' => 'bac2', 'organisation' => 'Lycée', 'start_date' => '2015-07-01'];
        $this->assertStringContainsString('BTSA Anabiotec', training_diploma_text($diploma));
        $this->assertStringContainsString('(2015)', training_diploma_text($diploma));
    }
}
