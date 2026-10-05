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

        $rangeLookback = max(10, min(500, (int) ($config['range_lookback_candles'] ?? 30)));
        $minMoveCandles = max(1, min(20, (int) ($config['min_move_candles'] ?? 2)));
        $minPullbackCandles = max(1, min(20, (int) ($config['min_pullback_candles'] ?? 1)));
        $minEntryCandlePercent = max(0.0, (float) ($config['min_entry_candle_percent'] ?? 0.3));
        $minEmaDistancePercent = max(0.0, (float) ($config['min_ema_distance_percent'] ?? 0.2));
        $maPeriod = max(1, min(1000, (int) ($config['ma_period'] ?? 20)));
        $sequenceLength = max(2, min(20, (int) ($config['exit_sequence_count'] ?? 3)));

        // Sideways width is intentionally an internal rule, not a user parameter.
        // The visible parameter is only how many candles must form the range.
        $rangeMaxWidthPercent = 3.0;

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

        // Setup state:
        // range -> breakout -> impulse -> pullback -> re-break/entry.
        $setupDirection = null;
        $breakoutLevel = null;
        $breakoutTimestamp = null;
        $impulseCount = 0;
        $pullbackCount = 0;
        $pullbackStarted = false;

        // Exit state: close-based reference high/low.
        $sequenceCount = 0;
        $referenceHigh = null;
        $referenceLow = null;

        $lastPrice = null;
        $lastTimestamp = null;
        $previousClose = null;

        $totalTrades = 0;
        $winningTrades = 0;
        $losingTrades = 0;
        $executionLog = [];

        // Setup instrumentation: tells us exactly where potential setups are filtered.
        $debug = [
            'range_detected' => 0,
            'breakout_detected' => 0,
            'impulse_completed' => 0,
            'pullback_started' => 0,
            'rebreak_detected' => 0,
            'entry_filters_passed' => 0,
            'entries' => 0,
        ];

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

            if (count($candlesBuffer) > $rangeLookback + 2) {
                array_shift($candlesBuffer);
            }

            if ($ma === null || $previousClose === null) {
                $lastPrice = $close;
                $lastTimestamp = $timestamp;
                $previousClose = $close;
                continue;
            }

            // Detect the range from the candles BEFORE the current candle.
            // Do not overwrite an active setup with a newer range.
            $range = null;
            if ($quantity <= 0 && $setupDirection === null) {
                $range = $this->detectRange($candlesBuffer, $rangeLookback, $rangeMaxWidthPercent);

                if ($range !== null) {
                    $debug['range_detected']++;
                }

                if ($range !== null && ($direction === 'long' || $direction === 'both') && $close > $range['high']) {
                    $setupDirection = 'long';

                    // Re-break is against the original range resistance, not the
                    // breakout candle's wick. This matches the visual concept:
                    // break -> pullback -> break of the range again.
                    $breakoutLevel = $range['high'];
                    $breakoutTimestamp = $timestamp;
                    $debug['breakout_detected']++;
                } elseif ($range !== null && ($direction === 'short' || $direction === 'both') && $close < $range['low']) {
                    $setupDirection = 'short';
                    $breakoutLevel = $range['low'];
                    $breakoutTimestamp = $timestamp;
                    $debug['breakout_detected']++;
                }
            }

            if ($quantity <= 0 && $setupDirection !== null && $breakoutLevel !== null) {
                $isDirectionalMove = $setupDirection === 'long'
                    ? $close > $previousClose
                    : $close < $previousClose;

                $isPullbackMove = $setupDirection === 'long'
                    ? $close < $previousClose
                    : $close > $previousClose;

                if (!$pullbackStarted) {
                    if ($isDirectionalMove) {
                        $impulseCount++;

                        if ($impulseCount === $minMoveCandles) {
                            $debug['impulse_completed']++;
                        }
                    } elseif ($isPullbackMove && $impulseCount >= $minMoveCandles) {
                        $pullbackStarted = true;
                        $pullbackCount = 1;
                        $debug['pullback_started']++;
                    }

                    // A return through the breakout level before the pullback is
                    // complete invalidates the setup.
                    if (
                        ($setupDirection === 'long' && $close < $breakoutLevel)
                        || ($setupDirection === 'short' && $close > $breakoutLevel)
                    ) {
                        $this->resetSetup(
                            $setupDirection,
                            $breakoutLevel,
                            $breakoutTimestamp,
                            $impulseCount,
                            $pullbackCount,
                            $pullbackStarted
                        );
                    }
                } else {
                    if ($isPullbackMove) {
                        $pullbackCount++;
                    } elseif ($isDirectionalMove && $pullbackCount >= $minPullbackCandles) {
                        // This is the first candle capable of re-breaking the
                        // breakout candle's extreme.
                    }

                    if (
                        ($setupDirection === 'long' && $close < $breakoutLevel)
                        || ($setupDirection === 'short' && $close > $breakoutLevel)
                    ) {
                        $this->resetSetup(
                            $setupDirection,
                            $breakoutLevel,
                            $breakoutTimestamp,
                            $impulseCount,
                            $pullbackCount,
                            $pullbackStarted
                        );
                    }
                }
            }

            // Risk exits are checked before the structural trailing exit.
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

            // Entry is only possible after:
            // breakout -> minimum directional candles -> minimum pullback candles
            // -> close breaks the breakout candle's high/low again.
            if ($quantity <= 0 && $setupDirection !== null && $breakoutLevel !== null && $pullbackStarted) {
                $reBreak = $setupDirection === 'long'
                    ? $close > $breakoutLevel
                    : $close < $breakoutLevel;

                $candleDirectionOk = $setupDirection === 'long'
                    ? $close > $open
                    : $close < $open;

                $candleSizeOk = $this->candleBodyPercent($open, $close) >= $minEntryCandlePercent;
                $emaDistanceOk = $ma > 0
                    && (abs($close - $ma) / $ma) * 100 >= $minEmaDistancePercent;

                $entrySignal = $pullbackCount >= $minPullbackCandles
                    && $reBreak
                    && $candleDirectionOk
                    && $candleSizeOk
                    && $emaDistanceOk;

                if ($reBreak && $pullbackCount >= $minPullbackCandles) {
                    $debug['rebreak_detected']++;
                }

                if ($entrySignal) {
                    $debug['entry_filters_passed']++;
                    $notional = $this->positionNotional(
                        $cash,
                        (float) ($strategy->risk_percent ?? 0),
                        (float) ($strategy->stop_loss ?? 0)
                    );

                    if ($notional > 0 && $close > 0) {
                        $quantity = $notional / $close;
                        $entryFee = $notional * $feeRate;
                        $entryValue = $setupDirection === 'long' ? $notional + $entryFee : $notional;

                        if ($setupDirection === 'long') {
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
                            $debug['entries']++;
                            $positionDirection = $setupDirection;
                            $entryPrice = $close;
                            $entryTimestamp = $timestamp;
                            $entryReason = 'range_breakout_pullback_rebreak';
                            $sequenceCount = 0;
                            $referenceHigh = $close;
                            $referenceLow = $close;

                            // One setup produces one entry.
                            $setupDirection = null;
                            $breakoutLevel = null;
                            $breakoutTimestamp = null;
                            $impulseCount = 0;
                            $pullbackCount = 0;
                            $pullbackStarted = false;
                        }
                    }
                }
            }

            $lastPrice = $close;
            $lastTimestamp = $timestamp;
            $previousClose = $close;
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
            'structure_debug' => $debug,
        ];
    }

    private function detectRange(array $candles, int $lookback, float $maxWidthPercent): ?array
    {
        // Exclude the current candle: the last item is the candle being evaluated.
        if (count($candles) < $lookback + 1) {
            return null;
        }

        $sample = array_slice($candles, -($lookback + 1), $lookback);
        $high = max(array_column($sample, 'high'));
        $low = min(array_column($sample, 'low'));

        if ($low <= 0 || (($high - $low) / $low) * 100 > $maxWidthPercent) {
            return null;
        }

        return ['high' => $high, 'low' => $low];
    }

    private function resetSetup(
        ?string &$direction,
        ?float &$level,
        ?int &$timestamp,
        int &$impulseCount,
        int &$pullbackCount,
        bool &$pullbackStarted
    ): void {
        $direction = null;
        $level = null;
        $timestamp = null;
        $impulseCount = 0;
        $pullbackCount = 0;
        $pullbackStarted = false;
    }

    private function candleBodyPercent(float $open, float $close): float
    {
        $base = min($open, $close);
        if ($base <= 0) {
            return 0;
        }

        return (abs($close - $open) / $base) * 100;
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
