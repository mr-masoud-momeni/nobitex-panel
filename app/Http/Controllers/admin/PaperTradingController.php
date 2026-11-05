<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\MarketCandle;
use App\Models\MarketSymbol;
use App\Models\PaperTradingAccount;
use App\Models\PaperTradingState;
use App\Services\Trading\LiveEmaSignalEngine;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaperTradingController extends Controller
{
    public function index()
    {
        $marketSymbol = MarketSymbol::where('symbol', 'BTCUSDT')
            ->whereHas('market', function ($query) {
                $query->where('driver', 'nobitex');
            })
            ->first();

        $state = $marketSymbol ? PaperTradingState::firstOrCreate(
            [
                'market_symbol_id' => $marketSymbol->id,
                'timeframe' => '15m',
                'strategy_key' => LiveEmaSignalEngine::STRATEGY_KEY,
            ],
            ['position_status' => 'flat']
        ) : null;

        $account = $state ? PaperTradingAccount::firstOrCreate(
            ['paper_trading_state_id' => $state->id],
            [
                'initial_capital' => 10000,
                'cash_balance' => 10000,
                'fee_percent' => 0.1,
                'realized_pnl' => 0,
            ]
        ) : null;

        if ($account) {
            $account->load(['openTrade', 'trades' => function ($query) {
                $query->where('status', 'closed')->orderByDesc('exit_timestamp')->limit(50);
            }]);
        }

        $latestPrice = $marketSymbol
            ? MarketCandle::where('market_symbol_id', $marketSymbol->id)
                ->where('timeframe', '15m')
                ->orderByDesc('timestamp')
                ->value('close')
            : null;

        $openTrade = $account ? $account->openTrade : null;
        $unrealizedPnl = null;
        $estimatedEquity = $account ? (float) $account->cash_balance : 0;

        if ($openTrade && $latestPrice) {
            $markNotional = (float) $openTrade->quantity * (float) $latestPrice;
            $estimatedExitFee = $markNotional * ((float) $account->fee_percent / 100);
            $unrealizedPnl = $markNotional - (float) $openTrade->entry_notional
                - (float) $openTrade->entry_fee - $estimatedExitFee;
            $estimatedEquity += $markNotional - $estimatedExitFee;
        }

        return view('Backend.paper-trading.index', [
            'state' => $state,
            'account' => $account,
            'openTrade' => $openTrade,
            'history' => $account ? $account->trades : collect(),
            'latestPrice' => $latestPrice,
            'unrealizedPnl' => $unrealizedPnl,
            'estimatedEquity' => $estimatedEquity,
        ]);
    }

    public function updateSettings(Request $request, PaperTradingAccount $account)
    {
        $hasTrades = $account->trades()->exists();

        if ($hasTrades || $account->openTrade()->exists()) {
            throw ValidationException::withMessages([
                'settings' => 'پس از ثبت اولین معامله، تنظیمات سرمایه و کارمزد قفل می‌شوند تا گزارش‌ها قابل اتکا بمانند.',
            ]);
        }

        $data = $request->validate([
            'initial_capital' => ['required', 'numeric', 'gt:0', 'max:1000000000000'],
            'fee_percent' => ['required', 'numeric', 'min:0', 'max:5'],
        ]);

        $account->update([
            'initial_capital' => $data['initial_capital'],
            'cash_balance' => $data['initial_capital'],
            'fee_percent' => $data['fee_percent'],
        ]);

        return redirect()->route('paper-trading.index')
            ->with('success', 'تنظیمات حساب آزمایشی ذخیره شد.');
    }
}
