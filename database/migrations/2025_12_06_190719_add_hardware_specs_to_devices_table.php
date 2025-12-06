<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('os_name')->nullable()->after('os');
            $table->string('os_version')->nullable()->after('os_name');
            $table->string('cpu_model')->nullable()->after('os_version');
            $table->integer('cpu_cores')->nullable()->after('cpu_model');
            $table->decimal('total_ram_gb', 10, 2)->nullable()->after('cpu_cores');
            $table->json('disks')->nullable()->after('total_ram_gb');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn([
                'os_name',
                'os_version',
                'cpu_model',
                'cpu_cores',
                'total_ram_gb',
                'disks',
            ]);
        });
    }
};
