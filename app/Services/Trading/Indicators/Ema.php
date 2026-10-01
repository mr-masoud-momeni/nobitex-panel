<?php

namespace App\Services\Trading\Indicators;

use InvalidArgumentException;

class Ema implements Indicator
{
    private int $period;
    private float $multiplier;
    private array $seedValues = [];
    private ?float $value = null;

    public function __construct(int $period)
    {
        if ($period < 1) {
            throw new InvalidArgumentException('EMA period must be at least 1.');
        }

        $this->period = $period;
        $this->multiplier = 2 / ($period + 1);
    }

    public function update(float $value): ?float
    {
        if ($this->value === null && count($this->seedValues) < $this->period) {
            $this->seedValues[] = $value;

            if (count($this->seedValues) < $this->period) {
                return null;
            }

            $this->value = array_sum($this->seedValues) / $this->period;
            $this->seedValues = [];

            return $this->value;
        }

        $this->value = (($value - $this->value) * $this->multiplier) + $this->value;

        return $this->value;
    }

    public function isReady(): bool
    {
        return $this->value !== null;
    }

    public function reset(): void
    {
        $this->seedValues = [];
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
}
