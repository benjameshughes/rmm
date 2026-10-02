<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            $table->foreignId('scheduled_task_id')->nullable()->after('script_id')->constrained('scheduled_tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scheduled_task_id');
        });
    }
};
