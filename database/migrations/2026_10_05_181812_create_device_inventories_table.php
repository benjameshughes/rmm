<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_inventories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->timestamp('collected_at');
            $table->json('data');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->decimal('total_ram_gb', 8, 1)->nullable();
            $table->string('windows_edition')->nullable();
            $table->string('windows_build')->nullable();
            $table->boolean('is_bitlocker_on')->nullable();
            $table->boolean('is_secure_boot')->nullable();
            $table->unsignedInteger('local_admin_count')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_inventories');
    }
};
