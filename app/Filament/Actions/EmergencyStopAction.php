<?php

namespace App\Filament\Actions;

use App\Services\EmergencyStopService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class EmergencyStopAction
{
    public static function make(): Action
    {
        return Action::make('emergencyStop')
            ->label(fn (): string => app(EmergencyStopService::class)->isActive() ? '解除紧急停止' : '紧急停止')
            ->icon(fn (): string => app(EmergencyStopService::class)->isActive() ? 'heroicon-o-play' : 'heroicon-o-stop-circle')
            ->color(fn (): string => app(EmergencyStopService::class)->isActive() ? 'warning' : 'danger')
            ->requiresConfirmation()
            ->modalHeading(fn (): string => app(EmergencyStopService::class)->isActive() ? '解除紧急停止' : '确认紧急停止')
            ->modalDescription(fn (): string => app(EmergencyStopService::class)->isActive()
                ? '解除后允许新的模型请求与 Canonical Commit，请确认故障或风险已经排除。'
                : '启用后将立即阻止新的模型请求与 Canonical Commit。已发出的请求仍可保存 Draft / Artifact。')
            ->modalSubmitActionLabel(fn (): string => app(EmergencyStopService::class)->isActive() ? '确认解除' : '确认紧急停止')
            ->action(function (EmergencyStopService $emergencyStop): void {
                $active = ! $emergencyStop->isActive();
                $emergencyStop->setActive($active);

                Notification::make()
                    ->title($active ? '紧急停止已启用' : '紧急停止已解除')
                    ->body($active ? '新的 Provider Request 与 Canonical Commit 已被阻止。' : '生成安全闸门已恢复。')
                    ->color($active ? 'danger' : 'success')
                    ->icon($active ? 'heroicon-o-stop-circle' : 'heroicon-o-check-circle')
                    ->send();
            });
    }
}
