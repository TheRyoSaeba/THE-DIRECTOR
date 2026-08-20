<?php

namespace App\Actions;

use App\Models\Character;
use App\Models\City;
use App\Services\JournalService;
use App\Services\CrimeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CryptoRugPull extends Action
{
    public function getId(): string
    {
        return 'crypto_rug_pull';
    }
    public function getIcon(): string
    {
        return 'CurrencyBtcIcon';
    }
    public function getButtonLabel(): string
    {
        return 'Run the Scheme';
    }

    public function getShape(Character $character): array
    {

        $targets = $this->cityTargetOptions($character);

        return [
            'id' => $this->getId(),
            'title' => 'Crypto Rug Pull',
 
            'category' => 'Underground Economy',
            'description' => 'Approach another player with a fake cryptocurrency scheme. Your success depends on your strength and wit.',
            'image_url' => 'https://images.thedirector.app/actions/monorailsalesman.jpg',
            'icon' => $this->getIcon(),
            'button_label' => $this->getButtonLabel(),
            'group_init_label' => null,
            'execute_route' => route('actions.crypto-rug-pull'),
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
        if ($this->isStrengthDepleted($character)) {
            return ['valid' => false, 'error' => 'You are too exhausted to attempt this. Wait for your strength to recover.'];
        }
        if (!$this->isAvailable($character)) {
            return ['valid' => false, 'error' => 'You cannot pull off this scheme in your current state!'];
        }
        return ['valid' => true, 'error' => null];
    }

    public function execute(Character $character, array $params): \Illuminate\Http\RedirectResponse
    {
        $check = $this->canExecute($character);
        if (!$check['valid']) {
            return $this->error($check['error']);
        }

        $targetId = $params['target_id'] ?? null;
        if (!$targetId || !is_numeric($targetId)) {
            return $this->error('You must select a target.');
        }

        $character->loadMissing(['stats', 'items.template', 'property', 'corporation']);
        $character->stats?->setRelation('character', $character);

        $target = Character::with(['stats', 'items.template', 'property', 'corporation'])->find($targetId);
        $target?->stats?->setRelation('character', $target);

        if (!$target)
            return $this->error('Target player not found.');
        if ($target->id === $character->id)
            return $this->error('You cannot scam yourself.');
        if ($target->city_id !== $character->city_id)
            return $this->error('Target must be in the same city.');
        if (!$target->isOnline())
            return $this->error('Target must be online.');
        if (!$this->isAvailable($target))
            return $this->error('You cannot possibly pull off this scheme against them in their state!');
        if ($this->isTargetOnActionCooldown($target))
            return $this->error("{$target->display_name} has recently had an action committed against them and is on high alert. Try again later.");

        $chance = $this->chance($character, $target);
        $roll = mt_rand(1, 100);
        $isSuccess = $roll <= $chance;

        $this->logAttempt($character, $target, $chance, $roll, $isSuccess);

        $this->resetStrength($character);
        
        $this->setTargetActionCooldown($target, 15 * 60, 30 * 60);

        if (!$isSuccess) {
            JournalService::custom($target->id, 'action_crypto_rug_pull', [
                'status' => 'failure',
                'message' => "{$character->display_name} cornered you at the bar and spent hours hyping up a worthless crypto coin. You rolled your eyes and walked away before they could drain your wallet.",
            ]);
            return $this->error("{$target->display_name} saw right through your pitch. You wasted your breath and gained nothing.");
        }

        $stolen = $this->transfer($character, $target);

        if ($stolen <= 0) {
            JournalService::custom($target->id, 'action_crypto_rug_pull', [
                'status' => 'failure',
                'message' => "{$character->display_name} cornered you at the bar and spent hours hyping up an amazing crypto investment opportunity, you wanted to get in on it but remembered you were broke and sulked away.",
            ]);
            return $this->error("You pitched your fake crypto to {$target->display_name}, but they had no cash to invest and you simply wasted your time.");
        }

        $character->stats->addIntelligence(mt_rand(5, 10), true);
        \App\Models\CharacterHistory::addHistory($character, 'earned_actions', $stolen);

        CrimeService::rugPull($character, $target, $character->city_id, $stolen);
        \App\Models\City::increaseCrimeRateById($character->city_id, 0.1);

        JournalService::custom($target->id, 'action_crypto_rug_pull', [
            'status' => 'success',
            'message' => "{$character->display_name} sold you on a fake cryptocurrency that was sure to explode and make you millions, unfortunately it collapsed and you lost \${$stolen}. The money is gone forever.",
            'amount' => $stolen,
        ]);

        return $this->success("Scam successful! You convinced {$target->display_name} to invest $" . number_format($stolen) . " in your worthless crypto scheme before you made off with all the profits.");
    }

    private function chance(Character $attacker, Character $target): int
    {
        $attackerStats = $attacker->stats?->effectiveStats() ?? ['intelligence' => 0];
        $targetStats = $target->stats?->effectiveStats() ?? ['intelligence' => 0];

        $intRatio = $attackerStats['intelligence'] / max(1, $targetStats['intelligence']);
        $intFactor = log($intRatio, 2) * 25;

        $crimeBonus = $this->crimeRateBonus(City::find($attacker->city_id), 0.15);

        $statChance = 70 + $intFactor + $crimeBonus;
        $strengthMultiplier = max(0.15, sqrt($this->getStrength($attacker) / 100));

        return (int) max(10, min(99, (int) round($statChance * $strengthMultiplier)));
    }

    private function transfer(Character $attacker, Character $target): int
    {
        return DB::transaction(function () use ($attacker, $target) {
            $attacker = Character::lockForUpdate()->find($attacker->id);
            $target = Character::lockForUpdate()->find($target->id);

            $totalCash = $target->dirty_cash + $target->cash_on_hand;
            $maxSteal = min(20000, (int) floor($totalCash * 0.70));
            if ($maxSteal < 10)
                return 0;

            $stolen = random_int(10, $maxSteal);
            $takeFromDirty = min($target->dirty_cash, $stolen);
            $target->dirty_cash -= $takeFromDirty;
            $remaining = $stolen - $takeFromDirty;
            if ($remaining > 0) {
                $target->cash_on_hand -= $remaining;
            }
            $target->save();
            $attacker->dirty_cash += $stolen;
            $attacker->save();
            return $stolen;
        });
    }

    private function logAttempt(Character $attacker, Character $target, int $chance, int $roll, bool $isSuccess): void
    {
        Log::info('[CryptoRugPull] Attempt', [
            'attacker' => $attacker->id,
            'target' => $target->id,
            'chance' => $chance,
            'roll' => $roll,
            'outcome' => $isSuccess ? 'SUCCESS' : 'FAILURE',
        ]);
    }
}
