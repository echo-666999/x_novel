<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64)->unique();
            $table->string('name');
            $table->string('base_url', 2048);
            $table->text('api_key')->nullable();
            $table->unsignedSmallInteger('connect_timeout')->default(10);
            $table->unsignedSmallInteger('timeout')->default(60);
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->index(['is_enabled', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_connections');
    }
};
