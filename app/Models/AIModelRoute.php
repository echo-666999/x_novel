<?php

namespace App\Models;

use App\Enums\AiStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['role', 'provider', 'model'])]
class AIModelRoute extends Model
{
    protected $table = 'ai_model_routes';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => AiStage::class];
    }
}
