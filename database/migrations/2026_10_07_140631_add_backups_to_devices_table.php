<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('backup_rest_username')->nullable();
            $table->text('backup_rest_password')->nullable();
            $table->text('backup_repository_password')->nullable();
            $table->timestamp('backup_configured_at')->nullable()->index();
            $table->timestamp('last_backup_at')->nullable();
            $table->string('last_backup_status')->nullable();
            $table->timestamp('last_good_backup_at')->nullable();
            $table->timestamp('backup_snapshots_listed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropIndex(['backup_configured_at']);
            $table->dropColumn([
                'backup_rest_username',
                'backup_rest_password',
                'backup_repository_password',
                'backup_configured_at',
                'last_backup_at',
                'last_backup_status',
                'last_good_backup_at',
                'backup_snapshots_listed_at',
            ]);
        });
    }
};
