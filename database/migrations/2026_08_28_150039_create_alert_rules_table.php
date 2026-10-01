<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('metric');
            $table->string('operator');
            $table->float('threshold');
            $table->integer('duration_minutes')->default(5);
            $table->string('severity');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
            $table->index('metric');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
