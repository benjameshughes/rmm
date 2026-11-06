<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_secrets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_command_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('value');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['device_command_id', 'name']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_secrets');
    }
};
