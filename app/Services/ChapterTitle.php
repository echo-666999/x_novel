<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class ChapterTitle
{
    public static function format(int $sequence, string $subtitle): string
    {
        $subtitle = trim($subtitle);
        // 模型偶尔把序号也写进章名；持久化时统一只保留一个序号。
        $subtitle = preg_replace('/^第[零一二三四五六七八九十百千万两\d\s]+章[\s·:：、.-]*/u', '', $subtitle) ?? $subtitle;
        if ($subtitle === '' || mb_strlen($subtitle) > 80) {
            throw ValidationException::withMessages(['chapter_title' => '章节标题必须包含不超过 80 字的具体章名，不能只有章节序号。']);
        }

        return '第'.self::chineseNumber($sequence).'章 '.$subtitle;
    }

    private static function chineseNumber(int $number): string
    {
        if ($number < 1 || $number > 9999) {
            return (string) $number;
        }

        $digits = ['零', '一', '二', '三', '四', '五', '六', '七', '八', '九'];
        $units = ['', '十', '百', '千'];
        $result = '';
        $pendingZero = false;
        foreach (str_split((string) $number) as $index => $digit) {
            $place = strlen((string) $number) - $index - 1;
            if ($digit === '0') {
                $pendingZero = $result !== '';

                continue;
            }
            if ($pendingZero) {
                $result .= '零';
                $pendingZero = false;
            }
            $result .= $digits[(int) $digit].$units[$place];
        }

        return str_starts_with($result, '一十') ? mb_substr($result, 1) : $result;
    }
}
