<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->float('cpu')->nullable();
            $table->float('ram')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->index(['device_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_metrics');
    }
};

