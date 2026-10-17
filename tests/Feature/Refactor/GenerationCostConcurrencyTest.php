<?php

use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\RequestUsageLedger;
use App\Models\AIModelPrice;
use App\Models\SystemSetting;
use App\Support\IsolatedTestEnvironment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** 真正的两个数据库会话争用同一金额授权行，不能用顺序调用冒充并发。 */
test('concurrent requests serialize cap reservation across postgres connections', function () {
    expect(DB::scalar('SELECT current_database()'))->toBe(IsolatedTestEnvironment::DATABASE);
    Artisan::call('migrate:fresh', ['--force' => true]);
    $setting = SystemSetting::findOrFail(SystemSetting::GENERATION);
    $data = $setting->value;
    $data['budget'] = ['daily_hard_limit_enabled' => true, 'daily_hard_limit' => '0.50'];
    $setting->update(['value' => $data]);
    AIModelPrice::create(['provider' => 'openai', 'model' => 'concurrent-model', 'currency' => 'USD', 'billing_unit' => 1000,
        'input_price' => 1, 'output_price' => 1, 'is_enabled' => true]);
    config(['database.connections.cost_contender' => config('database.connections.pgsql')]);
    $first = DB::connection('pgsql');
    $second = DB::connection('cost_contender');
    $second->statement("SET lock_timeout='100ms'");
    $request = new AiRequest(model: 'concurrent-model', provider: 'openai', prompt: 'x', maxTokens: 100);
    $first->beginTransaction();
    try {
        app(RequestUsageLedger::class)->prepare($request);
        DB::setDefaultConnection('cost_contender');
        expect(fn () => app(RequestUsageLedger::class)->prepare($request))->toThrow(QueryException::class);
        $first->commit();
        expect(fn () => app(RequestUsageLedger::class)->prepare($request))->toThrow(AiProviderException::class);
        expect($second->table('usage_records')->count())->toBe(1);
    } finally {
        DB::setDefaultConnection('pgsql');
        if ($first->transactionLevel()) {
            $first->rollBack();
        }
        DB::purge('cost_contender');
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
});
