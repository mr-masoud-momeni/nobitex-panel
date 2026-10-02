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
        $rules = $strategy->rules->sortBy('sort_order')->values();
        $entryRules = $rules->where('type', 'entry')->values();
        $exitRules = $rules->where('type', 'exit')->values();

        if ($entryRules->isEmpty()) {
            throw new InvalidArgumentException('استراتژی حداقل به یک شرط ورود نیاز دارد.');
        }

        $indicators = $this->buildIndicators($rules);
        $cash = (float) $trade->initial_capital;
        $quantity = 0.0;
        $entryPrice = null;
        $entryValue = null;
        $previousValues = [];
        $totalTrades = 0;
        $winningTrades = 0;
        $losingTrades = 0;
        $lastPrice = null;
        $startDate = $trade->start_date;
        $startTimestamp = $startDate instanceof \DateTimeInterface
            ? $startDate->getTimestamp()
            : Carbon::parse($startDate)->timestamp;

        foreach ($candles as $candle) {
            $close = (float) $candle->close;
            $high = (float) $candle->high;
            $low = (float) $candle->low;
            $volume = $candle->volume !== null ? (float) $candle->volume : null;

            $values = [
                'price' => $close,
                'volume' => $volume,
            ];

            foreach ($indicators as $key => $indicator) {
                if ($indicator instanceof Macd) {
                    $values[$key] = $indicator->update($close)['macd'];
                } else {
                    $values[$key] = $indicator->update($close);
                }
            }

            if ((int) $candle->timestamp < $startTimestamp) {
                $previousValues = $values;
                continue;
            }

            if ($quantity > 0) {
                $exitPrice = $this->exitPriceFromRisk($entryPrice, $high, $low, $strategy);

                if ($exitPrice === null && !$exitRules->isEmpty() && $this->evaluateRules($exitRules, $values, $previousValues)) {
                    $exitPrice = $close;
                }

                if ($exitPrice !== null) {
                    [$cash, $profit] = $this->closePosition(
                        $cash,
                        $quantity,
                        $exitPrice,
                        (float) ($trade->fee_percent ?? 0),
                        (float) ($entryValue ?? 0)
                    );

                    $totalTrades++;
                    $profit >= 0 ? $winningTrades++ : $losingTrades++;
                    $quantity = 0.0;
                    $entryPrice = null;
                    $entryValue = null;
                }
            }

            if ($quantity <= 0 && $this->evaluateRules($entryRules, $values, $previousValues)) {
                $feeRate = max(0.0, (float) ($trade->fee_percent ?? 0)) / 100;
                $notional = $this->positionNotional(
                    $cash,
                    (float) ($strategy->risk_percent ?? 0),
                    (float) ($strategy->stop_loss ?? 0)
                );

                if ($notional > 0 && $close > 0) {
                    $quantity = $notional / ($close * (1 + $feeRate));
                    $entryFee = $quantity * $close * $feeRate;
                    $entryValue = ($quantity * $close) + $entryFee;
                    $cash -= $entryValue;
                    $entryPrice = $close;
                }
            }

            $previousValues = $values;
            $lastPrice = $close;
        }

        if ($quantity > 0 && $lastPrice !== null) {
            [$cash, $profit] = $this->closePosition(
                $cash,
                $quantity,
                $lastPrice,
                (float) ($trade->fee_percent ?? 0),
                (float) ($entryValue ?? 0)
            );

            $totalTrades++;
            $profit >= 0 ? $winningTrades++ : $losingTrades++;
        }

        $resultAmount = $cash - (float) $trade->initial_capital;
        $resultPercent = ((float) $trade->initial_capital) > 0
            ? ($resultAmount / (float) $trade->initial_capital) * 100
            : 0;

        return [
            'result_amount' => $resultAmount,
            'result_percent' => $resultPercent,
            'total_trades' => $totalTrades,
            'winning_trades' => $winningTrades,
            'losing_trades' => $losingTrades,
            'final_capital' => $cash,
        ];
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

    private function evaluateRules($rules, array $values, array $previousValues): bool
    {
        if ($rules->isEmpty()) {
            return false;
        }

        $result = null;

        foreach ($rules as $rule) {
            $condition = $this->evaluateRule($rule, $values, $previousValues);

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
        array $previousValues
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

    private function exitPriceFromRisk(
        ?float $entryPrice,
        float $high,
        float $low,
        Strategy $strategy
    ): ?float {
        if ($entryPrice === null) {
            return null;
        }

        $stopLoss = (float) ($strategy->stop_loss ?? 0);
        $takeProfit = (float) ($strategy->take_profit ?? 0);

        if ($stopLoss > 0) {
            $stopPrice = $entryPrice * (1 - ($stopLoss / 100));

            if ($low <= $stopPrice) {
                return $stopPrice;
            }
        }

        if ($takeProfit > 0) {
            $takeProfitPrice = $entryPrice * (1 + ($takeProfit / 100));

            if ($high >= $takeProfitPrice) {
                return $takeProfitPrice;
            }
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
        float $price,
        float $feePercent,
        float $entryValue
    ): array {
        $feeRate = max(0.0, $feePercent) / 100;
        $gross = $quantity * $price;
        $fee = $gross * $feeRate;
        $proceeds = $gross - $fee;
        $profit = $proceeds - $entryValue;

        return [$cash + $proceeds, $profit];
    }
}
