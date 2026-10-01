<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trade extends Model
{
    protected $fillable = [
        'strategy_id',
        'type',
        'symbol',
        'timeframe',
        'initial_capital',
        'fee_percent',
        'start_date',
        'end_date',
        'status',
        'result_amount',
        'result_percent',
        'total_trades',
        'winning_trades',
        'losing_trades',
        'started_at',
        'stopped_at',
        'completed_at',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function strategy()
    {
        return $this->belongsTo(Strategy::class);
    }
}
