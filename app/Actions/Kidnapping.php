<?php

namespace App\Actions;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterJournal;
use App\Models\City;
use App\Services\CrimeService;
use App\Services\JournalService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

//! how group actions work, initiator starts, timer set to config.timers(3), acceptors get set to 2 hours.
//! when executed initiator timer is checked get set to 2 hours then all strength for all is reset to 0.
//TODO add pet robot here

//TODO kidnapping really should use action timer

class Kidnapping extends Action
{
    private const TARGET_CASH_THRESHOLD = 150_000;
    private const CACHE_TTL             = 600;
    private const MAX_PARTICIPANTS      = 5;

    public function getId(): string          { return 'kidnapping'; }
    public function getIcon(): string        { return 'Skull'; }
    public function getButtonLabel(): string { return 'Execute Abduction'; }
    public function getGroupInitLabel(): ?string { return 'Launch Operation'; }
    public function isGroupAction(): bool    { return true; }

    public function getShape(Character $character): array
    {
        $state           = $this->getState($character);
        $isWaiting       = false;
        $isReady         = false;
        $activeMembers   = null;
        $cancelRoute     = null;
        $eligibleTargets      = [];
        $availableAccomplices = [];

        if ($state) {
            if (($state['status'] ?? '') === 'ready_for_execution') {
                $isReady = true;
            } else {
                $isWaiting = true;
            }

            $participantMap = Character::whereIn('id', $state['required_members'])
                ->get(['id', 'display_name'])
                ->keyBy('id');

            $activeMembers = array_map(fn (int $id) => [
                'id'       => $id,
                'name'     => $participantMap->get($id)?->display_name ?? 'Unknown',
                'accepted' => in_array($id, $state['accepted_by']),
            ], $state['required_members']);

            if ($character->id === $state['initiator_id']) {
                $cancelRoute = route('actions.kidnapping.cancel');
            }
        } else {
            
            $eligibleTargets      = $this->cityTargetOptions($character);
            $availableAccomplices = $this->getAvailableAccomplices($character);
        }

        return [
            'id'               => $this->getId(),
            'title'            => 'Kidnapping',
         
            'category'         => 'Underground Economy',
            'description'      => "Coordinate with accomplices to abduct a wealthy victim. You'll need property and a weapon on you to pull this off. Success depends on the strength of the group and how many people are around  that could disrupt your plans.",
            'image_url'        => 'https://images.thedirector.app/actions/kidnapper.jpg',
            'icon'             => $this->getIcon(),
            'button_label'     => $this->getButtonLabel(),
            'group_init_label' => $this->getGroupInitLabel(),
            'execute_route'    => route('actions.kidnapping'),
            'cancel_route'     => $cancelRoute,
            'is_group_action'  => true,
            'available'        => true,
            'blocker'          => null,
            'is_waiting'       => $isWaiting,
            'is_ready'         => $isReady,
            'active_members'   => $activeMembers,
            'targets'          => $eligibleTargets,
            'accomplices'      => $availableAccomplices,
            'has_amount_input' => false,
            'amount_label'     => null,
            'pick_label'       => 'Select a target',
            'target_icon'      => 'user',
        ];
    }

    public function canExecute(Character $character): array
    {
         
          if ($this->isOnCooldown($character)) {
            return ['valid' => false, 'error' => 'You need to wait before performing another action.'];
         }
        
        if ($this->isStrengthDepleted($character)) {
            return ['valid' => false, 'error' => 'You are too exhausted to orchestrate this. Wait for your strength to recover.'];
        }
        if ($character->property_id === null) {
            return ['valid' => false, 'error' => 'You need a property to use as a safe house.'];
        }
        if (! in_array($character->property_condition, [\App\Models\Property::CONDITION_CONSTRUCTED, \App\Models\Property::CONDITION_BOMB], true)) {
            return ['valid' => false, 'error' => 'You need a functional property (inspected and not destroyed) to use as a safe house.'];
        }
        if (! $this->getEquippedWeaponId($character)) {
            return ['valid' => false, 'error' => 'You must have a weapon equipped to subdue the target.'];
        }
        return ['valid' => true, 'error' => null];
    }

    

    public function execute(Character $character, array $params): RedirectResponse
    {
        $cacheKey  = $this->cacheKey($character->id);
        $cacheData = $this->cacheGet($cacheKey);

        
        if ($cacheData && ($cacheData['status'] ?? '') === 'ready_for_execution') {
            if ($cacheData['initiator_id'] !== $character->id) {
                return $this->error('Only the operation initiator can execute.');
            }
            return $this->executeOperation($character, $cacheData);
        }

        if ($cacheData) {
            return $this->error('You already have a kidnapping operation in progress.');
        }

        

        $check = $this->canExecute($character);
        if (! $check['valid']) {
            return $this->error($check['error']);
        }

        if (! $this->isAvailable($character)) {
            return $this->error('You are currently unavailable (hospitalized or jailed).');
        }

        $targetId = (int) ($params['target_id'] ?? 0);
        if (! $targetId) {
            return $this->error('You must select a target.');
        }

        $target = Character::with('timers')->find($targetId);

        if (! $target || ! $target->isAlive())             return $this->error('Target not found.');
        if ($target->id === $character->id)                return $this->error('You cannot kidnap yourself.');
        if ($target->city_id !== $character->city_id)      return $this->error('Target is not in your city.');
        if (! $target->isOnline())                         return $this->error('Target is not online.');
        if (! $this->isAvailable($target))                 return $this->error('Target is currently unavailable.');
        if ($this->isTargetOnActionCooldown($target)) {
            return $this->error("{$target->display_name} has recently been targeted and is on high alert. Try again later.");
        }
        if (($target->cash_on_hand + $target->dirty_cash) < self::TARGET_CASH_THRESHOLD) {
            return $this->error('Target does not have enough cash on them to make this worthwhile.');
        }

        

        $accompliceIds = array_values(array_unique(
            array_filter(array_map('intval', (array) ($params['accomplice_ids'] ?? [])), fn ($id) => $id > 0)
        ));

        if (count($accompliceIds) === 0) {
            return $this->error('You must select at least one accomplice.');
        }
        if (count($accompliceIds) > self::MAX_PARTICIPANTS - 1) {
            return $this->error('A kidnapping crew cannot exceed ' . self::MAX_PARTICIPANTS . ' people including yourself.');
        }

        
        $accomplices = Character::whereIn('id', $accompliceIds)
            ->where('city_id', $character->city_id)
            ->where('id', '!=', $character->id)
            ->where('id', '!=', $target->id)
            ->alive()
            ->get();

        if ($accomplices->count() !== count($accompliceIds)) {
            return $this->error('One or more selected accomplices is unavailable or not in your city.');
        }

       

        $initiatorWeaponId = $this->getEquippedWeaponId($character);
        $requiredMemberIds = array_values(array_unique(array_merge([$character->id], $accompliceIds)));

        $this->cachePut($cacheKey, [
            'expires_at'         => now()->addSeconds(self::CACHE_TTL)->timestamp,
            'initiator_id'       => $character->id,
            'target_id'          => $target->id,
            'city_id'            => $character->city_id,
            'required_members'   => $requiredMemberIds,
            'accepted_by'        => [$character->id],
            'status'             => 'pending',
            'weapon_commitments' => [$character->id => $initiatorWeaponId],
        ], self::CACHE_TTL);

        foreach ($accomplices as $accomplice) {
            JournalService::custom($accomplice->id, 'kidnapping_request', [
                'initiator_id'   => $character->id,
                'initiator_name' => $character->display_name,
                'target_name'    => $target->display_name,
                'city_id'        => $character->city_id,
            ]);
        }

        Log::info('[Kidnapping] Initiated.', [
            'initiator'   => $character->id,
            'target'      => $target->id,
            'accomplices' => $accompliceIds,
        ]);

        return $this->success('Kidnapping plan set in motion. Your accomplice(s) has been contacted — the clock is running.');
    }



    public function accept(Character $character, CharacterJournal $journal): RedirectResponse
    {
        $initiatorId = (int) ($journal->data['initiator_id'] ?? 0);
        if (! $initiatorId) {
            $journal->delete();
            return $this->error('Invalid invitation data.');
        }

        //check cooldown for character
        if ($this->isOnCooldown($character)) {
            return $this->error('You cannot accept this invitation until your action timer is ready.');
        }

        

        $cacheKey  = $this->cacheKey($initiatorId);
        $cacheData = $this->cacheGet($cacheKey);

        if (! $cacheData || now()->timestamp > $cacheData['expires_at']) {
            if ($cacheData) $this->fail($initiatorId, $cacheData['required_members'], $cacheData['accepted_by'], 'expiry');
            $journal->delete();
            return $this->error('The window on this operation has already closed.');
        }

        if (! in_array($character->id, $cacheData['required_members'])) {
            $journal->delete();
            return $this->error('You are not listed as a participant in this operation.');
        }

        if (! in_array($character->id, $cacheData['accepted_by'])) {
            $cacheData['accepted_by'][] = $character->id;
            $this->setCooldownHours($character, 1);
        }

        if (! $character->isAlive()) {
            $journal->delete();
            return $this->error('You cannot accept — your character is no longer active.');
        }
        if (! $this->isAvailable($character)) {
            $journal->delete();
            return $this->error('You cannot accept while you are hospitalised or in jail.');
        }
        if ($character->city_id !== $cacheData['city_id']) {
            $journal->delete();
            return $this->error('You have left the city where this operation is taking place. You cannot join.');
        }

        $journal->delete();



        if (! in_array($character->id, $cacheData['accepted_by'])) {
            $cacheData['weapon_commitments'][$character->id] = $this->getEquippedWeaponId($character);
            $cacheData['accepted_by'][]                      = $character->id;
        }

        if (count($cacheData['accepted_by']) >= 2) {
            $cacheData['status'] = 'ready_for_execution';
            Log::info('[Kidnapping] Quorum met — ready for execution.', ['initiator' => $initiatorId]);
        }



        $this->cachePut($cacheKey, $cacheData, self::CACHE_TTL);

        Log::info('[Kidnapping] Accomplice accepted.', [
            'character_id' => $character->id,
            'initiator'    => $initiatorId,
            'accepted'     => count($cacheData['accepted_by']),
            'required'     => count($cacheData['required_members']),
        ]);

        return $this->success("You're in. Keep your head down, stay quiet and stay online.");
    }

    

    public function decline(Character $character, CharacterJournal $journal): RedirectResponse
    {
        $initiatorId = (int) ($journal->data['initiator_id'] ?? 0);
        $journal->delete();

        if (! $initiatorId) {
            return $this->success('You declined the invitation.');
        }

        $cacheKey  = $this->cacheKey($initiatorId);
        $cacheData = $this->cacheGet($cacheKey);

        if (! $cacheData || now()->timestamp > $cacheData['expires_at']) {
            if ($cacheData) $this->fail($initiatorId, $cacheData['required_members'], $cacheData['accepted_by'], 'expiry');
            return $this->success('The operation had already expired before you could respond.');
        }

        $cacheData['required_members'] = array_values(array_filter($cacheData['required_members'], fn ($id) => $id !== $character->id));
        $cacheData['accepted_by']      = array_values(array_filter($cacheData['accepted_by'],      fn ($id) => $id !== $character->id));
        unset($cacheData['weapon_commitments'][$character->id]);

        Log::info('[Kidnapping] Accomplice declined.', [
            'character_id' => $character->id,
            'initiator'    => $initiatorId,
            'remaining'    => count($cacheData['required_members']),
        ]);

        if (count($cacheData['required_members']) < 2) {
            $this->fail($initiatorId, $cacheData['required_members'], $cacheData['accepted_by'], 'not_enough_accomplices');
            return $this->success('You declined. Without enough crew the operation has been called off.');
        }

        $this->cachePut($cacheKey, $cacheData, self::CACHE_TTL);
        return $this->success('You declined. The remaining crew may still proceed without you.');
    }

    

    public function cancel(Character $character): RedirectResponse
    {
        $cacheKey  = $this->cacheKey($character->id);
        $cacheData = $this->cacheGet($cacheKey);

        if (! $cacheData || $cacheData['initiator_id'] !== $character->id) {
            return $this->error('No active operation to cancel.');
        }

        $this->cacheForget($cacheKey);

        CharacterJournal::where('type', 'kidnapping_request')
            ->whereJsonContains('data->initiator_id', $character->id)
            ->delete();

        Log::info('[Kidnapping] Cancelled by initiator.', ['initiator' => $character->id]);
        return $this->success('Operation aborted. Your crew has been stood down.');
    }

    

    private function executeOperation(Character $initiator, array $cacheData): RedirectResponse
    {
        try {
            $this->cacheForget($this->cacheKey($initiator->id));

            $target = Character::with(['stats', 'items.template', 'property', 'corporation', 'timers'])->find($cacheData['target_id']);

            

            if (! $target || ! $target->isAlive()) {
                return $this->error('Target no longer exists.');
            }
            if ($target->city_id !== $cacheData['city_id']) {
                return $this->error('Target has left the city.');
            }
            if (! $target->isOnline()) {
                return $this->error('Target is no longer online.');
            }
            if (! $this->isAvailable($target)) {
                return $this->error('Target is currently unavailable (hospitalized or jailed).');
            }
           
            if ($this->isTargetOnActionCooldown($target)) {
                return $this->error("{$target->display_name} has recently been targeted and is on high alert. Try again later.");
            }
            if (($target->cash_on_hand + $target->dirty_cash) < self::TARGET_CASH_THRESHOLD) {
                return $this->error('Target no longer has enough cash on them.');
            }

            
           
            
            if ($initiator->city_id !== $cacheData['city_id']) {
                return $this->error('You have left the operation city. You cannot execute from here.');
            }
            if (! $this->isAvailable($initiator)) {
                return $this->error('You are currently unavailable (hospitalized or jailed).');
            }

            if ($this->isOnCooldown($initiator)) {
                return $this->error('You need to wait before performing another action.');
            }
            
            if (! $initiator->isInHomeCity()) {
                return $this->error('You have to be in the same city as your safe house to pull this off, where else do you expect to keep them?!');
            }

            
            
            

            $members = Character::whereIn('id', $cacheData['accepted_by'])
                ->with(['stats', 'items.template', 'property', 'timers', 'corporation'])
                ->get();

            foreach ($members as $member) {
                if (! $member->isAlive()) {
                    $this->fail($initiator->id, $cacheData['required_members'], $cacheData['accepted_by'], 'member_dead');
                    return $this->error("One of your crew members is no longer alive. Operation aborted.");
                }
                if ($member->city_id !== $cacheData['city_id']) {
                    $this->fail($initiator->id, $cacheData['required_members'], $cacheData['accepted_by'], 'member_left_city');
                    return $this->error('One of your accomplices has left the city. Operation aborted.');
                }
                if (! $member->isOnline()) {
                    $this->fail($initiator->id, $cacheData['required_members'], $cacheData['accepted_by'], 'member_offline');
                    return $this->error('One of your accomplices is no longer online. Operation aborted.');
                }
                if (! $this->isAvailable($member)) {
                    $this->fail($initiator->id, $cacheData['required_members'], $cacheData['accepted_by'], 'member_unavailable');
                    return $this->error('One of your accomplices is currently hospitalised or jailed. Operation aborted.');
                }
            }

            

            foreach ($members as $member) {
                $member->stats?->setRelation('character', $member);
            }
            $target->stats?->setRelation('character', $target);

            $memberStats = $members->map(fn ($m) => $m->stats?->effectiveStats() ?? []);
            $targetStats = $target->stats?->effectiveStats() ?? [];

            $avgInt      = ($memberStats->avg('intelligence') ?? 0) / 1000;
            $avgOff      = ($memberStats->avg('offense') ?? 0)      / 1000;
            $avgLuck     = ($memberStats->avg('luck') ?? 0)          / 1000;
            $avgStrength = $members->avg(fn ($m) => (float) ($m->timers?->strength ?? 0));
            $targetDef   = ($targetStats['defense'] ?? 0) / 1000;
            $targetInt   = ($targetStats['intelligence'] ?? 0)    / 1000;

            $city = City::find($cacheData['city_id']);

            $attackerPower  = ($avgInt * 0.4) + ($avgOff * 0.4) + ($avgLuck * 0.2);
            $defenderPower  = ($targetDef * 0.7) + ($targetInt * 0.3);
            $powerFactor    = log(max(0.01, $attackerPower) / max(0.01, $defenderPower)) * 25;
            $crimeBonus     = $this->crimeRateBonus($city, 0.15);
            
            $onlineInCity = Character::onlineInCity($city->id)->count();

            $statChance         = 50 + $powerFactor + $crimeBonus;

            $strengthMultiplier = max(0.15, sqrt($avgStrength / 100));

           $cityMultiplier = max(0.15, min(1.0, 1 - (($onlineInCity - 1) * 0.1)));

            $chance             = (int) max(1, min(80, (int) round($statChance * $strengthMultiplier * $cityMultiplier)));
            $roll               = mt_rand(1, 100);
            $isSuccess          = $roll <= $chance;

            
            $this->resetStrength($initiator);
            $this->setCooldownHours($initiator, 1);
            foreach ($members as $member) {
                if ($member->id == $initiator->id) {
                    $this->resetStrength($member);
                }
            }
            $this->setTargetActionCooldown($target, 90 * 60, 120 * 60);

            Log::info('[Kidnapping] Execution attempt.', [
                'initiator' => $initiator->id,
                'avgInt' => $avgInt,
                'avgOff' => $avgOff,
                'avgLuck' => $avgLuck,
                'avgStrength' => $avgStrength,
                'targetDef' => $targetDef,
                'targetInt' => $targetInt,
                'target'    => $target->id,
                'onlineInCity' => $onlineInCity,
                'chance'    => $chance,
                'roll'      => $roll,
                'outcome'   => $isSuccess ? 'SUCCESS' : 'FAILURE',
            ]);

            $weaponCommitments = $cacheData['weapon_commitments'] ?? [];

            return $isSuccess
                ? $this->handleSuccess($initiator, $target, $members, $city)
                : $this->handleFailure($initiator, $target, $members, $weaponCommitments);

        } catch (\Throwable $e) {
            Log::error('[Kidnapping] Execution crashed.', ['initiator' => $initiator->id, 'error' => $e->getMessage()]);
            return $this->error('Operation failed due to unforeseen circumstances.');
        }
    }

    

    private function handleSuccess(Character $initiator, Character $target, Collection $members, ?City $city): RedirectResponse
    {
        $targetTotal     = $target->cash_on_hand + $target->dirty_cash;
        $ransom          = (int) min(floor($targetTotal * 0.45), 500000);
        $share           = (int) floor($ransom / $members->count());
        $accompliceCount = $members->count() - 1;
        $formattedRansom = '$' . number_format($ransom);
        $formattedShare  = '$' . number_format($share);
        $weaponName      = $initiator->getEquippedWeaponName();
        $propertyName    = $initiator->property?->name ?? 'a safe house';

        DB::transaction(function () use ($target, $ransom, $members, $share) {
            
            $allIds = $members->pluck('id')->push($target->id)->unique()->sort()->values()->all();
            Character::whereIn('id', $allIds)->lockForUpdate()->get(['id']);

            
            
            $lockedTarget = DB::table('characters')->where('id', $target->id)->first(['cash_on_hand', 'dirty_cash']);
            $availableHand  = (int) ($lockedTarget->cash_on_hand ?? 0);
            $availableDirty = (int) ($lockedTarget->dirty_cash ?? 0);
            $actualRansom   = min($ransom, $availableHand + $availableDirty);

            if ($actualRansom > 0) {
                $fromHand  = min($actualRansom, $availableHand);
                $fromDirty = $actualRansom - $fromHand;
                if ($fromHand > 0)  Character::where('id', $target->id)->decrement('cash_on_hand', $fromHand);
                if ($fromDirty > 0) Character::where('id', $target->id)->decrement('dirty_cash', $fromDirty);
            }

            if ($actualRansom > 0) {
                $actualShare = (int) floor($actualRansom / $members->count());
                if ($actualShare > 0) {
                    Character::whereIn('id', $members->pluck('id')->all())->increment('dirty_cash', $actualShare);
                }
            }
        });

        $accompliceText = $accompliceCount === 1 ? 'an accomplice' : "{$accompliceCount} accomplices";
        $initiatorMsg   = "You managed to grab {$target->display_name} off the street with the help of {$accompliceText}, when they tried to scream for help, you let your {$weaponName} do the talking. Back at the {$propertyName}, you applied some enhanced persuasive techiques and they agreed to pay the ransom of {$formattedRansom} — you took your cut of {$formattedShare} and dumped them in an alley.";
        $participantMsg = "You helped {$initiator->display_name} snatch up {$target->display_name}, the {$weaponName} doing most of the persuading. After hours at the {$propertyName} the ransom came through — your share: {$formattedShare}.";

        foreach ($members as $member) {
            $member->stats?->addOffense(mt_rand(60, 120), true);
            \App\Models\CharacterHistory::addHistory($member, 'earned_actions', $share);
            if ($member->id === $initiator->id) continue;
            JournalService::custom($member->id, 'action_result', [
                'status'  => 'success',
                'message' => $participantMsg,
                'amount'  => $share,
            ]);
        }

        JournalService::custom($target->id, 'action_kidnapping', [
            'status'  => 'success',
            'message' => "You were suddenly snatched off the street and thrown into the back of a vehicle by a masked individual and  {$accompliceText}. After what seemed like an eternity of terror, your captors released you in an alley — they cleaned out {$formattedRansom} from your pockets and stash before dumping you. A professional, terrifying operation.",
            'amount'  => $ransom,
        ]);

        CrimeService::kidnapping($initiator, $members->pluck('id')->all(), $target, $city?->id ?? $initiator->home_city_id, $ransom);
        City::increaseCrimeRateById($city?->id ?? $initiator->home_city_id, 0.3);

        return $this->success($initiatorMsg);
    }

    

    private function handleFailure(Character $initiator, Character $target, Collection $members, array $weaponCommitments): RedirectResponse
    {
        DB::transaction(function () use ($initiator, $members, $target, $weaponCommitments) {
            Character::whereIn('id', $members->pluck('id')->all())->lockForUpdate()->get(['id']);

            foreach ($members as $member) {
                $committedItemId = $weaponCommitments[$member->id] ?? null;
                $weapon          = $committedItemId ? CharacterItem::with('template')->find($committedItemId) : null;
                $weapon?->delete();

                if ($member->id === $initiator->id) continue;

                
                $weaponClause = $weapon ? ', losing your weapon in the process' : '';

                JournalService::custom($member->id, 'action_result', [
                    'status'  => 'failed',
                    'message' => "The kidnapping went sideways when {$target->display_name} spotted the van. You fled{$weaponClause} — no score, just a close call.",
                ]);
            }
        });

        $accompliceCount = $members->count() - 1;
        $accompliceText  = $accompliceCount === 1 ? 'one accomplice' : "{$accompliceCount} accomplices";

        JournalService::custom($target->id, 'action_kidnapping', [
            'status'  => 'failure',
            'message' => "A group of armed individuals attempted to abduct you tonight. It fell apart — you created enough noise and chaos that they scattered before they could get you into a vehicle.",
        ]);

        return $this->error("You and {$accompliceText} had {$target->display_name} cornered, but they spotted the van and screamed. You scattered — no ransom, just a wasted night and a lost weapon.");
    }

    

    private function fail(int $initiatorId, array $memberIds, array $acceptedIds, string $reason): void
    {
        $this->cacheForget($this->cacheKey($initiatorId));

        DB::transaction(function () use ($memberIds, $acceptedIds, $initiatorId, $reason) {
            $members = Character::whereIn('id', $memberIds)->lockForUpdate()->get();

            foreach ($members as $member) {
                
                if ($member->id === $initiatorId || in_array($member->id, $acceptedIds, true)) {
                    $this->setCooldownHours($member, 2);
                    $this->resetStrength($member);
                }

                JournalService::custom($member->id, 'action_result', [
                    'status'  => 'failed',
                    'message' => 'The kidnapping operation fell through before it could be executed. The planning window expired and your crew has been stood down.',
                ]);
                $member->journals()->where('type', 'kidnapping_request')->delete();
            }

            Log::info('[Kidnapping] Operation collapsed.', [
                'initiator' => $initiatorId,
                'reason'    => $reason,
                'notified'  => count($memberIds),
                'penalized' => count($acceptedIds) + 1,
            ]);
        });
    }

    

    private function cacheKey(int $initiatorId): string { return "kidnapping_{$initiatorId}"; }

    private function getState(Character $character): ?array
    {
        $cacheData = $this->cacheGet($this->cacheKey($character->id));
        if (! $cacheData) return null;
        if (now()->timestamp > $cacheData['expires_at']) {
            $this->fail($character->id, $cacheData['required_members'], $cacheData['accepted_by'], 'expiry');
            return null;
        }
        return $cacheData;
    }

    private function getEquippedWeaponId(Character $character): ?int
    {
        if ($character->relationLoaded('items')) {
            $item = $character->items->first(fn ($i) => $i->is_equipped === true && $i->template?->type === 'weapon');
            return $item?->id;
        }
        return $character->items()->equipped()->whereHas('template', fn ($q) => $q->where('type', 'weapon'))->value('id');
    }

    private function getAvailableAccomplices(Character $character): array
    {
        return Character::inCity($character->city_id)
            ->where('id', '!=', $character->id)
            ->alive()
            ->whereDoesntHave('timers', function ($q) {
                $q->where('hospital_until', '>', now()->getTimestamp())
                    ->orWhere('jail_until', '>', now()->getTimestamp());
            })
            ->get(['id', 'display_name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->display_name])
            ->values()
            ->all();
    }
}
