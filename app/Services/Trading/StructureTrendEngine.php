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

        // The market-data layer may intentionally provide candles before the
        // requested start date so indicators can warm up. Those candles must
        // never create a dome or a trade. The end date is a hard upper bound:
        // candles after it are not part of the backtest either.
        $startTimestamp = $trade->start_date instanceof \DateTimeInterface
            ? $trade->start_date->getTimestamp()
            : Carbon::parse($trade->start_date)->getTimestamp();
        $endTimestamp = $trade->end_date instanceof \DateTimeInterface
            ? $trade->end_date->getTimestamp()
            : Carbon::parse($trade->end_date)->getTimestamp();

        $cash = (float) $trade->initial_capital;
        $feeRate = max(0.0, (float) ($trade->fee_percent ?? 0)) / 100;

        $quantity = 0.0;
        $positionDirection = null;
        $entryPrice = null;
        $entryValue = null;
        $entryFee = 0.0;
        $entryTimestamp = null;
        $entryReason = null;

        $setupDirection = null;
        $domeActive = false;
        $domeCount = 0;
        $peakDistance = null;
        $pullbackStarted = false;
        $pullbackCount = 0;
        $waitingConfirmation = false;

        $previousClose = null;
        $previousEma = null;
        $previousDistance = null;

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
        $domeCandles = 0;
        $domeReturnCandles = 0;
        $domeReturning = false;

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
            'dome_starts' => [],
        ];

        foreach ($candles as $candle) {
            $timestamp = (int) $candle->timestamp;

            // Stop at the requested end date. This is important when the API
            // returns more candles than the selected test window.
            if ($timestamp > $endTimestamp) {
                break;
            }

            $close = (float) $candle->close;
            $open = (float) $candle->open;
            $high = (float) $candle->high;
            $low = (float) $candle->low;
            $ma = $ema->update($close);

            if ($ma === null || $ma <= 0) {
                $lastPrice = $close;
                $lastTimestamp = $timestamp;
                continue;
            }

            $emaDistance = (abs($close - $ma) / $ma) * 100;
            $allowedLong = $direction === 'long' || $direction === 'both';
            $allowedShort = $direction === 'short' || $direction === 'both';

            // Warm-up candles are used ONLY to bring EMA to the correct state.
            // They cannot start/complete a dome and cannot open/close trades.
            if ($timestamp < $startTimestamp) {
                $previousClose = $close;
                $previousEma = $ma;
                $previousDistance = $emaDistance;
                $lastPrice = $close;
                $lastTimestamp = $timestamp;
                continue;
            }

            // Do not carry a dome/setup that began before the requested window.
            // The first eligible candle is the beginning of the test window.
            if ($timestamp === $startTimestamp) {
                $domeCandidateDirection = null;
                $domeStartTimestamp = null;
                $domeStartPrice = null;
                $domePeakTimestamp = null;
                $domePeakPrice = null;
                $domePeakDistance = 0.0;
                $domeMovedAway = false;
                $domeCandles = 0;
                $domeReturnCandles = 0;
                $domeReturning = false;

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

            // Inspection-only dome detector.
            // Lower: bearish close below EMA -> at least the configured number
            // of candles on the lower side -> lowest Close is trough -> return
            // to EMA by wick. Upper is the exact mirror.
            if ($domeCandidateDirection === null) {
                $bearishStart = $open > $close && $close < $ma;
                $bullishStart = $open < $close && $close > $ma;

                $newLowerSide = $previousClose !== null
                    && $previousEma !== null
                    && $previousClose >= $previousEma;

                $newUpperSide = $previousClose !== null
                    && $previousEma !== null
                    && $previousClose <= $previousEma;

                if ($bearishStart && $newLowerSide) {
                    $debug['dome_starts'][] = [
                        'direction' => 'short',
                        'time' => $this->formatTimestamp($timestamp),
                        'time_jalali' => $this->formatTimestampJalali($timestamp),
                        'open' => $open,
                        'close' => $close,
                        'high' => $high,
                        'low' => $low,
                        'ema' => $ma,
                        'ema_distance_percent' => $emaDistance,
                        'previous_close' => $previousClose,
                        'previous_ema' => $previousEma,
                    ];

                    $domeCandidateDirection = 'short';
                    $domeStartTimestamp = $timestamp;
                    $domeStartPrice = $close;
                    $domePeakTimestamp = $timestamp;
                    $domePeakPrice = $close;
                    $domePeakDistance = $emaDistance;
                    $domeMovedAway = false;
                    $domeCandles = 1;
                    $domeReturnCandles = 0;
                    $domeReturning = false;
                } elseif ($bullishStart && $newUpperSide) {
                    $debug['dome_starts'][] = [
                        'direction' => 'long',
                        'time' => $this->formatTimestamp($timestamp),
                        'time_jalali' => $this->formatTimestampJalali($timestamp),
                        'open' => $open,
                        'close' => $close,
                        'high' => $high,
                        'low' => $low,
                        'ema' => $ma,
                        'ema_distance_percent' => $emaDistance,
                        'previous_close' => $previousClose,
                        'previous_ema' => $previousEma,
                    ];

                    $domeCandidateDirection = 'long';
                    $domeStartTimestamp = $timestamp;
                    $domeStartPrice = $close;
                    $domePeakTimestamp = $timestamp;
                    $domePeakPrice = $close;
                    $domePeakDistance = $emaDistance;
                    $domeMovedAway = false;
                    $domeCandles = 1;
                    $domeReturnCandles = 0;
                    $domeReturning = false;
                }
            } elseif ($domeCandidateDirection !== null) {
                $isLower = $domeCandidateDirection === 'short';
                $fullyOnDomeSide = $isLower ? $high <= $ma : $low >= $ma;
                $reachedEma = $isLower ? $high >= $ma : $low <= $ma;

                if ($fullyOnDomeSide) {
                    $domeCandles++;

                    $isMoreExtreme = $isLower
                        ? $close < $domePeakPrice
                        : $close > $domePeakPrice;

                    if ($isMoreExtreme) {
                        $domePeakPrice = $close;
                        $domePeakTimestamp = $timestamp;
                    }

                    if ($emaDistance > $domePeakDistance) {
                        $domeMovedAway = true;
                        $domePeakDistance = $emaDistance;
                    }

                    if ($domeMovedAway
                        && $domeCandles >= $minDomeCandles
                        && $emaDistance < $domePeakDistance
                    ) {
                        $domeReturning = true;
                    }

                    if ($domeReturning) {
                        $domeReturnCandles++;
                    }
                } elseif (!$reachedEma) {
                    $domeCandidateDirection = null;
                    $domeStartTimestamp = null;
                    $domeStartPrice = null;
                    $domePeakTimestamp = null;
                    $domePeakPrice = null;
                    $domePeakDistance = 0.0;
                    $domeMovedAway = false;
                    $domeCandles = 0;
                    $domeReturnCandles = 0;
                    $domeReturning = false;
                }

                if ($reachedEma) {
                    if ($domeCandles >= $minDomeCandles && $domeMovedAway) {
                        $domeDetections[] = [
                            'direction' => $domeCandidateDirection,
                            'start_time' => $this->formatTimestamp($domeStartTimestamp),
                            'start_time_jalali' => $this->formatTimestampJalali($domeStartTimestamp),
                            'start_price' => $domeStartPrice,
                            'dome_candles' => $domeCandles,
                            'return_candles' => $domeReturnCandles,
                            'peak_time' => $this->formatTimestamp($domePeakTimestamp),
                            'peak_time_jalali' => $this->formatTimestampJalali($domePeakTimestamp),
                            'peak_price' => $domePeakPrice,
                            'peak_distance_percent' => $domePeakDistance,
                            'end_time' => $this->formatTimestamp($timestamp),
                            'end_time_jalali' => $this->formatTimestampJalali($timestamp),
                            'end_price' => $close,
                        ];
                        $debug['dome_detected']++;
                    }

                    $domeCandidateDirection = null;
                    $domeStartTimestamp = null;
                    $domeStartPrice = null;
                    $domePeakTimestamp = null;
                    $domePeakPrice = null;
                    $domePeakDistance = 0.0;
                    $domeMovedAway = false;
                    $domeCandles = 0;
                    $domeReturnCandles = 0;
                    $domeReturning = false;
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
                    $this->resetSetup($setupDirection, $domeActive, $domeCount, $peakDistance, $pullbackStarted, $pullbackCount, $waitingConfirmation);
                }
            }

            if ($quantity <= 0 && $setupDirection !== null) {
                $directionStillValid = $setupDirection === 'long' ? $close > $ma : $close < $ma;

                if (!$directionStillValid) {
                    $this->resetSetup($setupDirection, $domeActive, $domeCount, $peakDistance, $pullbackStarted, $pullbackCount, $waitingConfirmation);
                } elseif (!$waitingConfirmation) {
                    if (!$domeActive) {
                        if ($emaDistance >= $minEmaDistancePercent) {
                            $domeActive = true;
                            $domeCount = 1;
                            $peakDistance = $emaDistance;
                        }
                    } elseif (!$pullbackStarted) {
                        if ($emaDistance >= $minEmaDistancePercent) {
                            if ($peakDistance === null || $emaDistance >= $peakDistance) {
                                $peakDistance = $emaDistance;
                                $domeCount++;
                            } elseif ($previousDistance !== null
                                && ($previousDistance - $emaDistance) >= $minPullbackSlopePercent
                                && $domeCount >= $minDomeCandles
                            ) {
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
                        $distanceDrop = $previousDistance !== null ? $previousDistance - $emaDistance : 0.0;
                        if ($distanceDrop >= $minPullbackSlopePercent) {
                            $pullbackCount++;
                        }

                        $distanceTurnsBack = $previousDistance !== null && $emaDistance > $previousDistance;

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
                                $notional = $this->positionNotional($cash, (float) ($strategy->risk_percent ?? 0), (float) ($strategy->stop_loss ?? 0));

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

                                        $this->resetSetup($setupDirection, $domeActive, $domeCount, $peakDistance, $pullbackStarted, $pullbackCount, $waitingConfirmation);
                                    }
                                }
                            } else {
                                $debug['confirmation_rejected']++;
                                $waitingConfirmation = true;
                            }
                        }
                    }
                }
            }

            if ($quantity > 0 && $positionDirection !== null) {
                $exitPrice = $this->exitPriceFromRisk($entryPrice, $high, $low, $strategy, $positionDirection);
                $exitReason = $exitPrice !== null ? 'stop_loss_or_take_profit' : null;

                if ($exitPrice === null) {
                    if ($positionDirection === 'long') {
                        if ($referenceHigh === null) $referenceHigh = $entryPrice;
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
                        if ($referenceLow === null) $referenceLow = $entryPrice;
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
                    [$cash, $profit] = $this->closePosition($cash, $quantity, $entryPrice, $exitPrice, (float) ($trade->fee_percent ?? 0), $entryValue, $entryFee, $positionDirection);
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
            [$cash, $profit] = $this->closePosition($cash, $quantity, $entryPrice, $lastPrice, (float) ($trade->fee_percent ?? 0), $entryValue, $entryFee, $positionDirection);
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

    private function resetSetup(?string &$direction, bool &$domeActive, int &$domeCount, ?float &$peakDistance, bool &$pullbackStarted, int &$pullbackCount, bool &$waitingConfirmation): void
    {
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
        return $close > 0 ? (abs($close - $open) / $close) * 100 : 0.0;
    }

    private function exitPriceFromRisk(?float $entryPrice, float $high, float $low, Strategy $strategy, string $direction): ?float
    {
        if ($entryPrice === null) return null;
        $stopLoss = max(0.0, (float) ($strategy->stop_loss ?? 0));
        $takeProfit = max(0.0, (float) ($strategy->take_profit ?? 0));
        if ($direction === 'long') {
            $sl = $stopLoss > 0 ? $entryPrice * (1 - $stopLoss / 100) : null;
            $tp = $takeProfit > 0 ? $entryPrice * (1 + $takeProfit / 100) : null;
            if ($sl !== null && $low <= $sl) return $sl;
            if ($tp !== null && $high >= $tp) return $tp;
        } else {
            $sl = $stopLoss > 0 ? $entryPrice * (1 + $stopLoss / 100) : null;
            $tp = $takeProfit > 0 ? $entryPrice * (1 - $takeProfit / 100) : null;
            if ($sl !== null && $high >= $sl) return $sl;
            if ($tp !== null && $low <= $tp) return $tp;
        }
        return null;
    }

    private function positionNotional(float $cash, float $riskPercent, float $stopLoss): float
    {
        if ($cash <= 0) return 0.0;
        if ($riskPercent <= 0 || $stopLoss <= 0) return $cash;
        return $cash * ($riskPercent / 100) / ($stopLoss / 100);
    }

    private function closePosition(float $cash, float $quantity, float $entryPrice, float $exitPrice, float $feePercent, float $entryValue, float $entryFee, string $direction): array
    {
        $feeRate = max(0.0, $feePercent) / 100;
        $gross = $quantity * $exitPrice;
        $exitFee = $gross * $feeRate;
        if ($direction === 'long') {
            $newCash = $gross - $exitFee;
            $profit = $newCash - $entryValue;
        } else {
            $newCash = $cash + ($entryPrice - $exitPrice) * $quantity - $exitFee;
            $profit = $newCash - $entryFee - $entryValue;
        }
        return [$newCash, $profit];
    }

    private function formatTimestamp(?int $timestamp): ?string
    {
        return $timestamp ? Carbon::createFromTimestamp($timestamp)->format('Y-m-d H:i:s') : null;
    }

    private function formatTimestampJalali(?int $timestamp): ?string
    {
        if (!$timestamp) return null;

        $date = Carbon::createFromTimestamp($timestamp);
        [$year, $month, $day] = $this->gregorianToJalali(
            (int) $date->format('Y'),
            (int) $date->format('m'),
            (int) $date->format('d')
        );

        return sprintf(
            '%04d-%02d-%02d %s',
            $year,
            $month,
            $day,
            $date->format('H:i:s')
        );
    }

    /**
     * Convert a Gregorian date to the Persian (Jalali) calendar.
     *
     * This is intentionally self-contained so the trading engine does not
     * depend on a non-existent global helper or an additional package.
     */
    private function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        // Standard Gregorian -> Jalali conversion.
        $gDaysInMonth = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $jDaysInMonth = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

        $gy -= 1600;
        $gm -= 1;
        $gd -= 1;

        $gDayNo = 365 * $gy
            + (int) floor(($gy + 3) / 4)
            - (int) floor(($gy + 99) / 100)
            + (int) floor(($gy + 399) / 400);

        for ($i = 0; $i < $gm; $i++) {
            $gDayNo += $gDaysInMonth[$i];
        }

        if ($gm > 1 && (($gy + 1600) % 4 === 0 && (($gy + 1600) % 100 !== 0 || ($gy + 1600) % 400 === 0))) {
            $gDayNo++;
        }

        $gDayNo += $gd;
        $jDayNo = $gDayNo - 79;

        $jNp = (int) floor($jDayNo / 12053);
        $jDayNo %= 12053;

        $jy = 979 + 33 * $jNp + 4 * (int) floor($jDayNo / 1461);
        $jDayNo %= 1461;

        if ($jDayNo >= 366) {
            $jy += (int) floor(($jDayNo - 1) / 365);
            $jDayNo = ($jDayNo - 1) % 365;
        }

        for ($i = 0; $i < 11 && $jDayNo >= $jDaysInMonth[$i]; $i++) {
            $jDayNo -= $jDaysInMonth[$i];
        }

        $jm = $i + 1;
        $jd = $jDayNo + 1;

        return [$jy, $jm, $jd];
    }}
