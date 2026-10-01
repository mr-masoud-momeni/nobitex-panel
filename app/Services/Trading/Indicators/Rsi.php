<?php

namespace App\Services\Trading\Indicators;

use InvalidArgumentException;

class Rsi implements Indicator
{
    private int $period;
    private array $changes = [];
    private ?float $previousValue = null;
    private ?float $averageGain = null;
    private ?float $averageLoss = null;
    private ?float $value = null;

    public function __construct(int $period)
    {
        if ($period < 1) {
            throw new InvalidArgumentException('RSI period must be at least 1.');
        }

        $this->period = $period;
    }

    public function update(float $value): ?float
    {
        if ($this->previousValue === null) {
            $this->previousValue = $value;

            return null;
        }

        $change = $value - $this->previousValue;
        $gain = max($change, 0.0);
        $loss = max(-$change, 0.0);

        $this->previousValue = $value;

        if ($this->averageGain === null || $this->averageLoss === null) {
            $this->changes[] = [$gain, $loss];

            if (count($this->changes) < $this->period) {
                return null;
            }

            $this->averageGain = array_sum(array_column($this->changes, 0)) / $this->period;
            $this->averageLoss = array_sum(array_column($this->changes, 1)) / $this->period;
            $this->changes = [];
        } else {
            $this->averageGain = (($this->averageGain * ($this->period - 1)) + $gain) / $this->period;
            $this->averageLoss = (($this->averageLoss * ($this->period - 1)) + $loss) / $this->period;
        }

        $this->value = $this->calculateRsi();

        return $this->value;
    }

    public function isReady(): bool
    {
        return $this->value !== null;
    }

    public function reset(): void
    {
        $this->changes = [];
        $this->previousValue = null;
        $this->averageGain = null;
        $this->averageLoss = null;
        $this->value = null;
    }

    public function period(): int
    {
        return $this->period;
    }

    public function value(): ?float
    {
        return $this->value;
    }

    private function calculateRsi(): float
    {
        if ($this->averageLoss == 0.0) {
            return 100.0;
        }

        if ($this->averageGain == 0.0) {
            return 0.0;
        }

        $rs = $this->averageGain / $this->averageLoss;

        return 100.0 - (100.0 / (1.0 + $rs));
    }
}
