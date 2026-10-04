<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Strategy extends Model
{
    protected $fillable = [
        'name',
        'description',
        'direction',
        'strategy_type',
        'config',
        'risk_percent',
        'stop_loss',
        'take_profit',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'config' => 'array',
    ];

    public function rules()
    {
        return $this->hasMany(StrategyRule::class)->orderBy('type')->orderBy('sort_order');
    }

    public function trades()
    {
        return $this->hasMany(Trade::class);
    }
}
