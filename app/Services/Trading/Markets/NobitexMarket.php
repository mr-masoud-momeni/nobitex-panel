<?php

namespace App\Services\Trading\Markets;

use App\Models\Market;
use App\Models\MarketSymbol;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NobitexMarket
{
    private const STATS_URL = 'https://api.nobitex.ir/market/stats';

    public function syncSymbols(): int
    {
        $response = Http::timeout(15)->get(self::STATS_URL);

        if (!$response->successful()) {
            throw new RuntimeException('Nobitex market stats request failed with HTTP '.$response->status().'.');
        }

        $payload = $response->json();

        if (($payload['status'] ?? null) !== 'ok' || !isset($payload['stats']) || !is_array($payload['stats'])) {
            throw new RuntimeException('Invalid response received from Nobitex market stats API.');
        }

        $market = Market::updateOrCreate(
            ['slug' => 'nobitex'],
            [
                'name' => 'Nobitex',
                'driver' => 'nobitex',
                'is_active' => true,
            ]
        );

        $seen = [];

        foreach ($payload['stats'] as $key => $stats) {
            if ($key === 'global' || strpos($key, '-') === false) {
                continue;
            }

            [$base, $quote] = explode('-', $key, 2);

            if (!$base || !$quote) {
                continue;
            }

            $symbol = strtoupper($base.$quote);
            $displayName = strtoupper($base).'/'.strtoupper($quote);

            MarketSymbol::updateOrCreate(
                [
                    'market_id' => $market->id,
                    'symbol' => $symbol,
                ],
                [
                    'display_name' => $displayName,
                    'base_asset' => strtoupper($base),
                    'quote_asset' => strtoupper($quote),
                    'is_active' => !($stats['isClosed'] ?? false),
                ]
            );

            $seen[] = $symbol;
        }

        if ($seen) {
            MarketSymbol::where('market_id', $market->id)
                ->whereNotIn('symbol', $seen)
                ->update(['is_active' => false]);
        }

        return count($seen);
    }
}
