<?php

namespace App\Filament\Forms\Components;

use App\Enums\WorldEntityType;
use App\Models\WorldEntity;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WorldEntityPicker extends NovelEntityPicker
{
    /** @var array<WorldEntityType|string> | Closure | null */
    protected array|Closure|null $types = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->placeholder('搜索当前小说世界实体');
    }

    /** @param array<WorldEntityType|string> | Closure $types */
    public function types(array|Closure $types): static
    {
        $this->types = $types;

        return $this;
    }

    protected function entityQuery(): Builder
    {
        $query = WorldEntity::query();
        $types = $this->evaluate($this->types);

        if (filled($types)) {
            $query->whereIn('type', collect($types)
                ->map(fn (WorldEntityType|string $type): string => $type instanceof WorldEntityType ? $type->value : $type)
                ->all());
        }

        return $query;
    }

    protected function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $query) use ($search): void {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        });
    }

    protected function optionLabel(Model $record): string
    {
        /** @var WorldEntity $record */
        return "{$record->name} · {$record->type->getLabel()}";
    }
}
