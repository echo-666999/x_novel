<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MemoryType: string implements HasLabel
{
    case Event = 'event';
    case CharacterMilestone = 'character_milestone';
    case Relationship = 'relationship';
    case World = 'world';
    case Location = 'location';
    case Item = 'item';
    case Foreshadowing = 'foreshadowing';
    case Arc = 'arc';
    case ReaderPromise = 'reader_promise';
    case ImportantDialogue = 'important_dialogue';

    public function getLabel(): string
    {
        return match ($this) {
            self::Event => '故事事件',
            self::CharacterMilestone => '人物里程碑',
            self::Relationship => '关系变化',
            self::World => '世界设定',
            self::Location => '地点',
            self::Item => '物品',
            self::Foreshadowing => '伏笔',
            self::Arc => '故事线',
            self::ReaderPromise => '读者承诺',
            self::ImportantDialogue => '重要对话',
        };
    }
}
