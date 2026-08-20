<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Career;
use App\Models\City;
use App\Models\MayorTerm;
use App\Models\Property;
use App\Support\SafeCache;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;


class BusinessController extends CityController
{
    public function index(Request $request, City $city)
    {

        try {
            [$character, $city] = $this->getContext($request, $city);

            $properties = Property::orderBy('price', 'asc')->get();

            $ownedProperty = $character->property;

            $businesses = Business::byCity($city)
                ->with('owner')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            $ownedBusinesses = Business::byCity($city)
                ->where('owner_id', $character->id)
                ->get();

            return Inertia::render('City/Business', [
                'properties' => $properties->map(fn($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'price' => $p->price,
                    'image_url' => $p->image_url,
                    'vehicle_capacity' => $p->vehicle_capacity,
                    'safe_capacity' => $p->safe_capacity,
                    'has_alarm' => $p->has_alarm,
                ]),
                'businesses' => $businesses->map(fn($b) => [
                    'id' => $b->id,
                    'code' => $b->code,
                    'slug' => $b->slug,
                    'name' => $b->name,
                    'description' => $b->description,
                    'icon' => $b->icon,
                    'image_url' => $b->image_url,
                    'base_price' => $b->base_price,
                    'formatted_price' => '$' . number_format($b->base_price),
                    'is_purchasable' => $b->is_purchasable,
                    'is_active' => $b->is_active,
                    'is_owned' => $b->owner_id !== null,
                    'owner_id' => $b->owner_id,
                    'owner_name' => $b->owner?->display_name,
                    'owner_title' => $b->owner_title,
                ]),
                'ownedProperty' => $ownedProperty ? [
                    'id' => $ownedProperty->id,
                    'name' => $ownedProperty->name,
                    'image_url' => $ownedProperty->image_url,
                    'sell_price' => $ownedProperty->sell_price,
                    'vehicle_capacity' => $ownedProperty->vehicle_capacity,
                    'safe_capacity' => $ownedProperty->safe_capacity,
                    'has_alarm' => $ownedProperty->has_alarm,
                    'property_condition' => $character->property_condition,
                ] : null,
                'ownedBusinesses' => $ownedBusinesses->map(fn($b) => [
                    'id' => $b->id,
                    'code' => $b->code,
                    'slug' => $b->slug,
                    'name' => $b->name,
                    'image_url' => $b->image_url,
                    'description' => $b->description,
                    'balance' => $b->owner_id === $character->id ? $b->balance : null,
                    'formatted_balance' => $b->owner_id === $character->id ? '$' . number_format($b->balance) : null,
                    'base_price' => $b->base_price,
                    'sell_value' => (int) round($b->base_price * 0.5),
                    'is_purchasable' => $b->is_purchasable,
                ]),
                'cityData' => [
                    'name' => $city->name,
                    'slug' => $city->slug,
                    'image_url' => $city->image_url,
                ],
                'character' => [
                    'id' => $character->id,
                    'cleanCash' => $character->cash_on_hand,
                    'formatted_cash' => '$' . number_format($character->cash_on_hand),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Business index error', [
                'user_id' => $request->user()->id,
                'city_slug' => $city->slug ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Failed to load data. Please try again.');
        }
    }

    public function purchase(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        $request->validate([
            'business_id' => 'required|integer|exists:businesses,id',
        ]);

        $businessId = $request->input('business_id');

        DB::beginTransaction();
        try {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();
            $business = Business::where('id', $businessId)->lockForUpdate()->first();

            if (!$business) {
                throw new \Exception('Business not found.');
            }

            if ($business->city_id !== $city->id) {
                DB::rollBack();
                return back()->with('error', 'This business is not located in this city.');
            }

            if (!$business->is_purchasable) {
                DB::rollBack();
                return back()->with('error', 'This business is not currently for sale.');
            }

            if ($character->isHospitalized() || $character->isJailed()) {
                DB::rollBack();
                return back()->with('error', 'You cannot purchase a business while hospitalized or jailed.');
            }

            if (!$character->hasBusinessCapacity()) {
                DB::rollBack();
                return back()->with('error', 'You cannot own more than 3 businesses at a time.');
            }

            if ($character->cash_on_hand < $business->base_price) {
                DB::rollBack();
                throw new \Exception('You cannot afford to purchase this business.');
            }

            $businessCode = strtolower((string) $business->code);

            if ($businessCode === 'city-hall') {
                $isMayor = (int) ($city->mayor_id ?? 0) === (int) $character->id;
                if (!$isMayor) {
                    DB::rollBack();
                    return back()->with('error', 'Only the sitting Mayor may purchase City Hall.');
                }
            }

            if ($businessCode === 'police') {
                $policeCareerId = Career::findByCode('police')?->id;
                $isCommissionerGeneral = $policeCareerId
                    && (int) $character->career_id === (int) $policeCareerId
                    && (int) $character->career_rank >= 4
                    && (int) $character->home_city_id === (int) $city->id;

                if (!$isCommissionerGeneral) {
                    DB::rollBack();
                    return back()->with('error', 'Only the Commissioner-General of this city may purchase the Police HQ.');
                }
            }

            if (!$business->is_active) {
                throw new \Exception('This Business is currently disabled.');
            }

            if (
                strtolower((string) $business->code) === 'bank'
                && \App\Models\BankCertificate::active()->forBank($business->id)->exists()
            ) {
                DB::rollBack();
                return back()->with('error', 'This bank cannot be purchased at the moment due to its prior committments!');
            }

            $previousOwner = $business->owner;

            if ($previousOwner) {
                if ($previousOwner->id === $character->id) {
                    DB::rollBack();
                    return back()->with('error', 'You already own this business.');
                }

                $previousOwner->cash_in_bank += $business->base_price;
                $previousOwner->save();

                \App\Services\JournalService::businessSold(
                    ownerId: $previousOwner->id,
                    buyerName: $character->display_name,
                    businessName: $business->name,
                    price: $business->base_price,
                    balance: $business->balance
                );

                \App\Models\BankTransaction::record(
                    characterId: $previousOwner->id,
                    type: \App\Models\BankTransaction::TYPE_DEPOSIT,
                    amount: $business->base_price,
                    balanceAfter: $previousOwner->cash_in_bank,
                    counterparty: $character->display_name,
                    note: $business->name,
                );
            }

            $character->cash_on_hand -= $business->base_price;
            $character->save();

            $business->update([
                'owner_id' => $character->id,
                'is_purchasable' => false,
            ]);

            DB::commit();

            $this->recordCityNews($city->id, $character->display_name, $business->name);

            return back()->with('success', " Congratulations, You have successfully acquired  the {$business->name}.");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Business acquisition failed', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'business_id' => $businessId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', $e->getMessage());
        }
    }

    private function recordCityNews(int $cityId, string $buyerName, string $businessName): void
    {
        $key = "city_news:business_purchases:{$cityId}";
        $cutoff = now('UTC')->subHour();
        $events = collect(SafeCache::get($key, []))
            ->filter(function ($event) use ($cutoff) {
                if (! is_array($event) || empty($event['occurredAt'])) {
                    return false;
                }

                try {
                    return Carbon::parse($event['occurredAt'])->gte($cutoff);
                } catch (\Throwable) {
                    return false;
                }
            })
            ->prepend([
                'type' => 'business',
                'message' => "{$buyerName} has purchased {$businessName}.",
                'occurredAt' => now('UTC')->toIso8601String(),
            ])
            ->take(8)
            ->values()
            ->all();

        SafeCache::put($key, $events, now()->addHour());
    }

    public function sell(Request $request, City $city)
    {
        try {
            [$character, $city] = $this->getContext($request, $city);

            $request->validate([
                'business_id' => 'required|integer|exists:businesses,id',
                'price' => 'required|integer|min:1',
            ]);

            $businessId = $request->input('business_id');
            $price = $request->input('price');

            DB::beginTransaction();
            $business = Business::lockForUpdate()->find($businessId);

            if (!$business) {
                DB::rollBack();
                return back()->with('error', 'Business not found.');
            }

            if ($business->owner_id !== $character->id) {
                throw new \Exception('Unauthorized management attempt.');
            }

            if ($business->city_id !== $city->id) {
                throw new \Exception('Location mismatch.');
            }

            if (
                strtolower((string) $business->code) === 'bank'
                && \App\Models\BankCertificate::active()->forBank($business->id)->exists()
            ) {
                DB::rollBack();
                return back()->with('error', 'You cannot sell this bank while certificates of deposit are still active.');
            }

            $business->update([
                'is_purchasable' => true,
                'base_price' => $price,
            ]);

            DB::commit();

            return back()->with('success', "{$business->name} has been listed for sale at $" . number_format($price));

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Business listing failed', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'business_id' => $businessId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Listing failed. Please try again.');
        }
    }

    public function cancelSale(Request $request, City $city)
    {
        try {
            [$character, $city] = $this->getContext($request, $city);

            $request->validate([
                'business_id' => 'required|integer|exists:businesses,id',
            ]);

            $businessId = $request->input('business_id');

            DB::beginTransaction();
            $business = Business::lockForUpdate()->find($businessId);

            if (!$business) {
                DB::rollBack();
                return back()->with('error', 'Business not found.');
            }

            if ($business->owner_id !== $character->id) {
                throw new \Exception('Unauthorized management attempt.');
            }

            if ($business->city_id !== $city->id) {
                throw new \Exception('Location mismatch.');
            }

            if (!$business->is_purchasable) {
                DB::rollBack();
                return back()->with('error', 'This business is not listed for sale.');
            }

            $business->update(['is_purchasable' => false]);

            DB::commit();

            return back()->with('success', "Sale listing for {$business->name} has been cancelled.");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Business sale cancellation failed', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'business_id' => $businessId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Cancellation failed. Please try again.');
        }
    }

    public function withdraw(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        $request->validate([
            'business_id' => 'required|integer|exists:businesses,id',
            'amount' => 'required|integer|min:1',
        ]);

        $businessId = $request->input('business_id');
        $amount = $request->input('amount');

        DB::beginTransaction();
        try {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();
            $business = Business::where('id', $businessId)->lockForUpdate()->first();

            if (!$business) {
                DB::rollBack();
                return back()->with('error', 'Business not found');
            }

            if ($business->owner_id !== $character->id) {
                DB::rollBack();
                return back()->with('error', 'You do not own this business');
            }

            if ($business->balance < $amount) {
                DB::rollBack();
                return back()->with('error', 'Insufficient business funds');
            }

            if (strtolower((string) $business->code) === 'bank') {
                $liabilities = \App\Models\BankCertificate::totalLiabilityForBank($business->id);
                $available = max(0, $business->balance - $liabilities);

                if ($amount > $available) {
                    DB::rollBack();
                    return back()->with('error', 'You must keep $' . number_format($liabilities) . ' reserved for active certificates of deposit.');
                }
            }

            $taxDeducted = 0;
            $activeTerm = MayorTerm::activeForCity($business->city_id);

            if ($activeTerm) {
                $policies = $activeTerm->getPolicies();
                $incomeTax = (int) ($policies['income_tax_rate'] ?? 5);
                $corpTax = 0;

                if ($activeTerm->corpRegUnlocked() && $character->corporation_id !== null) {
                    $corpTax = (int) ($policies['corporate_tax_rate'] ?? 0);
                }

                $combinedRate = min(35, $incomeTax + $corpTax);
                $taxDeducted = (int) floor($amount * ($combinedRate / 100));

                if ($taxDeducted > 0) {
                    $cityRevenue = (int) floor($taxDeducted * $activeTerm->servicesMultiplier());
                    $activeTerm->addFunds($cityRevenue, 'tax');
                }
            }

            $payout = $amount - $taxDeducted;

            DB::table('businesses')->where('id', $business->id)->decrement('balance', $amount);

            $character->addCash($payout, false, false);
            $character->save();

            \App\Models\CharacterHistory::addHistory($character, 'earned_business', $payout);

            DB::commit();

            if ($taxDeducted > 0) {
                return back()->with([
                    'success' => 'Withdrew $' . number_format($payout) . " from {$business->name}.",
                    'warning' => 'The city took $' . number_format($taxDeducted) . ' in taxes.',
                ]);
            }

            return back()->with('success', 'Withdrew $' . number_format($payout) . " from {$business->name}.");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Business withdrawal failed', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'business_id' => $businessId,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Withdrawal failed. Please try again.');
        }
    }

    public function updateDescription(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        $request->validate([
            'business_id' => 'required|integer|exists:businesses,id',
            'description' => 'required|string|max:30',
        ]);

        $business = Business::find($request->input('business_id'));

        if (!$business || $business->owner_id !== $character->id) {
            return back()->with('error', 'You do not own this business.');
        }

        if ($business->city_id !== $city->id) {
            return back()->with('error', 'Business is not in this city.');
        }

        $business->update(['description' => $request->input('description')]);

        return back()->with('success', "Description for {$business->name} has been updated.");
    }
}
