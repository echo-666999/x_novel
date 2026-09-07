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

    public function getLabel(): string
    {
        return str($this->value)->headline()->toString();
    }
}
