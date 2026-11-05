<?php

namespace App\Console\Commands;

use App\Models\MarketSymbol;
use App\Services\Trading\Markets\NobitexMarket;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class SyncLiveCandles extends Command
{
    protected $signature = 'market:sync-live-candles
                            {--symbol=BTCUSDT : Market symbol to synchronize}
                            {--timeframe=15m : Candle timeframe}
                            {--lookback=45 : Number of minutes to look back}';

    protected $description = 'Synchronize the latest Nobitex candles using the public REST API.';

    public function handle(NobitexMarket $nobitexMarket)
    {
        $symbol = strtoupper($this->option('symbol'));
        $timeframe = $this->option('timeframe');
        $lookback = (int) $this->option('lookback');

        if ($lookback < 1) {
            $this->error('The lookback option must be greater than zero.');
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
            $start = $end->copy()->subMinutes($lookback);

            $count = $nobitexMarket->syncCandles(
                $marketSymbol,
                $timeframe,
                $start,
                $end
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
