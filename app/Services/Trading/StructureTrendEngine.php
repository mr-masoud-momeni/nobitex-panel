<?php

namespace App\Services\Trading;

use App\Models\Strategy;
use App\Models\Trade;
use App\Services\Trading\Indicators\Ema;
use Carbon\Carbon;

class StructureTrendEngine
{
    public function run(Strategy $strategy, Trade $trade, iterable $candles): array
    {
        $config = is_array($strategy->config) ? $strategy->config : [];

        $maPeriod = max(1, (int) ($config['ma_period'] ?? 20));
        $swingStrength = max(2, min(10, (int) ($config['structure_swing_strength'] ?? 3)));
        $minSwingPercent = max(0.0, (float) ($config['structure_min_swing_percent'] ?? 0.4));
        $rangeLookback = max(10, min(500, (int) ($config['range_lookback_candles'] ?? 30)));
        $rangeMaxWidthPercent = max(0.1, (float) ($config['range_max_width_percent'] ?? 3.0));
        $breakoutBufferPercent = max(0.0, (float) ($config['breakout_buffer_percent'] ?? 0.1));
        $pullbackZonePercent = max(0.0, (float) ($config['pullback_zone_percent'] ?? 0.5));
        $pullbackMaxBars = max(1, min(50, (int) ($config['pullback_max_bars'] ?? 8)));
        $minConfirmationCandlePercent = max(0.0, (float) ($config['min_confirmation_candle_percent'] ?? 0.3));
        $sequenceLength = max(2, min(20, (int) ($config['exit_sequence_count'] ?? 3)));

        $direction = $strategy->direction ?: 'long';
        $ema = new Ema($maPeriod);

        $cash = (float) $trade->initial_capital;
        $feeRate = max(0.0, (float) ($trade->fee_percent ?? 0)) / 100;

        $quantity = 0.0;
        $positionDirection = null;
        $entryPrice = null;
        $entryValue = null;
        $entryFee = 0.0;
        $entryTimestamp = null;
        $entryReason = null;

        $candlesBuffer = [];
        $swingHighs = [];
        $swingLows = [];

        $marketState = 'range';
        $breakoutDirection = null;
        $breakoutLevel = null;
        $breakoutTimestamp = null;
        $pullbackDetected = false;
        $entryTaken = false;

        $sequenceCount = 0;
        $referenceHigh = null;
        $referenceLow = null;

        $lastPrice = null;
        $lastTimestamp = null;
        $previousMa = null;

        $totalTrades = 0;
        $winningTrades = 0;
        $losingTrades = 0;
        $executionLog = [];

        foreach ($candles as $candle) {
            $close = (float) $candle->close;
            $open = (float) $candle->open;
            $high = (float) $candle->high;
            $low = (float) $candle->low;
            $timestamp = (int) $candle->timestamp;
            $ma = $ema->update($close);

            $candlesBuffer[] = [
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'timestamp' => $timestamp,
            ];

            $maxBuffer = max($rangeLookback, ($swingStrength * 2) + 10);
            if (count($candlesBuffer) > $maxBuffer) {
                array_shift($candlesBuffer);
            }

            if ($ma === null || count($candlesBuffer) < ($swingStrength * 2) + 1) {
                $lastPrice = $close;
                $lastTimestamp = $timestamp;
                continue;
            }

            $this->confirmLatestSwing(
                $candlesBuffer,
                $swingStrength,
                $minSwingPercent,
                $swingHighs,
                $swingLows
            );

            $marketState = $this->detectRange($candlesBuffer, $rangeLookback, $rangeMaxWidthPercent)
                ? 'range'
                : ($this->detectStructureDirection($swingHighs, $swingLows, $minSwingPercent) ?: 'transition');

            $latestHigh = $this->latestSwing($swingHighs);
            $latestLow = $this->latestSwing($swingLows);

            // Structure is the trend detector. EMA only confirms direction.
            if ($marketState !== 'range' && $latestHigh !== null && ($direction === 'long' || $direction === 'both')) {
                $level = $latestHigh['price'];

                if (
                    $close > $level * (1 + $breakoutBufferPercent / 100)
                    && $close > $ma
                    && $this->emaSlopePositive($ma, $previousMa)
                ) {
                    if ($breakoutDirection !== 'long' || $breakoutLevel !== $level) {
                        $breakoutDirection = 'long';
                        $breakoutLevel = $level;
                        $breakoutTimestamp = $timestamp;
                        $pullbackDetected = false;
                        $entryTaken = false;
                    }
                }
            }

            if ($marketState !== 'range' && $latestLow !== null && ($direction === 'short' || $direction === 'both')) {
                $level = $latestLow['price'];

                if (
                    $close < $level * (1 - $breakoutBufferPercent / 100)
                    && $close < $ma
                    && $this->emaSlopeNegative($ma, $previousMa)
                ) {
                    if ($breakoutDirection !== 'short' || $breakoutLevel !== $level) {
                        $breakoutDirection = 'short';
                        $breakoutLevel = $level;
                        $breakoutTimestamp = $timestamp;
                        $pullbackDetected = false;
                        $entryTaken = false;
                    }
                }
            }

            if ($breakoutDirection !== null && $breakoutTimestamp !== null && !$entryTaken) {
                $barsSinceBreakout = $this->barsSinceTimestamp($candlesBuffer, $breakoutTimestamp);

                if ($barsSinceBreakout <= $pullbackMaxBars && $breakoutLevel !== null) {
                    if ($breakoutDirection === 'long') {
                        $emaZone = $low >= $ma * (1 - $pullbackZonePercent / 100)
                            && $low <= $ma * (1 + $pullbackZonePercent / 100);
                        $breakoutZone = $low <= $breakoutLevel * (1 + $pullbackZonePercent / 100)
                            && $low >= $breakoutLevel * (1 - $pullbackZonePercent / 100);

                        if ($emaZone || $breakoutZone) {
                            $pullbackDetected = true;
                        }
                    } else {
                        $emaZone = $high >= $ma * (1 - $pullbackZonePercent / 100)
                            && $high <= $ma * (1 + $pullbackZonePercent / 100);
                        $breakoutZone = $high <= $breakoutLevel * (1 + $pullbackZonePercent / 100)
                            && $high >= $breakoutLevel * (1 - $pullbackZonePercent / 100);

                        if ($emaZone || $breakoutZone) {
                            $pullbackDetected = true;
                        }
                    }
                } elseif ($barsSinceBreakout > $pullbackMaxBars) {
                    $breakoutDirection = null;
                    $breakoutLevel = null;
                    $breakoutTimestamp = null;
                    $pullbackDetected = false;
                }
            }

            if ($quantity > 0 && $positionDirection !== null) {
                $exitPrice = $this->exitPriceFromRisk(
                    $entryPrice,
                    $high,
                    $low,
                    $strategy,
                    $positionDirection
                );
                $exitReason = $exitPrice !== null ? 'stop_loss_or_take_profit' : null;

                if ($exitPrice === null) {
                    if ($positionDirection === 'long') {
                        if ($referenceHigh === null) {
                            $referenceHigh = $entryPrice;
                        }

                        if ($close > $referenceHigh) {
                            $referenceHigh = $close;
                            $sequenceCount = 0;
                        } else {
                            $sequenceCount++;
                            if ($sequenceCount >= $sequenceLength) {
                                $exitPrice = $close;
                                $exitReason = 'new_high_low_sequence_failed';
                            }
                        }
                    } else {
                        if ($referenceLow === null) {
                            $referenceLow = $entryPrice;
                        }

                        if ($close < $referenceLow) {
                            $referenceLow = $close;
                            $sequenceCount = 0;
                        } else {
                            $sequenceCount++;
                            if ($sequenceCount >= $sequenceLength) {
                                $exitPrice = $close;
                                $exitReason = 'new_high_low_sequence_failed';
                            }
                        }
                    }
                }

                if ($exitPrice !== null) {
                    [$cash, $profit] = $this->closePosition(
                        $cash,
                        $quantity,
                        $entryPrice,
                        $exitPrice,
                        (float) ($trade->fee_percent ?? 0),
                        $entryValue,
                        $entryFee,
                        $positionDirection
                    );

                    $totalTrades++;
                    $profit >= 0 ? $winningTrades++ : $losingTrades++;

                    $executionLog[] = [
                        'direction' => $positionDirection,
                        'entry_reason' => $entryReason,
                        'entry_time' => $this->formatTimestamp($entryTimestamp),
                        'entry_price' => $entryPrice,
                        'entry_value' => $entryValue,
                        'exit_time' => $this->formatTimestamp($timestamp),
                        'exit_price' => $exitPrice,
                        'profit' => $profit,
                        'profit_percent' => $entryValue > 0 ? ($profit / $entryValue) * 100 : 0,
                        'exit_reason' => $exitReason,
                        'cash_after' => $cash,
                        'market_state' => $marketState,
                    ];

                    $quantity = 0.0;
                    $positionDirection = null;
                    $entryPrice = null;
                    $entryValue = null;
                    $entryFee = 0.0;
                    $entryTimestamp = null;
                    $entryReason = null;
                    $sequenceCount = 0;
                    $referenceHigh = null;
                    $referenceLow = null;
                }
            }

            if ($quantity <= 0 && !$entryTaken && $breakoutDirection !== null) {
                $entryDirection = $breakoutDirection;

                if ($entryDirection === 'long') {
                    $entrySignal = $pullbackDetected
                        && $close > $open
                        && $close > $ma
                        && $this->emaSlopePositive($ma, $previousMa)
                        && $this->candleHeightPercent($high, $low) >= $minConfirmationCandlePercent
                        && ($breakoutLevel === null || $close >= $breakoutLevel);
                } else {
                    $entrySignal = $pullbackDetected
                        && $close < $open
                        && $close < $ma
                        && $this->emaSlopeNegative($ma, $previousMa)
                        && $this->candleHeightPercent($high, $low) >= $minConfirmationCandlePercent
                        && ($breakoutLevel === null || $close <= $breakoutLevel);
                }

                if ($entrySignal) {
                    $notional = $this->positionNotional(
                        $cash,
                        (float) ($strategy->risk_percent ?? 0),
                        (float) ($strategy->stop_loss ?? 0)
                    );

                    if ($notional > 0 && $close > 0) {
                        $quantity = $notional / $close;
                        $entryFee = $notional * $feeRate;
                        $entryValue = $entryDirection === 'long' ? $notional + $entryFee : $notional;

                        if ($entryDirection === 'long') {
                            if ($entryValue > $cash) {
                                $quantity = $cash / ($close * (1 + $feeRate));
                                $notional = $quantity * $close;
                                $entryFee = $notional * $feeRate;
                                $entryValue = $notional + $entryFee;
                            }
                            $cash -= $entryValue;
                        } else {
                            if ($entryFee > $cash) {
                                $quantity = $feeRate > 0 ? $cash / ($close * $feeRate) : 0;
                                $notional = $quantity * $close;
                                $entryFee = $notional * $feeRate;
                                $entryValue = $notional;
                            }
                            $cash -= $entryFee;
                        }

                        if ($quantity > 0) {
                            $positionDirection = $entryDirection;
                            $entryPrice = $close;
                            $entryTimestamp = $timestamp;
                            $entryReason = 'structure_break_pullback';
                            $entryTaken = true;
                            $sequenceCount = 0;
                            $referenceHigh = $close;
                            $referenceLow = $close;
                        }
                    }
                }
            }

            $lastPrice = $close;
            $lastTimestamp = $timestamp;
            $previousMa = $ma;
        }

        if ($quantity > 0 && $lastPrice !== null && $positionDirection !== null) {
            [$cash, $profit] = $this->closePosition(
                $cash,
                $quantity,
                $entryPrice,
                $lastPrice,
                (float) ($trade->fee_percent ?? 0),
                $entryValue,
                $entryFee,
                $positionDirection
            );

            $totalTrades++;
            $profit >= 0 ? $winningTrades++ : $losingTrades++;

            $executionLog[] = [
                'direction' => $positionDirection,
                'entry_reason' => $entryReason,
                'entry_time' => $this->formatTimestamp($entryTimestamp),
                'entry_price' => $entryPrice,
                'entry_value' => $entryValue,
                'exit_time' => $this->formatTimestamp($lastTimestamp),
                'exit_price' => $lastPrice,
                'profit' => $profit,
                'profit_percent' => $entryValue > 0 ? ($profit / $entryValue) * 100 : 0,
                'exit_reason' => 'end_of_test',
                'cash_after' => $cash,
                'market_state' => $marketState,
            ];
        }

        $initialCapital = (float) $trade->initial_capital;
        $resultAmount = $cash - $initialCapital;
        $resultPercent = $initialCapital > 0 ? ($resultAmount / $initialCapital) * 100 : 0;

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

    private function confirmLatestSwing(array $candles, int $strength, float $minSwingPercent, array &$highs, array &$lows): void
    {
        $candidateIndex = count($candles) - 1 - $strength;

        if ($candidateIndex < $strength) {
            return;
        }

        $candidate = $candles[$candidateIndex];
        $isHigh = true;
        $isLow = true;

        for ($i = $candidateIndex - $strength; $i <= $candidateIndex + $strength; $i++) {
            if ($i === $candidateIndex) {
                continue;
            }

            if ($candles[$i]['high'] >= $candidate['high']) {
                $isHigh = false;
            }

            if ($candles[$i]['low'] <= $candidate['low']) {
                $isLow = false;
            }
        }

        if ($isHigh && $this->isMeaningfulSwing($candidate['high'], $highs, $minSwingPercent)) {
            $this->appendSwing($highs, $candidate['high'], $candidate['timestamp']);
        }

        if ($isLow && $this->isMeaningfulSwing($candidate['low'], $lows, $minSwingPercent)) {
            $this->appendSwing($lows, $candidate['low'], $candidate['timestamp']);
        }
    }

    private function isMeaningfulSwing(float $price, array $swings, float $minPercent): bool
    {
        if (empty($swings) || $minPercent <= 0) {
            return true;
        }

        $last = $swings[count($swings) - 1]['price'];

        return $last <= 0 || abs(($price - $last) / $last) * 100 >= $minPercent;
    }

    private function appendSwing(array &$swings, float $price, int $index): void
    {
        $last = $swings[count($swings) - 1] ?? null;

        if ($last !== null && $last['index'] === $index) {
            return;
        }

        $swings[] = ['price' => $price, 'index' => $index];

        if (count($swings) > 20) {
            array_shift($swings);
        }
    }

    private function detectStructureDirection(array $highs, array $lows, float $minPercent): ?string
    {
        if (count($highs) < 2 || count($lows) < 2) {
            return null;
        }

        $previousHigh = $highs[count($highs) - 2]['price'];
        $lastHigh = $highs[count($highs) - 1]['price'];
        $previousLow = $lows[count($lows) - 2]['price'];
        $lastLow = $lows[count($lows) - 1]['price'];

        $bullishHigh = $lastHigh > $previousHigh * (1 + $minPercent / 100);
        $bullishLow = $lastLow > $previousLow * (1 + $minPercent / 100);
        $bearishHigh = $lastHigh < $previousHigh * (1 - $minPercent / 100);
        $bearishLow = $lastLow < $previousLow * (1 - $minPercent / 100);

        if ($bullishHigh && $bullishLow) {
            return 'long';
        }

        if ($bearishHigh && $bearishLow) {
            return 'short';
        }

        return null;
    }

    private function detectRange(array $candles, int $lookback, float $maxWidthPercent): bool
    {
        if (count($candles) < $lookback) {
            return false;
        }

        $sample = array_slice($candles, -$lookback);
        $high = max(array_column($sample, 'high'));
        $low = min(array_column($sample, 'low'));

        if ($low <= 0) {
            return false;
        }

        return (($high - $low) / $low) * 100 <= $maxWidthPercent;
    }

    private function latestSwing(array $swings): ?array
    {
        return $swings[count($swings) - 1] ?? null;
    }

    private function emaSlopePositive(?float $ma, ?float $previousMa): bool
    {
        return $ma !== null && $previousMa !== null && $ma > $previousMa;
    }

    private function emaSlopeNegative(?float $ma, ?float $previousMa): bool
    {
        return $ma !== null && $previousMa !== null && $ma < $previousMa;
    }

    private function barsSinceTimestamp(array $candles, int $timestamp): int
    {
        for ($i = count($candles) - 1, $bars = 0; $i >= 0; $i--, $bars++) {
            if ($candles[$i]['timestamp'] === $timestamp) {
                return $bars;
            }
        }

        return PHP_INT_MAX;
    }

    private function candleHeightPercent(float $high, float $low): float
    {
        if ($low <= 0) {
            return 0;
        }

        return (($high - $low) / $low) * 100;
    }

    private function positionNotional(float $cash, float $riskPercent, float $stopLoss): float
    {
        if ($cash <= 0) {
            return 0;
        }

        if ($riskPercent > 0 && $stopLoss > 0) {
            return min($cash, ($cash * ($riskPercent / 100)) / ($stopLoss / 100));
        }

        return $cash;
    }

    private function exitPriceFromRisk(?float $entryPrice, float $high, float $low, Strategy $strategy, string $direction): ?float
    {
        if ($entryPrice === null) {
            return null;
        }

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

    private function closePosition(float $cash, float $quantity, float $entryPrice, float $exitPrice, float $feePercent, float $entryValue, float $entryFee, string $direction): array
    {
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

    private function formatTimestamp(?int $timestamp): ?string
    {
        return $timestamp ? Carbon::createFromTimestamp($timestamp)->format('Y-m-d H:i:s') : null;
    }
}
