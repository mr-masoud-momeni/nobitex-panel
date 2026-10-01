<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMarketToTradesTable extends Migration
{
    public function up()
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->foreignId('market_id')->nullable()->after('strategy_id')->constrained('markets')->onDelete('restrict');
            $table->foreignId('market_symbol_id')->nullable()->after('market_id')->constrained('market_symbols')->onDelete('restrict');

            $table->index(['market_id', 'market_symbol_id']);
        });
    }

    public function down()
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropForeign(['market_symbol_id']);
            $table->dropForeign(['market_id']);
            $table->dropIndex(['market_id', 'market_symbol_id']);
            $table->dropColumn(['market_id', 'market_symbol_id']);
        });
    }
}