<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value', 'policy_history'])]
class SystemSetting extends Model
{
    public const GENERATION = 'generation';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        // 金额策略留痕与生效配置分开，规范化不能丢弃历史。
        return ['value' => 'json', 'policy_history' => 'array'];
    }
}
