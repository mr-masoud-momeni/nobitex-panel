<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\Strategy;
use App\Models\Trade;
use Illuminate\Http\Request;

class TradeController extends Controller
{
    public function index()
    {
        $trades = Trade::with('strategy')->orderByDesc('id')->get();

        return view('Backend.trades.index', compact('trades'));
    }

    public function create()
    {
        $strategies = Strategy::where('is_active', true)->orderBy('name')->get();

        return view('Backend.trades.create', compact('strategies'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'strategy_id' => ['required', 'exists:strategies,id'],
            'type' => ['required', 'in:backtest,paper,live'],
            'symbol' => ['required', 'string', 'max:30'],
            'timeframe' => ['required', 'string', 'max:20'],
            'initial_capital' => ['required', 'numeric', 'gt:0'],
            'fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        Trade::create(array_merge($data, [
            'status' => 'draft',
        ]));

        return redirect()
            ->route('trade.index')
            ->with('success', 'معامله با موفقیت ایجاد شد و آماده اجراست.');
    }

    public function show(Trade $trade)
    {
        $trade->load('strategy.rules');

        return view('Backend.trades.show', compact('trade'));
    }

    public function start(Trade $trade)
    {
        if ($trade->status === 'running') {
            return back();
        }

        if (in_array($trade->status, ['completed', 'stopped'])) {
            return back()->with('error', 'این معامله قبلاً اجرا شده است. برای اجرای جدید، معامله جدید ایجاد کنید.');
        }

        $trade->update([
            'status' => 'running',
            'started_at' => $trade->started_at ?: now(),
            'stopped_at' => null,
        ]);

        return back()->with('success', 'اجرای معامله شروع شد.');
    }

    public function stop(Trade $trade)
    {
        if ($trade->status !== 'running') {
            return back();
        }

        $trade->update([
            'status' => 'stopped',
            'stopped_at' => now(),
        ]);

        return back()->with('success', 'معامله متوقف شد و وضعیت آن ذخیره شد.');
    }
}
