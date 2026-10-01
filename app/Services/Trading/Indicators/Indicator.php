<?php

namespace App\Services\Trading\Indicators;

interface Indicator
{
    public function update(float $value): ?float;

    public function isReady(): bool;

    public function reset(): void;
}
