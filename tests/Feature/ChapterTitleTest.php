<?php

use App\Services\ChapterTitle;
use Illuminate\Validation\ValidationException;

test('chapter titles use one Chinese sequence and require a distinct subtitle', function () {
    expect(ChapterTitle::format(1, '在未央宫中苏醒'))->toBe('第一章 在未央宫中苏醒')
        ->and(ChapterTitle::format(12, '第12章 宫墙外的急报'))->toBe('第十二章 宫墙外的急报')
        ->and(ChapterTitle::format(101, '旧事重提'))->toBe('第一百零一章 旧事重提');

    expect(fn () => ChapterTitle::format(2, '第二章'))->toThrow(ValidationException::class);
});
