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
        Schema::table('devices', function (Blueprint $table) {
            $table->string('netdata_version')->nullable()->after('last_ip');
            $table->string('kernel_name')->nullable()->after('netdata_version');
            $table->string('kernel_version')->nullable()->after('kernel_name');
            $table->string('architecture')->nullable()->after('kernel_version');
            $table->string('virtualization')->nullable()->after('architecture');
            $table->string('container')->nullable()->after('virtualization');
            $table->boolean('is_k8s_node')->default(false)->after('container');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'netdata_version',
                'kernel_name',
                'kernel_version',
                'architecture',
                'virtualization',
                'container',
                'is_k8s_node',
            ]);
        });
    }
};
