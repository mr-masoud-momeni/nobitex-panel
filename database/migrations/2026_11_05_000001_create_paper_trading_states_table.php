<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paper_trading_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_symbol_id')->constrained('market_symbols')->onDelete('cascade');
            $table->string('timeframe', 20);
            $table->string('strategy_key', 60);
            $table->unsignedBigInteger('last_processed_candle_timestamp')->nullable();
            $table->string('position_status', 10)->default('flat');
            $table->timestamps();

            $table->unique(
                ['market_symbol_id', 'timeframe', 'strategy_key'],
                'paper_state_symbol_tf_strategy_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_trading_states');
    }
};
