<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Career;
use App\Models\Character;
use App\Models\CharacterTimers;
use App\Models\City;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CharacterController extends Controller
{
    public function create()
    {
        return Inertia::render('CharacterCreate', [
            'cities' => City::all(['id', 'name', 'description', 'image_url']),
            'careers' => Career::whereIn('code', ['customs', 'corporation', 'technician'])
                ->get(['id', 'name', 'code', 'description']),
        ]);
    }

    public function store(Request $request)
    {
        if ($request->user()->character()->withTrashed()->exists()) {
            return redirect()->back();
        }

        $cleanName = \Illuminate\Support\Str::of($request->input('display_name'))
            ->trim()
            ->squish()
            ->title()
            ->toString();
        //! frontend says 10 but you can do 20.
        $request->merge(['display_name' => $cleanName]);

        $validated = $request->validate([
            'display_name' => [
                'required',
                'string',
                'min:3',
                'max:20',
                'regex:/^[A-Za-z0-9 ]+$/',
                Rule::unique('characters', 'display_name'),

                function ($attribute, $value, $fail) {
                    $exists = \App\Models\Leaderboard::whereRaw(
                        'LOWER(display_name) = LOWER(?)', [$value]
                    )->exists();
                    if ($exists) {
                        $fail('This name belongs to a character on the leaderboard and cannot be reused.');
                    }
                },
            ],
            'gender' => ['required', 'in:Male,Female'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'career_id' => [
                'required',
                'integer',
                Rule::exists('careers', 'id')->where(function ($query) {
                    $query->whereIn('code', ['customs', 'corporation', 'technician']);
                }),
            ],
        ], [
            'display_name.regex' => 'Name can only contain letters, numbers, and spaces.',
            'display_name.unique' => 'This name is already taken.',
        ]);

        try {
            $career = Career::findOrFail($validated['career_id']);

            $character = Character::create([
                'user_id' => $request->user()->id,
                'display_name' => $validated['display_name'],
                'gender' => $validated['gender'],
                'city_id' => $validated['city_id'],
                'home_city_id' => $validated['city_id'],
                'career_id' => $career->id,
                'career_rank' => 1,
                'career_xp' => 100,
                'total_character_exp' => 100,
                'health' => 100,
                'max_health' => 100,
                'cash_on_hand' => 20000,
                'cash_in_bank' => 5000,
                'dirty_cash' => 0,
                'degrees' => $this->startingDegreesFor($career->code, (int) $validated['city_id']),
            ]);

            $this->initializeStartingValues($character, $career->code);

            return redirect()->route('dashboard')->with('success', 'Character created successfully!');

        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return redirect()->back()->withErrors(['display_name' => 'This character name is already taken']);
            }

            \Log::error('Character creation failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->withErrors(['error' => 'Failed to create character']);

        } catch (\Exception $e) {
            \Log::error('Character creation failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }


    private function startingDegreesFor(string $careerCode, int $cityId): ?array
    {
        $degree = match ($careerCode) {
            'technician' => 'engineering',
            'corporation' => 'business',
            default => null,
        };

        if (!$degree) {
            return null;
        }

        return [
            $degree => [
                'city_id' => $cityId,
                'cycles' => config("timers.degree_cycles.{$degree}", 0),
                'completed_at' => now()->toIso8601String(),
            ],
        ];
    }

    private function initializeStartingValues(Character $character, string $careerCode): void
    {
        $startingStats = match ($careerCode) {
            'customs' => [
                'influence' => 25,
                'intelligence' => 8,
                'offense' => 6,
                'defense' => 3,
                'luck' => 3,
            ],
            'corporation' => [
                'influence' => 25,
                'intelligence' => 7,
                'offense' => 5,
                'defense' => 5,
                'luck' => 3,
            ],
            'technician' => [
                'influence' => 25,
                'intelligence' => 4,
                'offense' => 4,
                'defense' => 6,
                'luck' => 6,
            ],
            default => [
                'influence' => 25,
                'intelligence' => 5,
                'offense' => 5,
                'defense' => 5,
                'luck' => 5,
            ]
        };

        $character->stats()->create([
            'character_id' => $character->id,
            'influence' => $startingStats['influence'],
            'intelligence' => $startingStats['intelligence'],
            'offense' => $startingStats['offense'],
            'defense' => $startingStats['defense'],
            'luck' => $startingStats['luck'],
        ]);

        $timers = new CharacterTimers;

        $timers->next_work_at = now()->addMinutes(0);
        $timers->next_action_at = now()->addMinutes(0);
        $timers->next_travel_at = now()->addMinutes(0);
        $timers->next_talents_at = now()->addMinutes(0);
        $timers->next_study_at = now()->addMinutes(0);
        $timers->next_conflict_at = now()->addHours(120);
        $timers->protection_until = now()->addHours(120);
        $timers->hospital_until = 0;
        $timers->character_id = $character->id;
        $timers->hospital_reason = null;
        $timers->jail_until = 0;
        $timers->strength = 100.00;


        $character->timers()->save($timers);

        \App\Models\CharacterHistory::appendCareerEvent($character, [
            'type' => 'career_started',
            'career_name' => $character->career?->name,
            'career_code' => $careerCode,
            'rank_name' => $character->current_rank?->rank_name,
        ]);
    }
}
