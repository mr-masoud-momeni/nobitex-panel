<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paper_trading_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paper_trading_account_id')
                ->constrained('paper_trading_accounts')
                ->onDelete('cascade');
            $table->foreignId('entry_signal_id')
                ->unique('paper_trade_entry_signal_unique')
                ->constrained('paper_trading_signals')
                ->onDelete('cascade');
            $table->foreignId('exit_signal_id')
                ->nullable()
                ->unique('paper_trade_exit_signal_unique')
                ->constrained('paper_trading_signals')
                ->nullOnDelete();
            $table->string('symbol', 40);
            $table->string('timeframe', 20);
            $table->string('status', 10)->default('open');
            $table->decimal('quantity', 30, 10);
            $table->decimal('entry_price', 30, 10);
            $table->decimal('exit_price', 30, 10)->nullable();
            $table->decimal('entry_notional', 30, 10);
            $table->decimal('exit_notional', 30, 10)->nullable();
            $table->decimal('entry_fee', 30, 10)->default(0);
            $table->decimal('exit_fee', 30, 10)->default(0);
            $table->decimal('gross_pnl', 30, 10)->nullable();
            $table->decimal('net_pnl', 30, 10)->nullable();
            $table->unsignedBigInteger('entry_timestamp');
            $table->unsignedBigInteger('exit_timestamp')->nullable();
            $table->timestamps();

            $table->index(['paper_trading_account_id', 'status'], 'paper_trade_account_status_idx');
            $table->index(['paper_trading_account_id', 'entry_timestamp'], 'paper_trade_account_entry_ts_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_trading_trades');
    }
};
