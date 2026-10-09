<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_backup_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('job', 64);
            $table->string('tool', 32)->nullable();
            $table->string('repository', 500)->nullable();
            $table->integer('last_exit_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('status_file_modified_at')->nullable();
            $table->unsignedInteger('snapshot_count')->nullable();
            $table->unsignedBigInteger('total_size')->nullable();
            $table->unsignedBigInteger('total_uncompressed_size')->nullable();
            $table->double('compression_ratio')->nullable();
            $table->double('compression_space_saving')->nullable();
            $table->unsignedBigInteger('total_blob_count')->nullable();
            $table->timestamp('latest_snapshot_at')->nullable();
            $table->unsignedBigInteger('latest_bytes_processed')->nullable();
            $table->unsignedBigInteger('baseline_bytes_processed')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_reported_at');
            $table->timestamps();

            $table->unique(['device_id', 'job']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_backup_jobs');
    }
};
