<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_backup_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('snapshot_id', 64);
            $table->string('short_id', 16);
            $table->timestamp('taken_at');
            $table->json('paths');
            $table->unsignedBigInteger('files')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->unsignedBigInteger('bytes_added')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'snapshot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_backup_snapshots');
    }
};
