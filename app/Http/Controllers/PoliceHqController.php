<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Career;
use App\Models\CareerRank;
use App\Models\Character;
use App\Models\City;
use App\Models\CrimeRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class PoliceHqController extends CityController
{
    private function getPolice(City $city): ?Business
    {
        return Business::forCity($city, 'police');
    }

    public function index(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        $police         = $this->getPolice($city);
        $policeCareerId = Career::findByCode('police')?->id;

        
        $commissioner = $city->getServiceLeader('police');
        $owner        = $police?->owner ? [
            'name'       => $police->owner->display_name ?? 'Unknown',
            'avatar_url' => $police->owner->avatar_url ?? null,
        ] : null;

        $isAlreadyPolice = $character->career_id === $policeCareerId && $character->home_city_id === $city->id;
        $isCommissioner  = $isAlreadyPolice && (int) $character->career_rank === 4;

        $training       = $character->getDegree('police_academy');
        $requiredCycles = config('timers.police_training_cycles', 30);

        // Eligibility gates (career conflicts, missing degree, mayor, jail, etc.)
        // are enforced inside enroll()/train()/graduate(). The frontend always
        // renders an actionable button — backend rejection surfaces as a flash.
        $academy = [
            'isPolice'    => $isAlreadyPolice,
            'training'    => $training ? [
                'enrolled'       => true,
                'cycles'         => $training['cycles'] ?? 0,
                'requiredCycles' => $requiredCycles,
                'completed'      => ! empty($training['completed_at']),
                'nextStudyAt'    => $character->timers?->next_study_at?->getTimestamp() ?? null,
            ] : null,
        ];

        

        $roster = [];
        if ($policeCareerId) {
            $officers = Character::where('career_id', $policeCareerId)
                ->where('home_city_id', $city->id)
                ->alive()
                ->orderByDesc('career_rank')
                ->orderBy('display_name')
                ->limit(100)
                ->get();

            $careerRanks = CareerRank::getRanksForCareer($policeCareerId)
                ->keyBy('rank_level');

            // One sessions lookup for the whole roster (same semantics as Character::isOnline()).
            $officerUserIds = $officers->pluck('user_id')->filter()->unique()->values()->all();
            $onlineUserIds = $officerUserIds
                ? DB::table('sessions')
                    ->whereIn('user_id', $officerUserIds)
                    ->whereNotNull('user_id')
                    ->distinct()
                    ->pluck('user_id')
                    ->flip()
                    ->all()
                : [];

            $roster = $officers->map(function ($c) use ($careerRanks, $onlineUserIds) {
                $rank = $careerRanks->get($c->career_rank);
                return [
                    'characterId' => $c->id,
                    'displayName' => $c->display_name ?? 'Unknown',
                    'avatarUrl'   => $c->custom_avatar_url ?: ($rank?->avatar_url ?? null),
                    'rankName'    => $rank?->rank_name ?? 'Officer',
                    'rankNumber'  => $c->career_rank ?? 1,
                    'isOnline'    => $c->user_id !== null && isset($onlineUserIds[$c->user_id]),
                ];
            })->values()->all();
        }

        
        
        

        $myOpenCases = CrimeRecord::where('character_id', $character->id)
            ->where('city_id', $city->id)
            ->whereIn('status', [
                CrimeRecord::STATUS_SENTENCED,
            ])
            ->latest('committed_at')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'id'             => $r->id,
                'type'           => $r->type,
                'typeLabel'      => $r->typeLabel(),
                'severity'       => $r->severity,
                'status'         => $r->status,
                'committedAtUtc' => $r->committed_at->utc()->format('d/m/y H:i:s') . ' UTC',
                'victimName'     => $r->data['victim_name'] ?? null,
            ])
            ->values()
            ->all();

        
        
        

        $jailedCharacters = Character::where('city_id', $city->id)
            ->alive()
            ->with('timers')
            ->whereHas('timers', fn ($q) => $q->where('jail_until', '>', now()->getTimestamp()))
            ->orderBy('display_name')
            ->limit(100)
            ->get();

       
      
        $convicts = $jailedCharacters->map(fn ($c) => [
            'displayName' => $c->display_name,
            'avatarUrl'   => $c->avatar_url,
        ])->values()->all();

        return Inertia::render('City/PoliceHq', [
            'cityName'       => $city->name,
            'citySlug'       => $city->slug,
            'commissioner'   => $commissioner,
            'owner'          => $owner,
            'leaderTitle'    => $police?->leader_title ?? 'Commissioner-General',
            'academy'        => $academy,
            'roster'         => $roster,
            'myOpenCases'    => $myOpenCases,
            'convicts'       => $convicts,
            'enrollFee'      => $police?->getSetting('enroll_fee', 1000) ?? 1000,
            'isOwner'        => $police?->owner_id === $character->id,
            'isCommissioner' => $isCommissioner,
            'openCaseCount'  => CrimeRecord::inCity($city->id)
                ->where('status', CrimeRecord::STATUS_OPEN)
                ->count(),
        ]);
    }
 

    //! police training also uses degrees

    public function enroll(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        $policeCareerId = Career::findByCode('police')?->id;

        if ($character->career_id === $policeCareerId) {
            return back()->with('error', 'You are already serving on the force.');
        }

        $blocker = $this->getJoinBlocker($character, $policeCareerId);
        if ($blocker) {
            return back()->with('error', $blocker);
        }

        $existing = $character->getDegree('police_academy');
        if ($existing) {
            return back()->with('error', 'You are already enrolled in the Police Academy.');
        }

        // Mutual exclusion with Customs Academy. Both use the `degrees` JSONB,
        // so without this gate a player could train for both in parallel and
        // graduate into whichever career they wanted first. Completed-but-not-
        // yet-graduated entries also block — finish what you started.
        if ($character->getDegree('customs')) {
            return back()->with('error', "You're already training for customs, pick a lane!");
        }

        try {
            return DB::transaction(function () use ($character, $city) {
                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

                $police    = $this->getPolice($city);
                $enrollFee = (int) ($police?->getSetting('enroll_fee', 1000) ?? 1000);

                if ($enrollFee > 0 && ! $character->removeCash($enrollFee, false)) {
                    return back()->with('error', 'You need $' . number_format($enrollFee) . ' on hand to enroll.');
                }

                if ($enrollFee > 0) {
                    $police?->addBalance($enrollFee);
                }

                $degrees = $character->degrees ?? [];
                $degrees['police_academy'] = [
                    'city_id'      => $city->id,
                    'cycles'       => 0,
                    'completed_at' => null,
                ];
                $character->update(['degrees' => $degrees]);

                $feeNote = $enrollFee > 0
                    ? ' Enrollment fee of $' . number_format($enrollFee) . ' charged.'
                    : '';

                return back()->with('success', 'You have enrolled in the Police Academy, Make sure you show up dressed and sharp for your training.' . $feeNote);
            });
        } catch (\Throwable $e) {
            Log::error('[PoliceHQ] Enrollment failed', [
                'character' => $character->id,
                'error'     => $e->getMessage(),
            ]);
            return back()->with('error', 'Enrollment failed. Please try again.');
        }
    }


    public function train(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        try {
            return DB::transaction(function () use ($character, $city) {
                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

                if ($character->timers?->next_study_at?->isFuture()) {
                    return back()->with('error', 'You need to wait before your next training session.');
                }

                if ($character->isHospitalized() || ! $character->isAlive() || $character->isJailed() ) {
                    return back()->with('error', 'Cannot train while incapacitated or dead.');
                }

                if ($character->isMayor()) {
                    return back()->with('error', 'A sitting mayor cannot conduct police training.');
                }

                $training = $character->getDegree('police_academy');
                if (! $training || ! empty($training['completed_at'])) {
                    return back()->with('error', 'Not enrolled or already completed training.');
                }

                if ($training['city_id'] !== $city->id) {
                    return back()->with('error', 'You must train at the precinct where you enrolled.');
                }

                $degrees = $character->degrees ?? [];
                $degrees['police_academy']['cycles']++;

                $required  = config('timers.police_training_cycles', 30);
                $completed = $degrees['police_academy']['cycles'] >= $required;

                if ($completed) {
                    $degrees['police_academy']['completed_at'] = now()->toIso8601String();
                }

                $character->update(['degrees' => $degrees]);

                $character->timers()->update([
                    'next_study_at' => now()->addSeconds(config('timers.study'))->getTimestamp(),
                ]);

                if ($completed) {
                    return back()->with('success', 'Training complete! You are ready to graduate and join the force.');
                }

                $current = $degrees['police_academy']['cycles'];
                $percent = ($current / $required) * 100;

                $message = match (true) {
                    $percent >= 90 => 'Final exercises. You are nearly ready for duty.',
                    $percent >= 50 => 'Solid progress. Your instructors are impressed.',
                    default        => 'Another training session complete. Keep at it, cadet.',
                };

                return back()->with('success', $message);
            });
        } catch (\Throwable $e) {
            Log::error('[PoliceHQ] Training failed', [
                'character' => $character->id,
                'error'     => $e->getMessage(),
            ]);
            return back()->with('error', 'Training failed. Please try again.');
        }
    }


    public function graduate(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        try {
            return DB::transaction(function () use ($character, $city) {
                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

                $training = $character->getDegree('police_academy');
                if (! $training || empty($training['completed_at'])) {
                    return back()->with('error', 'You have not completed your Police Academy training.');
                }

                if ($training['city_id'] !== $city->id) {
                    return back()->with('error', 'You must graduate at the precinct where you trained.');
                }

                if ($character->isMayor()) {
                    return back()->with('error', 'A sitting mayor cannot graduate from the police academy.');
                }

                if ($character->corporation_id) {
                    return back()->with('error', 'You must leave your corporation before joining the police.');
                }

                
                if ($character->career?->code === 'law') {
                    return back()->with('error', 'You cannot join the police while serving in the Judiciary. Step down or forcibly quit from settings first.');
                }

                if (! $character->startCareer('police')) {
                    return back()->with('error', 'Could not start police career.');
                }

                
                $character->quitDegree('police_academy');

                Log::info('[PoliceHQ] Player graduated from academy.', [
                    'character' => $character->id,
                ]);

                return back()->with('success', 'Congratulations, Officer! Welcome to the force.');
            });
        } catch (\Throwable $e) {
            Log::error('[PoliceHQ] Graduation failed', [
                'character' => $character->id,
                'error'     => $e->getMessage(),
            ]);
            return back()->with('error', 'Graduation failed. Please try again.');
        }
    }


    public function turnIn(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        $request->validate([
            'case_id' => 'required|integer',
        ]);

        return DB::transaction(function () use ($character, $request, $city) {
            
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();
            $character->refresh();

            $record = CrimeRecord::lockForUpdate()->find($request->case_id);

            if (! $record) {
                return back()->with('error', 'Case not found.');
            }

            
            $allPartyIds = $record->partyIds();
            $isParticipant = in_array($character->id, $allPartyIds, true);

            if (! $isParticipant) {
                return back()->with('error', 'This is not your case.');
            }

            if ($record->city_id !== $city->id) {
                return back()->with('error', 'This case is not under this precinct\'s jurisdiction.');
            }

            if ($record->status === CrimeRecord::STATUS_APPEALED) {
                return back()->with('error', 'Your appeal is pending. Wait for the Chief Justice to resolve it before turning yourself in.');
            }
            if ($record->status === CrimeRecord::STATUS_CONVICTED) {
                return back()->with('error', 'Your case is convicted but not yet sentenced. Wait for the judge to impose a sentence first.');
            }
            if ($record->status !== CrimeRecord::STATUS_SENTENCED) {
                return back()->with('error', 'This case cannot be surrendered in its current state.');
            }

            
            
            
            $otherIds = array_values(array_filter($allPartyIds, fn ($id) => $id !== $character->id));
            sort($otherIds);
            if (! empty($otherIds)) {
                DB::table('characters')->whereIn('id', $otherIds)->orderBy('id')->lockForUpdate()->get();
            }

            $allParties = Character::with('timers')
                ->whereIn('id', $allPartyIds)
                ->get()
                ->keyBy('id');

            
            $data                 = $record->data ?? [];
            $data['cooperation']  = true;
            $data['turned_in_at'] = now()->utc()->toIso8601String();
            $data['turned_in_by'] = $character->id;
            $record->data         = $data;
            $record->save();

            
            foreach ($allParties as $party) {
                $party->refresh();
                $record->applySentenceToCharacter($party);
            }

               City::decreaseCrimeRateById($character->home_city_id, 1.2);

                //TODO  for each participant being arrested gives a 10% chance of being fired if not in a corporation


            if ($record->detective_id) {
                $detective = Character::find($record->detective_id);
                if ($detective) {
                    \App\Models\CharacterHistory::addHistory($detective, 'cases_closed');
                }
            }

            
            $record->close();

            $partyCount = count($allParties);

            Log::info('[PoliceHQ] Voluntary surrender — sentence applied to all parties.', [
                'initiator'    => $character->id,
                'case'         => $record->id,
                'parties'      => $allPartyIds,
                'fine'         => $record->sentence['fine']         ?? 0,
                'jail_seconds' => $record->sentence['jail_seconds'] ?? 0,
            ]);

            $coNote = $partyCount > 1
                ? ' and your co-conspirators  will be paying for their crime as well.'
                : '';

            return back()->with('success',
                "You have turned yourself in for case #{$record->id} and have been fined {$record->sentence['fine']}.{$coNote}"
            );
        });
    }

    public function updateSettings(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        $police = $this->getPolice($city);

        if (! $police || $police->owner_id !== $character->id) {
            return back()->with('error', 'You do not own this precinct.');
        }

        $data = $request->validate([
            'enroll_fee' => 'required|integer|min:1000|max:10000',
        ]);

        try {
            $police->setSetting('enroll_fee', $data['enroll_fee']);
            return back()->with('success', 'Enrollment fee updated to $' . number_format($data['enroll_fee']) . '.');
        } catch (\Throwable $e) {
            Log::error('[PoliceHQ] Settings update failed', [
                'character' => $character->id,
                'error'     => $e->getMessage(),
            ]);
            return back()->with('error', 'Failed to update settings.');
        }
    }


    private function getJoinBlocker(Character $character, ?int $policeCareerId): ?string
    {
        if (! $policeCareerId) {
            return 'The police career is not available.';
        }

        $degrees      = $character->degrees ?? [];
        $hasAnyDegree = collect($degrees)->contains(
            fn ($d, $code) => $code !== 'police_academy' && ! empty($d['completed_at'])
        );
        if (! $hasAnyDegree) {
            return 'You need a degree before joining the force.';
        }

        if (! $character->isInHomeCity()) {
            return 'You cannot join the Police Force of this city!';
        }

       
        if ($character->corporation_id) {
            return 'You cannot join the police while in a corporation.';
        }

        if ($character->isMayor()) {
            return 'You cannot quit your career in the middle of your term.';
        }

        $hasActiveCases = CrimeRecord::where('character_id', $character->id)
            ->whereIn('status', [
                CrimeRecord::STATUS_CONVICTED,
                CrimeRecord::STATUS_SENTENCED,
            ])
            ->exists();
        if ($hasActiveCases) {
            return 'You cannot join the police with active criminal cases on your record.';
        }

        if ($character->isHospitalized()) {
            return 'You cannot enlist while hospitalized or incarcerated.';
        }

        return null;
    }

}
