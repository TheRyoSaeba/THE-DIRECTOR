<?php

namespace App\Actions;

use App\Models\Business;
use App\Models\Character;
use App\Models\City;
use App\Services\CrimeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

//! HALF ACTION HALF CAREER ACTIVITY.
class Launder extends Action
{
    public function getId(): string
    {
        return 'launder';
    }
    public function getIcon(): string
    {
        return 'Shuffle';
    }
    public function getButtonLabel(): string
    {
        return 'Launder Funds';
    }

    public function getShape(Character $character): array
    {
        
        $businesses = Business::where('owner_id', $character->id)
            ->where('city_id', $character->city_id)
            ->where('is_active', true)
            ->get(['id', 'name', 'balance']);

        return [
            'id' => $this->getId(),
            'title' => 'Money Laundering',
         
            'category' => 'Underground Economy',
            'description' => 'Clean your dirty cash through one of your businesses. The business needs enough in its balance to make it plausible, and there will be a small overhead. Success depends on your strength and wit.',
            'image_url' => 'https://images.thedirector.app/actions/launder.jpg',
            'icon' => $this->getIcon(),
            'button_label' => $this->getButtonLabel(),
            'group_init_label' => null,
            'execute_route' => route('actions.launder'),
            'cancel_route' => null,
            'is_group_action' => false,
            'available' => true,
            'blocker' => null,
            'is_waiting' => false,
            'is_ready' => false,
            'active_members' => null,
            'targets' => $businesses->map(fn($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'subtitle' => 'Max Wash: $' . number_format(min(50000, (int) floor($b->balance * 0.25))),
                'maxValue' => min(50000, (int) floor($b->balance * 0.25)),
            ])->values()->all(),
            'accomplices' => null,
            'has_amount_input' => true,
            'amount_label' => 'Launder Amount',
            'pick_label' => 'Select a shop',
            'target_icon' => 'store',
        ];
    }

    public function canExecute(Character $character): array
    {
        if ($this->isOnCooldown($character)) {
            return ['valid' => false, 'error' => 'You need to wait before performing another action.'];
        }
        if (!$this->isAvailable($character)) {
            return ['valid' => false, 'error' => 'You are currently unavailable (hospitalized or jailed).'];
        }
        if ($character->dirty_cash <= 0) {
            return ['valid' => false, 'error' => 'You do not have any dirty cash to launder.'];
        }
        return ['valid' => true, 'error' => null];
    }

    public function execute(Character $character, array $params): RedirectResponse
    {
        $check = $this->canExecute($character);
        if (!$check['valid']) {
            return $this->error($check['error']);
        }

        $businessId = $params['target_id'] ?? $params['business_id'] ?? null;
        $amount = isset($params['amount']) ? (int) $params['amount'] : 0;

        if (!$businessId || !is_numeric($businessId)) {
            return $this->error('You must select a shop.');
        }
        if ($amount <= 0) {
            return $this->error('Invalid amount to launder.');
        }
        if ($amount > 50000) {
            return $this->error('You cannot launder that much money through this crude method! Larger amounts requires better and more sophisicated methods, try laundering through a banker instead.');
        }

        $result = DB::transaction(function () use ($character, $businessId, $amount) {
            $charLock = Character::with(['stats', 'items.template', 'property', 'corporation', 'timers'])
                ->lockForUpdate()
                ->find($character->id, ['*']);

            $businessLock = Business::lockForUpdate()
                ->where('id', $businessId)
                ->where('owner_id', $charLock->id)
                ->where('city_id', $charLock->city_id)
                
                ->where('is_active', true)
                ->first();

            if (!$businessLock) {
                return ['success' => false, 'error' => 'Invalid, inactive, or unauthorized shop selected.'];
            }
            if ($amount > $charLock->dirty_cash) {
                return ['success' => false, 'error' => 'You do not have that much dirty cash.'];
            }

            $maxByBalance = (int) floor($businessLock->balance * 0.25);
            if ($amount > $maxByBalance) {
                return ['success' => false, 'error' => "You can only launder up to 25% of the shop's balance ($" . number_format($maxByBalance) . ")."];
            }

            if ($charLock->stats) {
                $charLock->stats->setRelation('character', $charLock);
            }

            $chance = $this->chance($charLock);
            $roll = mt_rand(1, 100);
            $isSuccess = $roll <= $chance;

            $this->logAttempt($charLock, $businessLock, $amount, $chance, $roll, $isSuccess);

            if (!$isSuccess) {
                $charLock->dirty_cash -= $amount;
                $charLock->save();
                return ['success' => false, 'failed_action' => true, 'amount' => $amount, 'business_name' => $businessLock->name];
            }

          
            $charLock->dirty_cash -= $amount;
            $charLock->save();
            $businessLock->balance += $amount;
            $businessLock->save();

            CrimeService::moneyLaundering($charLock, $charLock->city_id, $amount, $businessLock->name, false);

            \App\Models\City::increaseCrimeRateById($charLock->city_id, 0.1);

            return ['success' => true, 'amount' => $amount, 'clean_amount' => $amount, 'business_name' => $businessLock->name];
        });

        if (isset($result['error'])) {
            return $this->error($result['error']);
        }

        $this->resetStrength($character);
        $this->setCooldownMinutes($character, 15);

        if (isset($result['failed_action']) && $result['failed_action']) {
            return $this->error("The authorities noticed unusual activity and froze the funds in transit. You lost $" . number_format($result['amount']) . " of dirty cash!");
        }
        

        $character->stats->addLuck(mt_rand(10, 20), true);
        return $this->success("You have successfully washed $" . number_format($result['amount']) . " dirty cash through {$result['business_name']}. The profits have been transferred to your business's balance.");
    }

    private function chance(Character $character): int
    {
        $stats        = $character->stats?->effectiveStats() ?? ['intelligence' => 0];
        $intelligence = max(1, $stats['intelligence']);
        $intFactor  = min(35, max(0, (log10($intelligence) - 2) * 11));
        $crimeBonus = $this->crimeRateBonus(City::find($character->city_id), 0.15);
        $statChance         = 50 + $intFactor + $crimeBonus;
        $strengthMultiplier = max(0.15, sqrt($this->getStrength($character) / 100));

        return (int) max(1, min(75, (int) round($statChance * $strengthMultiplier)));
    }

    private function logAttempt(Character $character, Business $business, int $amount, int $chance, int $roll, bool $isSuccess): void
    {
        Log::info('[MoneyLaundering] Attempt', [
            'character' => $character->id,
            'business' => $business->id,
            'amount' => $amount,
            'chance' => $chance,
            'roll' => $roll,
            'outcome' => $isSuccess ? 'SUCCESS' : 'FAILURE',
        ]);
    }
}
