<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\Corporation;
use App\Models\CorporationTrustVote;
use App\Models\CorporationTrustVoteBallot;
use App\Services\JournalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CorporationVotingController extends Controller
{
    public function startTrustVote(Request $request)
    {
        $initialCharacter = $request->user()?->getLoadedCharacter();

        if (!$initialCharacter) {
            return back()->with('error', 'No character found.');
        }

        try {
            return DB::transaction(function () use ($initialCharacter) {
                $character = Character::where('id', $initialCharacter->id)->lockForUpdate()->first();

                if (!$character || $character->isHospitalized() || $character->isJailed()) {
                    return back()->with('error', 'You cannot open a Director vote right now.');
                }

                $holding = $character->corporation_id
                    ? Corporation::where('id', $character->corporation_id)->lockForUpdate()->first()
                    : null;

                if (!$holding || !$holding->isBoardMember($character)) {
                    return back()->with('error', 'Only holding-company board members can open a Director vote.');
                }

                $existingVote = CorporationTrustVote::pending()
                    ->where('holding_company_id', $holding->id)
                    ->lockForUpdate()
                    ->first();

                $pendingPromotion = CorporationTrustVote::promotionPending()
                    ->where('holding_company_id', $holding->id)
                    ->lockForUpdate()
                    ->first();

                if ($existingVote || $pendingPromotion) {
                    return back()->with('error', 'A Director vote is already active.');
                }

                $boardMembers = CorporationController::lockTrustVoteState($holding);
                if ($blocker = $holding->trustVoteReadinessBlocker($boardMembers)) {
                    return back()->with('error', $blocker);
                }

                $vote = CorporationTrustVote::create([
                    'holding_company_id' => $holding->id,
                    'initiated_by_id' => $character->id,
                    'status' => CorporationTrustVote::STATUS_PENDING,
                ]);

                foreach ($boardMembers as $boardMember) {
                    JournalService::custom($boardMember->id, 'corporation_trust_vote_opened', [
                        'holding_name' => $holding->name,
                        'initiator_name' => $character->display_name,
                        'vote_id' => $vote->id,
                    ]);
                }

                return back()->with('success', 'Director vote opened.');
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Trust vote start failed.', [
                'user_id' => $request->user()?->id,
                'character_id' => $initialCharacter->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Could not open the Director vote.');
        }
    }

    public function castTrustVote(Request $request)
    {
        $request->validate([
            'candidate_id' => 'required|integer|exists:characters,id',
        ]);

        $initialCharacter = $request->user()?->getLoadedCharacter();

        if (!$initialCharacter) {
            return back()->with('error', 'No character found.');
        }

        try {
            return DB::transaction(function () use ($initialCharacter, $request) {
                $character = Character::where('id', $initialCharacter->id)->lockForUpdate()->first();

                if (!$character || $character->isHospitalized() || $character->isJailed()) {
                    return back()->with('error', 'You cannot vote right now.');
                }

                $holding = $character->corporation_id
                    ? Corporation::where('id', $character->corporation_id)->lockForUpdate()->first()
                    : null;

                if (!$holding || !$holding->isBoardMember($character)) {
                    return back()->with('error', 'Only holding-company board members can vote.');
                }

                $vote = CorporationTrustVote::pending()
                    ->where('holding_company_id', $holding->id)
                    ->lockForUpdate()
                    ->first();

                $boardMembers = CorporationController::lockTrustVoteState($holding);
                if ($blocker = $holding->trustVoteReadinessBlocker($boardMembers)) {
                    $vote?->update(['status' => CorporationTrustVote::STATUS_CANCELLED]);
                    return back()->with('error', $blocker);
                }

                $candidateId = (int) $request->candidate_id;
                if ($candidateId === (int) $character->id) {
                    return back()->with('error', 'You cannot vote for yourself.');
                }

                $candidate = $boardMembers->firstWhere('id', $candidateId);
                if (!$candidate || !$holding->isEligibleTrustVoter($candidate)) {
                    return back()->with('error', 'Choose a valid board candidate.');
                }

                if (!$vote) {
                    $pendingPromotion = CorporationTrustVote::promotionPending()
                        ->where('holding_company_id', $holding->id)
                        ->lockForUpdate()
                        ->first();

                    if ($pendingPromotion) {
                        return back()->with('error', 'A Director has already secured the board mandate.');
                    }

                    $vote = CorporationTrustVote::create([
                        'holding_company_id' => $holding->id,
                        'initiated_by_id' => $character->id,
                        'status' => CorporationTrustVote::STATUS_PENDING,
                    ]);

                    foreach ($boardMembers as $boardMember) {
                        JournalService::custom($boardMember->id, 'corporation_trust_vote_opened', [
                            'holding_name' => $holding->name,
                            'initiator_name' => $character->display_name,
                            'vote_id' => $vote->id,
                        ]);
                    }
                }

                $alreadyVoted = CorporationTrustVoteBallot::where('trust_vote_id', $vote->id)
                    ->where('voter_id', $character->id)
                    ->lockForUpdate()
                    ->first();

                if ($alreadyVoted) {
                    return back()->with('error', 'You already voted in this attempt.');
                }

                CorporationTrustVoteBallot::create([
                    'trust_vote_id' => $vote->id,
                    'voter_id' => $character->id,
                    'candidate_id' => $candidate->id,
                ]);

                $ballots = CorporationTrustVoteBallot::where('trust_vote_id', $vote->id)
                    ->lockForUpdate()
                    ->get(['candidate_id', 'voter_id']);

                $requiredVotes = CorporationTrustVote::requiredVotesFor($boardMembers->count());
                $winnerId = $ballots
                    ->groupBy('candidate_id')
                    ->map->count()
                    ->filter(fn(int $count) => $count >= $requiredVotes)
                    ->keys()
                    ->first();

                if ($winnerId) {
                    $winner = $boardMembers->firstWhere('id', (int) $winnerId);
                    $vote->update([
                        'status' => CorporationTrustVote::STATUS_COMPLETED,
                        'winner_id' => $winner?->id,
                        'completed_at' => now(),
                    ]);

                    foreach ($boardMembers as $boardMember) {
                        JournalService::custom($boardMember->id, 'corporation_trust_vote_completed', [
                            'holding_name' => $holding->name,
                            'winner_name' => $winner?->display_name,
                            'vote_id' => $vote->id,
                        ]);
                    }

                    return back()->with('success', 'Director vote completed.');
                }

                if ($ballots->count() >= $boardMembers->count()) {
                    $vote->update([
                        'status' => CorporationTrustVote::STATUS_FAILED,
                        'completed_at' => now(),
                    ]);

                    foreach ($boardMembers as $boardMember) {
                        JournalService::custom($boardMember->id, 'corporation_trust_vote_failed', [
                            'holding_name' => $holding->name,
                            'vote_id' => $vote->id,
                        ]);
                    }

                    return back()->with('error', 'Director vote closed without a majority.');
                }

                return back()->with('success', 'Vote recorded.');
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Trust vote cast failed.', [
                'user_id' => $request->user()?->id,
                'character_id' => $initialCharacter->id ?? null,
                'candidate_id' => $request->candidate_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Could not record that vote.');
        }
    }
}
