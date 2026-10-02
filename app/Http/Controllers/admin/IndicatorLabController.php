<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\MarketCandle;
use App\Services\Trading\Indicators\Ema;
use App\Services\Trading\Markets\NobitexMarket;
use Carbon\Carbon;
use Illuminate\Http\Request;
use RuntimeException;

class IndicatorLabController extends Controller
{
    private const TIMEFRAME_SECONDS = [
        '1m' => 60,
        '5m' => 300,
        '15m' => 900,
        '30m' => 1800,
        '1h' => 3600,
        '4h' => 14400,
        '1d' => 86400,
    ];

    public function index()
    {
        $markets = $this->markets();

        return view('Backend.indicators.index', [
            'markets' => $markets,
            'result' => null,
        ]);
    }

    public function test(Request $request, NobitexMarket $nobitexMarket)
    {
        $data = $request->validate([
            'market_id' => ['required', 'exists:markets,id'],
            'market_symbol_id' => ['required', 'exists:market_symbols,id'],
            'timeframe' => ['required', 'string', 'max:20'],
            'period' => ['required', 'integer', 'min:1', 'max:1000'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        if (!isset(self::TIMEFRAME_SECONDS[$data['timeframe']])) {
            return back()
                ->withInput()
                ->withErrors(['timeframe' => 'تایم‌فریم انتخاب‌شده پشتیبانی نمی‌شود.']);
        }

        $market = Market::with(['symbols' => function ($query) use ($data) {
            $query->where('id', $data['market_symbol_id']);
        }])->findOrFail($data['market_id']);

        $symbol = $market->symbols->first();

        if (!$symbol) {
            return back()
                ->withInput()
                ->withErrors(['market_symbol_id' => 'نماد انتخاب‌شده متعلق به بازار انتخاب‌شده نیست.']);
        }

        $start = Carbon::parse($data['start_date'], 'Asia/Tehran');
        $end = Carbon::parse($data['end_date'], 'Asia/Tehran');

        // EMA needs historical candles before the visible range to establish its state.
        // Keep this consistent with the strategy warm-up policy.
        $warmupCandles = max(1000, ((int) $data['period']) * 50);
        $calculationStart = $this->subtractCandles(
            $start,
            $data['timeframe'],
            $warmupCandles
        );

        $storedCandles = MarketCandle::where('market_symbol_id', $symbol->id)
            ->where('timeframe', $data['timeframe'])
            ->whereBetween('timestamp', [$calculationStart->timestamp, $end->timestamp])
            ->orderBy('timestamp')
            ->get();

        $expectedCount = $this->expectedCandleCount(
            $calculationStart->timestamp,
            $end->timestamp,
            self::TIMEFRAME_SECONDS[$data['timeframe']]
        );

        // Use the local candle cache first. Only ask Nobitex when the required
        // calculation range is incomplete. syncCandles() is idempotent.
        if ($storedCandles->count() < $expectedCount) {
            try {
                $nobitexMarket->syncCandles(
                    $symbol,
                    $data['timeframe'],
                    $calculationStart,
                    $end
                );
            } catch (RuntimeException $e) {
                return back()
                    ->withInput()
                    ->withErrors(['start_date' => 'دریافت داده از نوبیتکس انجام نشد: '.$e->getMessage()]);
            }

            $storedCandles = MarketCandle::where('market_symbol_id', $symbol->id)
                ->where('timeframe', $data['timeframe'])
                ->whereBetween('timestamp', [$calculationStart->timestamp, $end->timestamp])
                ->orderBy('timestamp')
                ->get();
        }

        if ($storedCandles->isEmpty()) {
            return back()
                ->withInput()
                ->withErrors(['start_date' => 'برای این نماد، تایم‌فریم و بازه انتخاب‌شده هیچ کندلی در market_candles وجود ندارد و از نوبیتکس نیز داده‌ای دریافت نشد.']);
        }

        $ema = new Ema((int) $data['period']);
        $points = [];

        foreach ($storedCandles as $candle) {
            $value = $ema->update((float) $candle->close);

            // Warm-up candles participate in EMA state but are not displayed.
            if ((int) $candle->timestamp < $start->timestamp) {
                continue;
            }

            $points[] = [
                'timestamp' => (int) $candle->timestamp,
                'time' => Carbon::createFromTimestampUTC((int) $candle->timestamp)
                    ->setTimezone('Asia/Tehran')
                    ->format('Y-m-d H:i'),
                'close' => (float) $candle->close,
                'ema' => $value,
            ];
        }

        $readyPoints = array_values(array_filter($points, function ($point) {
            return $point['ema'] !== null;
        }));

        $chartPoints = array_map(function ($point) {
            return [
                'timestamp' => $point['timestamp'],
                'close' => $point['close'],
                'ema' => $point['ema'],
            ];
        }, $readyPoints);

        $tablePoints = array_slice($readyPoints, -200);
        $firstReady = $readyPoints[0] ?? null;
        $lastReady = $readyPoints ? $readyPoints[count($readyPoints) - 1] : null;

        $result = [
            'market_id' => (int) $market->id,
            'market_symbol_id' => (int) $symbol->id,
            'market' => $market->name,
            'symbol' => $symbol->display_name ?: $symbol->symbol,
            'raw_symbol' => $symbol->symbol,
            'timeframe' => $data['timeframe'],
            'period' => (int) $data['period'],
            'start_date' => $start->format('Y-m-d H:i'),
            'end_date' => $end->format('Y-m-d H:i'),
            'calculation_start' => $calculationStart->format('Y-m-d H:i'),
            'warmup_count' => $storedCandles->filter(
                fn ($candle) => (int) $candle->timestamp < $start->timestamp
            )->count(),
            'candle_count' => $storedCandles->count(),
            'displayed_count' => count($points),
            'ready_count' => count($readyPoints),
            'first_ready_time' => $firstReady['time'] ?? null,
            'last_ready_time' => $lastReady['time'] ?? null,
            'chart_points' => $chartPoints,
            'table_points' => array_reverse($tablePoints),
        ];

        return view('Backend.indicators.index', [
            'markets' => $this->markets(),
            'result' => $result,
        ]);
    }

    private function markets()
    {
        return Market::where('is_active', true)
            ->with(['symbols' => function ($query) {
                $query->where('is_active', true)->orderBy('display_name');
            }])
            ->orderBy('name')
            ->get();
    }

    private function subtractCandles(Carbon $start, string $timeframe, int $count): Carbon
    {
        return $start->copy()->subSeconds(
            self::TIMEFRAME_SECONDS[$timeframe] * $count
        );
    }

    private function expectedCandleCount(int $from, int $to, int $step): int
    {
        if ($to < $from) {
            return 0;
        }

        return (int) floor(($to - $from) / $step) + 1;
    }
}
