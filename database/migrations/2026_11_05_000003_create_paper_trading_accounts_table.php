<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paper_trading_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paper_trading_state_id')
                ->unique('paper_account_state_unique')
                ->constrained('paper_trading_states')
                ->onDelete('cascade');
            $table->decimal('initial_capital', 30, 10)->default(10000);
            $table->decimal('cash_balance', 30, 10)->default(10000);
            $table->decimal('fee_percent', 8, 5)->default(0.1);
            $table->decimal('realized_pnl', 30, 10)->default(0);
            $table->unsignedBigInteger('last_processed_signal_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_trading_accounts');
    }
};
