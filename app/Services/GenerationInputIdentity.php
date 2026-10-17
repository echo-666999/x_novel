<?php

namespace App\Services;

/** 对象键稳定排序而保持列表顺序，JSONB 往返不会改变语义身份。 */
final class GenerationInputIdentity
{
    public function hash(array $input): string
    {
        return hash('sha256', json_encode($this->normalize($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
    }
}
