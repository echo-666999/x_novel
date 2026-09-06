<?php

use App\Enums\FactHardness;
use App\Enums\FactSourceType;
use App\Enums\FactStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('predicate');
            $table->jsonb('value');
            $table->string('hardness')->default(FactHardness::Hard->value);
            $table->decimal('confidence', 5, 4)->default(1);
            $table->string('status')->default(FactStatus::Active->value);
            $table->boolean('locked')->default(false);
            $table->string('source_type');
            $table->unsignedBigInteger('source_event_id')->nullable();
            $table->timestamps();

            $table->index(['novel_id', 'subject_type', 'subject_id']);
            $table->index(['novel_id', 'predicate']);
            $table->index(['novel_id', 'locked']);
            $table->index(['novel_id', 'status']);
            $table->index(['novel_id', 'source_type']);
            $table->index('source_event_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('facts');
            $hardnesses = $this->quotedValues(FactHardness::cases());
            $statuses = $this->quotedValues(FactStatus::cases());
            $sourceTypes = $this->quotedValues(FactSourceType::cases());

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT facts_hardness_check CHECK (hardness IN ({$hardnesses}))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT facts_status_check CHECK (status IN ({$statuses}))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT facts_source_type_check CHECK (source_type IN ({$sourceTypes}))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT facts_confidence_check CHECK (confidence >= 0 AND confidence <= 1)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT facts_subject_id_check CHECK (subject_id > 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('facts');
    }

    /** @param array<int, BackedEnum> $cases */
    private function quotedValues(array $cases): string
    {
        return collect($cases)
            ->map(fn (BackedEnum $case): string => DB::connection()->getPdo()->quote($case->value))
            ->implode(', ');
    }
};
