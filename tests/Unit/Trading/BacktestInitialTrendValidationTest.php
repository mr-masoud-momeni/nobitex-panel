<?php

namespace Tests\Unit\Trading;

use App\Models\Strategy;
use App\Models\StrategyRule;
use App\Models\Trade;
use App\Services\Trading\BacktestEngine;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class BacktestInitialTrendValidationTest extends TestCase
{
    public function test_long_trade_exits_after_three_bars_without_initial_upward_progress(): void
    {
        $result = $this->runBacktest([
            $this->candle(1000, 97, 98),
            $this->candle(2000, 98, 100),
            $this->candle(3000, 100, 99),
            $this->candle(4000, 99, 98),
            $this->candle(5000, 98, 97),
        ]);

        $this->assertCount(1, $result['execution_log']);
        $this->assertSame('initial_trend_not_confirmed', $result['execution_log'][0]['exit_reason']);
    }

    public function test_initial_upward_progress_disables_the_initial_validation_for_rest_of_trade(): void
    {
        $result = $this->runBacktest([
            $this->candle(1000, 97, 98),
            $this->candle(2000, 98, 100),
            $this->candle(3000, 100, 102),
            $this->candle(4000, 102, 101),
            $this->candle(5000, 101, 100),
        ]);

        $this->assertCount(1, $result['execution_log']);
        $this->assertSame('end_of_test', $result['execution_log'][0]['exit_reason']);
    }

    private function runBacktest(array $candles): array
    {
        $strategy = new Strategy([
            'strategy_type' => 'generic',
            'direction' => 'long',
            'risk_percent' => 0,
            'stop_loss' => 0,
            'take_profit' => 0,
            'config' => [
                'initial_trend_check_enabled' => true,
                'initial_trend_check_candles' => 3,
            ],
        ]);
        $strategy->setRelation('rules', collect([
            new StrategyRule([
                'type' => 'long_entry',
                'indicator' => 'price',
                'parameters' => [],
                'operator' => 'crosses_above',
                'value_type' => 'number',
                'value' => 99,
                'sort_order' => 0,
            ]),
        ]));

        $trade = new Trade([
            'initial_capital' => 1000,
            'fee_percent' => 0,
            'start_date' => Carbon::createFromTimestamp(2000),
            'end_date' => Carbon::createFromTimestamp(5000),
        ]);

        return (new BacktestEngine())->run($strategy, $trade, $candles);
    }

    private function candle(int $timestamp, float $open, float $close): object
    {
        return (object) [
            'timestamp' => $timestamp,
            'open' => $open,
            'high' => max($open, $close) + 1,
            'low' => min($open, $close) - 1,
            'close' => $close,
            'volume' => 100,
        ];
    }
}
