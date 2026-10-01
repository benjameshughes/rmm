<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_metrics', function (Blueprint $table): void {
            $table->float('cpu_queue_length')->nullable();
            $table->float('swap_used_mib')->nullable();
            $table->float('swap_total_mib')->nullable();
            $table->float('disk_busy_percent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_metrics', function (Blueprint $table): void {
            $table->dropColumn(['cpu_queue_length', 'swap_used_mib', 'swap_total_mib', 'disk_busy_percent']);
        });
    }
};
