<?php

namespace App\Services;

use App\Data\NormalizedNovelOutline;
use App\Models\NovelOutline;

/**
 * 基于稳定业务 DTO 计算大纲版本校验和。
 */
class NovelOutlineChecksum
{
    /**
     * 计算不受数据库 ID、状态和时间戳影响的校验和。
     *
     * @param  array<string, mixed>|NormalizedNovelOutline|NovelOutline  $outline
     */
    public function for(array|NormalizedNovelOutline|NovelOutline $outline): string
    {
        $data = match (true) {
            $outline instanceof NovelOutline => NormalizedNovelOutline::fromModel($outline)->toArray(),
            $outline instanceof NormalizedNovelOutline => $outline->toArray(),
            is_array($outline) && array_key_exists('volumes', $outline) => NormalizedNovelOutline::fromArray($outline)->toArray(),
            default => $outline,
        };

        // Checksum 只覆盖稳定业务 DTO，不包含数据库 ID、时间戳、状态或运行进度。
        return hash('sha256', json_encode(
            $this->canonicalize($data),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * 递归排序关联数组键，消除无业务意义的键顺序差异。
     */
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
