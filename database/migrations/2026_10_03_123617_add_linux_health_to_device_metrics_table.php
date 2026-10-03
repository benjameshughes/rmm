<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_metrics', function (Blueprint $table): void {
            $table->json('failed_units')->nullable();
            $table->boolean('reboot_required')->nullable();
            $table->unsignedInteger('pending_updates')->nullable();
            $table->unsignedInteger('pending_security_updates')->nullable();
            $table->timestamp('updates_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_metrics', function (Blueprint $table): void {
            $table->dropColumn(['failed_units', 'reboot_required', 'pending_updates', 'pending_security_updates', 'updates_checked_at']);
        });
    }
};
