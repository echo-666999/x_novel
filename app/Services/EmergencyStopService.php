<?php

namespace App\Services;

use App\AI\Exceptions\AiProviderException;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmergencyStopService
{
    public const SETTING_KEY = 'emergency_stop';

    public function isActive(bool $lockForUpdate = false): bool
    {
        $query = SystemSetting::query()->whereKey(self::SETTING_KEY);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail()->value === true;
    }

    public function setActive(bool $active): void
    {
        DB::transaction(function () use ($active): void {
            $setting = SystemSetting::query()->lockForUpdate()->findOrFail(self::SETTING_KEY);
            $setting->update(['value' => $active]);
        });
    }

    public function assertProviderRequestsAllowed(): void
    {
        if ($this->isActive()) {
            throw new AiProviderException('emergency_stop', '系统已启用紧急停止，新的 Provider Request 已被阻止。', false);
        }
    }

    public function assertCanonicalCommitAllowed(): void
    {
        if ($this->isActive(lockForUpdate: true)) {
            throw ValidationException::withMessages([
                'emergency_stop' => '系统已启用紧急停止，Canonical Commit 已被阻止。',
            ]);
        }
    }
}
