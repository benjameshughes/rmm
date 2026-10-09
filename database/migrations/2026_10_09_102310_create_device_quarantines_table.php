<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_quarantines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_command_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 1000);
            $table->string('kind', 20);
            $table->string('folder', 64);
            $table->string('quarantined_to', 1000);
            $table->unsignedBigInteger('bytes');
            $table->unsignedBigInteger('files');
            $table->timestamp('quarantined_at');
            $table->timestamp('purge_after');
            $table->timestamp('purged_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'folder']);
            $table->index('purge_after');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_quarantines');
    }
};
