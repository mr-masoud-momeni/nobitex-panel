<?php

namespace App\Console\Commands;

use App\Models\MarketCandle;
use App\Models\MarketSymbol;
use App\Services\Trading\Indicators\Ema;
use App\Services\Trading\Indicators\Rsi;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckIndicators extends Command
{
    protected $signature = 'trading:check-indicators
                            {symbol : Market symbol, e.g. BTCUSDT}
                            {timeframe : Candle timeframe, e.g. 1h}
                            {date : Target candle date/time}
                            {--ema=20 : EMA period}
                            {--rsi=14 : RSI period}';

    protected $description = 'Calculate EMA and RSI from cached candles at a specific historical candle.';

    public function handle(): int
    {
        $symbol = MarketSymbol::where('symbol', strtoupper($this->argument('symbol')))->first();

        if (!$symbol) {
            $this->error('Market symbol not found.');

            return self::FAILURE;
        }

        $target = Carbon::parse($this->argument('date'));
        $emaPeriod = (int) $this->option('ema');
        $rsiPeriod = (int) $this->option('rsi');

        if ($emaPeriod < 1 || $rsiPeriod < 1) {
            $this->error('EMA and RSI periods must be at least 1.');

            return self::FAILURE;
        }

        $candles = MarketCandle::where('market_symbol_id', $symbol->id)
            ->where('timeframe', $this->argument('timeframe'))
            ->where('timestamp', '<=', $target->timestamp)
            ->orderBy('timestamp')
            ->get(['timestamp', 'close']);

        if ($candles->isEmpty()) {
            $this->error('No cached candles were found for this symbol/timeframe.');

            return self::FAILURE;
        }

        $ema = new Ema($emaPeriod);
        $rsi = new Rsi($rsiPeriod);
        $targetCandle = null;
        $emaValue = null;
        $rsiValue = null;

        foreach ($candles as $candle) {
            $emaValue = $ema->update((float) $candle->close);
            $rsiValue = $rsi->update((float) $candle->close);

            if ((int) $candle->timestamp === (int) $target->timestamp) {
                $targetCandle = $candle;
                break;
            }
        }

        if (!$targetCandle) {
            $this->error('The exact target candle was not found in the cache.');

            return self::FAILURE;
        }

        $this->table(
            ['Field', 'Value'],
            [
                ['Symbol', $symbol->display_name],
                ['Timeframe', $this->argument('timeframe')],
                ['Candle', Carbon::createFromTimestamp((int) $targetCandle->timestamp)->toDateTimeString().' UTC'],
                ['Close', $targetCandle->close],
                ['EMA'.$emaPeriod, $emaValue === null ? 'not ready' : sprintf('%.12f', $emaValue)],
                ['RSI'.$rsiPeriod, $rsiValue === null ? 'not ready' : sprintf('%.12f', $rsiValue)],
            ]
        );

        return self::SUCCESS;
    }
}
