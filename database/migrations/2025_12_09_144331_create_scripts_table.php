<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scripts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category');
            $table->string('platform');
            $table->string('script_type');
            $table->text('script_content');
            $table->boolean('is_system')->default(false);
            $table->integer('timeout_seconds')->default(300);
            $table->boolean('requires_admin')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category', 'platform']);
            $table->index('is_system');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scripts');
    }
};
