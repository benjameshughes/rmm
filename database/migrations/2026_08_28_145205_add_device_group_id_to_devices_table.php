<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('device_group_id')->nullable()->after('status')->constrained('device_groups')->nullOnDelete();
            $table->index('device_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['device_group_id']);
            $table->dropColumn('device_group_id');
        });
    }
};
