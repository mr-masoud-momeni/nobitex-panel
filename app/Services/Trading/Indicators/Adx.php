<?php

namespace App\Services\Trading\Indicators;

use InvalidArgumentException;

/**
 * Wilder's Average Directional Index.
 *
 * The class exposes ADX, +DI and -DI from the same OHLC stream.
 * ADX is not available until enough DX observations have accumulated.
 */
class Adx
{
    private int $period;
    private ?float $previousHigh = null;
    private ?float $previousLow = null;
    private ?float $previousClose = null;

    private array $initialTrueRanges = [];
    private array $initialPlusDm = [];
    private array $initialMinusDm = [];

    private ?float $smoothedTrueRange = null;
    private ?float $smoothedPlusDm = null;
    private ?float $smoothedMinusDm = null;

    private array $initialDx = [];
    private ?float $adx = null;
    private ?float $plusDi = null;
    private ?float $minusDi = null;

    public function __construct(int $period = 14)
    {
        if ($period < 1) {
            throw new InvalidArgumentException('ADX period must be at least 1.');
        }

        $this->period = $period;
    }

    /**
     * Process one completed candle and return the current indicator values.
     *
     * @return array{adx: ?float, plus_di: ?float, minus_di: ?float}
     */
    public function updateCandle(float $high, float $low, float $close): array
    {
        if ($high < $low) {
            throw new InvalidArgumentException('Candle high must be greater than or equal to low.');
        }

        if ($this->previousHigh === null || $this->previousLow === null || $this->previousClose === null) {
            $this->previousHigh = $high;
            $this->previousLow = $low;
            $this->previousClose = $close;

            return $this->values();
        }

        $upMove = $high - $this->previousHigh;
        $downMove = $this->previousLow - $low;

        $plusDm = ($upMove > $downMove && $upMove > 0.0) ? $upMove : 0.0;
        $minusDm = ($downMove > $upMove && $downMove > 0.0) ? $downMove : 0.0;
        $trueRange = max(
            $high - $low,
            abs($high - $this->previousClose),
            abs($low - $this->previousClose)
        );

        $this->previousHigh = $high;
        $this->previousLow = $low;
        $this->previousClose = $close;

        if ($this->smoothedTrueRange === null) {
            $this->initialTrueRanges[] = $trueRange;
            $this->initialPlusDm[] = $plusDm;
            $this->initialMinusDm[] = $minusDm;

            if (count($this->initialTrueRanges) < $this->period) {
                return $this->values();
            }

            $this->smoothedTrueRange = array_sum($this->initialTrueRanges);
            $this->smoothedPlusDm = array_sum($this->initialPlusDm);
            $this->smoothedMinusDm = array_sum($this->initialMinusDm);

            $this->initialTrueRanges = [];
            $this->initialPlusDm = [];
            $this->initialMinusDm = [];
        } else {
            $this->smoothedTrueRange = $this->smoothedTrueRange - ($this->smoothedTrueRange / $this->period) + $trueRange;
            $this->smoothedPlusDm = $this->smoothedPlusDm - ($this->smoothedPlusDm / $this->period) + $plusDm;
            $this->smoothedMinusDm = $this->smoothedMinusDm - ($this->smoothedMinusDm / $this->period) + $minusDm;
        }

        $this->plusDi = $this->smoothedTrueRange > 0.0
            ? 100.0 * $this->smoothedPlusDm / $this->smoothedTrueRange
            : 0.0;
        $this->minusDi = $this->smoothedTrueRange > 0.0
            ? 100.0 * $this->smoothedMinusDm / $this->smoothedTrueRange
            : 0.0;

        $diSum = $this->plusDi + $this->minusDi;
        $dx = $diSum > 0.0
            ? 100.0 * abs($this->plusDi - $this->minusDi) / $diSum
            : 0.0;

        if ($this->adx === null) {
            $this->initialDx[] = $dx;

            if (count($this->initialDx) >= $this->period) {
                $this->adx = array_sum($this->initialDx) / $this->period;
                $this->initialDx = [];
            }
        } else {
            $this->adx = (($this->adx * ($this->period - 1)) + $dx) / $this->period;
        }

        return $this->values();
    }

    public function isReady(): bool
    {
        return $this->adx !== null;
    }

    public function period(): int
    {
        return $this->period;
    }

    public function value(): ?float
    {
        return $this->adx;
    }

    public function plusDi(): ?float
    {
        return $this->plusDi;
    }

    public function minusDi(): ?float
    {
        return $this->minusDi;
    }

    public function reset(): void
    {
        $this->previousHigh = null;
        $this->previousLow = null;
        $this->previousClose = null;
        $this->initialTrueRanges = [];
        $this->initialPlusDm = [];
        $this->initialMinusDm = [];
        $this->smoothedTrueRange = null;
        $this->smoothedPlusDm = null;
        $this->smoothedMinusDm = null;
        $this->initialDx = [];
        $this->adx = null;
        $this->plusDi = null;
        $this->minusDi = null;
    }

    public function exportState(): array
    {
        return [
            'period' => $this->period,
            'previous_high' => $this->previousHigh,
            'previous_low' => $this->previousLow,
            'previous_close' => $this->previousClose,
            'initial_true_ranges' => $this->initialTrueRanges,
            'initial_plus_dm' => $this->initialPlusDm,
            'initial_minus_dm' => $this->initialMinusDm,
            'smoothed_true_range' => $this->smoothedTrueRange,
            'smoothed_plus_dm' => $this->smoothedPlusDm,
            'smoothed_minus_dm' => $this->smoothedMinusDm,
            'initial_dx' => $this->initialDx,
            'adx' => $this->adx,
            'plus_di' => $this->plusDi,
            'minus_di' => $this->minusDi,
        ];
    }

    public function importState(array $state): void
    {
        if ((int) ($state['period'] ?? 0) !== $this->period) {
            throw new InvalidArgumentException('ADX checkpoint period does not match.');
        }

        $this->previousHigh = isset($state['previous_high']) ? (float) $state['previous_high'] : null;
        $this->previousLow = isset($state['previous_low']) ? (float) $state['previous_low'] : null;
        $this->previousClose = isset($state['previous_close']) ? (float) $state['previous_close'] : null;
        $this->initialTrueRanges = $state['initial_true_ranges'] ?? [];
        $this->initialPlusDm = $state['initial_plus_dm'] ?? [];
        $this->initialMinusDm = $state['initial_minus_dm'] ?? [];
        $this->smoothedTrueRange = isset($state['smoothed_true_range']) ? (float) $state['smoothed_true_range'] : null;
        $this->smoothedPlusDm = isset($state['smoothed_plus_dm']) ? (float) $state['smoothed_plus_dm'] : null;
        $this->smoothedMinusDm = isset($state['smoothed_minus_dm']) ? (float) $state['smoothed_minus_dm'] : null;
        $this->initialDx = $state['initial_dx'] ?? [];
        $this->adx = isset($state['adx']) ? (float) $state['adx'] : null;
        $this->plusDi = isset($state['plus_di']) ? (float) $state['plus_di'] : null;
        $this->minusDi = isset($state['minus_di']) ? (float) $state['minus_di'] : null;
    }

    private function values(): array
    {
        return [
            'adx' => $this->adx,
            'plus_di' => $this->plusDi,
            'minus_di' => $this->minusDi,
        ];
    }
}
