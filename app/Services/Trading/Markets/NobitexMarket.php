<?php

namespace App\Services\Trading\Markets;

use App\Models\Market;
use App\Models\MarketCandle;
use App\Models\MarketSymbol;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NobitexMarket
{
    private const STATS_URL = 'https://apiv2.nobitex.ir/market/stats';
    private const HISTORY_URL = 'https://apiv2.nobitex.ir/market/udf/history';

    private const RESOLUTIONS = [
        '1m' => '1',
        '5m' => '5',
        '15m' => '15',
        '30m' => '30',
        '1h' => '60',
        '4h' => '240',
        '1d' => 'D',
    ];

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

    public function syncCandles(
        MarketSymbol $marketSymbol,
        string $timeframe,
        Carbon $start,
        Carbon $end,
        int $warmupCandles = 0
    ): int {
        if (!isset(self::RESOLUTIONS[$timeframe])) {
            throw new RuntimeException("Unsupported Nobitex timeframe: {$timeframe}.");
        }

        if ($start->greaterThan($end)) {
            throw new RuntimeException('Historical data start date must be before end date.');
        }

        $resolution = self::RESOLUTIONS[$timeframe];
        $from = $this->warmupStart($start, $timeframe, $warmupCandles)->timestamp;
        $to = $end->timestamp;
        $stored = 0;
        $page = 1;

        /*
         * Nobitex limits UDF history responses to 500 candles.
         * The API explicitly provides "page" for older chunks.
         * We keep the original from/to range fixed and walk pages
         * instead of moving "to" while also sending countback.
         */
        while (true) {
            $response = Http::timeout(30)
                ->acceptJson()
                ->get(self::HISTORY_URL, [
                    'symbol' => strtoupper($marketSymbol->symbol),
                    'resolution' => $resolution,
                    'from' => $from,
                    'to' => $to,
                    'page' => $page,
                ]);

            if (!$response->successful()) {
                throw new RuntimeException(
                    'Nobitex historical data request failed with HTTP '.$response->status().'.'
                );
            }

            $payload = $response->json();

            if (($payload['s'] ?? null) === 'no_data') {
                break;
            }

            if (($payload['s'] ?? null) !== 'ok') {
                throw new RuntimeException(
                    'Nobitex historical data request failed: '.($payload['errmsg'] ?? 'unknown error').'.'
                );
            }

            $timestamps = $payload['t'] ?? [];
            $opens = $payload['o'] ?? [];
            $highs = $payload['h'] ?? [];
            $lows = $payload['l'] ?? [];
            $closes = $payload['c'] ?? [];
            $volumes = $payload['v'] ?? [];

            $count = min(
                count($timestamps),
                count($opens),
                count($highs),
                count($lows),
                count($closes),
                count($volumes)
            );

            if ($count === 0) {
                break;
            }

            $rows = [];

            for ($i = 0; $i < $count; $i++) {
                $timestamp = (int) $timestamps[$i];

                if ($timestamp < $from || $timestamp > $to) {
                    continue;
                }

                $rows[] = [
                    'market_symbol_id' => $marketSymbol->id,
                    'timeframe' => $timeframe,
                    'timestamp' => $timestamp,
                    'open' => $opens[$i],
                    'high' => $highs[$i],
                    'low' => $lows[$i],
                    'close' => $closes[$i],
                    'volume' => $volumes[$i] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows) {
                MarketCandle::upsert(
                    $rows,
                    ['market_symbol_id', 'timeframe', 'timestamp'],
                    ['open', 'high', 'low', 'close', 'volume', 'updated_at']
                );

                $stored += count($rows);
            }

            /*
             * A full page means there may be another older page.
             * A partial page means we reached the beginning of the
             * requested range (or the available history).
             */
            if ($count < 500) {
                break;
            }

            $page++;
        }

        return $stored;
    }

    private function warmupStart(Carbon $start, string $timeframe, int $warmupCandles): Carbon
    {
        if ($warmupCandles <= 0) {
            return $start->copy();
        }

        $minutes = [
            '1m' => 1,
            '5m' => 5,
            '15m' => 15,
            '30m' => 30,
            '1h' => 60,
            '4h' => 240,
            '1d' => 1440,
        ][$timeframe] ?? null;

        if ($minutes === null) {
            throw new RuntimeException("Unsupported Nobitex timeframe: {$timeframe}.");
        }

        return $start->copy()->subMinutes($minutes * $warmupCandles);
    }
}
