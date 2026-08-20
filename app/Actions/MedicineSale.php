<?php

namespace App\Actions;

use App\Models\Character;
use App\Models\CharacterJournal;
use App\Models\Corporation;
use App\Models\CorporationProperty;
use App\Models\GameItem;
use App\Services\JournalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MedicineSale extends Action
{
    private const CACHE_TTL = 600;
    private const MIN_PRICE = 1_000;
    private const MAX_PRICE = 100_000;
    private const MAX_PACKS_PER_OFFER = 2;

    public function getId(): string
    {
        return 'medicine_sale';
    }

    public function getShape(Character $character): array
    {
        $pendingOffers = $character->corporation_id
            ? $this->pendingOffers($character)
            : collect();
        $property = $this->operationalMedicalProperty($character);
        $targets = $property
            ? $this->targets($character, $property)
            : collect();
        $products = $property
            ? $this->products($character, $property)
            : collect();

        return [
            'id' => $this->getId(),
            'title' => 'Medical Sales',
         
            'category' => 'Corporate Operations',
            'description' => 'Choose a buyer, medicine, and a small handoff. If they accept, the corporation gets paid through the slush fund.',
            'image_url' => 'https://images.thedirector.app/properties/medical1.jpg',
            'icon' => 'PrescriptionIcon',
            'button_label' => 'Send Offer',
            'group_init_label' => null,
            'execute_route' => route('career.corporation.actions.medical-sale'),
            'cancel_route' => route('career.corporation.actions.medical-sale.cancel'),
            'is_group_action' => false,
            'available' => $targets->isNotEmpty() && $products->isNotEmpty(),
            'blocker' => $this->blocker($targets, $products),
            'is_waiting' => $pendingOffers->isNotEmpty(),
            'is_ready' => false,
            'active_members' => null,
            'targets' => $targets->values()->all(),
            'products' => $products->values()->all(),
            'pending_offers' => $pendingOffers->values()->all(),
            'accomplices' => null,
            'has_amount_input' => true,
            'has_product_input' => true,
            'has_pack_input' => true,
            'amount_label' => 'Total Offer Price',
            'product_label' => 'Medicine',
            'pack_label' => 'Handoff Size',
            'pick_label' => 'Choose Buyer',
            'target_icon' => 'user',
        ];
    }

    public function canExecute(Character $character): array
    {
        if ($this->isOnCooldown($character)) {
            return ['valid' => false, 'error' => 'You need to wait before performing another action.'];
        }

        if (!$this->isAvailable($character) || !$character->isAlive()) {
            return ['valid' => false, 'error' => 'You cannot run company sales right now.'];
        }

        $corp = $character->corporation;
        if (!$corp) {
            return ['valid' => false, 'error' => 'You need to be in a corporation to move company medicine.'];
        }

        if ($corp->is_holding_company) {
            return ['valid' => false, 'error' => 'Holding companies do not sell operating stock directly.'];
        }

        if ((int) $character->city_id !== (int) $corp->home_city_id) {
            return ['valid' => false, 'error' => 'You need to be in the corporation home city to move company medicine.'];
        }

        return ['valid' => true, 'error' => null];
    }

    public function execute(Character $character, array $params): RedirectResponse
    {
        $price = (int) ($params['amount'] ?? 0);
        $product = (string) ($params['product'] ?? '');
        $packCount = (int) ($params['pack_count'] ?? 1);
        $buyerId = (int) ($params['target_id'] ?? 0);

        if ($buyerId <= 0 || !isset(CorporationProperty::MEDICAL_PRODUCTS[$product]) || $packCount < 1 || $packCount > self::MAX_PACKS_PER_OFFER) {
            return $this->error('Choose a valid buyer and medicine.');
        }

        if ($price < self::MIN_PRICE || $price > self::MAX_PRICE) {
            return $this->error('Choose a serious offer price.');
        }

        try {
            return DB::transaction(function () use ($character, $buyerId, $product, $packCount, $price) {
                $seller = Character::with(['corporation', 'timers'])
                    ->lockForUpdate()
                    ->find($character->id);

                if (!$seller) {
                    return $this->error('No active character found.');
                }

                $check = $this->canExecute($seller);
                if (!$check['valid']) {
                    return $this->error($check['error']);
                }

                if ($this->pendingOffers($seller)->isNotEmpty()) {
                    return $this->error('You already have a medicine offer waiting on a buyer.');
                }

                $corp = Corporation::operating()
                    ->lockForUpdate()
                    ->find($seller->corporation_id);

                if (!$corp) {
                    return $this->error('Your corporation cannot move this stock.');
                }

                $property = $corp->lockedMedicalProperty();
                if (!$property || !$property->isOperational()) {
                    return $this->error("That corporation's building is currently closed and cannot be worked at!");
                }

                $buyer = Character::lockForUpdate()->find($buyerId);
                if (!$buyer || !$buyer->isAlive()) {
                    return $this->error('Choose a valid buyer.');
                }

                if ((int) $buyer->id === (int) $seller->id) {
                    return $this->error('You cannot sell company stock to yourself.');
                }

                if ((int) ($buyer->corporation_id ?? 0) === (int) $corp->id) {
                    return $this->error('You cannot sell company stock to someone in your corporation.');
                }

                if ((int) $buyer->city_id !== (int) $corp->home_city_id) {
                    return $this->error('The buyer needs to be in the corporation home city.');
                }

                $template = GameItem::where('slug', $product)->first();
                if (!$template) {
                    return $this->error('That medicine does not exist!');
                }

                $requestKey = self::cacheKey((string) Str::uuid());
                $reservation = $property->reserveMedicalPacks($product, $requestKey, $packCount, self::CACHE_TTL);
                if (!$reservation) {
                    return $this->error('You do not have enough of that medicine to sell that many packs');
                }

                if (
                    !$this->cachePut($requestKey, [
                        'seller_id' => $seller->id,
                        'buyer_id' => $buyer->id,
                        'corporation_id' => $corp->id,
                        'property_id' => $property->id,
                        'product' => $product,
                        'pack_count' => $packCount,
                        'price' => $price,
                        'reserved_cost' => $reservation['cost'],
                        'expires_at' => now()->addSeconds(self::CACHE_TTL)->timestamp,
                    ], self::CACHE_TTL)
                ) {
                    throw new \RuntimeException('Could not open medicine sale cache channel.');
                }

                JournalService::custom($buyer->id, 'corporate_medicine_sale_request', [
                    'request_key' => $requestKey,
                    'seller_id' => $seller->id,
                    'seller_name' => $seller->display_name,
                    'corporation_id' => $corp->id,
                    'corporation_name' => $corp->name,
                    'product' => $product,
                    'product_name' => CorporationProperty::MEDICAL_PRODUCTS[$product],
                    'item_image_url' => $template->image_url,
                    'pack_count' => $packCount,
                    'price' => $price,
                    'pack_units' => CorporationProperty::MEDICAL_PACK_UNITS,
                ]);

                $this->setCooldownMinutes($seller, 3);

                Log::info('[MedicineSale] Offer created.', [
                    'seller_id' => $seller->id,
                    'buyer_id' => $buyer->id,
                    'corporation_id' => $corp->id,
                    'property_id' => $property->id,
                    'product' => $product,
                    'pack_count' => $packCount,
                    'price' => $price,
                ]);

                return $this->success('You have offered ' . $buyer->display_name . ' ' . $packCount . ' Pack(s) of ' . $product . '. You\'ll have to wait to see if they accept within 10 minutes');
            });
        } catch (\Throwable $e) {
            Log::error('[MedicineSale] Offer failed.', [
                'character_id' => $character->id ?? null,
                'buyer_id' => $buyerId,
                'product' => $product,
                'pack_count' => $packCount,
                'error' => $e->getMessage(),
            ]);

            return $this->error('The sale failed. Please try again.');
        }
    }

    public function cancel(Character $character, array $params): RedirectResponse
    {
        $requestKey = (string) ($params['request_key'] ?? '');

        if ($requestKey === '') {
            return $this->error('Choose a sale offer to cancel.');
        }

        $state = $this->cacheGet($requestKey);
        if (!$state) {
            $this->deleteBuyerJournalForRequest($requestKey, sellerId: (int) $character->id);
            return $this->error('That offer is no longer active.');
        }

        try {
            return DB::transaction(function () use ($character, $requestKey, $state) {
                $seller = Character::lockForUpdate()->find($character->id);
                if (!$seller || (int) $seller->id !== (int) ($state['seller_id'] ?? 0)) {
                    return $this->error('That offer does not belong to you.');
                }

                $property = CorporationProperty::lockForUpdate()->find((int) ($state['property_id'] ?? 0));
                $this->releaseIfPossible($property, (string) ($state['product'] ?? ''), $requestKey);
                $this->deleteBuyerJournalForRequest($requestKey, (int) ($state['buyer_id'] ?? 0));
                $this->forgetCacheAfterCommit($requestKey);

                Log::info('[MedicineSale] Offer cancelled by seller.', [
                    'seller_id' => $seller->id,
                    'buyer_id' => (int) ($state['buyer_id'] ?? 0),
                    'corporation_id' => (int) ($state['corporation_id'] ?? 0),
                    'property_id' => (int) ($state['property_id'] ?? 0),
                    'product' => (string) ($state['product'] ?? ''),
                    'pack_count' => (int) ($state['pack_count'] ?? 0),
                ]);

                return $this->success('You pulled the offer before anyone could make it your problem.');
            });
        } catch (\Throwable $e) {
            Log::error('[MedicineSale] Cancel failed.', [
                'character_id' => $character->id ?? null,
                'request_key' => $requestKey,
                'error' => $e->getMessage(),
            ]);

            return $this->error('The sale could not be cancelled. Please try again.');
        }
    }

    public function accept(Character $buyer, CharacterJournal $journal): RedirectResponse
    {
        if ($journal->type !== 'corporate_medicine_sale_request') {
            return $this->error('Unsupported request type.');
        }

        $buyerId = (int) $buyer->id;
        $requestKey = (string) ($journal->data['request_key'] ?? '');
        $state = $requestKey !== '' ? $this->cacheGet($requestKey) : null;

        if (!$state) {
            if ((int) $journal->character_id === $buyerId) {
                $journal->delete();
            }
            return $this->error('That offer has expired.');
        }

        try {
            return DB::transaction(function () use ($buyerId, $journal, $requestKey, $state) {
                $journal = CharacterJournal::where('id', $journal->id)->lockForUpdate()->first();
                if (
                    !$journal
                    || $journal->type !== 'corporate_medicine_sale_request'
                    || (int) $journal->character_id !== $buyerId
                ) {
                    return $this->error('That offer is not addressed to you.');
                }

                $buyer = Character::lockForUpdate()->find($buyerId);
                if (!$buyer || (int) $buyer->id !== (int) ($state['buyer_id'] ?? 0)) {
                    return $this->error('That offer is not addressed to you.');
                }

                if (!$buyer->isAlive() || $buyer->isHospitalized() || $buyer->isJailed()) {
                    return $this->error('You cannot complete this deal right now.');
                }

                $seller = Character::lockForUpdate()->find((int) $state['seller_id']);
                $corp = Corporation::operating()
                    ->lockForUpdate()
                    ->find((int) $state['corporation_id']);

                if (!$seller || !$corp || (int) $seller->corporation_id !== (int) $corp->id) {
                    $property = CorporationProperty::lockForUpdate()->find((int) ($state['property_id'] ?? 0));
                    $this->releaseIfPossible($property, (string) ($state['product'] ?? ''), $requestKey);
                    $journal->delete();
                    $this->forgetCacheAfterCommit($requestKey);
                    return $this->error('That company no longer controls the sale.');
                }

                if ((int) ($buyer->corporation_id ?? 0) === (int) $corp->id) {
                    $property = CorporationProperty::lockForUpdate()->find((int) ($state['property_id'] ?? 0));
                    $this->releaseIfPossible($property, (string) ($state['product'] ?? ''), $requestKey);
                    $journal->delete();
                    $this->forgetCacheAfterCommit($requestKey);
                    return $this->error('That sale can no longer be completed.');
                }

                if (!$seller->isAlive() || $seller->isHospitalized() || $seller->isJailed() || (int) $seller->city_id !== (int) $corp->home_city_id) {
                    $property = CorporationProperty::lockForUpdate()->find((int) ($state['property_id'] ?? 0));
                    $this->releaseIfPossible($property, (string) ($state['product'] ?? ''), $requestKey);
                    $journal->delete();
                    $this->forgetCacheAfterCommit($requestKey);
                    return $this->error('The seller can no longer complete the handoff.');
                }

                if ((int) $buyer->city_id !== (int) $corp->home_city_id) {
                    return $this->error('You need to be in the company home city to complete the sale.');
                }

                $property = $corp->lockedMedicalProperty();
                if (!$property || !$property->isOperational()) {
                    return $this->error("That corporation's building is currently under maintenance and cannot sell to you");
                }

                $product = (string) ($state['product'] ?? '');
                if (!isset(CorporationProperty::MEDICAL_PRODUCTS[$product])) {
                    $journal->delete();
                    $this->forgetCacheAfterCommit($requestKey);
                    return $this->error('That medicine is no longer available.');
                }

                $template = GameItem::where('slug', $product)->lockForUpdate()->first();
                if (!$template) {
                    throw new \RuntimeException("Missing medicine item template {$product}");
                }

                $packCount = max(1, min(self::MAX_PACKS_PER_OFFER, (int) ($state['pack_count'] ?? 1)));
                $capacity = $this->buyerCanReceivePacks($buyer, $packCount);
                if (!$capacity['valid']) {
                    return $this->error($capacity['error']);
                }

                $price = max(1, (int) ($state['price'] ?? 0));
                if ((int) $buyer->cash_on_hand < $price) {
                    return $this->error('You do not have enough cash on hand.');
                }

                if (!$property->consumeMedicalReservation($product, $requestKey)) {
                    $journal->delete();
                    $this->forgetCacheAfterCommit($requestKey);
                    return $this->error('That stock is gone.');
                }

                if (!$buyer->removeCash($price)) {
                    throw new \RuntimeException('Buyer cash debit failed during medicine sale.');
                }

                $corp->increment('slush_fund', $price);
                $corp->increment('total_profits', $price);

                for ($i = 0; $i < $packCount; $i++) {
                    $buyer->items()->create([
                        'game_item_id' => $template->id,
                        'durability_remaining' => $template->durability,
                        'location' => 'on_hand',
                        'is_equipped' => false,
                        'equipped_slot' => null,
                        'data' => [
                            'units' => CorporationProperty::MEDICAL_PACK_UNITS,
                            'pack_units' => CorporationProperty::MEDICAL_PACK_UNITS,
                            'source_corporation_id' => $corp->id,
                            'source_property_id' => $property->id,
                            'acquired_via' => 'corporate_medicine_sale',
                        ],
                    ]);
                }

                $journal->delete();
                $this->forgetCacheAfterCommit($requestKey);

                JournalService::custom($seller->id, 'corporate_medicine_sold', [
                    'buyer_name' => $buyer->display_name,
                    'corporation_name' => $corp->name,
                    'product_name' => CorporationProperty::MEDICAL_PRODUCTS[$product],
                    'pack_count' => $packCount,
                    'price' => $price,
                ]);

                Log::info('[MedicineSale] Offer accepted.', [
                    'buyer_id' => $buyer->id,
                    'seller_id' => $seller->id,
                    'corporation_id' => $corp->id,
                    'property_id' => $property->id,
                    'product' => $product,
                    'pack_count' => $packCount,
                    'price' => $price,
                ]);

                return $this->success("You have successfully purchased {$packCount} pack(s) of {$product}. You can use it from your life section in settings. Make sure you don't get too addicted!");
            });
        } catch (\Throwable $e) {
            Log::error('[MedicineSale] Accept failed.', [
                'buyer_id' => $buyer->id ?? null,
                'journal_id' => $journal->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('The sale failed. Please try again.');
        }
    }

    public function decline(Character $buyer, CharacterJournal $journal): RedirectResponse
    {
        $buyerId = (int) $buyer->id;

        return DB::transaction(function () use ($buyerId, $journal) {
            $journal = CharacterJournal::where('id', $journal->id)->lockForUpdate()->first();
            if (
                !$journal
                || $journal->type !== 'corporate_medicine_sale_request'
                || (int) $journal->character_id !== $buyerId
            ) {
                return $this->error('Request not found or invalid.');
            }

            if ($journal->type === 'corporate_medicine_sale_request') {
                $requestKey = (string) ($journal->data['request_key'] ?? '');
                if ($requestKey !== '') {
                    $state = $this->cacheGet($requestKey);
                    if ($state) {
                        if ((int) ($state['buyer_id'] ?? 0) !== $buyerId) {
                            return $this->error('That offer is not addressed to you.');
                        }

                        $property = CorporationProperty::lockForUpdate()->find((int) ($state['property_id'] ?? 0));
                        $this->releaseIfPossible($property, (string) ($state['product'] ?? ''), $requestKey);
                    }

                    $this->forgetCacheAfterCommit($requestKey);
                }
            }

            $journal->delete();

            return $this->success('Sale request declined.');
        });
    }

    private static function cacheKey(string $uuid): string
    {
        return "corporate_medicine_sale:{$uuid}";
    }

    private function targets(Character $character, ?CorporationProperty $property = null)
    {
        if (!$property) {
            return collect();
        }

        return Character::query()
            ->where('city_id', $character->city_id)
            ->where('id', '!=', $character->id)
            ->where(function ($query) use ($property) {
                $query->whereNull('corporation_id')
                    ->orWhere('corporation_id', '!=', $property->corporation_id);
            })
            ->alive()
            ->orderBy('display_name')
            ->get(['id', 'display_name'])
            ->map(function (Character $buyer) {
                return [
                    'id' => $buyer->id,
                    'name' => $buyer->display_name,
                ];
            })
            ->values();
    }

    private function pendingOffers(Character $character)
    {
        $journals = CharacterJournal::query()
            ->where('type', 'corporate_medicine_sale_request')
            ->where('data->seller_id', (string) $character->id)
            ->latest()
            ->get();

        if ($journals->isEmpty()) {
            return collect();
        }

        $now = now()->timestamp;
        $offers = collect();
        $staleJournalIds = [];

        foreach ($journals as $journal) {
            $requestKey = (string) ($journal->data['request_key'] ?? '');
            $state = $requestKey !== '' ? $this->cacheGet($requestKey) : null;

            if (
                !$state
                || (int) ($state['seller_id'] ?? 0) !== (int) $character->id
                || (int) ($state['expires_at'] ?? 0) <= $now
            ) {
                $staleJournalIds[] = $journal->id;
                continue;
            }

            $offers->push([
                'request_key' => $requestKey,
                'buyer_id' => (int) ($state['buyer_id'] ?? 0),
                'product' => (string) ($state['product'] ?? ''),
                'pack_count' => (int) ($state['pack_count'] ?? 1),
                'price' => (int) ($state['price'] ?? 0),
                'expires_at' => (int) ($state['expires_at'] ?? 0),
            ]);
        }

        if ($staleJournalIds) {
            CharacterJournal::whereIn('id', $staleJournalIds)->delete();
        }

        if ($offers->isEmpty()) {
            return collect();
        }

        $buyerMap = Character::query()
            ->whereIn('id', $offers->pluck('buyer_id')->filter()->unique()->all())
            ->get(['id', 'display_name', 'custom_avatar_url'])
            ->keyBy('id');
        $images = GameItem::query()
            ->whereIn('slug', $offers->pluck('product')->filter()->unique()->all())
            ->pluck('image_url', 'slug')
            ->all();

        return $offers
            ->map(function (array $offer) use ($buyerMap, $images) {
                $buyer = $buyerMap->get($offer['buyer_id']);
                $product = $offer['product'];

                return [
                    'request_key' => $offer['request_key'],
                    'buyer_id' => $offer['buyer_id'],
                    'buyer_name' => $buyer?->display_name ?? 'Unknown Buyer',
                    'buyer_avatar_url' => $buyer?->custom_avatar_url,
                    'product' => $product,
                    'product_name' => CorporationProperty::MEDICAL_PRODUCTS[$product] ?? 'Unknown Medicine',
                    'product_image_url' => $images[$product] ?? null,
                    'pack_count' => $offer['pack_count'],
                    'price' => $offer['price'],
                    'expires_at' => $offer['expires_at'],
                    'status' => 'waiting',
                ];
            })
            ->values();
    }

    private function blocker($targets, $products): ?string
    {
        if ($products->isEmpty()) {
            return 'Your company has no medicine stock to sell in this city.';
        }

        if ($targets->isEmpty()) {
            return 'No buyer is available in this city right now.';
        }

        return null;
    }

    private function products(Character $character, ?CorporationProperty $property = null)
    {
        $property ??= $this->operationalMedicalProperty($character);
        if (!$property) {
            return collect();
        }

        $data = CorporationProperty::normalizeMedicalData($property->data);
        $images = GameItem::query()
            ->whereIn('slug', array_keys(CorporationProperty::MEDICAL_PRODUCTS))
            ->pluck('image_url', 'slug')
            ->all();

        return collect(CorporationProperty::MEDICAL_PRODUCTS)
            ->map(function (string $label, string $slug) use ($data, $images) {
                $packs = (int) ($data['stock'][$slug][CorporationProperty::MEDICAL_STOCK_STOCKED]['packs'] ?? 0);

                if ($packs <= 0) {
                    return null;
                }

                return [
                    'id' => $slug,
                    'name' => $label,
                    'image_url' => $images[$slug] ?? null,
                    'maxPacks' => min($packs, self::MAX_PACKS_PER_OFFER),
                ];
            })
            ->filter()
            ->values();
    }

    private function operationalMedicalProperty(Character $character): ?CorporationProperty
    {
        $corpId = (int) ($character->corporation_id ?? 0);
        if ($corpId <= 0) {
            return null;
        }

        $corp = $character->relationLoaded('corporation')
            ? $character->getRelation('corporation')
            : null;

        if (!$corp || (int) $corp->id !== $corpId) {
            $corp = Corporation::query()
                ->where('id', $corpId)
                ->operating()
                ->first();
        }

        if (!$corp || $corp->is_holding_company || (int) $character->city_id !== (int) $corp->home_city_id) {
            return null;
        }

        return $corp->medicalProperty(operationalOnly: true);
    }

    private function releaseIfPossible(?CorporationProperty $property, string $product, string $requestKey): void
    {
        if (!$property || !isset(CorporationProperty::MEDICAL_PRODUCTS[$product])) {
            return;
        }

        $property->releaseMedicalReservation($product, $requestKey);
    }

    private function deleteBuyerJournalForRequest(string $requestKey, ?int $buyerId = null, ?int $sellerId = null): void
    {
        if ($requestKey === '') {
            return;
        }

        $query = CharacterJournal::query()
            ->where('type', 'corporate_medicine_sale_request')
            ->where('data->request_key', $requestKey);

        if ($buyerId) {
            $query->where('character_id', $buyerId);
        }

        if ($sellerId) {
            $query->where('data->seller_id', (string) $sellerId);
        }

        $query->delete();
    }

    private function buyerCanReceivePacks(Character $buyer, int $packCount): array
    {
        if ($packCount < 1 || $packCount > self::MAX_PACKS_PER_OFFER) {
            return ['valid' => false, 'error' => 'Choose a valid pack count.'];
        }

        $capacity = $buyer->canCarryItemQuantity('item', $packCount);
        if (!$capacity['valid']) {
            return ['valid' => false, 'error' => 'You cannot carry that many packs.'];
        }

        return ['valid' => true, 'error' => null];
    }

    private function forgetCacheAfterCommit(string $requestKey): void
    {
        DB::afterCommit(fn() => $this->cacheForget($requestKey));
    }
}
