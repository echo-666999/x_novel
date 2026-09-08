<?php

namespace App\Data;

final readonly class SystemHealthCheck
{
    public function __construct(
        public string $key,
        public string $label,
        public string $status,
        public string $detail,
    ) {}

    public function statusLabel(): string
    {
        return match ($this->status) {
            'healthy' => '正常',
            'warning' => '需要处理',
            'manual' => '需人工验证',
            default => '未知',
        };
    }
}
