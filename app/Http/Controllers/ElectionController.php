<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\Election;
use App\Services\JournalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ElectionController extends Controller
{
    public function index(Request $request, \App\Models\City $city)
    {
        $character = $request->user()->character;
        
        
        $character->loadMissing(['timers', 'career', 'city']);
      //! should add a drop out option
        
        
        $this->advanceElectionState($city);
        $city->refresh(); 

        $election = $city
            ->elections()
            ->active()
            ->with([
                "activeCampaigns.candidate:id,display_name,career_id,career_rank,custom_avatar_url",
            ])
            ->first();

        $recentResult = $city
            ->elections()
            ->completed()
            ->where("updated_at", ">=", now()->subHours(1))
            ->with(["campaigns.candidate:id,display_name"])
            ->latest("updated_at")
            ->first();

        $hasVoted = $election
            ? DB::table("campaign_votes")
                ->where("election_id", $election->id)
                ->where("voter_id", $character->id)
                ->exists()
            : false;

        if ($election?->isRegistrationOpen()) {
            
            
            $election->setRelation('city', $city);
            $canApply = $election->canApply($character);
        } elseif (!$election && !$city->mayor_id) {
            $errors = Election::checkCharacterEligibility($character, $city);
            $canApply = [
                "can_apply" => empty($errors),
                "errors" => $errors,
            ];
        } else {
            $canApply = null;
        }
        return Inertia::render("City/Election", [
            "city" => [
                "id" => $city->id,
                "name" => $city->name,
                "slug" => $city->slug,
                "current_mayor" => $city->mayor_display_name,
            ],
            "election" => $election
                ? $this->formatElection($election, $hasVoted, $character->id, $character->home_city_id)
                : null,
            "recent_result" => $recentResult
                ? $this->formatResult($recentResult)
                : null,
            "can_apply" => $canApply,
            "needs_election" => !$election && !$city->mayor_id,
            "election_constants" => [
                "application_fee" => Election::APPLICATION_FEE,
                "min_rank_required" => Election::MIN_RANK_REQUIRED,
                "registration_days" => Election::REGISTRATION_PERIOD_DAYS,
                "voting_days" => Election::VOTING_PERIOD_DAYS,
                "term_duration_days" => Election::TERM_DURATION_DAYS,
            ],
        ]);
    }

    public function apply(Request $request, \App\Models\City $city)
    {
        $request->validate([
            "manifesto" => "required|string|min:50|max:500",
        ]);

        $character = $request->user()->character;

        DB::beginTransaction();
        try {
            DB::table("characters")
                ->where("id", $character->id)
                ->lockForUpdate()
                ->first();

            $election =
                $city->elections()->active()->first() ??
                Election::startForCity($city);

            $check = $election->canApply($character);
            if (!$check["can_apply"]) {
                DB::rollBack();
                return back()->with("error", implode(" ", $check["errors"]));
            }

            $character->decrement("cash_on_hand", Election::APPLICATION_FEE);



            Campaign::create([
                "election_id" => $election->id,
                "candidate_id" => $character->id,
                "city_id" => $city->id,
                "manifesto" => $request->manifesto,
                "campaign_fund" =>  Election::APPLICATION_FEE,
                "status" => "active",
            ]);

            DB::commit();
            return back()->with(
                "success",
                "Campaign registered! Registration closes in " .
                    Election::REGISTRATION_PERIOD_DAYS .
                    " days.",
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with(
                "error",
                "An error occurred. Please try again.",
            );
        }
    }

    public function vote(Request $request, \App\Models\City $city)
    {
        $request->validate([
            "campaign_id" => "required|integer|exists:campaigns,id",
        ]);

        $character = $request->user()->character;

        DB::beginTransaction();
        try {
            $campaign = Campaign::where("id", $request->campaign_id)
                ->where("city_id", $city->id)
                ->where("status", "active")
                ->lockForUpdate()
                ->first();

            if (!$campaign) {
                DB::rollBack();
                return back()->with("error", "Campaign not found.");
            }

            $election = $campaign->election;

            if (!$election->isVotingOpen()) {
                DB::rollBack();
                return back()->with("error", "Voting is not currently open.");
            }

            if ($character->home_city_id !== $city->id) {
                DB::rollBack();
                return back()->with(
                    "error",
                    "You can only vote in your home city.",
                );
            }

            $alreadyVoted = DB::table("campaign_votes")
                ->where("election_id", $election->id)
                ->where("voter_id", $character->id)
                ->exists();

            if ($alreadyVoted) {
                DB::rollBack();
                return back()->with(
                    "error",
                    "You have already voted in this election.",
                );
            }

            DB::table("campaign_votes")->insert([
                "election_id" => $election->id,
                "campaign_id" => $campaign->id,
                "voter_id" => $character->id,
                "created_at" => now(),
            ]);

            $campaign->increment("votes");
            $election->increment("total_votes");

            DB::commit();
            return back()->with("success", "Your vote has been cast.");
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with(
                "error",
                "An error occurred. Please try again.",
            );
        }
    }

    public function addFunds(Request $request, \App\Models\City $city)
    {
        $request->validate([
            "campaign_id" => "required|integer|exists:campaigns,id",
            "amount" => "required|integer|min:1",
        ]);

        $character = $request->user()->character;

        DB::beginTransaction();
        try {
            DB::table("characters")
                ->where("id", $character->id)
                ->lockForUpdate()
                ->first();
            $character->refresh();

            $campaign = Campaign::where("id", $request->campaign_id)
                ->where("candidate_id", $character->id)
                ->where("status", "active")
                ->first();

            if (!$campaign || !$campaign->election->isRegistrationOpen()) {
                DB::rollBack();
                return back()->with("error", "Cannot add funds at this time.");
            }

            if ($character->cash_in_bank < $request->amount) {
                DB::rollBack();
                return back()->with("error", "Insufficient funds in bank.");
            }

            $character->decrement("cash_in_bank", $request->amount);
            $campaign->increment("campaign_fund", $request->amount);

            DB::commit();
            return back()->with(
                "success",
                "You have added some more funds to your Campaign!",
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with(
                "error",
                "An error occurred. Please try again.",
            );
        }
    }

    

    

    
    private function advanceElectionState(\App\Models\City $city): void
    {
        
        if ($city->mayor_id) {
            $expiredElection = Election::where('city_id', $city->id)
                ->where('status', 'completed')
                ->where('winner_id', $city->mayor_id)
                ->whereNotNull('term_end')
                ->where('term_end', '<=', now())
                ->first();

            if ($expiredElection) {
                try {
                    DB::transaction(function () use ($city) {
                        $mayor = $city->mayor()->lockForUpdate()->first();
                        if (! $mayor) {
                            return;
                        }

                        
                        
                        Business::where('owner_id', $mayor->id)
                            ->where('city_id', $city->id)
                            ->where(DB::raw('LOWER(code)'), 'city-hall')
                            ->update(['owner_id' => null, 'is_purchasable' => true]);

                        
                        
                        
                        
                        $term = \App\Models\MayorTerm::activeForCity($city->id);

                        if ($term) {
                            \App\Services\MayorService::removeMayor(
                                $term,
                                $city,
                                \App\Models\MayorTerm::END_TERM_COMPLETE
                            );
                        } else {
                            
                            $mayor->quitCareer(true);
                            $city->removeMayor();
                            Election::startForCity($city);
                        }

                        JournalService::custom($mayor->id, 'mayor_term_expired', [
                            'city_name' => $city->name,
                            'message'   => "Your mayoral term in {$city->name} has expired. You have been removed from office.",
                        ]);

                        Log::info('Mayor term expired (page-visit trigger).', [
                            'city'  => $city->name,
                            'mayor' => $mayor->display_name,
                        ]);
                    });
                } catch (\Throwable $e) {
                    Log::error('Mayor term expiry failed (page-visit).', [
                        'city_id' => $city->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        }

        
        Election::where('city_id', $city->id)
            ->where('status', 'registration')
            ->where('registration_end', '<=', now())
            ->get()
            ->each(function (Election $election) {
                try {
                    $election->startVoting();
                } catch (\Throwable $e) {
                    Log::error('Election voting start failed (page-visit).', [
                        'election_id' => $election->id,
                        'error'       => $e->getMessage(),
                    ]);
                }
            });

        
        Election::where('city_id', $city->id)
            ->where('status', 'voting')
            ->where('voting_end', '<=', now())
            ->get()
            ->each(function (Election $election) {
                try {
                    $election->finalize();
                } catch (\Throwable $e) {
                    Log::error('Election finalization failed (page-visit).', [
                        'election_id' => $election->id,
                        'error'       => $e->getMessage(),
                    ]);
                }
            });
    }

    private function formatElection(
        Election $election,
        bool $hasVoted,
        int $myCandidateId,
        int $viewerHomeCityId,
    ): array {
        $myCampaign = $election->activeCampaigns->firstWhere(
            "candidate_id",
            $myCandidateId,
        );

        return [
            "id"                => $election->id,
            "cycle"             => $election->cycle_number,
            "status"            => $election->status,
            "registration_ends" => $election->registration_end,
            "voting_starts"     => $election->voting_start,
            "voting_ends"       => $election->voting_end,
            "has_voted"         => $hasVoted,
            "max_candidates"    => Election::MAX_CANDIDATES,
            
            
            "my_campaign_fund"  => $myCampaign?->campaign_fund,
            "is_home_city"      => $viewerHomeCityId === $election->city_id,
            "candidates"        => $election->activeCampaigns
                ->filter(fn(Campaign $c) => $c->candidate !== null)
                ->sortBy("created_at")
                ->map(fn(Campaign $c) => [
                    "campaign_id"  => $c->id,
                    "display_name" => $c->candidate->display_name,
                    "avatar_url"   => $c->candidate->avatar_url,
                    "manifesto"    => $c->manifesto,
                ])
                ->values(),
        ];
    }

    private function formatResult(Election $recentResult): array
    {
        return [
            "cycle" => $recentResult->cycle_number,
            "ended_at" => $recentResult->updated_at,
            "winner" => $recentResult->winner?->display_name ?? "Unknown",
            "term_end" => $recentResult->term_end,
            "total_votes" => $recentResult->total_votes,
            "candidates" => $recentResult->campaigns
                ->filter(fn(Campaign $c) => $c->candidate !== null)
                ->map(
                    fn(Campaign $c) => [
                        "display_name" => $c->candidate->display_name,
                        "votes" => $c->votes,
                        "vote_percentage" => $c->vote_percentage,
                        "won" => $c->status === "won",
                    ],
                )
                ->sortByDesc("votes")
                ->values(),
        ];
    }
}
