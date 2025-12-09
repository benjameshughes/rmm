<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('script_id')->nullable()->constrained('scripts')->nullOnDelete();
            $table->text('script_content');
            $table->string('script_type');
            $table->string('status')->default('pending');
            $table->text('output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('timeout_seconds')->default(300);
            $table->foreignId('queued_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['device_id', 'status']);
            $table->index(['device_id', 'queued_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_commands');
    }
};
