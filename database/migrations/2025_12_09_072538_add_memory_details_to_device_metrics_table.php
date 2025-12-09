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
            $table->float('memory_cached_mib')->nullable()->after('memory_total_mib');
            $table->float('memory_buffers_mib')->nullable()->after('memory_cached_mib');
            $table->float('memory_available_mib')->nullable()->after('memory_buffers_mib');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'memory_cached_mib',
                'memory_buffers_mib',
                'memory_available_mib',
            ]);
        });
    }
};
