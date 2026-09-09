<?php

namespace App\Enums;

enum EventType: string
{
    case CharacterStatusChanged = 'character_status_changed';
    case CharacterMoved = 'character_moved';
    case CharacterInjured = 'character_injured';
    case CharacterRecovered = 'character_recovered';
    case CharacterGoalChanged = 'character_goal_changed';
    case CharacterEmotionChanged = 'character_emotion_changed';
    case CharacterLearned = 'character_learned';
    case CharacterForgot = 'character_forgot';
    case CharacterAbilityAcquired = 'character_ability_acquired';
    case CharacterAbilityChanged = 'character_ability_changed';
    case RelationshipChanged = 'relationship_changed';
    case PromiseMade = 'promise_made';
    case PromiseBroken = 'promise_broken';
    case DebtCreated = 'debt_created';
    case DebtResolved = 'debt_resolved';
    case ItemAcquired = 'item_acquired';
    case ItemTransferred = 'item_transferred';
    case ItemLost = 'item_lost';
    case ItemDestroyed = 'item_destroyed';
    case ItemStateChanged = 'item_state_changed';
    case ConflictStarted = 'conflict_started';
    case ConflictEscalated = 'conflict_escalated';
    case ConflictResolved = 'conflict_resolved';
    case ThreadOpened = 'thread_opened';
    case ThreadProgressed = 'thread_progressed';
    case ThreadClosed = 'thread_closed';
    case ReaderPromiseCreated = 'reader_promise_created';
    case ReaderPromiseResolved = 'reader_promise_resolved';
    case ForeshadowingPlanted = 'foreshadowing_planted';
    case ForeshadowingReinforced = 'foreshadowing_reinforced';
    case ForeshadowingDue = 'foreshadowing_due';
    case ForeshadowingPaidOff = 'foreshadowing_paid_off';
    case ForeshadowingAbandoned = 'foreshadowing_abandoned';
    case WorldRuleRevealed = 'world_rule_revealed';
    case WorldRuleChanged = 'world_rule_changed';
    case LocationStateChanged = 'location_state_changed';
    case FactionStateChanged = 'faction_state_changed';
    case WorldStateChanged = 'world_state_changed';
    case EventCorrected = 'event_corrected';
    case EventInvalidated = 'event_invalidated';
    case ManualCorrection = 'manual_correction';

    /** @return array<int, string> */
    public function allowedSubjectTypes(): array
    {
        return match ($this) {
            self::CharacterStatusChanged,
            self::CharacterMoved,
            self::CharacterInjured,
            self::CharacterRecovered,
            self::CharacterGoalChanged,
            self::CharacterEmotionChanged,
            self::CharacterLearned,
            self::CharacterForgot,
            self::CharacterAbilityAcquired,
            self::CharacterAbilityChanged => ['character'],

            self::RelationshipChanged,
            self::PromiseMade,
            self::PromiseBroken,
            self::DebtCreated,
            self::DebtResolved => ['relationship'],

            self::ItemAcquired,
            self::ItemTransferred,
            self::ItemLost,
            self::ItemDestroyed,
            self::ItemStateChanged => ['item'],

            self::ConflictStarted,
            self::ConflictEscalated,
            self::ConflictResolved => ['conflict'],

            self::ThreadOpened,
            self::ThreadProgressed,
            self::ThreadClosed => ['thread'],

            self::ReaderPromiseCreated,
            self::ReaderPromiseResolved => ['reader_promise'],

            self::ForeshadowingPlanted,
            self::ForeshadowingReinforced,
            self::ForeshadowingDue,
            self::ForeshadowingPaidOff,
            self::ForeshadowingAbandoned => ['foreshadowing'],

            self::WorldRuleRevealed,
            self::WorldRuleChanged,
            self::WorldStateChanged => ['world', 'world_entity'],

            self::LocationStateChanged => ['location', 'world_entity'],
            self::FactionStateChanged => ['faction', 'world_entity'],

            self::EventCorrected,
            self::EventInvalidated,
            self::ManualCorrection => [],
        };
    }

    public function getLabel(): string
    {
        return str($this->value)->headline()->toString();
    }
}
