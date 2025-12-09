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
        Schema::create('device_disk_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_metric_id')->constrained('device_metrics')->cascadeOnDelete();
            $table->string('mount_point');
            $table->string('filesystem')->nullable();
            $table->float('used_gb')->nullable();
            $table->float('available_gb')->nullable();
            $table->float('total_gb')->nullable();
            $table->float('usage_percent')->nullable();
            $table->float('read_kbps')->nullable();
            $table->float('write_kbps')->nullable();
            $table->float('utilization_percent')->nullable();
            $table->timestamps();

            $table->index(['device_metric_id', 'mount_point']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_disk_metrics');
    }
};
