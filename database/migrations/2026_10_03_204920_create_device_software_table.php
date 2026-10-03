<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_software', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('package_id');
            $table->string('name');
            $table->string('installed_version')->nullable();
            $table->string('latest_version')->nullable();
            $table->boolean('is_update_available');
            $table->string('source')->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['device_id', 'package_id']);
            $table->index('package_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_software');
    }
};
