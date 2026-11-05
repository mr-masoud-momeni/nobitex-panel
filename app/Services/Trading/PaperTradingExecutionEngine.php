<?php

namespace App\Services\Trading;

use App\Models\PaperTradingAccount;
use App\Models\PaperTradingSignal;
use App\Models\PaperTradingState;
use App\Models\PaperTradingTrade;
use Illuminate\Support\Facades\DB;

class PaperTradingExecutionEngine
{
    public const DEFAULT_INITIAL_CAPITAL = 10000;
    public const DEFAULT_FEE_PERCENT = 0.1;

    public function process(PaperTradingState $state): array
    {
        return DB::transaction(function () use ($state) {
            $account = PaperTradingAccount::firstOrCreate(
                ['paper_trading_state_id' => $state->id],
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

            $lastSignalId = (int) ($account->last_processed_signal_id ?? 0);
            $signals = PaperTradingSignal::where('paper_trading_state_id', $state->id)
                ->where('id', '>', $lastSignalId)
                ->orderBy('id')
                ->get();

            $processed = 0;
            $opened = 0;
            $closed = 0;
            $feeRate = ((float) $account->fee_percent) / 100;

            foreach ($signals as $signal) {
                if ($signal->signal === 'entry') {
                    $hasOpenTrade = PaperTradingTrade::where('paper_trading_account_id', $account->id)
                        ->where('status', 'open')
                        ->exists();

                    $cash = (float) $account->cash_balance;
                    $price = (float) $signal->close;

                    if (!$hasOpenTrade && $cash > 0 && $price > 0) {
                        // Reserve enough cash for both the buy notional and its fee.
                        $quantity = round($cash / ($price * (1 + $feeRate)), 10);
                        $entryNotional = round($quantity * $price, 10);
                        $entryFee = round($entryNotional * $feeRate, 10);

                        if ($quantity > 0 && ($entryNotional + $entryFee) <= $cash) {
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

                            $account->cash_balance = round($cash - $entryNotional - $entryFee, 10);
                            $opened++;
                        }
                    }
                } elseif ($signal->signal === 'exit') {
                    $openTrade = PaperTradingTrade::where('paper_trading_account_id', $account->id)
                        ->where('status', 'open')
                        ->lockForUpdate()
                        ->first();

                    if ($openTrade) {
                        $exitPrice = (float) $signal->close;
                        $exitNotional = round((float) $openTrade->quantity * $exitPrice, 10);
                        $exitFee = round($exitNotional * $feeRate, 10);
                        $grossPnl = round($exitNotional - (float) $openTrade->entry_notional, 10);
                        $netPnl = round(
                            $grossPnl - (float) $openTrade->entry_fee - $exitFee,
                            10
                        );

                        $openTrade->update([
                            'exit_signal_id' => $signal->id,
                            'status' => 'closed',
                            'exit_price' => $exitPrice,
                            'exit_notional' => $exitNotional,
                            'exit_fee' => $exitFee,
                            'gross_pnl' => $grossPnl,
                            'net_pnl' => $netPnl,
                            'exit_timestamp' => $signal->candle_timestamp,
                        ]);

                        $account->cash_balance = round(
                            (float) $account->cash_balance + $exitNotional - $exitFee,
                            10
                        );
                        $account->realized_pnl = round((float) $account->realized_pnl + $netPnl, 10);
                        $closed++;
                    }
                }

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
        });
    }
}
