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
            $table->renameColumn('backup_rest_username', 'backup_repository_name');
            $table->dropColumn('backup_rest_password');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->renameColumn('backup_repository_name', 'backup_rest_username');
            $table->text('backup_rest_password')->nullable();
        });
    }
};
