<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTradesTable extends Migration
{
    public function up()
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained('strategies')->onDelete('cascade');
            $table->string('type', 20);
            $table->string('symbol', 30);
            $table->string('timeframe', 20);
            $table->decimal('initial_capital', 20, 8);
            $table->decimal('fee_percent', 8, 4)->nullable();
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->decimal('result_amount', 20, 8)->nullable();
            $table->decimal('result_percent', 12, 4)->nullable();
            $table->unsignedInteger('total_trades')->nullable();
            $table->unsignedInteger('winning_trades')->nullable();
            $table->unsignedInteger('losing_trades')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('stopped_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['strategy_id', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('trades');
    }
}
