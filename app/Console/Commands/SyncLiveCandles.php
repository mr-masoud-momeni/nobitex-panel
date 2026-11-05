<?php

namespace App\Console\Commands;

use App\Models\MarketCandle;
use App\Models\MarketSymbol;
use App\Services\Trading\Markets\NobitexMarket;
use Carbon\Carbon;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class SyncLiveCandles extends Command
{
    protected $signature = 'market:sync-live-candles
                            {--symbol=BTCUSDT : Market symbol to synchronize}
                            {--timeframe=15m : Candle timeframe}
                            {--lookback=45 : Number of minutes to look back}
                            {--warmup=200 : Initial historical candles required by live indicators}';

    protected $description = 'Synchronize the latest Nobitex candles using the public REST API.';

    public function handle(NobitexMarket $nobitexMarket)
    {
        $symbol = strtoupper($this->option('symbol'));
        $timeframe = $this->option('timeframe');
        $warmup = (int) $this->option('warmup');

        if ($warmup < 0) {
            $this->error('Warmup cannot be negative.');
            return 1;
        }

        $timeframeMinutes = [
            '1m' => 1,
            '5m' => 5,
            '15m' => 15,
            '30m' => 30,
            '1h' => 60,
            '4h' => 240,
            '1d' => 1440,
        ][$timeframe] ?? null;

        if ($timeframeMinutes === null) {
            $this->error("Unsupported timeframe: {$timeframe}.");
            return 1;
        }

        $marketSymbol = MarketSymbol::where('symbol', $symbol)
            ->whereHas('market', function ($query) {
                $query->where('driver', 'nobitex');
            })
            ->first();

        if (!$marketSymbol) {
            $this->error("Nobitex symbol {$symbol} was not found. Run market:sync-symbols first.");
            return 1;
        }

        try {
            $end = Carbon::now();

            // Resume from the newest candle already stored. One-candle overlap
            // refreshes the most recent bar and makes retries safe.
            $latestStoredTimestamp = MarketCandle::where('market_symbol_id', $marketSymbol->id)
                ->where('timeframe', $timeframe)
                ->max('timestamp');

            if ($latestStoredTimestamp !== null) {
                $start = Carbon::createFromTimestamp((int) $latestStoredTimestamp)
                    ->subSeconds($timeframeMinutes * 60);
            } else {
                // First run: fetch enough history to seed indicators, plus a few
                // extra bars so the signal engine has a closed candle to initialize on.
                $start = $end->copy()->subMinutes($timeframeMinutes * ($warmup + 5));
            }

            $count = $nobitexMarket->syncCandles(
                $marketSymbol,
                $timeframe,
                $start,
                $end,
                0
            );
        } catch (Throwable $e) {
            report($e);
            $this->error('Candle synchronization failed: '.$e->getMessage());
            return 1;
        }

        $this->info("Synchronized {$count} candle row(s) for {$symbol} ({$timeframe}).");

        return 0;
    }
}
