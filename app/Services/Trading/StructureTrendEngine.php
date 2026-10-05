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

        $maPeriod = max(1, min(1000, (int) ($config['ma_period'] ?? 20)));
        $minEmaDistancePercent = max(0.0, (float) ($config['min_ema_distance_percent'] ?? 0.5));
        $minDomeCandles = max(2, min(20, (int) ($config['min_dome_candles'] ?? 3)));
        $minPullbackCandles = max(1, min(20, (int) ($config['min_pullback_candles'] ?? 2)));
        $minPullbackSlopePercent = max(0.0, (float) ($config['min_pullback_slope_percent'] ?? 0.05));
        $minEntryCandlePercent = max(0.0, (float) ($config['min_entry_candle_percent'] ?? 0.3));
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

        // Pullback "dome" state:
        // far from EMA -> dome/extension -> distance starts shrinking -> confirmation.
        $setupDirection = null;
        $domeCount = 0;
        $peakDistance = null;
        $pullbackCount = 0;
        $pullbackDistanceStart = null;
        $previousDistance = null;

        // Exit state: close-based reference high/low.
        $sequenceCount = 0;
        $referenceHigh = null;
        $referenceLow = null;

        $lastPrice = null;
        $lastTimestamp = null;

        $totalTrades = 0;
        $winningTrades = 0;
        $losingTrades = 0;
        $executionLog = [];

        $debug = [
            'dome_detected' => 0,
            'pullback_started' => 0,
            'pullback_completed' => 0,
            'confirmation_detected' => 0,
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

            if ($ma === null || $ma <= 0) {
                $lastPrice = $close;
                $lastTimestamp = $timestamp;
                continue;
            }

            $emaDistance = (abs($close - $ma) / $ma) * 100;
            $aboveEma = $close > $ma;
            $belowEma = $close < $ma;

            // Build/track the dome only while no position is open.
            if ($quantity <= 0) {
                $allowedLong = $direction === 'long' || $direction === 'both';
                $allowedShort = $direction === 'short' || $direction === 'both';

                if ($setupDirection === null) {
                    if ($emaDistance >= $minEmaDistancePercent) {
                        if ($aboveEma && $allowedLong) {
                            $setupDirection = 'long';
                            $domeCount = 1;
                            $peakDistance = $emaDistance;
                            $pullbackCount = 0;
                            $pullbackDistanceStart = null;
                        } elseif ($belowEma && $allowedShort) {
                            $setupDirection = 'short';
                            $domeCount = 1;
                            $peakDistance = $emaDistance;
                            $pullbackCount = 0;
                            $pullbackDistanceStart = null;
                        }
                    }
                } else {
                    $directionStillValid = $setupDirection === 'long' ? $aboveEma : $belowEma;

                    if (!$directionStillValid) {
                        $this->resetDome(
                            $setupDirection,
                            $domeCount,
                            $peakDistance,
                            $pullbackCount,
                            $pullbackDistanceStart
                        );
                    } elseif ($pullbackCount === 0) {
                        if ($peakDistance === null || $emaDistance >= $peakDistance) {
                            $peakDistance = $emaDistance;
                            $domeCount++;
                        } else {
                            // The distance has turned down after building an extension.
                            if ($domeCount >= $minDomeCandles) {
                                $pullbackCount = 1;
                                $pullbackDistanceStart = $emaDistance;
                                $debug['dome_detected']++;
                                $debug['pullback_started']++;
                            } else {
                                // Too short to be a dome; keep the newest extension
                                // as the beginning of a fresh candidate.
                                $domeCount = 1;
                                $peakDistance = $emaDistance;
                            }
                        }
                    } else {
                        $distanceDrop = $previousDistance !== null
                            ? $previousDistance - $emaDistance
                            : 0.0;

                        if ($distanceDrop >= $minPullbackSlopePercent) {
                            $pullbackCount++;
                        } elseif ($emaDistance >= $previousDistance) {
                            // Distance stopped falling. If enough pullback candles
                            // exist, this candle is the confirmation candidate.
                            if ($pullbackCount >= $minPullbackCandles) {
                                $debug['pullback_completed']++;

                                $candleDirectionOk = $setupDirection === 'long'
                                    ? $close > $open
                                    : $close < $open;

                                $distanceTurnsBack = $emaDistance > $previousDistance;
                                $candleSizeOk = $this->candleBodyPercent($open, $close) >= $minEntryCandlePercent;
                                $emaDistanceOk = $emaDistance >= $minEmaDistancePercent;

                                if ($candleDirectionOk && $distanceTurnsBack && $candleSizeOk && $emaDistanceOk) {
                                    $debug['confirmation_detected']++;
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
                                            $entryReason = 'ema_dome_pullback_confirmation';
                                            $sequenceCount = 0;
                                            $referenceHigh = $close;
                                            $referenceLow = $close;

                                            $this->resetDome(
                                                $setupDirection,
                                                $domeCount,
                                                $peakDistance,
                                                $pullbackCount,
                                                $pullbackDistanceStart
                                            );
                                        }
                                    }
                                }
                            }

                            if ($quantity <= 0) {
                                // The pullback failed to produce a valid confirmation.
                                // Start over from the current distance if it is still
                                // far enough from EMA.
                                if ($emaDistance >= $minEmaDistancePercent) {
                                    $domeCount = 1;
                                    $peakDistance = $emaDistance;
                                    $pullbackCount = 0;
                                    $pullbackDistanceStart = null;
                                } else {
                                    $this->resetDome(
                                        $setupDirection,
                                        $domeCount,
                                        $peakDistance,
                                        $pullbackCount,
                                        $pullbackDistanceStart
                                    );
                                }
                            }
                        } else {
                            // Pullback continues. A strong expansion away from EMA
                            // updates the dome peak and requires a new pullback.
                            if ($emaDistance > $peakDistance) {
                                $peakDistance = $emaDistance;
                            }
                        }
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

            $previousDistance = $emaDistance;
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

    private function resetDome(
        ?string &$direction,
        int &$domeCount,
        ?float &$peakDistance,
        int &$pullbackCount,
        ?float &$pullbackDistanceStart
    ): void {
        $direction = null;
        $domeCount = 0;
        $peakDistance = null;
        $pullbackCount = 0;
        $pullbackDistanceStart = null;
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
