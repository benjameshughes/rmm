<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every device enrolled so far runs the full Windows agent, so none of them is monitor only.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->boolean('is_monitor_only')->nullable()->after('agent_version');
        });

        DB::table('devices')->update(['is_monitor_only' => false]);
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('is_monitor_only');
        });
    }
};
