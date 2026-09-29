<?php

namespace App\Services;

use App\Models\Novel;
use Illuminate\Validation\ValidationException;

final class TargetPlatformResolver
{
    /**
     * @return array{code: string, label: string, source: string}
     */
    public function forNovel(Novel $novel): array
    {
        $bible = $novel->relationLoaded('currentBible')
            ? $novel->currentBible
            : $novel->currentBible()->first();

        if ($bible !== null) {
            return $this->selection(
                data_get($bible->style_profile, 'target_platform'),
                'current_bible',
                'Current Bible 的目标平台无效，请先创建包含有效目标平台的新版本。',
                'style_profile.target_platform',
            );
        }

        return $this->defaultSelection();
    }

    /**
     * @return array{code: string, label: string, source: string}
     */
    public function defaultSelection(): array
    {
        return $this->selection(
            config('narrative.default_platform'),
            'default_config',
            'NARRATIVE_DEFAULT_TARGET_PLATFORM 配置无效，请设置为 narrative.platforms 中的有效 key。',
            'narrative.default_platform',
        );
    }

    public function defaultCodeOrNull(): ?string
    {
        try {
            return $this->defaultSelection()['code'];
        } catch (ValidationException) {
            // 手工 Bible 表单必须保持可用，让用户可以选择有效平台修复错误配置。
            return null;
        }
    }

    /**
     * @return array{code: string, label: string, source: string}
     */
    private function selection(mixed $code, string $source, string $message, string $errorKey): array
    {
        $platforms = config('narrative.platforms', []);

        if (! is_string($code) || ! array_key_exists($code, $platforms)) {
            throw ValidationException::withMessages([$errorKey => $message]);
        }

        return [
            'code' => $code,
            'label' => $platforms[$code],
            'source' => $source,
        ];
    }
}
