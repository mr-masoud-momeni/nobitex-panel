<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperTradingSignal extends Model
{
    protected $fillable = [
        'paper_trading_state_id',
        'candle_timestamp',
        'signal',
        'close',
        'ema_fast',
        'ema_slow',
    ];

    protected $casts = [
        'candle_timestamp' => 'integer',
    ];

    public function state()
    {
        return $this->belongsTo(PaperTradingState::class, 'paper_trading_state_id');
    }
}
