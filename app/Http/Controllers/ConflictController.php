<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Services\ConflictService;
use App\Services\CrimeService;
use App\Services\JournalService;
use App\Services\OrganizedHitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

// ! THE CORROSIVENESS OF INFLUENCE, MUCH LIKE DENNETT'S UNIVERSAL ACID.
//*WATCH IT EAT !

class ConflictController extends Controller
{
    public function results(Request $request): \Illuminate\Http\RedirectResponse|\Inertia\Response
    {
        if (!session()->has('conflict_result')) {
            return redirect()->route('conflict');
        }

        $result = session()->pull('conflict_result');

        $imageMap = [
            'kill' => 'https://images.thedirector.app/Conflict/kill.jpg',
            'damage' => 'https://images.thedirector.app/Conflict/damage.jpg',
            'hospitalized' => 'https://images.thedirector.app/Conflict/gbh.jpg',
            'miss' => 'https://images.thedirector.app/Conflict/damage.jpg',
            'gbh_miss' => 'https://images.thedirector.app/Conflict/gbh.jpg',
        ];

        return Inertia::render('Conflict/ConflictResults', [
            'outcome' => $result['outcome'],
            'message' => $result['message'],
            'image_url' => $imageMap[$result['outcome']] ?? $imageMap['damage'],
            'is_instant_kill' => $result['is_instant_kill'] ?? false,
            'is_critical' => $result['is_critical'],
            'loot' => $result['loot'],
            'damage' => $result['damage'],
        ]);
    }

    public function index(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return redirect()->route('character.create');
        }

        $items = $character->items()
            ->with('template')
            ->where(function ($q) {
                $q->whereRaw('"is_equipped" IS TRUE')
                    ->orWhere('location', 'on_hand');
            })
            ->get();

        $equipped = [
            'weapon' => $this->mapItem($items->first(fn($i) => $i->equipped_slot === 'weapon' && $i->is_equipped)),
            'armor' => $this->mapItem($items->first(fn($i) => $i->equipped_slot === 'armor' && $i->is_equipped)),
        ];

        $onHandWeapons = $items
            ->filter(fn($item) => $item->location === 'on_hand' && $item->template?->type === 'weapon')
            ->map(fn($item) => $this->mapItem($item))
            ->values();

        return Inertia::render('Conflict/Conflict', [
            'equipped' => $equipped,
            'onHandWeapons' => $onHandWeapons,
            'organizedHit' => $this->getOrganizedHitProps($character),
            'pendingInvite' => $this->getPendingInvite($character),
            'accomplices' => Character::where('city_id', $character->city_id)
                ->where('id', '!=', $character->id)
                ->alive()
                ->select(['id', 'display_name'])
                ->orderBy('display_name')
                ->get()
                ->map(fn($c) => ['id' => $c->id, 'name' => $c->display_name])
                ->values(),
            'myId' => $character->id,
        ]);
    }

    private function mapItem($item)
    {
        if (!$item || !$item->template) {
            return null;
        }

        return [
            'id' => $item->id,
            'name' => $item->template->name,
            'image_url' => $item->template->image_url,
            'type' => $item->template->type,
            'durability' => $item->durability_remaining,
            'max_uses' => $item->template->durability,
        ];
    }



    private function getOrganizedHitProps(Character $character): ?array
    {
        $state = OrganizedHitService::findOpForMember($character->id);
        if (!$state) {
            return null;
        }

        if (!in_array($character->id, $state['accepted_by'], true)) {
            return null;
        }

        return [
            'target_name' => $state['target_name'],
            'member_ids' => $state['member_ids'],
            'member_names' => $state['member_names'] ?? [],
            'accepted_by' => $state['accepted_by'],
            'is_ready' => $state['is_ready'] ?? false,
            'expires_at' => $state['expires_at'],
            'is_initiator' => $state['initiator_id'] === $character->id,
            'initiator_id' => $state['initiator_id'],
        ];
    }

    private function getPendingInvite(Character $character): ?array
    {
        $state = OrganizedHitService::findOpForMember($character->id);
        if (!$state) {
            return null;
        }
        if (in_array($character->id, $state['accepted_by'], true)) {
            return null;
        }

        return [
            'initiator_id' => $state['initiator_id'],
            'initiator_name' => $state['member_names'][$state['initiator_id']] ?? '?',
            'target_name' => $state['target_name'],
            'member_ids' => $state['member_ids'],
            'member_names' => $state['member_names'],
            'accepted_by' => $state['accepted_by'],
        ];
    }



    public function organizedHitInitiate(Request $request)
    {
        $request->validate([
            'target' => 'required|string|max:30',
            'accomplice_ids' => 'required|array|size:2',
            'accomplice_ids.*' => 'required|integer',
            'message' => 'sometimes|nullable|string|max:200',
        ]);

        $initiator = $request->user()->getLoadedCharacter();

        $target = Character::with([
            'stats',
            'items.template',
            'timers',
            'career',
            'corporation.properties',
            'corporation.activeSubsidiaries',
            'homeCity',
            'property',
        ])
            ->whereRaw('LOWER(display_name) = ?', [strtolower($request->input('target'))])
            ->first();

        $targetValidation = $initiator->canFight($target);
        if (!$targetValidation['valid']) {
            return back()->with('error', $targetValidation['error']);
        }

        $error = OrganizedHitService::initiate(
            $initiator,
            $target,
            array_map('intval', $request->input('accomplice_ids')),
            $request->input('message')
        );

        if ($error) {
            return back()->with('error', $error);
        }

        return back()->with('success', 'Operation initiated. Waiting for your crew to confirm.');
    }

    public function organizedHitAccept(Request $request, int $initiatorId)
    {
        $accomplice = $request->user()->getLoadedCharacter();

        $error = OrganizedHitService::accept($accomplice, $initiatorId);

        if ($error) {
            return back()->with('error', $error);
        }

        return back()->with('success', 'You have joined the operation. Stand by for the initiator to execute.');
    }

    public function organizedHitDecline(Request $request, int $initiatorId)
    {
        $accomplice = $request->user()->getLoadedCharacter();

        OrganizedHitService::decline($accomplice, $initiatorId);

        return back()->with('info', 'You declined the operation.');
    }

    public function organizedHitCancel(Request $request)
    {
        $initiator = $request->user()->getLoadedCharacter();

        OrganizedHitService::cancel($initiator);

        return back()->with('info', 'Operation called off.');
    }

    public function organizedHitExecute(Request $request)
    {
        $initiator = $request->user()->getLoadedCharacter();

        $result = OrganizedHitService::execute($initiator);

        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }

        session()->put('conflict_result', $result);

        return redirect()->route('conflict.results');
    }



    public function gbh(Request $request)
    {
        $request->validate([
            'target' => 'required|string|max:30',
            'message' => 'required|string|max:200',
        ]);

        $attacker = $request->user()->getLoadedCharacter();
        $customMessage = $request->input('message');

        $defender = Character::with([
            'stats',
            'items.template',
            'timers',
            'career',
            'corporation.properties',
            'corporation.activeSubsidiaries',
            'homeCity',
            'property',
        ])
            ->whereRaw('LOWER(display_name) = ?', [strtolower($request->input('target'))])
            ->first();

        $validation = $attacker->canFight($defender);

        if (!$validation['valid']) {
            return back()->with('error', $validation['error']);
        }

        // getLoadedCharacter already eager-loads: timers, career, city, homeCity.
        // Add only what's actually missing for effectiveStats() + combat.
        $attacker->loadMissing([
            'stats',
            'items.template',
            'corporation.properties',
            'corporation.activeSubsidiaries',
            'property',
        ]);
        // Wire stats<->character on both sides so effectiveStats() doesn't
        // lazy-load the back-reference (would fire `select * from characters
        // where id = N` per effectiveStats call).
        $attacker->stats?->setRelation('character', $attacker);
        $defender->stats?->setRelation('character', $defender);

        DB::beginTransaction();
        try {
            DB::table('characters')->whereIn('id', [$attacker->id, $defender->id])->lockForUpdate()->get();

            if (!$attacker->stats || !$defender->stats) {
                DB::rollBack();
                return back()->with('error', 'Combat stats not initialized.');
            }

            $defenderHealthBefore = $defender->health;
            $defenderMaxHealthBefore = $defender->max_health;

            $attackerStats = $attacker->stats->effectiveStats();
            $defenderStats = $defender->stats->effectiveStats();

            $attackerPower = $attackerStats['offense'] + $attackerStats['intelligence'];
            $defenderPower = $defenderStats['defense'] + $defenderStats['intelligence'];
            $powerRatio = $attackerPower / max(1, $defenderPower);

            if ($powerRatio > 1.25) {
                $chance = 75;
            } elseif ($powerRatio >= 0.75) {
                $chance = 35;
            } else {
                $chance = 15;
            }

            $gbhLanded = rand(1, 100) <= $chance;

            if (!$gbhLanded) {
                $outcome = $this->handleGbhMiss($attacker, $defender, $attackerStats, $defenderStats, $customMessage);
            } else {
                $outcome = $this->handleGbhSuccess($attacker, $defender, $attackerStats, $defenderStats, $powerRatio, $customMessage);
            }

            $cooldown = $outcome['outcome'] === 'gbh_miss'
                ? config('timers.conflict_miss')
                : config('timers.conflict');
            $attacker->setCombatCooldown($cooldown);

            $defender->setProtection(config('timers.protection_min'));

            DB::commit();

            $this->logCombatAudit(
                type: 'gbh',
                attacker: $attacker,
                defender: $defender,
                attackerStats: $attackerStats,
                defenderStats: $defenderStats,
                calculations: [
                    'defender_health_before' => $defenderHealthBefore,
                    'defender_max_health_before' => $defenderMaxHealthBefore,
                    'attacker_power' => round($attackerPower, 2),
                    'defender_power' => round($defenderPower, 2),
                    'power_ratio' => round($powerRatio, 4),
                    'hit_chance' => $chance,
                    'gbh_landed' => $gbhLanded,
                    'damage_dealt' => $outcome['damage'] ?? null,
                ],
                outcome: $outcome['outcome'],
                result: ['cash_looted' => 0, 'dirty_cash_looted' => 0],
            );

            session()->put('conflict_result', [
                'outcome' => $outcome['outcome'],
                'message' => $outcome['message'],
                'damage' => (int) ($outcome['damage'] ?? 0),
                'is_critical' => (bool) ($outcome['is_critical'] ?? false),
                'loot' => $outcome['loot'] ?? null,
                'is_instant_kill' => false,
            ]);

            return redirect()->route('conflict.results');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[Conflict] GBH failed', [
                'attacker' => $attacker?->display_name ?? 'Unknown',
                'defender' => $defender?->display_name ?? 'Unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'An error occurred during the assault');
        }
    }



    public function attack(Request $request)
    {
        $request->validate([
            'target' => 'required|string|max:30',
            'message' => 'sometimes|string|max:200',
        ]);

        $attacker = $request->user()->getLoadedCharacter();

        $customMessage = $request->input('message');

        $defender = Character::with([
            'stats',
            'items.template',
            'timers',
            'career',
            'corporation.properties',
            'corporation.activeSubsidiaries',
            'homeCity',
            'property',
        ])
            ->whereRaw('LOWER(display_name) = ?', [strtolower($request->input('target'))])
            ->first();


        $validation = $attacker->canFight($defender);

        if (!$validation['valid']) {
            return back()->with('error', $validation['error']);
        }

        // getLoadedCharacter already eager-loads: timers, career, city, homeCity.
        // Add only what's actually missing for effectiveStats() + combat.
        $attacker->loadMissing([
            'stats',
            'items.template',
            'corporation.properties',
            'corporation.activeSubsidiaries',
            'property',
        ]);
        // Wire stats<->character on both sides; see gbh() for rationale.
        $attacker->stats?->setRelation('character', $attacker);
        $defender->stats?->setRelation('character', $defender);

        DB::beginTransaction();
        try {
            DB::table('characters')->whereIn('id', [$attacker->id, $defender->id])->lockForUpdate()->get();

            if (!$attacker->stats || !$defender->stats) {
                DB::rollBack();
                return back()->with('error', 'Combat stats not initialized.');
            }

            $defenderHealthBefore = $defender->health;
            $defenderMaxHealthBefore = $defender->max_health;

            $attackerStats = $attacker->stats->effectiveStats();
            $defenderStats = $defender->stats->effectiveStats();

            $attackerPower = ConflictService::calculateCombatPower($attacker, $attackerStats, false);
            $defenderPower = ConflictService::calculateCombatPower($defender, $defenderStats, true);
            $powerDiff = $attackerPower - $defenderPower;
            $advantage = $powerDiff / max($defenderPower, 1);
            $influenceDiff = $attackerStats['influence'] - $defenderStats['influence'];

            $hitChance = ConflictService::calculateHitChance($attackerStats, $defenderStats, $advantage, $influenceDiff);
            $hitRoll = ConflictService::luckyRoll(0.01, 100.0, $attackerStats['luck'], $defenderStats['luck']);

            $killChance = null;
            $killRoll = null;

            if ($hitRoll > $hitChance) {
                $outcome = $this->handleAttackMiss($attacker, $defender, $customMessage);
            } else {
                $killChance = ConflictService::calculateKillChance($attacker, $defender, $advantage, $influenceDiff);
                $killRoll = ConflictService::luckyRoll(0.01, 100.0, $attackerStats['luck'], $defenderStats['luck']);

                if ($killRoll <= $killChance) {
                    $outcome = $this->handleKill($attacker, $defender, $attackerStats, $defenderStats, $customMessage);
                } else {
                    $outcome = $this->handleDamage($attacker, $defender, $attackerStats, $defenderStats, $advantage, $influenceDiff, $customMessage);
                }
            }

            $cooldown = $outcome['outcome'] === 'miss'
                ? config('timers.conflict_miss')
                : config('timers.conflict');
            $attacker->setCombatCooldown($cooldown);

            if ($outcome['outcome'] === 'miss') {
                $defender->setProtection(config('timers.protection_min'));
            } elseif ($outcome['outcome'] === 'damage') {
                $defender->setProtection(rand(
                    config('timers.protection_min'),
                    config('timers.protection_max')
                ));
            }

            DB::commit();

            $this->logCombatAudit(
                type: 'attack',
                attacker: $attacker,
                defender: $defender,
                attackerStats: $attackerStats,
                defenderStats: $defenderStats,
                calculations: [
                    'defender_health_before' => $defenderHealthBefore,
                    'defender_max_health_before' => $defenderMaxHealthBefore,
                    'attacker_combat_power' => round($attackerPower, 2),
                    'defender_combat_power' => round($defenderPower, 2),
                    'power_diff' => round($powerDiff, 2),
                    'advantage' => round($advantage, 4),
                    'influence_diff' => round($influenceDiff, 2),
                    'hit_chance' => round($hitChance, 2),
                    'hit_roll' => round($hitRoll, 2),
                    'hit_landed' => $hitRoll <= $hitChance,
                    'kill_chance' => $killChance !== null ? round($killChance, 2) : null,
                    'kill_roll' => $killRoll !== null ? round($killRoll, 2) : null,
                    'kill_fired' => $killChance !== null && $killRoll !== null
                        ? $killRoll <= $killChance : null,
                    'damage_dealt' => $outcome['damage'] ?? null,
                    'is_critical' => $outcome['is_critical'] ?? false,
                    'max_health_reduction' => $outcome['max_health_reduction'] ?? null,
                    'is_instant_kill' => $outcome['is_instant_kill'] ?? false,
                ],
                outcome: $outcome['outcome'],
                result: [
                    'cash_looted' => $outcome['loot']['cash'] ?? 0,
                    'dirty_cash_looted' => $outcome['loot']['dirty_cash'] ?? 0,
                ],
            );

            session()->put('conflict_result', [
                'outcome' => $outcome['outcome'],
                'message' => $outcome['message'],
                'damage' => (int) ($outcome['damage'] ?? 0),
                'is_instant_kill' => (bool) ($outcome['is_instant_kill'] ?? false),
                'is_critical' => (bool) ($outcome['is_critical'] ?? false),
                'loot' => $outcome['loot'] ?? null,
            ]);

            return redirect()->route('conflict.results');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[Conflict] Attack failed', [
                'attacker' => $attacker?->display_name ?? 'Unknown',
                'defender' => $defender?->display_name ?? 'Unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'An error occurred during the attack');
        }
    }



    private function handleGbhMiss(Character $attacker, Character $defender, array $attackerStats, array $defenderStats, ?string $customMessage): array
    {
        $weaponName = $attacker->getEquippedWeaponName();

        JournalService::attackReceived(
            defenderId: $defender->id,
            attackerName: $attacker->display_name,
            result: 'gbh_miss',
            weaponUsed: $weaponName,
            customMessage: $customMessage
        );

        \App\Models\CharacterHistory::addHistory($attacker, 'gbh_missed');

        $attacker->consumeDurability('weapon');
        $defender->consumeDurability('armor');

        return [
            'outcome' => 'gbh_miss',
            'message' => "You attempted to beat {$defender->display_name} but weren't able to subdue them .",
        ];
    }

    private function handleGbhSuccess(Character $attacker, Character $defender, array $attackerStats, array $defenderStats, float $powerRatio, ?string $customMessage): array
    {
        $baseDamage = sqrt(max(0.1, $attackerStats['intelligence'])) / 8;
        $defenseMultiplier = 1 / (1 + (sqrt($defenderStats['defense']) / 150));
        $damage = $baseDamage * $defenseMultiplier;

        $damage = (int) round(ConflictService::luckyRoll($damage * 0.7, $damage * 1.3, $attackerStats['luck'], $defenderStats['luck']));

        $maxPercentDamage = (int) floor($defender->health * 0.03);
        $damage = min($damage, $maxPercentDamage, $defender->health - 1);
        $damage = max(1, $damage);

        if ($damage >= 3) {
            $defender->max_health -= 1;
        }

        $defender->health -= $damage;
        $defender->health = max(1, $defender->health);
        $defender->save();

        $attacker->consumeDurability('weapon');
        $defender->consumeDurability('armor');

        $defender->hospitalize(config('timers.hospital'), $customMessage);

        CrimeService::assault($attacker, $defender, $attacker->city_id);
        \App\Models\City::increaseCrimeRateById($attacker->city_id, 0.3);

        $weaponName = $attacker->getEquippedWeaponName();

        JournalService::attackReceived(
            defenderId: $defender->id,
            attackerName: $attacker->display_name,
            result: 'hospitalized',
            weaponUsed: $weaponName,
            hospitalDuration: config('timers.hospital'),
            customMessage: $customMessage
        );

        \App\Models\CharacterHistory::addHistory($attacker, 'gbh_landed');
        \App\Models\CharacterHistory::addHistory($defender, 'times_hit');

        return [
            'outcome' => 'hospitalized',
            'damage' => $damage,
            'hospital_duration' => config('timers.hospital'),
            'message' => "You found {$defender->display_name} in the middle of the street and started beating them with your {$weaponName}! They have been hospitalized for an hour.",
        ];
    }

    private function handleAttackMiss(Character $attacker, Character $defender, ?string $customMessage): array
    {
        $weaponName = $attacker->getEquippedWeaponName();

        JournalService::attackReceived(
            defenderId: $defender->id,
            attackerName: $attacker->display_name,
            result: 'miss',
            weaponUsed: $weaponName,
            customMessage: $customMessage
        );

        \App\Models\CharacterHistory::addHistory($attacker, 'attacks_missed');

        $attacker->consumeDurability('weapon');
        $defender->consumeDurability('armor');
        $attacker->halveProtection();

        return [
            'outcome' => 'miss',
            'message' => "You attempted to attack {$defender->display_name} but missed!",
        ];
    }


    //! TODO Dead attacker gets revived -> armor is not consumed
    private function handleKill(
        Character $attacker,
        Character $defender,
        array $attackerStats,
        array $defenderStats,
        ?string $customMessage,
        ?int $actualDamage = null,
        bool $isCritical = false
    ): array {
        $lootResult = ConflictService::loot($attacker, $defender, $attackerStats, $defenderStats);
        //TODO check if attacker is in a corporation and the cto if so do not report or applyinfluence.

        ConflictService::applyInfluenceChanges($attacker, $defender, $attackerStats, $defenderStats, 1.0);

        CrimeService::murder($attacker, $defender, $attacker->city_id);

        \App\Models\City::increaseCrimeRateById($attacker->city_id, 0.6);

        $defender->kill('Murdered', $customMessage);

        \App\Models\CharacterHistory::addHistory($attacker, 'kills');
        \App\Models\CharacterHistory::appendKill($attacker, $defender->display_name);
        if ($actualDamage === null) {
            \App\Models\CharacterHistory::addHistory($attacker, 'instant_kills');
        }
        if ($isCritical) {
            \App\Models\CharacterHistory::addHistory($attacker, 'critical_hits');
        }

        $attacker->consumeDurability('weapon');
        $attacker->halveProtection();

        $weaponTemplate = $attacker->getEquippedWeaponTemplate();
        $weaponName = $weaponTemplate?->name ?? 'bare hands';
        $lootMessage = implode(' ', $lootResult['messages']);

        $fallback = $actualDamage !== null
            ? "You attacked {$defender->display_name} with your {$weaponName}  for {$actualDamage} damage and killed them! {$lootMessage}"
            : "One clean strike. {$defender->display_name} was eliminated with your {$weaponName} before they could react. {$lootMessage}";

        $resultMessage = $weaponTemplate?->resolveWeaponMessage(
            'kill_result_message',
            $attacker->display_name,
            $defender->display_name,
            $actualDamage ?? 0
        ) ?? $fallback;

        if ($weaponTemplate?->kill_result_message && $lootMessage) {
            $resultMessage .= ' ' . $lootMessage;
        }

        return [
            'outcome' => 'kill',
            'damage' => $actualDamage,
            'is_instant_kill' => $actualDamage === null,
            'is_critical' => $isCritical,
            'message' => $resultMessage,
            'loot' => [
                'cash' => $lootResult['cash_looted'],
                'dirty_cash' => $lootResult['dirty_cash_looted'],
            ],
        ];
    }

    private function handleDamage(Character $attacker, Character $defender, array $attackerStats, array $defenderStats, float $advantage, float $influenceDiff, ?string $customMessage): array
    {
        $damageResult = ConflictService::calculateDamage($attackerStats, $defenderStats, $advantage, $defender);
        $baseDamage = $damageResult['damage'];
        $isCritical = $damageResult['is_critical'];

        if ($influenceDiff >= 5) {
            $minMult = 0.80;
            $maxMult = 1.20;
        } elseif ($influenceDiff <= -5) {
            $minMult = 0.65;
            $maxMult = 0.90;
        } else {
            $minMult = 0.75;
            $maxMult = 1.10;
        }

        $minDmg = max(1, $baseDamage * $minMult);
        $maxDmg = min(99, $baseDamage * $maxMult);

        $actualDamage = (int) round(
            ConflictService::luckyRoll($minDmg, $maxDmg, $defenderStats['luck'], $attackerStats['luck'])
        );

        $maxHealthReduction = ConflictService::calculateMaxHealthReduction(
            $actualDamage,
            $isCritical,
            $attackerStats['intelligence'],
            $defenderStats['intelligence']
        );

        $newHealth = $defender->health - $actualDamage;

        if ($newHealth <= 0) {
            return $this->handleKill(
                $attacker,
                $defender,
                $attackerStats,
                $defenderStats,
                $customMessage,
                $actualDamage,
                $isCritical
            );
        }

        $weaponTemplate = $attacker->getEquippedWeaponTemplate();
        $weaponName = $weaponTemplate?->name ?? 'bare hands';

        $attacker->consumeDurability('weapon');
        $defender->consumeDurability('armor');
        $attacker->halveProtection();

        $newMaxHealth = max(1, $defender->max_health - $maxHealthReduction);
        $defender->health = max(1, min($newHealth, $newMaxHealth));
        $defender->max_health = $newMaxHealth;
        $defender->save();

        \App\Models\CharacterHistory::addHistory($attacker, 'attacks_landed');
        if ($isCritical) {
            \App\Models\CharacterHistory::addHistory($attacker, 'critical_hits');
        }
        \App\Models\CharacterHistory::addHistory($defender, 'times_hit');

        ConflictService::applyInfluenceChanges($attacker, $defender, $attackerStats, $defenderStats, $actualDamage / 100);

        $journalMessage = $weaponTemplate?->resolveWeaponMessage(
            'damage_journal_message',
            $attacker->display_name,
            $defender->display_name,
            $actualDamage,
            $isCritical,
            $maxHealthReduction
        );

        JournalService::attackReceived(
            defenderId: $defender->id,
            attackerName: $attacker->display_name,
            result: 'hit',
            weaponUsed: $weaponName,
            damage: $actualDamage,
            wasCritical: $isCritical,
            maxHealthLost: $maxHealthReduction,
            customMessage: $customMessage,
            weaponMessage: $journalMessage
        );

        $critText = $isCritical ? 'CRITICAL HIT! ' : '';
        $injuryText = $maxHealthReduction > 0 ? " They lost {$maxHealthReduction} max HP!" : '';

        $resultMessage = $weaponTemplate?->resolveWeaponMessage(
            'damage_result_message',
            $attacker->display_name,
            $defender->display_name,
            $actualDamage,
            $isCritical,
            $maxHealthReduction
        ) ?? "{$critText}You attacked {$defender->display_name} with your {$weaponName} for {$actualDamage} damage!{$injuryText}";

        return [
            'outcome' => 'damage',
            'damage' => $actualDamage,
            'is_critical' => $isCritical,
            'max_health_reduction' => $maxHealthReduction,
            'message' => $resultMessage,
        ];
    }



    private function logCombatAudit(
        string $type,
        Character $attacker,
        Character $defender,
        array $attackerStats,
        array $defenderStats,
        array $calculations,
        string $outcome,
        array $result
    ): void {
        Log::info('[CombatAudit] ' . strtoupper($type), [
            'attacker' => [
                'id' => $attacker->id,
                'name' => $attacker->display_name,
                'health' => $attacker->health,
                'max_health' => $attacker->max_health,
                'total_xp' => $attacker->total_character_exp,
                'weapon' => $attacker->getEquippedWeaponName(),
                'stats' => $attackerStats,
                'talents' => [
                    'goal_of_all_life' => $attacker->hasTalentActive('goal_of_all_life'),
                ],
            ],
            'defender' => [
                'id' => $defender->id,
                'name' => $defender->display_name,
                'health_before' => $calculations['defender_health_before'],
                'health_after' => $defender->health,
                'max_health_before' => $calculations['defender_max_health_before'],
                'max_health_after' => $defender->max_health,
                'total_xp' => $defender->total_character_exp,
                'stats' => $defenderStats,
                'talents' => [
                    'defense_in_depth' => $defender->hasTalentActive('defense_in_depth'),
                ],
            ],
            'calculations' => $calculations,
            'outcome' => $outcome,
            'result' => $result,
        ]);
    }
}
