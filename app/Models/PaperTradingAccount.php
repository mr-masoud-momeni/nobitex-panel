<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperTradingAccount extends Model
{
    protected $fillable = [
        'paper_trading_state_id',
        'initial_capital',
        'cash_balance',
        'fee_percent',
        'realized_pnl',
        'last_processed_signal_id',
    ];

    protected $casts = [
        'last_processed_signal_id' => 'integer',
    ];

    public function state()
    {
        return $this->belongsTo(PaperTradingState::class, 'paper_trading_state_id');
    }

    public function trades()
    {
        return $this->hasMany(PaperTradingTrade::class, 'paper_trading_account_id');
    }

    public function openTrade()
    {
        return $this->hasOne(PaperTradingTrade::class, 'paper_trading_account_id')
            ->where('status', 'open');
    }
}
