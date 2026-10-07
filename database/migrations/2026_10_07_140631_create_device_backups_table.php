<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_backups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_command_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('status');
            $table->integer('exit_code')->nullable();
            $table->string('snapshot_id')->nullable();
            $table->unsignedBigInteger('files_new')->nullable();
            $table->unsignedBigInteger('files_changed')->nullable();
            $table->unsignedBigInteger('files_unmodified')->nullable();
            $table->unsignedBigInteger('data_added')->nullable();
            $table->unsignedBigInteger('total_bytes_processed')->nullable();
            $table->float('duration_seconds')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('finished_at');
            $table->timestamps();

            $table->index(['device_id', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_backups');
    }
};
