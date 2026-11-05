<?php

namespace App\Services\Trading;

use App\Models\MarketCandle;
use App\Models\MarketSymbol;
use App\Models\PaperTradingSignal;
use App\Models\PaperTradingState;
use App\Services\Trading\Indicators\Ema;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LiveEmaSignalEngine
{
    public const STRATEGY_KEY = 'ema20_ema50_long_v1';

    private const TIMEFRAME_SECONDS = [
        '15m' => 900,
    ];

    public function process(MarketSymbol $marketSymbol, string $timeframe = '15m', int $warmupCandles = 200): array
    {
        if (!isset(self::TIMEFRAME_SECONDS[$timeframe])) {
            throw new InvalidArgumentException('The live EMA engine currently supports only the 15m timeframe.');
        }

        if ($warmupCandles < 50) {
            throw new InvalidArgumentException('At least 50 warmup candles are required.');
        }

        $query = MarketCandle::where('market_symbol_id', $marketSymbol->id)
            ->where('timeframe', $timeframe);

        $closedCutoff = now()->timestamp - self::TIMEFRAME_SECONDS[$timeframe];
        $latestClosedTimestamp = (int) (clone $query)
            ->where('timestamp', '<=', $closedCutoff)
            ->max('timestamp');

        if ($latestClosedTimestamp <= 0) {
            return [
                'status' => 'no_closed_candles',
                'processed' => 0,
                'created_signals' => [],
            ];
        }

        $state = PaperTradingState::firstOrCreate(
            [
                'market_symbol_id' => $marketSymbol->id,
                'timeframe' => $timeframe,
                'strategy_key' => self::STRATEGY_KEY,
            ],
            [
                'position_status' => 'flat',
            ]
        );

        return DB::transaction(function () use (
            $state,
            $marketSymbol,
            $timeframe,
            $warmupCandles,
            $latestClosedTimestamp
        ) {
            $state = PaperTradingState::whereKey($state->id)
                ->lockForUpdate()
                ->firstOrFail();

            $cursor = $state->last_processed_candle_timestamp;

            if ($cursor === null) {
                $availableWarmup = MarketCandle::where('market_symbol_id', $marketSymbol->id)
                    ->where('timeframe', $timeframe)
                    ->where('timestamp', '<', $latestClosedTimestamp)
                    ->orderByDesc('timestamp')
                    ->limit($warmupCandles)
                    ->count();

                if ($availableWarmup < $warmupCandles) {
                    return [
                        'status' => 'waiting_for_warmup',
                        'processed' => 0,
                        'created_signals' => [],
                        'available_warmup' => $availableWarmup,
                        'required_warmup' => $warmupCandles,
                    ];
                }

                $state->last_processed_candle_timestamp = $latestClosedTimestamp;
                $state->save();

                return [
                    'status' => 'initialized',
                    'processed' => 0,
                    'created_signals' => [],
                    'last_processed' => $latestClosedTimestamp,
                ];
            }

            $pendingCandles = MarketCandle::where('market_symbol_id', $marketSymbol->id)
                ->where('timeframe', $timeframe)
                ->where('timestamp', '>', $cursor)
                ->where('timestamp', '<=', $latestClosedTimestamp)
                ->orderBy('timestamp')
                ->get(['timestamp', 'close']);

            if ($pendingCandles->isEmpty()) {
                return [
                    'status' => 'up_to_date',
                    'processed' => 0,
                    'created_signals' => [],
                ];
            }

            $firstPendingTimestamp = (int) $pendingCandles->first()->timestamp;

            $warmup = MarketCandle::where('market_symbol_id', $marketSymbol->id)
                ->where('timeframe', $timeframe)
                ->where('timestamp', '<', $firstPendingTimestamp)
                ->orderByDesc('timestamp')
                ->limit($warmupCandles)
                ->get(['timestamp', 'close'])
                ->reverse()
                ->values();

            if ($warmup->count() < $warmupCandles) {
                return [
                    'status' => 'waiting_for_warmup',
                    'processed' => 0,
                    'created_signals' => [],
                    'available_warmup' => $warmup->count(),
                    'required_warmup' => $warmupCandles,
                ];
            }

            $emaFast = new Ema(20);
            $emaSlow = new Ema(50);

            foreach ($warmup as $candle) {
                $close = (float) $candle->close;
                $emaFast->update($close);
                $emaSlow->update($close);
            }

            $createdSignals = [];
            $processed = 0;

            foreach ($pendingCandles as $candle) {
                $close = (float) $candle->close;
                $fastValue = $emaFast->update($close);
                $slowValue = $emaSlow->update($close);

                if ($fastValue !== null && $slowValue !== null) {
                    $signal = null;

                    if ($state->position_status === 'flat' && $fastValue > $slowValue && $close > $fastValue) {
                        $signal = 'entry';
                        $state->position_status = 'long';
                    } elseif ($state->position_status === 'long' && $close < $fastValue) {
                        $signal = 'exit';
                        $state->position_status = 'flat';
                    }

                    if ($signal !== null) {
                        PaperTradingSignal::create([
                            'paper_trading_state_id' => $state->id,
                            'candle_timestamp' => (int) $candle->timestamp,
                            'signal' => $signal,
                            'close' => $close,
                            'ema_fast' => $fastValue,
                            'ema_slow' => $slowValue,
                        ]);

                        $createdSignals[] = [
                            'signal' => $signal,
                            'timestamp' => (int) $candle->timestamp,
                            'close' => $close,
                            'ema_fast' => $fastValue,
                            'ema_slow' => $slowValue,
                        ];
                    }
                }

                $state->last_processed_candle_timestamp = (int) $candle->timestamp;
                $processed++;
            }

            $state->save();

            return [
                'status' => 'processed',
                'processed' => $processed,
                'created_signals' => $createdSignals,
                'last_processed' => $state->last_processed_candle_timestamp,
                'position_status' => $state->position_status,
            ];
        });
    }
}
