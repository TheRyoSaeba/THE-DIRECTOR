<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
//! T0D0 new talent saving face cfo only - shoot without losing influence  ceo should ideally choose packing punch or delivering highest crit hit 
class TalentController extends Controller
{
    public function index(Request $request)
    {
        $character = $this->requireCharacter($request);
        //! duration denotes how long the buff actually lasts  while cooldown is how long before you can reactivate

        $duration = config('timers.talent_duration');
        $cooldown = config('timers.talent_cooldown');

       
        $activeId       = null;
        $activeExpiry   = 0;
        if ($character->active_talent) {
            [$activeId, $activeExpiry] = array_pad(explode(':', $character->active_talent, 2), 2, 0);
            $activeExpiry = (int) $activeExpiry;
            if (now()->timestamp >= $activeExpiry) {
                $activeId = null; 
            }
        }

        $cooldownRemaining = $character->timers?->next_talents_at?->isFuture()
            ? $character->timers->next_talents_at->diffInSeconds(now())
            : 0;

        $buildTalent = function (string $id, string $name, string $desc, string $icon, bool $unlocked) use ($activeId, $activeExpiry, $cooldownRemaining, $duration, $cooldown): array {
            $isActive        = $activeId === $id;
            $activeRemaining = $isActive ? max(0, $activeExpiry - now()->timestamp) : 0;

            return [
                'id'               => $id,
                'name'             => $name,
                'description'      => $desc,
                'icon'             => $icon,
                'duration'         => $duration,
                'cooldown'         => $cooldown,
                'unlocked'         => $unlocked,
                'active'           => $isActive,
                'active_remaining' => $activeRemaining,   
                'on_cooldown'      => !$isActive && $cooldownRemaining > 0,
                'cooldown_remaining' => $isActive ? 0 : $cooldownRemaining,  
            ];
        };

        $talents = [
            $buildTalent(
                'over_educated',
                'Over Educated',
                'Your  academic obsession has rewired how you think. Your mind processes faster than most, Doubling your work output — Halves Work cooldown for 30 mins.',
                'GraduationCap',
                $character->hasAllDegrees()
            ),
            $buildTalent(
                'defense_in_depth',
                'Defense in Depth',
                'Your experience in the field has toughened you up, and you\'ve gotten better at reading an attacker\'s movements — Doubles your damage reduction for 30 mins, but does not protect against instant kills.',
                'ShieldChevron',
                $character->hasDefenseInDepthRequirements()
            ),
            $buildTalent(
                'goal_of_all_life',
                'The Goal of All Life is Death',
                'You have stared down death enough times to stop fearing it. When your back is up against the wall, you become deadlier than ever - At low health, your kill chances scales for 30 mins.',
                'Skull',
                $character->hasGoalOfAllLifeRequirements()
            ),
            $buildTalent(
                'lazarus_connection',
                'The Lazarus Connection',
                'Your medical expertise has helped you bring too many people back from the brink of death too many times and you have gained a knack for it. Gain one chance to revive a dead compatriot — at great cost to them.',
                'Heartbeat',
                $character->hasLazarusConnectionRequirements()
            ),
        ];

        return Inertia::render('Talents', [
            'talents'  => $talents,
            'duration' => $duration,
            'cooldown' => $cooldown,
        ]);
    }

    public function activate(Request $request)
    {
        $request->validate(['talent_id' => 'required|string']);

        $character = $request->user()->character;
        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        if (!$character->canActivateTalent()) {
            return back()->with('error', $character->hasTalentActive()
                ? 'You already have a talent active.'
                : 'Your talent is still on cooldown.');
        }

        $talentId = $request->input('talent_id');

        $talentConfig = match ($talentId) {
            'over_educated' => [
                'check' => fn () => $character->hasAllDegrees(),
                'message' => 'Over Educated activated! Your work cooldown is halved for ' . (config('timers.talent_duration') / 60) . ' minutes.',
            ],
            'defense_in_depth' => [
                'check' => fn () => $character->hasDefenseInDepthRequirements(),
                'message' => 'Defense in Depth activated! Your damage reduction is doubled for ' . (config('timers.talent_duration') / 60) . ' minutes.',
            ],
            'goal_of_all_life' => [
                'check'   => fn () => $character->hasGoalOfAllLifeRequirements(),
                'message' => 'The Goal of All Life is Death has been activated. Your kill chance increases for ' . (config('timers.talent_duration') / 60) . ' minutes.',
            ],
            'lazarus_connection' => [
                'check'   => fn () => $character->hasLazarusConnectionRequirements(),
                'message' => 'The Lazarus Connection is active. You may attempt to revive a fallen compatriot.',
            ],
            default => null,
        };

        if (!$talentConfig) {
            return back()->with('error', 'Unknown talent.');
        }

        if (!($talentConfig['check'])()) {
            return back()->with('error', 'You have not unlocked this talent.');
        }

        $duration  = config('timers.talent_duration');
        $cooldown  = config('timers.talent_cooldown');
        $expiresAt = now()->addSeconds($duration)->timestamp;

        
        
        $character->update(['active_talent' => "{$talentId}:{$expiresAt}"]);
        $character->timers()->update([
            'next_talents_at' => now()->addSeconds($duration + $cooldown)->getTimestamp(),
        ]);

        $talentColumn = match ($talentId) {
            'over_educated'      => 'talent_over_educated_used',
            'defense_in_depth'   => 'talent_defense_in_depth_used',
            'goal_of_all_life'   => 'talent_goal_of_all_life_used',
            'lazarus_connection' => 'talent_lazarus_connection_used',
            default              => null,
        };
        if ($talentColumn) {
            \App\Models\CharacterHistory::addHistory($character, $talentColumn);
        }

        return back()->with('success', $talentConfig['message']);
    }
}
