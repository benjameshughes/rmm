<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEXT stops at 64 KB on MySQL and MariaDB, but the agent may send up to a
 * million characters, and a software inventory of a few hundred packages
 * comes close to the old limit on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_commands', function (Blueprint $table): void {
            $table->mediumText('output')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', function (Blueprint $table): void {
            $table->text('output')->nullable()->change();
        });
    }
};
