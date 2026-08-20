<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterHistory;
use App\Models\CharacterJournal;
use App\Models\City;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class OrganizedHitService
{
    public const ACCEPT_TTL_SECONDS  = 300;
    public const EXECUTE_TTL_SECONDS = 3600;

    public const STRONG_XP_RATIO  = 1.5;
    public const STRONG_STAT_MULT = 2;
    public const PENALTY_STAT_MULT = 0.8;

    private const WARN_HIGH_RATIO  = 1.5;
    private const WARN_MID_RATIO   = 0.5;
    private const WARN_CHANCE_HIGH = 65;
    private const WARN_CHANCE_MID  = 35;
    private const WARN_CHANCE_LOW  = 15;

    private const CACHE_BUFFER_SECONDS = 600; // 10 min slack so getState's manual expiry check is the canonical truth.
    private const ACTIVE_SET_TTL       = 86400;
    private const ACTIVE_SET_KEY       = 'org_hits_active';

    

    public static function cacheKey(int $initiatorId): string
    {
        return "organized_hit_{$initiatorId}";
    }

    public static function cacheGet(string $key): ?array
    {
        try {
            return Cache::get($key);
        } catch (\Throwable $e) {
            Log::error('[OrganizedHit][Cache] GET failed.', ['key' => $key, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public static function cachePut(string $key, array $data, int $ttlSeconds): bool
    {
        try {
            Cache::put($key, $data, $ttlSeconds);
            return true;
        } catch (\Throwable $e) {
            Log::error('[OrganizedHit][Cache] PUT failed.', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public static function cacheForget(string $key): bool
    {
        try {
            Cache::forget($key);
            return true;
        } catch (\Throwable $e) {
            Log::error('[OrganizedHit][Cache] FORGET failed.', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private static function addToActiveSet(int $initiatorId): void
    {
        $active = Cache::get(self::ACTIVE_SET_KEY, []);
        $active = array_values(array_unique(array_merge($active, [$initiatorId])));
        Cache::put(self::ACTIVE_SET_KEY, $active, self::ACTIVE_SET_TTL);
    }

    private static function removeFromActiveSet(int $initiatorId): void
    {
        $active = array_values(array_filter(
            Cache::get(self::ACTIVE_SET_KEY, []),
            fn ($id) => (int) $id !== $initiatorId
        ));
        Cache::put(self::ACTIVE_SET_KEY, $active, self::ACTIVE_SET_TTL);
    }

    

    public static function getState(int $initiatorId): ?array
    {
        $data = self::cacheGet(self::cacheKey($initiatorId));

        if (! $data) {
            return null;
        }

        if (now()->timestamp > $data['expires_at']) {
            self::collapse($initiatorId, $data, 'expiry');
            return null;
        }

        return $data;
    }

    /**
     * Find the active op a character is currently part of (initiator or invited member).
     * Optionally exclude one initiator so accept() can ask "any OTHER op?".
     * The active set is bounded (concurrent ops at any moment is small), so a
     * linear scan is fine and removes the need for per-character pointer caches.
     */
    public static function findOpForMember(int $characterId, ?int $excludeInitiatorId = null): ?array
    {
        foreach (Cache::get(self::ACTIVE_SET_KEY, []) as $initiatorId) {
            $initiatorId = (int) $initiatorId;
            if ($initiatorId === $excludeInitiatorId) {
                continue;
            }
            $state = self::getState($initiatorId);
            if ($state && in_array($characterId, $state['member_ids'], true)) {
                return $state;
            }
        }
        return null;
    }

    

    public static function initiate(
        Character $initiator,
        Character $target,
        array     $accompliceIds,
        ?string   $customMessage = null
    ): ?string {
        // Cooldown gate — the initiator must have a free conflict timer to
        // begin an op. Without this, a player could spend cooldown‑draining
        // actions on themselves to delay their next attack and still mount
        // an organized hit while "timed out".
        if ($initiator->timers?->next_conflict_at?->isFuture()) {
            return 'You must wait for your timer to be ready before initiating an organized hit.';
        }

        if (self::findOpForMember($initiator->id)) {
            return 'You are already part of an active organized operation.';
        }

        if (count($accompliceIds) !== 2) {
            return 'An organized hit requires exactly 2 accomplices.';
        }

        if (in_array($initiator->id, $accompliceIds, true)) {
            return 'You cannot add yourself as an accomplice.';
        }

        if (in_array($target->id, $accompliceIds, true)) {
            return 'The target cannot also be an accomplice.';
        }

        $accomplices = Character::whereIn('id', array_unique($accompliceIds))
            ->where('city_id', $initiator->city_id)
            ->alive()
            ->get()
            ->keyBy('id');

        if ($accomplices->count() !== 2) {
            return 'Both accomplices must be alive and in your city.';
        }

        $acc0 = $accomplices[$accompliceIds[0]];
        $acc1 = $accomplices[$accompliceIds[1]];

        foreach ([$acc0, $acc1] as $acc) {
            if (self::findOpForMember($acc->id)) {
                return "{$acc->display_name} is already part of another operation.";
            }
        }

        $memberIds   = [$initiator->id, $acc0->id, $acc1->id];
        $memberNames = [
            $initiator->id => $initiator->display_name,
            $acc0->id      => $acc0->display_name,
            $acc1->id      => $acc1->display_name,
        ];

        $allMembers  = collect([$initiator, $acc0, $acc1]);
        // See execute() for rationale: prevents effectiveStats() from
        // lazy-loading the back-reference for every member.
        foreach ($allMembers as $member) {
            $member->stats?->setRelation('character', $member);
        }
        $target->stats?->setRelation('character', $target);
        $memberStats = $allMembers->map(
            fn (Character $c) => $c->stats?->effectiveStats() ?? []
        )->values()->toArray();

        self::maybeWarnTarget($target, $memberStats);

        $now  = now()->timestamp;
        $data = [
            'initiator_id'       => $initiator->id,
            'target_id'          => $target->id,
            'target_name'        => $target->display_name,
            'member_ids'         => $memberIds,
            'member_names'       => $memberNames,
            'accepted_by'        => [$initiator->id],
            'is_ready'           => false,
            'expires_at'         => $now + self::ACCEPT_TTL_SECONDS,
            'execute_expires_at' => null,
            // Initiator's taunt / final words for the target. May be null;
            // execute() falls back to the default kill reason if so.
            'custom_message'     => $customMessage !== null && trim($customMessage) !== ''
                ? mb_substr(trim($customMessage), 0, 200)
                : null,
        ];

        if (! self::cachePut(
            self::cacheKey($initiator->id),
            $data,
            self::ACCEPT_TTL_SECONDS + self::CACHE_BUFFER_SECONDS
        )) {
            return 'Could not initiate the operation. Please try again.';
        }

        self::addToActiveSet($initiator->id);

        // Invite journals are notifications only — no longer used for relational lookup.
        foreach ([$acc0, $acc1] as $accomplice) {
            JournalService::custom($accomplice->id, 'organized_hit_invite', [
                'initiator_id'   => $initiator->id,
                'initiator_name' => $initiator->display_name,
                'target_name'    => $target->display_name,
                'member_names'   => $memberNames,
            ]);
        }

        Log::info('[OrganizedHit] Initiated.', [
            'initiator'   => $initiator->id,
            'target'      => $target->id,
            'accomplices' => [$acc0->id, $acc1->id],
        ]);

        return null;
    }

    public static function accept(Character $accomplice, int $initiatorId): ?string
    {
        if ($accomplice->timers?->next_conflict_at?->isFuture()) {
            return 'You must wait for your timer to be ready before accepting this invite.';
        }

        $state = self::getState($initiatorId);

        if (! $state) {
            return 'This operation no longer exists or has expired.';
        }
        if (! in_array($accomplice->id, $state['member_ids'], true)) {
            return 'You were not invited to this operation.';
        }
        if (in_array($accomplice->id, $state['accepted_by'], true)) {
            return 'You have already accepted.';
        }

        // Validate before mutating: not committed to any other active op.
        if (self::findOpForMember($accomplice->id, excludeInitiatorId: $initiatorId)) {
            return 'You are already committed to another operation.';
        }

        $state['accepted_by'][] = $accomplice->id;
        $allAccepted = count($state['accepted_by']) === count($state['member_ids']);

        if ($allAccepted) {
            $state['is_ready']           = true;
            $state['expires_at']         = now()->timestamp + self::EXECUTE_TTL_SECONDS;
            $state['execute_expires_at'] = now()->timestamp + self::EXECUTE_TTL_SECONDS;
        }

        self::cachePut(
            self::cacheKey($initiatorId),
            $state,
            ($allAccepted ? self::EXECUTE_TTL_SECONDS : max(60, $state['expires_at'] - now()->timestamp))
                + self::CACHE_BUFFER_SECONDS
        );

        // Clear the invite notification — the user has answered it.
        CharacterJournal::where('character_id', $accomplice->id)
            ->whereIn('type', ['organized_hit_invite'])
            ->whereJsonContains('data->initiator_id', $initiatorId)
            ->delete();

        JournalService::custom($initiatorId, 'organized_hit_accepted', [
            'accomplice_name' => $accomplice->display_name,
            'target_name'     => $state['target_name'],
            'all_ready'       => $allAccepted,
        ]);

        Log::info('[OrganizedHit] Accepted.', [
            'accomplice' => $accomplice->id,
            'initiator'  => $initiatorId,
            'is_ready'   => $allAccepted,
        ]);

        return null;
    }

    public static function decline(Character $accomplice, int $initiatorId): void
    {
        $state = self::getState($initiatorId);

        CharacterJournal::where('character_id', $accomplice->id)
            ->whereIn('type', ['organized_hit_invite'])
            ->whereJsonContains('data->initiator_id', $initiatorId)
            ->delete();

        if (! $state) {
            return;
        }

        JournalService::custom($initiatorId, 'organized_hit_declined', [
            'accomplice_name' => $accomplice->display_name,
            'target_name'     => $state['target_name'],
        ]);

        self::collapse($initiatorId, $state, 'declined');

        Log::info('[OrganizedHit] Declined.', [
            'accomplice' => $accomplice->id,
            'initiator'  => $initiatorId,
        ]);
    }

    public static function cancel(Character $initiator): void
    {
        $state = self::getState($initiator->id);

        if (! $state) {
            return;
        }

        self::collapse($initiator->id, $state, 'cancelled');

        Log::info('[OrganizedHit] Cancelled.', ['initiator' => $initiator->id]);
    }

    

   
    public static function execute(Character $initiator): array
    {
       
        $raw = self::cacheGet(self::cacheKey($initiator->id));
        if (! $raw) {
            return ['error' => 'No active operation found.'];
        }
        if (now()->timestamp > ($raw['execute_expires_at'] ?? $raw['expires_at'])) {
            self::collapse($initiator->id, $raw, 'execute_expiry');
            return ['error' => 'The execute window expired. The operation has been called off.'];
        }
        if (! ($raw['is_ready'] ?? false)) {
            return ['error' => 'Your crew has not fully accepted yet.'];
        }
       
        if ($initiator->timers?->next_conflict_at?->isFuture()) {
            return ['error' => 'You must wait for your timer to be ready before executing.'];
        }

        $state = $raw;

        $target = Character::with([
            'stats',
            'items.template',
            'timers',
            'career',
            'corporation.properties',
            'corporation.activeSubsidiaries',
            'homeCity',
            'property',
        ])->find($state['target_id']);

        if (! $target) {
            self::collapse($initiator->id, $state, 'target_unavailable');
            return ['error' => 'The target is no longer available.'];
        }

        $validation = $initiator->canFight($target);
        if (! $validation['valid']) {
            return ['error' => $validation['error']];
        }

        // Eager-load EVERY relation CharacterStats::calculateBonuses() touches.
        // Without this, each Character instance's relation cache is empty and
        // loadMissing() in calculateBonuses fires per-instance: 3 members in
        // the same corp = 3 corp loads, 3 properties loads, 3 activeSubsidiaries
        // loads, 3 career loads. whereIn-with dedupes by id and shares the
        // loaded models across all member instances.
        //
        // withTrashed: keeps soft-deleted members in the collection so isAlive()
        // catches them and aborts cleanly; without it, dead members vanish
        // silently and the op runs with a phantom crew.
        $members = Character::withTrashed()
            ->with([
                'stats',
                'items.template',
                'timers',
                'career',
                'corporation.properties',
                'corporation.activeSubsidiaries',
                'homeCity',
                'property',
            ])
            ->whereIn('id', $state['member_ids'])
            ->get()
            ->keyBy('id');

        // Wire the inverse: stats->character. Without this, every
        // effectiveStats() call lazy-loads its owning Character to read
        // properties on it (CharacterStats line 136). Setting the relation
        // by hand avoids that.
        foreach ($members as $member) {
            $member->stats?->setRelation('character', $member);
        }
        $target->stats?->setRelation('character', $target);

        if ($members->count() !== count($state['member_ids'])) {
            self::collapse($initiator->id, $state, 'member_unavailable');
            return ['error' => 'A member of the crew is no longer reachable. Operation aborted.'];
        }

        foreach ($members as $member) {
            if (! $member->isAlive()) {
                self::collapse($initiator->id, $state, 'member_unavailable');
                return ['error' => "{$member->display_name} is no longer alive. Operation aborted."];
            }
            if ($member->id === $initiator->id) {
                continue;
            }
            if (! $member->isOnline()) {
                self::collapse($initiator->id, $state, 'member_unavailable');
                return ['error' => "{$member->display_name} is no longer online. Operation aborted."];
            }
            if ($member->city_id !== $target->city_id) {
                self::collapse($initiator->id, $state, 'member_unavailable');
                return ['error' => "{$member->display_name} is no longer in the same city. Operation aborted."];
            }
            if ($member->isHospitalized() || $member->isJailed()) {
                self::collapse($initiator->id, $state, 'member_unavailable');
                return ['error' => "{$member->display_name} is unavailable (hospitalized or jailed). Operation aborted."];
            }
            // Cooldown gate at execute time for accomplices — same exploit
            // closure as for the initiator above.
            if ($member->timers?->next_conflict_at?->isFuture()) {
                self::collapse($initiator->id, $state, 'member_unavailable');
                return ['error' => "{$member->display_name} is on combat cooldown. Operation aborted."];
            }
        }

        $statsByMemberId = $members->map(
            fn (Character $c) => $c->stats?->effectiveStats() ?? []
        )->toArray();

        $composite      = self::buildComposite($members, $statsByMemberId, $target);
        $compositeStats = $composite['stats'];
        $defenderStats  = $target->stats->effectiveStats();

        $attackerPower = $compositeStats['offense']      * 3.0
            + $compositeStats['defense']      * 1.0
            + $compositeStats['intelligence'] * 1.5
            + $compositeStats['luck']         * 1.2
            + $composite['total_composite_xp'] / 1000;
        $defenderPower = ConflictService::calculateCombatPower($target, $defenderStats, true);
        $advantage     = ($attackerPower - $defenderPower) / max($defenderPower, 1);
        $influenceDiff = $compositeStats['influence'] - $defenderStats['influence'];

        DB::beginTransaction();
        try {
            DB::table('characters')
                ->whereIn('id', array_merge($state['member_ids'], [$target->id]))
                ->lockForUpdate()
                ->get();

            if (! $target->stats) {
                DB::rollBack();
                return ['error' => 'Target stats not initialized.'];
            }

            $snapshotHealth    = $target->health;
            $snapshotMaxHealth = $target->max_health;

            $damageResult = ConflictService::calculateDamage($compositeStats, $defenderStats, $advantage, $target);
            $baseDamage   = $damageResult['damage'];
            $isCritical   = $damageResult['is_critical'];

            if ($influenceDiff >= 5) {
                [$minMult, $maxMult] = [0.80, 1.20];
            } elseif ($influenceDiff <= -5) {
                [$minMult, $maxMult] = [0.65, 0.90];
            } else {
                [$minMult, $maxMult] = [0.75, 1.10];
            }

            $actualDamage = (int) round(ConflictService::luckyRoll(
                max(1, $baseDamage * $minMult),
                min(100, $baseDamage * $maxMult),
                $defenderStats['luck'],
                $compositeStats['luck']
            ));

            $maxHealthReduction = ConflictService::calculateMaxHealthReduction(
                $actualDamage,
                $isCritical,
                $compositeStats['intelligence'],
                $defenderStats['intelligence']
            );

            $names   = array_values($state['member_names']);
            $rest    = array_slice($names, 1);
            $crewStr = count($rest) === 2
                ? "{$rest[0]} and {$rest[1]}"
                : implode(', ', $rest);

            if ($target->health - $actualDamage <= 0) {
                $lootResult = ConflictService::loot($initiator, $target, $compositeStats, $defenderStats);

                foreach ($members as $memberId => $member) {
                    $ms = $statsByMemberId[$memberId] ?? [];
                    ConflictService::applyInfluenceChanges($member, $target, $ms, $defenderStats, 1.0 / 3);
                }

         
                $accompliceIds = array_values(array_filter(
                    $state['member_ids'],
                    fn (int $id) => $id !== $initiator->id
                ));
                CrimeService::organizedHit($initiator, $accompliceIds, $target, $initiator->city_id);
                City::increaseCrimeRateById($initiator->city_id, 0.6);

             
                $killReason = $state['custom_message'] ?? '3 MAN ATTACK';
                $target->kill('Organized Hit', $killReason);

                $initiator->consumeDurability('weapon');

                foreach ($members as $member) {
                    $member->halveProtection();
                    CharacterHistory::addHistory($member, 'kills');
                    CharacterHistory::appendKill($member, $target->display_name);
                }
                if ($isCritical) {
                    CharacterHistory::addHistory($initiator, 'critical_hits');
                }

                $lootMsg        = implode(' ', $lootResult['messages']);
                $outcome        = 'kill';
                $outcomeMessage = "You, {$crewStr} approached {$target->display_name} in the middle of the street "
                    . "and hit them  for {$actualDamage} damage — they didn't survive."
                    . ($lootMsg ? " {$lootMsg}" : '');
                $lootData = [
                    'cash'       => $lootResult['cash_looted'],
                    'dirty_cash' => $lootResult['dirty_cash_looted'],
                ];

            } else {
                $newMaxHealth        = max(1, $target->max_health - $maxHealthReduction);
                $target->health      = max(1, min($target->health - $actualDamage, $newMaxHealth));
                $target->max_health  = $newMaxHealth;
                $target->save();

                $damagePercent = $actualDamage / 100;
                foreach ($members as $memberId => $member) {
                    $ms = $statsByMemberId[$memberId] ?? [];
                    ConflictService::applyInfluenceChanges($member, $target, $ms, $defenderStats, $damagePercent / 3);
                }

                $initiator->consumeDurability('weapon');
                $target->consumeDurability('armor');

                foreach ($members as $member) {
                    $member->halveProtection();
                }

                $target->setProtection(
                    rand(config('timers.protection_min'), config('timers.protection_max')) * 2
                );

                CharacterHistory::addHistory($initiator, 'attacks_landed');
                if ($isCritical) {
                    CharacterHistory::addHistory($initiator, 'critical_hits');
                }
                CharacterHistory::addHistory($target, 'times_hit');

                JournalService::organizedAttackReceived(
                    defenderId:    $target->id,
                    attackerNames: array_values($state['member_names']),
                    result:        'hit',
                    weaponUsed:    $initiator->getEquippedWeaponName(),
                    damage:        $actualDamage,
                    isCritical:    $isCritical,
                    maxHealthLost: $maxHealthReduction,
                    customMessage: $state['custom_message'] ?? null,
                );

                $critText   = $isCritical ? 'CRITICAL HIT! ' : '';
                $injuryText = $maxHealthReduction > 0
                    ? " They suffered a permanent injury (-{$maxHealthReduction} max HP)."
                    : '';

                $outcome        = 'damage';
                $outcomeMessage = "{$critText}You, {$crewStr} approached {$target->display_name} in the middle of the street "
                    . "and hit them for {$actualDamage} damage before they could get away.{$injuryText}";
                $lootData = null;
            }

            // Per-accomplice journal entries.
            foreach ($members as $member) {
                if ($member->id === $initiator->id) {
                    continue;
                }
                $otherNames = collect($state['member_names'])
                    ->reject(fn ($n) => $n === $member->display_name)
                    ->values();
                $a0       = $otherNames[0] ?? '?';
                $a1       = $otherNames[1] ?? null;
                $crewLine = $a1 ? "{$a0} and {$a1}" : $a0;
                $msg      = $outcome === 'kill'
                    ? "You, {$crewLine}, approached {$target->display_name} in the middle of the street and managed to kill them, they took {$actualDamage} damage and couldn't survive."
                    : "You, {$crewLine}, approached {$target->display_name} in the middle of the street and hit them for {$actualDamage} damage before they could get away.";
                JournalService::custom($member->id, 'organized_hit_result', ['message' => $msg]);
            }

            // Doubled cooldown for the whole crew (organized-hit specific).
            foreach ($members as $member) {
                $member->setCombatCooldown(config('timers.conflict') * 2);
            }

            self::cacheForget(self::cacheKey($initiator->id));
            self::removeFromActiveSet($initiator->id);

            Log::info('[CombatAudit] ORGANIZED_HIT', [
                'initiator'       => $initiator->id,
                'members'         => $state['member_ids'],
                'target'          => $target->id,
                'composite_path'  => $composite['path'],
                'multiplier'      => $composite['multiplier'],
                'composite_stats' => $compositeStats,
                'defender_stats'  => $defenderStats,
                'advantage'       => round($advantage, 4),
                'damage'          => $actualDamage,
                'is_critical'     => $isCritical,
                'max_hp_lost'     => $maxHealthReduction,
                'outcome'         => $outcome,
                'health_before'   => $snapshotHealth,
                'health_after'    => $target->health,
            ]);

            DB::commit();

            return [
                'outcome'         => $outcome,
                'message'         => $outcomeMessage,
                'damage'          => $actualDamage,
                'is_instant_kill' => false,
                'is_critical'     => $isCritical,
                'loot'            => $lootData,
            ];

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[OrganizedHit] Execute failed.', [
                'initiator' => $initiator->id,
                'error'     => $e->getMessage(),
            ]);
            return ['error' => 'The operation failed. Please try again.'];
        }
    }

    

    public static function buildComposite(Collection $members, array $memberStats, Character $target): array
    {
        $strongestXp  = $members->max('total_character_exp');
        $targetXp     = $target->total_character_exp;
        $isStrongPath = $targetXp >= ($strongestXp * self::STRONG_XP_RATIO);
        $multiplier   = $isStrongPath ? self::STRONG_STAT_MULT : self::PENALTY_STAT_MULT;

        $compositeStats = [];
        foreach (['offense', 'defense', 'intelligence', 'luck'] as $stat) {
            $sum = array_sum(array_column($memberStats, $stat));
            $compositeStats[$stat] = (int) round($sum * $multiplier);
        }

        $compositeStats['influence'] = min(
            150.0,
            (float) array_sum(array_column($memberStats, 'influence'))
        );

        return [
            'stats'               => $compositeStats,
            'path'                => $isStrongPath ? 'strong' : 'penalty',
            'multiplier'          => $multiplier,
            'strongest_member_xp' => $strongestXp,
            'target_xp'           => $targetXp,
            'total_composite_xp'  => $members->sum('total_character_exp'),
        ];
    }

    public static function maybeWarnTarget(Character $target, array $memberStats): void
    {
        $targetIntel  = (float) ($target->stats?->effectiveStats()['intelligence'] ?? 0);
        $highestIntel = (float) max(array_column($memberStats, 'intelligence') ?: [0]);

        if ($highestIntel <= 0) {
            return;
        }

        $ratio  = $targetIntel / $highestIntel;
        $chance = match (true) {
            $ratio >= self::WARN_HIGH_RATIO => self::WARN_CHANCE_HIGH,
            $ratio >= self::WARN_MID_RATIO  => self::WARN_CHANCE_MID,
            default                         => self::WARN_CHANCE_LOW,
        };

        if (mt_rand(1, 100) > $chance) {
            return;
        }

        JournalService::custom($target->id, 'organized_hit_warning', [
            'target_name' => $target->display_name,
        ]);

        Log::info('[OrganizedHit] Intel warning delivered.', [
            'target' => $target->id,
            'ratio'  => round($ratio, 3),
            'chance' => $chance,
        ]);
    }

    public static function collapse(int $initiatorId, array $data, string $reason): void
    {
        self::cacheForget(self::cacheKey($initiatorId));
        self::removeFromActiveSet($initiatorId);

        $acceptedIds  = $data['accepted_by'] ?? [];
        $allMemberIds = $data['member_ids']  ?? [];

        // Penalise everyone who accepted (incl. initiator) on non-cancel collapses.
        // 'cancelled' = the initiator called it off cleanly: nobody pays.
        if (! empty($acceptedIds) && $reason !== 'cancelled') {
            $cooldown  = config('timers.conflict', 120) * 4;
            $penalised = Character::with('timers')->whereIn('id', $acceptedIds)->get();
            foreach ($penalised as $member) {
                $member->setCombatCooldown($cooldown);
            }
        }

        $message = match ($reason) {
            'expiry'         => 'The acceptance window closed before all crew confirmed. The hit has been called off.',
            'execute_expiry' => 'The execute window expired. The operation was abandoned and your crew stood down.',
            'member_left'    => 'One of your crew left the city. The operation has been aborted.',
            'declined'       => 'A crew member declined the invitation. The operation cannot proceed.',
            'cancelled'      => 'The operation was called off by the initiator.',
            default          => 'The organized hit has been called off.',
        };

        foreach ($allMemberIds as $memberId) {
            JournalService::custom((int) $memberId, 'organized_hit_collapsed', [
                'message' => $message,
            ]);

            CharacterJournal::where('character_id', $memberId)
                ->whereIn('type', ['organized_hit_invite'])
                ->whereJsonContains('data->initiator_id', $initiatorId)
                ->delete();
        }

        Log::info('[OrganizedHit] Collapsed.', [
            'initiator' => $initiatorId,
            'reason'    => $reason,
            'penalised' => $reason !== 'cancelled' ? $acceptedIds : [],
        ]);
    }
}