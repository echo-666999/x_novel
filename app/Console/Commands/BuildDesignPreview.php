<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/** 生成不含业务数据的静态夹具；不注册生产路由、不连接模型、不建立测试小说。 */
class BuildDesignPreview extends Command
{
    protected $signature = 'design:preview';

    protected $description = '仅在开发/测试环境构建 R01 静态视觉预览';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('视觉夹具只能在 local/testing 环境构建。');

            return self::FAILURE;
        }
        $manifest = json_decode(File::get(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $css = File::get(public_path('build/'.$manifest['resources/css/filament/x/theme.css']['file']));
        $script = File::get(resource_path('js/display-preferences.js'));
        $directory = storage_path('app/private/design-preview');
        File::ensureDirectoryExists($directory);
        foreach (['create', 'workbench', 'reader', 'issues'] as $page) {
            File::put($directory.'/'.$page.'.html', view('filament.previews.foundation', compact('page', 'css', 'script'))->render());
        }
        $this->info('已生成四页静态示例：'.$directory.'；没有生产路由或业务写入。');

        return self::SUCCESS;
    }
}
