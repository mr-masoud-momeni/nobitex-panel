<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperTradingTrade extends Model
{
    protected $fillable = [
        'paper_trading_account_id',
        'entry_signal_id',
        'exit_signal_id',
        'symbol',
        'timeframe',
        'status',
        'quantity',
        'entry_price',
        'exit_price',
        'entry_notional',
        'exit_notional',
        'entry_fee',
        'exit_fee',
        'gross_pnl',
        'net_pnl',
        'entry_timestamp',
        'exit_timestamp',
    ];

    protected $casts = [
        'entry_timestamp' => 'integer',
        'exit_timestamp' => 'integer',
    ];

    public function account()
    {
        return $this->belongsTo(PaperTradingAccount::class, 'paper_trading_account_id');
    }

    public function entrySignal()
    {
        return $this->belongsTo(PaperTradingSignal::class, 'entry_signal_id');
    }

    public function exitSignal()
    {
        return $this->belongsTo(PaperTradingSignal::class, 'exit_signal_id');
    }
}
