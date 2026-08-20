<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Character;
use App\Models\City;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ShopController extends CityController
{
    public function index(Request $request, City $city, string $business_slug)
    {
        try {
            [$character, $city] = $this->getContext($request, $city);

            $shop = Business::byCity($city)
                ->where('slug', 'ILIKE', $business_slug)
                ->with(['owner'])
                ->first();

            if (!$shop || !str_starts_with(strtolower($shop->code), 'shop-')) {
                abort(404, 'Shop not found');
            }

            $isOwner = $shop->owner_id === $character->id;

            $items = $shop->getShopItems($isOwner);

            $restockAt = $shop->getSetting('manual_restock_at');
            $nextRestock = $restockAt ?\Carbon\Carbon::parse($restockAt)->addHours(12) : null;

            $hasInsurance = false;
            $insuranceExpiresAt = null;
            $insurancePremium = null;

            if (strtolower($shop->code) === 'shop-pharmacy') {
                $subscribers = $shop->getSetting('insurance_subscribers', []);
                $expiresAt = $subscribers[(string)$character->id] ?? null;
                if ($expiresAt && \Carbon\Carbon::parse($expiresAt)->isFuture()) {
                    $hasInsurance = true;
                    $insuranceExpiresAt = \Carbon\Carbon::parse($expiresAt)->timestamp;
                }
                $insurancePremium = (int)$shop->getSetting('insurance_premium', 50000);
            }

            return Inertia::render('City/Shop', [
                'city' => [
                    'name' => $city->name,
                    'slug' => $city->slug,
                ],
                'shop' => [
                    'name' => $shop->name,
                    'description' => $shop->description,
                    'image_url' => $shop->image_url,
                    'owner_name' => $shop->owner ? $shop->owner->display_name : (strtolower($shop->code) === 'shop-blackmarket' ? 'Underground Network' : 'STATE OWNED'),
                    'owner_avatar' => $shop->owner ? $shop->owner->avatar_url : null,
                    'is_owner' => $isOwner,
                    'slug' => $shop->slug,
                    'code' => $shop->code,
                    'can_restock' => $isOwner ? $shop->canManualRestock() : null,
                    'next_restock_at' => $isOwner && $nextRestock ? $nextRestock->timestamp : null,
                    'has_insurance' => $hasInsurance,
                    'insurance_expires_at' => $insuranceExpiresAt,
                    'insurance_premium' => $insurancePremium,
                ],
                'products' => $items->map(function ($item) {
                $maxDurability = $item->durability ?? 0;
                $currentDurability = $item->stock > 0 ? $maxDurability : 0;
                $conditionPercent = $maxDurability > 0 ? (int)round(($currentDurability / $maxDurability) * 100) : null;

                return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'slug' => $item->slug,
                        'price' => $item->price,
                        'image_url' => $item->image_url,
                        'is_active' => (bool)$item->is_active,
                        'attributes' => [
                            'slot' => $item->slot ?? null,

                            'stock' => $item->stock ?? 0,
                            'description' => $item->description ?? null,
                        ],
                    ];
            }),
                'character' => [
                    'money' => $character->cash_on_hand,
                    'inventory_items' => $character->items()->with('template')->get()->map(fn($i) => [
            'slug' => $i->template->slug,
            'location' => $i->location,
            ])->toArray(),
                ],
            ]);
        }
        catch (\Throwable $e) {
            Log::error('Shop index error', [
                'character_id' => $character->id ?? null,
                'business_slug' => $business_slug,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'The shop could not be loaded at this time.');
        }
    }

    public function purchase(Request $request, City $city, string $business_slug)
    {
        $request->validate([
            'item_slug' => 'required|string',
        ]);

        [$character, $city] = $this->getContext($request, $city);
        $slug = trim($request->input('item_slug'));

        $shop = Business::byCity($city)->where('slug', 'ILIKE', $business_slug)->first();
        if (!$shop || !str_starts_with(strtolower($shop->code), 'shop-')) {
            abort(404);
        }

        DB::beginTransaction();
        try {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();
            $shop = Business::where('id', $shop->id)->lockForUpdate()->first();

            if (!$shop) {
                DB::rollBack();
                throw new \Exception('System error: Data not found.');
            }

            $item = $shop->findShopItem($slug);

            if (!$item) {
                DB::rollBack();
                return back()->with('error', 'Item not found in this shop.');
            }

            if (strtolower($shop->code) === 'shop-pharmacy' && self::isCorporateOnlyItem($item)) {
                DB::rollBack();
                return back()->with('error', 'Item not found in this shop.');
            }

            $item = \App\Models\GameItem::where('id', $item->id)->lockForUpdate()->first();

            if (!$item) {
                DB::rollBack();
                return back()->with('error', 'Item not found in this shop.');
            }

            if (strtolower($shop->code) === 'shop-pharmacy' && self::isCorporateOnlyItem($item)) {
                DB::rollBack();
                return back()->with('error', 'Item not found in this shop.');
            }

            if (isset($item->stock) && $item->stock <= 0) {
                DB::rollBack();
                return back()->with('error', 'Item is out of stock.');
            }

            $price = $item->price;

            if (strtolower($shop->code) === 'shop-pharmacy') {
                $subscribers = $shop->getSetting('insurance_subscribers', []);
                $expiresAt = $subscribers[(string)$character->id] ?? null;
                if ($expiresAt && \Carbon\Carbon::parse($expiresAt)->isFuture()) {
                    $price = (int)round($price * 0.70);
                }
            }

            if ($character->cash_on_hand < $price) {
                DB::rollBack();
                return back()->with('error', 'You do not have enough money for this purchase.');
            }

            if (!$character->removeCash($price, false)) {
                DB::rollBack();
                return back()->with('error', 'You do not have enough money for this purchase.');
            }

            $character->giveItem($slug);

            if (isset($item->stock)) {
                $item->decrement('stock');
            }

            $shop->addBalance($price);

            $character->save();

            DB::commit();

            return back()->with('success', "Purchased {$item->name}!");
        }
        catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Purchase failed', [
                'character_id' => $character->id ?? null,
                'item_slug' => $slug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $isDomainError = ($e instanceof \Exception && !($e instanceof \Error));
            $message = $isDomainError ? $e->getMessage() : 'The acquisition could not be processed at this time.';

            return back()->with('error', $message);
        }
    }

    public function restock(Request $request, City $city, string $business_slug)
    {
        [$character, $city] = $this->getContext($request, $city);
        $shop = Business::byCity($city)->where('slug', 'ILIKE', $business_slug)->first();

        if (!$shop || !str_starts_with(strtolower($shop->code), 'shop-')) {
            abort(404);
        }

        if ($shop->owner_id !== $character->id) {
            abort(403, 'This isn\'t your shop!');
        }

        if (!$shop->canManualRestock()) {
            return back()->with('error', 'Restock is on cooldown.');
        }

        $shop->restock();

        return back()->with('success', 'Shop catalog restocked! Items were successfully replenished.');
    }

    public function settings(Request $request, City $city, string $business_slug)
    {
        [$character, $city] = $this->getContext($request, $city);
        $shop = Business::byCity($city)->where('slug', 'ILIKE', $business_slug)->first();

        if (!$shop || !str_starts_with(strtolower($shop->code), 'shop-')) {
            abort(404);
        }

        if ($shop->owner_id !== $character->id) {
            abort(403, 'This isn\'t your shop!');
        }

        $validated = $request->validate([
            'description' => 'nullable|string|max:255',
            'image_url' => 'nullable|url',
        ]);

        $shop->update([
            'description' => $validated['description'] ?? $shop->description,
            'image_url' => $validated['image_url'] ?? $shop->image_url,
        ]);

        if (strtolower($shop->code) === 'shop-pharmacy' && $request->has('insurance_premium')) {
            $premium = (int)$request->input('insurance_premium');
            $premium = max(10000, min(100000, $premium));
            $shop->setSetting('insurance_premium', $premium);
        }

        return back()->with('success', 'Shop settings updated.');
    }

    public function purchaseInsurance(Request $request, City $city, string $business_slug)
    {
        [$character, $city] = $this->getContext($request, $city);
        $shop = Business::byCity($city)->where('slug', 'ILIKE', $business_slug)->first();

        if (!$shop || strtolower($shop->code) !== 'shop-pharmacy') {
            abort(404);
        }

        $subscribers = $shop->getSetting('insurance_subscribers', []);
        $existing = $subscribers[(string)$character->id] ?? null;

        if ($existing && \Carbon\Carbon::parse($existing)->isFuture()) {
            return back()->with('error', 'You already have active insurance.');
        }

        $premium = (int)$shop->getSetting('insurance_premium', 50000);

        if ($character->cash_on_hand < $premium) {
            return back()->with('error', 'Insufficient funds for insurance premium.');
        }

        DB::beginTransaction();
        try {
            $character->removeCash($premium);
            $shop->addBalance($premium);

            $subscribers[(string)$character->id] = now()->addDays(7)->toIso8601String();
            $shop->setSetting('insurance_subscribers', $subscribers);

            DB::commit();

            return back()->with('success', 'Health insurance purchased! Coverage active for 7 days.');
        }
        catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Insurance purchase failed', [
                'character_id' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Could not process insurance purchase.');
        }
    }



    private static function isCorporateOnlyItem(\App\Models\GameItem $item): bool
    {
        return (bool) data_get($item->data ?? [], 'corporate_only', false);
    }
}
