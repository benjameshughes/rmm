<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_local_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('sid');
            $table->string('username');
            $table->string('full_name')->nullable();
            $table->boolean('is_enabled');
            $table->boolean('is_admin')->nullable();
            $table->boolean('is_signed_in')->nullable();
            $table->timestamp('last_logon_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'sid']);
            $table->index('username');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_local_accounts');
    }
};
