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
        Schema::table('device_metrics', function (Blueprint $table) {
            $table->float('cpu_user')->nullable()->after('cpu');
            $table->float('cpu_system')->nullable()->after('cpu_user');
            $table->float('cpu_nice')->nullable()->after('cpu_system');
            $table->float('cpu_iowait')->nullable()->after('cpu_nice');
            $table->float('cpu_irq')->nullable()->after('cpu_iowait');
            $table->float('cpu_softirq')->nullable()->after('cpu_irq');
            $table->float('cpu_steal')->nullable()->after('cpu_softirq');
            $table->float('cpu_idle')->nullable()->after('cpu_steal');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'cpu_user',
                'cpu_system',
                'cpu_nice',
                'cpu_iowait',
                'cpu_irq',
                'cpu_softirq',
                'cpu_steal',
                'cpu_idle',
            ]);
        });
    }
};
