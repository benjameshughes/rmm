<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->timestamp('virtual_printer_seen_at')->nullable()->index();
            $table->timestamp('virtual_printer_missing_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropIndex(['virtual_printer_seen_at']);
            $table->dropColumn(['virtual_printer_seen_at', 'virtual_printer_missing_since']);
        });
    }
};
