<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->string('hostname');
            $table->string('hardware_fingerprint')->nullable()->unique();
            $table->string('api_key')->nullable()->unique();
            $table->string('status')->default('pending'); // pending, active, revoked
            $table->string('os')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->timestamps();

            $table->index('hostname');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};

