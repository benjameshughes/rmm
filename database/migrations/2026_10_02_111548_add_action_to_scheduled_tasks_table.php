<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every schedule before this ran a script, so existing rows are backfilled
     * before the column becomes required. Wake schedules have no script.
     */
    public function up(): void
    {
        Schema::table('scheduled_tasks', function (Blueprint $table) {
            $table->string('action')->nullable()->after('name');
        });

        DB::table('scheduled_tasks')->update(['action' => 'run_script']);

        Schema::table('scheduled_tasks', function (Blueprint $table) {
            $table->string('action')->nullable(false)->change();
            $table->unsignedBigInteger('script_id')->nullable()->change();
        });
    }

    /**
     * Wake schedules cannot exist without the action column, so they are removed first.
     */
    public function down(): void
    {
        DB::table('scheduled_tasks')->whereNull('script_id')->delete();

        Schema::table('scheduled_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('script_id')->nullable(false)->change();
            $table->dropColumn('action');
        });
    }
};
