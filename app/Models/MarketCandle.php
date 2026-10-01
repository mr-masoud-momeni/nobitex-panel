<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketCandle extends Model
{
    protected $fillable = [
        'market_symbol_id',
        'timeframe',
        'timestamp',
        'open',
        'high',
        'low',
        'close',
        'volume',
    ];

    public function marketSymbol()
    {
        return $this->belongsTo(MarketSymbol::class);
    }
}
