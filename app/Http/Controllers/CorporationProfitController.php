<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\CharacterJournal;
use App\Models\City;
use App\Models\Corporation;
use App\Models\CorporationProperty;
use App\Models\BankTransaction;
use App\Models\Career;
use App\Services\JournalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CorporationProfitController extends Controller
{
    private const MAX_OFFSHORE_TRUST_BALANCE = 20_000_000;

    public function produceMedical(Request $request)
    {
        $request->validate([
            'corporation_id' => 'nullable|integer|exists:corporations,id',
            'target_id' => 'nullable',
        ]);

        $character = $request->user()->getLoadedCharacter();
        $corpId = (int) ($request->corporation_id ?? $request->target_id ?? 0);

        if (!$character) {
            return back()->with('error', 'No active character found.');
        }

        if ($corpId <= 0) {
            return back()->with('error', 'Choose a valid operating corporation.');
        }

        try {
            return DB::transaction(function () use ($character, $corpId) {
                $character = Character::with('timers')
                    ->lockForUpdate()
                    ->find($character->id);

                if ($blocker = $this->medicalWorkerBlocker($character)) {
                    return back()->with('error', $blocker);
                }

                $corp = $this->lockedOperatingCorporation($corpId);
                if (!$corp) {
                    return back()->with('error', 'Choose a valid operating corporation.');
                }

                if ((int) $character->city_id !== (int) $corp->home_city_id) {
                    return back()->with('error', 'You need to be in the corporation home city to produce this medicine');
                }

                $property = $corp->lockedMedicalProperty();
                if (!$property) {
                    return back()->with('error', 'That corporation needs a medical property.');
                }

                if (!$property->isOperational()) {
                    return back()->with('error', "That corporation's building is currently under maintenance and cannot be worked at!");
                }

                $packs = random_int(1, 3);
                $product = CorporationProperty::chooseMedicalProduct();

                $data = CorporationProperty::normalizeMedicalData($property->data);
                $payout = $packs * $data['medical_payout_per_pack'];

                if ((int) $corp->slush_fund < $payout) {
                    return back()->with('error', 'The company cannot fund a production run.');
                }

                $property->addStockedMedicalPacks($product, $packs, $payout);
                $corp->decrement('slush_fund', $payout);
                $character->increment('dirty_cash', $payout);
                $this->setCooldown($character, 10);

                Log::info('[CorporationProfit] Medical production succeeded.', [
                    'character_id' => $character->id,
                    'corporation_id' => $corp->id,
                    'property_id' => $property->id,
                    'product' => $product,
                    'product_label' => CorporationProperty::MEDICAL_PRODUCTS[$product] ?? $product,
                    'packs' => $packs,
                    'payout_per_pack' => $data['medical_payout_per_pack'],
                    'total_payout' => $payout,
                    'corp_slush_after' => (int) $corp->fresh()->slush_fund,
                ]);

                $msg = 'You spent all day in the lab, using the cheapest ingredients possible, fidgeting with the encapsulation machines and managed to produce '
                    . $packs . ' packs of ' . CorporationProperty::MEDICAL_PRODUCTS[$product]
                    . '. You earned $' . number_format($payout) . ' for your efforts';

                return back()->with('success', $msg);
            });
        } catch (\Throwable $e) {
            Log::error('[CorporationProfit] Medical production failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id ?? null,
                'corporation_id' => $corpId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Production failed. Please try again.');
        }
    }

    public function sellMedicalToNpc(Request $request)
    {
        $request->validate([
            'product' => 'required|string|in:' . implode(',', array_keys(CorporationProperty::MEDICAL_PRODUCTS)),
        ]);

        $character = $request->user()->getLoadedCharacter();
        $product = (string) $request->product;

        if (!$character) {
            return back()->with('error', 'No active character found.');
        }

        try {
            return DB::transaction(function () use ($character, $product) {
                $character = Character::with('timers')
                    ->lockForUpdate()
                    ->find($character->id);

                $corp = $this->lockedCorporationForMember($character);
                if (!$corp || !$corp->isCeo($character)) {
                    return back()->with('error', 'Only the CEO can move company stock.');
                }

                if ($blocker = $this->companyStockBlocker($character, $corp)) {
                    return back()->with('error', $blocker);
                }

                $property = $corp->lockedMedicalProperty();
                if (!$property) {
                    return back()->with('error', 'Your corporation needs a medical property.');
                }

                if (!$property->isOperational()) {
                    return back()->with('error', "Your corporation's building is currently under maintenance and cannot be worked at!");
                }

                $cleared = $property->clearMedicalProductStock($product);
                $totalPacks = (int) $cleared['total_packs'];

                if ($totalPacks <= 0) {
                    return back()->with('error', 'There is no stock to sell.');
                }

                $pricePerPack = CorporationProperty::rollMedicalNpcPricePerPack($product);
                $payout = $totalPacks * $pricePerPack;

                $reservedKeys = $cleared['reserved_keys'];
                foreach ($reservedKeys as $requestKey) {
                    CharacterJournal::where('type', 'corporate_medicine_sale_request')
                        ->where('data->request_key', $requestKey)
                        ->delete();
                }

                DB::afterCommit(function () use ($reservedKeys) {
                    foreach ($reservedKeys as $requestKey) {
                        Cache::forget($requestKey);
                    }
                });

                $corp->increment('slush_fund', $payout);
                $corp->increment('total_profits', $payout);
                City::increaseCrimeRateById((int) $corp->home_city_id, 0.1);
                $this->setCooldown($character, 15);

                Log::info('[CorporationProfit] NPC sale completed.', [
                    'character_id' => $character->id,
                    'corporation_id' => $corp->id,
                    'property_id' => $property->id,
                    'product' => $product,
                    'product_label' => CorporationProperty::MEDICAL_PRODUCTS[$product] ?? $product,
                    'packs_sold' => $totalPacks,
                    'stocked_packs_cleared' => (int) $cleared['stocked_packs'],
                    'reserved_packs_cleared' => (int) $cleared['reserved_packs'],
                    'price_per_pack' => $pricePerPack,
                    'total_payout' => $payout,
                    'home_city_id' => (int) $corp->home_city_id,
                ]);

                $msg = 'You signed some deals with some TOR network Ulbrich-lite Marketplace owner and quietly shipped your stock through back alley channels and earned $'
                    . number_format($payout) . ' for the company';

                return back()->with('success', $msg);
            });
        } catch (\Throwable $e) {
            Log::error('[CorporationProfit] NPC medical sale failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id ?? null,
                'product' => $product,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Sale failed. Please try again.');
        }
    }

    public function setMedicalPayoutRate(Request $request)
    {
        $request->validate([
            'rate' => 'required|integer|min:' . CorporationProperty::MIN_MEDICAL_PAYOUT_PER_PACK . '|max:' . CorporationProperty::MAX_MEDICAL_PAYOUT_PER_PACK,
        ]);

        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No active character found.');
        }

        try {
            return DB::transaction(function () use ($character, $request) {
                $character = Character::lockForUpdate()->find($character->id);
                $corp = $this->lockedCorporationForMember($character);

                if (!$corp || !$corp->isCeo($character)) {
                    return back()->with('error', 'Only the CEO can set the medical payout rate.');
                }

                if ((int) $character->city_id !== (int) $corp->home_city_id) {
                    return back()->with('error', 'You need to be in the corporation home city to do this action!');
                }

                $property = $corp->lockedMedicalProperty();
                if (!$property) {
                    return back()->with('error', 'The corporation does not have a medical property.');
                }

                $data = CorporationProperty::normalizeMedicalData($property->data);
                $previousRate = (int) ($data['medical_payout_per_pack'] ?? 0);
                $data['medical_payout_per_pack'] = (int) $request->rate;
                $property->update(['data' => $data]);

                Log::info('[CorporationProfit] Medical payout rate updated.', [
                    'character_id' => $character->id,
                    'corporation_id' => $corp->id,
                    'property_id' => $property->id,
                    'previous_rate' => $previousRate,
                    'new_rate' => (int) $request->rate,
                ]);

                return back()->with('success', 'Medical payout rate updated to $' . number_format($request->rate) . ' per pack.');
            });
        } catch (\Throwable $e) {
            Log::error('[CorporationProfit] Setting payout rate failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Failed to update payout rate.');
        }
    }

    public function setMirrorBankerPercentage(Request $request)
    {
        $request->validate([
            'percentage' => 'required|integer|min:' . CorporationProperty::MIN_MIRROR_BANKER_PERCENTAGE . '|max:' . CorporationProperty::MAX_MIRROR_BANKER_PERCENTAGE,
        ]);

        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No active character found.');
        }

        try {
            return DB::transaction(function () use ($character, $request) {
                $character = Character::with('timers')->lockForUpdate()->find($character->id);
                $corp = $this->lockedCorporationForMember($character);

                if (!$corp || $corp->roleFor($character) !== Corporation::POSITION_CFO) {
                    return back()->with('error', 'Only the CFO can set the mirror transaction percentage.');
                }

                if ($blocker = $this->companyLaunderingBlocker($character, $corp, requireTimer: false)) {
                    return back()->with('error', $blocker);
                }

                $property = $corp->lockedLaunderingProperty();
                if (!$property) {
                    return back()->with('error', 'Your corporation needs a laundering property.');
                }

                $property->updateMirrorBankerPercentage((int) $request->percentage);

                return back()->with('success', 'Banker percentage updated.');
            });
        } catch (\Throwable $e) {
            Log::error('[CorporationProfit] Setting mirror banker percentage failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to update banker percentage.');
        }
    }

    public function transferToOffshoreTrust(Request $request)
    {
        $request->validate([
            'amount' => 'required|integer|min:1',
        ]);

        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return back()->with('error', 'No active character found.');
        }

        try {
            return DB::transaction(function () use ($character, $request) {
                $cfo = Character::with(['timers', 'career'])
                    ->lockForUpdate()
                    ->find($character->id);

                if (!$cfo) {
                    return back()->with('error', 'No active character found.');
                }

                $corp = $this->lockedCorporationForMember($cfo);
                if (!$corp || $corp->roleFor($cfo) !== Corporation::POSITION_CFO) {
                    return back()->with('error', 'Only the CFO can move funds into the offshore trust.');
                }

                if ($blocker = $this->companyLaunderingBlocker($cfo, $corp, requireTimer: false)) {
                    return back()->with('error', $blocker);
                }

                $property = $corp->lockedLaunderingProperty();
                if (!$property) {
                    return back()->with('error', 'Your corporation needs a laundering property.');
                }

                if (!$property->isOperational()) {
                    return back()->with('error', "Your corporation's laundering property is currently under maintenance.");
                }

                $amount = (int) $request->amount;
                if ((int) $corp->slush_fund < $amount) {
                    return back()->with('error', 'The corporation does not have enough slush funds.');
                }

                if ($property->offshoreBalance() + $amount > self::MAX_OFFSHORE_TRUST_BALANCE) {
                    return back()->with('error', 'PanamaCo can hold at most $' . number_format(self::MAX_OFFSHORE_TRUST_BALANCE) . '.');
                }

                $corp->decrement('slush_fund', $amount);
                $property->addOffshoreBalance($amount);

                Log::info('[CorporationProfit] Offshore trust funded.', [
                    'cfo_id' => $cfo->id,
                    'corporation_id' => $corp->id,
                    'property_id' => $property->id,
                    'amount' => $amount,
                ]);

                return back()->with('success', '$' . number_format($amount) . ' moved into PanamaCo.');
            });
        } catch (\Throwable $e) {
            Log::error('[CorporationProfit] Offshore trust funding failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id ?? null,
                'amount' => $request->amount,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to move funds into PanamaCo.');
        }
    }

    public function createMirrorTransaction(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();
        $bankerName = trim((string) $request->input('banker_name', ''));
        $amountInput = filter_var($request->input('amount'), FILTER_VALIDATE_INT);

        if (!$character) {
            return back()->with('error', 'No active character found.');
        }

        if ($bankerName === '') {
            return back()->with('error', 'Enter a banker name.');
        }

        if (strlen($bankerName) > 30) {
            return back()->with('error', 'Banker name is too long.');
        }

        if ($amountInput === false || $amountInput < 1000) {
            return back()->with('error', 'Mirror transactions must be at least $1,000.');
        }

        $amount = (int) $amountInput;

        try {
            return DB::transaction(function () use ($character, $bankerName, $amount) {
                $cfo = Character::with(['timers', 'career'])
                    ->lockForUpdate()
                    ->find($character->id);

                if (!$cfo) {
                    return back()->with('error', 'No active character found.');
                }

                $corp = $this->lockedCorporationForMember($cfo);
                if (!$corp || $corp->roleFor($cfo) !== Corporation::POSITION_CFO) {
                    return back()->with('error', 'Only the CFO can run mirror transactions.');
                }

                if ($blocker = $this->companyLaunderingBlocker($cfo, $corp)) {
                    return back()->with('error', $blocker);
                }

                $property = $corp->lockedLaunderingProperty();
                if (!$property) {
                    return back()->with('error', 'Your corporation needs a laundering property.');
                }

                if (!$property->isOperational()) {
                    return back()->with('error', "Your corporation's laundering property is currently under maintenance.");
                }

                $data = CorporationProperty::normalizeLaunderingData($property->data);
                if (!empty($data['mirror_requests'])) {
                    return back()->with('error', 'There is already a mirror transaction waiting on a banker.');
                }

                $banker = Character::with(['career', 'timers'])
                    ->whereRaw('LOWER(display_name) = ?', [strtolower($bankerName)])
                    ->lockForUpdate()
                    ->first();

                if (!$banker || !$banker->isAlive()) {
                    return back()->with('error', 'Choose a valid banker.');
                }

                if ($banker->id === $cfo->id) {
                    return back()->with('error', 'You cannot mirror funds through yourself.');
                }

                if ($blocker = $this->mirrorBankerBlocker($banker, $corp)) {
                    return back()->with('error', $blocker);
                }

                $requestKey = $this->mirrorCacheKey((string) Str::uuid());
                $bankerPercentage = (int) ($data['banker_percentage'] ?? CorporationProperty::DEFAULT_MIRROR_BANKER_PERCENTAGE);
                $reservation = $property->reserveMirrorTransaction(
                    $requestKey,
                    $amount,
                    (int) $cfo->id,
                    (int) $banker->id,
                    $bankerPercentage
                );

                if (!$reservation) {
                    return back()->with('error', 'PanamaCo does not hold enough available funds.');
                }

                $expiresAt = (int) $reservation['expires_at'];
                $state = [
                    'request_key' => $requestKey,
                    'cfo_id' => (int) $cfo->id,
                    'banker_id' => (int) $banker->id,
                    'corporation_id' => (int) $corp->id,
                    'property_id' => (int) $property->id,
                    'amount' => $amount,
                    'banker_percentage' => $bankerPercentage,
                    'status' => CorporationProperty::MIRROR_STATUS_PENDING,
                    'expires_at' => $expiresAt,
                ];

                if (!$this->cachePut($requestKey, $state, CorporationProperty::MIRROR_TRANSACTION_TTL_SECONDS)) {
                    throw new \RuntimeException('Could not open mirror transaction cache channel.');
                }

                JournalService::custom($banker->id, 'corporate_mirror_transaction_request', [
                    'request_key' => $requestKey,
                    'cfo_id' => $cfo->id,
                    'cfo_name' => $cfo->display_name,
                    'banker_id' => $banker->id,
                    'corporation_id' => $corp->id,
                    'corporation_name' => $corp->name,
                    'property_id' => $property->id,
                    'amount' => $amount,
                    'banker_percentage' => $bankerPercentage,
                    'expires_at' => $expiresAt,
                ]);

                Log::info('[CorporationProfit] Mirror transaction requested.', [
                    'cfo_id' => $cfo->id,
                    'banker_id' => $banker->id,
                    'corporation_id' => $corp->id,
                    'property_id' => $property->id,
                    'amount' => $amount,
                    'banker_percentage' => $bankerPercentage,
                    'offshore_reserved' => $amount,
                ]);

                return back()->with('success', "Mirror transaction request sent to {$banker->display_name}. They have 10 minutes to respond.");
            });
        } catch (\Throwable $e) {
            Log::error('[CorporationProfit] Mirror transaction request failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id ?? null,
                'banker_name' => $bankerName,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Mirror transaction failed. Please try again.');
        }
    }

    public function cancelMirrorTransaction(Request $request)
    {
        $request->validate([
            'request_key' => 'required|string',
        ]);

        $character = $request->user()->getLoadedCharacter();
        $requestKey = (string) $request->request_key;

        if (!$character) {
            return back()->with('error', 'No active character found.');
        }
        $state = $this->cacheGet($requestKey);

        if (!$state) {
            $this->deleteMirrorJournalForRequest($requestKey, cfoId: (int) ($character->id ?? 0));
            return back()->with('error', 'That mirror transaction is no longer active.');
        }

        try {
            return DB::transaction(function () use ($character, $requestKey, $state) {
                $cfo = Character::lockForUpdate()->find($character->id);
                if (!$cfo || (int) $cfo->id !== (int) ($state['cfo_id'] ?? 0)) {
                    return back()->with('error', 'That mirror transaction does not belong to you.');
                }

                $property = CorporationProperty::lockForUpdate()->find((int) ($state['property_id'] ?? 0));
                $property?->releaseMirrorTransaction($requestKey);
                $this->deleteMirrorJournalForRequest($requestKey, bankerId: (int) ($state['banker_id'] ?? 0));
                DB::afterCommit(fn() => $this->cacheForget($requestKey));

                Log::info('[CorporationProfit] Mirror transaction cancelled.', [
                    'cfo_id' => $cfo->id,
                    'banker_id' => (int) ($state['banker_id'] ?? 0),
                    'corporation_id' => (int) ($state['corporation_id'] ?? 0),
                    'amount' => (int) ($state['amount'] ?? 0),
                ]);

                return back()->with('success', 'You canceled the laundering scheme before things went haywire.');
            });
        } catch (\Throwable $e) {
            Log::error('[CorporationProfit] Mirror transaction cancellation failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id ?? null,
                'request_key' => $requestKey,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Mirror transaction could not be cancelled.');
        }
    }

    public function acceptMirrorTransaction(Character $banker, CharacterJournal $journal)
    {
        if ($journal->type !== 'corporate_mirror_transaction_request') {
            return back()->with('error', 'Unsupported request type.');
        }

        $requestKey = (string) ($journal->data['request_key'] ?? '');
        $state = $requestKey !== '' ? $this->cacheGet($requestKey) : null;

        if (!$state) {
            DB::transaction(function () use ($journal) {
                $this->releaseMirrorRequestFromJournal($journal);
                $journal->delete();
            });
            return back()->with('error', 'That mirror transaction has expired.');
        }

        try {
            return DB::transaction(function () use ($banker, $journal, $requestKey, $state) {
                $journal = CharacterJournal::where('id', $journal->id)->lockForUpdate()->first();
                if (
                    !$journal
                    || $journal->type !== 'corporate_mirror_transaction_request'
                    || (int) $journal->character_id !== (int) $banker->id
                ) {
                    return back()->with('error', 'That request is not addressed to you.');
                }

                if (($state['status'] ?? CorporationProperty::MIRROR_STATUS_PENDING) !== CorporationProperty::MIRROR_STATUS_PENDING) {
                    return back()->with('error', 'That mirror transaction has already been accepted.');
                }

                $corp = Corporation::operating()
                    ->lockForUpdate()
                    ->find((int) ($state['corporation_id'] ?? 0));
                $property = CorporationProperty::lockForUpdate()->find((int) ($state['property_id'] ?? 0));
                $ids = collect([(int) $banker->id, (int) ($state['cfo_id'] ?? 0)])
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
                $characters = Character::with(['career', 'timers'])
                    ->whereIn('id', $ids)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $banker = $characters->get((int) $banker->id);
                $cfo = $characters->get((int) ($state['cfo_id'] ?? 0));

                if (!$corp || !$property || !$cfo || !$banker) {
                    $property?->releaseMirrorTransaction($requestKey);
                    $journal->delete();
                    DB::afterCommit(fn() => $this->cacheForget($requestKey));
                    return back()->with('error', 'That company no longer controls the transaction.');
                }

                if (!$property->isOperational() || (int) $property->corporation_id !== (int) $corp->id) {
                    return back()->with('error', "That corporation's laundering property is currently under maintenance.");
                }

                if ((int) $cfo->corporation_id !== (int) $corp->id || $corp->roleFor($cfo) !== Corporation::POSITION_CFO || !$cfo->isAlive()) {
                    $property->releaseMirrorTransaction($requestKey);
                    $journal->delete();
                    DB::afterCommit(fn() => $this->cacheForget($requestKey));
                    return back()->with('error', 'That corporation no longer has authority to run this transaction.');
                }

                if ($blocker = $this->mirrorBankerBlocker($banker, $corp, requireTimer: true)) {
                    return back()->with('error', $blocker);
                }

                $reservation = $property->acceptMirrorTransaction($requestKey);
                if (!$reservation) {
                    $journal->delete();
                    DB::afterCommit(fn() => $this->cacheForget($requestKey));
                    return back()->with('error', 'That mirror transaction is no longer available.');
                }

                $amount = (int) ($reservation['amount'] ?? $state['amount'] ?? 0);
                $bankerPercentage = (int) ($reservation['banker_percentage'] ?? $state['banker_percentage'] ?? CorporationProperty::DEFAULT_MIRROR_BANKER_PERCENTAGE);
                $acceptedAt = (int) ($reservation['accepted_at'] ?? now()->timestamp);
                $expiresAt = (int) ($reservation['expires_at'] ?? $state['expires_at'] ?? 0);
                $ttl = max(1, $expiresAt - now()->timestamp);
                $state['status'] = CorporationProperty::MIRROR_STATUS_ACCEPTED;
                $state['accepted_at'] = $acceptedAt;

                if (!$this->cachePut($requestKey, $state, $ttl)) {
                    throw new \RuntimeException('Could not update mirror transaction cache channel.');
                }

                $this->setCooldown($banker, 15);

                $journal->delete();

                JournalService::custom($cfo->id, 'corporate_mirror_transaction_accepted', [
                    'banker_name' => $banker->display_name,
                    'corporation_name' => $corp->name,
                    'amount' => $amount,
                    'banker_percentage' => $bankerPercentage,
                ]);

                Log::info('[CorporationProfit] Mirror transaction accepted.', [
                    'cfo_id' => $cfo->id,
                    'banker_id' => $banker->id,
                    'corporation_id' => $corp->id,
                    'property_id' => $property->id,
                    'amount' => $amount,
                    'banker_percentage' => $bankerPercentage,
                ]);

                return back()->with('success', 'You accepted the placement request. If the CFO closes it, your fee will be deposited.');
            });
        } catch (\Throwable $e) {
            Log::error('[CorporationProfit] Mirror transaction accept failed.', [
                'banker_id' => $banker->id ?? null,
                'journal_id' => $journal->id,
                'request_key' => $requestKey,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Mirror transaction failed. Please try again.');
        }
    }

    public function executeMirrorTransaction(Request $request)
    {
        $request->validate([
            'request_key' => 'required|string',
        ]);

        $character = $request->user()->getLoadedCharacter();
        $requestKey = (string) $request->request_key;

        if (!$character) {
            return back()->with('error', 'No active character found.');
        }

        $state = $this->cacheGet($requestKey);
        if (!$state) {
            return back()->with('error', 'That mirror transaction is no longer active.');
        }

        try {
            return DB::transaction(function () use ($character, $requestKey, $state) {
                $cfo = Character::with(['timers', 'career'])
                    ->lockForUpdate()
                    ->find($character->id);
                $corp = Corporation::operating()
                    ->lockForUpdate()
                    ->find((int) ($state['corporation_id'] ?? 0));
                $property = CorporationProperty::lockForUpdate()->find((int) ($state['property_id'] ?? 0));
                $banker = Character::with(['career', 'timers'])
                    ->lockForUpdate()
                    ->find((int) ($state['banker_id'] ?? 0));

                if (!$corp || !$property || !$cfo || !$banker) {
                    $property?->releaseMirrorTransaction($requestKey);
                    DB::afterCommit(fn() => $this->cacheForget($requestKey));
                    return back()->with('error', 'That mirror transaction can no longer be completed.');
                }

                if ((int) $cfo->id !== (int) ($state['cfo_id'] ?? 0) || (int) $cfo->corporation_id !== (int) $corp->id || $corp->roleFor($cfo) !== Corporation::POSITION_CFO || !$cfo->isAlive()) {
                    $property->releaseMirrorTransaction($requestKey);
                    DB::afterCommit(fn() => $this->cacheForget($requestKey));
                    return back()->with('error', 'You no longer have authority to complete this mirror transaction.');
                }

                if ($blocker = $this->companyLaunderingBlocker($cfo, $corp)) {
                    return back()->with('error', $blocker);
                }

                if (!$property->isOperational() || (int) $property->corporation_id !== (int) $corp->id) {
                    $property->releaseMirrorTransaction($requestKey);
                    DB::afterCommit(fn() => $this->cacheForget($requestKey));
                    return back()->with('error', "Your corporation's laundering property is currently under maintenance.");
                }

                if ($blocker = $this->mirrorBankerBlocker($banker, $corp, requireTimer: false)) {
                    $property->releaseMirrorTransaction($requestKey);
                    DB::afterCommit(fn() => $this->cacheForget($requestKey));
                    return back()->with('error', $blocker);
                }

                $reservation = $property->consumeMirrorTransaction($requestKey);
                if (!$reservation) {
                    return back()->with('error', 'The banker has not accepted this mirror transaction yet.');
                }

                $amount = (int) ($reservation['amount'] ?? $state['amount'] ?? 0);
                $bankerPercentage = (int) ($reservation['banker_percentage'] ?? $state['banker_percentage'] ?? CorporationProperty::DEFAULT_MIRROR_BANKER_PERCENTAGE);
                $bankerFee = (int) floor($amount * ($bankerPercentage / 100));
                $spreadPercentage = random_int(
                    CorporationProperty::MIRROR_SPREAD_PERCENT_MIN,
                    CorporationProperty::MIRROR_SPREAD_PERCENT_MAX
                );
                $spread = (int) floor($amount * ($spreadPercentage / 100));
                $cleanAmount = max(0, $amount - $bankerFee);

                $corp->increment('cash_reserves', $cleanAmount);
                if ($spread > 0) {
                    $property->addOffshoreBalance($spread);
                }

                if ($bankerFee > 0) {
                    DB::table('characters')->where('id', $banker->id)->increment('cash_in_bank', $bankerFee);
                    BankTransaction::record(
                        characterId: $banker->id,
                        type: BankTransaction::TYPE_DEPOSIT,
                        amount: $bankerFee,
                        balanceAfter: (int) $banker->cash_in_bank + $bankerFee,
                        counterparty: 'PanamaCo Trust',
                        note: 'Private Placement Fee',
                    );
                }

                City::increaseCrimeRateById((int) $corp->home_city_id, 0.2);
                City::increaseCrimeRateById((int) $banker->home_city_id, 0.1);
                $this->setCooldown($cfo, 15);
                DB::afterCommit(fn() => $this->cacheForget($requestKey));

                JournalService::custom($banker->id, 'corporate_mirror_transaction_completed', [
                    'cfo_name' => $cfo->display_name,
                    'corporation_name' => $corp->name,
                    'amount' => $amount,
                    'banker_fee' => $bankerFee,
                    'spread' => $spread,
                ]);

                Log::info('[CorporationProfit] Mirror transaction completed.', [
                    'cfo_id' => $cfo->id,
                    'banker_id' => $banker->id,
                    'corporation_id' => $corp->id,
                    'property_id' => $property->id,
                    'amount' => $amount,
                    'banker_fee' => $bankerFee,
                    'offshore_spread_percentage' => $spreadPercentage,
                    'offshore_spread' => $spread,
                    'clean_amount' => $cleanAmount,
                ]);

                return back()->with('success', 'You succesfully laundered $' . number_format($cleanAmount) . ' using the company\'s offshore trust, and transferred it to the company\'s cash reserves. The offshore trust also made $'. number_format($spread) . ' in profits.');
            });
        } catch (\Throwable $e) {
            Log::error('[CorporationProfit] Mirror transaction execution failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id ?? null,
                'request_key' => $requestKey,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Mirror transaction failed. Please try again.');
        }
    }

    public function declineMirrorTransaction(Character $banker, CharacterJournal $journal)
    {
        if ($journal->type !== 'corporate_mirror_transaction_request') {
            return back()->with('error', 'Unsupported request type.');
        }

        return DB::transaction(function () use ($banker, $journal) {
            $journal = CharacterJournal::where('id', $journal->id)->lockForUpdate()->first();
            if (
                !$journal
                || $journal->type !== 'corporate_mirror_transaction_request'
                || (int) $journal->character_id !== (int) $banker->id
            ) {
                return back()->with('error', 'Request not found or invalid.');
            }

            $requestKey = (string) ($journal->data['request_key'] ?? '');
            if ($requestKey !== '') {
                $state = $this->cacheGet($requestKey);
                $property = CorporationProperty::lockForUpdate()->find((int) (($state['property_id'] ?? null) ?: ($journal->data['property_id'] ?? 0)));
                $property?->releaseMirrorTransaction($requestKey);
                DB::afterCommit(fn() => $this->cacheForget($requestKey));
            }

            if ($cfoId = (int) ($journal->data['cfo_id'] ?? 0)) {
                JournalService::custom($cfoId, 'corporate_mirror_transaction_declined', [
                    'banker_name' => $banker->display_name,
                    'corporation_name' => $journal->data['corporation_name'] ?? 'your corporation',
                ]);
            }

            $journal->delete();

            return back()->with('success', 'Mirror transaction declined.');
        });
    }

    private function medicalWorkerBlocker(?Character $character, bool $requireActionReady = true): ?string
    {
        if (!$character || !$character->isAlive()) {
            return 'No active character found.';
        }

        if ($character->isHospitalized() || $character->isJailed()) {
            return 'You cannot work at this company right now.';
        }

        $degree = $character->getDegree('medicine');
        if (!$degree || empty($degree['completed_at'])) {
            return 'You need a  medical degree to work for this company.';
        }

        if ($requireActionReady && $character->timers?->next_action_at?->isFuture()) {
            return 'You need to wait before performing another action.';
        }

        return null;
    }

    private function companyStockBlocker(?Character $character, Corporation $corp): ?string
    {
        if (!$character || !$character->isAlive()) {
            return 'No active character found.';
        }

        if ($character->isHospitalized() || $character->isJailed()) {
            return 'You cannot move company stock right now.';
        }

        if ($character->timers?->next_action_at?->isFuture()) {
            return 'You need to wait before performing another action.';
        }

        if ($corp->is_holding_company) {
            return 'Holding companies cannot run operating property sales directly.';
        }

        if ((int) $character->city_id !== (int) $corp->home_city_id) {
            return 'You need to be in the corporation home city to do this action!';
        }

        return null;
    }

    private function companyLaunderingBlocker(?Character $character, Corporation $corp, bool $requireTimer = true): ?string
    {
        if (!$character || !$character->isAlive()) {
            return 'No active character found.';
        }

        if ($character->isHospitalized() || $character->isJailed()) {
            return 'You cannot move company funds right now.';
        }

        if ($requireTimer && $character->timers?->next_action_at?->isFuture()) {
            return 'You need to wait before performing another action.';
        }

        if ($corp->is_holding_company) {
            return 'Holding companies cannot run operating property transactions directly.';
        }

        if ((int) $character->city_id !== (int) $corp->home_city_id) {
            return 'You need to be in the corporation home city to do this action!';
        }

        return null;
    }

    private function mirrorBankerBlocker(?Character $banker, Corporation $corp, bool $requireTimer = false): ?string
    {
        if (!$banker || !$banker->isAlive()) {
            return 'Choose a valid banker.';
        }

        if ($banker->isHospitalized() || $banker->isJailed()) {
            return 'That banker cannot handle the transaction right now.';
        }

        if ($requireTimer && $banker->timers?->next_action_at?->isFuture()) {
            return 'You need to wait before performing another action.';
        }

        $bankingCareerId = Career::findByCode('banking')?->id;
        if (!$bankingCareerId || (int) $banker->career_id !== (int) $bankingCareerId || (int) $banker->career_rank < 2) {
            return 'Choose a qualified banker.';
        }

        if ((int) $banker->city_id !== (int) $banker->home_city_id) {
            return 'That banker needs to be in their home city.';
        }

        return null;
    }

    private function lockedOperatingCorporation(int $corpId): ?Corporation
    {
        return Corporation::operating()
            ->lockForUpdate()
            ->find($corpId);
    }

    private function lockedCorporationForMember(?Character $character): ?Corporation
    {
        if (!$character?->corporation_id) {
            return null;
        }

        return Corporation::operating()
            ->lockForUpdate()
            ->find($character->corporation_id);
    }

    private function setCooldown(Character $character, int $minutes): void
    {
        $character->timers?->update(['next_action_at' => now()->addMinutes($minutes)])
            ?? $character->timers()->create(['next_action_at' => now()->addMinutes($minutes)]);
    }

    private function mirrorCacheKey(string $uuid): string
    {
        return "corporate_mirror_transaction:{$uuid}";
    }

    private function cacheGet(string $key): ?array
    {
        try {
            $value = Cache::get($key);

            return is_array($value) ? $value : null;
        } catch (\Throwable $e) {
            Log::error('[CorporationProfit][Cache] GET failed.', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function cachePut(string $key, array $data, int $ttlSeconds): bool
    {
        try {
            Cache::put($key, $data, $ttlSeconds);

            return true;
        } catch (\Throwable $e) {
            Log::error('[CorporationProfit][Cache] PUT failed.', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function cacheForget(string $key): bool
    {
        try {
            Cache::forget($key);

            return true;
        } catch (\Throwable $e) {
            Log::error('[CorporationProfit][Cache] FORGET failed.', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function deleteMirrorJournalForRequest(string $requestKey, ?int $bankerId = null, ?int $cfoId = null): void
    {
        if ($requestKey === '') {
            return;
        }

        $query = CharacterJournal::query()
            ->where('type', 'corporate_mirror_transaction_request')
            ->where('data->request_key', $requestKey);

        if ($bankerId) {
            $query->where('character_id', $bankerId);
        }

        if ($cfoId) {
            $query->where('data->cfo_id', (string) $cfoId);
        }

        $query->delete();
    }

    private function releaseMirrorRequestFromJournal(CharacterJournal $journal): void
    {
        $requestKey = (string) ($journal->data['request_key'] ?? '');
        $propertyId = (int) ($journal->data['property_id'] ?? 0);

        if ($requestKey === '' || $propertyId <= 0) {
            return;
        }

        $property = CorporationProperty::lockForUpdate()->find($propertyId);
        $property?->releaseMirrorTransaction($requestKey);
    }
}
