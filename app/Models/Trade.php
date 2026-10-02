<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trade extends Model
{
    protected $fillable = [
        'strategy_id',
        'market_id',
        'market_symbol_id',
        'type',
        'symbol',
        'timeframe',
        'initial_capital',
        'fee_percent',
        'warmup_candles',
        'start_date',
        'end_date',
        'status',
        'result_amount',
        'result_percent',
        'total_trades',
        'winning_trades',
        'losing_trades',
        'backtest_log',
        'started_at',
        'stopped_at',
        'completed_at',
    ];

    protected $casts = [
        'warmup_candles' => 'integer',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
        'completed_at' => 'datetime',
        'backtest_log' => 'array',
    ];

    public function strategy()
    {
        return $this->belongsTo(Strategy::class);
    }

    public function market()
    {
        return $this->belongsTo(Market::class);
    }

    public function marketSymbol()
    {
        return $this->belongsTo(MarketSymbol::class);
    }
}
