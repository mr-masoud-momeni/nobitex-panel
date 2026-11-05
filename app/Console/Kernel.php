<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Refresh the recent candle window and recover short interruptions.
        $schedule->command('market:sync-live-candles --symbol=BTCUSDT --timeframe=15m --lookback=45 --warmup=200')
            ->everyMinute()
            ->withoutOverlapping();

        // Evaluate signals only after their 15-minute candles have closed.
        $schedule->command('trading:process-paper-signals --symbol=BTCUSDT --timeframe=15m --warmup=200')
            ->everyMinute()
            ->withoutOverlapping();

        // Execute persisted signals after the signal engine has finished.
        $schedule->command('trading:execute-paper-signals --symbol=BTCUSDT --timeframe=15m')
            ->everyMinute()
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
