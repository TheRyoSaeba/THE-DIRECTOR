<?php

namespace App\Http\Controllers;

use App\Events\CityEvents\CityEventDispatcher;
use App\Models\CareerEarn;
use App\Models\Character;
use App\Models\MayorTerm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class WorkController extends Controller
{
    private const SECRET_EARN_CODE = 'ransomware_attack';

    public function index(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return redirect()->route('character.create');
        }

        $jobs = CareerEarn::where('career_id', $character->career_id)
            ->where('code', '!=', self::SECRET_EARN_CODE)
            ->where('min_rank', '<=', $character->career_rank ?? 1)
            ->where('min_career_xp', '<=', $character->career_xp ?? 0)
            ->orderBy('min_career_xp')
            ->get(['id', 'title'])
            ->makeHidden(['career_id', 'min_rank', 'min_career_xp']);

        if ($character->getDegree('finance') && $character->getDegree('finance')['completed_at']) {
            $secretJob = CareerEarn::where('code', self::SECRET_EARN_CODE)->first(['id', 'title']);
            if ($secretJob) {
                $jobs->push($secretJob);
            }
        }
        return Inertia::render('Work', [
            'jobs' => $jobs,
        ]);
    }

    public function attempt(Request $request)
    {
        $request->validate(['earn_id' => 'required|integer']);

        $character = $request->user()->getLoadedCharacter();
        if (!$character) {
            return back()->with('error', 'No character found');
        }

        $earn = CareerEarn::find($request->earn_id);

        if (!$earn) {
            return back()->with('error', 'You cannot do this earn anymore!');
        }

        $isSecret = $earn->code === self::SECRET_EARN_CODE;

        if ($isSecret) {
            if (!$character->getDegree('finance') || !$character->getDegree('finance')['completed_at']) {
                return back()->with('error', 'You cannot do this earn yet!');
            }
        } else {
            if (!$earn->isAvailableFor($character)) {
                return back()->with('error', 'You cannot do this earn anymore!');
            }
        }

        if ($character->timers?->next_work_at?->isFuture()) {
            return back()->with('error', 'You need to wait before working again');
        }

        if ($character->isHospitalized() || !$character->isAlive() || $character->isJailed()) {
            return back()->with('error', 'You cannot work in such a state!');
        }

        $character->loadMissing(['homeCity']);

        if (!$isSecret) {
            $blockMessage = CityEventDispatcher::dispatchWork($character);
            if ($blockMessage !== null) {
                $character->timers()->update([
                    'next_work_at' => now()->addSeconds(config('timers.work'))->getTimestamp(),
                ]);
                return back()->with('error', $blockMessage);
            }
        }

        $success = $isSecret
            ? (mt_rand(1, 100) <= 75)
            : $earn->rollSuccess($character->career_xp ?? 0);

        $workCooldown = config('timers.work');
        if ($character->hasTalentActive('over_educated')) {
            $workCooldown = (int) ceil($workCooldown / 2);
        }
        $nextWorkAt = now()->addSeconds($workCooldown)->getTimestamp();

        if (!$success) {
            $character->timers()->update(['next_work_at' => $nextWorkAt]);
            return back()->with('error', $earn->getFormattedFailureMessage());
        }

        $payout = $earn->calculatePayout();
        $xp = $earn->calculateXpGain();

        $taxDeducted = 0;
        $incomeTaxDeducted = 0;
        $corpTaxDeducted = 0;
        $cityRevenue = 0;
        $activeTerm = null;

        if (!$isSecret) {
            $cityForTax = $character->homeCity ?? $character->city;

            if ($cityForTax) {
                $activeTerm = MayorTerm::activeForCity($cityForTax->id);

                if ($activeTerm) {
                    $policies = $activeTerm->getPolicies();
                    $incomeTax = (int) ($policies['income_tax_rate'] ?? 5);
                    $corpTax = 0;
                    $isCorpMember = $character->corporation_id !== null || $character->career_id === 12;

                    if ($activeTerm->corpRegUnlocked() && $isCorpMember) {
                        $corpTax = (int) ($policies['corporate_tax_rate'] ?? 0);
                    }

                    if ($isCorpMember && $activeTerm->budget_corp_reg < 25) {
                        $incomeTax = (int) floor($incomeTax * 0.60);
                    }

                    $combinedRate = min(50, $incomeTax + $corpTax);
                    $effectiveCorpTax = min($corpTax, max(0, $combinedRate - $incomeTax));
                    $effectiveIncomeTax = $combinedRate - $effectiveCorpTax;

                    $incomeTaxDeducted = (int) floor($payout * ($effectiveIncomeTax / 100));
                    $corpTaxDeducted = (int) floor($payout * ($effectiveCorpTax / 100));
                    $taxDeducted = $incomeTaxDeducted + $corpTaxDeducted;

                    if ($taxDeducted > 0) {
                        $payout -= $taxDeducted;
                        $cityRevenue = (int) floor($taxDeducted * $activeTerm->servicesMultiplier());
                    }
                }
            }
        }

        $statGains = [];
        $influenceGain = 0;
        $stats = $character->stats;
        if ($stats) {
            $statGains = $earn->ApplyStats();
            $influenceGain = $earn->calculateInfluenceGain();
        }

        DB::beginTransaction();
        try {
            $cashColumn = $isSecret ? 'dirty_cash' : 'cash_on_hand';
            $cashAmount = $payout;

            $locked = DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();
            if (!$locked) {
                DB::rollBack();
                return back()->with('error', 'No character found');
            }

            DB::table('characters')->where('id', $character->id)->update([
                $cashColumn => DB::raw("{$cashColumn} + {$cashAmount}"),
                'career_xp' => DB::raw("career_xp + {$xp}"),
                'total_character_exp' => DB::raw("total_character_exp + {$xp}"),
                'total_earns' => DB::raw('total_earns + 1'),
            ]);
            $character->{$cashColumn} += $cashAmount;
            $character->career_xp += $xp;
            $character->total_character_exp += $xp;
            $character->total_earns = (int) $character->total_earns + 1;

            if ($cityRevenue > 0 && $activeTerm !== null) {
                $activeTerm->addFunds($cityRevenue, 'tax');
            }

            DB::table('character_timers')
                ->where('character_id', $character->id)
                ->update(['next_work_at' => $nextWorkAt]);

            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error('Work attempt failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->with('error', 'An error occurred. Please try again later.');
        }

        if ($stats) {
            foreach ($statGains as $stat => $amount) {
                $method = 'add' . ucfirst($stat);
                if (method_exists($stats, $method)) {
                    $stats->$method($amount, false);
                }
            }
            if ($influenceGain != 0) {
                $stats->addInfluence($influenceGain);
            }
            $stats->save();
        }

        $character->incrementWorks24h();

        try {
            $user = $request->user();
            $user?->checkAchievementThresholds('earns', (int) $character->total_earns, $character);
        } catch (\Throwable $e) {
            Log::warning('Achievement threshold check failed after work.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id,
                'error' => $e->getMessage(),
            ]);
        }

        $message = $earn->getFormattedSuccessMessage($payout + $taxDeducted);

        if ($taxDeducted > 0) {
            $warning = $corpTaxDeducted > 0
                ? sprintf(
                    'The city took %s%s in income taxes from your earnings. You also paid %s%s in corp taxes.',
                    chr(36),
                    number_format($incomeTaxDeducted),
                    chr(36),
                    number_format($corpTaxDeducted)
                )
                : sprintf(
                    'The city took %s%s in taxes from your earnings.',
                    chr(36),
                    number_format($taxDeducted)
                );

            return back()->with([
                'success' => $message,
                'warning' => $warning,
            ]);
        }

        return back()->with('success', $message);
    }
}
