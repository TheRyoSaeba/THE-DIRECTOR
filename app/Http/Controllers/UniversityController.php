<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Character;
use App\Models\City;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class UniversityController extends CityController
{
    private const DEGREE_CAREER_MAP = [
        'finance' => 'Banking',
        'law' => 'Law',
        'medicine' => 'Healthcare',
        'engineering' => 'Technician',
        'business' => 'Corporation',
    ];
    //TODO: AT SOME POINT HAVE TO FIGURE OUT THE DEGREE CITY THING
    public function index(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        $university = Business::forCity($city, 'university');
        if (!$university) {
            return redirect()->route('city.show', $city->slug)->with('error', 'There is no University in this city.');
        }

        $university->load('owner');
        $degreesCfg = config('timers.degree_cycles', []);
        $charDegrees = $character->degrees ?? [];

        $degrees = collect($degreesCfg)->map(function ($needed, $code) use ($charDegrees, $city) {
            $d = $charDegrees[$code] ?? null;
            return [
                'code' => $code,
                'name' => ucfirst($code),
                'career' => self::DEGREE_CAREER_MAP[strtolower($code)] ?? null,
                'required_cycles' => $needed,
                'cycles' => $d['cycles'] ?? 0,
                'enrolled' => $d !== null,
                'completed' => !empty($d['completed_at']),
                'city_id' => $d['city_id'] ?? null,
                'is_local' => ($d['city_id'] ?? null) === $city->id,
            ];
        })->values();

        $activeDegrees = collect($charDegrees)->whereNull('completed_at');

        return Inertia::render('City/University', [
            'citySlug' => $city->slug,
            'university' => [
                'name' => $university->name,
                'image_url' => $university->image_url,
                'owner' => [
                    'name' => $university->owner?->display_name ?? 'GOVT. OPERATED',
                    'avatar_url' => $university->owner?->avatar_url,
                ],
            ],
            'degrees' => $degrees,
            'canEnroll' => $activeDegrees->count() < 2,
            'activeCityId' => $activeDegrees->first()['city_id'] ?? null,
            'tuition' => $university->getSetting('tuition', config('timers.default_tuition')),
            'isOwner' => $university->owner_id === $character->id,
        ]);
    }

    public function enroll(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);
        $code = $request->validate(['degree_code' => 'required|string'])['degree_code'];

        if (!config("timers.degree_cycles.$code")) {
            return back()->with('error', 'Invalid degree.');
        }

        if ($character->getDegree($code)) {
            return back()->with('error', 'You are already enrolled or finished this degree.');
        }

        $active = collect($character->degrees ?? [])->whereNull('completed_at');
        if ($active->count() >= 2) {
            return back()->with('error', 'You can only study two degrees or a degree and training at a time, take it easy you  overachiever!');
        }

        if ($active->isNotEmpty() && $active->first()['city_id'] !== $city->id) {
            return back()->with('error', 'You must continue your studies where you first enrolled.');
        }

        try {
            return DB::transaction(function () use ($character, $city, $code) {

                $uni = Business::forCity($city, 'university');
                $tuition = $uni?->getSetting('tuition', config('timers.default_tuition')) ?? 0;


                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

                if ($tuition > 0) {
                    if (!$character->removeCash($tuition, false)) {
                        return back()->with('error', "You need \${$tuition} on hand to enroll.");
                    }

                    $uni?->addBalance($tuition);
                }

                $character->enrollDegree($code, $city->id);
                return back()->with('success', "Enrolled in " . ucfirst($code) . " degree.");
            });
        } catch (\Throwable $e) {
            Log::error('Enrollment failed', [
                'user_id' => $request->user()->id,
                'degree' => $code,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->with('error', 'Enrollment failed. Please try again.');
        }
    }

    public function study(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);
        $code = $request->validate(['degree_code' => 'required|string'])['degree_code'];

        try {
            return DB::transaction(function () use ($character, $city, $code) {
                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

                if ($character->timers?->next_study_at?->isFuture()) {
                    return back()->with('error', 'You need to wait before studying again.');
                }

                if ($character->isHospitalized() || $character->isJailed() || !$character->isAlive()) {
                    return back()->with('error', 'Cannot study while incapacitated or dead.');
                }

                $degree = $character->getDegree($code);
                if (!$degree || !empty($degree['completed_at'])) {
                    return back()->with('error', 'Not enrolled or already completed.');
                }

                if ($degree['city_id'] !== $city->id) {
                    return back()->with('error', 'You must study at your university of enrollment, there is no study abroad or transfer program.');
                }

                $completed = $character->studyDegree($code);
                $character->timers()->update(['next_study_at' => now()->addSeconds(config('timers.study'))->getTimestamp()]);

                if ($completed) {
                    $career = ucfirst(self::DEGREE_CAREER_MAP[strtolower($code)] ?? $code);
                    return back()->with('success', "Degree completed! You can now start a career in {$career}.");
                }

                $required = config("timers.degree_cycles.$code", 100);
                $current = $character->getDegree($code)['cycles'] ?? 0;
                $newCycles = $current + 1;
                $percent = ($newCycles / $required) * 100;

                $message = 'You completed another successful study session.';
                if ($percent >= 90) {
                    $message = 'You meet with your academic advisor to prepare for your PhD defense.';
                } elseif ($percent >= 50) {
                    $message = 'You are making steady progress towards your degree.';
                }

                return back()->with('success', $message);
            });
        } catch (\Throwable $e) {
            Log::error('Study attempt failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'An error occurred. Please try again later.');
        }
    }
    //TODO figure out how career should be handled .... crossroads?
    public function startCareer(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);
        $code = $request->validate(['degree_code' => 'required|string'])['degree_code'];

        try {
            if ($character->homeCity->mayor_id === $character->id) {
                return back()->with('error', 'You cannot change careers while serving as Mayor.');
            }

            if ($character->corporation_id) {
                return back()->with('error', 'You must leave your corporation before starting a new career.');
            }

            $currentCode = $character->career?->code;

            if (strcasecmp($currentCode, 'police') == 0) {
                return back()->with('error', 'You cannot start a new career while serving on the force. Step down or force quit from settings instead.');
            }

            if (strcasecmp($currentCode, 'law') == 0) {
                return back()->with('error', 'You cannot start a new career while serving in the Judiciary. Step down or force quit from settings instead.');
            }

            $degree = $character->getDegree($code);
            if (!$degree || empty($degree['completed_at'])) {
                return back()->with('error', 'Degree not completed.');
            }


            if ($character->homeCity->id !== $city->id) {
                return back()->with('error', 'You can only start your career in your home city.');
            }


            $career = self::DEGREE_CAREER_MAP[strtolower($code)] ?? null;
            if (!$career) {
                return back()->with('error', 'Could not start career.');
            }

            if (!$character->startCareer($career)) {
                return back()->with('error', 'Could not start career.');
            }
            return back()->with('success', "You have successfully completed your " . ucfirst($code) . " degree and have begun your new career!");
        } catch (\Throwable $e) {
            Log::error('Start career failed', [
                'user_id' => $request->user()->id,
                'degree' => $code,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->with('error', 'Failed to start career. Please try again.');
        }
    }

    public function updateSettings(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);
        $uni = Business::forCity($city, 'university');

        if (!$uni || $uni->owner_id !== $character->id) {
            return back()->with('error', 'You do not own this university.');
        }

        if ((int) $request->input('tuition') <= 5000) {
            return back()->with('error', "You can't  make college that cheap, you socialist! - How about thinking of the poor student loan companies for once?");
        }

        $data = $request->validate([
            'tuition' => 'required|integer|min:0|max:' . config('timers.max_tuition'),
        ]);

        try {
            $uni->setSetting('tuition', $data['tuition']);
            return back()->with('success', 'Tuition updated to $' . number_format($data['tuition']) . '.');
        } catch (\Throwable $e) {
            Log::error('University settings update failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->with('error', 'Failed to update settings.');
        }
    }
}
