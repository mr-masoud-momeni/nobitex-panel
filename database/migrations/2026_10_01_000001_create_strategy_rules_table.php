<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStrategyRulesTable extends Migration
{
    public function up()
    {
        Schema::create('strategy_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained('strategies')->onDelete('cascade');
            $table->string('type', 20);
            $table->string('indicator', 50);
            $table->json('parameters')->nullable();
            $table->string('operator', 30);
            $table->string('value_type', 20);
            $table->json('value')->nullable();
            $table->string('logical_operator', 10)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['strategy_id', 'type', 'sort_order']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('strategy_rules');
    }
}
