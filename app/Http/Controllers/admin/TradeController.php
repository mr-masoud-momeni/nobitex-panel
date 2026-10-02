<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\MarketSymbol;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\MarketCandle;
use App\Services\Trading\BacktestEngine;
use App\Services\Trading\Indicators\IndicatorWarmup;
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

        $now = Carbon::now('Asia/Tehran');
        $defaultStart = $trade?->start_date?->copy()->setTimezone('Asia/Tehran') ?? $now->copy()->subMonth();
        $defaultEnd = $trade?->end_date?->copy()->setTimezone('Asia/Tehran') ?? $now;

        return view('Backend.trades.create', [
            'strategies' => $strategies,
            'markets' => $markets,
            'trade' => $trade,
            'default_start_date_unix' => $defaultStart->timestamp * 1000,
            'default_end_date_unix' => $defaultEnd->timestamp * 1000,
            'default_start_date_jalali' => $this->formatJalaliDateTime($defaultStart),
            'default_end_date_jalali' => $this->formatJalaliDateTime($defaultEnd),
        ]);
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
        $data = $request->validate([
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
            'warmup_candles' => ['required', 'integer', 'min:1', 'max:100000'],
            'start_date' => ['nullable', 'integer'],
            'end_date' => ['nullable', 'integer'],
        ]);

        if (!empty($data['start_date'])) {
            $data['start_date'] = Carbon::createFromTimestampMs((int) $data['start_date'], 'Asia/Tehran');
        }

        if (!empty($data['end_date'])) {
            $data['end_date'] = Carbon::createFromTimestampMs((int) $data['end_date'], 'Asia/Tehran');
        }

        if (!empty($data['start_date']) && !empty($data['end_date']) && $data['end_date']->lt($data['start_date'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'end_date' => 'تاریخ پایان باید بعد از تاریخ شروع باشد.',
            ]);
        }

        return $data;
    }

    private function formatJalaliDateTime(Carbon $date): string
    {
        [$year, $month, $day] = $this->gregorianToJalali(
            (int) $date->format('Y'),
            (int) $date->format('m'),
            (int) $date->format('d')
        );

        return sprintf('%04d/%02d/%02d %s', $year, $month, $day, $date->format('H:i'));
    }

    private function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $gDaysInMonth = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $jDaysInMonth = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
        $gy -= 1600;
        $gm -= 1;
        $gd -= 1;
        $gDayNo = 365 * $gy + intdiv($gy + 3, 4) - intdiv($gy + 99, 100) + intdiv($gy + 399, 400);
        for ($i = 0; $i < $gm; $i++) $gDayNo += $gDaysInMonth[$i];
        if ($gm > 1 && (($gy + 1600) % 4 === 0 && (($gy + 1600) % 100 !== 0 || ($gy + 1600) % 400 === 0))) $gDayNo++;
        $gDayNo += $gd;
        $jDayNo = $gDayNo - 79;
        $jNp = intdiv($jDayNo, 12053);
        $jDayNo %= 12053;
        $jy = 979 + 33 * $jNp + 4 * intdiv($jDayNo, 1461);
        $jDayNo %= 1461;
        if ($jDayNo >= 366) {
            $jy += intdiv($jDayNo - 1, 365);
            $jDayNo = ($jDayNo - 1) % 365;
        }
        for ($i = 0; $i < 11 && $jDayNo >= $jDaysInMonth[$i]; $i++) $jDayNo -= $jDaysInMonth[$i];
        return [$jy, $i + 1, $jDayNo + 1];
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

    public function start(
        Trade $trade,
        NobitexMarket $nobitexMarket,
        IndicatorWarmup $indicatorWarmup,
        BacktestEngine $backtestEngine
    )
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

                $strategy = $trade->strategy()->with('rules')->firstOrFail();
                $warmupCandles = $indicatorWarmup->candlesFor($strategy, $trade->warmup_candles);

                $start = Carbon::parse($trade->start_date);
                $end = Carbon::parse($trade->end_date);

                $count = $nobitexMarket->syncCandles(
                    $marketSymbol,
                    $trade->timeframe,
                    $start,
                    $end,
                    $warmupCandles
                );

                $timeframeMinutes = [
                    '1m' => 1,
                    '5m' => 5,
                    '15m' => 15,
                    '30m' => 30,
                    '1h' => 60,
                    '4h' => 240,
                    '1d' => 1440,
                ][$trade->timeframe] ?? null;

                if ($timeframeMinutes === null) {
                    throw new \RuntimeException('تایم‌فریم انتخاب‌شده پشتیبانی نمی‌شود.');
                }

                $candleStart = $start->copy()->subMinutes($timeframeMinutes * $warmupCandles);

                $candles = MarketCandle::where('market_symbol_id', $marketSymbol->id)
                    ->where('timeframe', $trade->timeframe)
                    ->whereBetween('timestamp', [$candleStart->timestamp, $end->timestamp])
                    ->orderBy('timestamp')
                    ->get();

                if ($candles->isEmpty()) {
                    throw new \RuntimeException('هیچ کندلی برای اجرای بک‌تست پیدا نشد.');
                }

                $result = $backtestEngine->run($strategy, $trade, $candles);

                $trade->update([
                    'status' => 'completed',
                    'result_amount' => $result['result_amount'],
                    'result_percent' => $result['result_percent'],
                    'total_trades' => $result['total_trades'],
                    'winning_trades' => $result['winning_trades'],
                    'losing_trades' => $result['losing_trades'],
                    'backtest_log' => $result['execution_log'],
                    'completed_at' => now(),
                    'stopped_at' => null,
                ]);

                return back()->with(
                    'success',
                    "بک‌تست با موفقیت اجرا شد. {$count} کندل همگام‌سازی شد؛ "
                    ."نتیجه: ".number_format($result['result_percent'], 2)."٪ | "
                    ."تعداد معاملات: ".$result['total_trades']
                );
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
