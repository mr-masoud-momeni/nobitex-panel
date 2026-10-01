<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Strategy extends Model
{
    protected $fillable = [
        'name',
        'description',
        'entry_conditions',
        'exit_conditions',
        'risk_percent',
        'stop_loss',
        'take_profit',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
