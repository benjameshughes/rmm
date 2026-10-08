<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_disk_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('root', 1000);
            $table->unsignedTinyInteger('depth')->nullable();
            $table->timestamp('scanned_at');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('allocated');
            $table->unsignedBigInteger('files');
            $table->unsignedInteger('error_count');
            $table->json('data');
            $table->timestamps();

            $table->index(['device_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_disk_scans');
    }
};
