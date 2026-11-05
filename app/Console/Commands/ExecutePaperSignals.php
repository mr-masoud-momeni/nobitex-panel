<?php

namespace App\Console\Commands;

use App\Models\PaperTradingState;
use App\Services\Trading\PaperTradingExecutionEngine;
use Illuminate\Console\Command;
use Throwable;

class ExecutePaperSignals extends Command
{
    protected $signature = 'trading:execute-paper-signals
                            {--symbol=BTCUSDT : Market symbol to execute}
                            {--timeframe=15m : Signal timeframe}
                            {--strategy=ema20_ema50_long_v1 : Signal strategy key}';

    protected $description = 'Execute persisted paper-trading signals against the virtual account.';

    public function handle(PaperTradingExecutionEngine $engine): int
    {
        $symbol = strtoupper($this->option('symbol'));
        $timeframe = $this->option('timeframe');
        $strategy = $this->option('strategy');

        $state = PaperTradingState::where('timeframe', $timeframe)
            ->where('strategy_key', $strategy)
            ->whereHas('marketSymbol', function ($query) use ($symbol) {
                $query->where('symbol', $symbol);
            })
            ->with('marketSymbol')
            ->first();

        if (!$state) {
            $this->info('Paper signal state has not been initialized yet.');
            return self::SUCCESS;
        }

        try {
            $result = $engine->process($state);
        } catch (Throwable $e) {
            report($e);
            $this->error('Paper execution failed: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info(
            'Processed '.$result['processed'].' signal(s); '
            .$result['opened'].' trade(s) opened; '
            .$result['closed'].' trade(s) closed. '
            .'Cash balance: '.$result['cash_balance'].' USDT.'
        );

        return self::SUCCESS;
    }
}
