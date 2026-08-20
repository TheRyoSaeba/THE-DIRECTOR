<?php

namespace App\Http\Controllers;

use App\Events\CityEvents\CityEventDispatcher;
use App\Models\Business;
use App\Models\Career;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\City;
use App\Models\CorporationProperty;
use App\Services\JournalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

// ? Obviously driving from new york to tokyo is stupid,  and so is the rest
    // ? but i'm not going to hardcode or go through the trouble of filtering by vehicle or whatever and doing this and that
    //? this is a game by a solo dev.
    //! It's called verisimilitude!

class TransitHubController extends CityController
{
    public const TICKET_PRICE_MIN = 500;
    public const TICKET_PRICE_MAX = 5000;
    private const CORPORATE_MEDICINE_SEIZURE_CHANCE = 33;

    private const CITY_COORDS = [
        'tokyo' => [
            'lat' => 35.6762,
            'lng' => 139.6503,
            'image' => 'https://images.thedirector.app/tokyo.jpeg',
        ],
        'seoul' => [
            'lat' => 37.5665,
            'lng' => 126.978,
            'image' => 'https://images.thedirector.app/seoul.jpeg',
        ],
        'new-york' => [
            'lat' => 40.7128,
            'lng' => -74.006,
            'image' => 'https://images.thedirector.app/newyork.jpeg',
        ],
    ];

    private const VEHICLE_REDUCTIONS = [
        'private-jet' => 0.65,
        'byd-ev' => 0.55,
        'gojeon-exodus' => 0.35,
        'toyota-suv' => 0.20,
    ];

    private function getHub(City $city): ?Business
    {
        return Business::forCity($city, 'transit-hub');
    }
    private function getTicketPrice(City $fromCity, City $destCity): int
    {
        $destHub = $this->getHub($destCity);
        if (!$destHub) {
            return self::TICKET_PRICE_MIN;
        }

        $price = (int) data_get($destHub->data, "ticket_prices.{$fromCity->slug}", self::TICKET_PRICE_MIN);
        return max(self::TICKET_PRICE_MIN, min(self::TICKET_PRICE_MAX, $price));
    }

    /**
     * Pull a ticket price out of an already-loaded hub. Used by index()
     * after we batch-loaded all hubs in one query, so per-destination
     * cards don't trigger N+1 Business::forCity() lookups.
     */
    private function priceFromHub(?Business $destHub, string $fromSlug): int
    {
        if (!$destHub) {
            return self::TICKET_PRICE_MIN;
        }
        $price = (int) data_get($destHub->data, "ticket_prices.{$fromSlug}", self::TICKET_PRICE_MIN);
        return max(self::TICKET_PRICE_MIN, min(self::TICKET_PRICE_MAX, $price));
    }

    public function index(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        // Eager load `items.template` once so getEquippedVehicle() reads
        // from memory and the destination map doesn't re-query.
        $character->loadMissing('items.template');

        // One City query reused for both the hub batch-load and the
        // destination map. Avoids the previous index() pattern that
        // round-tripped to cities twice.
        $allCities = City::all();
        $otherCities = $allCities->where('id', '!=', $city->id);

        // One query for ALL transit hubs (current city + every destination)
        // instead of one query per destination card. Keyed by city_id for
        // O(1) lookup. Eager loads owner so the hero card doesn't fire
        // a follow-up query.
        $hubsByCity = Business::query()
            ->where('code', 'transit-hub')
            ->whereIn('city_id', $allCities->pluck('id'))
            ->with('owner:id,display_name,custom_avatar_url,career_id,career_rank')
            ->get()
            ->keyBy('city_id');

        $hub = $hubsByCity->get($city->id);
        $isOwner = $hub && $hub->owner_id === $character->id;

        // Customs Director is the in-fiction service leader for the airport —
        // the senior badge running the bureau in this city. Mirrors how Bank
        // surfaces its Banking director, Hospital its Healthcare director.
        $director = $city->getServiceLeader('customs');

        $equippedVehicle = $character->getEquippedVehicle();

        if (
            $equippedVehicle
            && $equippedVehicle->durability_remaining !== null
            && $equippedVehicle->durability_remaining <= 0
        ) {
            $equippedVehicle->update(['is_equipped' => false, 'equipped_slot' => null]);
            $equippedVehicle = null;
        }

        $destinations = $otherCities->values()->map(function ($destCity) use ($city, $equippedVehicle, $hubsByCity) {
            $distance = $this->calculateDistance(
                self::CITY_COORDS[$city->slug] ?? null,
                self::CITY_COORDS[$destCity->slug] ?? null,
            );

            $flightTime = $this->calculateBaseTime($distance, $destCity->slug);
            $flightCost = $this->priceFromHub($hubsByCity->get($destCity->id), $city->slug);

            return [
                'id' => $destCity->id,
                'name' => $destCity->name,
                'slug' => $destCity->slug,
                'description' => $destCity->description,
                'image_url' => self::CITY_COORDS[$destCity->slug]['image'] ?? $destCity->image_url,
                'distance' => round($distance),
                'flight_time' => $flightTime,
                'flight_cost' => $flightCost,

            ];
        });
        $ownerSettings = null;
        if ($isOwner) {
            $incomingPrices = [];
            foreach ($otherCities as $incoming) {
                $incomingPrices[] = [
                    'city_slug' => $incoming->slug,
                    'city_name' => $incoming->name,
                    'price' => (int) data_get($hub->data, "ticket_prices.{$incoming->slug}", self::TICKET_PRICE_MIN),
                ];
            }
            $ownerSettings = [
                'incoming_prices' => $incomingPrices,
                'min_price' => self::TICKET_PRICE_MIN,
                'max_price' => self::TICKET_PRICE_MAX,
            ];
        }
        $training = $character->getDegree('Customs');
        $requiredCycles = config('timers.customs_training_cycles', 10);
        $customsCareerId = Career::findByCode('customs')?->id;

        $academy = [
            'isCustoms' => $character->career_id === $customsCareerId,
            'requiredCycles' => $requiredCycles,
            'training' => $training ? [
                'enrolled' => true,
                'cycles' => (int) ($training['cycles'] ?? 0),
                'completed' => !empty($training['completed_at']),
                'cityId' => (int) ($training['city_id'] ?? 0),
                'nextStudyAt' => $character->timers?->next_study_at?->getTimestamp() ?? null,
            ] : null,
        ];

        return Inertia::render('City/TransitHub', [
            'cityName' => $city->name,
            'citySlug' => $city->slug,
            'cityImage' => self::CITY_COORDS[$city->slug]['image'] ?? $city->image_url,
            'hubName' => $hub?->name ?? "{$city->name} Transit Hub",
            'hubImage' => $hub?->image_url,
            'isOwner' => $isOwner,
            'ownerName' => $hub?->owner?->display_name ?? 'State Owned',
            'ownerAvatar' => $hub?->owner?->avatar_url,
            'director' => $director,
            'ownerSettings' => $ownerSettings,
            'destinations' => $destinations,
            'equippedVehicle' => $equippedVehicle ? [
                'name' => $equippedVehicle->template->name,
                'image_url' => $equippedVehicle->template->image_url,
                'slug' => $equippedVehicle->template->slug,
            ] : null,
            'academy' => $academy,

        ]);
    }

    public function travel(Request $request, City $city, City $destination)
    {
        [$character, $currentCity] = $this->getContext($request, $city);

        $method = $request->input('method', 'flight');

        if ($character->isHospitalized() || $character->isJailed()) {
            return back()->with('error', 'You cannot travel while jailed or hospitalized.');
        }

        if (!$character->isAlive()) {
            return back()->with('error', 'You are dead.');
        }

        if ($character->city_id === $destination->id) {
            return back()->with('error', 'You are already in this city.');
        }

        if ($character->timers?->next_travel_at?->isFuture()) {
            return back()->with('error', 'You cannot travel yet.');
        }

        DB::beginTransaction();
        try {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

            $distance = $this->calculateDistance(
                self::CITY_COORDS[$currentCity->slug] ?? null,
                self::CITY_COORDS[$destination->slug] ?? null,
            );

            $flightTime = $this->calculateBaseTime($distance, $destination->slug);
            $travelTime = $flightTime;
            $travelCost = 0;
            $equippedVehicle = null;

            if ($method === 'vehicle') {
                $equippedVehicle = $character
                    ->items()
                    ->whereRaw('"is_equipped" IS TRUE')
                    ->whereHas('template', fn($q) => $q->where('type', 'vehicle'))
                    ->with('template')
                    ->first();

                if (!$equippedVehicle) {
                    DB::rollBack();
                    return back()->with('error', 'No vehicle equipped for this transit method.');
                }

                if (
                    $equippedVehicle->durability_remaining !== null
                    && $equippedVehicle->durability_remaining <= 0
                ) {
                    DB::rollBack();
                    return back()->with('error', 'Your vehicle is totaled. Repair it first.');
                }

                $reduction = self::VEHICLE_REDUCTIONS[$equippedVehicle->template->slug] ?? 0.05;
                $travelTime = (int) ($flightTime * (1 - $reduction));
            } else {
                // Flight cost set by the destination hub's owner.
                $travelCost = $this->getTicketPrice($currentCity, $destination);
            }


            if ($method !== 'vehicle' && $travelCost > 0) {
                if (!$character->removeCash($travelCost, false)) {
                    DB::rollBack();
                    return back()->with('error', 'Insufficient funds for travel.');
                }

                // Fares route to the DESTINATION's hub (the one that set the price).
                $destinationHub = $this->getHub($destination);
                $destinationHub?->addBalance($travelCost);
            }

            $unlucky = $method !== 'vehicle' && rand(1, 100) <= 3;

            if ($unlucky) {
                $character->timers()->update([
                    'next_travel_at' => now()->addSeconds($travelTime)->getTimestamp(),
                ]);
                $character->touch();

                Log::info('[Travel] Unlucky flight (fare retained by hub).', [
                    'character_id' => $character->id,
                    'from' => $currentCity->slug,
                    'to' => $destination->slug,
                    'fare' => $travelCost,
                ]);

                DB::commit();

                $msg = $method === 'flight'
                    ? "The Amadeus software bungled up your tickets and you couldn't fly!"
                    : "You couldn't get clearance after waiting all day and had to go back home.";

                return redirect()->route('dashboard')->with('error', $msg);
            }

            if (
                $method === 'vehicle'
                && $equippedVehicle
                && $equippedVehicle->durability_remaining !== null
            ) {
                $character->consumeDurability('vehicle');
            }

            $this->maybeSeizeCorporateMedicine($character, $currentCity, $destination);

            \App\Models\CustomsLog::logTravel($character, $currentCity->id, $destination->id);

            $character->city_id = $destination->id;
            $character->save();

            CityEventDispatcher::dispatch($character, 'travel');

            $character->timers()->update([
                'next_travel_at' => now()->addSeconds($travelTime)->getTimestamp(),
            ]);

            DB::commit();

            $message = "You have successfully traveled to {$destination->name}.";

            return redirect()->route('dashboard')->with('success', $message);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Travel] Travel failed.', [
                'character_id' => $character->id ?? null,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'The travel process failed.');
        }
    }


    public function updateSettings(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        $hub = $this->getHub($city);
        if (!$hub) {
            abort(404);
        }
        if ($hub->owner_id !== $character->id) {
            abort(403, 'You do not own this business.');
        }

        $validated = $request->validate([
            'ticket_prices' => ['required', 'array'],
            'ticket_prices.*' => ['integer', "min:" . self::TICKET_PRICE_MIN, "max:" . self::TICKET_PRICE_MAX],
        ]);

        // Only accept slugs of cities that actually exist (drops typos / spoofed keys).
        $validSlugs = City::where('id', '!=', $city->id)->pluck('slug')->all();
        $clean = collect($validated['ticket_prices'])
            ->only($validSlugs)
            ->map(fn($p) => max(self::TICKET_PRICE_MIN, min(self::TICKET_PRICE_MAX, (int) $p)))
            ->all();

        $hub->setSetting('ticket_prices', $clean);

        return back()->with('success', 'Ticket prices updated.');
    }

    public function enrollAcademy(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        $customsCareerId = Career::findByCode('customs')?->id;

        if ($customsCareerId && $character->career_id === $customsCareerId) {
            return back()->with('error', 'You are already a customs officer.');
        }

        if ($blocker = $this->getJoinBlocker($character)) {
            return back()->with('error', $blocker);
        }

        if ($character->getDegree('Customs')) {
            return back()->with('error', 'You are already enrolled in the Customs Academy.');
        }

        // Mutual exclusion with Police Academy. See PoliceHqController::enroll
        // for the rationale — both pseudo-degrees live in the same JSONB blob,
        // so we have to block the cross-case here too. Completed-but-not-
        // yet-graduated entries also block — finish what you started.
        if ($character->getDegree('police_academy')) {
            return back()->with('error', "You're already training for police, pick a lane!");
        }

        try {
            return DB::transaction(function () use ($character, $city) {
                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

                $character->enrollDegree('Customs', $city->id);

                return back()->with(
                    'success',
                    "You've enrolled at the {$city->name} Customs Academy. Train hard — the Bureau is watching."
                );
            });
        } catch (\Throwable $e) {
            Log::error('[TransitHub] Academy enrollment failed.', [
                'character' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Enrollment failed. Try again.');
        }
    }

    public function trainAcademy(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        try {
            return DB::transaction(function () use ($character, $city) {
                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

                if ($character->timers?->next_study_at?->isFuture()) {
                    return back()->with('error', 'You need to wait before your next training session.');
                }

                if (!$character->isAlive() || $character->isHospitalized() || $character->isJailed()) {
                    return back()->with('error', 'You cannot train while incapacitated or dead.');
                }

                if ($character->isMayor()) {
                    return back()->with('error', 'A sitting mayor cannot train at the academy.');
                }

                $training = $character->getDegree('customs');
                if (!$training || !empty($training['completed_at'])) {
                    return back()->with('error', 'You are not enrolled, or have already completed training.');
                }

                if ((int) ($training['city_id'] ?? 0) !== $city->id) {
                    return back()->with('error', 'You must train at the academy where you enrolled.');
                }

                $degrees = $character->degrees ?? [];
                if (!isset($degrees['customs'])) {
                    foreach ($degrees as $degreeCode => $degree) {
                        if (strtolower((string) $degreeCode) === 'customs') {
                            $degrees['customs'] = $degree;
                            unset($degrees[$degreeCode]);
                            break;
                        }
                    }
                }

                $degrees['customs']['cycles'] = (int) ($degrees['customs']['cycles'] ?? 0) + 1;

                $required = config('timers.customs_training_cycles', 10);
                $completed = $degrees['customs']['cycles'] >= $required;

                if ($completed) {
                    $degrees['customs']['completed_at'] = now()->toIso8601String();
                }

                $character->update(['degrees' => $degrees]);

                $character->timers()->update([
                    'next_study_at' => now()->addSeconds(config('timers.study'))->getTimestamp(),
                ]);

                if ($completed) {
                    return back()->with('success', 'Training complete. Take the oath when you are ready.');
                }

                $current = $degrees['customs']['cycles'];
                $percent = ($current / $required) * 100;

                $message = match (true) {
                    $percent >= 90 => 'Final days at the academy, you\'re almost done!',
                    $percent >= 50 => 'Solid progress. Your instructors approve.',
                    default => 'Another day of training down. Keep at it, cadet.',
                };

                return back()->with('success', $message);
            });
        } catch (\Throwable $e) {
            Log::error('[TransitHub] Academy training failed.', [
                'character' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Training failed. Try again.');
        }
    }

    public function graduateAcademy(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        try {
            return DB::transaction(function () use ($character, $city) {
                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

                $training = $character->getDegree('Customs');
                if (!$training || empty($training['completed_at'])) {
                    return back()->with('error', 'You have not completed your Customs Academy training.');
                }

                if ((int) ($training['city_id'] ?? 0) !== $city->id) {
                    return back()->with('error', 'You must take the oath at the academy where you trained.');
                }

                if ($character->isMayor()) {
                    return back()->with('error', 'A sitting mayor cannot take the customs oath.');
                }

                if ($character->corporation_id) {
                    return back()->with('error', 'You must leave your corporation before joining the Bureau.');
                }

                if ($character->career?->code === 'police') {
                    return back()->with('error', 'You cannot join Customs while serving in the Police. Step down first.');
                }

                if ($character->career?->code === 'law') {
                    return back()->with('error', 'You cannot join Customs while serving in the Judiciary. Step down first.');
                }

                if (!$character->startCareer('customs')) {
                    return back()->with('error', 'Could not start customs career.');
                }

                $character->quitDegree('Customs');

                Log::info('[TransitHub] Player graduated from customs academy.', [
                    'character' => $character->id,
                    'city' => $city->id,
                ]);

                return back()->with('success', 'Welcome to the Bureau, Officer.');
            });
        } catch (\Throwable $e) {
            Log::error('[TransitHub] Academy graduation failed.', [
                'character' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Graduation failed. Try again.');
        }
    }


    private function getJoinBlocker(Character $character): ?string
    {
        $degrees = $character->degrees ?? [];
        $hasAnyCompletedDegree = collect($degrees)->contains(
            fn($d, $code) => !in_array(strtolower((string) $code), ['customs', 'police_academy'], true)
            && !empty($d['completed_at'])
        );
        if (!$hasAnyCompletedDegree) {
            return 'You need at least one degree before enrolling at the Customs Academy.';
        }

        if (!$character->isInHomeCity()) {
            return 'You can only train at the Customs Academy in your home city.';
        }

        if ($character->corporation_id) {
            return 'You cannot enroll while in a corporation.';
        }

        if ($character->isMayor()) {
            return 'You cannot enroll mid-term as mayor.';
        }

        if ($character->isHospitalized() || $character->isJailed()) {
            return 'You cannot enroll while incapacitated.';
        }

        return null;
    }

    private function maybeSeizeCorporateMedicine(Character $character, City $fromCity, City $destination): array
    {
        $items = CharacterItem::query()
            ->where('character_id', $character->id)
            ->onHand()
            ->whereHas('template', fn($query) => $query->whereIn('slug', array_keys(CorporationProperty::MEDICAL_PRODUCTS)))
            ->with('template:id,name,slug')
            ->lockForUpdate()
            ->get();

        if ($items->isEmpty() || random_int(1, 100) > self::CORPORATE_MEDICINE_SEIZURE_CHANCE) {
            return [];
        }

        $names = $items
            ->map(fn(CharacterItem $item) => $item->template?->name)
            ->filter()
            ->unique()
            ->values()
            ->all();

        CharacterItem::query()
            ->whereIn('id', $items->pluck('id')->all())
            ->delete();

        JournalService::custom($character->id, 'busted_at_customs', [
            'message' => "Customs flagged your luggage between {$fromCity->name} and {$destination->name}, found the untested medical supplies you were trying to smuggle, and confiscated it.",
        ]);

        Log::info('[Travel] Corporate medicine seized during transit.', [
            'character_id' => $character->id,
            'from' => $fromCity->slug,
            'to' => $destination->slug,
            'items' => $names,
        ]);

        return $names;
    }


    private function calculateDistance(?array $from, ?array $to): float
    {
        if (!$from || !$to) {
            return 5000.0;
        }

        $earthRadius = 6371;

        $dLat = deg2rad($to['lat'] - $from['lat']);
        $dLon = deg2rad($to['lng'] - $from['lng']);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($from['lat'])) * cos(deg2rad($to['lat']))
            * sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function calculateBaseTime(float $distance, string $slug): int
    {
        $baseSeconds = config('timers.travel', 900);

        $cityOffset = match ($slug) {
            'seoul' => 600,
            'tokyo' => 300,
            'new-york' => 1200,
            default => 0,
        };

        $scaling = (int) (($distance / 1000) * 360);

        return (int) min(3600, $baseSeconds + $cityOffset + $scaling);
    }
}
