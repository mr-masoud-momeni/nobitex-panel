<?php

namespace App\Services\Trading\Indicators;

use App\Models\Strategy;

class IndicatorWarmup
{
    public function candlesFor(Strategy $strategy, ?int $override = null): int
    {
        if ($override !== null) {
            return max(1, $override);
        }

        $maxPeriod = 1;
        $maxLookback = 1;

        foreach ($strategy->rules as $rule) {
            $maxPeriod = max($maxPeriod, $this->maxPeriod($rule->parameters));
            $maxLookback = max($maxLookback, $this->lookback($rule->parameters));

            if ($rule->value_type === 'indicator' && is_array($rule->value)) {
                $targetParameters = $rule->value['parameters'] ?? [];
                $maxPeriod = max($maxPeriod, $this->maxPeriod($targetParameters));
                $maxLookback = max($maxLookback, $this->lookback($targetParameters));
            }
        }

        return max(1000, ($maxPeriod * 50) + $maxLookback);
    }

    private function lookback($parameters): int
    {
        if (!is_array($parameters) || !isset($parameters['lookback']) || !is_numeric($parameters['lookback'])) {
            return 1;
        }

        return max(1, (int) $parameters['lookback']);
    }

    private function maxPeriod($parameters): int
    {
        if (!is_array($parameters)) {
            return 1;
        }

        $max = 1;

        foreach (['period', 'fast', 'slow', 'signal'] as $key) {
            if (isset($parameters[$key]) && is_numeric($parameters[$key])) {
                $max = max($max, (int) $parameters[$key]);
            }
        }

        return $max;
    }
}
