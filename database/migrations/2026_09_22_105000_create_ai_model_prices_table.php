<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->string('model');
            $table->char('currency', 3)->default('USD');
            $table->unsignedBigInteger('billing_unit')->default(1_000_000);
            $table->decimal('input_price', 24, 12)->nullable();
            $table->decimal('cached_input_price', 24, 12)->nullable();
            $table->decimal('output_price', 24, 12)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['provider', 'model', 'currency'], 'ai_model_prices_identity_unique');
            $table->index(['provider', 'model', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_prices');
    }
};
