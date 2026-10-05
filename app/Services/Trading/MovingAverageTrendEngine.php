<?php

namespace App\Services\Trading;

use App\Models\Strategy;
use App\Models\Trade;
use App\Services\Trading\Indicators\Ema;
use Carbon\Carbon;
use InvalidArgumentException;

class MovingAverageTrendEngine
{
    public function run(Strategy $strategy, Trade $trade, iterable $candles): array
    {
        $config = is_array($strategy->config) ? $strategy->config : [];
        $maType = $config['ma_type'] ?? 'ema';
        $period = max(1, (int) ($config['ma_period'] ?? 20));
        $direction = $strategy->direction ?: 'long';
        $zonePercent = max(0.0, (float) ($config['pullback_zone_percent'] ?? 0.2));
        $minCandlePercent = max(0.0, (float) ($config['min_confirmation_candle_percent'] ?? 0.3));
        $sequenceLength = max(2, min(20, (int) ($config['exit_sequence_count'] ?? 3)));

        if ($maType !== 'ema') {
            throw new InvalidArgumentException('در حال حاضر فقط EMA برای استراتژی روند با میانگین متحرک پشتیبانی می‌شود.');
        }

        $ema = new Ema($period);
        $cash = (float) $trade->initial_capital;
        $feeRate = max(0.0, (float) ($trade->fee_percent ?? 0)) / 100;

        $quantity = 0.0;
        $positionDirection = null;
        $entryPrice = null;
        $entryValue = null;
        $entryFee = 0.0;
        $entryTimestamp = null;
        $entryReason = null;

        $trendDirection = null;
        $trendCycleId = 0;
        $entryTaken = false;
        $pullbackDetected = false;
        $postBreakExtreme = null;
        $lockedStructure = null;

        $sequenceCount = 0;
        $sequenceCompleted = false;
        $referenceHigh = null;
        $referenceLow = null;

        $previousCandle = null;
        $previousMa = null;
        $lastPrice = null;
        $lastTimestamp = null;
        $startTimestamp = $trade->start_date instanceof \DateTimeInterface
            ? $trade->start_date->getTimestamp()
            : Carbon::parse($trade->start_date)->timestamp;

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

            if ($ma === null) {
                $previousCandle = $candle;
                $lastPrice = $close;
                $lastTimestamp = $timestamp;
                continue;
            }

            $isLongBreak = $previousCandle !== null
                && $previousMa !== null
                && $previousCandle->low <= $previousMa
                && $low > $ma;

            $isShortBreak = $previousCandle !== null
                && $previousMa !== null
                && $previousCandle->high >= $previousMa
                && $high < $ma;

            if ($isLongBreak) {
                $trendDirection = 'long';
                $trendCycleId++;
                $entryTaken = false;
                $pullbackDetected = false;
                $postBreakExtreme = $high;
                $lockedStructure = null;
            } elseif ($isShortBreak) {
                $trendDirection = 'short';
                $trendCycleId++;
                $entryTaken = false;
                $pullbackDetected = false;
                $postBreakExtreme = $low;
                $lockedStructure = null;
            }

            $allowedTrend = $direction === 'both' || $trendDirection === $direction;
            $structuralBreak = false;

            if ($trendDirection === 'long' && $allowedTrend && !$entryTaken) {
                $hadPullback = $pullbackDetected;
                $zoneLow = $ma * (1 - $zonePercent / 100);
                $zoneHigh = $ma * (1 + $zonePercent / 100);

                if ($low >= $zoneLow && $low <= $zoneHigh) {
                    $pullbackDetected = true;
                }

                if ($lockedStructure !== null && $high > $lockedStructure) {
                    $structuralBreak = true;
                }

                if ($postBreakExtreme !== null && $high > $postBreakExtreme) {
                    $postBreakExtreme = $high;
                    $lockedStructure = null;
                } elseif ($postBreakExtreme !== null && $high < $postBreakExtreme && $lockedStructure === null) {
                    $lockedStructure = $postBreakExtreme;
                }
            }

            if ($trendDirection === 'short' && $allowedTrend && !$entryTaken) {
                $hadPullback = $pullbackDetected;
                $zoneLow = $ma * (1 - $zonePercent / 100);
                $zoneHigh = $ma * (1 + $zonePercent / 100);

                if ($high >= $zoneLow && $high <= $zoneHigh) {
                    $pullbackDetected = true;
                }

                if ($lockedStructure !== null && $low < $lockedStructure) {
                    $structuralBreak = true;
                }

                if ($postBreakExtreme !== null && $low < $postBreakExtreme) {
                    $postBreakExtreme = $low;
                    $lockedStructure = null;
                } elseif ($postBreakExtreme !== null && $low > $postBreakExtreme && $lockedStructure === null) {
                    $lockedStructure = $postBreakExtreme;
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
                    $qualifies = $positionDirection === 'long'
                        ? $high > ($previousCandle !== null ? (float) $previousCandle->high : $high)
                        : $low < ($previousCandle !== null ? (float) $previousCandle->low : $low);

                    if ($positionDirection === 'long') {
                        if ($referenceHigh === null) {
                            $referenceHigh = $entryPrice;
                        }

                        if ($high > $referenceHigh) {
                            $referenceHigh = $high;
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

                        if ($low < $referenceLow) {
                            $referenceLow = $low;
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
                        'trend_cycle' => $trendCycleId,
                    ];

                    $quantity = 0.0;
                    $positionDirection = null;
                    $entryPrice = null;
                    $entryValue = null;
                    $entryFee = 0.0;
                    $entryTimestamp = null;
                    $entryReason = null;
                    $sequenceCount = 0;
                    $sequenceCompleted = false;
                    $referenceHigh = null;
                    $referenceLow = null;
                }
            }

            if ($quantity <= 0 && !$entryTaken && $allowedTrend) {
                $entrySignal = false;
                $entryDirection = $trendDirection;

                if ($trendDirection === 'long') {
                    $confirmation = $hadPullback
                        && $low > $ma
                        && $close > $open
                        && $this->candleHeightPercent($high, $low) >= $minCandlePercent;

                    $entrySignal = $confirmation || $structuralBreak;
                    $entryReason = $confirmation ? 'pullback_confirmation' : ($structuralBreak ? 'structural_breakout' : null);
                    $entryReason = $confirmation ? 'pullback_confirmation' : ($structuralBreak ? 'structural_breakout' : null);
                } elseif ($trendDirection === 'short') {
                    $confirmation = $hadPullback
                        && $high < $ma
                        && $close < $open
                        && $this->candleHeightPercent($high, $low) >= $minCandlePercent;

                    $entrySignal = $confirmation || $structuralBreak;
                }

                if ($entrySignal && $entryDirection !== null) {
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
                            $entryTaken = true;
                            $sequenceCount = 0;
                            $sequenceCompleted = false;
                            $referenceHigh = $high;
                            $referenceLow = $low;
                        }
                    }
                }
            }

            $previousCandle = $candle;
            $previousMa = $ma;
            $lastPrice = $close;
            $lastTimestamp = $timestamp;
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
                'trend_cycle' => $trendCycleId,
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

    private function formatTimestamp(?int $timestamp): ?string
    {
        return $timestamp ? Carbon::createFromTimestamp($timestamp)->format('Y-m-d H:i:s') : null;
    }
}
