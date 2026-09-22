<?php

namespace App\Filament\Resources\AIModelPrices\Pages;

use App\Filament\Resources\AIModelPrices\AIModelPriceResource;
use App\Models\AIModelPrice;
use Filament\Resources\Pages\CreateRecord;

class CreateAIModelPrice extends CreateRecord
{
    protected static string $resource = AIModelPriceResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->ensureAtLeastOnePrice($data);
        $this->ensureUniqueIdentity($data);

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function ensureAtLeastOnePrice(array $data): void
    {
        if (collect(['input_price', 'cached_input_price', 'output_price'])->contains(fn (string $field): bool => filled($data[$field] ?? null))) {
            return;
        }

        $this->addError('data.input_price', '至少填写一种 Token 价格。');
        $this->halt();
    }

    /** @param array<string, mixed> $data */
    private function ensureUniqueIdentity(array $data): void
    {
        $exists = AIModelPrice::query()
            ->where('provider', $data['provider'])
            ->where('model', $data['model'])
            ->where('currency', $data['currency'])
            ->exists();

        if (! $exists) {
            return;
        }

        $this->addError('data.model', '该供应商、模型和币种的价格已经存在。');
        $this->halt();
    }
}
