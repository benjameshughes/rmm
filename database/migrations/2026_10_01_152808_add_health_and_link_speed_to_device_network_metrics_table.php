<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_network_metrics', function (Blueprint $table): void {
            $table->float('errors_inbound')->nullable();
            $table->float('errors_outbound')->nullable();
            $table->float('drops_inbound')->nullable();
            $table->float('drops_outbound')->nullable();
            $table->unsignedBigInteger('link_speed_kbps')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_network_metrics', function (Blueprint $table): void {
            $table->dropColumn(['errors_inbound', 'errors_outbound', 'drops_inbound', 'drops_outbound', 'link_speed_kbps']);
        });
    }
};
