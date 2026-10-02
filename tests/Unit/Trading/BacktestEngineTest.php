<?php

namespace Tests\Unit\Trading;

use App\Models\Strategy;
use App\Models\StrategyRule;
use App\Models\Trade;
use App\Services\Trading\BacktestEngine;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use stdClass;

class BacktestEngineTest extends TestCase
{
    public function test_warmup_candles_only_prepare_indicators_and_do_not_open_positions(): void
    {
        $strategy = new Strategy([
            'risk_percent' => null,
            'stop_loss' => null,
            'take_profit' => null,
        ]);

        $strategy->setRelation('rules', collect([
            new StrategyRule([
                'type' => 'entry',
                'indicator' => 'price',
                'parameters' => [],
                'operator' => '>',
                'value_type' => 'number',
                'value' => 100,
                'logical_operator' => null,
                'sort_order' => 0,
            ]),
            new StrategyRule([
                'type' => 'exit',
                'indicator' => 'price',
                'parameters' => [],
                'operator' => '<',
                'value_type' => 'number',
                'value' => 100,
                'logical_operator' => null,
                'sort_order' => 0,
            ]),
        ]));

        $trade = new Trade([
            'initial_capital' => 1000,
            'fee_percent' => 0,
        ]);
        $trade->start_date = Carbon::createFromTimestamp(2000, 'UTC');

        $candles = [
            $this->candle(1000, 120),
            $this->candle(2000, 110),
            $this->candle(3000, 90),
        ];

        $result = (new BacktestEngine())->run($strategy, $trade, $candles);

        $this->assertSame(1, $result['total_trades']);
        $this->assertSame(0, $result['winning_trades']);
        $this->assertSame(1, $result['losing_trades']);
        $this->assertEqualsWithDelta(-18.1818181818, $result['result_percent'], 0.0000001);
    }

    private function candle(int $timestamp, float $close): stdClass
    {
        $candle = new stdClass();
        $candle->timestamp = $timestamp;
        $candle->open = $close;
        $candle->high = $close;
        $candle->low = $close;
        $candle->close = $close;
        $candle->volume = 1;

        return $candle;
    }
}
