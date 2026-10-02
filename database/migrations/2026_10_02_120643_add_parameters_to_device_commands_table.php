<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_commands', function (Blueprint $table): void {
            $table->json('parameters')->nullable()->after('script_type');
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', function (Blueprint $table): void {
            $table->dropColumn('parameters');
        });
    }
};
