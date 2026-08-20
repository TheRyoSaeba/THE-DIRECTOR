<?php

namespace App\Actions;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\City;
use App\Models\Property;
use App\Services\JournalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

//TODO add pet robot here

class PlantBomb extends Action
{
    private const ITEM_SLUG = 'rcied';
    private const COOLDOWN_MINS = 60;
    private const ALARM_PENALTY = 25;
    private const OFFLINE_BONUS = 15;
    private const EXPIRY_HOURS = 2;

    private const PROPERTY_PENALTIES = [
        'Rowhouse' => 0,
        'Apartment' => 5,
        'Estate' => 10,
        'Skyscraper Home' => 15,
        'Private Island' => 25,
    ];

    public function getId(): string
    {
        return 'plant_bomb';
    }
    public function getIcon(): string
    {
        return 'Warning';
    }
    public function getButtonLabel(): string
    {
        return 'Plant Device';
    }

    public function getShape(Character $character): array
    {

        $targets = $this->homeCityNameTargetOptions($character);

        return [
            'id' => $this->getId(),
            'title' => 'Plant Explosives',
         
            'category' => 'Underground Economy',
            'description' => "Plant a remote-detonated IED on a target's home. Wait for a quiet city, a high strength and a target who's asleep for your best chance of success. Planting on their home requires a two-parter, but their businesses are a simple one click job.",
            'image_url' => 'https://images.thedirector.app/properties/bombed.jpg',
            'icon' => $this->getIcon(),
            'button_label' => $this->getButtonLabel(),
            'group_init_label' => null,
            'execute_route' => route('actions.plant-bomb'),
            'cancel_route' => null,
            'is_group_action' => false,
            'available' => true,
            'blocker' => null,
            'is_waiting' => false,
            'is_ready' => false,
            'active_members' => null,
            'targets' => $targets,
            'accomplices' => null,
            'has_amount_input' => false,
            'amount_label' => null,
            'pick_label' => 'Select a target',
            'target_icon' => 'user',
        ];
    }

    public function canExecute(Character $character): array
    {
        if ($this->isOnCooldown($character)) {
            return ['valid' => false, 'error' => 'You need to wait before performing another action.'];
        }

        if (!$this->isAvailable($character)) {
            return ['valid' => false, 'error' => 'You cannot do this from a hospital bed or a jail cell.'];
        }

        if (!$this->hasReadyDevice($character)) {
            return ['valid' => false, 'error' => 'You do not have a RCIED device on hand.'];
        }

        return ['valid' => true, 'error' => null];
    }

    public function execute(Character $character, array $params): RedirectResponse
    {
        $check = $this->canExecute($character);
        if (!$check['valid']) {
            return $this->error($check['error']);
        }

        if ($character->created_at->diffInDays(now()) < 5) {
            return $this->error('Your character must be at least 5 days old to plant explosives.');
        }

        $targetName = trim($params['target_id'] ?? '');

        if (!$targetName || strtolower($targetName) === strtolower($character->display_name)) {
            return $this->error('You must select a valid target that is not yourself.');
        }

        $target = Character::whereRaw('LOWER(display_name) = LOWER(?)', [$targetName])->first();

        if (!$target || $target->deleted_at !== null) {
            return $this->error('Target not found.');
        }

        if (!$target->property_id || !$target->property) {
            return $this->error('This target does not own a property.');
        }

        if (!$character->isAlive()) {
            return $this->error('You are dead.');
        }

        if ($character->isAdmin()) {
            return $this->error('You cannot perform this action.');
        }

        if (!$target->isAlive()) {
            return $this->error('Target player is dead.');
        }

        if ($target->isAdmin()) {
            return $this->error('This player cannot be targeted.');
        }

        if ($this->isTargetOnActionCooldown($target)) {
            return $this->error("{$target->display_name} has recently had an action committed against them and is on high alert. Try again later.");
        }

        if ($character->city_id !== $target->home_city_id) {
            return $this->error("You must be in {$target->display_name}'s resident city to plant a bomb on their property.");
        }

        if (Property::hasBomb($target)) {
            $this->clearIfExpired($target);
            if (Property::hasBomb($target)) {
                return $this->error('While attempting to plant a bomb, you discovered that one had already been planted on the property.');
            }
        }

        if ($target->property_condition !== Property::CONDITION_CONSTRUCTED) {
            return $this->error("This property cannot be attacked at the moment.");
        }

        $character->loadMissing(['stats', 'items.template', 'timers']);
        $character->stats?->setRelation('character', $character);

        $city = City::find($character->city_id);
        $targetOffline = !$target->isOnline();
        $onlineInCity = Character::onlineInCity($character->city_id)->count();
        $chance = $this->chance($character, $target, $city, $targetOffline, $onlineInCity);
        $roll = mt_rand(1, 100);
        $isSuccess = $roll <= $chance;

        $this->logAttempt($character, $target, $chance, $roll, $isSuccess, $targetOffline, $onlineInCity);

        return DB::transaction(function () use ($character, $target, $isSuccess) {
            $character = Character::lockForUpdate()->find($character->id);
            $target = Character::with('property')->withTrashed()->lockForUpdate()->find($target->id);

            if (!$target || $target->deleted_at !== null) {
                return $this->error('Target is no longer available.');
            }

            if (!$target->isAlive()) {
                return $this->error('Target player is dead.');
            }

            if ($this->isTargetOnActionCooldown($target)) {
                return $this->error("{$target->display_name} has recently had an action committed against them and is on high alert. Try again later.");
            }

            if ($character->city_id !== $target->home_city_id) {
                return $this->error("You must be in {$target->display_name}'s resident city to plant a bomb on their property.");
            }

            if (Property::hasBomb($target)) {
                $this->clearIfExpired($target);
                if (Property::hasBomb($target)) {
                    return $this->error('While attempting to plant a bomb, you discovered that one had already been planted on the property.');
                }
            }

            if ($target->property_condition !== Property::CONDITION_CONSTRUCTED) {
                return $this->error("This property cannot be attacked at the moment.");
            }

            $rcied = $this->lockReadyDevice($character);

            if (!$rcied) {
                return $this->error('Your RCIED device could not be located.');
            }

            $this->resetStrength($character);
            $this->setCooldownMinutes($character, self::COOLDOWN_MINS);
            $this->setTargetActionCooldown($target, 60 * 60, 120 * 60);

            return $isSuccess
                ? $this->handleSuccess($character, $target, $rcied)
                : $this->handleFailure($character, $target, $rcied);
        });
    }

    private function handleSuccess(Character $character, Character $target, $rcied): RedirectResponse
    {
        $target->property_condition = Property::CONDITION_BOMB;
        $target->save();

        $rcied->update([
            'data' => [
                'target_character_id' => $target->id,
                'planted_at' => now()->toIso8601String(),
            ],
        ]);

        $propertyName = $target->property->name ?? 'their residence';

        $character->stats?->addLuck(mt_rand(50, 100), true);

        return $this->success("You managed to sneak through undetected around {$target->display_name}'s {$propertyName} and rigged an important load bearing structure  with a bomb, now all you have to do is go to your inventory and detonate it !");
    }

    private function handleFailure(Character $character, Character $target, $rcied): RedirectResponse
    {

        $rcied->delete();

        $healthLoss = (int) floor($character->health * 0.20);
        $maxHealthLoss = (int) floor($character->max_health * 0.03);

        $isKill = ($character->health - $healthLoss <= 20) && (random_int(1, 100) <= 33);

        Log::info('[BombAudit] BACKFIRE', [
            'attacker_id' => $character->id,
            'attacker_name' => $character->display_name,
            'health_loss' => $healthLoss,
            'max_loss' => $maxHealthLoss,
            'is_lethal' => $isKill,
            'hp_before' => $character->health,
            'hp_after' => $isKill ? 0 : max(1, $character->health - $healthLoss),
        ]);

        if ($isKill) {
            $character->kill('Explosive', "Your Bomb backfired and blew up in your face, normally you would have survived, but you were already weakened.");

            return redirect()->route('death')->with('error', 'You are dead.');
        }
        $newHealth = max(1, $character->health - $healthLoss);
        $newMaxHealth = max($newHealth, max(1, $character->max_health - $maxHealthLoss));

        $character->update([
            'health' => $newHealth,
            'max_health' => $newMaxHealth,
        ]);

        JournalService::custom($target->id, 'bomb_plant_failed', [
            'result' => 'failed_plant',
            'attacker_name' => $character->display_name,
        ]);

        return $this->error(
            "The device detonated prematurely during the plant. You survived — barely. "
            . "You lost {$healthLoss} HP and suffered a permanent injury of {$maxHealthLoss} max HP."
        );
    }

    private function chance(
        Character $attacker,
        Character $target,
        ?City $city,
        bool $targetOffline,
        int $onlineInCity
    ): int {
        $luck = max(1, $attacker->stats?->effectiveStats()['luck'] ?? 1);

        $luckFactor = min(50, max(0, (log10($luck) - 2) * 11));
        $crimeBonus = $this->crimeRateBonus($city, 0.15);
        $offlineBonus = $targetOffline ? self::OFFLINE_BONUS : 0;
        $tierPenalty = self::PROPERTY_PENALTIES[$target->property?->name ?? ''] ?? 0;
        $alarmPenalty = ($target->property?->has_alarm ?? false) ? self::ALARM_PENALTY : 0;

        $statChance = 20 + $luckFactor + $crimeBonus + $offlineBonus - $tierPenalty - $alarmPenalty;

        $cityMultiplier = max(0.15, min(1.0, 1 - (($onlineInCity - 1) * 0.1)));

        $strengthMultiplier = max(0.15, sqrt($this->getStrength($attacker) / 100));


        return (int) max(1, min(90, (int) round($statChance * $strengthMultiplier * $cityMultiplier)));
    }

    private function hasReadyDevice(Character $character): bool
    {
        return $character->items()
            ->whereHas('template', fn($q) => $q->where('slug', 'ILIKE', self::ITEM_SLUG))
            ->where('location', 'on_hand')
            ->where(fn($q) => $q
                ->whereNull('data')
                ->orWhereRaw("data->>'target_character_id' IS NULL"))
            ->exists();
    }

    private function lockReadyDevice(Character $character)
    {
        return $character->items()
            ->whereHas('template', fn($q) => $q->where('slug', 'ILIKE', self::ITEM_SLUG))
            ->where('location', 'on_hand')
            ->where(fn($q) => $q
                ->whereNull('data')
                ->orWhereRaw("data->>'target_character_id' IS NULL"))
            ->lockForUpdate()
            ->first();
    }
    // ? this technically lets you keep replanting your bomb if it's expired
    private function clearIfExpired(Character $target): void
    {
        $rcied = CharacterItem::whereHas(
            'template',
            fn($q) => $q->where('slug', 'ILIKE', self::ITEM_SLUG)
        )
            ->whereRaw("data->>'target_character_id' = ?", [(string) $target->id])
            ->first();

        if (!$rcied || empty($rcied->data['planted_at'])) {
            return;
        }

        if (Carbon::parse($rcied->data['planted_at'])->addHours(self::EXPIRY_HOURS)->isPast()) {

            $rcied->update(['data' => null]);
            $target->property_condition = Property::CONDITION_CONSTRUCTED;
            $target->save();
        }
    }

    private function logAttempt(
        Character $attacker,
        Character $target,
        int $chance,
        int $roll,
        bool $isSuccess,
        bool $targetOffline,
        int $onlineInCity
    ): void {
        Log::info('[BombAudit] PLANT ATTEMPT', [
            'attacker' => $attacker->id,
            'target' => $target->id,
            'property' => $target->property?->name,
            'has_alarm' => $target->property?->has_alarm,
            'target_offline' => $targetOffline,
            'online_in_city' => $onlineInCity,
            'chance' => $chance,
            'roll' => $roll,
            'outcome' => $isSuccess ? 'SUCCESS' : 'FAILURE',
        ]);
    }
}
