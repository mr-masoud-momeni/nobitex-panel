<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperTradingState extends Model
{
    protected $fillable = [
        'market_symbol_id',
        'timeframe',
        'strategy_key',
        'last_processed_candle_timestamp',
        'position_status',
    ];

    protected $casts = [
        'last_processed_candle_timestamp' => 'integer',
    ];

    public function marketSymbol()
    {
        return $this->belongsTo(MarketSymbol::class);
    }

    public function signals()
    {
        return $this->hasMany(PaperTradingSignal::class);
    }
}
