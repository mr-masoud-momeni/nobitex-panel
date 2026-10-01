<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMarketSymbolsTable extends Migration
{
    public function up()
    {
        Schema::create('market_symbols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->onDelete('cascade');
            $table->string('symbol', 50);
            $table->string('display_name', 50);
            $table->string('base_asset', 30)->nullable();
            $table->string('quote_asset', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['market_id', 'symbol']);
            $table->index(['market_id', 'is_active']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('market_symbols');
    }
}
