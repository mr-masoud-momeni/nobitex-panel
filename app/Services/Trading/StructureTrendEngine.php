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

        /*
         * Setup state:
         *
         * 1. A close crosses the EMA -> activate a flag.
         * 2. Price must move far enough from the EMA.
         * 3. That distance must remain extended for N candles.
         * 4. Distance starts shrinking -> pullback begins.
         * 5. After M meaningful pullback candles, wait for a confirmation candle.
         * 6. If confirmation fails, keep waiting. Do not start another dome.
         * 7. Only a new EMA crossing cancels the whole setup and starts a new one.
         */
        $setupDirection = null;
        $domeActive = false;
        $domeCount = 0;
        $peakDistance = null;
        $pullbackStarted = false;
        $pullbackCount = 0;
        $waitingConfirmation = false;

        // Previous candle/EMA are needed to detect actual crossings.
        $previousClose = null;
        $previousEma = null;
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

        $domeDetections = [];
        $domeCandidateDirection = null;
        $domeStartTimestamp = null;
        $domeStartPrice = null;
        $domePeakTimestamp = null;
        $domePeakPrice = null;
        $domePeakDistance = 0.0;
        $domeMovedAway = false;

        $debug = [
            'ema_crosses' => 0,
            'dome_detected' => 0,
            'pullback_started' => 0,
            'pullback_completed' => 0,
            'confirmation_detected' => 0,
            'confirmation_rejected' => 0,
            'entry_filters_passed' => 0,
            'entries' => 0,
            'domes' => [],
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
            $allowedLong = $direction === 'long' || $direction === 'both';
            $allowedShort = $direction === 'short' || $direction === 'both';

            // Inspection-only dome detector: first EMA crossing -> move away -> return crossing.
            if ($domeCandidateDirection === null && $previousClose !== null && $previousEma !== null) {
                if ($previousClose <= $previousEma && $close > $ma) {
                    $domeCandidateDirection = 'long';
                    $domeStartTimestamp = $timestamp;
                    $domeStartPrice = $close;
                    $domePeakTimestamp = $timestamp;
                    $domePeakPrice = $close;
                    $domePeakDistance = $emaDistance;
                    $domeMovedAway = false;
                } elseif ($previousClose >= $previousEma && $close < $ma) {
                    $domeCandidateDirection = 'short';
                    $domeStartTimestamp = $timestamp;
                    $domeStartPrice = $close;
                    $domePeakTimestamp = $timestamp;
                    $domePeakPrice = $close;
                    $domePeakDistance = $emaDistance;
                    $domeMovedAway = false;
                }
            } elseif ($domeCandidateDirection !== null) {
                if ($emaDistance > $domePeakDistance) {
                    $domePeakDistance = $emaDistance;
                    $domePeakTimestamp = $timestamp;
                    $domePeakPrice = $close;
                    $domeMovedAway = true;
                }

                $returnedToEma = ($domeCandidateDirection === 'long' && $previousClose > $previousEma && $close <= $ma)
                    || ($domeCandidateDirection === 'short' && $previousClose < $previousEma && $close >= $ma);

                if ($returnedToEma) {
                    if ($domeMovedAway) {
                        $domeDetections[] = [
                            'direction' => $domeCandidateDirection,
                            'start_time' => $this->formatTimestamp($domeStartTimestamp),
                            'start_time_jalali' => $this->formatTimestampJalali($domeStartTimestamp),
                            'start_price' => $domeStartPrice,
                            'peak_time' => $this->formatTimestamp($domePeakTimestamp),
                            'peak_time_jalali' => $this->formatTimestampJalali($domePeakTimestamp),
                            'peak_price' => $domePeakPrice,
                            'peak_distance_percent' => $domePeakDistance,
                            'end_time' => $this->formatTimestamp($timestamp),
                            'end_time_jalali' => $this->formatTimestampJalali($timestamp),
                            'end_price' => $close,
                        ];
                    }

                    $domeCandidateDirection = null;
                    $domeStartTimestamp = null;
                    $domeStartPrice = null;
                    $domePeakTimestamp = null;
                    $domePeakPrice = null;
                    $domePeakDistance = 0.0;
                    $domeMovedAway = false;
                }
            }

            $crossedUp = $previousClose !== null
                && $previousEma !== null
                && $previousClose <= $previousEma
                && $close > $ma;

            $crossedDown = $previousClose !== null
                && $previousEma !== null
                && $previousClose >= $previousEma
                && $close < $ma;

            /*
             * A new EMA crossing is the only event that replaces an old setup.
             * This is intentional: once a dome/pullback is identified, a failed
             * confirmation must not immediately create another setup on the same side.
             */
            if ($quantity <= 0 && ($crossedUp || $crossedDown)) {
                $newDirection = $crossedUp ? 'long' : 'short';
                $directionAllowed = $newDirection === 'long' ? $allowedLong : $allowedShort;

                if ($directionAllowed) {
                    $setupDirection = $newDirection;
                    $domeActive = false;
                    $domeCount = 0;
                    $peakDistance = 0.0;
                    $pullbackStarted = false;
                    $pullbackCount = 0;
                    $waitingConfirmation = false;
                    $debug['ema_crosses']++;
                } else {
                    $this->resetSetup(
                        $setupDirection,
                        $domeActive,
                        $domeCount,
                        $peakDistance,
                        $pullbackStarted,
                        $pullbackCount,
                        $waitingConfirmation
                    );
                }
            }

            // Build the setup only while no position is open.
            if ($quantity <= 0 && $setupDirection !== null) {
                $directionStillValid = $setupDirection === 'long' ? $close > $ma : $close < $ma;

                // Crossing back through EMA cancels the setup.
                if (!$directionStillValid) {
                    $this->resetSetup(
                        $setupDirection,
                        $domeActive,
                        $domeCount,
                        $peakDistance,
                        $pullbackStarted,
                        $pullbackCount,
                        $waitingConfirmation
                    );
                } elseif (!$waitingConfirmation) {
                    if (!$domeActive) {
                        // The EMA crossing has activated the flag, but the dome
                        // only starts once the configured distance is reached.
                        if ($emaDistance >= $minEmaDistancePercent) {
                            $domeActive = true;
                            $domeCount = 1;
                            $peakDistance = $emaDistance;
                        }
                    } elseif (!$pullbackStarted) {
                        // Keep extending the dome while distance is stable/increasing.
                        if ($emaDistance >= $minEmaDistancePercent) {
                            if ($peakDistance === null || $emaDistance >= $peakDistance) {
                                $peakDistance = $emaDistance;
                                $domeCount++;
                            } elseif ($previousDistance !== null
                                && ($previousDistance - $emaDistance) >= $minPullbackSlopePercent
                                && $domeCount >= $minDomeCandles
                            ) {
                                // Distance has turned down after a sufficiently
                                // long extension: the dome is complete.
                                $pullbackStarted = true;
                                $pullbackCount = 1;
                                $debug['dome_detected']++;
                                $debug['pullback_started']++;
                            }
                        } elseif ($domeCount >= $minDomeCandles
                            && $previousDistance !== null
                            && ($previousDistance - $emaDistance) >= $minPullbackSlopePercent
                        ) {
                            $pullbackStarted = true;
                            $pullbackCount = 1;
                            $debug['dome_detected']++;
                            $debug['pullback_started']++;
                        }
                    } else {
                        // Pullback: count only candles with a meaningful decrease
                        // in distance from EMA.
                        $distanceDrop = $previousDistance !== null
                            ? $previousDistance - $emaDistance
                            : 0.0;

                        if ($distanceDrop >= $minPullbackSlopePercent) {
                            $pullbackCount++;
                        }

                        // Once enough pullback candles exist, an expansion away
                        // from EMA is the confirmation candidate.
                        $distanceTurnsBack = $previousDistance !== null
                            && $emaDistance > $previousDistance;

                        if ($pullbackCount >= $minPullbackCandles && $distanceTurnsBack) {
                            $debug['pullback_completed']++;
                            $debug['confirmation_detected']++;

                            $candleDirectionOk = $setupDirection === 'long'
                                ? $close > $open
                                : $close < $open;

                            $candleSizeOk = $this->candleBodyPercent($open, $close) >= $minEntryCandlePercent;
                            $emaDistanceOk = $emaDistance >= $minEmaDistancePercent;

                            if ($candleDirectionOk && $candleSizeOk && $emaDistanceOk) {
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

                                        $this->resetSetup(
                                            $setupDirection,
                                            $domeActive,
                                            $domeCount,
                                            $peakDistance,
                                            $pullbackStarted,
                                            $pullbackCount,
                                            $waitingConfirmation
                                        );
                                    }
                                }
                            } else {
                                /*
                                 * Dome and pullback were real, but this candle did
                                 * not confirm. Freeze this setup. We now wait for a
                                 * fresh EMA crossing instead of repeatedly re-testing
                                 * every candle in the same structure.
                                 */
                                $debug['confirmation_rejected']++;
                                $waitingConfirmation = true;
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

            $previousClose = $close;
            $previousEma = $ma;
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

        $debug['domes'] = $domeDetections;

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

    private function resetSetup(
        ?string &$direction,
        bool &$domeActive,
        int &$domeCount,
        ?float &$peakDistance,
        bool &$pullbackStarted,
        int &$pullbackCount,
        bool &$waitingConfirmation
    ): void {
        $direction = null;
        $domeActive = false;
        $domeCount = 0;
        $peakDistance = null;
        $pullbackStarted = false;
        $pullbackCount = 0;
        $waitingConfirmation = false;
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
        return $timestamp ? Carbon::createFromTimestamp($timestamp, 'Asia/Tehran')->format('Y-m-d H:i:s') : null;
    }

    private function formatTimestampJalali(?int $timestamp): ?string
    {
        if (!$timestamp) {
            return null;
        }

        $date = Carbon::createFromTimestamp($timestamp, 'Asia/Tehran');
        [$year, $month, $day] = $this->gregorianToJalali(
            (int) $date->format('Y'),
            (int) $date->format('m'),
            (int) $date->format('d')
        );

        return sprintf('%04d/%02d/%02d %s', $year, $month, $day, $date->format('H:i'));
    }

    private function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $gDaysInMonth = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $jDaysInMonth = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
        $gy -= 1600;
        $gm -= 1;
        $gd -= 1;
        $gDayNo = 365 * $gy + intdiv($gy + 3, 4) - intdiv($gy + 99, 100) + intdiv($gy + 399, 400);
        for ($i = 0; $i < $gm; $i++) $gDayNo += $gDaysInMonth[$i];
        if ($gm > 1 && (($gy + 1600) % 4 === 0 && (($gy + 1600) % 100 !== 0 || ($gy + 1600) % 400 === 0))) $gDayNo++;
        $gDayNo += $gd;
        $jDayNo = $gDayNo - 79;
        $jNp = intdiv($jDayNo, 12053);
        $jDayNo %= 12053;
        $jy = 979 + 33 * $jNp + 4 * intdiv($jDayNo, 1461);
        $jDayNo %= 1461;
        if ($jDayNo >= 366) {
            $jy += intdiv($jDayNo - 1, 365);
            $jDayNo = ($jDayNo - 1) % 365;
        }
        for ($i = 0; $i < 11 && $jDayNo >= $jDaysInMonth[$i]; $i++) $jDayNo -= $jDaysInMonth[$i];
        return [$jy, $i + 1, $jDayNo + 1];
    }
}
