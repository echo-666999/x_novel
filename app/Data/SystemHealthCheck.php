<?php

namespace App\Data;

final readonly class SystemHealthCheck
{
    public function __construct(
        public string $key,
        public string $label,
        public string $status,
        public string $detail,
        // 健康观测带时间，配置存在与服务在线不能混为一谈。
        public ?string $observedAt = null,
    ) {}

    public function statusLabel(): string
    {
        return match ($this->status) {
            'healthy' => '正常',
            'warning' => '需要处理',
            'manual' => '未验证运行状态',
            'stale' => '观测已过期',
            default => '未知',
        };
    }
}
