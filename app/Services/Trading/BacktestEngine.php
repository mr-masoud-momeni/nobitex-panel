<?php

namespace App\Services\Trading;

use App\Models\Strategy;
use App\Models\StrategyRule;
use App\Models\Trade;
use Carbon\Carbon;
use App\Services\Trading\Indicators\Ema;
use App\Services\Trading\Indicators\Macd;
use App\Services\Trading\Indicators\Rsi;
use App\Services\Trading\Indicators\Sma;
use InvalidArgumentException;

class BacktestEngine
{
    public function run(Strategy $strategy, Trade $trade, iterable $candles): array
    {
        if (($strategy->strategy_type ?? 'generic') === 'ma_trend') {
            return app(MovingAverageTrendEngine::class)->run($strategy, $trade, $candles);
        }

        $rules = $strategy->rules->sortBy('sort_order')->values();
        $direction = $strategy->direction ?: 'long';

        $entryRules = [
            'long' => $rules->filter(fn ($rule) => in_array($rule->type, ['entry', 'long_entry'], true))->values(),
            'short' => $rules->where('type', 'short_entry')->values(),
        ];
        $exitRules = [
            'long' => $rules->filter(fn ($rule) => in_array($rule->type, ['exit', 'long_exit'], true))->values(),
            'short' => $rules->where('type', 'short_exit')->values(),
        ];

        if ($direction !== 'short' && $entryRules['long']->isEmpty()) {
            throw new InvalidArgumentException('استراتژی Long حداقل به یک شرط ورود نیاز دارد.');
        }
        if ($direction !== 'long' && $entryRules['short']->isEmpty()) {
            throw new InvalidArgumentException('استراتژی Short حداقل به یک شرط ورود نیاز دارد.');
        }

        $indicators = $this->buildIndicators($rules);
        $cash = (float) $trade->initial_capital;
        $quantity = 0.0;
        $positionDirection = null;
        $entryPrice = null;
        $entryValue = null;
        $entryFee = 0.0;
        $previousValues = [];
        $valueHistory = [];
        $totalTrades = 0;
        $winningTrades = 0;
        $losingTrades = 0;
        $executionLog = [];
        $lastPrice = null;
        $entryTimestamp = null;
        $entryReason = null;
        $startTimestamp = $trade->start_date instanceof \DateTimeInterface
            ? $trade->start_date->getTimestamp()
            : Carbon::parse($trade->start_date)->timestamp;

        foreach ($candles as $candle) {
            $close = (float) $candle->close;
            $high = (float) $candle->high;
            $low = (float) $candle->low;
            $volume = $candle->volume !== null ? (float) $candle->volume : null;

            $values = ['price' => $close, 'high' => $high, 'low' => $low, 'volume' => $volume];

            foreach ($indicators as $key => $indicator) {
                $values[$key] = $indicator instanceof Macd
                    ? $indicator->update($close)['macd']
                    : $indicator->update($close);
            }

            if ((int) $candle->timestamp < $startTimestamp) {
                $this->appendHistory($valueHistory, $values);
                $previousValues = $values;
                continue;
            }

            if ($quantity > 0 && $positionDirection !== null) {
                $exitPrice = $this->exitPriceFromRisk($entryPrice, $high, $low, $strategy, $positionDirection);
                $exitReason = $exitPrice !== null ? 'stop_loss_or_take_profit' : null;

                if ($exitPrice === null && !$exitRules[$positionDirection]->isEmpty()
                    && $this->evaluateRules($exitRules[$positionDirection], $values, $previousValues, $valueHistory)) {
                    $exitPrice = $close;
                    $exitReason = 'exit_rule';
                }

                if ($exitPrice !== null) {
                    [$cash, $profit] = $this->closePosition(
                        $cash, $quantity, $entryPrice, $exitPrice,
                        (float) ($trade->fee_percent ?? 0), $entryValue, $entryFee, $positionDirection
                    );

                    $totalTrades++;
                    $profit >= 0 ? $winningTrades++ : $losingTrades++;
                    $executionLog[] = [
                        'direction' => $positionDirection,
                        'entry_reason' => $entryReason,
                        'entry_time' => $this->formatTimestamp($entryTimestamp),
                        'entry_price' => $entryPrice,
                        'entry_value' => $entryValue,
                        'exit_time' => $this->formatTimestamp((int) $candle->timestamp),
                        'exit_price' => $exitPrice,
                        'profit' => $profit,
                        'profit_percent' => $entryValue > 0 ? ($profit / $entryValue) * 100 : 0,
                        'exit_reason' => $exitReason,
                        'cash_after' => $cash,
                    ];
                    $quantity = 0.0;
                    $positionDirection = null;
                    $entryPrice = null;
                    $entryValue = null;
                    $entryFee = 0.0;
                    $entryTimestamp = null;
                    $entryReason = null;
                }
            }

            if ($quantity <= 0) {
                $directions = $direction === 'both' ? ['long', 'short'] : [$direction];

                foreach ($directions as $candidateDirection) {
                    if ($entryRules[$candidateDirection]->isEmpty()
                        || !$this->evaluateRules($entryRules[$candidateDirection], $values, $previousValues, $valueHistory)) {
                        continue;
                    }

                    $feeRate = max(0.0, (float) ($trade->fee_percent ?? 0)) / 100;
                    $notional = $this->positionNotional($cash, (float) ($strategy->risk_percent ?? 0), (float) ($strategy->stop_loss ?? 0));

                    if ($notional <= 0 || $close <= 0) {
                        break;
                    }

                    $quantity = $notional / $close;
                    $entryFee = $notional * $feeRate;
                    $entryValue = $candidateDirection === 'long' ? $notional + $entryFee : $notional;

                    if ($candidateDirection === 'long') {
                        if ($entryValue > $cash) {
                            $quantity = $cash / ($close * (1 + $feeRate));
                            $notional = $quantity * $close;
                            $entryFee = $notional * $feeRate;
                            $entryValue = $notional + $entryFee;
                        }
                        $cash -= $entryValue;
                    } else {
                        if ($entryFee > $cash) {
                            $quantity = $cash / ($close * max($feeRate, 0.000000001));
                            $notional = $quantity * $close;
                            $entryFee = $notional * $feeRate;
                            $entryValue = $notional;
                        }
                        $cash -= $entryFee;
                    }

                    $positionDirection = $candidateDirection;
                    $entryPrice = $close;
                    $entryTimestamp = (int) $candle->timestamp;
                    $entryReason = 'entry_rule';
                    break;
                }
            }

            $this->appendHistory($valueHistory, $values);
            $previousValues = $values;
            $lastPrice = $close;
        }

        if ($quantity > 0 && $lastPrice !== null && $positionDirection !== null) {
            [$cash, $profit] = $this->closePosition(
                $cash, $quantity, $entryPrice, $lastPrice,
                (float) ($trade->fee_percent ?? 0), $entryValue, $entryFee, $positionDirection
            );

            $totalTrades++;
            $profit >= 0 ? $winningTrades++ : $losingTrades++;
            $executionLog[] = [
                'direction' => $positionDirection,
                'entry_reason' => $entryReason,
                'entry_time' => $this->formatTimestamp($entryTimestamp),
                'entry_price' => $entryPrice,
                'entry_value' => $entryValue,
                'exit_time' => $this->formatTimestamp((int) $trade->end_date->timestamp),
                'exit_price' => $lastPrice,
                'profit' => $profit,
                'profit_percent' => $entryValue > 0 ? ($profit / $entryValue) * 100 : 0,
                'exit_reason' => 'end_of_test',
                'cash_after' => $cash,
            ];
        }

        $resultAmount = $cash - (float) $trade->initial_capital;
        $resultPercent = (float) $trade->initial_capital > 0
            ? ($resultAmount / (float) $trade->initial_capital) * 100 : 0;

        return [
            'result_amount' => $resultAmount,
            'result_percent' => $resultPercent,
            'total_trades' => $totalTrades,
            'winning_trades' => $winningTrades,
            'losing_trades' => $losingTrades,
            'final_capital' => $cash,
            'execution_log' => $executionLog,
        ];
    }

    private function formatTimestamp(?int $timestamp): ?string
    {
        return $timestamp ? Carbon::createFromTimestamp($timestamp)->format('Y-m-d H:i:s') : null;
    }

    private function buildIndicators($rules): array
    {
        $indicators = [];

        foreach ($rules as $rule) {
            $this->addIndicator($indicators, $rule->indicator, $rule->parameters ?: []);

            if ($rule->value_type === 'indicator' && is_array($rule->value)) {
                $this->addIndicator(
                    $indicators,
                    $rule->value['indicator'] ?? null,
                    $rule->value['parameters'] ?? []
                );
            }
        }

        return $indicators;
    }

    private function addIndicator(array &$indicators, ?string $name, array $parameters): void
    {
        if (!$name || in_array($name, ['price', 'volume'], true)) {
            return;
        }

        $key = $this->indicatorKey($name, $parameters);

        if (isset($indicators[$key])) {
            return;
        }

        switch ($name) {
            case 'ema':
                $indicators[$key] = new Ema((int) ($parameters['period'] ?? 14));
                break;
            case 'sma':
                $indicators[$key] = new Sma((int) ($parameters['period'] ?? 14));
                break;
            case 'rsi':
                $indicators[$key] = new Rsi((int) ($parameters['period'] ?? 14));
                break;
            case 'macd':
                $indicators[$key] = new Macd(
                    (int) ($parameters['fast'] ?? 12),
                    (int) ($parameters['slow'] ?? 26),
                    (int) ($parameters['signal'] ?? 9)
                );
                break;
            default:
                throw new InvalidArgumentException("اندیکاتور پشتیبانی نمی‌شود: {$name}");
        }
    }

    private function indicatorKey(string $name, array $parameters): string
    {
        if ($name === 'macd') {
            return sprintf(
                'macd:%d:%d:%d',
                (int) ($parameters['fast'] ?? 12),
                (int) ($parameters['slow'] ?? 26),
                (int) ($parameters['signal'] ?? 9)
            );
        }

        if (in_array($name, ['ema', 'sma', 'rsi'], true)) {
            return $name.':'.(int) ($parameters['period'] ?? 14);
        }

        return $name;
    }

    private function evaluateRules($rules, array $values, array $previousValues, array $valueHistory = []): bool
    {
        if ($rules->isEmpty()) {
            return false;
        }

        $result = null;

        foreach ($rules as $rule) {
            $condition = $this->evaluateRule($rule, $values, $previousValues, $valueHistory);

            if ($result === null) {
                $result = $condition;
                continue;
            }

            $result = ($rule->logical_operator ?? 'AND') === 'OR'
                ? ($result || $condition)
                : ($result && $condition);
        }

        return (bool) $result;
    }

    private function evaluateRule(
        StrategyRule $rule,
        array $values,
        array $previousValues,
        array $valueHistory = []
    ): bool {
        $sourceKey = $this->indicatorKey($rule->indicator, $rule->parameters ?: []);
        $source = $this->valueForKey($rule->indicator, $sourceKey, $values);

        if ($source === null) {
            return false;
        }

        $target = null;
        $previousTarget = null;

        if ($rule->value_type === 'number') {
            $target = is_numeric($rule->value) ? (float) $rule->value : null;
            $previousTarget = $target;
        } elseif ($rule->value_type === 'indicator' && is_array($rule->value)) {
            $targetIndicator = $rule->value['indicator'] ?? null;
            $targetParameters = $rule->value['parameters'] ?? [];
            $targetKey = $this->indicatorKey($targetIndicator, $targetParameters);
            $target = $this->valueForKey($targetIndicator, $targetKey, $values);
            $previousTarget = $this->valueForKey($targetIndicator, $targetKey, $previousValues);
        }

        if ($target === null) {
            return false;
        }

        if ($rule->operator === 'slope_>' || $rule->operator === 'slope_<') {
            $lookback = max(1, (int) (($rule->parameters ?: [])['lookback'] ?? 5));
            $history = $valueHistory[$sourceKey] ?? [];

            if (count($history) < $lookback) {
                return false;
            }

            $past = (float) $history[count($history) - $lookback];
            if (abs($past) < 0.0000000001) {
                return false;
            }

            $slopePercent = (($source - $past) / abs($past)) * 100;

            return $rule->operator === 'slope_>'
                ? $slopePercent > $target
                : $slopePercent < $target;
        }

        switch ($rule->operator) {
            case '>':
                return $source > $target;
            case '<':
                return $source < $target;
            case '>=':
                return $source >= $target;
            case '<=':
                return $source <= $target;
            case '=':
                return abs($source - $target) < 0.0000000001;
            case 'crosses_above':
                return $this->crossesAbove(
                    $source,
                    $target,
                    $previousValues[$sourceKey] ?? null,
                    $previousTarget
                );
            case 'crosses_below':
                return $this->crossesBelow(
                    $source,
                    $target,
                    $previousValues[$sourceKey] ?? null,
                    $previousTarget
                );
            case 'breaks_above_without_touch':
                if ($rule->indicator !== 'price') {
                    return false;
                }

                return $this->breaksAboveWithoutTouch(
                    (float) ($values['low'] ?? 0),
                    $target,
                    isset($previousValues['low']) ? (float) $previousValues['low'] : null,
                    $previousTarget
                );
            case 'breaks_below_without_touch':
                if ($rule->indicator !== 'price') {
                    return false;
                }

                return $this->breaksBelowWithoutTouch(
                    (float) ($values['high'] ?? 0),
                    $target,
                    isset($previousValues['high']) ? (float) $previousValues['high'] : null,
                    $previousTarget
                );
            default:
                return false;
        }
    }

    private function valueForKey(?string $indicator, string $key, array $values): ?float
    {
        if ($indicator === 'price') {
            return isset($values['price']) ? (float) $values['price'] : null;
        }

        if ($indicator === 'volume') {
            return isset($values['volume']) && $values['volume'] !== null
                ? (float) $values['volume']
                : null;
        }

        return isset($values[$key]) && $values[$key] !== null
            ? (float) $values[$key]
            : null;
    }

    private function crossesAbove(
        float $source,
        float $target,
        ?float $previousSource,
        ?float $previousTarget
    ): bool {
        if ($previousSource === null || $previousTarget === null) {
            return false;
        }

        return $previousSource <= $previousTarget && $source > $target;
    }

    private function crossesBelow(
        float $source,
        float $target,
        ?float $previousSource,
        ?float $previousTarget
    ): bool {
        if ($previousSource === null || $previousTarget === null) {
            return false;
        }

        return $previousSource >= $previousTarget && $source < $target;
    }

    private function breaksAboveWithoutTouch(
        float $low,
        float $target,
        ?float $previousLow,
        ?float $previousTarget
    ): bool {
        if ($previousLow === null || $previousTarget === null) {
            return false;
        }

        return $previousLow <= $previousTarget && $low > $target;
    }

    private function breaksBelowWithoutTouch(
        float $high,
        float $target,
        ?float $previousHigh,
        ?float $previousTarget
    ): bool {
        if ($previousHigh === null || $previousTarget === null) {
            return false;
        }

        return $previousHigh >= $previousTarget && $high < $target;
    }

    private function exitPriceFromRisk(?float $entryPrice, float $high, float $low, Strategy $strategy, string $direction): ?float
    {
        if ($entryPrice === null) return null;

        $stopLoss = (float) ($strategy->stop_loss ?? 0);
        $takeProfit = (float) ($strategy->take_profit ?? 0);

        if ($direction === 'short') {
            if ($stopLoss > 0 && $high >= $entryPrice * (1 + $stopLoss / 100)) {
                return $entryPrice * (1 + $stopLoss / 100);
            }
            if ($takeProfit > 0 && $low <= $entryPrice * (1 - $takeProfit / 100)) {
                return $entryPrice * (1 - $takeProfit / 100);
            }
            return null;
        }

        if ($stopLoss > 0 && $low <= $entryPrice * (1 - $stopLoss / 100)) {
            return $entryPrice * (1 - $stopLoss / 100);
        }
        if ($takeProfit > 0 && $high >= $entryPrice * (1 + $takeProfit / 100)) {
            return $entryPrice * (1 + $takeProfit / 100);
        }

        return null;
    }

    private function positionNotional(float $cash, float $riskPercent, float $stopLoss): float
    {
        if ($cash <= 0) {
            return 0;
        }

        if ($riskPercent > 0 && $stopLoss > 0) {
            return min(
                $cash,
                ($cash * ($riskPercent / 100)) / ($stopLoss / 100)
            );
        }

        return $cash;
    }

    private function closePosition(
        float $cash,
        float $quantity,
        float $entryPrice,
        float $exitPrice,
        float $feePercent,
        float $entryValue,
        float $entryFee,
        string $direction
    ): array {
        $feeRate = max(0.0, $feePercent) / 100;
        $exitGross = $quantity * $exitPrice;
        $exitFee = $exitGross * $feeRate;

        if ($direction === 'short') {
            $profit = ($quantity * ($entryPrice - $exitPrice)) - $entryFee - $exitFee;
            return [$cash + $profit, $profit];
        }

        $proceeds = $exitGross - $exitFee;
        $profit = $proceeds - $entryValue;
        return [$cash + $proceeds, $profit];
    }

    private function appendHistory(array &$history, array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value === null) continue;
            $history[$key] = $history[$key] ?? [];
            $history[$key][] = (float) $value;
        }
    }
}
