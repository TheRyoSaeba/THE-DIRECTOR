<?php

namespace App\Actions;

use App\Models\Business;
use App\Models\Character;
use App\Models\City;
use App\Models\Corporation;
use App\Models\CorporationProperty;
use App\Services\JournalService;
use App\Services\CrimeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Extortion extends Action
{
    private const EXTORTION_MIN_PERCENT = 5;
    private const EXTORTION_MAX_PERCENT = 15;
    private const MAX_STEAL_AMOUNT = 100000;

    public function getId(): string
    {
        return 'extortion';
    }
    public function getIcon(): string
    {
        return 'DesktopIcon';
    }
    public function getButtonLabel(): string
    {
        return 'Extort Business';
    }

    public function getShape(Character $character): array
    {
        $targets = array_merge(
            $this->activeBusinessNameOptions($character),
            $this->extortableHqOptions($character),
        );

        return [
            'id' => $this->getId(),
            'title' => 'Business Extortion',
 
            'category' => 'Underground Economy',
            'description' => 'Purchase and deploy a ransomware as a service program to extort  businesses and corporations. Success depends on your offensive abilities, strength and how many people are buzzing around the city.',
            'image_url' => 'https://images.thedirector.app/actions/extort.png',
            'icon' => $this->getIcon(),
            'button_label' => $this->getButtonLabel(),
            'group_init_label' => null,
            'execute_route' => route('actions.extortion'),
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
            'pick_label' => 'Select a business',
            'target_icon' => 'store',
        ];
    }

    public function canExecute(Character $character): array
    {
        if ($this->isStrengthDepleted($character)) {
            return ['valid' => false, 'error' => 'You are too exhausted to attempt this. Wait for your strength to recover.'];
        }
        if (!$this->isAvailable($character)) {
            return ['valid' => false, 'error' => 'You cannot pull off an extortion right now.'];
        }


        if ($character->cash_on_hand < 1000) {
            return ['valid' => false, 'error' => 'You need at least $1,000 in cash to purchase the ransomware as a service.'];
        }

        return ['valid' => true, 'error' => null];
    }

    public function execute(Character $character, array $params): \Illuminate\Http\RedirectResponse
    {
        $check = $this->canExecute($character);
        if (!$check['valid']) {
            return $this->error($check['error']);
        }

        $targetName = trim($params['target_id'] ?? '');
        if (!$targetName) {
            return $this->error('You must select a business target.');
        }


        if (str_starts_with($targetName, 'hq:')) {
            return $this->executeOnHq($character, trim(substr($targetName, 3)));
        }

        return DB::transaction(function () use ($character, $targetName) {
            $character = Character::lockForUpdate()->find($character->id);
            $business = Business::whereRaw('LOWER(name) = LOWER(?)', [$targetName])->lockForUpdate()->first();

            if (!$business) {
                return $this->error('Business not found.');
            }

            if ($business->city_id !== $character->city_id) {
                return $this->error('The selected business is not in your current city.');
            }

            if (!$business->is_active) {
                return $this->error('This business is inactive and cannot be targeted.');
            }


            if ($business->last_extorted_at && Carbon::parse($business->last_extorted_at)->isFuture()) {
                return $this->error('This business has been hit too recently and their networks are locked down. Try again later.');
            }

            $character->loadMissing(['stats', 'property', 'corporation']);
            $character->stats?->setRelation('character', $character);
            $owner = $business->owner_id ? Character::with('stats')->find($business->owner_id) : null;
            $owner?->stats?->setRelation('character', $owner);


            if ($business->owner_id && $business->owner_id === $character->id) {
                return $this->error('You cannot extort your own business.');
            }

            $city = City::find($character->city_id);
            $onlineInCity = Character::onlineInCity($character->city_id)->count();
            $ownerDefense = max(1, $owner?->stats?->effectiveStats()['defense'] ?? 1);

            $chance = $this->chance($character, $city, $ownerDefense, $onlineInCity);
            $roll = mt_rand(1, 100);
            $isSuccess = $roll <= $chance;

            Log::info('[Extortion] Attempt', [
                'attacker' => $character->id,
                'business' => $business->name,
                'business_id' => $business->id,
                'owner_id' => $business->owner_id,
                'owner_defense' => $ownerDefense,
                'online_in_city' => $onlineInCity,
                'chance' => $chance,
                'roll' => $roll,
                'outcome' => $isSuccess ? 'SUCCESS' : 'FAILURE',
            ]);
            $character->decrement('cash_on_hand', 1000);
            $this->resetStrength($character);
            $business->update([
                'last_extorted_at' => now()->addMinutes(mt_rand(45, 120)),
            ]);

            if (!$isSuccess) {
                if ($owner) {
                    JournalService::custom($owner->id, 'action_ransomware_attack', [
                        'status' => 'failure',
                        'message' => "Your security systems at {$business->name} detected and blocked an attempted ransomware attack. None of your data was compromised.",
                    ]);
                }
                return $this->error("Your ransomware failed to penetrate {$business->name}'s defenses. Their security system detected the intrusion and locked you out.");
            }

            $businessBalance = $business->balance;

            $extortPercent = mt_rand(self::EXTORTION_MIN_PERCENT, self::EXTORTION_MAX_PERCENT) / 100;
            $stolen = (int) floor($businessBalance * $extortPercent);

            if ($stolen > self::MAX_STEAL_AMOUNT) {
                $stolen = self::MAX_STEAL_AMOUNT;
            }
            if ($stolen < 10) {
                $stolen = $businessBalance;
            }


            $business->removeBalance($stolen);


            $character->addCash($stolen, true);


            $character->stats?->addOffense(mt_rand(50, 100), true);
            \App\Models\CharacterHistory::addHistory($character, 'earned_actions', $stolen);

            CrimeService::extortion($character, $character->city_id, $stolen, $business->name);
            City::increaseCrimeRateById($character->city_id, 0.3);

            if ($owner) {
                $financeDegree = $owner->getDegree('finance') && $owner->getDegree('finance')['completed_at'];
                $additionalMsg = $financeDegree
                    ? " Given your financial expertise, you were able to trace the payments back to {$character->display_name}."
                    : "";
                JournalService::custom($owner->id, 'action_ransomware_attack', [
                    'status' => 'success',
                    'message' => "Someone deployed a ransomware attack against The {$business->name}, encrypting your system and forcing you to pay $" . number_format($stolen) . " out of the business's funds as a ransom to decrypt your files." . $additionalMsg,
                ]);
            }


            return $this->success("You successfully deployed the ransomware and  hijacked {$business->name}'s network, forcing them to pay \$" . number_format($stolen) . " in dirty money as a ransom.");
        });
    }

    private function chance(Character $attacker, ?City $city, int $ownerDefense, int $onlineInCity, int $hqTier = 0): int
    {
        $offense = max(1, $attacker->stats?->effectiveStats()['offense'] ?? 1);
        $offenseFactor = min(70, max(0, (log10($offense) - 2) * 20));

        $crimeBonus = $this->crimeRateBonus($city, 0.15);

        $defensePenalty = min(15, (int) round(sqrt($ownerDefense) / 25));


        $tierPenalty = max(0, $hqTier * 10);

        $statChance = 5 + $offenseFactor + $crimeBonus - $defensePenalty - $tierPenalty;

        $cityMultiplier = max(0.15, min(1.0, 1 - (($onlineInCity - 1) * 0.1)));

        $strengthMultiplier = max(0.15, sqrt($this->getStrength($attacker) / 100));

        return (int) max(1, min(95, (int) round($statChance * $strengthMultiplier * $cityMultiplier)));
    }

    private function extortableHqOptions(Character $character): array
    {

        return Corporation::inCity($character->city_id)
            ->active()
            ->where('is_holding_company', false)
            ->whereHas('properties', fn($q) => $q
                ->where('type', CorporationProperty::TYPE_HQ)
                ->where('condition', CorporationProperty::CONDITION_CONSTRUCTED))
            ->with([
                'properties' => fn($q) => $q
                    ->where('type', CorporationProperty::TYPE_HQ)
                    ->where('condition', CorporationProperty::CONDITION_CONSTRUCTED)
            ])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function ($corp) {
                $hqName = $corp->properties->first()?->name;
                return [

                    'id' => 'hq:' . $corp->name,
                    'name' => $corp->name . "'s " . $hqName,
                ];
            })
            ->values()
            ->all();
    }

    private function executeOnHq(Character $character, string $corporationName): \Illuminate\Http\RedirectResponse
    {
        return DB::transaction(function () use ($character, $corporationName) {
            $character = Character::lockForUpdate()->find($character->id);

            $corp = Corporation::whereRaw('LOWER(name) = LOWER(?)', [$corporationName])
                ->where('home_city_id', $character->city_id)
                ->where('is_holding_company', false)
                ->lockForUpdate()
                ->first();

            if (!$corp) {
                return $this->error('Target corporation not found in your city.');
            }

            $hq = $corp->properties()
                ->where('type', CorporationProperty::TYPE_HQ)
                ->where('condition', CorporationProperty::CONDITION_CONSTRUCTED)
                ->lockForUpdate()
                ->first();

            if (!$hq) {
                return $this->error('That corporation has no operational HQ to extort.');
            }

            if ($hq->isProtected()) {
                return $this->error('This Corporation has been hit too recently and their networks are locked down. Try again later.');
            }
            if ($character->corporation_id && (int) $character->corporation_id === (int) $corp->id) {
                return $this->error('You cannot extort your own corporation.');
            }

            $character->loadMissing(['stats', 'property', 'corporation']);
            $character->stats?->setRelation('character', $character);

            $ceo = $corp->ceo;
            $ceo?->loadMissing('stats');
            $ceo?->stats?->setRelation('character', $ceo);

            $ceoDefense = max(1, $ceo?->stats?->effectiveStats()['defense'] ?? 1);

            $city = City::find($character->city_id);
            $onlineInCity = Character::onlineInCity($character->city_id)->count();

            $chance = $this->chance($character, $city, $ceoDefense, $onlineInCity, (int) $hq->tier);
            $roll = mt_rand(1, 100);
            $isSuccess = $roll <= $chance;

            Log::info('[Extortion][HQ] Attempt', [
                'attacker' => $character->id,
                'corporation_id' => $corp->id,
                'corporation_name' => $corp->name,
                'hq_tier' => $hq->tier,
                'ceo_id' => $corp->ceo_id,
                'ceo_defense' => $ceoDefense,
                'online_in_city' => $onlineInCity,
                'chance' => $chance,
                'roll' => $roll,
                'outcome' => $isSuccess ? 'SUCCESS' : 'FAILURE',
            ]);

            $character->decrement('cash_on_hand', 1000);
            $this->resetStrength($character);


            $hq->update([
                'protection_until' => now()->addMinutes(mt_rand(90, 120)),
            ]);

            if (!$isSuccess) {
                if ($ceo) {
                    JournalService::custom($ceo->id, 'action_ransomware_attack', [
                        'status' => 'failure',
                        'message' => "{$corp->name}'s security team detected and shut down a ransomware probe targeting the {$hq->name}. Corporate data was not compromised.",
                    ]);
                }
                return $this->error("Your ransomware failed to penetrate {$corp->name}'s defenses. Their security team detected the intrusion and locked you out.");
            }

            $reserves = (int) $corp->cash_reserves;
            $extortPercent = mt_rand(self::EXTORTION_MIN_PERCENT, self::EXTORTION_MAX_PERCENT) / 100;
            $stolen = (int) floor($reserves * $extortPercent);

            if ($stolen > self::MAX_STEAL_AMOUNT) {
                $stolen = self::MAX_STEAL_AMOUNT;
            }
            if ($stolen < 10) {
                $stolen = $reserves;
            }

            if ($stolen <= 0) {
                return $this->error("You unleashed a deadly ransomware attack on {$corp->name}'s servers, but the company was broke and couldn't pay a dime!");
            }

            $corp->decrement('cash_reserves', $stolen);
            $character->addCash($stolen, true);
            $character->stats?->addOffense(mt_rand(50, 100), true);
            \App\Models\CharacterHistory::addHistory($character, 'earned_actions', $stolen);

            CrimeService::extortion($character, $character->city_id, $stolen, $corp->name);
            City::increaseCrimeRateById($character->city_id, 0.3);

            if ($ceo) {
                $financeDegree = $ceo->getDegree('finance') && $ceo->getDegree('finance')['completed_at'];
                $additionalMsg = $financeDegree
                    ? " Given your financial expertise, you were able to trace the payments back to {$character->display_name}."
                    : "";
                JournalService::custom($ceo->id, 'action_ransomware_attack', [
                    'status' => 'success',
                    'message' => "Someone deployed a ransomware attack against your company's {$hq->name}, encrypting corporate systems and forcing the company to pay $" . number_format($stolen) . " out of its cash reserves." . $additionalMsg,
                ]);
            }

            return $this->success("You hijacked {$corp->name}'s corporate network and forced them to wire \$" . number_format($stolen) . " in dirty money out of their reserves.");
        });
    }
}
