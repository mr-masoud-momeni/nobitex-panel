<?php

namespace App\Services\Trading\Indicators;

use App\Models\Strategy;

class IndicatorWarmup
{
    public function candlesFor(Strategy $strategy): int
    {
        $maxPeriod = 1;

        foreach ($strategy->rules as $rule) {
            $maxPeriod = max($maxPeriod, $this->maxPeriod($rule->parameters));

            if ($rule->value_type === 'indicator' && is_array($rule->value)) {
                $maxPeriod = max($maxPeriod, $this->maxPeriod($rule->value['parameters'] ?? []));
            }
        }

        return max(1000, $maxPeriod * 50);
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
