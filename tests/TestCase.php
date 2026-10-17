<?php

namespace Tests;

use App\AI\Contracts\AiProvider;
use App\AI\Contracts\EmbeddingProvider;
use App\AI\Providers\FakeAiProvider;
use App\AI\Providers\FakeEmbeddingProvider;
use App\Support\IsolatedTestEnvironment;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /** 必须在 RefreshDatabase 等 trait 执行之前检查目标，拒绝配置缓存绕回开发库。 */
    public function createApplication()
    {
        $app = parent::createApplication();
        $name = config('database.default');
        (new IsolatedTestEnvironment)->assertSafe(
            config('database.connections.'.$name),
            $app->environment(),
            (string) config('database.redis.options.prefix'),
            (string) config('queue.default'),
        );
        // 所有自动测试默认禁止真实 HTTP；具体测试显式提供固定响应。
        Http::preventStrayRequests();
        // 业务测试默认使用项目已有固定响应 Provider，真实适配器测试需显式构造并伪造 HTTP。
        $app->instance(AiProvider::class, new FakeAiProvider);
        $app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);

        return $app;
    }
}
