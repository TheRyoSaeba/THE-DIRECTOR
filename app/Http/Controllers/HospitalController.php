<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Career;
use App\Models\CareerRank;
use App\Models\Character;
use App\Models\City;
use App\Models\Leaderboard;
use App\Services\JournalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

//! this produces 10 duplicate for timers and cities
class HospitalController extends CityController
{
    private function getHospital(City $city): ?Business
    {
        return Business::forCity($city, 'hospital');
    }

    private function healthcareCareerId(): ?int
    {
        return Career::findByCode('healthcare')?->id;
    }


    private function purge(?Business $hospital): void
    {
        if (!$hospital)
            return;




        $cooldowns = (array) $hospital->getSetting('surgery_cooldowns', []);
        if ($cooldowns) {
            $active = array_filter($cooldowns, fn($ts) => \Carbon\Carbon::parse($ts)->isFuture());
            if (count($active) !== count($cooldowns)) {
                $hospital->setSetting('surgery_cooldowns', $active);
            }


        }

        foreach (['surgery_queue', 'gender_queue'] as $key) {
            $queue = collect($hospital->getSetting($key, []));
            if ($queue->isEmpty())
                continue;

            $ids = $queue->pluck('id')->filter()->unique();
            $alive = DB::table('characters')
                ->whereNull('deleted_at')
                ->whereIn('id', $ids)
                ->pluck('id')
                ->flip();

            $filtered = $queue->filter(fn($e) => isset($alive[$e['id'] ?? null]))->values()->all();

            if (count($filtered) !== count($queue)) {
                $hospital->setSetting($key, $filtered);
            }
        }
    }





    public function index(Request $request, City $city): \Inertia\Response
    {
        [$character, $city] = $this->getContext($request, $city);

        $hospital = $this->getHospital($city);
        if ($hospital) {
            $hospital->loadMissing('owner');
        }
        $this->purge($hospital);

        $careerId = $this->healthcareCareerId();
        $chief = $city->getServiceLeader('healthcare');
        $isOwner = $hospital && $hospital->owner_id === $character->id;

        $owner = $hospital?->owner ? [
            'name' => $hospital->owner->display_name ?? 'Unknown',
            'avatar_url' => $hospital->owner->avatar_url ?? null,
        ] : null;


        $now = now()->getTimestamp();


        $wardRows = Character::where('city_id', $city->id)
            ->alive()
            ->whereHas('timers', fn($q) => $q->where('hospital_until', '>', $now))
            ->select('id')
            ->limit(50)
            ->get();

        $patients = $wardRows->map(fn(Character $p) => [
            'id' => encrypt($p->id),
            'name' => 'Patient #' . strtoupper(\Illuminate\Support\Str::random(5)),
            'avatar_url' => null,
        ])->values()->all();


        $staff = [];
        if ($careerId) {
            $ranks = CareerRank::getRanksForCareer($careerId)->keyBy('rank_level');
            $members = Character::where('career_id', $careerId)
                ->where('home_city_id', $city->id)
                ->alive()
                ->orderByDesc('career_rank')
                ->orderBy('display_name')
                ->select('id', 'display_name', 'custom_avatar_url', 'career_rank')
                ->limit(50)
                ->get();

            $staff = $members->map(fn(Character $m) => [
                'id' => $m->id,
                'name' => $m->display_name,
                'avatar_url' => $m->custom_avatar_url ?: ($ranks->get($m->career_rank)?->avatar_url ?? null),
                'rank_name' => $ranks->get($m->career_rank)?->rank_name ?? 'Resident',
            ])->values()->all();
        }

        $genderFee = $hospital ? (int) $hospital->getSetting('gender_reassignment_fee', 50_000) : 50_000;
        $surgeryFee = $hospital ? (int) $hospital->getSetting('surgery_fee', 10_000) : 10_000;

        $surgeryQueue = $hospital ? (array) $hospital->getSetting('surgery_queue', []) : [];
        $genderQueue = $hospital ? (array) $hospital->getSetting('gender_queue', []) : [];

        $queuePatients = collect($surgeryQueue)->map(fn($e) => [
            'id' => encrypt($e['id']),
            'name' => 'Patient #' . strtoupper(\Illuminate\Support\Str::random(5)),
            'avatar_url' => null,
            'queue_type' => 'surgery',
        ])->merge(
                collect($genderQueue)->map(fn($e) => [
                    'id' => encrypt($e['id']),
                    'name' => 'Patient #' . strtoupper(\Illuminate\Support\Str::random(5)),
                    'avatar_url' => null,
                    'queue_type' => 'gender',
                ])
            )->values()->all();

        $inSurgeryQueue = collect($surgeryQueue)->where('id', $character->id)->isNotEmpty();
        $inGenderQueue = collect($genderQueue)->where('id', $character->id)->isNotEmpty();

        $appliedSurgeryFee = (int) (data_get(
            collect($surgeryQueue)->firstWhere('id', $character->id),
            'fee'
        ) ?? 0);
        $appliedGenderFee = (int) (data_get(
            collect($genderQueue)->firstWhere('id', $character->id),
            'fee'
        ) ?? 0);

        return Inertia::render('City/Hospital', [
            'hospital' => $hospital ? [
                'name' => $hospital->name,
                'city' => $city->name,
                'image' => $hospital->image_url,
                'description' => $hospital->description,
                'chief_name' => $chief['name'] ?? null,
                'chief_avatar' => $chief['avatar_url'] ?? null,
                'owner' => $owner,
            ] : null,
            'is_owner' => $isOwner,
            'patients' => $patients,
            'queue_patients' => $queuePatients,
            'in_surgery_queue' => $inSurgeryQueue,
            'in_gender_queue' => $inGenderQueue,
            'staff' => $staff,
            'gender_fee' => $genderFee,
            'surgery_fee' => $surgeryFee,
            'applied_surgery_fee' => $appliedSurgeryFee,
            'applied_gender_fee' => $appliedGenderFee,
            'city_slug' => $city->slug,
        ]);
    }





    public function EmergencyRoom(Request $request): \Inertia\Response
    {
        $character = $request->user()->character;
        $city = $character->homeCity;

        $hospital = $this->getHospital($city);
        if ($hospital) {
            $hospital->loadMissing('owner');
        }
        $this->purge($hospital);

        $careerId = $this->healthcareCareerId();
        $rank = (int) $character->career_rank;

        $isChief = $careerId
            && $character->career_id === $careerId
            && $rank === 4
            && $character->home_city_id === $character->city_id;

        $isOwner = $hospital && $hospital->owner_id === $character->id;

        $chief = $city->getServiceLeader('healthcare');
        $owner = $hospital?->owner ? [
            'name' => $hospital->owner->display_name ?? 'Unknown',
            'avatar_url' => $hospital->owner->avatar_url ?? null,
        ] : null;


        $rankMap = $careerId ? CareerRank::getRanksForCareer($careerId)->keyBy('rank_level') : collect();
        $rankLabel = $rankMap->get($rank)?->rank_name ?? 'Resident';

        $surgeryFee = $hospital ? (int) $hospital->getSetting('surgery_fee', 10_000) : 10_000;
        $genderFee = $hospital ? (int) $hospital->getSetting('gender_reassignment_fee', 50_000) : 50_000;


        $surgeonSurgeryCut = (int) floor($surgeryFee * 0.10);
        $surgeonGenderCut = (int) floor($genderFee * 0.10);


        $now = now()->getTimestamp();
        $wardRows = Character::where('city_id', $city->id)
            ->alive()
            ->where('id', '!=', $character->id)
            ->whereHas('timers', fn($q) => $q->where('hospital_until', '>', $now))
            ->select('id', 'health', 'max_health')
            ->limit(50)
            ->get();

        $patients = $wardRows->map(fn(Character $p) => [
            'id' => encrypt($p->id),
            'name' => 'Patient #' . strtoupper(\Illuminate\Support\Str::random(5)),
            'avatar_url' => null,
        ])->values()->all();


        $staff = [];
        if ($careerId) {
            $members = Character::where('career_id', $careerId)
                ->where('home_city_id', $city->id)
                ->alive()
                ->orderByDesc('career_rank')
                ->orderBy('display_name')
                ->select('id', 'display_name', 'custom_avatar_url', 'career_rank')
                ->limit(50)
                ->get();

            $staff = $members->map(fn(Character $m) => [
                'id' => $m->id,
                'name' => $m->display_name,
                'avatar_url' => $m->custom_avatar_url ?: ($rankMap->get($m->career_rank)?->avatar_url ?? null),
                'rank_name' => $rankMap->get($m->career_rank)?->rank_name ?? 'Resident',
                'rank_level' => $m->career_rank,
                'is_self' => $m->id === $character->id,
            ])->values()->all();
        }

        $surgeryQueueRaw = $hospital ? (array) $hospital->getSetting('surgery_queue', []) : [];
        $genderQueueRaw = $hospital ? (array) $hospital->getSetting('gender_queue', []) : [];

        $surgeryQueue = collect($surgeryQueueRaw)->map(fn($q) => [
            'id' => encrypt($q['id']),
            'name' => 'Patient #' . strtoupper(\Illuminate\Support\Str::random(5)),
            'avatar_url' => null,
            'applied_at' => $q['applied_at'] ?? null,
        ])->values()->all();

        $genderQueue = collect($genderQueueRaw)->map(fn($q) => [
            'id' => encrypt($q['id']),
            'name' => 'Patient #' . strtoupper(\Illuminate\Support\Str::random(5)),
            'avatar_url' => null,
            'applied_at' => $q['applied_at'] ?? null,
        ])->values()->all();

        return Inertia::render('Careers/Healthcare', [
            'hospital' => $hospital ? [
                'name' => $hospital->name,
                'image' => $hospital->image_url,
                'description' => $hospital->description,
                'chief_name' => $chief['name'] ?? null,
                'chief_avatar' => $chief['avatar_url'] ?? null,
                'owner' => $owner,
            ] : null,
            'rank' => $rank,
            'rank_label' => $rankLabel,
            'is_chief' => $isChief,
            'is_owner' => $isOwner,
            'patients' => $patients,
            'surgery_queue' => $surgeryQueue,
            'gender_queue' => $genderQueue,
            'staff' => $staff,
            'gender_fee' => $genderFee,
            'surgery_fee' => $surgeryFee,
            'surgeon_surgery_cut' => $surgeonSurgeryCut,
            'surgeon_gender_cut' => $surgeonGenderCut,
        ]);
    }





    public function updateSettings(Request $request, City $city): \Illuminate\Http\RedirectResponse
    {
        [$character, $city] = $this->getContext($request, $city);

        $hospital = $this->getHospital($city);

        if (!$hospital || $hospital->owner_id !== $character->id) {
            return back()->with('error', 'You do not have authority to manage this hospital.');
        }

        // Fee bounds — kept in sync with the City/Hospital.tsx owner
        // modal slider min/max so the UI can't submit values the
        // backend would reject.
        $request->validate([
            'gender_reassignment_fee' => 'sometimes|integer|min:5000|max:50000',
            'surgery_fee' => 'sometimes|integer|min:10000|max:100000',
        ]);

        if ($request->has('gender_reassignment_fee')) {
            $hospital->setSetting('gender_reassignment_fee', (int) $request->gender_reassignment_fee);
        }
        if ($request->has('surgery_fee')) {
            $hospital->setSetting('surgery_fee', (int) $request->surgery_fee);
        }

        Log::info('[Hospital] Owner updated rates.', [
            'owner' => $character->id,
            'city' => $city->id,
            'gender_fee' => $request->gender_reassignment_fee ?? null,
            'surgery_fee' => $request->surgery_fee ?? null,
        ]);

        return back()->with('success', 'Hospital rates updated.');
    }







    public function surgery(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['character_id' => 'required|string']);

        try {
            $patientId = (int) decrypt($request->character_id);
        } catch (\Throwable) {
            return back()->with('error', 'Invalid patient reference.');
        }

        $character = $request->user()->character;
        $careerId = $this->healthcareCareerId();

        if (!$careerId || $character->career_id !== $careerId || (int) $character->career_rank < 2) {
            return back()->with('error', 'Emergency Care requires rank 2 or above.');
        }


        $rankName = $character->current_rank?->rank_name ?? '';
        $hospital = $this->getHospital($character->city);

        try {
            return DB::transaction(function () use ($character, $patientId, $careerId, $rankName, $hospital) {



                $locked = DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

                if (
                    !$locked || $locked->deleted_at || $locked->health <= 0
                    || $locked->career_id !== $careerId || (int) $locked->career_rank < 2
                    || $locked->city_id !== $locked->home_city_id
                ) {
                    return back()->with('error', 'Your status has changed. Please try again.');
                }

                $nextAction = DB::table('character_timers')
                    ->where('character_id', $character->id)
                    ->value('next_action_at');
                if ($nextAction && $nextAction > now()->getTimestamp()) {
                    return back()->with('error', 'You must wait before your next action.');
                }

                if ($patientId === $character->id) {
                    return back()->with('error', 'You cannot operate on yourself.');
                }


                $character->career_rank = $locked->career_rank;
                $character->city_id = $locked->city_id;

                $patient = Character::with('timers')
                    ->where('id', $patientId)
                    ->where('city_id', $character->city_id)
                    ->alive()
                    ->lockForUpdate()
                    ->first();

                if (!$patient) {
                    return back()->with('error', 'Patient not found or no longer in this city.');
                }

                if ($patient->isJailed()) {
                    return back()->with('error', 'A jailed patient cannot be admitted for surgery.');
                }

                if ($patient->health >= $patient->max_health) {
                    return back()->with('error', 'This patient is already at full health.');
                }


                $cooldowns = $hospital ? (array) $hospital->getSetting('surgery_cooldowns', []) : [];
                $cooldownUntil = $cooldowns[(string) $patient->id] ?? null;
                if ($cooldownUntil && \Carbon\Carbon::parse($cooldownUntil)->isFuture()) {
                    $remaining = \Carbon\Carbon::parse($cooldownUntil)->diffForHumans();
                    return back()->with('error', "This patient cannot receive surgery again until {$remaining}.");
                }


                $queueEntry = collect($hospital ? (array) $hospital->getSetting('surgery_queue', []) : [])
                    ->firstWhere('id', $patient->id);
                $fee = (int) (data_get($queueEntry, 'fee') ?? ($hospital ? $hospital->getSetting('surgery_fee', 10_000) : 10_000));
                $surgeonCut = (int) floor($fee * 0.10);


                $character->addCash($surgeonCut);
                if ($hospital) {
                    DB::table('businesses')->where('id', $hospital->id)->decrement('balance', $surgeonCut);
                }
                //! TODO right now we allow to heal  at 3 cities .

                $xp = max(40000, min((int) $character->career_xp, 100000));

                $ceiling = 15 + (int) round((log10($xp) - log10(40000)) / (log10(100000) - log10(40000)) * 15);
                $healPool = random_int(15, min(25, $ceiling));

                $patient->refresh();

                $deficit = max(0, $patient->max_health - $patient->health);
                $healAmount = min($healPool, $deficit);

                if ($healAmount <= 0) {
                    return back()->with('error', 'This patient is already at full health, but they paid the fee so they get no refunds.');
                }

                $patient->heal($healAmount);

                $character->addXp(100 + ((int) $character->career_rank * 15));

                $cooldowns[(string) $patient->id] = now()->addHours(12)->toIso8601String();
                $hospital->setSetting('surgery_cooldowns', $cooldowns);

                DB::table('character_timers')
                    ->where('character_id', $character->id)
                    ->update(['next_action_at' => now()->addSeconds(config('timers.action'))->getTimestamp()]);

                JournalService::custom($patient->id, 'hospital_surgery', [
                    'message' => "{$rankName} {$character->display_name} has successfully performed surgery on you and managed to restore your health by {$healAmount} HP.",
                    'heal_amount' => $healAmount,
                ]);

                \App\Models\CharacterHistory::addHistory($character, 'surgeries_performed');
                \App\Models\CharacterHistory::addHistory($character, 'earned_career', $surgeonCut);

                Log::info('[Hospital] Surgery performed.', [
                    'surgeon' => $character->id,
                    'patient' => $patient->id,
                    'heal_amount' => $healAmount,
                    'fee' => $fee,
                    'surgeon_cut' => $surgeonCut,
                ]);

                if ($hospital) {
                    $queue = collect($hospital->getSetting('surgery_queue', []))
                        ->reject(fn($entry) => ($entry['id'] ?? null) === $patient->id)
                        ->values()
                        ->all();
                    $hospital->setSetting('surgery_queue', $queue);
                }

                return back()->with(
                    'success',
                    'After hours in the emergency room, you managed to successfully restore the patient\'s health to a stable amount. You have been paid $' . number_format($surgeonCut) . '.'
                );
            });
        } catch (\Throwable $e) {
            Log::error('[Hospital] Surgery failed.', [
                'surgeon' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Surgery failed. Please try again.');
        }
    }





    //! you could just keep applying for gender for xp glitch

    public function genderReassignment(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['character_id' => 'required|string']);

        try {
            $patientId = (int) decrypt($request->character_id);
        } catch (\Throwable) {
            return back()->with('error', 'Invalid patient reference.');
        }

        $character = $request->user()->character;
        $careerId = $this->healthcareCareerId();

        if (!$careerId || $character->career_id !== $careerId || (int) $character->career_rank < 3) {
            return back()->with('error', 'Gender reassignment requires rank 3 or above.');
        }

        $rankName = $character->current_rank?->rank_name ?? '';
        $hospital = $this->getHospital($character->city);

        try {
            return DB::transaction(function () use ($character, $patientId, $careerId, $rankName, $hospital) {
                $locked = DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

                if (
                    !$locked || $locked->deleted_at || $locked->health <= 0
                    || $locked->career_id !== $careerId || (int) $locked->career_rank < 3
                    || $locked->city_id !== $locked->home_city_id
                ) {
                    return back()->with('error', 'Your status has changed. Please try again.');
                }

                $nextAction = DB::table('character_timers')
                    ->where('character_id', $character->id)
                    ->value('next_action_at');
                if ($nextAction && $nextAction > now()->getTimestamp()) {
                    return back()->with('error', 'You must wait before your next action.');
                }

                if ($patientId === $character->id) {
                    return back()->with('error', 'You cannot perform this procedure on yourself.');
                }

                $character->career_rank = $locked->career_rank;
                $character->city_id = $locked->city_id;

                $patient = Character::with('timers')
                    ->where('id', $patientId)
                    ->where('city_id', $character->city_id)
                    ->alive()
                    ->lockForUpdate()
                    ->first();

                if (!$patient) {
                    return back()->with('error', 'Patient not found or no longer in this city.');
                }

                if ($patient->isJailed()) {
                    return back()->with('error', 'A jailed patient cannot be admitted for this procedure.');
                }


                $queueEntry = collect($hospital ? (array) $hospital->getSetting('gender_queue', []) : [])
                    ->firstWhere('id', $patient->id);
                $fee = (int) (data_get($queueEntry, 'fee') ?? ($hospital ? $hospital->getSetting('gender_reassignment_fee', 50_000) : 50_000));
                $surgeonCut = (int) floor($fee * 0.10);


                $character->addCash($surgeonCut);
                if ($hospital) {
                    DB::table('businesses')->where('id', $hospital->id)->decrement('balance', $surgeonCut);
                }
                $newGender = $patient->gender === 'Male' ? 'Female' : 'Male';
                $patient->update(['gender' => $newGender]);

                $character->addXp(50 + ((int) $character->career_rank * 10));

                DB::table('character_timers')
                    ->where('character_id', $character->id)
                    ->update(['next_action_at' => now()->addSeconds(config('timers.action'))->getTimestamp()]);

                $procedures = $newGender === 'Female'
                    ? 'bilateral breast augmentation and vaginoplasty'
                    : 'mastectomy and phalloplasty';

                // Compute the noun OUTSIDE the string. PHP's "{...}"
                // interpolation only accepts variable-access expressions
                // ($var / ->prop / ->method() / [key]) — operators like
                // `==`, `?:`, function calls, etc. are parse errors inside
                // the curlies. Putting the ternary in a variable first
                // sidesteps that grammar entirely.
                $genderNoun = $newGender === 'Female' ? 'woman' : 'man';

                JournalService::custom($patient->id, 'hospital_gender_reassignment', [
                    'message' => "{$rankName} {$character->display_name} completed your {$procedures} surgery and you are now a {$genderNoun}.",
                    'new_gender' => $newGender,
                    'fee' => $fee,
                ]);

                \App\Models\CharacterHistory::addHistory($character, 'gender_reassignments_performed');
                \App\Models\CharacterHistory::addHistory($character, 'earned_career', $surgeonCut);

                Log::info('[Hospital] Gender reassignment completed.', [
                    'doctor' => $character->id,
                    'patient' => $patient->id,
                    'new_gender' => $newGender,
                    'fee' => $fee,
                    'surgeon_cut' => $surgeonCut,
                ]);

                if ($hospital) {
                    $queue = collect($hospital->getSetting('gender_queue', []))
                        ->reject(fn($entry) => ($entry['id'] ?? null) === $patient->id)
                        ->values()
                        ->all();
                    $hospital->setSetting('gender_queue', $queue);
                }

                return back()->with(
                    'success',
                    'After hours of performing a ' . $procedures . ' you finally managed to turn your patient into a ' . $genderNoun . ' and got paid $' . number_format($surgeonCut) . '.'
                );
            });
        } catch (\Throwable $e) {
            Log::error('[Hospital] Gender reassignment failed.', [
                'doctor' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Procedure failed. Please try again.');
        }
    }






    public function applySurgery(Request $request, City $city): \Illuminate\Http\RedirectResponse
    {
        [$character, $city] = $this->getContext($request, $city);

        if (!$character->isAlive() || $character->isJailed()) {
            return back()->with('error', 'You cannot apply in your current state.');
        }

        if ($character->isHospitalized()) {
            return back()->with('error', 'You are already admitted to the ward.');
        }

        if ($character->health >= $character->max_health) {
            return back()->with('error', 'You are already at full health.');
        }

        $hospital = $this->getHospital($city);
        if (!$hospital)
            return back()->with('error', 'No hospital found in this city.');

        //TODO should clear cooldown once timer expires
        $cooldownUntil = ((array) $hospital->getSetting('surgery_cooldowns', []))[(string) $character->id] ?? null;
        if ($cooldownUntil && \Carbon\Carbon::parse($cooldownUntil)->isFuture()) {
            return back()->with('error', 'You cannot receive surgery again in this city until ' . \Carbon\Carbon::parse($cooldownUntil)->utc()->format('d/m/y H:i:s') . ' UTC.');
        }

        $fee = (int) $hospital->getSetting('surgery_fee', 10_000);
        if ($character->cash_on_hand < $fee) {
            return back()->with('error', 'You need $' . number_format($fee) . ' cash on hand to apply.');
        }

        $result = DB::transaction(function () use ($hospital, $character, $fee) {
            DB::table('businesses')->where('id', $hospital->id)->lockForUpdate()->first();

            $queue = (array) $hospital->getSetting('surgery_queue', []);

            if (collect($queue)->where('id', $character->id)->isNotEmpty()) {
                return 'duplicate';
            }


            if (!$character->removeCash($fee)) {
                return 'insufficient';
            }
            DB::table('businesses')->where('id', $hospital->id)->increment('balance', $fee);

            $queue[] = ['id' => $character->id, 'applied_at' => now()->getTimestamp(), 'fee' => $fee];
            $hospital->setSetting('surgery_queue', $queue);
            return 'ok';
        });

        if ($result === 'duplicate') {
            return back()->with('error', 'You already have a pending surgery application.');
        }

        if ($result === 'insufficient') {
            return back()->with('error', 'You do not have $' . number_format($fee) . ' cash on hand to apply.');
        }

        Log::info('[Hospital] Surgery application submitted.', [
            'character' => $character->id,
            'city' => $city->id,
            'fee' => $fee,
        ]);

        return back()->with('success', 'You have succesfully paid $' . number_format($fee) . ' for surgery. A doctor  will attend to you as soon as they can.');
    }




    //TODO should clear cooldown once timer expires
    public function applyGender(Request $request, City $city): \Illuminate\Http\RedirectResponse
    {
        [$character, $city] = $this->getContext($request, $city);

        if (!$character->isAlive() || $character->isJailed()) {
            return back()->with('error', 'You cannot apply in your current state.');
        }

        $hospital = $this->getHospital($city);
        if (!$hospital) {
            return back()->with('error', 'No hospital found in this city.');
        }

        $fee = (int) $hospital->getSetting('gender_reassignment_fee', 50_000);
        if ($character->cash_on_hand < $fee) {
            return back()->with('error', 'You need $' . number_format($fee) . ' cash on hand to apply.');
        }

        $result = DB::transaction(function () use ($hospital, $character, $fee) {
            DB::table('businesses')->where('id', $hospital->id)->lockForUpdate()->first();

            $queue = (array) $hospital->getSetting('gender_queue', []);

            if (collect($queue)->where('id', $character->id)->isNotEmpty()) {
                return 'duplicate';
            }


            if (!$character->removeCash($fee)) {
                return 'insufficient';
            }
            DB::table('businesses')->where('id', $hospital->id)->increment('balance', $fee);

            $queue[] = ['id' => $character->id, 'applied_at' => now()->getTimestamp(), 'fee' => $fee];
            $hospital->setSetting('gender_queue', $queue);
            return 'ok';
        });

        if ($result === 'duplicate') {
            return back()->with('error', 'You already have a pending gender reassignment application.');
        }

        if ($result === 'insufficient') {
            return back()->with('error', 'You do not have $' . number_format($fee) . ' cash on hand to apply.');
        }

        Log::info('[Hospital] Gender reassignment application submitted.', [
            'character' => $character->id,
            'city' => $city->id,
            'fee' => $fee,
        ]);

        return back()->with('success', 'You have paid $' . number_format($fee) . ' for gender reassignment procedures. A surgeon will review your case.');
    }





    public function cancelSurgery(Request $request, City $city): \Illuminate\Http\RedirectResponse
    {
        [$character, $city] = $this->getContext($request, $city);

        $hospital = $this->getHospital($city);
        if (!$hospital) {
            return back()->with('error', 'No hospital found in this city.');
        }

        $queue = collect($hospital->getSetting('surgery_queue', []));
        $entry = $queue->firstWhere('id', $character->id);
        $filtered = $queue->where('id', '!=', $character->id)->values()->all();

        if (!$entry) {
            return back()->with('error', 'You are not in the surgery queue.');
        }


        $refund = (int) (data_get($entry, 'fee') ?? 0);
        if ($refund > 0) {
            $refund = $refund * 0.25;
            $character->addCash($refund);
            DB::table('businesses')->where('id', $hospital->id)->decrement('balance', $refund);
        }

        $hospital->setSetting('surgery_queue', $filtered);

        return back()->with('warning', 'Your application for surgery has been canceled, unfortunately you were still charged for the stay and only 25% of the fee could be refunded.');
    }





    public function cancelGender(Request $request, City $city): \Illuminate\Http\RedirectResponse
    {
        [$character, $city] = $this->getContext($request, $city);

        $hospital = $this->getHospital($city);
        if (!$hospital) {
            return back()->with('error', 'No hospital found in this city.');
        }

        $queue = collect($hospital->getSetting('gender_queue', []));
        $entry = $queue->firstWhere('id', $character->id);
        $filtered = $queue->where('id', '!=', $character->id)->values()->all();

        if (!$entry) {
            return back()->with('error', 'You are not in the gender reassignment queue.');
        }

        $refund = (int) (data_get($entry, 'fee') ?? 0);
        if ($refund > 0) {
            $character->addCash($refund * 0.25);
            DB::table('businesses')->where('id', $hospital->id)->decrement('balance', $refund * 0.25);
        }

        $hospital->setSetting('gender_queue', $filtered);

        return back()->with('warning', 'You cancelled your gender reassignment surgery, unfortunately the hospital could only refund you 25% of the payment. Whoops!');
    }







    public function dismiss(Request $request): \Illuminate\Http\RedirectResponse
    {
        $character = $request->user()->character;
        $careerId = $this->healthcareCareerId();

        $isChief = $careerId
            && $character->career_id === $careerId
            && (int) $character->career_rank === 4
            && $character->home_city_id !== null;

        if (!$isChief) {
            return back()->with('error', 'Only the Surgeon General can dismiss staff.');
        }

        $validated = $request->validate([
            'character_id' => 'required|integer|exists:characters,id',
        ]);

        $targetId = (int) $validated['character_id'];

        if ($targetId === $character->id) {
            return back()->with('error', 'You cannot dismiss yourself.');
        }

        try {
            return DB::transaction(function () use ($character, $careerId, $targetId) {
                $locked = DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

                if (!$locked || $locked->career_id !== $careerId || (int) $locked->career_rank !== 4) {
                    return back()->with('error', 'Your authority has changed.');
                }

                $target = Character::lockForUpdate()->find($targetId);

                if (!$target || !$target->isAlive()) {
                    return back()->with('error', 'Staff member not found.');
                }

                if ($target->career_id !== $careerId) {
                    return back()->with('error', 'That character is not in the healthcare career.');
                }

                if ($target->home_city_id !== $character->home_city_id) {
                    return back()->with('error', 'That staff member does not serve in your hospital.');
                }

                if ((int) $target->career_rank >= 4) {
                    return back()->with('error', 'You cannot dismiss another Surgeon General.');
                }

                $rankName = $target->current_rank?->rank_name ?? 'Staff';
                $callerRank = $character->current_rank?->rank_name ?? 'Surgeon General';

                $target->quitCareer(preserveExp: true);

                JournalService::careerDismissed(
                    $target->id,
                    $character->display_name,
                    $callerRank,
                    'Hospital'
                );

                Log::info('[Hospital] Staff dismissed.', [
                    'by' => $character->id,
                    'target' => $targetId,
                    'rank' => $rankName,
                ]);

                return back()->with('success', "{$target->display_name} has been dismissed from the hospital.");
            });
        } catch (\Throwable $e) {
            Log::error('[Hospital] Dismiss failed.', [
                'by' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Dismissal failed. Please try again.');
        }
    }








    public function revive(Request $request, string $displayName): \Illuminate\Http\RedirectResponse
    {
        $surgeon = $request->user()->character;
        $careerId = $this->healthcareCareerId();


        if (!$careerId || $surgeon->career_id !== $careerId) {
            return back()->with('error', 'Only healthcare workers can attempt a revival.');
        }
        if (!$surgeon->isAlive() || $surgeon->isHospitalized() || $surgeon->isJailed()) {
            return back()->with('error', 'You cannot attempt a revival in your current state.');
        }
        if (!$surgeon->hasTalentActive('lazarus_connection')) {
            return back()->with('error', 'The Lazarus Connection talent must be active to attempt this.');
        }


        $target = Character::findByName($displayName);

        if (!$target || !$target->trashed()) {
            return back()->with('error', 'Character not found or not deceased.');
        }
        if ($target->city_id !== $surgeon->city_id) {
            return back()->with('error', 'You must be in the same city as the deceased.');
        }
        if (!$target->isOnline()) {
            return back()->with('error', 'The patient must be present and online for a revival attempt.');
        }
        if (!$target->deleted_at->addHours(12)->isFuture()) {
            return back()->with('error', 'The revival window for this character has passed.');
        }
        if ($target->death_cause === 'Suicide') {
            return back()->with('error', 'This character chose to end their own life. That decision cannot be undone.');
        }
        if ($target->revived_at !== null) {
            return back()->with('error', 'This character has already been revived once. They cannot be brought back again.');
        }


        if ($target->user?->is_banned) {
            return back()->with('error', 'This character cannot be revived.');
        }


        $surgeon->loadMissing(['timers', 'career', 'stats']);
        $target->loadMissing(['stats']);

        $rankName = $surgeon->current_rank?->rank_name ?? 'Surgeon';




        $surgeonCity = $surgeon->city;
        $hospital = $surgeonCity ? $this->getHospital($surgeonCity) : null;

        try {
            return DB::transaction(function () use ($surgeon, $target, $rankName, $hospital) {
                $locked = DB::table('characters')->where('id', $surgeon->id)->lockForUpdate()->first();


                if (!$locked || $locked->deleted_at || $locked->health <= 0) {
                    return back()->with('error', 'You cannot attempt a revival in your current state.');
                }

                $surgeon->active_talent = $locked->active_talent;
                if (!$surgeon->hasTalentActive('lazarus_connection')) {
                    return back()->with('error', 'The Lazarus Connection talent must be active to attempt this.');
                }

                Character::withTrashed()->where('id', $target->id)->lockForUpdate()->first();
                $target->refresh();

                if (!$target->trashed() || $target->revived_at !== null) {
                    return back()->with('error', 'Revival is no longer possible for this character.');
                }

                $surgeonStats = $surgeon->stats->effectiveStats();
                $targetStats = $target->stats->effectiveStats();

                $surgeriesDone = (int) DB::table('character_histories')
                    ->where('character_id', $surgeon->id)
                    ->value('surgeries_performed');

                $surgeonSkill = log10(max(1, $surgeon->career_xp)) * 9;
                $intelligenceBonus = log10(max(1, $surgeonStats['intelligence'])) * 2;
                $luckEdge = (log10(max(1, $surgeonStats['luck'])) - log10(max(1, $targetStats['luck']))) * 5;
                $resistance = log10(max(1, $target->total_character_exp)) * 3;
                $combatResist = log10(max(1, $targetStats['defense'] + $targetStats['intelligence'] + $targetStats['offense'])) * 3;
                $surgeryBoost = min(5, max(0, (log10(max(10, $surgeriesDone)) - log10(10)) / (log10(100) - log10(10)) * 5));

                $chance = min(55, max(1, $surgeonSkill + $intelligenceBonus + $luckEdge - ($resistance + $combatResist) + $surgeryBoost));
                $roll = random_int(1, 100);
                $success = $roll <= $chance;

                Log::info('[Hospital] Revive attempt.', [
                    'surgeon' => $surgeon->id,
                    'target' => $target->id,
                    'chance' => $chance,
                    'roll' => $roll,
                    'success' => $success,
                ]);


                $surgeon->update(['active_talent' => null]);
                $surgeon->timers()->update([
                    'next_talents_at' => now()->addHours(12)->getTimestamp(),
                ]);
                \App\Models\CharacterHistory::addHistory($surgeon, 'talent_lazarus_connection_used');




                DB::table('characters')->where('id', $target->id)->update([
                    'revived_at' => now(),
                ]);

                if (!$success) {
                    return back()->with('error', "You did your absolute best, but it wasn't enough. The revival failed. {$target->display_name} could not be brought back.");
                }


                $target->restore();


                DB::table('characters')->where('id', $target->id)->update([
                    'health' => max(1, (int) ceil($target->max_health * 0.10)),
                    'death_cause' => null,
                    'death_reason' => null,
                    'biography' => '',
                ]);


                $xpPenalty = (int) floor($target->total_character_exp * 0.15);
                DB::table('characters')->where('id', $target->id)->update([
                    'total_character_exp' => max(0, $target->total_character_exp - $xpPenalty),
                ]);






                $target->refresh();
                $surgeryFee = (int) floor($target->cash_on_hand * 0.10);
                $surgeonCut = (int) floor($surgeryFee * 0.10);
                $hospitalCut = $surgeryFee - $surgeonCut;

                if ($surgeryFee > 0) {
                    DB::table('characters')->where('id', $target->id)
                        ->where('cash_on_hand', '>=', $surgeryFee)
                        ->decrement('cash_on_hand', $surgeryFee);
                    if ($surgeonCut > 0) {
                        DB::table('characters')->where('id', $surgeon->id)
                            ->increment('cash_on_hand', $surgeonCut);
                    }
                    if ($hospital && $hospitalCut > 0) {
                        DB::table('businesses')->where('id', $hospital->id)
                            ->increment('balance', $hospitalCut);
                    }
                    \App\Models\CharacterHistory::addHistory($surgeon, 'earned_career', $surgeonCut);
                }


                $lockUntil = now()->addHours(12)->getTimestamp();
                DB::table('character_timers')->where('character_id', $target->id)->update([
                    'next_work_at' => $lockUntil,
                    'next_action_at' => $lockUntil,
                    'next_conflict_at' => $lockUntil,
                    'next_study_at' => $lockUntil,
                    'next_travel_at' => $lockUntil,
                    'next_talents_at' => $lockUntil,
                    'protection_until' => $lockUntil,
                ]);





                Leaderboard::clearOnRevival($target->id);

                $feeNote = $surgeryFee > 0
                    ? ' You have also been charged $' . number_format($surgeryFee) . ' in surgical fees.'
                    : '';
                JournalService::custom($target->id, 'revived', [
                    'message' => "{$rankName} {$surgeon->display_name} has brought you back from the dead. You are alive — barely. All actions are locked for 12 hours while you recover.{$feeNote}",
                ]);

                Log::info('[Hospital] Revive succeeded.', [
                    'surgeon' => $surgeon->id,
                    'target' => $target->id,
                    'xp_penalty' => $xpPenalty,
                    'surgery_fee' => $surgeryFee,
                    'surgeon_cut' => $surgeonCut,
                    'hospital_cut' => $hospitalCut,
                ]);

                return back()->with('success', "After 12 hours of surgery, {$target->display_name} has been successfully revived. They have suffered a great deal, and have a long road of recovery ahead.");
            });
        } catch (\Throwable $e) {
            Log::error('[Hospital] Revive failed unexpectedly.', [
                'surgeon' => $surgeon->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Revival failed unexpectedly. Please try again.');
        }
    }






    public function resign(Request $request): \Illuminate\Http\RedirectResponse
    {
        $character = $request->user()->character;
        $careerId = $this->healthcareCareerId();

        if (!$careerId || $character->career_id !== $careerId) {
            return back()->with('error', 'You are not employed in healthcare.');
        }

        try {
            return DB::transaction(function () use ($character, $careerId) {
                $locked = DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

                if (!$locked || $locked->career_id !== $careerId) {
                    return back()->with('error', 'Your career status has changed.');
                }

                $rankName = $character->current_rank?->rank_name ?? 'Surgeon General';
                $character->quitCareer(preserveExp: true);

                JournalService::custom($character->id, 'career_step_down', [
                    'message' => "You have stepped down as {$rankName}. Your service to the hospital has been honourably recorded.",
                ]);

                Log::info('[Hospital] Staff resigned.', [
                    'character' => $character->id,
                ]);

                return redirect()->route('dashboard')
                    ->with('success', "You have stepped down as {$rankName}.");
            });
        } catch (\Throwable $e) {
            Log::error('[Hospital] Resign failed.', [
                'character' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Step-down failed. Please try again.');
        }
    }
}
