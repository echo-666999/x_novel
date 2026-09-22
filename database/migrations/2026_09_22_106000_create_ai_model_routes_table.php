<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_routes', function (Blueprint $table): void {
            $table->id();
            $table->string('role', 64)->unique();
            $table->string('provider', 64);
            $table->string('model');
            $table->timestamps();

            $table->index(['provider', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_routes');
    }
};
