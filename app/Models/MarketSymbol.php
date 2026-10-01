<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketSymbol extends Model
{
    protected $fillable = [
        'market_id',
        'symbol',
        'display_name',
        'base_asset',
        'quote_asset',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function market()
    {
        return $this->belongsTo(Market::class);
    }
}
