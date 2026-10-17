<?php

use App\Filament\Support\GenerationPresentation;

/** 只验证隔离边界与不可信文本，不用数值镜像测试替代浏览器视觉验收。 */
test('visual preview command refuses production environments', function () {
    $this->app['env'] = 'production';
    try {
        $this->artisan('design:preview')->assertFailed();
    } finally {
        $this->app['env'] = 'testing';
    }
});

test('issue evidence text is escaped and unknown statuses remain visibly unknown', function () {
    $html = view('filament.components.foundation.issue-summary', ['title' => '测试', 'status' => 'unknown-state',
        'message' => '<script>alert(1)</script>', 'impact' => '影响', 'suggestion' => '先查看证据'])->render();
    expect($html)->not->toContain('<script>')->toContain('&lt;script&gt;', '状态尚未确认');
    expect(GenerationPresentation::amount(null, 'USD'))->toBe('待确认');
    expect(GenerationPresentation::amount('0.000001', 'USD'))->toBe('< USD 0.0001');
});
