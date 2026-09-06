<?php

namespace App\Filament\Forms\Components;

use App\Models\Novel;
use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class NovelEntityPicker extends Select
{
    protected Novel|int|Closure|null $novel = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->searchable()
            ->preload()
            ->options(fn (): array => $this->pickerOptions())
            ->getSearchResultsUsing(fn (string $search): array => $this->pickerOptions($search))
            ->getOptionLabelUsing(fn ($value): ?string => $this->labelForKey($value))
            ->getOptionLabelsUsing(fn (array $values): array => $this->labelsForKeys($values));
    }

    public function novel(Novel|int|Closure $novel): static
    {
        $this->novel = $novel;

        return $this;
    }

    abstract protected function entityQuery(): Builder;

    abstract protected function applySearch(Builder $query, string $search): void;

    abstract protected function optionLabel(Model $record): string;

    protected function novelId(): int
    {
        $novel = $this->evaluate($this->novel);

        if ($novel instanceof Novel) {
            return (int) $novel->getKey();
        }

        if (is_int($novel) || (is_string($novel) && ctype_digit($novel))) {
            return (int) $novel;
        }

        throw new LogicException(static::class.' requires a current novel via novel().');
    }

    /** @return array<int, string> */
    private function pickerOptions(?string $search = null): array
    {
        $query = $this->entityQuery()
            ->where('novel_id', $this->novelId())
            ->orderBy('name');

        if (filled($search)) {
            $this->applySearch($query, $search);
        }

        return $query
            ->limit($this->getOptionsLimit())
            ->get()
            ->mapWithKeys(fn (Model $record): array => [
                $record->getKey() => $this->optionLabel($record),
            ])
            ->all();
    }

    private function labelForKey(mixed $key): ?string
    {
        if (blank($key)) {
            return null;
        }

        $record = $this->entityQuery()
            ->where('novel_id', $this->novelId())
            ->find($key);

        return $record ? $this->optionLabel($record) : null;
    }

    /**
     * @param  array<int|string>  $keys
     * @return array<int|string, string>
     */
    private function labelsForKeys(array $keys): array
    {
        return $this->entityQuery()
            ->where('novel_id', $this->novelId())
            ->whereKey($keys)
            ->get()
            ->mapWithKeys(fn (Model $record): array => [
                $record->getKey() => $this->optionLabel($record),
            ])
            ->all();
    }
}
