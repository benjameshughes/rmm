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
        Schema::create('device_network_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_metric_id')->constrained('device_metrics')->cascadeOnDelete();
            $table->string('interface');
            $table->float('received_kbps')->nullable();
            $table->float('sent_kbps')->nullable();
            $table->unsignedBigInteger('received_bytes')->nullable();
            $table->unsignedBigInteger('sent_bytes')->nullable();
            $table->timestamps();

            $table->index(['device_metric_id', 'interface']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_network_metrics');
    }
};
