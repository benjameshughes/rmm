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
            // Load averages
            $table->float('load1')->nullable()->after('ram');
            $table->float('load5')->nullable()->after('load1');
            $table->float('load15')->nullable()->after('load5');

            // Uptime in seconds
            $table->unsignedBigInteger('uptime_seconds')->nullable()->after('load15');

            // Memory details (in MiB)
            $table->float('memory_used_mib')->nullable()->after('uptime_seconds');
            $table->float('memory_free_mib')->nullable()->after('memory_used_mib');
            $table->float('memory_total_mib')->nullable()->after('memory_free_mib');

            // Netdata alerts summary
            $table->unsignedInteger('alerts_normal')->nullable()->after('memory_total_mib');
            $table->unsignedInteger('alerts_warning')->nullable()->after('alerts_normal');
            $table->unsignedInteger('alerts_critical')->nullable()->after('alerts_warning');

            // Agent version that submitted this metric
            $table->string('agent_version', 20)->nullable()->after('alerts_critical');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'load1',
                'load5',
                'load15',
                'uptime_seconds',
                'memory_used_mib',
                'memory_free_mib',
                'memory_total_mib',
                'alerts_normal',
                'alerts_warning',
                'alerts_critical',
                'agent_version',
            ]);
        });
    }
};
