<?php

namespace App\Filament\Forms\Components;

use App\Models\Character;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CharacterPicker extends NovelEntityPicker
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->placeholder('搜索当前小说角色');
    }

    protected function entityQuery(): Builder
    {
        return Character::query();
    }

    protected function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $query) use ($search): void {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('role', 'like', "%{$search}%");
        });
    }

    protected function optionLabel(Model $record): string
    {
        /** @var Character $record */
        return "{$record->name} · {$record->role}";
    }
}
