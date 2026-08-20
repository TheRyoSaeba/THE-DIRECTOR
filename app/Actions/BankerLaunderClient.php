<?php

namespace App\Actions;

use App\Models\Character;
use App\Models\City;
use App\Models\LaunderOffer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BankerLaunderClient extends Action
{
    public const CUT_MIN = 0.05;
    public const CUT_MAX = 0.30;
    public const OVERHEAD = 0.06;

    private const COOLDOWN_MINUTES = 15;

    public static function maxAmount(int $bankBalance): int
    {
        return max(100_000, min(1_000_000, (int) floor($bankBalance * 0.25)));
    }

    public function chance(Character $character): int
    {

        $character->loadMissing(['stats']);
        $character->stats?->setRelation('character', $character);

        $effectiveStats = $character->stats?->effectiveStats() ?? [];
        $luck = max(1, (int) ($effectiveStats['luck'] ?? 1));
        $luckFactor = min(1.15, max(0.10, log10($luck) / 4.0));
        $luckBonus = ($luckFactor - 0.50) * 50;
        $city = $character->homeCity ?? City::find($character->home_city_id);
        $crimeBonus = $this->crimeRateBonus($city, 0.15);
        $statChance = 55 + $luckBonus + $crimeBonus;
//min of 75 and max of 99
        return (int) min(99, max(80, round($statChance)));
    }

    public function getId(): string
    {
        return 'banker_launder_client';
    }
    public function getIcon(): string
    {
        return 'Vault';
    }
    public function getButtonLabel(): string
    {
        return 'Send Funds';
    }

    public function getShape(Character $character): array
    {
        $offers = LaunderOffer::forClient($character->id)
            ->active()
            ->with(['banker:id,display_name,custom_avatar_url'])
            ->latest()
            ->get();

        $overheadPct = round(self::OVERHEAD * 100);

        $targets = $offers->map(function (LaunderOffer $o) {
            $queued = (int) ($o->amount_sent ?? 0);
            $subtitle = $queued > 0 ? 'Funds queued' : 'Awaiting funds';

            return [
                'id' => $o->id,
                'name' => $o->banker?->display_name ?? 'Unknown Banker',
                'subtitle' => $subtitle,

                'amount_sent' => $queued,
                'cut_pct' => (float) $o->cut_pct,
                'overhead' => self::OVERHEAD,
                'max_amount' => (int) $o->amount,
            ];
        })->values()->all();

        return [
            'id' => $this->getId(),
            'title' => 'Banker Laundering',
 
            'category' => 'Underground Economy',
            'description' => 'Send dirty cash to your banker to be cleaned through the bank\'s trade overinvoicing scheme.',
            'image_url' => 'https://images.thedirector.app/actions/banklaunder.png',
            'icon' => $this->getIcon(),
            'button_label' => $this->getButtonLabel(),
            'group_init_label' => null,
            'execute_route' => route('actions.banker-launder-client'),

            'cancel_route' => route('career.banking.launder.cancel', ['offer' => 0]),

            'refund_route' => route('actions.banker-launder-client-refund'),
            'is_group_action' => false,
            'available' => $offers->isNotEmpty(),
            'blocker' => $offers->isEmpty() ? 'No banker has made contact with you yet. A banker must reach out before you can use this service.' : null,
            'is_waiting' => false,
            'is_ready' => false,
            'active_members' => null,
            'targets' => $targets,
            'accomplices' => null,
            'has_amount_input' => true,
            'amount_label' => 'Dirty cash to send',
            'pick_label' => 'Select your banker',
            'target_icon' => 'vault',
        ];
    }

    public function canExecute(Character $character): array
    {

        if (!$this->isAvailable($character)) {
            return ['valid' => false, 'error' => 'You cannot send funds from a hospital bed or a jail cell.'];
        }
        if ($this->isOnCooldown($character)) {
            return ['valid' => false, 'error' => 'You need to wait before performing another action.'];
        }
        return ['valid' => true, 'error' => null];
    }



    public function execute(Character $character, array $params): RedirectResponse
    {
        $check = $this->canExecute($character);
        if (!$check['valid']) {
            return $this->error($check['error']);
        }

        $offerId = (int) ($params['target_id'] ?? 0);
        $sendAmount = (int) ($params['amount'] ?? 0);

        if ($offerId <= 0) {
            return $this->error('You must select a banker.');
        }
        if ($sendAmount < 1_000) {
            return $this->error('Minimum amount is $1,000.');
        }

        try {
            return DB::transaction(function () use ($character, $offerId, $sendAmount) {
                $character = Character::lockForUpdate()->find($character->id);
                $offer = LaunderOffer::lockForUpdate()->find($offerId);

                if (!$offer || $offer->client_id !== $character->id) {
                    return $this->error('Arrangement not found or unauthorized.');
                }
                if (!$offer->isActive()) {
                    return $this->error('This arrangement is no longer active.');
                }


                $banker = Character::with('homeCity')->lockForUpdate()->find($offer->banker_id);
                $bank = $banker?->homeCity
                    ? \App\Models\Business::forCity($banker->homeCity, 'bank')
                    : null;
                $liveMax = $bank ? self::maxAmount((int) $bank->balance) : 0;

                if ($liveMax <= 0) {
                    return $this->error("The banker's bank cannot currently accept laundering.");
                }


                if ($character->city_id !== $banker->home_city_id) {
                    return $this->error('You can only launder with a banker while you\'re in their city.');
                }


                $newTotal = ((int) ($offer->amount_sent ?? 0)) + $sendAmount;

                if ($newTotal > $liveMax) {
                    return $this->error(
                        'The Amount you are trying to send, combined with the amount you have already sent, exceeds the current bank capacity. Maximum is $' . number_format($liveMax) . '.'
                    );
                }
                if ((int) $character->dirty_cash < $sendAmount) {
                    return $this->error('You do not have $' . number_format($sendAmount) . ' in dirty cash.');
                }

                DB::table('characters')->where('id', $character->id)->decrement('dirty_cash', $sendAmount);


                $offer->update([
                    'amount_sent' => $newTotal,
                    'amount' => $liveMax,
                    'status' => LaunderOffer::STATUS_CLIENT_SENT,
                ]);

                $this->setCooldownMinutes($character, self::COOLDOWN_MINUTES);

                Log::info('[BankerLaunderClient] Funds sent.', [
                    'client' => $character->id,
                    'banker' => $offer->banker_id,
                    'offer' => $offer->id,
                    'sent' => $sendAmount,
                    'total' => $newTotal,
                    'live_max' => $liveMax,
                ]);

                return $this->success(
                    'You have successfully sent $' . number_format($sendAmount) . ' to your banker for laundering. '
                );
            });
        } catch (\Throwable $e) {
            Log::error('[BankerLaunderClient] Send failed.', ['character' => $character->id, 'error' => $e->getMessage()]);
            return $this->error('Send failed. Please try again.');
        }
    }



    public function refund(Character $character, array $params): RedirectResponse
    {
        $offerId = (int) ($params['target_id'] ?? 0);

        if ($offerId <= 0) {
            return $this->error('No arrangement selected.');
        }

        try {
            return DB::transaction(function () use ($character, $offerId) {
                $character = Character::lockForUpdate()->find($character->id);
                $offer = LaunderOffer::lockForUpdate()->find($offerId);

                if (!$offer || $offer->client_id !== $character->id) {
                    return $this->error('Arrangement not found or unauthorized.');
                }
                if (!$offer->isActive() || ($offer->amount_sent ?? 0) <= 0) {
                    return $this->error('No queued funds to reclaim.');
                }

                $refundAmount = (int) $offer->amount_sent;
                DB::table('characters')->where('id', $character->id)->increment('dirty_cash', $refundAmount);
                $offer->update(['amount_sent' => 0, 'status' => LaunderOffer::STATUS_PENDING]);

                Log::info('[BankerLaunderClient] Funds reclaimed.', [
                    'client' => $character->id,
                    'banker' => $offer->banker_id,
                    'amount' => $refundAmount,
                ]);

                return $this->success('$' . number_format($refundAmount) . 'has been returned to you.');
            });
        } catch (\Throwable $e) {
            Log::error('[BankerLaunderClient] Refund failed.', ['character' => $character->id, 'error' => $e->getMessage()]);
            return $this->error('Refund failed. Please try again.');
        }
    }
}
