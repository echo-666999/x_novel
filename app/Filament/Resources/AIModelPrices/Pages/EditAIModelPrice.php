<?php

namespace App\Filament\Resources\AIModelPrices\Pages;

use App\Filament\Resources\AIModelPrices\AIModelPriceResource;
use App\Models\AIModelPrice;
use Filament\Resources\Pages\EditRecord;

class EditAIModelPrice extends EditRecord
{
    protected static string $resource = AIModelPriceResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! collect(['input_price', 'cached_input_price', 'output_price'])->contains(fn (string $field): bool => filled($data[$field] ?? null))) {
            $this->addError('data.input_price', '至少填写一种 Token 价格。');
            $this->halt();
        }

        $duplicate = AIModelPrice::query()
            ->where('provider', $data['provider'])
            ->where('model', $data['model'])
            ->where('currency', $data['currency'])
            ->whereKeyNot($this->getRecord()->getKey())
            ->exists();

        if ($duplicate) {
            $this->addError('data.model', '该供应商、模型和币种的价格已经存在。');
            $this->halt();
        }

        return $data;
    }
}
