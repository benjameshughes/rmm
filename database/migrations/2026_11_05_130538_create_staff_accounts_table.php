<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->unique();
            $table->string('full_name');
            $table->text('password');
            $table->boolean('is_admin');
            $table->boolean('is_enabled');
            $table->timestamps();
        });

        Schema::create('staff_account_device', function (Blueprint $table): void {
            $table->foreignId('staff_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->primary(['staff_account_id', 'device_id']);
        });

        Schema::create('staff_account_device_group', function (Blueprint $table): void {
            $table->foreignId('staff_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_group_id')->constrained()->cascadeOnDelete();
            $table->primary(['staff_account_id', 'device_group_id']);
        });

        Schema::create('staff_account_tag', function (Blueprint $table): void {
            $table->foreignId('staff_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['staff_account_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_account_tag');
        Schema::dropIfExists('staff_account_device_group');
        Schema::dropIfExists('staff_account_device');
        Schema::dropIfExists('staff_accounts');
    }
};
