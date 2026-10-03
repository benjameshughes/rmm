<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_disk_metrics', function (Blueprint $table): void {
            $table->float('inode_usage_percent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_disk_metrics', function (Blueprint $table): void {
            $table->dropColumn('inode_usage_percent');
        });
    }
};
