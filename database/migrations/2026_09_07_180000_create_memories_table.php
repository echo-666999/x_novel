<?php

use App\Enums\MemoryStatus;
use App\Enums\MemoryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->text('summary');
            $table->jsonb('entities')->default('{}');
            $table->decimal('salience', 4, 3);
            $table->string('status')->default(MemoryStatus::Active->value);
            $table->string('embedding_model')->nullable();
            $table->unsignedInteger('valid_from_chapter');
            $table->unsignedInteger('valid_to_chapter')->nullable();
            $table->timestamps();

            $table->index(['novel_id', 'status', 'type']);
            $table->index(['novel_id', 'source_type', 'source_id']);
            $table->index(['novel_id', 'valid_from_chapter', 'valid_to_chapter']);
            $table->index('embedding_model');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

            $table = DB::connection()->getSchemaGrammar()->wrapTable('memories');
            $embedding = DB::connection()->getQueryGrammar()->wrap('embedding');
            $dimensions = max(1, (int) config('ai.embedding.dimensions', 1536));
            $types = $this->quotedValues(MemoryType::cases());
            $statuses = $this->quotedValues(MemoryStatus::cases());

            DB::statement("ALTER TABLE {$table} ADD COLUMN {$embedding} vector({$dimensions}) NULL");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT memories_type_check CHECK (type IN ({$types}))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT memories_status_check CHECK (status IN ({$statuses}))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT memories_salience_check CHECK (salience >= 0 AND salience <= 1)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT memories_validity_check CHECK (valid_from_chapter > 0 AND (valid_to_chapter IS NULL OR valid_to_chapter >= valid_from_chapter))");
        } else {
            Schema::table('memories', function (Blueprint $table) {
                $table->text('embedding')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('memories');
    }

    /** @param array<int, BackedEnum> $cases */
    private function quotedValues(array $cases): string
    {
        return collect($cases)
            ->map(fn (BackedEnum $case): string => DB::connection()->getPdo()->quote($case->value))
            ->implode(', ');
    }
};
