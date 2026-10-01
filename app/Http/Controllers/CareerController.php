<?php

namespace App\Http\Controllers;


use App\Http\Controllers\CorporationController;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\City;
use App\Models\Corporation;
use App\Models\CorporationBoardPromotion;
use App\Models\CorporationSubsidiaryInvite;
use App\Models\CorporationMergerRequest;
use App\Models\CorporationProperty;
use App\Models\CorporationTrustVote;
use App\Models\CrimeRecord;
use App\Models\GameItem;
use App\Models\MayorTerm;
use App\Services\JournalService;
use App\Services\MayorService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

//! all starting career/mayor careers are handled here.

//!  5 WAYS TO CHANGE CAREERS, UNI START CAREER, QUIT CAREER, POLICE, CORPORATION CHECK ALL.
//! IF LAW OR POLICE CANNOT JOIN CORPORATION
//! if MAYOR OR COMMISH AND OWN THE CITY HALL OR STATION BUSINESSES MUST BE RELEASED AT TIME OF QUIT.
//! CORPORATION CANNOT QUIT CAREER OR UNI START CAREER OR JOIN THE POLICE
// ! OTHER CAREERS CAN DO IT ALL.
//! police and law can still quit through mayor which is fine.


class CareerController extends Controller
{
    public function technician(Request $request)
    {
        $character = $request->user()->character;


        $vehicles = CharacterItem::where('durability_remaining', 0)
            ->whereHas('template', fn($q) => $q->where('type', 'vehicle'))
            ->whereHas('character', fn($q) => $q->where('city_id', $character->city_id))
            ->with(['template:id,name,slug,image_url,price,durability', 'character:id,display_name'])
            ->get()
            ->map(fn($item) => [
                'id' => $item->id,
                'vehicle_name' => $item->template->name,
                'vehicle_image' => $item->template->image_url,
                'owner_name' => $item->character->display_name,
                'repair_cost' => (int) ceil($item->template->price * 0.05),
            ]);




        $uninspectedHomes = Character::where('home_city_id', $character->city_id)
            ->whereNotNull('property_id')
            ->where(fn($q) => $q->whereNull('property_condition')->orWhere('property_condition', ''))
            ->with('property:id,name,image_url,price')
            ->get()
            ->map(fn($owner) => [
                'owner_id' => $owner->id,
                'owner_name' => $owner->display_name,
                'property_name' => $owner->property->name,
                'property_image' => $owner->property->image_url,
                'inspect_fee' => $owner->id === $character->id ? 0 : (int) ceil($owner->property->price * 0.02),
                'is_self' => $owner->id === $character->id,
            ]);

        // Engineer (rank 2+) sees pending corporate properties they can
        // construct. Filtered to corps based in this city — same locality
        // rule we already apply to home repair / inspection.
        $pendingCorpProperties = $character->career_rank >= 2
            ? \App\Models\CorporationProperty::query()
                ->where('condition', \App\Models\CorporationProperty::CONDITION_PENDING)
                ->whereNotNull('corporation_id')
                ->whereHas('corporation', fn($q) => $q->where('home_city_id', $character->city_id))
                ->with('corporation:id,name,home_city_id')
                ->get()
                ->map(fn($property) => [
                    'property_id' => $property->id,
                    'property_name' => $property->name,
                    'property_image' => $property->image_url,
                    'property_type' => $property->type,
                    'property_tier' => $property->tier,
                    'corporation_name' => $property->corporation?->name,
                    'build_fee' => max(1, (int) ceil($property->price * 0.001)),
                ])
                ->all()
            : [];

        return Inertia::render('Careers/Workshop', [
            'vehicles' => $vehicles,
            'uninspectedHomes' => $uninspectedHomes,
            'destroyedHomes' => $character->career_rank >= 2
                ? Character::where('home_city_id', $character->city_id)
                    ->whereNotNull('property_id')
                    ->where('property_condition', \App\Models\Property::CONDITION_DESTROYED)
                    ->with('property:id,name,image_url,price')
                    ->get()
                    ->map(fn($owner) => [
                        'owner_id' => $owner->id,
                        'owner_name' => $owner->display_name,
                        'property_name' => $owner->property->name,
                        'property_image' => $owner->property->image_url,

                        'repair_fee' => $owner->id === $character->id ? 0 : (int) ceil($owner->property->price * 0.05),
                        'is_self' => $owner->id === $character->id,
                    ])
                : [],
            'pendingCorpProperties' => $pendingCorpProperties,
        ]);
    }

    public function repair(Request $request)
    {
        $request->validate(['item_id' => 'required|integer']);
        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character, $request) {
                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

                if ($character->timers->next_action_at?->isFuture()) {
                    return back()->with('error', 'You need to wait before performing another action.');
                }
                if ($character->isHospitalized() || !$character->isAlive() || $character->isJailed()) {
                    return back()->with('error', 'You cannot work in your current state.');
                }

                $item = CharacterItem::lockForUpdate()->find($request->item_id);
                if (!$item || $item->durability_remaining !== 0) {
                    return back()->with('error', 'This vehicle is no longer available for repair.');
                }

                $template = $item->template;
                if (!$template || $template->type !== 'vehicle') {
                    return back()->with('error', 'Invalid vehicle.');
                }

                $owner = Character::lockForUpdate()->find($item->character_id);
                if (!$owner || $owner->city_id !== $character->city_id) {
                    return back()->with('error', 'Vehicle owner is no longer in this city.');
                }

                $isSelf = $owner->id === $character->id;
                $repairCost = (int) ceil($template->price * 0.05);

                if ($owner->cash_on_hand < $repairCost) {
                    return back()->with('error', 'The vehicle owner cannot afford the $' . number_format($repairCost) . ' repair cost.');
                }

                $owner->decrement('cash_on_hand', $repairCost);

                $techCut = (int) floor($repairCost * 1.0);
                if ($techCut > 0 && !$isSelf) {
                    $character->increment('cash_on_hand', $techCut);
                }

                if ($techCut > 0)
                    \App\Models\CharacterHistory::addHistory($character, 'earned_career', $techCut);

                $xpFactor = log10(max(1, $character->career_xp) + 1) / log10(10001);
                $priceFactor = max(0, min(1, 1 - log10(max(1, $template->price)) / log10(5000001)));
                $restored = max(1, min($template->durability, (int) ceil(($xpFactor * 0.7 + $priceFactor * 0.3) * $template->durability)));
                $item->update(['durability_remaining' => $restored]);

                $xp = (int) min(50, ceil(sqrt($template->price) / 20));
                if ($isSelf)
                    $xp = (int) ceil($xp * 0.25);
                $character->addXp($xp);

                \App\Models\CharacterHistory::addHistory($character, 'vehicles_repaired');

                $character->timers()->update([
                    'next_action_at' => now()->addSeconds(config('timers.action'))->getTimestamp(),
                ]);

                if (!$isSelf) {
                    JournalService::vehicleRepaired(
                        $owner->id,
                        $character->display_name,
                        $template->name,
                        $repairCost,
                        $restored,
                        $template->durability
                    );
                }

                Log::info('[Career] Vehicle repaired.', [
                    'technician' => $character->id,
                    'owner' => $owner->id,
                    'item' => $item->id,
                    'repair_cost' => $repairCost,
                    'restored' => $restored,
                    'is_self' => $isSelf,
                ]);

                $ratio = $template->durability > 0 ? $restored / $template->durability : 0;
                $target = $isSelf ? "your {$template->name}" : "{$owner->display_name}'s {$template->name}";

                $msg = match (true) {
                    $ratio >= 1.0 => "You managed to repair {$target} so well it's like it's brand new!",
                    $ratio >= 0.75 => "You managed to repair {$target} good enough to survive a couple more rounds.",
                    $ratio >= 0.50 => "You managed to repair {$target} well enough to keep it running, but it's seen better days.",
                    $ratio >= 0.25 => "You managed to repair {$target} good enough to run, but it's not in great shape!",
                    default => "You managed to patch {$target} up just enough to limp along.",
                };

                return back()->with('success', $msg);
            });
        } catch (\Throwable $e) {
            Log::error('[Career] Repair failed.', [
                'character_id' => $character->id,
                'item_id' => $request->item_id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Repair failed. Please try again.');
        }
    }

    public function inspectHome(Request $request)
    {
        $request->validate(['owner_id' => 'required|integer']);
        $character = $request->user()->character;
        $isSelf = (int) $request->owner_id === $character->id;

        try {
            return DB::transaction(function () use ($character, $request, $isSelf) {
                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

                if ($character->timers->next_action_at?->isFuture()) {
                    return back()->with('error', 'You need to wait before performing another action.');
                }
                if ($character->isHospitalized() || !$character->isAlive() || $character->isJailed()) {
                    return back()->with('error', 'You cannot work in your current state.');
                }

                $query = Character::where('id', $request->owner_id)
                    ->where('home_city_id', $character->city_id)
                    ->whereNotNull('property_id')
                    ->where(fn($q) => $q->whereNull('property_condition')->orWhere('property_condition', ''));

                if (!$isSelf) {
                    $query->where('id', '!=', $character->id);
                }

                $owner = $query->lockForUpdate()->first();

                if (!$owner) {
                    return back()->with('error', 'Property not found or already inspected.');
                }

                $property = $owner->property;
                $fee = $isSelf ? 0 : (int) ceil($property->price * 0.02);

                if (!$isSelf) {
                    $character->increment('cash_on_hand', $fee);
                    \App\Models\CharacterHistory::addHistory($character, 'earned_career', $fee);
                }

                DB::table('characters')
                    ->where('id', $owner->id)
                    ->update(['property_condition' => \App\Models\Property::CONDITION_CONSTRUCTED]);

                $xp = min(100, (int) ceil(sqrt($property->price) / 22));

                if ($isSelf)
                    $xp = (int) ceil($xp * 0.5);

                $character->addXp($xp);

                $character->timers()->update([
                    'next_action_at' => now()->addSeconds(config('timers.action'))->getTimestamp(),
                ]);

                \App\Models\CharacterHistory::addHistory($character, 'homes_inspected');

                if (!$isSelf) {
                    JournalService::homeInspected(
                        $owner->id,
                        $character->display_name,
                        $property->name,
                        $fee
                    );
                }

                Log::info('[Career] Home inspected.', [
                    'technician' => $character->id,
                    'owner' => $owner->id,
                    'property' => $property->id,
                    'fee' => $fee,
                    'self' => $isSelf,
                ]);

                return $isSelf
                    ? back()->with('success', "Your {$property->name} has been certified.")
                    : back()->with('success', "Inspection complete. {$owner->display_name}'s {$property->name} has been certified and you have earned $" . number_format($fee) . ' as an inspector.');
            });
        } catch (\Throwable $e) {
            Log::error('[Career] Home inspection failed.', [
                'character_id' => $character->id,
                'owner_id' => $request->owner_id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Inspection failed. Please try again.');
        }
    }

    public function repairHome(Request $request)
    {
        $request->validate(['owner_id' => 'required|integer']);
        $character = $request->user()->character;
        $isSelf = (int) $request->owner_id === $character->id;

        try {
            return DB::transaction(function () use ($character, $request, $isSelf) {
                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

                if ($character->timers->next_action_at?->isFuture()) {
                    return back()->with('error', 'You need to wait before performing another action.');
                }
                if ($character->isHospitalized() || !$character->isAlive() || $character->isJailed()) {
                    return back()->with('error', 'You cannot work in your current state.');
                }

                if ($character->career_rank < 2) {
                    return back()->with('error', 'Only Engineers can repair destroyed properties.');
                }

                $query = Character::where('id', $request->owner_id)
                    ->where('home_city_id', $character->city_id)
                    ->whereNotNull('property_id')
                    ->where('property_condition', \App\Models\Property::CONDITION_DESTROYED);

                if (!$isSelf) {
                    $query->where('id', '!=', $character->id);
                }

                $owner = $query->lockForUpdate()->first();

                if (!$owner) {
                    return back()->with('error', 'Property not found or not in a destroyed state.');
                }



                if (
                    $owner->property_condition !== \App\Models\Property::CONDITION_DESTROYED
                    || !$owner->property_id
                ) {
                    return back()->with('error', 'This property is no longer in a destroyed state.');
                }

                $property = $owner->property;
                $fee = $isSelf ? 0 : (int) ceil($property->price * 0.015);

                if (!$isSelf) {
                    if ($owner->cash_on_hand < $fee) {
                        return back()->with('error', "The property owner cannot afford the $" . number_format($fee) . ' reconstruction cost.');
                    }
                    $owner->decrement('cash_on_hand', $fee);
                    $character->increment('cash_on_hand', $fee);
                    \App\Models\CharacterHistory::addHistory($character, 'earned_career', $fee);
                }

                $homesInspected = (int) (DB::table('character_histories')
                    ->where('character_id', $character->id)
                    ->value('homes_inspected') ?? 0);

                //! The same logic that makes a home harder to bomb also makes it harder to repair

                $tierPenalty = match ($property->name) {
                    'Rowhouse' => 0,
                    'Apartment' => 5,
                    'Estate' => 10,
                    'Skyscraper Home' => 15,
                    'Private Island' => 25,
                    default => 10,
                };

                $xpFactor = max(0.0, 1.0 - log10(max(1, $character->career_xp) + 1) / log10(100_001));
                $expFactor = max(0.0, 1.0 - min(1.0, $homesInspected / 50));
                $skillDeficit = (int) round(30 * ($xpFactor * 0.5 + $expFactor * 0.5));


                $successChance = max(4, min(55, 55 - $tierPenalty - $skillDeficit));

                if (mt_rand(1, 100) > $successChance) {




                    $survivingItemCount = $owner->items()
                        ->whereIn('location', ['safe', 'garage'])
                        ->count();
                    $owner->items()
                        ->delete();


                    DB::table('characters')
                        ->where('id', $owner->id)
                        ->update(['property_id' => null, 'property_condition' => null]);


                    $character->timers()->update([
                        'next_action_at' => now()->addSeconds(config('timers.action'))->getTimestamp(),
                    ]);

                    \App\Models\CharacterHistory::addHistory($character, 'homes_inspected');

                    if (!$isSelf) {
                        JournalService::custom($owner->id, 'home_repaired', [
                            'technician_name' => $character->display_name,
                            'property_name' => $property->name,
                            'fee' => $fee,
                            'failed' => true,
                            'surviving_items_lost' => $survivingItemCount,
                        ]);
                    }

                    Log::info('[Career] Home reconstruction failed — property lost.', [
                        'technician' => $character->id,
                        'owner' => $owner->id,
                        'property' => $property->id,
                        'success_chance' => $successChance,
                        'tier_penalty' => $tierPenalty,
                        'skill_deficit' => $skillDeficit,
                        'self' => $isSelf,
                    ]);

                    return $isSelf
                        ? back()->with('error', "Your attempt to repair your {$property->name} went wrong — the structure couldn't be salvaged. The property has been lost.")
                        : back()->with('error', "The reconstruction of {$owner->display_name}'s {$property->name} failed — the structure couldn't hold. The property has been lost.");
                }

                DB::table('characters')
                    ->where('id', $owner->id)
                    ->update(['property_condition' => \App\Models\Property::CONDITION_CONSTRUCTED]);



                $xp = min(200, (int) ceil(sqrt($property->price) / 11));
                if ($isSelf)
                    $xp = (int) ceil($xp * 0.5);
                $character->addXp($xp);

                $character->timers()->update([
                    'next_action_at' => now()->addSeconds(config('timers.action'))->getTimestamp(),
                ]);

                \App\Models\CharacterHistory::addHistory($character, 'homes_inspected');

                if (!$isSelf) {
                    JournalService::custom($owner->id, 'home_repaired', [
                        'technician_name' => $character->display_name,
                        'property_name' => $property->name,
                        'fee' => $fee,
                    ]);
                }

                Log::info('[Career] Home reconstructed.', [
                    'technician' => $character->id,
                    'owner' => $owner->id,
                    'property' => $property->id,
                    'fee' => $fee,
                    'success_chance' => $successChance,
                    'tier_penalty' => $tierPenalty,
                    'skill_deficit' => $skillDeficit,
                    'self' => $isSelf,
                ]);

                return $isSelf
                    ? back()->with('success', "You managed to successfully repair your  {$property->name} back to shape .")
                    : back()->with('success', "Reconstruction complete. {$owner->display_name}'s {$property->name} has been rebuilt and you have earned $" . number_format($fee) . '.');
            });
        } catch (\Throwable $e) {
            Log::error('[Career] Home reconstruction failed.', [
                'character_id' => $character->id,
                'owner_id' => $request->owner_id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Reconstruction failed. Please try again.');
        }
    }

    public function constructCorporationProperty(Request $request)
    {
        $request->validate(['property_id' => 'required|integer']);
        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character, $request) {
                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

                if ($character->timers->next_action_at?->isFuture()) {
                    return back()->with('error', 'You need to wait before performing another action.');
                }
                if ($character->isHospitalized() || !$character->isAlive() || $character->isJailed()) {
                    return back()->with('error', 'You cannot work in your current state.');
                }
                if ($character->career_rank < 2) {
                    return back()->with('error', 'Only Engineers can construct corporation properties.');
                }

                $property = \App\Models\CorporationProperty::query()
                    ->where('condition', \App\Models\CorporationProperty::CONDITION_PENDING)
                    ->whereNotNull('corporation_id')
                    ->where('id', (int) $request->property_id)
                    ->lockForUpdate()
                    ->first();

                if (!$property) {
                    return back()->with('error', 'Property not found or already constructed.');
                }

                $corp = \App\Models\Corporation::where('id', $property->corporation_id)
                    ->lockForUpdate()
                    ->first();

                if (!$corp) {
                    return back()->with('error', 'The owning corporation no longer exists.');
                }

                if ((int) $corp->home_city_id !== (int) $character->city_id) {
                    return back()->with('error', 'You can only construct properties in your current city.');
                }

                $fee = max(1, (int) ceil($property->price * 0.001));

                $character->increment('cash_on_hand', $fee);
                \App\Models\CharacterHistory::addHistory($character, 'earned_career', $fee);

                // For HQ upgrades, delete the old sibling HQ row BEFORE
                // flipping the PENDING row to CONSTRUCTED — otherwise both
                // rows are briefly non-PENDING at the same time and the
                // partial unique index `corp_properties_owned_singleton`
                // (WHERE condition != 'PENDING') fires.
                //
                // The sweep predicate must mirror the index predicate
                // exactly. The old code only swept CONSTRUCTED siblings,
                // which left DEFAULTED rows (from failed daily upkeep) as
                // well as BOMBED / DESTROYED / SEIZED rows in place. When
                // a corp recovered by purchasing a tier upgrade — allowed
                // because purchaseTemplate only blocks on an existing
                // PENDING row — construction would then violate the
                // constraint on the (corporation_id, type) slot.
                //
                // Deleting any non-PENDING sibling is the correct
                // semantic: the new HQ replaces whatever physical/legal
                // state the old one was in.
                if ($property->type === \App\Models\CorporationProperty::TYPE_HQ) {
                    $sweptOldHq = \App\Models\CorporationProperty::where('corporation_id', $corp->id)
                        ->where('type', \App\Models\CorporationProperty::TYPE_HQ)
                        ->where('id', '!=', $property->id)
                        ->where('condition', '!=', \App\Models\CorporationProperty::CONDITION_PENDING)
                        ->lockForUpdate()
                        ->get(['id', 'tier', 'condition']);

                    if ($sweptOldHq->isNotEmpty()) {
                        \App\Models\CorporationProperty::whereIn('id', $sweptOldHq->pluck('id'))->delete();

                        Log::info('[Career] Swept old HQ rows during construction.', [
                            'corp_id' => $corp->id,
                            'new_property_id' => $property->id,
                            'new_tier' => $property->tier,
                            'replaced' => $sweptOldHq->map(fn($row) => [
                                'id' => $row->id,
                                'tier' => $row->tier,
                                'condition' => $row->condition,
                            ])->all(),
                        ]);
                    }
                }

                $property->update(['condition' => \App\Models\CorporationProperty::CONDITION_CONSTRUCTED]);

                if ($property->type === \App\Models\CorporationProperty::TYPE_HQ) {
                    $corp->update(['hq_tier' => $property->tier]);
                }

                $xp = min(500, (int) ceil(sqrt($property->price) / 5));
                $character->addXp($xp);

                $character->timers()->update([
                    'next_action_at' => now()->addSeconds(config('timers.action'))->getTimestamp(),
                ]);

                \App\Models\CharacterHistory::addHistory($character, 'properties_constructed');

                if ($corp->ceo_id) {
                    JournalService::custom($corp->ceo_id, 'corporation_property_constructed', [
                        'engineer_name' => $character->display_name,
                        'property_name' => $property->name,
                        'corporation_name' => $corp->name,
                        'fee' => $fee,
                    ]);
                }

                Log::info('[Career] Corporation property constructed.', [
                    'engineer' => $character->id,
                    'corp' => $corp->id,
                    'property' => $property->id,
                    'type' => $property->type,
                    'tier' => $property->tier,
                    'fee' => $fee,
                ]);

                return back()->with(
                    'success',
                    "Construction complete. {$corp->name}'s {$property->name} is operational and you've earned $" . number_format($fee) . '.'
                );
            });
        } catch (\Throwable $e) {
            Log::error('[Career] Corporation property construction failed.', [
                'character_id' => $character->id,
                'property_id' => $request->property_id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Construction failed. Please try again.');
        }
    }





    public function corporate(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();
        $character?->loadMissing(['career', 'city', 'homeCity', 'corporation']);
        if (!$character) {
            return redirect()->route('dashboard')->with('error', 'Character not found.');
        }

        $corp = $character?->corporation;

        // Set the already-queried corporation onto the relation cache so
        // getPromotionChecker() and getFoundingBlocker() don't re-query it.
        if ($corp && !$character->relationLoaded('corporation')) {
            $character->setRelation('corporation', $corp);
        } elseif (!$corp) {
            $character->setRelation('corporation', null);
        }

        if (
            $corp
            && strcasecmp($character->career?->code, 'corporation') === 0
            && in_array((int) ($character->career_rank ?? 0), [3, 4, 5, 6], true)
            && ($character->rank_requirements['ready'] ?? false)
        ) {
            $promotionBlocker = $character->getPromotionChecker();
            $request->attributes->set('promotion_checker_character_id', $character->id);
            $request->attributes->set('promotion_checker', $promotionBlocker);

            if ($promotionBlocker === null) {
                return redirect()->route('career.promote');
            }
        }

        // Every page prop below is lazy: partial reloads (`only: [...]`, incl.
        // dot paths such as `corporation.members`) only run the queries of the
        // props they ask for. Shared setup is memoized per request so a full
        // page load runs the same queries as before, exactly once.
        $boardroom = function () use ($character) {
            $foundingBlocker = CorporationController::getFoundingBlocker($character);
            $showFoundingForm = strcasecmp($character->career?->code, 'corporation') === 0
                && !$character->corporation_id
                && !$character->isMayor()
                && CorporationController::isFoundingRankEligible($character);

            return [
                'canFound' => $foundingBlocker === null,
                'showFoundingForm' => $showFoundingForm,
                'foundingCost' => CorporationController::FOUNDING_COST,
                'blocker' => $foundingBlocker,
                'homeCity' => $character->homeCity?->name,
                'currentRank' => $character->current_rank?->rank_name ?? 'Staff',
            ];
        };

        if (!$corp) {
            return Inertia::render('Careers/Corporate', [
                'myId' => $character->id,
                'boardroom' => $boardroom,
                'corporation' => null,
                'isLocalEnvironment' => app()->isLocal(),
            ]);
        }

        $ctx = $this->corporatePageContext($character, $corp);
        // Resolve a prop only after the shared relation load has run, so its
        // relations come from the eager load (same queries/columns as before).
        $afterCore = fn (\Closure $resolve) => function () use ($ctx, $resolve) {
            $ctx['core']();

            return $resolve();
        };

        return Inertia::render('Careers/Corporate', [
            'myId' => $character->id,
            'boardroom' => $boardroom,
            'isLocalEnvironment' => app()->isLocal(),
            // Plain array of lazy leaves so the client can request individual
            // `corporation.<key>` paths; key order matches the previous payload.
            'corporation' => [
                'id' => $corp->id,
                'name' => $corp->name,
                'imageUrl' => $corp->image_url,
                'boardNotes' => $corp->board_notes,
                'city' => $afterCore(fn () => $corp->city?->name ?? 'Unknown'),
                'isHoldingCompany' => (bool) $corp->is_holding_company,
                'parentTrustId' => $corp->parent_trust_id,
                'parentTrust' => $afterCore(fn () => $corp->parentTrust ? [
                    'id' => $corp->parentTrust->id,
                    'name' => $corp->parentTrust->name,
                    'imageUrl' => $corp->parentTrust->image_url,
                    'city' => $corp->parentTrust->city?->name ?? 'Unknown',
                ] : null),
                'holdingContext' => fn () => $ctx['holdingContext'](),
                // Operating companies only need their own row; holding companies
                // sum their subsidiaries (from the shared relation load).
                'cash_reserves' => fn () => $corp->is_holding_company
                    ? $afterCore(fn () => (int) $corp->cash_reserves + (int) $corp->subsidiaries->sum('cash_reserves'))()
                    : (int) $corp->cash_reserves,
                'slush_fund' => fn () => $corp->is_holding_company
                    ? $afterCore(fn () => (int) $corp->slush_fund + (int) $corp->subsidiaries->sum('slush_fund'))()
                    : (int) $corp->slush_fund,
                'total_profits' => $corp->total_profits,
                'ceo' => $afterCore(fn () => ['id' => $corp->ceo_id, 'name' => $corp->ceo?->display_name ?? 'Vacant']),
                'isCeo' => $corp->isCeo($character),
                'isCfo' => $corp->roleFor($character) === Corporation::POSITION_CFO,
                'isFounder' => $corp->isFounder($character),
                'maxMembers' => $corp->max_member_slots,
                'ceoCareerRank' => $afterCore(fn () => $corp->ceo?->career_rank ?? 0),
                // Properties tab only: not sent on first load, the page requests
                // them (partial reload) when the Properties tab is opened.
                'properties' => Inertia::optional(fn () => $ctx['properties']()['owned']),
                'purchasableProperties' => Inertia::optional(fn () => $ctx['properties']()['templates']),
                'propertyTemplates' => Inertia::optional(fn () => $ctx['properties']()['templates']),
                'propertyTaxRate' => Inertia::optional(fn () => $ctx['properties']()['taxRate']),
                'members' => $afterCore(fn () => $corp->members->map(fn($m) => $ctx['core']()['mapMember']($m, $corp))),
                'subsidiaries' => $afterCore(fn () => $corp->is_holding_company
                    ? $corp->subsidiaries->map($ctx['core']()['mapOperatingCompany'])
                    : []),
                // Company Actions tab only: requested when that tab is opened.
                'merger' => Inertia::optional(fn () => $ctx['merger']()),
                'subsidiaryInvites' => fn () => $ctx['board']()['subsidiaryInvites'],
                'boardActions' => fn () => $ctx['board']()['boardActions'],
                'pendingMoveRequest' => fn () => $corp->pendingMoveRequest(),
                'moveFee' => Corporation::MOVE_HQ_FEE,
                'isPhaseOneOperatingCompany' => CorporationController::isPhaseOneOperatingCompany($corp),
            ],
        ]);
    }

    /**
     * Wrap a builder so it runs at most once per request (shared setup for
     * several lazy Inertia props).
     */
    private static function memoOnce(\Closure $builder): \Closure
    {
        $resolved = false;
        $value = null;

        return function () use (&$resolved, &$value, $builder) {
            if (!$resolved) {
                $value = $builder();
                $resolved = true;
            }

            return $value;
        };
    }

    /**
     * Lazily-built, memoized data groups for the corporate page. Each entry is
     * a closure; nothing is queried until a prop that needs it is resolved.
     *
     * @return array<string, \Closure>
     */
    private function corporatePageContext(Character $character, Corporation $corp): array
    {
        // ── core: member/subsidiary/parent-trust relations + rank cache ──
        $core = self::memoOnce(function () use ($corp) {
            $memberLoader = fn($q) => $q->select(
                'id',
                'display_name',
                'corporation_id',
                'corporation_position',
                'corporation_reports_to_id',
                'career_id',
                'career_rank',
                'career_xp',
                'health',
                'deleted_at',
                'custom_avatar_url',
                'gender'
            )->orderByDesc('career_xp');

            $subsidiarySelect = fn($q) => $q->select(
                'id',
                'name',
                'home_city_id',
                'founder_id',
                'ceo_id',
                'parent_trust_id',
                'is_holding_company',
                'image_url',
                'slush_fund',
                'cash_reserves',
            )->orderBy('name');

            // Build the full relation map in one pass so Laravel executes
            // exactly one query per relation regardless of which branch applies.
            // (Owned properties are loaded by the properties group below.)
            $relations = [
                'members' => $memberLoader,
                'ceo:id,display_name,career_rank',
                'city:id,name,slug',
            ];

            if ($corp->parent_trust_id) {
                $relations['parentTrust:id,name,image_url,home_city_id,is_holding_company,parent_trust_id'] = null;
                $relations['parentTrust.members'] = $memberLoader;
                $relations['parentTrust.activeSubsidiaries'] = $subsidiarySelect;
                $relations['parentTrust.activeSubsidiaries.members'] = fn($query) => $memberLoader($query)
                    ->where('corporation_id', '!=', $corp->id);
                $relations['parentTrust.activeSubsidiaries.ceo'] = function ($query) use ($corp) {
                    $query->select('id', 'display_name', 'career_rank');

                    if ($corp->ceo_id) {
                        $query->where('id', '!=', $corp->ceo_id);
                    }
                };
            }

            if ($corp->is_holding_company) {
                $relations['subsidiaries'] = $subsidiarySelect;
                $relations['subsidiaries.members'] = $memberLoader;
                $relations['subsidiaries.ceo:id,display_name,career_rank'] = null;
            }

            // Remove null-valued string keys (the colon-notation relations
            // that don't need closures) and re-pack so load() receives them
            // correctly — string keys without closures must be plain values.
            $eagerLoad = [];
            foreach ($relations as $key => $closure) {
                if (is_int($key)) {
                    $eagerLoad[] = $closure; // numeric key: value is the relation string
                } elseif ($closure === null) {
                    $eagerLoad[] = $key;    // string key with null: key is the relation
                } else {
                    $eagerLoad[$key] = $closure; // string key with closure
                }
            }

            $corp->load($eagerLoad);

            if ($corp->relationLoaded('city') && $corp->relationLoaded('subsidiaries')) {
                foreach ($corp->subsidiaries as $subsidiary) {
                    if ((int) $subsidiary->home_city_id === (int) $corp->home_city_id) {
                        $subsidiary->setRelation('city', $corp->city);
                    }
                }
            }

            if ($corp->parentTrust && $corp->relationLoaded('city')) {
                $corp->parentTrust->setRelation('city', $corp->city);
            }

            if (
                $corp->parentTrust?->relationLoaded('city')
                && $corp->parentTrust->relationLoaded('activeSubsidiaries')
            ) {
                foreach ($corp->parentTrust->activeSubsidiaries as $subsidiary) {
                    if ((int) $subsidiary->home_city_id === (int) $corp->parentTrust->home_city_id) {
                        $subsidiary->setRelation('city', $corp->parentTrust->city);
                    }
                }
            }

            if ($corp->parentTrust?->relationLoaded('activeSubsidiaries')) {
                $currentSubsidiary = $corp->parentTrust->activeSubsidiaries->firstWhere('id', $corp->id);

                if ($currentSubsidiary) {
                    foreach (['members', 'ceo', 'city'] as $relation) {
                        if ($corp->relationLoaded($relation)) {
                            $currentSubsidiary->setRelation($relation, $corp->getRelation($relation));
                        }
                    }
                }
            }

            // ── Pre-resolve all rank names in one query ────────────────────────
            // $mapMember calls $m->current_rank which hits CareerRank per member.
            // Instead, bulk-load all rank name/avatar combos and resolve from a map.
            $allMembers = collect($corp->members ?? []);
            if ($corp->is_holding_company) {
                foreach ($corp->subsidiaries ?? [] as $sub) {
                    $allMembers = $allMembers->merge($sub->members ?? []);
                }
            }
            if ($corp->parentTrust) {
                $allMembers = $allMembers->merge($corp->parentTrust->members ?? []);
                foreach ($corp->parentTrust->activeSubsidiaries ?? [] as $sub) {
                    $allMembers = $allMembers->merge($sub->members ?? []);
                }
            }
            $careerIds = $allMembers->pluck('career_id')->unique()->filter()->values()->all();
            $rankLevels = $allMembers->pluck('career_rank')->unique()->filter()->values()->all();
            $rankCache = \App\Models\CareerRank::bulkLoadForCharacters($careerIds, $rankLevels);
            // key: "{career_id}_{career_rank}" => ['rank_name' => ..., 'avatar_url' => ...]

            $mapMember = function (Character $m, Corporation $scopeCorp) use ($rankCache) {
                $rk = $rankCache["{$m->career_id}_{$m->career_rank}"] ?? null;
                return [
                    'id' => $m->id,
                    'name' => $m->display_name,
                    'avatarUrl' => $m->custom_avatar_url ?: ($rk['avatar_url'] ?? null),
                    'position' => (int) $scopeCorp->ceo_id === (int) $m->id
                        ? 'CEO'
                        : match ($m->corporation_position) {
                            Corporation::POSITION_DIRECTOR_OF_BOARD => 'BOARD_DIRECTOR',
                            default => strtoupper($m->corporation_position ?? 'member'),
                        },
                    'positionRaw' => $m->corporation_position,
                    'reportsToId' => $m->corporation_reports_to_id,
                    'reportsToName' => $scopeCorp->members->firstWhere('id', $m->corporation_reports_to_id)?->display_name,
                    'rank' => $rk['rank_name'] ?? 'Staff',
                    'careerRank' => $m->career_rank ?? 0,
                    'isCeo' => (int) $scopeCorp->ceo_id === (int) $m->id,
                    'isFounder' => (int) $scopeCorp->founder_id === (int) $m->id,
                ];
            };

            $mapOperatingCompany = function (Corporation $company) use ($mapMember) {
                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'imageUrl' => $company->image_url,
                    'city' => $company->city?->name ?? 'Unknown',
                    'ceo' => ['id' => $company->ceo_id, 'name' => $company->ceo?->display_name ?? 'Vacant'],
                    'members' => $company->members->map(fn($m) => $mapMember($m, $company)),
                ];
            };

            return ['mapMember' => $mapMember, 'mapOperatingCompany' => $mapOperatingCompany];
        });

        // Shared by the merger (successor list gate) and subsidiary-invite groups.
        $incomingSubsidiaryInvites = self::memoOnce(fn () => CorporationSubsidiaryInvite::pending()
            ->with([
                'holdingCompany:id,name',
                'targetCorporation:id,name',
                'requester:id,display_name',
            ])
            ->where('target_ceo_id', $character->id)
            ->orderByDesc('created_at')
            ->get());

        // ── merger ──
        $merger = self::memoOnce(function () use ($character, $corp, $core, $incomingSubsidiaryInvites) {
            $core();

            $canUseMerger = $corp->isCeo($character)
                && CorporationController::isPhaseOneOperatingCompany($corp)
                && !CorporationController::hasHoldingCompanyInCity((int) $corp->home_city_id);

            $eligibleSuccessors = ($canUseMerger || $incomingSubsidiaryInvites()->isNotEmpty())
                ? $corp->members
                    ->filter(fn($member) => CorporationController::isEligibleMergerSuccessor($corp, $member))
                    ->values()
                    ->map(fn($member) => [
                        'id' => $member->id,
                        'name' => $member->display_name,
                        'rank' => $member->current_rank?->rank_name ?? 'Staff',
                    ])
                : collect();

            $mergerTargets = $canUseMerger
                ? Corporation::query()
                    ->select('id', 'name', 'home_city_id', 'ceo_id', 'is_holding_company', 'parent_trust_id')
                    ->with(['city:id,name,slug', 'ceo:id,display_name'])
                    ->where('home_city_id', $corp->home_city_id)
                    ->where('id', '!=', $corp->id)
                    ->where('is_holding_company', false)
                    ->whereNull('parent_trust_id')
                    ->orderBy('name')
                    ->get()
                    ->filter(fn($candidate) => $candidate->ceo_id)
                    ->values()
                    ->map(fn($candidate) => [
                        'id' => $candidate->id,
                        'name' => $candidate->name,
                        'city' => $candidate->city?->name ?? 'Unknown',
                        'ceoName' => $candidate->ceo?->display_name ?? 'Vacant',
                    ])
                : collect();

            $mergerRequests = CorporationMergerRequest::pending()
                ->with([
                    'requester:id,display_name',
                    'target:id,display_name',
                    'requesterCorporation:id,name',
                    'targetCorporation:id,name',
                    'requesterSuccessor:id,display_name',
                ])
                ->where(function ($query) use ($character) {
                    $query->where('requester_id', $character->id)
                        ->orWhere('target_id', $character->id);
                })
                ->orderByDesc('created_at')
                ->get();

            return [
                'canPropose' => $canUseMerger && $mergerRequests->isEmpty(),
                'cost' => CorporationController::MERGER_COST,
                'targets' => $mergerTargets,
                'successors' => $eligibleSuccessors,
                'incoming' => $mergerRequests
                    ->filter(fn($mergerRequest) => (int) $mergerRequest->target_id === (int) $character->id)
                    ->values()
                    ->map(fn($mergerRequest) => [
                        'id' => $mergerRequest->id,
                        'holdingName' => $mergerRequest->holding_name,
                        'requesterName' => $mergerRequest->requester?->display_name,
                        'requesterCorporationName' => $mergerRequest->requesterCorporation?->name,
                        'targetCorporationName' => $mergerRequest->targetCorporation?->name,
                        'requesterSuccessorName' => $mergerRequest->requesterSuccessor?->display_name,
                        'expiresAt' => $mergerRequest->expires_at?->toIso8601String(),
                    ]),
                'outgoing' => $mergerRequests
                    ->filter(fn($mergerRequest) => (int) $mergerRequest->requester_id === (int) $character->id)
                    ->values()
                    ->map(fn($mergerRequest) => [
                        'id' => $mergerRequest->id,
                        'holdingName' => $mergerRequest->holding_name,
                        'targetName' => $mergerRequest->target?->display_name,
                        'targetCorporationName' => $mergerRequest->targetCorporation?->name,
                        'requesterSuccessorName' => $mergerRequest->requesterSuccessor?->display_name,
                        'expiresAt' => $mergerRequest->expires_at?->toIso8601String(),
                    ]),
            ];
        });

        // ── board actions + subsidiary invites ──
        $board = self::memoOnce(function () use ($character, $corp, $core, $incomingSubsidiaryInvites) {
            $core();

            $isHoldingBoardMember = $corp->isBoardMember($character);
            $boardPromotionTargets = collect();
            $pendingBoardPromotions = collect();
            $kickableSubsidiaries = collect();
            $subsidiaryInviteTargets = collect();
            $trustVote = null;
            $boardCapacity = $corp->is_holding_company ? $corp->boardCapacity() : 0;
            $boardCount = $corp->is_holding_company ? $corp->boardMemberCount() : 0;
            $pendingBoardIntakeCount = $corp->is_holding_company ? $corp->pendingBoardIntakeCount() : 0;
            $availableBoardSlots = $corp->is_holding_company
                ? max(0, $boardCapacity - $boardCount - $pendingBoardIntakeCount)
                : 0;
            $hasSubsidiaryCapacity = $corp->is_holding_company && $corp->hasSubsidiaryCapacity();

            if ($corp->is_holding_company) {
                $pendingBoardPromotions = CorporationBoardPromotion::pending()
                    ->with(['subsidiary:id,name', 'promotedCeo:id,display_name', 'successor:id,display_name'])
                    ->where('holding_company_id', $corp->id)
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(fn($promotion) => [
                        'id' => $promotion->id,
                        'subsidiaryName' => $promotion->subsidiary?->name,
                        'ceoName' => $promotion->promotedCeo?->display_name,
                        'successorName' => $promotion->successor?->display_name,
                    ]);

                $boardPromotionTargets = $corp->subsidiaries
                    ->map(function (Corporation $subsidiary) {
                        $ceo = $subsidiary->ceo;


                        $successors = $subsidiary->members
                            ->filter(fn($member) => CorporationController::isEligibleMergerSuccessor($subsidiary, $member))
                            ->values()
                            ->map(fn($member) => [
                                'id' => $member->id,
                                'name' => $member->display_name,
                            ]);

                        return [
                            'id' => $subsidiary->id,
                            'name' => $subsidiary->name,
                            'ceoName' => $ceo?->display_name ?? 'Vacant',
                            'ready' => $ceo,
                            'successors' => $successors,
                        ];
                    })
                    ->values();

                $kickableSubsidiaries = $corp->subsidiaries->count()
                    ? $corp->subsidiaries
                        ->map(fn(Corporation $subsidiary) => [
                            'id' => $subsidiary->id,
                            'name' => $subsidiary->name,
                            'ceoName' => $subsidiary->ceo?->display_name ?? 'Vacant',
                            'memberCount' => $subsidiary->members->count(),
                        ])
                        ->values()
                    : collect();

                $trustBoardMembers = $corp->trustVoteBoardMembers();
                $trustCandidates = $trustBoardMembers
                    ->filter(fn(Character $member) => $corp->isEligibleTrustVoter($member))
                    ->values()
                    ->map(fn(Character $member) => [
                        'id' => $member->id,
                        'name' => $member->display_name,
                        'avatarUrl' => $member->avatar_url,
                        'position' => $member->corporation_position === Corporation::POSITION_DIRECTOR_OF_BOARD
                            ? 'BOARD_DIRECTOR'
                            : strtoupper($member->corporation_position ?? 'member'),
                    ]);

                $activeTrustVote = CorporationTrustVote::pending()
                    ->with('ballots:id,trust_vote_id,voter_id,candidate_id')
                    ->where('holding_company_id', $corp->id)
                    ->first();

                $promotionPendingTrustVote = CorporationTrustVote::promotionPending()
                    ->with('winner:id,display_name')
                    ->where('holding_company_id', $corp->id)
                    ->first();

                $trustVoteBlocker = $corp->trustVoteReadinessBlocker($trustBoardMembers);
                $trustVoteBallots = $activeTrustVote?->ballots ?? collect();
                $trustVoteBallotsByVoter = $trustVoteBallots->keyBy('voter_id');
                $trustVoteTallies = $trustVoteBallots
                    ->groupBy('candidate_id')
                    ->map(fn($ballots) => $ballots->count());
                $trustCandidateIds = $trustCandidates
                    ->pluck('id')
                    ->map(fn($id) => (int) $id)
                    ->all();

                $trustVote = [
                    'visible' => (bool) $corp->is_holding_company,
                    'canStart' => !$activeTrustVote && !$promotionPendingTrustVote,
                    'blocker' => $trustVoteBlocker,
                    'boardCount' => $trustBoardMembers->count(),
                    'requiredVotes' => CorporationTrustVote::requiredVotesFor($trustBoardMembers->count()),
                    'boardMembers' => $trustBoardMembers->map(function (Character $member) use ($character, $trustCandidateIds, $trustVoteBallotsByVoter, $trustVoteTallies) {
                        $ballot = $trustVoteBallotsByVoter->get($member->id);

                        return [
                            'id' => $member->id,
                            'name' => $member->display_name,
                            'avatarUrl' => $member->avatar_url,
                            'position' => $member->corporation_position === Corporation::POSITION_DIRECTOR_OF_BOARD
                                ? 'BOARD_DIRECTOR'
                                : strtoupper($member->corporation_position ?? 'member'),
                            'isMe' => (int) $member->id === (int) $character->id,
                            'isCandidate' => in_array((int) $member->id, $trustCandidateIds, true),
                            'votes' => (int) ($trustVoteTallies[$member->id] ?? 0),
                            'hasVoted' => (bool) $ballot,
                        ];
                    })->values(),
                    'candidates' => $trustCandidates->map(fn(array $candidate) => [
                        ...$candidate,
                        'votes' => (int) ($trustVoteTallies[$candidate['id']] ?? 0),
                        'isMe' => (int) $candidate['id'] === (int) $character->id,
                    ]),
                    'active' => $activeTrustVote ? [
                        'id' => $activeTrustVote->id,
                        'ballotCount' => $trustVoteBallots->count(),
                    ] : null,
                    'promotionPending' => $promotionPendingTrustVote ? [
                        'winnerName' => $promotionPendingTrustVote->winner?->display_name,
                    ] : null,
                ];
            }

            if ($isHoldingBoardMember && $hasSubsidiaryCapacity) {
                $subsidiaryInviteTargets = Corporation::query()
                    ->select('id', 'name', 'home_city_id', 'ceo_id', 'is_holding_company', 'parent_trust_id')
                    ->with(['ceo:id,display_name'])
                    ->where('home_city_id', $corp->home_city_id)
                    ->where('is_holding_company', false)
                    ->whereNull('parent_trust_id')
                    ->whereNotNull('ceo_id')
                    ->orderBy('name')
                    ->get()
                    ->filter(fn(Corporation $candidate) => !CorporationController::hasPendingSubsidiaryInviteForCorporation($candidate->id))
                    ->values()
                    ->map(fn(Corporation $candidate) => [
                        'id' => $candidate->id,
                        'name' => $candidate->name,
                        'ceoName' => $candidate->ceo?->display_name ?? 'Vacant',
                    ]);
            }

            $outgoingSubsidiaryInvites = CorporationSubsidiaryInvite::pending()
                ->with(['targetCorporation:id,name', 'targetCeo:id,display_name'])
                ->where('requester_id', $character->id)
                ->where('holding_company_id', $corp->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn(CorporationSubsidiaryInvite $invite) => [
                    'id' => $invite->id,
                    'targetCorporationName' => $invite->targetCorporation?->name,
                    'targetCeoName' => $invite->targetCeo?->display_name,
                    'expiresAt' => $invite->expires_at?->toIso8601String(),
                ]);


            $subsidiaryInvites = [
                'canInvite' => $isHoldingBoardMember && $hasSubsidiaryCapacity,
                'targets' => $subsidiaryInviteTargets,
                'incoming' => $incomingSubsidiaryInvites()
                    ->map(fn(CorporationSubsidiaryInvite $invite) => [
                        'id' => $invite->id,
                        'holdingName' => $invite->holdingCompany?->name,
                        'requesterName' => $invite->requester?->display_name,
                        'targetCorporationName' => $invite->targetCorporation?->name,
                        'expiresAt' => $invite->expires_at?->toIso8601String(),
                    ])
                    ->values(),
                'outgoing' => $outgoingSubsidiaryInvites,
            ];

            return [
                'subsidiaryInvites' => $subsidiaryInvites,
                'boardActions' => [
                    'isBoardMember' => $isHoldingBoardMember,
                    'capacity' => $boardCapacity,
                    'boardCount' => $boardCount,
                    'availableSlots' => $availableBoardSlots,
                    'canPromote' => $isHoldingBoardMember,
                    'promotionTargets' => $boardPromotionTargets,
                    'pendingPromotions' => $pendingBoardPromotions,
                    'kickableSubsidiaries' => $kickableSubsidiaries,
                    'subsidiaryInvites' => $subsidiaryInvites,
                    'subsidiaryCount' => $corp->is_holding_company ? $corp->activeSubsidiaryCount() : 0,
                    'trustVote' => $trustVote,
                ],
            ];
        });

        // ── properties (owned + purchasable templates + tax) ──
        $properties = self::memoOnce(function () use ($corp) {
            $corp->load(['properties' => fn($q) => $q->owned()->orderBy('type')->orderBy('tier')]);

            $propertyTaxRate = CorporationController::propertyTaxRateFor($corp);
            $medicineImages = GameItem::query()
                ->whereIn('slug', array_keys(CorporationProperty::MEDICAL_PRODUCTS))
                ->pluck('image_url', 'slug')
                ->all();
            $ownedProperties = $corp->properties
                ->map(fn(CorporationProperty $property) => [
                    'id' => $property->id,
                    'type' => $property->type,
                    'tier' => $property->tier,
                    'name' => $property->name,
                    'imageUrl' => $property->image_url,
                    'price' => $property->price,
                    'dailyUpkeep' => $property->dailyUpkeepCost(),
                    'condition' => $property->condition,
                    'operational' => $property->isOperational(),
                    'data' => $property->publicData($medicineImages),
                ])
                ->values();

            $availablePurchaseIds = $corp->is_holding_company
                ? collect()
                : CorporationProperty::purchasableTemplatesFor($corp)->pluck('id')->map(fn($id) => (int) $id);

            $propertyTemplates = $corp->is_holding_company
                ? collect()
                : CorporationProperty::query()
                    ->templates()
                    ->orderByRaw("CASE WHEN type = 'hq' THEN 0 ELSE 1 END")
                    ->orderBy('tier')
                    ->orderBy('name')
                    ->get()
                    ->reject(function (CorporationProperty $template) use ($corp) {
                        return $corp->properties->contains(
                            fn(CorporationProperty $property) => $property->type === $template->type
                            && (int) $property->tier === (int) $template->tier
                        );
                    })
                    ->values()
                    ->map(function (CorporationProperty $template) use ($availablePurchaseIds, $propertyTaxRate) {
                        $quote = CorporationProperty::quoteFor($template, $propertyTaxRate);

                        return [
                            'id' => $template->id,
                            'type' => $template->type,
                            'tier' => $template->tier,
                            'name' => $template->name,
                            'imageUrl' => $template->image_url,
                            'condition' => $template->condition,
                            'price' => $quote['price'],
                            'dailyUpkeep' => $template->dailyUpkeepCost(),
                            'taxRate' => $quote['taxRate'],
                            'tax' => $quote['tax'],
                            'total' => $quote['total'],
                            'data' => $template->data ?? [],
                            'canPurchaseNow' => $availablePurchaseIds->contains((int) $template->id),
                        ];
                    })
                    ->values();

            return ['owned' => $ownedProperties, 'templates' => $propertyTemplates, 'taxRate' => $propertyTaxRate];
        });

        // ── holding context (parent trust board + sibling operating companies) ──
        $holdingContext = self::memoOnce(function () use ($corp, $core) {
            $mapMember = $core()['mapMember'];
            $mapOperatingCompany = $core()['mapOperatingCompany'];

            if (!$corp->parentTrust || $corp->is_holding_company) {
                return null;
            }

            $holding = $corp->parentTrust;

            return [
                'holdingCompany' => [
                    'id' => $holding->id,
                    'name' => $holding->name,
                    'imageUrl' => $holding->image_url,
                    'boardMembers' => $holding->members->map(fn($m) => $mapMember($m, $holding)),
                ],
                'operatingCompanies' => $holding->activeSubsidiaries->map($mapOperatingCompany),
            ];
        });

        return [
            'core' => $core,
            'merger' => $merger,
            'board' => $board,
            'properties' => $properties,
            'holdingContext' => $holdingContext,
        ];
    }





    public function banking()
    {
        return Inertia::render('Careers/Banking');
    }

    public function customs(Request $request)
    {
        // Cities are already available via the character's loaded relation.
        // Use a single pluck only for resolving other_city_id names.
        $character = $request->user()->character;
        $city = $character->city;
        $cityId = $character->home_city_id;
        $rank = $character->career_rank;

        DB::table('travel_logs')
            ->where('city_id', $cityId)
            ->where('created_at', '<', now()->subHours(12))
            ->delete();

        $allCityNames = \App\Models\City::pluck('name', 'id');
        $homeCityName = $city->name;




        $incomingRaw = DB::table('travel_logs')
            ->where('city_id', $cityId)
            ->where('direction', 'IN')
            ->where('character_id', '!=', $character->id)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->orderByDesc('created_at')
            ->limit(60)
            ->get();

        $incoming = $incomingRaw->map(function ($row) use ($allCityNames, $homeCityName) {
            $data = json_decode($row->snapshot_data ?? '{}', true) ?? [];
            $items = $data['items'] ?? [];

            $itemSummary = array_map(fn($i) => [
                'name' => $i['name'] ?? null,
                'image_url' => $i['image_url'] ?? null,
                'type' => $i['type'] ?? null,
            ], array_slice($items, 0, 16));

            $otherCityName = $allCityNames[$row->other_city_id] ?? 'Unknown';

            return [
                'id' => $row->id,
                'character_name' => $row->character_name,
                'gender' => $row->gender,
                'conviction_count' => (int) $row->conviction_count,
                'direction' => 'IN',
                'other_city_name' => $otherCityName,
                'from_city' => $otherCityName,
                'to_city' => $homeCityName,
                'was_searched' => (bool) $row->was_searched,
                'search_results' => $row->search_results ? json_decode($row->search_results, true) : null,
                'cash_on_hand' => (int) ($data['cash_on_hand'] ?? 0),
                'avatar_url' => $data['avatar_url'] ?? null,
                'items' => $itemSummary,
                'item_count' => count($items),
                'searchable' => !(bool) $row->was_searched,
                'time_diff' => \Carbon\Carbon::parse($row->created_at)->utc()->format('d/m/y H:i:s') . ' UTC',
            ];
        })->values()->all();




        $historyRaw = DB::table('travel_logs')
            ->where('city_id', $cityId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $history = $historyRaw->map(function ($row) use ($allCityNames, $homeCityName) {
            $otherCityName = $allCityNames[$row->other_city_id] ?? 'Unknown';
            return [
                'id' => $row->id,
                'character_name' => $row->character_name,
                'direction' => $row->direction,
                'from_city' => $row->direction === 'IN' ? $otherCityName : $homeCityName,
                'to_city' => $row->direction === 'IN' ? $homeCityName : $otherCityName,
                'was_searched' => (bool) $row->was_searched,
                'conviction_count' => (int) $row->conviction_count,
                'time_diff' => Carbon::parse($row->created_at)->utc()->format('d/m/y H:i:s') . ' UTC',
                'avatar_url' => (json_decode($row->snapshot_data ?? '{}', true) ?? [])['avatar_url'] ?? null,
            ];
        })->values()->all();


        $seized = DB::table('travel_logs')
            ->where('city_id', $cityId)
            ->where('searched_by_id', $character->id)
            ->sum(DB::raw("COALESCE((search_results->'seized_dirty_cash')::numeric, 0)"));

        $fined = DB::table('travel_logs')
            ->where('city_id', $cityId)
            ->where('searched_by_id', $character->id)
            ->sum(DB::raw("COALESCE((search_results->'fine_amount')::numeric, 0)"));

        return Inertia::render('Careers/Customs', [
            'rank' => $rank,
            'rank_label' => $character->current_rank?->rank_name ?? 'Customs Officer',
            'city_name' => $city->name,
            'city_slug' => $city->slug,
            'city_image' => $city->image_url,
            'logs' => $incoming,
            'history' => $history,
            'stats' => [
                'incoming' => $incomingRaw->count(),
                'outgoing' => $historyRaw->where('direction', 'OUT')->count(),
                'seized' => (int) $seized,
                'fined' => (int) $fined,
                'searched' => $incomingRaw->where('was_searched', true)->count(),
            ],
            'pendingMoveRequests' => $this->loadPendingMoveRequests($cityId),
            'moveAgentPayout' => Corporation::MOVE_HQ_AGENT_PAYOUT,
        ]);
    }

    /**
     * Pull all pending corp-relocation requests targeting this city out of
     * the per-city Redis index. Cross-checks each entry against the per-corp
     * key so requests whose per-corp TTL expired don't linger in the agent's
     * UI even if the city index hasn't refreshed yet.
     */
    private function loadPendingMoveRequests(int $cityId): array
    {
        $list = Cache::get(Corporation::moveRequestsCityKey($cityId), []);
        if (!is_array($list) || empty($list)) {
            return [];
        }

        $stale = [];
        $fresh = [];
        foreach ($list as $corpId => $entry) {
            if (!is_array($entry)) {
                $stale[] = (int) $corpId;
                continue;
            }
            if (!Cache::has(Corporation::moveRequestKey((int) $corpId))) {
                $stale[] = (int) $corpId;
                continue;
            }
            $fresh[] = $entry;
        }

        // Lazy-clean stale index entries — only writes if anything was dropped.
        foreach ($stale as $staleId) {
            Corporation::removeFromMoveCityIndex($cityId, $staleId);
        }

        return $fresh;
    }
    // ? it might seem odd to give a starting career this much power, unilateral power to seize dirty cash and items, but it's better to make all careers powerful rather than nerfing them all.
    public function customsSearch(Request $request)
    {
        $request->validate(['log_id' => 'required|integer']);
        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character, $request) {
                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

                if ($character->timers->next_action_at?->isFuture()) {
                    return back()->with('error', 'You need to wait before performing another action.');
                }
                if (!$character->isAlive() || $character->isHospitalized() || $character->isJailed()) {
                    return back()->with('error', 'You cannot work in your current state.');
                }

                $log = DB::table('travel_logs')
                    ->where('id', $request->log_id)
                    ->where('city_id', $character->home_city_id)
                    ->lockForUpdate()
                    ->first();

                if (!$log)
                    return back()->with('error', 'Travel log not found.');
                if (Carbon::parse($log->created_at)->addMinutes(15)->isPast())
                    return back()->with('error', 'This traveler has already cleared customs and left — you cannot search them anymore.');
                if ($log->was_searched)
                    return back()->with('error', 'This traveler has already been searched by another officer.');
                if ($log->direction !== 'IN')
                    return back()->with('error', 'You can only search people arriving into your city.');
                if ($log->character_id === $character->id)
                    return back()->with('error', 'You cannot search yourself.');

                $snapshot = json_decode($log->snapshot_data ?? '{}', true) ?? [];
                $dirtyCash = (int) ($snapshot['dirty_cash'] ?? 0);
                $convictions = (int) $log->conviction_count;


                $detectionPower = min(4, sqrt($character->career_xp / 5000));
                $roll = random_int(1, 100);
                $seizurePercent = match (true) {
                    $roll <= 5 + $detectionPower * 1.5 => 100,
                    $roll <= 15 + $detectionPower * 3 => 50,
                    $roll <= 35 + $detectionPower * 5 => 25,
                    $roll <= 65 + $detectionPower * 7 => 10,
                    default => 0,
                };


                $traveler = Character::where('id', $log->character_id)
                    ->where('city_id', $log->city_id)
                    ->lockForUpdate()
                    ->first();
                $actualSeized = 0;
                $actualFine = 0;
                $travelerCityName = $traveler?->city?->name ?? $character->city?->name ?? 'the city';

                if ($traveler && $traveler->isAlive()) {
                    $toSeize = (int) floor($dirtyCash * ($seizurePercent / 100));
                    if ($toSeize > 0) {
                        $actualSeized = min($toSeize, (int) $traveler->dirty_cash);
                        if ($actualSeized > 0) {
                            $traveler->decrement('dirty_cash', $actualSeized);
                            $payout = (int) floor($actualSeized * 0.015);
                            $character->increment('cash_on_hand', $payout);
                            \App\Models\CharacterHistory::addHistory($character, 'earned_career', $payout);
                        }
                    }


                    $fine = $actualSeized > 0 ? min((int) floor($actualSeized / 250), 20_000) : 0;
                    if ($fine > 0) {
                        $actualFine = min($fine, (int) $traveler->cash_on_hand);
                        if ($actualFine > 0) {
                            $traveler->decrement('cash_on_hand', $actualFine);
                            $activeTerm = MayorTerm::activeForCity($character->home_city_id);
                            $activeTerm?->addFunds($actualFine, 'fine');
                        }
                    }
                }

                if ($actualSeized > 0 || $actualFine > 0) {
                    \App\Models\City::decreaseCrimeRateById($character->home_city_id, 0.05);
                    \App\Models\CharacterHistory::addHistory($character, 'travelers_inspected');
                }


                DB::table('travel_logs')->where('id', $log->id)->update([
                    'was_searched' => true,
                    'searched_by_id' => $character->id,
                    'search_results' => json_encode([
                        'seized_dirty_cash' => $actualSeized,
                        'fine_amount' => $actualFine,
                        'conviction_count' => $convictions,
                        'searched_at' => now()->utc()->toIso8601String(),
                        'officer_name' => $character->display_name,
                        'seized_gadget_name' => null,
                        'seized_contraband_name' => null,
                    ]),
                ]);

                $character->addXp(min(150, max(10, (int) floor(
                    $convictions * 1.2 + $actualSeized / 10_000 + $actualFine / 500
                ))));

                $character->timers()->update([
                    'next_action_at' => now()->addSeconds(config('timers.action'))->getTimestamp(),
                ]);

                $seizedContrabandName = null;
                if ($character->career_rank >= 2 && $seizurePercent >= 10) {
                    $snapshotItems = collect($snapshot['items'] ?? []);
                    $corporateMedicineSlugs = array_keys(CorporationProperty::MEDICAL_PRODUCTS);

                    $snapshotContrabandNames = $snapshotItems
                        ->filter(fn($i) => ($i['type'] ?? '') === 'gadget' || in_array((string) ($i['slug'] ?? ''), $corporateMedicineSlugs, true))
                        ->pluck('name')
                        ->filter()
                        ->unique()
                        ->values();

                    $snapshotContrabandSlugs = $snapshotItems
                        ->pluck('slug')
                        ->filter()
                        ->unique()
                        ->values();

                    if (($snapshotContrabandNames->isNotEmpty() || $snapshotContrabandSlugs->isNotEmpty()) && $traveler && $traveler->isAlive()) {
                        $contrabandTemplateIds = GameItem::query()
                            ->where(function ($query) use ($snapshotContrabandNames, $snapshotContrabandSlugs, $corporateMedicineSlugs) {
                                $query->where(function ($q) use ($snapshotContrabandNames) {
                                    $q->where('type', 'gadget')
                                        ->whereIn('name', $snapshotContrabandNames->all());
                                })
                                    ->orWhereIn('slug', array_values(array_intersect($snapshotContrabandSlugs->all(), $corporateMedicineSlugs)));
                            })
                            ->pluck('id');

                        if ($contrabandTemplateIds->isNotEmpty()) {
                            $contraband = CharacterItem::where('character_id', $traveler->id)
                                ->onHand()
                                ->whereIn('game_item_id', $contrabandTemplateIds->all())
                                ->with('template:id,name,type,slug')
                                ->inRandomOrder()
                                ->lockForUpdate()
                                ->first();

                            if ($contraband) {
                                $seizedContrabandName = $contraband->template->name ?? 'Unknown Device';
                                $contraband->delete();
                                \App\Models\City::decreaseCrimeRateById($character->home_city_id, 0.05);
                                if ($actualSeized <= 0 && $actualFine <= 0) {
                                    \App\Models\CharacterHistory::addHistory($character, 'travelers_inspected');
                                }
                                Log::info('[Customs] Contraband seized.', [
                                    'officer' => $character->id,
                                    'traveler' => $traveler->id,
                                    'item' => $seizedContrabandName,
                                ]);



                                $existingResults = json_decode(
                                    DB::table('travel_logs')->where('id', $log->id)->value('search_results') ?? '{}',
                                    true
                                ) ?? [];
                                $existingResults['seized_gadget_name'] = $seizedContrabandName;
                                $existingResults['seized_contraband_name'] = $seizedContrabandName;
                                DB::table('travel_logs')
                                    ->where('id', $log->id)
                                    ->update(['search_results' => json_encode($existingResults)]);
                            }
                        }
                    }
                }


                $actionParts = array_filter([
                    $actualSeized > 0 ? '$' . number_format($actualSeized) . ' in undeclared cash. They seized it' : null,
                    $actualFine > 0 ? 'and fined you $' . number_format($actualFine) . '' : null,
                    $seizedContrabandName ? 'They also found your illegal ' . strtolower($seizedContrabandName) . ' and confiscated it.You\'re lucky they didn\'t arrest you as well' : null,
                ]);

                JournalService::custom($log->character_id, 'busted_at_customs', [
                    'message' => !empty($actionParts)
                        ? " {$travelerCityName} Customs Official {$character->display_name} searched you upon your arrival and discovered you had " . implode(' ', $actionParts) . '.'
                        : " {$travelerCityName} Customs Official {$character->display_name} searched you upon your arrival and found nothing to be concerned about and let you go.",
                ]);

                Log::info('[Customs] Search executed.', [
                    'officer' => $character->id,
                    'log_id' => $log->id,
                    'traveler' => $log->character_id,
                    'detection_power' => $detectionPower,
                    'seizure_percent' => $seizurePercent,
                    'actual_seized' => $actualSeized,
                    'actual_fine' => $actualFine,
                    'convictions' => $convictions,
                ]);


                $gadgetWarning = $seizedContrabandName
                    ? "You also discovered they were carrying a " . strtolower($seizedContrabandName) . " on them and confiscated it."
                    : null;

                if ($actualSeized > 0 || $actualFine > 0) {
                    $msg = "You busted {$log->character_name} trying to enter the city with $"
                        . number_format($actualSeized)
                        . ' - you seized it all, fined them $'
                        . number_format($actualFine)
                        . ' and got paid $'
                        . number_format((int) floor($actualSeized * 0.015))
                        . ' for your good work!';
                    return $gadgetWarning
                        ? back()->with(['success' => $msg, 'warning' => $gadgetWarning])
                        : back()->with('success', $msg);
                }

                if ($seizedContrabandName) {
                    return back()->with('success', "You searched {$log->character_name} and found their illegal " . strtolower($seizedContrabandName) . '. You confiscated it.');
                }

                $msg = "You searched {$log->character_name} thoroughly but could not find any illegal untaxed cash. Better luck next time.";
                return $gadgetWarning
                    ? back()->with(['error' => $msg, 'warning' => $gadgetWarning])
                    : back()->with('error', $msg);
            });

        } catch (\Throwable $e) {
            Log::error('[Customs] Search failed.', [
                'character_id' => $character->id,
                'log_id' => $request->log_id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Search failed. Please try again.');
        }
    }

    /**
     * Customs officer (rank 2+) in the destination city approves a pending
     * corp relocation request. Flips corp.home_city_id, pays the agent
     * MOVE_HQ_AGENT_PAYOUT out of the escrowed fee and treats the remainder
     * as the relocation filing cost rather than mayor funds. Re-validates
     * corp eligibility under lock because cache state can drift.
     */
    public function customsApproveMove(Request $request)
    {
        $request->validate(['corporation_id' => 'required|integer']);
        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character, $request) {
                return $this->decideMoveRequest($character, (int) $request->corporation_id, true);
            });
        } catch (\Throwable $e) {
            Log::error('[Customs] Move approval failed.', [
                'character_id' => $character->id ?? null,
                'corporation_id' => $request->corporation_id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Could not process the relocation request.');
        }
    }

    /**
     * Customs officer (rank 2+) denies a pending corp relocation request.
     * Refunds the full escrowed fee back to the corp's cash_reserves,
     * still pays the agent for processing time + grants XP.
     */
    public function customsDenyMove(Request $request)
    {
        $request->validate(['corporation_id' => 'required|integer']);
        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character, $request) {
                return $this->decideMoveRequest($character, (int) $request->corporation_id, false);
            });
        } catch (\Throwable $e) {
            Log::error('[Customs] Move denial failed.', [
                'character_id' => $character->id ?? null,
                'corporation_id' => $request->corporation_id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Could not process the relocation request.');
        }
    }

    private function decideMoveRequest(?Character $character, int $corporationId, bool $approve)
    {
        $character = Character::where('id', $character?->id)->lockForUpdate()->first();
        if (!$character) {
            return back()->with('error', 'No active character found.');
        }

        if ($character->timers->next_action_at?->isFuture()) {
            return back()->with('error', 'You need to wait before performing another action.');
        }
        if (!$character->isAlive() || $character->isHospitalized() || $character->isJailed()) {
            return back()->with('error', 'You cannot work in your current state.');
        }
        if (strcasecmp($character->career?->code ?? '', 'customs') !== 0) {
            return back()->with('error', 'Only customs officers can process relocation requests.');
        }
        if ((int) $character->career_rank < 2) {
            return back()->with('error', 'You need at least rank 2 to process corporate relocation requests.');
        }

        $payload = Cache::get(Corporation::moveRequestKey($corporationId));
        if (!$payload || !is_array($payload)) {
            return back()->with('error', 'That relocation request has expired or no longer exists.');
        }

        if ((int) ($payload['to_city_id'] ?? 0) !== (int) $character->home_city_id) {
            return back()->with('error', 'You can only review relocations to your home city.');
        }

        $corp = Corporation::where('id', $corporationId)->lockForUpdate()->first();
        if (!$corp) {
            // Stale request for a corp that no longer exists — clean the index
            // so other agents don't keep seeing this row.
            Cache::forget(Corporation::moveRequestKey($corporationId));
            Corporation::removeFromMoveCityIndex((int) $character->home_city_id, $corporationId);
            return back()->with('error', 'The corporation no longer exists.');
        }

        if (!CorporationController::isPhaseOneOperatingCompany($corp)) {
            $this->refundAndClear($corp, $payload);
            return back()->with('error', 'Corporation is no longer eligible for relocation. Fee refunded.');
        }

        if (CorporationController::hasPendingMergerForCorporations([$corp->id])) {
            $this->refundAndClear($corp, $payload);
            return back()->with('error', 'Corporation now has a pending merger. Fee refunded.');
        }

        if (
            CorporationSubsidiaryInvite::pending()
                ->where('target_corporation_id', $corp->id)
                ->exists()
        ) {
            $this->refundAndClear($corp, $payload);
            return back()->with('error', 'Corporation now has a pending subsidiary invitation. Fee refunded.');
        }

        if ($corp->properties()->where('condition', CorporationProperty::CONDITION_PENDING)->exists()) {
            $this->refundAndClear($corp, $payload);
            return back()->with('error', 'Corporation has unfinished construction. Fee refunded.');
        }

        $fraudData = Cache::get(\App\Actions\InvestmentFraud::cacheKey($corp));
        if ($fraudData && now()->timestamp <= ($fraudData['expires_at'] ?? 0)) {
            $this->refundAndClear($corp, $payload);
            return back()->with('error', 'Corporation has a pending investment fraud action. Fee refunded.');
        }

        if ((int) $corp->home_city_id === (int) $character->home_city_id) {
            $this->refundAndClear($corp, $payload);
            return back()->with('error', 'Corporation is already based in this city. Fee refunded.');
        }

        $fee = (int) ($payload['fee_escrowed'] ?? Corporation::MOVE_HQ_FEE);
        $agentPayout = min($fee, Corporation::MOVE_HQ_AGENT_PAYOUT);

        if ($approve) {
            $fromCityId = (int) $corp->home_city_id;
            $toCityId = (int) $character->home_city_id;
            $corp->update(['home_city_id' => $toCityId]);

            if ($corp->ceo_id) {
                JournalService::custom((int) $corp->ceo_id, 'corporation_move_completed', [
                    'corporation_name' => $corp->name,
                    'from_city_id' => $fromCityId,
                    'from_city_name' => $payload['from_city_name'] ?? 'Unknown',
                    'to_city_id' => $toCityId,
                    'to_city_name' => $payload['to_city_name'] ?? 'Unknown',
                    'agent_name' => $character->display_name,
                ]);
            }

            $message = " You decided to approve {$corp->name}'s Headquarters relocation to your city. You earned $" . number_format($agentPayout) . ' as payment.';
        } else {
            // Denial — full refund to the corp; agent still gets paid for review time.
            $corp->increment('cash_reserves', $fee);

            if ($corp->ceo_id) {
                JournalService::custom((int) $corp->ceo_id, 'corporation_move_denied', [
                    'corporation_name' => $corp->name,
                    'from_city_name' => $payload['from_city_name'] ?? 'Unknown',
                    'to_city_name' => $payload['to_city_name'] ?? 'Unknown',
                    'agent_name' => $character->display_name,
                    'refund' => $fee,
                ]);
            }

            $message = "Denied {$corp->name}'s relocation. \$" . number_format($fee)
                . ' refunded. You earned $' . number_format($agentPayout) . ' for processing.';
        }

        $character->increment('cash_on_hand', $agentPayout);
        \App\Models\CharacterHistory::addHistory($character, 'earned_career', $agentPayout);
        $character->addXp(50);
        $character->timers()->update([
            'next_action_at' => now()->addSeconds(config('timers.action'))->getTimestamp(),
        ]);

        Cache::forget(Corporation::moveRequestKey($corporationId));
        Corporation::removeFromMoveCityIndex((int) $character->home_city_id, $corporationId);

        Log::info('[Customs] Move request decided.', [
            'agent_id' => $character->id,
            'corporation_id' => $corp->id,
            'outcome' => $approve ? 'APPROVED' : 'DENIED',
            'fee' => $fee,
            'agent_payout' => $agentPayout,
        ]);

        return back()->with('success', $message);
    }

    private function refundAndClear(Corporation $corp, array $payload): void
    {
        $fee = (int) ($payload['fee_escrowed'] ?? Corporation::MOVE_HQ_FEE);
        if ($fee > 0) {
            $corp->increment('cash_reserves', $fee);
        }
        Cache::forget(Corporation::moveRequestKey((int) $corp->id));
        Corporation::removeFromMoveCityIndex((int) ($payload['to_city_id'] ?? 0), (int) $corp->id);
    }

    public function politics(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();
        $city = $character?->city;

        if (!$city || $city->mayor_id !== $character->id) {
            return redirect()->route('dashboard')->with('error', 'You are not the current mayor.');
        }

        $term = MayorTerm::activeForCity($city->id);
        if (!$term) {
            return redirect()->route('dashboard')->with('error', 'Your mayoral term could not be found.');
        }

        $term = MayorService::tick($term, $city);
        if ($term === null) {
            return redirect()->route('dashboard')->with('error', 'Your term has ended.');
        }

        $policies = $term->getPolicies();
        $activeBond = $term->getActiveBond();

        $policeCareer = \App\Models\Career::findByCode('police');
        $policeCareerId = $policeCareer?->id;

        $activeCases = CrimeRecord::where('city_id', $city->id)
            ->whereNotIn('status', [
                CrimeRecord::STATUS_SENTENCED,
                CrimeRecord::STATUS_ACQUITTED,
                CrimeRecord::STATUS_SUPPRESSED,
                CrimeRecord::STATUS_CLOSED,
            ])
            ->latest()
            ->limit(50)
            ->get(['id', 'type', 'severity', 'status'])
            ->map(fn($r) => [
                'value' => (string) $r->id,
                'label' => '#' . $r->id . ' — ' . $r->typeLabel() . ' (' . ucfirst($r->severity) . ', ' . ucfirst($r->status) . ')',
            ])
            ->values()
            ->all();

        $pardonTargetIds = CrimeRecord::where('city_id', $city->id)
            ->whereIn('status', [CrimeRecord::STATUS_SENTENCED, CrimeRecord::STATUS_APPEALED, CrimeRecord::STATUS_CONVICTED])
            ->get(['character_id', 'data'])
            ->flatMap(function (CrimeRecord $record) {
                $ids = [];
                if ($record->character_id) {
                    $ids[] = (int) $record->character_id;
                }

                foreach (($record->data['participants'] ?? []) as $participantId) {
                    $ids[] = (int) $participantId;
                }

                return $ids;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        $pardonTargets = Character::whereIn('id', $pardonTargetIds)
            ->where('city_id', $city->id)
            ->alive()
            ->select(['id', 'display_name'])
            ->orderBy('display_name')
            ->limit(50)
            ->get()
            ->map(fn($c) => ['value' => (string) $c->id, 'label' => $c->display_name])
            ->values()
            ->all();

        $policeOfficers = $policeCareerId
            ? Character::where('home_city_id', $city->id)
                ->where('career_id', $policeCareerId)
                ->whereBetween('career_rank', [1, 3])
                ->alive()
                ->select(['id', 'display_name', 'career_rank'])
                ->orderByDesc('career_rank')
                ->orderBy('display_name')
                ->limit(30)
                ->get()
                ->map(fn($c) => [
                    'value' => (string) $c->id,
                    'label' => $c->display_name . ' (Rank ' . $c->career_rank . ')',
                ])
                ->values()
                ->all()
            : [];

        $commissionerList = $policeCareerId
            ? Character::where('home_city_id', $city->id)
                ->where('career_id', $policeCareerId)
                ->where('career_rank', 4)
                ->alive()
                ->select(['id', 'display_name'])
                ->limit(1)
                ->get()
                ->map(fn($c) => ['value' => (string) $c->id, 'label' => $c->display_name . ' (Commissioner)'])
                ->values()
                ->all()
            : [];

        return Inertia::render('Careers/MayoralChambers', [
            'city' => [
                'name' => $city->name,
                'slug' => $city->slug,
                'image_url' => $city->image_url,
            ],
            'term' => [
                'id' => $term->id,
                'period' => $term->period,
                'city_funds' => $term->city_funds,
                'assembly_score' => $term->assembly_score,
                'budget_law' => $term->budget_law,
                'budget_corp_reg' => $term->budget_corp_reg,
                'budget_services' => $term->budget_services,
                'budget_bonds' => $term->budget_bonds,
                'income_tax_rate' => $policies['income_tax_rate'],
                'corporate_tax_rate' => $policies['corporate_tax_rate'],
                'corp_regulation_active' => $policies['corp_regulation_active'],
                'bonds_active' => $policies['bonds_active'],
                'death_sentence_active' => $policies['death_sentence_active'],
                'suppressions_used' => $term->suppressionCount(),
                'has_active_bond' => $term->hasActiveBond(),
                'bond_yield' => $activeBond ? $activeBond['yield_pct'] : null,
                'bond_matures_at' => $activeBond ? $activeBond['matures_at'] : null,
                'pardon_cooldown_passed' => $term->pardonCooldownPassed(),
                'audit_in_progress' => $term->auditInProgress(),
                'ledger' => $term->ledger ?? [],
                'period_income' => (function () use ($term) {
                    $inc = $term->actions_log['period_income'] ?? [];
                    return [
                        'tax' => (int) ($inc['tax'] ?? 0),
                        'fine' => (int) ($inc['fine'] ?? 0),
                        'audit' => (int) ($inc['audit'] ?? 0),
                        'bond' => (int) ($inc['bond'] ?? 0),
                    ];
                })(),
            ],
            'mayor_name' => $character->display_name,
            'crime_rate' => (float) $city->crime_rate,
            'pickerData' => [
                'active_cases' => $activeCases,
                'pardon_targets' => $pardonTargets,
                'police_officers' => $policeOfficers,
                'commissioner' => $commissionerList,
            ],
        ]);
    }





    public function promote(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return redirect()->route('dashboard')->with('error', 'Character not found.');
        }

        if ($request->isMethod('get')) {
            $blocker = $character->getPromotionChecker();
            if ($blocker) {
                if ($character->career?->code === 'corporation' && str_contains($blocker, 'boardroom')) {
                    return redirect()->route('career.corporate')->with('error', $blocker);
                }

                return redirect()->route('dashboard')->with('error', $blocker);
            }
        }

        if ($character->isHospitalized() || !$character->isAlive() || $character->isJailed()) {
            return back()->with('error', 'You cannot be promoted while hospitalized or in jail.');
        }

        $targetRank = $character->career_rank + 1;
        $promotion = \App\Models\CareerPromotion::findForPromotion(
            $character->career_id,
            $targetRank,
        );


        if ($request->isMethod('get')) {
            return Inertia::render('Careers/Promotion', [
                'currentRank' => $character->current_rank?->rank_name ?? 'Officer',
                'nextRank' => $character->nextRank?->rank_name ?? 'Next Rank',
                'career' => $character->career?->name ?? 'Unknown',
                'promotion' => $promotion ? [
                    'scenario' => $promotion->scenario,
                    'option_1' => $promotion->option_1,
                    'option_2' => $promotion->option_2,
                ] : null,
            ]);
        }


        $request->validate([
            'option' => 'required|integer|in:0,1,2',
        ]);

        $option = (int) $request->option;
        if ($promotion && !in_array($option, [1, 2], true)) {
            return back()->with('error', 'Please choose an option before confirming your promotion.');
        }

        try {
            DB::transaction(function () use ($character, $promotion, $option) {
                $previousCareerRank = (int) ($character->career_rank ?? 0);
                $isCorporationCareer = strcasecmp($character->career?->code ?? '', 'corporation') === 0;

                $character->promote();

                if ($isCorporationCareer && $previousCareerRank === 4 && (int) $character->career_rank === 5) {
                    CorporationController::completeRankFiveCorporationHandoff($character);
                }

                if ($isCorporationCareer && $previousCareerRank === 5 && (int) $character->career_rank === 6) {
                    CorporationController::updateBoardPositionAfterPromotion($character);
                }

                if ($isCorporationCareer && $previousCareerRank === 6 && (int) $character->career_rank === 7) {
                    CorporationController::completeDirectorOfBoardPromotion($character);
                }

                $character->refresh();
                $character->resetRankMemo();

                if ($promotion && $option > 0) {
                    $reward = $promotion->rewardsFor($option);

                    if (!empty($reward['cash'])) {
                        $character->addCash((int) $reward['cash']);
                    }
                    if (!empty($reward['dirty_cash'])) {
                        $character->addCash((int) $reward['dirty_cash'], asDirtyCash: true);
                    }
                    if (!empty($reward['intelligence'])) {
                        $character->stats->addIntelligence((int) $reward['intelligence']);
                    }
                    if (!empty($reward['luck'])) {
                        $character->stats->addLuck((int) $reward['luck']);
                    }
                    if (!empty($reward['offense'])) {
                        $character->stats->addOffense((int) $reward['offense']);
                    }
                    if (!empty($reward['defense'])) {
                        $character->stats->addDefense((int) $reward['defense']);
                    }
                    if (!empty($reward['item_slug'])) {
                        $item = \App\Models\GameItem::where('slug', $reward['item_slug'])->first();
                        if ($item) {


                            $alreadyOwned = \App\Models\CharacterItem::where('character_id', $character->id)
                                ->where('game_item_id', $item->id)
                                ->where(fn($q) => $q->onHand()->orWhere(fn($q) => $q->inSafe()))
                                ->exists();

                            if (!$alreadyOwned) {
                                \App\Models\CharacterItem::create([
                                    'character_id' => $character->id,
                                    'game_item_id' => $item->id,
                                    'location' => 'on_hand',
                                    'durability_remaining' => $item->durability ?? null,
                                ]);
                            }
                        }
                    }
                }
            });

            Log::info('[Career] Character promoted.', [
                'character_id' => $character->id,
                'new_rank' => $character->career_rank,
                'option' => $option,
            ]);



            $newRankName = $character->current_rank?->rank_name ?? 'your new rank';
            $consequenceText = ($promotion && $option > 0)
                ? $promotion->consequenceFor($option)
                : "Congratulations on your promotion to {$newRankName}.";






            if ($character->can_promote) {
                return redirect()->route('career.promote')->with('success', $consequenceText);
            }

            return redirect()->route('dashboard')->with('success', $consequenceText);

        } catch (\Throwable $e) {
            Log::error('[Career] Promotion failed.', [
                'character_id' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', $e->getMessage());
        }
    }
}
