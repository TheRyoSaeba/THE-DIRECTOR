<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\CrimeRecord;
use App\Models\MayorTerm;
use App\Services\JournalService;
use App\Services\MayorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
//TODO wire up death penalty and martial law.

class PoliticsController extends Controller
{
    private function loadActiveTerm(Request $request): array|RedirectResponse
    {
        $character = $request->user()->character;
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

        return [$character, $city, $term];
    }

    public function budget(Request $request)
    {
        $result = $this->loadActiveTerm($request);
        if ($result instanceof RedirectResponse)
            return $result;
        [$character, $city, $term] = $result;

        $request->validate([
            'budget_law' => 'required|integer|min:0|max:100',
            'budget_corp_reg' => 'required|integer|min:0|max:100',
            'budget_services' => 'required|integer|min:0|max:100',
            'budget_bonds' => 'required|integer|min:0|max:100',
        ]);

        $sum = (int) $request->budget_law + (int) $request->budget_corp_reg
            + (int) $request->budget_services + (int) $request->budget_bonds;

        if ($sum !== 100) {
            return back()->with('error', "Budget must total exactly 100 points ({$sum} entered).");
        }

        try {
            return DB::transaction(function () use ($request, $term, $city) {
                DB::table('mayor_terms')->where('id', $term->id)->lockForUpdate()->first();

                $bondWarning = '';
                if ((int) $request->budget_bonds < 50 && $term->hasActiveBond()) {
                    $bondWarning = ' Warning: your active bond will default on your next page visit — dropping Bonds below 50% triggers an immediate default.';
                }

                $corpRegWarning = '';
                if ((int) $request->budget_corp_reg < 50 && $term->corp_regulation_active) {
                    $corpRegWarning = ' Your Corp Reg will be auto-disabled and your corporate tax rate zeroed since your budget is below 50%';
                }

                DB::table('mayor_terms')->where('id', $term->id)->update([
                    'budget_law' => (int) $request->budget_law,
                    'budget_corp_reg' => (int) $request->budget_corp_reg,
                    'budget_services' => (int) $request->budget_services,
                    'budget_bonds' => (int) $request->budget_bonds,
                ]);

                $term->refresh();
                if (!$term->corpRegUnlocked()) {
                    // Below the regulated gate — zero the corp tax AND disable
                    // the regulation policy. Without disabling the policy, a
                    // mayor with budget_corp_reg = 30 would still pass the
                    // audit gate check because audit() reads corp_regulation_active.
                    $term->setPolicy('corporate_tax_rate', 0);
                    $term->setPolicy('corp_regulation_active', false);
                    $city->syncPoliciesFromTerm($term);
                }

                return back()->with('success', 'Budget committed.' . $bondWarning . $corpRegWarning);
            });
        } catch (\Throwable $e) {
            Log::error('[Politics] Budget update failed.', [
                'character_id' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Budget update failed. Please try again.');
        }
    }

    public function taxes(Request $request)
    {
        $result = $this->loadActiveTerm($request);
        if ($result instanceof RedirectResponse)
            return $result;
        [$character, $city, $term] = $result;

        $request->validate([
            'income_tax_rate' => 'required|integer|min:5|max:25',
            'corporate_tax_rate' => 'required|integer|min:0|max:25',
        ]);

        $corpRate = (int) $request->corporate_tax_rate;
        if ($corpRate > 0 && !$term->corpRegUnlocked()) {
            return back()->with('error', 'Corp Reg budget must be ≥50% to apply corporate tax.');
        }

        try {
            $term->setPolicy('income_tax_rate', (int) $request->income_tax_rate);
            $term->setPolicy('corporate_tax_rate', $corpRate);
            $city->syncPoliciesFromTerm($term);

            \App\Models\CharacterHistory::addHistory($character, 'policies_enacted');

            return back()->with('success', 'Tax rates updated.');
        } catch (\Throwable $e) {
            Log::error('[Politics] Tax update failed.', [
                'character_id' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Tax update failed. Please try again.');
        }
    }

    public function enableCorpReg(Request $request)
    {
        $result = $this->loadActiveTerm($request);
        if ($result instanceof RedirectResponse)
            return $result;
        [$character, $city, $term] = $result;

        if ($term->corp_regulation_active) {
            return back()->with('error', 'Corporate regulation is already active.');
        }
        if ($term->budget_corp_reg < 50) {
            return back()->with('error', 'Corp Reg budget must be ≥50% to enable regulation.');
        }

        try {
            return DB::transaction(function () use ($term, $city, $character) {
                MayorTerm::where('id', $term->id)->lockForUpdate()->first();
                $term->refresh();

                if (!$term->deductFunds(MayorTerm::COST_ENABLE_CORP_REG)) {
                    return back()->with('error', 'Insufficient city funds ($25,000 required).');
                }

                $term->setPolicy('corp_regulation_active', true);
                $city->syncPoliciesFromTerm($term);

                \App\Models\CharacterHistory::addHistory($character, 'policies_enacted');

                return back()->with('success', 'Corporate regulation enabled. Audit actions now available.');
            });
        } catch (\Throwable $e) {
            Log::error('[Politics] Enable corp reg failed.', [
                'character_id' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Failed to enable corporate regulation. Please try again.');
        }
    }

    public function audit(Request $request)
    {
        $result = $this->loadActiveTerm($request);
        if ($result instanceof RedirectResponse)
            return $result;
        [$character, $city, $term] = $result;

        if (!$term->corp_regulation_active) {
            return back()->with('error', 'Corporate regulation must be enabled first.');
        }
        if ($term->auditInProgress()) {
            return back()->with('error', 'An audit is already in progress.');
        }
        //check if there is even a commissioner is in the city.

        try {
            if (!$term->deductFunds(MayorTerm::COST_AUDIT)) {
                return back()->with('error', 'Insufficient city funds ($75,000 required).');
            }

            $term->logAction('audits', [
                'status' => 'pending',
                'initiated_at' => now()->getTimestamp(),
                'initiated_period' => $term->period,
            ]);

            JournalService::custom(
                $city->mayor_id,
                'audit_initiated',
                ['message' => 'You have initiated a corporate audit. The Commissioner will assign an officer to execute it.']
            );

            return back()->with('success', 'Corporate audit initiated. The Commissioner has been notified.');
        } catch (\Throwable $e) {
            Log::error('[Politics] Audit initiation failed.', [
                'character_id' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Audit failed to initiate. Please try again.');
        }
    }

    public function bond(Request $request)
    {
        $result = $this->loadActiveTerm($request);
        if ($result instanceof RedirectResponse)
            return $result;
        [$character, $city, $term] = $result;

        if (!$term->bondsUnlocked()) {
            return back()->with('error', 'Bonds budget must be ≥50% to issue bonds.');
        }
        if ($term->hasActiveBond()) {
            return back()->with('error', 'A bond is already outstanding.');
        }

        $request->validate(['principal' => 'required|integer|min:' . MayorTerm::BOND_MIN]);
        $principal = (int) $request->principal;

        try {
            return DB::transaction(function () use ($term, $city, $principal, $character) {
                MayorTerm::where('id', $term->id)->lockForUpdate()->first();
                $term->refresh();

                $maxPrincipal = $term->maxBondPrincipal();
                if ($principal > $maxPrincipal) {
                    return back()->with('error', "Bond principal cannot exceed \${$maxPrincipal} (40% of treasury).");
                }
                if (!$term->deductFunds($principal)) {
                    return back()->with('error', 'Insufficient city funds.');
                }

                $crimeRate = (float) $city->fresh()->crime_rate;
                $yieldPct = MayorTerm::calculateBondYield($crimeRate);

                $term->setBond([
                    'principal' => $principal,
                    'yield_pct' => $yieldPct,
                    'matures_at' => now()->addHours(21)->getTimestamp(),
                    'issued_at' => now()->getTimestamp(),
                    'crime_at_issue' => $crimeRate,
                ]);

                \App\Models\CharacterHistory::addHistory($character, 'policies_enacted');

                return back()->with('success', sprintf(
                    'Bond issued: $%s principal at %.1f%% yield. Matures in 21 hours.',
                    number_format($principal),
                    $yieldPct
                ));
            });
        } catch (\Throwable $e) {
            Log::error('[Politics] Bond issuance failed.', [
                'character_id' => $character->id,
                'principal' => $principal,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Bond issuance failed. Please try again.');
        }
    }

    public function suppress(Request $request)
    {
        $result = $this->loadActiveTerm($request);
        if ($result instanceof RedirectResponse)
            return $result;
        [$character, $city, $term] = $result;

        $request->validate(['case_id' => 'required|integer']);

        try {
            return DB::transaction(function () use ($request, $city, $term) {
                $term = MayorTerm::where('id', $term->id)->lockForUpdate()->first();

                if (!$term || $term->ended_at !== null) {
                    return redirect()->route('dashboard')->with('error', 'Your term has ended.');
                }
                $record = CrimeRecord::where('id', $request->case_id)
                    ->where('city_id', $city->id)
                    ->lockForUpdate()
                    ->first();

                if (!$record || !$record->isActive()) {
                    return back()->with('error', 'Case not found or already resolved.');
                }

                if (!$term->deductFunds(MayorTerm::COST_SUPPRESS)) {
                    return back()->with('error', 'Insufficient city funds ($50,000 required).');
                }

                $record->suppress();
                \App\Models\City::increaseCrimeRateById($city->id, 3.0);

                $term->logAction('suppressions', [
                    'case_id' => $record->id,
                    'at' => now()->getTimestamp(),
                    'period' => $term->period,
                ]);

                $term = MayorService::applySuppressionAssemblyHit($term, $city);
                if ($term === null) {
                    return redirect()->route('dashboard')
                        ->with('error', 'The Assembly has lost confidence in your administration. Your term has ended.');
                }

                return back()->with('success', ' You have supressed this case');
            });
        } catch (\Throwable $e) {
            Log::error('[Politics] Case suppression failed.', [
                'character_id' => $character->id,
                'case_id' => $request->case_id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Suppression failed. Please try again.');
        }
    }

    public function pardon(Request $request)
    {
        $result = $this->loadActiveTerm($request);
        if ($result instanceof RedirectResponse)
            return $result;
        [$character, $city, $term] = $result;

        $request->validate(['character_id' => 'required|integer']);

        if (!$term->pardonCooldownPassed()) {
            return back()->with('error', 'Pardon is on a 10-hour cooldown.');
        }

        try {
            return DB::transaction(function () use ($request, $character, $city, $term) {
                $term = MayorTerm::where('id', $term->id)->lockForUpdate()->first();

                if (!$term || $term->ended_at !== null) {
                    return redirect()->route('dashboard')->with('error', 'Your term has ended.');
                }

                if (!$term->pardonCooldownPassed()) {
                    return back()->with('error', 'Pardon is on a 10-hour cooldown.');
                }

                $target = Character::where('id', $request->character_id)
                    ->where('city_id', $city->id)
                    ->alive()
                    ->lockForUpdate()
                    ->first();

                if (!$target) {
                    return back()->with('error', 'Character not found in this city.');
                }

                if (CrimeRecord::convictionCount($target->id) < 1) {
                    return back()->with('error', 'That character has no criminal record to pardon.');
                }

                if ($term->city_funds < MayorTerm::COST_PARDON) {
                    return back()->with('error', 'Insufficient city funds ($75,000 required).');
                }

                $cleared = CrimeRecord::pardonConvictionsFor($target->id);
                if ($cleared < 1) {
                    return back()->with('error', 'That character has no criminal record to pardon.');
                }

                $term->deductFunds(MayorTerm::COST_PARDON);

                $wasJailed = $target->isJailed();
                if ($wasJailed) {
                    $target->timers()->update(['jail_until' => null]);
                }

                \App\Models\City::increaseCrimeRateById($city->id, 1.0);

                $term->logAction('pardons', [
                    'character_id' => $target->id,
                    'character_name' => $target->display_name,
                    'at' => now()->getTimestamp(),
                ]);

                JournalService::custom($target->id, 'pardoned', [
                    'message' => "Mayor {$character->display_name} of {$city->name} has granted you a pardon. Your criminal record has been expunged." . ($wasJailed ? ' Your jail sentence has also  been commuted.' : ''),
                ]);

                \App\Models\CharacterHistory::addHistory($character, 'pardons_issued');

                Log::info('[Politics] Pardon issued.', [
                    'mayor' => $character->id,
                    'pardoned' => $target->id,
                    'city' => $city->id,
                    'records_cleared' => $cleared,
                ]);

                return back()->with('success', "{$target->display_name} has been pardoned.");
            });
        } catch (\Throwable $e) {
            Log::error('[Politics] Pardon failed.', [
                'character_id' => $character->id,
                'target_id' => $request->character_id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Pardon failed. Please try again.');
        }
    }

    public function dismissOfficer(Request $request)
    {
        $result = $this->loadActiveTerm($request);
        if ($result instanceof RedirectResponse)
            return $result;
        [$character, $city, $term] = $result;

        $request->validate(['character_id' => 'required|integer']);

        try {
            return DB::transaction(function () use ($request, $character, $city, $term) {
                $term = MayorTerm::where('id', $term->id)->lockForUpdate()->first();

                if (!$term || $term->ended_at !== null) {
                    return redirect()->route('dashboard')->with('error', 'Your term has ended.');
                }
                if (!$term->deductFunds(MayorTerm::COST_DISMISS_OFFICER)) {
                    return back()->with('error', 'Insufficient city funds ($50,000 required).');
                }

                $policeCareer = \App\Models\Career::findByCode('police');
                $target = Character::where('id', $request->character_id)
                    ->where('home_city_id', $city->id)
                    ->where('career_id', $policeCareer?->id)
                    ->whereBetween('career_rank', [1, 3])
                    ->alive()
                    ->lockForUpdate()
                    ->first();

                if (!$target) {
                    $term->addFunds(MayorTerm::COST_DISMISS_OFFICER);
                    return back()->with('error', 'Officer not found (must be rank 1–3 in this city).');
                }

                $target->quitCareer(preserveExp: false);
                \App\Models\City::increaseCrimeRateById($city->id, 2.0);

                $term->logAction('dismissals', [
                    'type' => 'officer',
                    'character_id' => $target->id,
                    'character_name' => $target->display_name,
                    'at' => now()->getTimestamp(),
                    'period' => $term->period,
                ]);

                JournalService::custom($target->id, 'dismissed_by', [
                    'dismissed_by' => $character->display_name,
                    'dismisser_rank_name' => 'Mayor',
                    'career_name' => 'Police Force',
                ]);

                \App\Models\CharacterHistory::addHistory($character, 'officers_dismissed');

                Log::info('[Politics] Officer dismissed.', [
                    'mayor' => $character->id,
                    'target' => $target->id,
                    'city' => $city->id,
                ]);

                return back()->with('success', "{$target->display_name} has been dismissed from the force.");
            });
        } catch (\Throwable $e) {
            Log::error('[Politics] Officer dismissal failed.', [
                'character_id' => $character->id,
                'target_id' => $request->character_id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Dismissal failed. Please try again.');
        }
    }

    public function dismissCommissioner(Request $request)
    {
        $result = $this->loadActiveTerm($request);
        if ($result instanceof RedirectResponse)
            return $result;
        [$character, $city, $term] = $result;

        $dismissals = $term->actions_log['dismissals'] ?? [];
        $commDismissed = collect($dismissals)->contains(fn($d) => ($d['type'] ?? '') === 'commissioner');
        if ($commDismissed) {
            return back()->with('error', 'You may only dismiss the Commissioner once per term.');
        }

        try {
            return DB::transaction(function () use ($character, $city, $term) {
                $term = MayorTerm::where('id', $term->id)->lockForUpdate()->first();

                if (!$term || $term->ended_at !== null) {
                    return redirect()->route('dashboard')->with('error', 'Your term has ended.');
                }

                $policeCareer = \App\Models\Career::findByCode('police');
                $commissioner = Character::where('home_city_id', $city->id)
                    ->where('career_id', $policeCareer?->id)
                    ->where('career_rank', 4)
                    ->alive()
                    ->lockForUpdate()
                    ->first();

                if (!$commissioner) {
                    return back()->with('error', 'No Commissioner found in this city.');
                }
                if (!$term->canDismissCommissioner($commissioner)) {
                    return back()->with('error', 'A qualified rank-3 officer must be ready for elevation before the Commissioner can be dismissed.');
                }
                if (!$term->deductFunds(MayorTerm::COST_DISMISS_COMM)) {
                    return back()->with('error', 'Insufficient city funds ($150,000 required).');
                }

                $commissioner->quitCareer(preserveExp: false);
                \App\Models\City::increaseCrimeRateById($city->id, 3.0);

                $term->logAction('dismissals', [
                    'type' => 'commissioner',
                    'character_id' => $commissioner->id,
                    'character_name' => $commissioner->display_name,
                    'at' => now()->getTimestamp(),
                    'period' => $term->period,
                ]);

                JournalService::custom($commissioner->id, 'dismissed_by', [
                    'dismissed_by' => $character->display_name,
                    'dismisser_rank_name' => 'Mayor',
                    'career_name' => 'Police Commissioner',
                ]);

                \App\Models\CharacterHistory::addHistory($character, 'officers_dismissed');

                Log::info('[Politics] Commissioner dismissed.', [
                    'mayor' => $character->id,
                    'commissioner' => $commissioner->id,
                    'city' => $city->id,
                ]);

                return back()->with('success', "{$commissioner->display_name} has been dismissed as Commissioner.");
            });
        } catch (\Throwable $e) {
            Log::error('[Politics] Commissioner dismissal failed.', [
                'character_id' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Dismissal failed. Please try again.');
        }
    }

    public function deathSentence(Request $request)
    {
        $result = $this->loadActiveTerm($request);
        if ($result instanceof RedirectResponse)
            return $result;
        [$character, $city, $term] = $result;

        if ($term->death_sentence_active) {
            return back()->with('error', 'Capital punishment is already enacted.');
        }

        try {
            return DB::transaction(function () use ($term, $city, $character) {
                MayorTerm::where('id', $term->id)->lockForUpdate()->first();
                $term->refresh();

                if (!$term->deductFunds(MayorTerm::COST_DEATH_SENTENCE)) {
                    return back()->with('error', 'Insufficient city funds ($100,000 required).');
                }

                $term->setPolicy('death_sentence_active', true);
                $city->syncPoliciesFromTerm($term);

                \App\Models\CharacterHistory::addHistory($character, 'policies_enacted');

                Log::info('[Politics] Death sentence enacted.', [
                    'mayor' => $character->id,
                    'city' => $city->id,
                ]);

                return back()->with('success', 'Capital punishment enacted. Capital convictions may now result in character death.');
            });
        } catch (\Throwable $e) {
            Log::error('[Politics] Death sentence enactment failed.', [
                'character_id' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Failed to enact capital punishment. Please try again.');
        }
    }

    public function resign(Request $request)
    {
        $result = $this->loadActiveTerm($request);
        if ($result instanceof RedirectResponse)
            return $result;
        [$character, $city, $term] = $result;

        try {
            MayorService::removeMayor($term, $city, MayorTerm::END_RESIGNED);

            Log::info('[Politics] Mayor resigned.', [
                'character_id' => $character->id,
                'city_id' => $city->id,
            ]);

            return redirect()->route('dashboard')->with('success', 'You have resigned as mayor.');
        } catch (\Throwable $e) {
            Log::error('[Politics] Mayor resignation failed.', [
                'character_id' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Resignation failed. Please try again.');
        }
    }
}
