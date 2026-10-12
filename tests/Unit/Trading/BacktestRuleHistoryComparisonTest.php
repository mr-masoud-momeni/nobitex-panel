<?php

namespace Tests\Unit\Trading;

use App\Models\StrategyRule;
use App\Services\Trading\BacktestEngine;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class BacktestRuleHistoryComparisonTest extends TestCase
{
    public function test_rule_can_compare_current_indicator_value_with_a_prior_candle(): void
    {
        $engine = new BacktestEngine();
        $method = new ReflectionMethod($engine, 'evaluateRule');
        $method->setAccessible(true);

        $rule = new StrategyRule([
            'indicator' => 'adx',
            'parameters' => ['period' => 14],
            'operator' => '>',
            'value_type' => 'history',
            'value' => ['lookback' => 3],
        ]);

        $result = $method->invoke(
            $engine,
            $rule,
            ['adx:14' => 23.0],
            ['adx:14' => 22.0],
            ['adx:14' => [20.0, 21.0, 22.0]]
        );

        $this->assertTrue($result);
    }

    public function test_historical_comparison_returns_false_when_history_is_insufficient(): void
    {
        $engine = new BacktestEngine();
        $method = new ReflectionMethod($engine, 'evaluateRule');
        $method->setAccessible(true);

        $rule = new StrategyRule([
            'indicator' => 'adx',
            'parameters' => ['period' => 14],
            'operator' => '>',
            'value_type' => 'history',
            'value' => ['lookback' => 3],
        ]);

        $result = $method->invoke(
            $engine,
            $rule,
            ['adx:14' => 23.0],
            ['adx:14' => 22.0],
            ['adx:14' => [20.0, 21.0]]
        );

        $this->assertFalse($result);
    }
}
