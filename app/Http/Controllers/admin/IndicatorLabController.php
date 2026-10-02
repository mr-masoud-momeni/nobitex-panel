<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\MarketCandle;
use App\Services\Trading\Indicators\Ema;
use Carbon\Carbon;
use Illuminate\Http\Request;

class IndicatorLabController extends Controller
{
    public function index()
    {
        $markets = Market::where('is_active', true)
            ->with(['symbols' => function ($query) {
                $query->where('is_active', true)->orderBy('display_name');
            }])
            ->orderBy('name')
            ->get();

        return view('Backend.indicators.index', [
            'markets' => $markets,
            'result' => null,
        ]);
    }

    public function test(Request $request)
    {
        $data = $request->validate([
            'market_id' => ['required', 'exists:markets,id'],
            'market_symbol_id' => ['required', 'exists:market_symbols,id'],
            'timeframe' => ['required', 'string', 'max:20'],
            'period' => ['required', 'integer', 'min:1', 'max:1000'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

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

        $candles = MarketCandle::where('market_symbol_id', $symbol->id)
            ->where('timeframe', $data['timeframe'])
            ->whereBetween('timestamp', [$start->timestamp, $end->timestamp])
            ->orderBy('timestamp')
            ->get();

        if ($candles->isEmpty()) {
            return back()
                ->withInput()
                ->withErrors(['start_date' => 'برای این نماد، تایم‌فریم و بازه زمانی هیچ کندلی در market_candles پیدا نشد.']);
        }

        $ema = new Ema((int) $data['period']);
        $points = [];

        foreach ($candles as $candle) {
            $value = $ema->update((float) $candle->close);

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

        $result = [
            'market' => $market->name,
            'symbol' => $symbol->display_name ?: $symbol->symbol,
            'raw_symbol' => $symbol->symbol,
            'timeframe' => $data['timeframe'],
            'period' => (int) $data['period'],
            'start_date' => $start->format('Y-m-d H:i'),
            'end_date' => $end->format('Y-m-d H:i'),
            'candle_count' => $candles->count(),
            'ready_count' => count($readyPoints),
            'first_ready_time' => $readyPoints[0]['time'] ?? null,
            'last_ready_time' => $readyPoints[count($readyPoints) - 1]['time'] ?? null,
            'chart_points' => $chartPoints,
            'table_points' => array_reverse($tablePoints),
        ];

        $markets = Market::where('is_active', true)
            ->with(['symbols' => function ($query) {
                $query->where('is_active', true)->orderBy('display_name');
            }])
            ->orderBy('name')
            ->get();

        return view('Backend.indicators.index', compact('markets', 'result'))
            ->withInput();
    }
}
