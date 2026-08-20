<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\CharacterJournal;
use App\Models\CrimeRecord;
use App\Models\DefenseOffer;
use App\Services\JournalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


//! TODO check for death penalty  on capital cases
class LawCaseController extends Controller
{
    
    
    

    public function prosecute(Request $request, int $id)
    {
        $character = $request->user()->character;

        if ($character->career_rank !== 2) {
            return back()->with('error', 'Only an Attorney may file charges.');
        }

        return DB::transaction(function () use ($character, $id) {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

            if (! $character->isAlive()) {
                return back()->with('error', 'You cannot work in your current state.');
            }
            if ($character->isHospitalized() || $character->isJailed()) {
                return back()->with('error', 'You cannot work while hospitalised.');
            }
            if ($character->timers?->next_action_at?->isFuture()) {
                return back()->with('error', 'You must wait before performing another action.');
            }

            $record = CrimeRecord::lockForUpdate()->find($id);

            if (! $record) {
                return back()->with('error', 'Case not found.');
            }
            if ($record->status !== CrimeRecord::STATUS_REFERRED) {
                return back()->with('error', 'This case is no longer available for prosecution.');
            }
             
            if ($record->isConflicted($character)) {
                return back()->with('error', 'You have a conflict of interest in this case.');
            }
            if ($record->city_id !== $character->home_city_id) {
                return back()->with('error', 'You can only prosecute cases in your home city.');
            }

            if (! $record->fileCharges($character)) {
                return back()->with('error', 'Unable to file charges. The chain of custody may have changed state.');
            }

            $character->addXp(50);

            \App\Models\CharacterHistory::addHistory($character, 'cases_prosecuted');
            \App\Models\CharacterHistory::addHistoryCaseType($character, $record->type);

            $character->timers()->update([
                'next_action_at' => now()->addSeconds(config('timers.action'))->getTimestamp(),
            ]);

            Log::info('[Law] Charges filed.', [
                'prosecutor' => $character->id,
                'case'       => $id,
                'severity'   => $record->severity,
            ]);

            return back()->with('success',
                "You have successfully filed charges on case #{$id}. The defendant(s) have been notified."
            );
        });
    }

    
    
    

    public function defend(Request $request, int $id)
    {
        $validated = $request->validate([
            'fee' => 'required|integer|min:1000|max:1000000',
        ]);

        $character = $request->user()->character;

        if ($character->career_rank !== 2) {
            return back()->with('error', 'Only an Attorney may take a defence case.');
        }

        return DB::transaction(function () use ($character, $id, $validated) {
            $attorney = Character::with(['timers'])->lockForUpdate()->find($character->id);

            if (! $attorney || ! $attorney->isAlive()) {
                return back()->with('error', 'You cannot work in your current state.');
            }
            if ($attorney->isHospitalized() || $attorney->isJailed()) {
                return back()->with('error', 'You cannot work while hospitalised.');
            }

            $record = CrimeRecord::lockForUpdate()->find($id);

            if (! $record) {
                return back()->with('error', 'Case not found.');
            }
            if ($record->city_id !== $attorney->city_id) {
                return back()->with('error', 'You cannot offer legal services on a case in a city you\'re not currently in.');
            }
            if ($record->status !== CrimeRecord::STATUS_CHARGED) {
                return back()->with('error', 'This case is no longer available for defence.');
            }

            if (isset(($record->data ?? [])['auto_charged_reason'])) {
                return back()->with('error', 'This case was automatically forwarded to a judge and is no longer open for defence.');
            }
            if ($record->isConflicted($attorney)) {
                return back()->with('error', 'You have a conflict of interest in this case.');
            }
            if ($record->prosecutor_id === $attorney->id) {
                return back()->with('error', 'You are the prosecutor on this case.');
            }

            if ($record->defense_id !== null) {
                return back()->with('error', 'This case already has retained counsel.');
            }

            $activeOffer = DefenseOffer::where('crime_record_id', $record->id)
                ->active()
                ->lockForUpdate()
                ->latest()
                ->first();

            if ($activeOffer) {
                if ($activeOffer->status === DefenseOffer::STATUS_ACCEPTED) {
                    return back()->with('error', 'This case already has retained counsel.');
                }

                $isStale = ! $activeOffer->created_at || $activeOffer->created_at->copy()->addMinutes(3)->isPast();
                if (! $isStale) {
                    return back()->with('error', 'A defence offer is already pending on this case.');
                }

                $activeOffer->update(['status' => DefenseOffer::STATUS_CANCELLED]);
            }

            $defendantId = $record->character_id;
            if (! $defendantId || ! Character::whereKey($defendantId)->exists()) {
                return back()->with('error', 'No active defendant can accept a defence offer for this case.');
            }

            $offer = DefenseOffer::create([
                'crime_record_id' => $record->id,
                'attorney_id' => $attorney->id,
                'defendant_id' => $defendantId,
                'fee' => (int) $validated['fee'],
                'status' => DefenseOffer::STATUS_PENDING,
            ]);

            $data = $record->data ?? [];
            unset($data['defense_pending'], $data['defense_pending_since']);
            $record->data = $data;
            $record->save();

            $prosecutor = $record->prosecutor_id
                ? Character::find($record->prosecutor_id)
                : null;

            JournalService::defenseRequest(
                $defendantId,
                $attorney->id,
                $attorney->display_name,
                $record->id,
                $record->typeLabel(),
                $record->type,
                $record->severity,
                $prosecutor?->display_name,
                $offer->id,
                (int) $validated['fee']
            );

            Log::info('[Law] Defence offer sent.', [
                'attorney'  => $attorney->id,
                'defendant' => $defendantId,
                'case'      => $id,
                'fee'       => (int) $validated['fee'],
            ]);

            return back()->with('success',
                "You have sent a defence offer for case #{$id}. The defendant must accept before you can argue the case."
            );
        });
    }

    
    
    

    public function acceptDefense(Request $request, CharacterJournal $journal)
    {
        $defendant = $request->user()->character;

        if (! $defendant) {
            return back()->with('error', 'No character found.');
        }

        return DB::transaction(function () use ($defendant, $journal) {
            $defendant = Character::lockForUpdate()->find($defendant->id);

            $journalData = $journal->data;
            $caseId      = (int) ($journalData['case_id']     ?? 0);
            $attorneyId  = (int) ($journalData['attorney_id'] ?? 0);
            $offerId     = (int) ($journalData['offer_id']    ?? 0);

            if (! $caseId || ! $attorneyId || ! $offerId || ! $defendant) {
                $journal->delete();
                return back()->with('error', 'Invalid defence request data.');
            }

            $record = CrimeRecord::lockForUpdate()->find($caseId);
            $offer = DefenseOffer::lockForUpdate()->find($offerId);

            if (! $record || ! $offer) {
                $journal->delete();
                return back()->with('error', 'The case no longer exists.');
            }
            if ($record->status !== CrimeRecord::STATUS_CHARGED) {
                $journal->delete();
                return back()->with('error', 'This case is no longer in the charged state.');
            }
            if ($offer->crime_record_id !== $record->id || $offer->attorney_id !== $attorneyId || $offer->defendant_id !== $defendant->id) {
                $journal->delete();
                return back()->with('error', 'The defence offer is no longer valid.');
            }
            if ($offer->status !== DefenseOffer::STATUS_PENDING) {
                $journal->delete();
                return back()->with('error', 'This defence offer has already been resolved.');
            }
            if ($record->character_id !== $defendant->id) {
                return back()->with('error', 'You are not the defendant in this case.');
            }
            if ($record->defense_id !== null) {
                $journal->delete();
                return back()->with('error', 'This case already has retained counsel.');
            }

            $attorney = Character::lockForUpdate()->find($attorneyId);
            if (! $attorney || ! $attorney->isAlive() || $attorney->career_rank !== 2) {
                $offer->update(['status' => DefenseOffer::STATUS_CANCELLED]);
                $journal->delete();
                return back()->with('error', 'The defence attorney is no longer available.');
            }

            $fee = (int) $offer->fee;
            $totalFunds = (int) $defendant->cash_on_hand + (int) $defendant->cash_in_bank;
            if ($totalFunds < $fee) {
                return back()->with('error',
                    'You need $' . number_format($fee) . ' in total funds to retain this defence counsel.'
                );
            }

            $fromHand = min($fee, (int) $defendant->cash_on_hand);
            $fromBank = min($fee - $fromHand, (int) $defendant->cash_in_bank);

            DB::table('characters')->where('id', $defendant->id)->update([
                'cash_on_hand' => DB::raw('cash_on_hand - ' . $fromHand),
                'cash_in_bank' => DB::raw('cash_in_bank - ' . $fromBank),
            ]);
            DB::table('characters')->where('id', $attorney->id)->increment('cash_on_hand', $fee);

            $recordData                    = $record->data ?? [];
            unset($recordData['defense_pending'], $recordData['defense_pending_since']);
            $record->data = $recordData;
            $record->defense_id = $attorney->id;
            $record->save();

            $offer->update([
                'status' => DefenseOffer::STATUS_ACCEPTED,
                'accepted_at' => now()->utc(),
            ]);

            $journal->delete();

            Log::info('[Law] Defendant accepted defence offer.', [
                'defendant' => $defendant->id,
                'attorney'  => $attorneyId,
                'case'      => $caseId,
                'fee'       => $fee,
            ]);

            JournalService::defenseRetained(
                $attorney->id,
                $defendant->display_name,
                $record->id,
                $record->typeLabel(),
                $fee
            );

            return back()->with('success',
                'You retained defence counsel for case #' . $record->id . '. Your attorney must now argue the case.'
            );
        });
    }

    
    
    

    public function declineDefense(Request $request, CharacterJournal $journal)
    {
        $defendant = $request->user()->character;

        if (! $defendant) {
            return back()->with('error', 'No character found.');
        }

        return DB::transaction(function () use ($defendant, $journal) {
            DB::table('characters')->where('id', $defendant->id)->lockForUpdate()->first();

            $data       = $journal->data;
            $caseId     = (int) ($data['case_id']     ?? 0);
            $attorneyId = (int) ($data['attorney_id'] ?? 0);
            $offerId    = (int) ($data['offer_id']    ?? 0);

            $record = $caseId ? CrimeRecord::lockForUpdate()->find($caseId) : null;
            $offer = $offerId ? DefenseOffer::lockForUpdate()->find($offerId) : null;

            if ($offer && $offer->defendant_id === $defendant->id && $offer->attorney_id === $attorneyId && $offer->status === DefenseOffer::STATUS_PENDING) {
                $offer->update(['status' => DefenseOffer::STATUS_DECLINED]);
            } elseif ($record && $record->defense_id === $attorneyId) {
                $record->clearDefensePending($attorneyId);
            }

            if ($caseId && $attorneyId && $record) {
                JournalService::defenseDeclined(
                    $attorneyId,
                    $defendant->display_name,
                    $record->id,
                    $record->typeLabel()
                );
            }

            $journal->delete();

            Log::info('[Law] Defendant declined defence offer.', [
                'defendant' => $defendant->id,
                'attorney'  => $attorneyId,
                'case'      => $caseId,
            ]);

            return back()->with('success',
                'You have declined the defence offer. The case is open for another attorney.'
            );
        });
    }

    
    
    

    public function executeDefense(Request $request, DefenseOffer $offer)
    {
        $character = $request->user()->character;
        $offerId = $offer->id;
        $recordId = $offer->crime_record_id;

        if ($character->career_rank !== 2) {
            return back()->with('error', 'Only an Attorney may argue a defence case.');
        }

        return DB::transaction(function () use ($character, $offerId, $recordId) {
            $record = CrimeRecord::lockForUpdate()->find($recordId);
            $offer = DefenseOffer::lockForUpdate()->find($offerId);
            $attorney = Character::with(['timers', 'stats', 'items.template', 'property', 'corporation'])
                ->lockForUpdate()
                ->find($character->id);

            if (! $attorney || ! $offer || $offer->attorney_id !== $attorney->id) {
                return back()->with('error', 'Defence offer not found or unauthorized.');
            }
            if ($offer->status !== DefenseOffer::STATUS_ACCEPTED) {
                return back()->with('error', 'This defence offer is not ready to execute.');
            }
            if (! $attorney->isAlive()) {
                return back()->with('error', 'You cannot work in your current state.');
            }
            if ($attorney->isHospitalized() || $attorney->isJailed()) {
                return back()->with('error', 'You cannot work while hospitalised.');
            }
            if ($attorney->timers?->next_action_at?->isFuture()) {
                return back()->with('error', 'You must wait before performing another action.');
            }

            if (! $record) {
                $offer->update(['status' => DefenseOffer::STATUS_CANCELLED]);
                return back()->with('error', 'The case no longer exists.');
            }
            if ($record->city_id !== $attorney->city_id) {
                return back()->with('error', 'You cannot go trial when the case is in a city you are not currently in!');
            }
            if ($record->status !== CrimeRecord::STATUS_CHARGED) {
                $offer->update(['status' => DefenseOffer::STATUS_CANCELLED]);
                return back()->with('error', 'This case is no longer in the charged state.');
            }
            if ($record->defense_id !== $attorney->id || $record->character_id !== $offer->defendant_id) {
                $offer->update(['status' => DefenseOffer::STATUS_CANCELLED]);
                return back()->with('error', 'The defence offer is no longer valid.');
            }
            if ($record->isConflicted($attorney) || $record->prosecutor_id === $attorney->id) {
                $offer->update(['status' => DefenseOffer::STATUS_CANCELLED]);
                return back()->with('error', 'You can no longer argue this case.');
            }

            $attorney->timers()->update([
                'next_action_at' => now()->addSeconds(config('timers.action'))->getTimestamp(),
            ]);

            return $this->runDefenseTrial($record, $attorney, $offer);
        });
    }

    public function judgeVerdict(Request $request, int $id)
    {
        $request->validate([
            'verdict'      => 'required|in:convict,acquit',
            'fine'         => 'required_if:verdict,convict|integer|min:0',
            'jail_seconds' => 'required_if:verdict,convict|integer|min:0',
        ]);

        $character = $request->user()->character;

        if ($character->career_rank < 3) {
            return back()->with('error', 'Only a District Judge may act as judge on a case.');
        }

        return DB::transaction(function () use ($character, $request, $id) {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

            if (! $character->isAlive()) {
                return back()->with('error', 'You cannot work in your current state.');
            }
            if ($character->isHospitalized() || $character->isJailed()) {
                return back()->with('error', 'You cannot work while hospitalised.');
            }
            if ($character->timers?->next_action_at?->isFuture()) {
                return back()->with('error', 'You must wait before performing another action.');
            }

            $record = CrimeRecord::lockForUpdate()->find($id);

            if (! $record) {
                return back()->with('error', 'Case not found.');
            }
            if ($record->status !== CrimeRecord::STATUS_CHARGED) {
                return back()->with('error', 'This case is not in the charged state.');
            }

            $isAutoCharged = isset(($record->data ?? [])['auto_charged_reason']);

            
            
            if (! $isAutoCharged && (! $record->charged_at || $record->charged_at->isAfter(now()->subHour()))) {
                return back()->with('error',
                    'You must give the suspect at least one hour to muster a defence.'
                );
            }

            if ($record->isConflicted($character)) {
                return back()->with('error', 'You have a conflict of interest in this case.');
            }
            if ($record->prosecutor_id === $character->id) {
                return back()->with('error', 'You prosecuted this case and cannot also judge it.');
            }
            if ($record->defense_id === $character->id) {
                return back()->with('error', 'You defended this case and cannot also judge it.');
            }
            if ($record->city_id !== $character->home_city_id) {
                return back()->with('error', 'You can only judge cases in your home city.');
            }

            if ($record->defense_id !== null) {
                $record->clearDefensePending();
                $record = CrimeRecord::lockForUpdate()->find($record->id);
            }

            if ($request->verdict === 'acquit') {
                if (! $record->acquit($character)) {
                    return back()->with('error', 'Unable to acquit the case at this time.');
                }
                $record->validateSuspectAccuracy();

                $character->addXp(75);

                \App\Models\CharacterHistory::addHistory($character, 'cases_acquitted');
                \App\Models\CharacterHistory::addHistoryCaseType($character, $record->type);

                $character->timers()->update([
                    'next_action_at' => now()->addSeconds(config('timers.action'))->getTimestamp(),
                ]);

                $rankLabel = $character->current_rank?->rank_name ?? 'District Judge';
                $judgeName = $character->display_name ?? 'The Court';
                $cityName  = $record->city?->name ?? 'an undisclosed city';
                $this->notifyOutcomeToAllParticipants($record, 'acquitted',
                    "{$rankLabel} {$judgeName} of {$cityName} has reviewed the charges of {$record->typeLabel()} "
                    . 'and has ruled in your favour. All charges have been dropped.'
                );

                Log::info('[Law] Judge acquitted (uncontested).', ['judge' => $character->id, 'case' => $id]);

                return back()->with('success', "You have successfully acquitted the defendant on Case #{$id} and the city has paid you for your work.");
            }

            if (! $record->convict($character)) {
                return back()->with('error', 'Unable to record conviction at this time.');
            }

            return $this->applyJudgeSentence($character, $record, $request);
        });
    }

    
    
    

    public function judgeSentence(Request $request, int $id)
    {
        $request->validate([
            'verdict'      => 'required|in:sentence,acquit',
            'fine'         => 'required_if:verdict,sentence|integer|min:0',
            'jail_seconds' => 'required_if:verdict,sentence|integer|min:0',
        ]);

        $character = $request->user()->character;

        if ($character->career_rank < 3) {
            return back()->with('error', 'Only a District Judge may sentence a convicted case.');
        }

        return DB::transaction(function () use ($character, $request, $id) {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

            if (! $character->isAlive()) {
                return back()->with('error', 'You cannot work in your current state.');
            }
            if ($character->isHospitalized() || $character->isJailed()) {
                return back()->with('error', 'You cannot work while hospitalised.');
            }
            if ($character->timers?->next_action_at?->isFuture()) {
                return back()->with('error', 'You must wait before performing another action.');
            }

            $record = CrimeRecord::lockForUpdate()->find($id);

            if (! $record) {
                return back()->with('error', 'Case not found.');
            }
            if ($record->status !== CrimeRecord::STATUS_CONVICTED) {
                return back()->with('error', 'This case is not awaiting sentencing.');
            }
            if ($record->isConflicted($character)) {
                return back()->with('error', 'You have a conflict of interest in this case.');
            }
            if ($record->prosecutor_id === $character->id) {
                return back()->with('error', 'You prosecuted this case and cannot also sentence it.');
            }
            if ($record->defense_id === $character->id) {
                return back()->with('error', 'You defended this case and cannot also sentence it.');
            }
            if ($record->city_id !== $character->home_city_id) {
                return back()->with('error', 'You can only sentence cases in your home city.');
            }

            if ($record->defense_id !== null) {
                $record->clearDefensePending();
                $record = CrimeRecord::lockForUpdate()->find($record->id);
            }

            if ($request->verdict === 'acquit') {
                if (! $record->acquit($character)) {
                    return back()->with('error', 'Unable to process acquittal.');
                }
                $record->validateSuspectAccuracy();

                \App\Models\CharacterHistory::addHistory($character, 'cases_acquitted');
                \App\Models\CharacterHistory::addHistoryCaseType($character, $record->type);

                $character->timers()->update([
                    'next_action_at' => now()->addSeconds(config('timers.action'))->getTimestamp(),
                ]);

                $rankLabel = $character->current_rank?->rank_name ?? 'District Judge';
                $judgeName = $character->display_name ?? 'The Court';
                $cityName  = $record->city?->name ?? 'an undisclosed city';
                $this->notifyOutcomeToAllParticipants($record, 'acquitted',
                    "{$rankLabel} {$judgeName} of {$cityName} has overturned the conviction for {$record->typeLabel()} "
                    . 'and acquitted you. All charges have been dropped.'
                );

                Log::info('[Law] Judge acquitted (convicted case).', ['judge' => $character->id, 'case' => $id]);

                return back()->with('success', "You have successfully acquitted the defendant on Case #{$id}. I hope you know what you're doing!");
            }

            return $this->applyJudgeSentence($character, $record, $request);
        });
    }

    
    
    

    public function appeal(Request $request, int $id)
    {
        $character = $request->user()->character;

        return DB::transaction(function () use ($character, $id) {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

            $record = CrimeRecord::lockForUpdate()->find($id);

            if (! $record) {
                return back()->with('error', 'Case not found.');
            }
            if ($record->character_id !== $character->id) {
                return back()->with('error', 'You can only appeal your own case.');
            }
            if ($record->severity === CrimeRecord::SEV_MISDEMEANOR) {
                return back()->with('error', 'Misdemeanor convictions are final and cannot be appealed.');
            }
            if (isset(($record->data ?? [])['appeal'])) {
                return back()->with('error', "This case has already been through appeal. The Chief Justice's ruling is final.");
            }
            if (! $record->appeal()) {
                return back()->with('error', 'This case cannot be appealed in its current state.');
            }

            Log::info('[Law] Appeal filed.', [
                'character' => $character->id,
                'case'      => $id,
                'severity'  => $record->severity,
            ]);

            return back()->with('success', "Your appeal for case #{$record->id} ({$record->typeLabel()}) has been filed. A Chief Justice will review the original verdict.");
        });
    }

    
    
    

    public function resolveAppeal(Request $request, int $id)
    {
        $request->validate([
            'upheld' => 'required|boolean',
        ]);

        $character = $request->user()->character;

        if ($character->career_rank < 4) {
            return back()->with('error', 'Only a Chief Justice may rule on appeals.');
        }

        return DB::transaction(function () use ($character, $request, $id) {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

            if (! $character->isAlive()) {
                return back()->with('error', 'You cannot work in your current state.');
            }
            if ($character->isHospitalized() || $character->isJailed()) {
                return back()->with('error', 'You cannot work while hospitalised.');
            }
            if ($character->timers?->next_action_at?->isFuture()) {
                return back()->with('error', 'You must wait before performing another action.');
            }

            $record = CrimeRecord::lockForUpdate()->find($id);

            if (! $record) {
                return back()->with('error', 'Case not found.');
            }
            if ($record->status !== CrimeRecord::STATUS_APPEALED) {
                return back()->with('error', 'This case is not under appeal.');
            }
            if ($record->isConflicted($character)) {
                return back()->with('error', 'You have a conflict of interest in this case.');
            }
            if ($record->prosecutor_id === $character->id) {
                return back()->with('error', 'You prosecuted this case and cannot rule on its appeal.');
            }
            if ($record->defense_id === $character->id) {
                return back()->with('error', 'You defended this case and cannot rule on its appeal.');
            }
            if ($record->city_id !== $character->home_city_id) {
                return back()->with('error', 'You can only rule on appeals in your home city.');
            }

            $upheld = (bool) $request->upheld;

            if (! $record->resolveAppeal($character, $upheld)) {
                return back()->with('error', 'You cannot rule on a case you have already adjudicated.');
            }

            $character->timers()->update([
                'next_action_at' => now()->addSeconds(config('timers.action'))->getTimestamp(),
            ]);

            $wasCorrect = $record->validateAppealAccuracy($character, $upheld);

            \App\Models\CharacterHistory::addHistory($character, 'cases_appealed');
            \App\Models\CharacterHistory::addHistoryCaseType($character, $record->type);

            $chiefJusticeName = $character->display_name ?? 'The Chief Justice';
            $cityName         = $record->city?->name ?? 'an undisclosed city';
            $outcomeText = $upheld
                ? "Chief Justice {$chiefJusticeName} of {$cityName} has denied your appeal. The original conviction for "
                    . "{$record->typeLabel()} stands and your sentence remains in effect."
                : "Chief Justice {$chiefJusticeName} of {$cityName} has granted your appeal. The conviction for "
                    . "{$record->typeLabel()} has been overturned. You are free.";

            $this->notifyOutcomeToAllParticipants($record, $upheld ? 'sentenced' : 'acquitted', $outcomeText, 'appeal_outcome');

            $ruling          = $upheld ? 'upheld' : 'overturned';
            $correctnessNote = $wasCorrect
                ? ' Your ruling was the right choice. Justice was truly served, and Beccaria would be proud.'
                : ' Your ruling has been recorded, but you may want to review your notes.';

            Log::info('[Law] Appeal resolved.', [
                'chief_justice' => $character->id,
                'case'          => $id,
                'upheld'        => $upheld,
                'was_correct'   => $wasCorrect,
            ]);

            return back()->with('success',
                "You have successfully ruled on Case #{$id} and the conviction was {$ruling}.{$correctnessNote}"
            );
        });
    }

    
    
    

    private function runDefenseTrial(
        CrimeRecord $record,
        ?Character  $attorney,
        ?DefenseOffer $offer = null
    ): \Illuminate\Http\RedirectResponse {
        if (! $attorney) {
            $record->clearDefensePending($record->defense_id ?? 0);
            return back()->with('error',
                'The defence attorney could not be located. The case has been released for another attorney.'
            );
        }

        $attorney->loadMissing(['stats', 'items.template', 'property', 'corporation']);
        $attorney->stats?->setRelation('character', $attorney);

        $prosecutor = $record->prosecutor_id
            ? Character::with(['stats', 'items.template', 'property', 'corporation'])
                ->find($record->prosecutor_id)
            : null;

        
        if (! $prosecutor || ! $prosecutor->stats || ! $attorney->stats) {
            $record->resolveFromDefense($attorney, true);

            $attorney->addXp(50);

            DB::table('characters')->where('id', $attorney->id)
                ->increment('cash_on_hand', 500);

            $this->notifyTrialParticipants($record, 'acquitted', $attorney);
            $this->markDefenseOfferExecuted($offer);

            Log::info('[Law] Defence wins uncontested (prosecutor absent).', [
                'defense'       => $attorney->id,
                'case'          => $record->id,
            ]);

            return back()->with('success', "The prosecutor didn't show up for case #{$record->id}. Your client was succesfully acquited and now walks free!");
        }

        $prosecutor->stats->setRelation('character', $prosecutor);

        
       $prosecutorCases = (int) DB::table('character_histories')
        ->where('character_id', $prosecutor->id)
        ->value('cases_prosecuted') ?? 0;
         $defenderCases = (int) DB::table('character_histories')
        ->where('character_id', $attorney->id)
        ->value('cases_defended') ?? 0;

 
    $prosecutorExp = log10(max(1, $prosecutorCases)) * 10;
    $defenderExp   = log10(max(1, $defenderCases)) * 10;

 
    $prosecutorXpFactor = min(25, $prosecutor->career_xp / 5000);
    $defenderXpFactor   = min(35, $attorney->career_xp   / 5000);

    $prosecutorBasePower = $prosecutorExp + $prosecutorXpFactor;
    $defenderBasePower   = $defenderExp + $defenderXpFactor;

    $evidenceFactor = max(1, $record->evidence_level) / 25; 
    $adjustedProsecutorPower = $prosecutorBasePower * $evidenceFactor;

    $denominator = max(1, $defenderBasePower + $adjustedProsecutorPower);
    $defenseChance = (int) round(
        max(10, min(90, ($defenderBasePower / $denominator) * 100))
    );

        $defenseWins = mt_rand(1, 100) <= $defenseChance;

        Log::info('[Law] Defence trial resolved.', [
            'defense'        => $attorney->id,
            'case'           => $record->id,
            'severity'       => $record->severity,
            'defense_chance' => $defenseChance,
            'outcome'        => $defenseWins ? 'ACQUITTED' : 'CONVICTED',
        ]);

        if ($defenseWins) {
            $record->resolveFromDefense($attorney, true);

            [$minCash, $maxCash, $minXp, $maxXp] = match ($record->severity) {
                CrimeRecord::SEV_CAPITAL => [2_000, 4_000, 200, 400],
                CrimeRecord::SEV_FELONY  => [1_000, 2_000, 100, 250],
                default                  => [500,   1_000,  50, 150],
            };

            $factor = max(0.3, $record->evidence_level / 100);
            $cash   = (int) round(mt_rand($minCash, $maxCash) * $factor);
            $xp     = (int) round(mt_rand($minXp, $maxXp) * $factor);
            $stat   = match ($record->severity) {
                CrimeRecord::SEV_CAPITAL => mt_rand(100, 150),
                CrimeRecord::SEV_FELONY  => mt_rand(50, 75),
                default                  => mt_rand(25, 50),
            };

            if ($stat > 0) {
                $attorney->stats?->addIntelligence($stat,true);
            }

            $attorney->addXp($xp);
            DB::table('characters')->where('id', $attorney->id)
                ->increment('cash_on_hand', $cash);

            \App\Models\CharacterHistory::addHistory($attorney, 'cases_defended');
            \App\Models\CharacterHistory::addHistory($attorney, 'earned_career', $cash);
            \App\Models\CharacterHistory::addHistoryCaseType($attorney, $record->type);

            $this->notifyTrialParticipants($record, 'acquitted', $attorney);
            $this->markDefenseOfferExecuted($offer);

            return back()->with('success', "You argued a good case and managed to acquit your client. Well done!");
        }

        $record->resolveFromDefense($attorney, false);

        [$minXp, $maxXp] = match ($record->severity) {
            CrimeRecord::SEV_CAPITAL => [100, 200],
            CrimeRecord::SEV_FELONY  => [50,  100],
            default                  => [10,   50],
        };
        $factor = max(0.3, $record->evidence_level / 100);
        $attorney->addXp((int) round(mt_rand($minXp, $maxXp) * $factor));

        $this->notifyTrialParticipants($record, 'convicted', $attorney);
        $this->markDefenseOfferExecuted($offer);

        return back()->with('error', "Unfortunately, after hours in court you were unable to keep your client from being convicted, their case has been sent to a district judge!");
 
    }

    private function markDefenseOfferExecuted(?DefenseOffer $offer): void
    {
        if ($offer && $offer->status === DefenseOffer::STATUS_ACCEPTED) {
            $offer->update([
                'status' => DefenseOffer::STATUS_EXECUTED,
                'executed_at' => now()->utc(),
            ]);
        }
    }


    private function applyJudgeSentence(
        Character   $judge,
        CrimeRecord $record,
        Request     $request
    ): \Illuminate\Http\RedirectResponse {
        $fine        = (int) max(0, (int) ($request->fine         ?? 0));
        $jailSeconds = (int) max(0, (int) ($request->jail_seconds ?? 0));

        $bounds = CrimeRecord::sentenceBounds($record->severity);

        $fmtH = static function (int $s): string {
            $h = intdiv($s, 3600);
            $m = intdiv($s % 3600, 60);
            $hLabel = $h === 1 ? 'hour' : 'hours';
            $mLabel = $m === 1 ? 'minute' : 'minutes';
            if ($h > 0 && $m > 0) return "{$h} {$hLabel} {$m} {$mLabel}";
            if ($h > 0)           return "{$h} {$hLabel}";
            return "{$m} {$mLabel}";
        };

        if ($fine < $bounds['min_fine']) {
            return back()->with('error',
                'The city cannot allow that, fines must be at least $' . number_format($bounds['min_fine'])
                . " for a {$record->severity} charge!"
            );
        }
        if ($fine > $bounds['max_fine']) {
            return back()->with('error',
                'The fines cannot exceed $' . number_format($bounds['max_fine'])
                . " for a {$record->severity} charge!"
            );
        }
        if ($jailSeconds > $bounds['max_jail']) {
            return back()->with('error',
                'You cannot imprison for more than ' . $fmtH($bounds['max_jail'])
                . " for a {$record->severity} charge."
            );
        }

        $priorConvictions = $record->character_id
            ? CrimeRecord::convictionCount((int) $record->character_id)
            : 0;
        $isCapitalCase = $record->severity === CrimeRecord::SEV_CAPITAL;

        if ($jailSeconds > 0 && ! $isCapitalCase && $priorConvictions < 10) {
            return back()->with('error',
                'The defendant does not have a severe enough record for jail time. Issue a fine instead.'
            );
        }

        if ($bounds['min_jail'] > 0 && $jailSeconds < $bounds['min_jail']) {
            return back()->with('error',
                "A {$record->severity} conviction requires at least {$fmtH($bounds['min_jail'])} imprisonment!"
            );
        }

        if (! $record->applySentence($judge, ['fine' => $fine, 'jail_seconds' => $jailSeconds])) {
            return back()->with('error', 'Unable to apply sentence. Case may have been modified.');
        }

        $fmtSentence = static function (int $fine, int $jailSecs) use ($fmtH): string {
            if ($jailSecs > 0 && $fine > 0)
                return $fmtH($jailSecs) . ' imprisonment and a fine of $' . number_format($fine);
            if ($jailSecs > 0) return $fmtH($jailSecs) . ' imprisonment';
            if ($fine > 0)     return 'a fine of $' . number_format($fine);
            return 'a suspended sentence';
        };

        $sentenceSummary = $fmtSentence($fine, $jailSeconds);
        $judgeLabel      = $judge->current_rank?->rank_name ?? 'District Judge';
        $judgeName       = $judge->display_name ?? 'The Court';
        $cityName        = $record->city?->name ?? 'The city';
        $defendantName   = $record->character_id
            ? (Character::find($record->character_id)?->display_name ?? 'the defendant')
            : 'the defendant';

        $allConvictedIds = $record->partyIds();

        $canAppeal = $record->severity !== CrimeRecord::SEV_MISDEMEANOR;

        foreach ($allConvictedIds as $pid) {
            $isLead = ((int) $pid) === ((int) $record->character_id);
            $role   = $isLead ? '' : ' as a co-conspirator';

            $parts = [
                "{$judgeLabel} {$judgeName} of {$cityName} has convicted you{$role} in the case of {$record->typeLabel()}.",
                'A warrant has been issued for your arrest. You can turn yourself in at the Police HQ.',
            ];

            if ($isLead) {
                $parts[] = $canAppeal
                    ? 'As this is a ' . $record->severity . ' charge, you have the right to appeal before enforcement.'
                    : 'Misdemeanor sentences are final and cannot be appealed.';
            } else {
                $parts[] = $canAppeal
                    ? 'The lead defendant may file an appeal on your behalf before enforcement.'
                    : 'Misdemeanor sentences are final and cannot be appealed.';
            }

            JournalService::custom((int) $pid, 'case_sentenced', [
                'case_id'      => $record->id,
                'crime_type'   => $record->typeLabel(),
                'severity'     => $record->severity,
                'fine'         => $fine,
                'jail_seconds' => $jailSeconds,
                'can_appeal'   => $canAppeal,
                'message'      => implode(' ', $parts),
            ]);
        }

        $record->validateSuspectAccuracy();

        $judge->addXp(100);

        \App\Models\CharacterHistory::addHistory($judge, 'cases_sentenced');
        \App\Models\CharacterHistory::addHistoryCaseType($judge, $record->type);

        $judge->timers()->update([
            'next_action_at' => now()->addSeconds(config('timers.action'))->getTimestamp(),
        ]);

        return back()->with('success',
            "You have successfully sentenced {$defendantName} to {$sentenceSummary} for the crime of {$record->typeLabel()}. "
            . 'They must turn themselves in or be arrested in order to face justice, but the city has paid you for your hard work regardless.'
        );
    }

    
    
    

    private function notifyTrialParticipants(CrimeRecord $record, string $outcome, Character $attorney): void
    {
        $acquitted    = $outcome === 'acquitted';
        $crimeLabel   = $record->typeLabel();
        $defenseLabel = $attorney->display_name;
        $cityName     = $record->city?->name ?? 'The City';

        if ($acquitted) {
            $message = "The court of {$cityName} has found you not guilty of {$crimeLabel}. "
                . "Your defence attorney {$defenseLabel} argued successfully on your behalf. All charges have been dropped.";
        } else {
            $message = "Despite arguments from your attorney {$defenseLabel}, "
                . "the court of {$cityName} was not convinced and has found you guilty of {$crimeLabel}."
                . ($record->severity !== CrimeRecord::SEV_MISDEMEANOR
                    ? ' The case now proceeds to sentencing. You will have the right to appeal after sentencing.'
                    : ' The case now proceeds to sentencing. Misdemeanor sentences are final.');
        }

        $this->notifyOutcomeToAllParticipants($record, $outcome, $message);
    }

    private function notifyOutcomeToAllParticipants(
        CrimeRecord $record,
        string      $outcome,
        string      $message,
        string      $journalType = 'case_outcome'
    ): void {
        $notified = [];

        foreach ($record->partyIds() as $pid) {
            if (! in_array((int) $pid, $notified, true)) {
                JournalService::custom((int) $pid, $journalType, [
                    'case_id' => $record->id,
                    'outcome' => $outcome,
                    'message' => $message,
                ]);
                $notified[] = (int) $pid;
            }
        }
    }
}
