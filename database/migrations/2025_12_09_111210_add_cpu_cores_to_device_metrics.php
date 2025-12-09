<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('device_metrics', function (Blueprint $table) {
            $table->integer('cpu_cores')->nullable()->after('cpu_idle');
            $table->float('cpu_frequency_mhz')->nullable()->after('cpu_cores');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_metrics', function (Blueprint $table) {
            $table->dropColumn(['cpu_cores', 'cpu_frequency_mhz']);
        });
    }
};
