<?php

namespace App\Services\Trading\Indicators;

use InvalidArgumentException;

class Macd
{
    private Ema $fastEma;
    private Ema $slowEma;
    private Ema $signalEma;
    private ?float $macd = null;
    private ?float $signal = null;
    private ?float $histogram = null;

    public function __construct(int $fast = 12, int $slow = 26, int $signal = 9)
    {
        if ($fast < 1 || $slow < 1 || $signal < 1) {
            throw new InvalidArgumentException('MACD periods must be at least 1.');
        }

        if ($fast >= $slow) {
            throw new InvalidArgumentException('MACD fast period must be smaller than slow period.');
        }

        $this->fastEma = new Ema($fast);
        $this->slowEma = new Ema($slow);
        $this->signalEma = new Ema($signal);
    }

    public function update(float $value): array
    {
        $fast = $this->fastEma->update($value);
        $slow = $this->slowEma->update($value);

        if ($fast === null || $slow === null) {
            return [
                'macd' => null,
                'signal' => null,
                'histogram' => null,
            ];
        }

        $this->macd = $fast - $slow;
        $this->signal = $this->signalEma->update($this->macd);

        if ($this->signal !== null) {
            $this->histogram = $this->macd - $this->signal;
        }

        return [
            'macd' => $this->macd,
            'signal' => $this->signal,
            'histogram' => $this->histogram,
        ];
    }

    public function isReady(): bool
    {
        return $this->macd !== null && $this->signal !== null;
    }

    public function reset(): void
    {
        $this->fastEma->reset();
        $this->slowEma->reset();
        $this->signalEma->reset();
        $this->macd = null;
        $this->signal = null;
        $this->histogram = null;
    }

    public function fastPeriod(): int
    {
        return $this->fastEma->period();
    }

    public function slowPeriod(): int
    {
        return $this->slowEma->period();
    }

    public function signalPeriod(): int
    {
        return $this->signalEma->period();
    }

    public function macd(): ?float
    {
        return $this->macd;
    }

    public function signal(): ?float
    {
        return $this->signal;
    }

    public function histogram(): ?float
    {
        return $this->histogram;
    }
}
