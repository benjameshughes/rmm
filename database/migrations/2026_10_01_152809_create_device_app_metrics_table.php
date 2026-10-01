<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_app_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_metric_id')->index()->constrained('device_metrics')->cascadeOnDelete();
            $table->string('name');
            $table->float('cpu_percent')->nullable();
            $table->float('memory_mib')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_app_metrics');
    }
};
