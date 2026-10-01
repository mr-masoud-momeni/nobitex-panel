<?php

namespace Tests\Unit\Trading;

use App\Services\Trading\Indicators\Ema;
use App\Services\Trading\Indicators\Rsi;
use PHPUnit\Framework\TestCase;

class IndicatorsTest extends TestCase
{
    public function test_ema_uses_sma_as_initial_value_and_then_exponential_smoothing(): void
    {
        $ema = new Ema(20);

        $values = [
            100, 101, 99, 102, 98, 103, 97, 104, 96, 105,
            95, 106, 94, 107, 93, 108, 92, 109, 91, 110,
            90, 111, 89, 112, 88, 113, 87, 114, 86, 115,
            85, 116, 84, 117, 83, 118,
        ];

        $result = null;

        foreach ($values as $value) {
            $result = $ema->update((float) $value);
        }

        $this->assertTrue($ema->isReady());
        $this->assertEqualsWithDelta(100.5, $ema->update(118.0) ?? $result, 0.0000001);
    }

    public function test_rsi_uses_wilder_smoothing(): void
    {
        $rsi = new Rsi(14);

        $values = [
            100, 101, 99, 102, 98, 103, 97, 104, 96, 105,
            95, 106, 94, 107, 93, 108, 92, 109, 91, 110,
            90, 111, 89, 112, 88, 113, 87, 114, 86, 115,
            85, 116, 84, 117, 83, 118,
        ];

        foreach ($values as $value) {
            $last = $rsi->update((float) $value);
        }

        $this->assertTrue($rsi->isReady());
        $this->assertEqualsWithDelta(52.82785639797295, $last, 0.0000001);
    }

    public function test_indicators_are_not_ready_until_their_required_history_exists(): void
    {
        $ema = new Ema(3);
        $rsi = new Rsi(3);

        $this->assertNull($ema->update(100.0));
        $this->assertNull($ema->update(101.0));
        $this->assertNull($rsi->update(100.0));
        $this->assertNull($rsi->update(101.0));

        $this->assertNotNull($ema->update(102.0));
        $this->assertNull($rsi->update(102.0));
        $this->assertNotNull($rsi->update(103.0));
    }
}
