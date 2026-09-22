<?php

namespace App\Services;

class NovelOutlineChecksum
{
    /** @param array<string, mixed> $content */
    public function for(array $content): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($content),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
