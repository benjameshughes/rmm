<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What on the device an alert is about when a device can have several at
     * once, such as the printer behind a printer problem alert.
     */
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->string('subject')->nullable()->after('metric');
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->dropColumn('subject');
        });
    }
};
