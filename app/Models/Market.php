<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Market extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'driver',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function symbols()
    {
        return $this->hasMany(MarketSymbol::class);
    }
}
