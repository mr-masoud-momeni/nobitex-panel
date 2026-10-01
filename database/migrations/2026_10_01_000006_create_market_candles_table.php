<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMarketCandlesTable extends Migration
{
    public function up()
    {
        Schema::create('market_candles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_symbol_id')->constrained('market_symbols')->onDelete('cascade');
            $table->string('timeframe', 20);
            $table->unsignedBigInteger('timestamp');
            $table->decimal('open', 30, 10);
            $table->decimal('high', 30, 10);
            $table->decimal('low', 30, 10);
            $table->decimal('close', 30, 10);
            $table->decimal('volume', 30, 10)->nullable();
            $table->timestamps();

            $table->unique(
                ['market_symbol_id', 'timeframe', 'timestamp'],
                'market_candles_symbol_timeframe_timestamp_unique'
            );

            $table->index(
                ['market_symbol_id', 'timeframe', 'timestamp'],
                'market_candles_lookup_index'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('market_candles');
    }
}
