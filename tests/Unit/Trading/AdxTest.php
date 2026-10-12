<?php

namespace Tests\Unit\Trading;

use App\Services\Trading\Indicators\Adx;
use PHPUnit\Framework\TestCase;

class AdxTest extends TestCase
{
    public function test_adx_and_directional_indicators_identify_a_persistent_uptrend(): void
    {
        $adx = new Adx(5);

        for ($i = 0; $i < 40; $i++) {
            $close = 100.0 + ($i * 2.0);
            $values = $adx->updateCandle($close + 1.0, $close - 1.0, $close);
        }

        $this->assertTrue($adx->isReady());
        $this->assertEqualsWithDelta(100.0, $values['adx'], 0.000001);
        $this->assertGreaterThan(0.0, $values['plus_di']);
        $this->assertEqualsWithDelta(0.0, $values['minus_di'], 0.000001);
    }

    public function test_adx_waits_for_sufficient_history_and_can_be_reset(): void
    {
        $adx = new Adx(3);

        $this->assertNull($adx->updateCandle(101.0, 99.0, 100.0)['adx']);
        $this->assertNull($adx->updateCandle(102.0, 100.0, 101.0)['adx']);
        $this->assertFalse($adx->isReady());

        for ($i = 2; $i < 20; $i++) {
            $close = 100.0 + $i;
            $values = $adx->updateCandle($close + 1.0, $close - 1.0, $close);
        }

        $this->assertTrue($adx->isReady());

        $adx->reset();

        $this->assertFalse($adx->isReady());
        $this->assertNull($adx->value());
        $this->assertNull($adx->plusDi());
        $this->assertNull($adx->minusDi());
    }
}
