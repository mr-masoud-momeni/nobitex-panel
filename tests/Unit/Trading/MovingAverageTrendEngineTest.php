<?php

namespace Tests\Unit\Trading;

use App\Models\Strategy;
use App\Models\Trade;
use App\Services\Trading\MovingAverageTrendEngine;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class MovingAverageTrendEngineTest extends TestCase
{
    public function test_long_pullback_confirmation_and_three_high_exit(): void
    {
        $strategy = new Strategy([
            'strategy_type' => 'ma_trend',
            'direction' => 'long',
            'risk_percent' => 0,
            'stop_loss' => 0,
            'take_profit' => 0,
            'config' => [
                'ma_type' => 'ema',
                'ma_period' => 3,
                'pullback_zone_percent' => 0.2,
                'min_confirmation_candle_percent' => 0.3,
                'exit_sequence_count' => 3,
            ],
        ]);

        $trade = new Trade([
            'initial_capital' => 1000,
            'fee_percent' => 0,
        ]);
        $trade->start_date = Carbon::createFromTimestamp(0);
        $trade->end_date = Carbon::createFromTimestamp(8);

        $candles = [
            $this->candle(0, 10, 10.2, 9.8, 10),
            $this->candle(1, 10, 11.0, 9.9, 10),
            $this->candle(2, 12, 12.2, 10.9, 12),
            $this->candle(3, 13, 13.2, 12.1, 13),
            $this->candle(4, 12.3, 12.5, 12.16, 12.3),
            $this->candle(5, 12.7, 12.9, 12.6, 12.7),
            $this->candle(6, 12.9, 13.0, 12.7, 12.9),
            $this->candle(7, 13.1, 13.2, 12.8, 13.1),
            $this->candle(8, 13.3, 13.4, 13.0, 13.3),
            $this->candle(9, 13.2, 13.3, 12.9, 13.0),
        ];

        $result = (new MovingAverageTrendEngine())->run($strategy, $trade, $candles);

        $this->assertSame(1, $result['total_trades']);
        $this->assertSame(1, $result['winning_trades']);
        $this->assertSame('new_high_low_sequence_failed', $result['execution_log'][0]['exit_reason']);
        $this->assertSame('long', $result['execution_log'][0]['direction']);
    }

    private function candle(int $timestamp, float $open, float $high, float $low, float $close): object
    {
        return (object) [
            'timestamp' => $timestamp,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => 1,
        ];
    }
}
