<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paper_trading_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paper_trading_state_id')
                ->constrained('paper_trading_states')
                ->onDelete('cascade');
            $table->unsignedBigInteger('candle_timestamp');
            $table->string('signal', 10);
            $table->decimal('close', 30, 10);
            $table->decimal('ema_fast', 30, 10);
            $table->decimal('ema_slow', 30, 10);
            $table->timestamps();

            $table->unique(
                ['paper_trading_state_id', 'candle_timestamp'],
                'paper_signal_state_candle_unique'
            );
            $table->index(['paper_trading_state_id', 'signal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_trading_signals');
    }
};
