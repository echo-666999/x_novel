<?php

use Symfony\Component\Process\Process;

test('the narrative default target platform is fanqie when the environment value is absent', function () {
    expect(config('narrative.default_platform'))->toBe('fanqie');
});

test('the narrative default target platform survives config cache', function () {
    $cachePath = app()->getCachedConfigPath();
    $cache = new Process(
        [PHP_BINARY, 'artisan', 'config:cache', '--no-ansi'],
        base_path(),
        ['NARRATIVE_DEFAULT_TARGET_PLATFORM' => 'qimao'],
    );
    $clear = new Process([PHP_BINARY, 'artisan', 'config:clear', '--no-ansi'], base_path());

    try {
        $cache->mustRun();
        $cachedConfig = require $cachePath;

        expect(data_get($cachedConfig, 'narrative.default_platform'))->toBe('qimao');
    } finally {
        $clear->mustRun();
    }
});
