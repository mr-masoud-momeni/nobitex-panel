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
        $strategies = Strategy::where('strategy_type', 'generic')
            ->where(function ($query) use ($trade) {
                $query->where('is_active', true);

                if ($trade && $trade->strategy && $trade->strategy->strategy_type === 'generic') {
                    $query->orWhere('id', $trade->strategy_id);
                }
            })
            ->orderBy('name')
            ->get();

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
                'backtest_log',
                'last_processed_timestamp',
                'engine_state',
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
            $copy->backtest_log = null;
            $copy->last_processed_timestamp = null;
            $copy->engine_state = null;
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
            'strategy_id' => ['required', Rule::exists('strategies', 'id')->where('strategy_type', 'generic')],
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
            'start_date' => ['nullable', 'integer', 'min:1'],
            'end_date' => ['nullable', 'integer', 'min:1'],
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

        $storedBacktestLog = is_array($trade->backtest_log) ? $trade->backtest_log : [];
        $structureDebug = is_array($storedBacktestLog['structure_debug'] ?? null)
            ? $storedBacktestLog['structure_debug']
            : [];
        $backtestLog = isset($storedBacktestLog['executions']) && is_array($storedBacktestLog['executions'])
            ? $storedBacktestLog['executions']
            : $storedBacktestLog;

        foreach ($backtestLog as &$execution) {
            if (!empty($execution['entry_time'])) {
                $execution['entry_time_jalali'] = $this->formatJalaliDateTime(
                    Carbon::parse($execution['entry_time'], 'Asia/Tehran')
                );
            }
            if (!empty($execution['exit_time'])) {
                $execution['exit_time_jalali'] = $this->formatJalaliDateTime(
                    Carbon::parse($execution['exit_time'], 'Asia/Tehran')
                );
            }
        }
        unset($execution);

        foreach (($structureDebug['domes'] ?? []) as &$dome) {
            foreach (['start_time', 'peak_time', 'end_time'] as $timeKey) {
                if (!empty($dome[$timeKey])) {
                    $dome[$timeKey.'_jalali'] = $this->formatJalaliDateTime(
                        Carbon::parse($dome[$timeKey], 'Asia/Tehran')
                    );
                }
            }
        }
        unset($dome);

        return view('Backend.trades.show', [
            'trade' => $trade,
            'backtestLog' => $backtestLog,
            'structureDebug' => $structureDebug,
            'startDateJalali' => $trade->start_date
                ? $this->formatJalaliDateTime($trade->start_date->copy()->setTimezone('Asia/Tehran'))
                : null,
            'endDateJalali' => $trade->end_date
                ? $this->formatJalaliDateTime($trade->end_date->copy()->setTimezone('Asia/Tehran'))
                : null,
        ]);
    }

    public function start(
        Trade $trade,
        NobitexMarket $nobitexMarket,
        IndicatorWarmup $indicatorWarmup,
        BacktestEngine $backtestEngine
    )
    {
        if ($trade->type !== 'backtest') {
            return back()->with('error', 'ادامه خودکار فعلاً فقط برای معاملات Backtest مبتنی بر Rule Builder فعال است.');
        }

        if ($trade->status === 'completed') {
            return back()->with('error', 'این بک‌تست به پایان رسیده است. برای اجرای جدید، معامله را داپلیکیت کنید.');
        }

        try {
            $outcome = DB::transaction(function () use (
                $trade,
                $nobitexMarket,
                $indicatorWarmup,
                $backtestEngine
            ) {
                $lockedTrade = Trade::whereKey($trade->id)->lockForUpdate()->firstOrFail();

                if ($lockedTrade->status === 'completed') {
                    return ['kind' => 'completed'];
                }

                if (!$lockedTrade->start_date) {
                    throw new \RuntimeException('برای شروع بک‌تست باید تاریخ شروع مشخص شده باشد.');
                }

                $lockedTrade->loadMissing(['market', 'strategy.rules']);

                if (($lockedTrade->strategy->strategy_type ?? null) !== 'generic') {
                    throw new \RuntimeException('این بخش فقط برای استراتژی‌های Rule Builder فعال است.');
                }

                if (($lockedTrade->market->driver ?? null) !== 'nobitex') {
                    throw new \RuntimeException('در حال حاضر فقط منبع Nobitex برای دریافت داده تاریخی پیاده‌سازی شده است.');
                }

                $marketSymbol = MarketSymbol::where('id', $lockedTrade->market_symbol_id)
                    ->where('market_id', $lockedTrade->market_id)
                    ->firstOrFail();

                $strategy = $lockedTrade->strategy;
                $warmupCandles = $indicatorWarmup->candlesFor($strategy, $lockedTrade->warmup_candles);

                $timeframeMinutes = [
                    '1m' => 1,
                    '5m' => 5,
                    '15m' => 15,
                    '30m' => 30,
                    '1h' => 60,
                    '4h' => 240,
                    '1d' => 1440,
                ][$lockedTrade->timeframe] ?? null;

                if ($timeframeMinutes === null) {
                    throw new \RuntimeException('تایم‌فریم انتخاب‌شده پشتیبانی نمی‌شود.');
                }

                $intervalSeconds = $timeframeMinutes * 60;
                $nowTimestamp = Carbon::now('UTC')->timestamp;
                $latestClosedTimestamp = (intdiv($nowTimestamp, $intervalSeconds) * $intervalSeconds) - $intervalSeconds;

                $endBoundaryTimestamp = $latestClosedTimestamp;
                if ($lockedTrade->end_date) {
                    $requestedEnd = $lockedTrade->end_date->timestamp;
                    $requestedLastCandle = (intdiv($requestedEnd, $intervalSeconds) * $intervalSeconds) - $intervalSeconds;
                    $endBoundaryTimestamp = min($latestClosedTimestamp, $requestedLastCandle);
                }

                $lastProcessed = $lockedTrade->last_processed_timestamp;
                $isFirstRun = $lastProcessed === null;
                $nextStartTimestamp = $isFirstRun
                    ? $lockedTrade->start_date->timestamp
                    : ((int) $lastProcessed + $intervalSeconds);

                if ($endBoundaryTimestamp < $nextStartTimestamp) {
                    return ['kind' => 'no_new'];
                }

                $syncStart = Carbon::createFromTimestamp($nextStartTimestamp, 'UTC');
                $syncEnd = Carbon::createFromTimestamp($endBoundaryTimestamp, 'UTC');

                $syncedCount = $nobitexMarket->syncCandles(
                    $marketSymbol,
                    $lockedTrade->timeframe,
                    $syncStart,
                    $syncEnd,
                    $isFirstRun ? $warmupCandles : 0
                );

                $query = MarketCandle::where('market_symbol_id', $marketSymbol->id)
                    ->where('timeframe', $lockedTrade->timeframe)
                    ->where('timestamp', '<=', $endBoundaryTimestamp);

                if ($isFirstRun) {
                    $candleStartTimestamp = $lockedTrade->start_date->timestamp
                        - ($intervalSeconds * $warmupCandles);
                    $query->where('timestamp', '>=', $candleStartTimestamp);
                } else {
                    $query->where('timestamp', '>', (int) $lastProcessed);
                }

                $candles = $query->orderBy('timestamp')->get();

                if ($candles->isEmpty()) {
                    return ['kind' => 'no_new', 'synced' => $syncedCount];
                }

                $shouldFinalize = $lockedTrade->end_date !== null
                    && $latestClosedTimestamp >= $endBoundaryTimestamp
                    && $endBoundaryTimestamp === ((intdiv($lockedTrade->end_date->timestamp, $intervalSeconds) * $intervalSeconds) - $intervalSeconds);

                $engineCheckpoint = $lockedTrade->engine_state;
                if ($isFirstRun) {
                    $engineCheckpoint = ['warmup_remaining' => $warmupCandles];
                }

                $result = $backtestEngine->run(
                    $strategy,
                    $lockedTrade,
                    $candles,
                    $engineCheckpoint,
                    $shouldFinalize
                );

                $storedLog = is_array($lockedTrade->backtest_log) ? $lockedTrade->backtest_log : [];
                $previousExecutions = is_array($storedLog['executions'] ?? null)
                    ? $storedLog['executions']
                    : [];
                $storedLog['executions'] = array_merge($previousExecutions, $result['execution_log']);

                $lockedTrade->update([
                    'status' => $shouldFinalize ? 'completed' : 'running',
                    'started_at' => $lockedTrade->started_at ?: now(),
                    'stopped_at' => null,
                    'completed_at' => $shouldFinalize ? now() : null,
                    'result_amount' => $result['result_amount'],
                    'result_percent' => $result['result_percent'],
                    'total_trades' => $result['total_trades'],
                    'winning_trades' => $result['winning_trades'],
                    'losing_trades' => $result['losing_trades'],
                    'backtest_log' => $storedLog,
                    'engine_state' => $result['engine_state'],
                    'last_processed_timestamp' => $result['last_processed_timestamp'],
                ]);

                return [
                    'kind' => 'processed',
                    'synced' => $syncedCount,
                    'candles' => $candles->count(),
                    'result' => $result,
                    'finalized' => $shouldFinalize,
                ];
            }, 3);

            if ($outcome['kind'] === 'completed') {
                return back()->with('error', 'این بک‌تست به پایان رسیده است.');
            }

            if ($outcome['kind'] === 'no_new') {
                return back()->with('info', 'هنوز کندل بسته‌شده جدیدی برای پردازش وجود ندارد. وضعیت قبلی معامله حفظ شد.');
            }

            $result = $outcome['result'];
            $message = ($outcome['finalized'] ? 'بک‌تست به پایان رسید' : 'بک‌تست به‌روزرسانی شد')
                . "؛ {$outcome['candles']} کندل پردازش شد و {$outcome['synced']} کندل همگام‌سازی شد. "
                . 'نتیجه فعلی: ' . number_format($result['result_percent'], 2) . '٪ | '
                . 'تعداد معاملات بسته‌شده: ' . $result['total_trades'];

            if (!$outcome['finalized'] && ($result['warmup_remaining'] ?? 0) > 0) {
                $message .= ' | کندل باقی‌مانده برای Warm-up: ' . $result['warmup_remaining'];
            }

            return back()->with('success', $message);
        } catch (Throwable $e) {
            return back()->with('error', 'ادامه بک‌تست ناموفق بود: ' . $e->getMessage());
        }
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
