<?php

namespace App\Console\Commands;

use App\Services\Trading\Markets\NobitexMarket;
use Illuminate\Console\Command;
use Throwable;

class SyncMarketSymbols extends Command
{
    protected $signature = 'market:sync-symbols {--market=nobitex : Market driver to sync}';

    protected $description = 'Sync public market symbols from the configured market provider.';

    public function handle(NobitexMarket $nobitexMarket)
    {
        if ($this->option('market') !== 'nobitex') {
            $this->error('Only the Nobitex market provider is available right now.');
            return 1;
        }

        try {
            $count = $nobitexMarket->syncSymbols();
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $this->info("Nobitex symbols synced successfully: {$count}");
        return 0;
    }
}
