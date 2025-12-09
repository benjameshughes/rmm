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
            $table->unsignedInteger('processes_running')->nullable()->after('agent_version');
            $table->unsignedInteger('processes_blocked')->nullable()->after('processes_running');
            $table->unsignedInteger('processes_total')->nullable()->after('processes_blocked');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'processes_running',
                'processes_blocked',
                'processes_total',
            ]);
        });
    }
};
