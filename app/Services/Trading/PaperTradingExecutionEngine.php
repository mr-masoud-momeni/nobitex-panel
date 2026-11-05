<?php

namespace App\Services\Trading;

use App\Models\PaperTradingAccount;
use App\Models\PaperTradingSignal;
use App\Models\PaperTradingState;
use App\Models\PaperTradingTrade;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaperTradingExecutionEngine
{
    public const DEFAULT_INITIAL_CAPITAL = 10000;
    public const DEFAULT_FEE_PERCENT = 0.1;

    /**
     * Execute every signal not yet consumed by this virtual account.
     * Balance changes, trade writes and cursor advancement are atomic.
     */
    public function process(PaperTradingState $state): array
    {
        return DB::transaction(function () use ($state) {
            // Serialize account initialization and execution for this strategy state.
            $lockedState = PaperTradingState::whereKey($state->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedState->loadMissing('marketSymbol');

            $account = PaperTradingAccount::firstOrCreate(
                ['paper_trading_state_id' => $lockedState->id],
                [
                    'initial_capital' => self::DEFAULT_INITIAL_CAPITAL,
                    'cash_balance' => self::DEFAULT_INITIAL_CAPITAL,
                    'fee_percent' => self::DEFAULT_FEE_PERCENT,
                    'realized_pnl' => 0,
                ]
            );

            $account = PaperTradingAccount::whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();

            $feePercent = (float) $account->fee_percent;
            if ($feePercent < 0 || $feePercent >= 100) {
                throw new RuntimeException('Paper trading fee_percent must be between 0 and 100.');
            }

            $feeRate = $feePercent / 100;
            $lastSignalId = (int) ($account->last_processed_signal_id ?? 0);

            $signals = PaperTradingSignal::where('paper_trading_state_id', $lockedState->id)
                ->where('id', '>', $lastSignalId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $processed = 0;
            $opened = 0;
            $closed = 0;

            foreach ($signals as $signal) {
                if ($signal->signal === 'entry') {
                    if ($this->openTrade($account, $lockedState, $signal, $feeRate)) {
                        $opened++;
                    }
                } elseif ($signal->signal === 'exit') {
                    if ($this->closeTrade($account, $signal, $feeRate)) {
                        $closed++;
                    }
                }

                // The signal is consumed even if it is not actionable (e.g. duplicate entry).
                $account->last_processed_signal_id = $signal->id;
                $processed++;
            }

            $account->save();

            return [
                'status' => 'processed',
                'processed' => $processed,
                'opened' => $opened,
                'closed' => $closed,
                'cash_balance' => (float) $account->cash_balance,
                'realized_pnl' => (float) $account->realized_pnl,
            ];
        }, 3);
    }

    private function openTrade(
        PaperTradingAccount $account,
        PaperTradingState $state,
        PaperTradingSignal $signal,
        float $feeRate
    ): bool {
        $openTrade = PaperTradingTrade::where('paper_trading_account_id', $account->id)
            ->where('status', 'open')
            ->lockForUpdate()
            ->first();

        if ($openTrade) {
            return false;
        }

        $price = (float) $signal->close;
        $cash = (float) $account->cash_balance;

        if ($price <= 0 || $cash <= 0) {
            return false;
        }

        // Use all available cash while reserving enough for the entry fee.
        $quantity = round($cash / ($price * (1 + $feeRate)), 10);
        $entryNotional = round($quantity * $price, 10);
        $entryFee = round($entryNotional * $feeRate, 10);
        $totalCost = round($entryNotional + $entryFee, 10);

        if ($quantity <= 0 || $totalCost > $cash) {
            return false;
        }

        PaperTradingTrade::create([
            'paper_trading_account_id' => $account->id,
            'entry_signal_id' => $signal->id,
            'symbol' => $state->marketSymbol->symbol,
            'timeframe' => $state->timeframe,
            'status' => 'open',
            'quantity' => $quantity,
            'entry_price' => $price,
            'entry_notional' => $entryNotional,
            'entry_fee' => $entryFee,
            'entry_timestamp' => $signal->candle_timestamp,
        ]);

        $account->cash_balance = round($cash - $totalCost, 10);

        return true;
    }

    private function closeTrade(
        PaperTradingAccount $account,
        PaperTradingSignal $signal,
        float $feeRate
    ): bool {
        $trade = PaperTradingTrade::where('paper_trading_account_id', $account->id)
            ->where('status', 'open')
            ->lockForUpdate()
            ->first();

        if (!$trade) {
            return false;
        }

        $exitPrice = (float) $signal->close;
        $quantity = (float) $trade->quantity;

        if ($exitPrice <= 0 || $quantity <= 0) {
            return false;
        }

        $exitNotional = round($quantity * $exitPrice, 10);
        $exitFee = round($exitNotional * $feeRate, 10);
        $grossPnl = round($exitNotional - (float) $trade->entry_notional, 10);
        $netPnl = round($grossPnl - (float) $trade->entry_fee - $exitFee, 10);
        $cashReturned = round($exitNotional - $exitFee, 10);

        $trade->update([
            'exit_signal_id' => $signal->id,
            'status' => 'closed',
            'exit_price' => $exitPrice,
            'exit_notional' => $exitNotional,
            'exit_fee' => $exitFee,
            'gross_pnl' => $grossPnl,
            'net_pnl' => $netPnl,
            'exit_timestamp' => $signal->candle_timestamp,
        ]);

        $account->cash_balance = round((float) $account->cash_balance + $cashReturned, 10);
        $account->realized_pnl = round((float) $account->realized_pnl + $netPnl, 10);

        return true;
    }
}
