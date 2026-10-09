<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_backup_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_backup_job_id')->constrained()->cascadeOnDelete();
            $table->string('snapshot_id', 64);
            $table->string('short_id', 16);
            $table->timestamp('taken_at');
            $table->string('hostname')->nullable();
            $table->json('paths')->nullable();
            $table->json('tags')->nullable();
            $table->double('duration_seconds')->nullable();
            $table->unsignedBigInteger('files_new')->nullable();
            $table->unsignedBigInteger('files_changed')->nullable();
            $table->unsignedBigInteger('files_unmodified')->nullable();
            $table->unsignedBigInteger('total_files_processed')->nullable();
            $table->unsignedBigInteger('total_bytes_processed')->nullable();
            $table->unsignedBigInteger('data_added')->nullable();
            $table->unsignedBigInteger('data_added_packed')->nullable();
            $table->timestamps();

            $table->unique(['server_backup_job_id', 'snapshot_id']);
            $table->index(['server_backup_job_id', 'taken_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_backup_snapshots');
    }
};
