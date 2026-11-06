<?php

namespace App\Services\Trading\Indicators;

use InvalidArgumentException;

class Sma implements Indicator
{
    private int $period;
    private array $values = [];
    private ?float $value = null;

    public function __construct(int $period)
    {
        if ($period < 1) {
            throw new InvalidArgumentException('SMA period must be at least 1.');
        }

        $this->period = $period;
    }

    public function update(float $value): ?float
    {
        $this->values[] = $value;

        if (count($this->values) > $this->period) {
            array_shift($this->values);
        }

        if (count($this->values) < $this->period) {
            return null;
        }

        $this->value = array_sum($this->values) / $this->period;

        return $this->value;
    }

    public function isReady(): bool
    {
        return $this->value !== null;
    }

    public function reset(): void
    {
        $this->values = [];
        $this->value = null;
    }

    public function exportState(): array
    {
        return [
            'period' => $this->period,
            'values' => $this->values,
            'value' => $this->value,
        ];
    }

    public function importState(array $state): void
    {
        if ((int) ($state['period'] ?? 0) !== $this->period) {
            throw new InvalidArgumentException('SMA checkpoint period does not match.');
        }

        $this->values = array_map('floatval', $state['values'] ?? []);
        $this->value = isset($state['value']) ? (float) $state['value'] : null;
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
