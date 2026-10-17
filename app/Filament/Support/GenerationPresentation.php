<?php

namespace App\Filament\Support;

/** 只翻译持久领域状态，不在浏览器创造第二套任务状态机。 */
final class GenerationPresentation
{
    public static function status(string $status): array
    {
        return match ($status) {
            'queued' => ['label' => '等待执行', 'tone' => 'neutral', 'icon' => 'heroicon-o-clock'],
            'running' => ['label' => '生成中', 'tone' => 'info', 'icon' => 'heroicon-o-arrow-path'],
            'open' => ['label' => '待恢复或提醒', 'tone' => 'warning', 'icon' => 'heroicon-o-information-circle'],
            'awaiting_user' => ['label' => '待你处理', 'tone' => 'warning', 'icon' => 'heroicon-o-exclamation-triangle'],
            'diagnosing' => ['label' => 'AI 分析中', 'tone' => 'info', 'icon' => 'heroicon-o-sparkles'],
            'repairing' => ['label' => '自动处理中', 'tone' => 'info', 'icon' => 'heroicon-o-wrench-screwdriver'],
            'paused' => ['label' => '已暂停', 'tone' => 'neutral', 'icon' => 'heroicon-o-pause'],
            'needs_attention' => ['label' => '需要你选择', 'tone' => 'warning', 'icon' => 'heroicon-o-exclamation-triangle'],
            'completed', 'resolved' => ['label' => $status === 'resolved' ? '已解决' : '已完成', 'tone' => 'success', 'icon' => 'heroicon-o-check-circle'],
            'failed', 'blocked' => ['label' => '暂时无法继续', 'tone' => 'danger', 'icon' => 'heroicon-o-x-circle'],
            'cancelled' => ['label' => '已取消', 'tone' => 'neutral', 'icon' => 'heroicon-o-stop'],
            'draft' => ['label' => '草稿 · 尚未正式保存', 'tone' => 'warning', 'icon' => 'heroicon-o-document-text'],
            'canonical' => ['label' => '正式正文', 'tone' => 'success', 'icon' => 'heroicon-o-check-badge'],
            default => ['label' => '状态尚未确认', 'tone' => 'neutral', 'icon' => 'heroicon-o-question-mark-circle'],
        };
    }

    public static function amount(?string $amount, string $currency): string
    {
        if ($amount === null) {
            return '待确认';
        }

        // 小额费用保留非零含义，不能格式化成“免费”。
        return (float) $amount > 0 && (float) $amount < 0.0001 ? '< '.$currency.' 0.0001' : $currency.' '.number_format((float) $amount, 4);
    }
}
