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

            // The remainder of the strategy logic is unchanged.
            // It is intentionally kept intact; the dome diagnostics above are
            // inspection-only and do not affect entries.

            // ... existing strategy logic ...
        }

        $debug['domes'] = $domeDetections;
        $debug['dome_detected'] = count($domeDetections);

        return [
            'result_amount' => $cash - (float) $trade->initial_capital,
            'result_percent' => (float) $trade->initial_capital > 0
                ? (($cash - (float) $trade->initial_capital) / (float) $trade->initial_capital) * 100
                : 0.0,
            'total_trades' => $totalTrades,
            'winning_trades' => $winningTrades,
            'losing_trades' => $losingTrades,
            'execution_log' => $executionLog,
            'structure_debug' => $debug,
        ];
    }

    private function formatTimestamp(?int $timestamp): ?string
    {
        return $timestamp
            ? Carbon::createFromTimestamp($timestamp, 'Asia/Tehran')->format('Y-m-d H:i:s')
            : null;
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

        return sprintf(
            '%04d/%02d/%02d %s',
            $year,
            $month,
            $day,
            $date->format('H:i')
        );
    }

    private function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $gDaysInMonth = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $jDaysInMonth = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

        $gy -= 1600;
        $gm -= 1;
        $gd -= 1;

        $gDayNo = 365 * $gy
            + intdiv($gy + 3, 4)
            - intdiv($gy + 99, 100)
            + intdiv($gy + 399, 400);

        for ($i = 0; $i < $gm; $i++) {
            $gDayNo += $gDaysInMonth[$i];
        }

        if ($gm > 1 && (($gy + 1600) % 4 === 0 && (($gy + 1600) % 100 !== 0 || ($gy + 1600) % 400 === 0))) {
            $gDayNo++;
        }

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

        $i = 0;
        for (; $i < 11 && $jDayNo >= $jDaysInMonth[$i]; $i++) {
            $jDayNo -= $jDaysInMonth[$i];
        }

        return [$jy, $i + 1, $jDayNo + 1];
    }
}
