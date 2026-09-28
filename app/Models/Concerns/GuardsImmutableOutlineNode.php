<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * 禁止单独修改或删除已落库的大纲子节点，修订必须创建新版本。
 */
trait GuardsImmutableOutlineNode
{
    /**
     * 注册不可变保护；版本头级联清理仍由数据库负责。
     */
    protected static function bootGuardsImmutableOutlineNode(): void
    {
        static::updating(function (): never {
            // Outline 节点一旦保存就会被 Plan 和 Canonical Event 引用，修订必须创建完整新版本。
            throw new LogicException('Novel Outline nodes are immutable; create a new Outline Version instead.');
        });

        static::deleting(function (): never {
            // 正常清理由数据库随版本头级联完成，禁止绕过版本边界单独删除节点。
            throw new LogicException('Novel Outline nodes cannot be deleted individually.');
        });
    }
}
