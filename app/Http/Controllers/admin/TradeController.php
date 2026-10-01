<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\MarketSymbol;
use App\Models\Strategy;
use App\Models\Trade;
use App\Services\Trading\Markets\NobitexMarket;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class TradeController extends Controller
{
    public function index()
    {
        $trades = Trade::with(['strategy', 'market', 'marketSymbol'])->orderByDesc('id')->get();

        return view('Backend.trades.index', compact('trades'));
    }

    public function create()
    {
        return $this->formView(null);
    }

    public function edit(Trade $trade)
    {
        if ($trade->status !== 'draft') {
            return redirect()
                ->route('trade.show', $trade)
                ->with('error', 'فقط معامله‌ای که هنوز اجرا نشده قابل ویرایش است.');
        }

        return $this->formView($trade);
    }

    private function formView($trade)
    {
        $strategies = Strategy::where(function ($query) use ($trade) {
            $query->where('is_active', true);

            if ($trade) {
                $query->orWhere('id', $trade->strategy_id);
            }
        })->orderBy('name')->get();

        $markets = Market::where(function ($query) use ($trade) {
            $query->where('is_active', true);

            if ($trade) {
                $query->orWhere('id', $trade->market_id);
            }
        })
            ->with(['symbols' => function ($query) use ($trade) {
                $query->where(function ($q) use ($trade) {
                    $q->where('is_active', true);

                    if ($trade) {
                        $q->orWhere('id', $trade->market_symbol_id);
                    }
                })->orderBy('display_name');
            }])
            ->orderBy('name')
            ->get();

        return view('Backend.trades.create', compact('strategies', 'markets', 'trade'));
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);

        $symbol = MarketSymbol::where('id', $data['market_symbol_id'])
            ->where('market_id', $data['market_id'])
            ->where('is_active', true)
            ->value('symbol');

        Trade::create(array_merge($data, [
            'symbol' => $symbol,
            'status' => 'draft',
        ]));

        return redirect()
            ->route('trade.index')
            ->with('success', 'معامله با موفقیت ایجاد شد و آماده اجراست.');
    }

    public function update(Request $request, Trade $trade)
    {
        if ($trade->status !== 'draft') {
            return back()->with('error', 'معامله پس از اجرا قابل ویرایش نیست.');
        }

        $data = $this->validatedData($request, $trade);

        $symbol = MarketSymbol::where('id', $data['market_symbol_id'])
            ->where('market_id', $data['market_id'])
            ->value('symbol');

        $trade->update(array_merge($data, [
            'symbol' => $symbol,
        ]));

        return redirect()
            ->route('trade.index')
            ->with('success', 'معامله با موفقیت ویرایش شد.');
    }

    public function duplicate(Trade $trade)
    {
        $copy = DB::transaction(function () use ($trade) {
            $copy = $trade->replicate([
                'status',
                'result_amount',
                'result_percent',
                'total_trades',
                'winning_trades',
                'losing_trades',
                'started_at',
                'stopped_at',
                'completed_at',
            ]);

            $copy->status = 'draft';
            $copy->result_amount = null;
            $copy->result_percent = null;
            $copy->total_trades = null;
            $copy->winning_trades = null;
            $copy->losing_trades = null;
            $copy->started_at = null;
            $copy->stopped_at = null;
            $copy->completed_at = null;
            $copy->save();

            return $copy;
        });

        return redirect()
            ->route('trade.edit', $copy)
            ->with('success', 'یک نسخه جدید از معامله ساخته شد. حالا می‌توانید تنظیمات آن را تغییر دهید.');
    }

    private function validatedData(Request $request, ?Trade $trade = null)
    {
        return $request->validate([
            'strategy_id' => ['required', 'exists:strategies,id'],
            'market_id' => ['required', 'exists:markets,id'],
            'market_symbol_id' => [
                'required',
                Rule::exists('market_symbols', 'id')->where(function ($query) use ($request, $trade) {
                    return $query
                        ->where('market_id', $request->input('market_id'))
                        ->where(function ($q) use ($trade) {
                            $q->where('is_active', true);

                            if ($trade) {
                                $q->orWhere('id', $trade->market_symbol_id);
                            }
                        });
                }),
            ],
            'type' => ['required', 'in:backtest,paper,live'],
            'timeframe' => ['required', 'string', 'max:20'],
            'initial_capital' => ['required', 'numeric', 'gt:0'],
            'fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
    }

    public function destroy(Trade $trade)
    {
        if ($trade->status === 'running') {
            return back()->with('error', 'معامله در حال اجرا را نمی‌توان حذف کرد. ابتدا آن را متوقف کنید.');
        }

        $trade->delete();

        return redirect()
            ->route('trade.index')
            ->with('success', 'معامله با موفقیت حذف شد.');
    }

    public function show(Trade $trade)
    {
        $trade->load(['strategy.rules', 'market', 'marketSymbol']);

        return view('Backend.trades.show', compact('trade'));
    }

    public function start(Trade $trade, NobitexMarket $nobitexMarket)
    {
        if ($trade->status === 'running') {
            return back();
        }

        if (in_array($trade->status, ['completed', 'stopped'])) {
            return back()->with('error', 'این معامله قبلاً اجرا شده است. برای اجرای جدید، معامله جدید ایجاد کنید.');
        }

        if ($trade->type === 'backtest') {
            if (!$trade->start_date || !$trade->end_date) {
                return back()->with('error', 'برای بک‌تست باید تاریخ شروع و پایان مشخص شده باشد.');
            }

            $trade->update([
                'status' => 'running',
                'started_at' => $trade->started_at ?: now(),
                'stopped_at' => null,
            ]);

            try {
                $marketSymbol = MarketSymbol::where('id', $trade->market_symbol_id)
                    ->where('market_id', $trade->market_id)
                    ->firstOrFail();

                if (($trade->market->driver ?? null) !== 'nobitex') {
                    throw new \RuntimeException('در حال حاضر فقط منبع Nobitex برای دریافت داده تاریخی پیاده‌سازی شده است.');
                }

                $count = $nobitexMarket->syncCandles(
                    $marketSymbol,
                    $trade->timeframe,
                    Carbon::parse($trade->start_date),
                    Carbon::parse($trade->end_date)
                );

                $trade->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'stopped_at' => null,
                ]);

                return back()->with('success', "دریافت داده انجام شد. {$count} کندل در جدول market_candles ثبت/به‌روزرسانی شد.");
            } catch (Throwable $e) {
                $trade->update([
                    'status' => 'draft',
                    'started_at' => null,
                ]);

                return back()->with('error', 'دریافت داده‌های تاریخی ناموفق بود: '.$e->getMessage());
            }
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
