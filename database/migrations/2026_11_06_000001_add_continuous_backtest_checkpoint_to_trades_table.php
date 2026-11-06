<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

class AddContinuousBacktestCheckpointToTradesTable extends Migration
{
    public function up()
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->unsignedBigInteger('last_processed_timestamp')->nullable()->after('backtest_log');
            $table->json('engine_state')->nullable()->after('last_processed_timestamp');
        });
    }

    public function down()
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn(['last_processed_timestamp', 'engine_state']);
        });
    }
}
