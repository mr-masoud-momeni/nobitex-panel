<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StrategyRule extends Model
{
    protected $fillable = [
        'type',
        'indicator',
        'parameters',
        'operator',
        'value_type',
        'value',
        'logical_operator',
        'sort_order',
    ];

    protected $casts = [
        'parameters' => 'array',
        'value' => 'array',
    ];

    public function strategy()
    {
        return $this->belongsTo(Strategy::class);
    }
}
