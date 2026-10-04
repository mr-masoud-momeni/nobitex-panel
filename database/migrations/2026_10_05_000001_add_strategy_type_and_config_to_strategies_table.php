<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStrategyTypeAndConfigToStrategiesTable extends Migration
{
    public function up()
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->string('strategy_type', 30)->default('generic')->after('direction');
            $table->json('config')->nullable()->after('strategy_type');
        });
    }

    public function down()
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->dropColumn(['strategy_type', 'config']);
        });
    }
}
