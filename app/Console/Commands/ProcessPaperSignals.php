<?php

namespace App\Console\Commands;

use App\Models\MarketSymbol;
use App\Services\Trading\LiveEmaSignalEngine;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class ProcessPaperSignals extends Command
{
    protected $signature = 'trading:process-paper-signals
                            {--symbol=BTCUSDT : Market symbol to process}
                            {--timeframe=15m : Candle timeframe}
                            {--warmup=200 : Number of historical candles used to warm up indicators}';

    protected $description = 'Process closed candles and persist long-only EMA20/EMA50 paper signals.';

    public function handle(LiveEmaSignalEngine $engine): int
    {
        $symbol = strtoupper($this->option('symbol'));
        $timeframe = $this->option('timeframe');
        $warmup = (int) $this->option('warmup');

        if ($warmup < 50) {
            $this->error('The warmup option must be at least 50.');
            return self::FAILURE;
        }

        $marketSymbol = MarketSymbol::where('symbol', $symbol)
            ->whereHas('market', function ($query) {
                $query->where('driver', 'nobitex');
            })
            ->first();

        if (!$marketSymbol) {
            $this->error("Nobitex symbol {$symbol} was not found. Run market:sync-symbols first.");
            return self::FAILURE;
        }

        try {
            $result = $engine->process($marketSymbol, $timeframe, $warmup);
        } catch (Throwable $e) {
            report($e);
            $this->error('Paper signal processing failed: '.$e->getMessage());
            return self::FAILURE;
        }

        if ($result['status'] === 'waiting_for_warmup') {
            $this->info(
                'Waiting for historical candles: '
                .$result['available_warmup'].'/'.$result['required_warmup'].'.'
            );

            return self::SUCCESS;
        }

        if ($result['status'] === 'initialized') {
            $this->info('Signal engine initialized. It will evaluate the next closed candle.');
            return self::SUCCESS;
        }

        if ($result['status'] === 'no_closed_candles') {
            $this->info('No closed candles are available yet.');
            return self::SUCCESS;
        }

        $this->info(
            'Processed '.$result['processed'].' closed candle(s). '
            .count($result['created_signals']).' new signal(s).'
        );

        foreach ($result['created_signals'] as $signal) {
            $this->line(
                strtoupper($signal['signal'])
                .' | '.Carbon::createFromTimestamp($signal['timestamp'])->toDateTimeString()
                .' | close='.$signal['close']
                .' | EMA20='.$signal['ema_fast']
                .' | EMA50='.$signal['ema_slow']
            );
        }

        return self::SUCCESS;
    }
}
