<?php

namespace App\Models;

use App\Services\JournalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Election extends Model
{
    

    const APPLICATION_FEE = 75_000;
    const MIN_RANK_REQUIRED = 2;
    const MAX_CANDIDATES = 3;
    const REGISTRATION_PERIOD_DAYS = 2;
    const VOTING_PERIOD_DAYS = 1;
    const TERM_DURATION_DAYS = 7;

    protected $fillable = [
        "city_id",
        "cycle_number",
        "status",
        "registration_start",
        "registration_end",
        "voting_start",
        "voting_end",
        "winner_id",
        "total_votes",
        "term_start",
        "term_end",
    ];

    protected $casts = [
        "city_id"            => "integer",
        "winner_id"          => "integer",
        "total_votes"        => "integer",
        "registration_start" => "datetime",
        "registration_end"   => "datetime",
        "voting_start"       => "datetime",
        "voting_end"         => "datetime",
        "term_start"         => "datetime",
        "term_end"           => "datetime",
    ];

    

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(Character::class, "winner_id");
    }

    
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    
    public function activeCampaigns(): HasMany
    {
        return $this->hasMany(Campaign::class)->where("status", "active");
    }

    

    public function scopeActive($query)
    {
        return $query->whereIn("status", ["registration", "voting"]);
    }

    public function scopeForCity($query, int $cityId)
    {
        return $query->where("city_id", $cityId);
    }

    public function scopeCompleted($query)
    {
        return $query->where("status", "completed");
    }

    

    public function isRegistrationOpen(): bool
    {
        return $this->status === "registration" &&
            $this->registration_end?->isFuture();
    }

    public function isVotingOpen(): bool
    {
        return $this->status === "voting" && $this->voting_end?->isFuture();
    }

    public static function checkCharacterEligibility(
        Character $character,
        City $city,
    ): array {
        $errors = [];

        if ($character->career_rank < self::MIN_RANK_REQUIRED) {
            $errors[] = "Requires Rank " . self::MIN_RANK_REQUIRED . " to run for mayor";
        }

        if ($character->home_city_id !== $city->id) {
            $errors[] = "You must be a home city resident to run for mayor";
        }

        if ($character->cash_on_hand < self::APPLICATION_FEE) {
            $errors[] = sprintf(
                'Need $%s application fee on hand',
                number_format(self::APPLICATION_FEE),
            );
        }



        if ($city->mayor_id === $character->id) {
            $errors[] = "You are already serving as mayor";
        }

        if ($character->corporation_id !== null) {
            $errors[] = "You cannot run for mayor while working for a corporation.";
        }

        return $errors;
    }

    public function canApply(Character $character): array
    {
        $errors = self::checkCharacterEligibility($character, $this->city);

        if (!$this->isRegistrationOpen()) {
            $errors[] = "Registration period is not open";
        }

        if ($this->activeCampaigns()->count() >= self::MAX_CANDIDATES) {
            $errors[] =
                "Maximum candidates (" .
                self::MAX_CANDIDATES .
                ") already registered";
        }
           
        
        

        if (
            $this->campaigns()->where("candidate_id", $character->id)->exists()
        ) {
            $errors[] = "You have already applied to this election";
        }

        return [
            "can_apply" => empty($errors),
            "errors" => $errors,
        ];
    }

    

    
    public static function startForCity(City $city): self
    {
        $lastCycle = self::forCity($city->id)->max("cycle_number") ?? 0;
        $now = now();

        return self::create([
            "city_id" => $city->id,
            "cycle_number" => $lastCycle + 1,
            "status" => "registration",
            "registration_start" => $now,
            "registration_end" => $now
                ->copy()
                ->addDays(self::REGISTRATION_PERIOD_DAYS),
        ]);
    }

    
    public function startVoting(): void
    {
        if (
            $this->status !== "registration" ||
            $this->registration_end->isFuture()
        ) {
            return;
        }

        $now = now();

        $this->update([
            "status" => "voting",
            "voting_start" => $now,
            "voting_end" => $now->copy()->addDays(self::VOTING_PERIOD_DAYS),
        ]);
    }

    
    public function finalize(): void
    {
        if ($this->status !== "voting" || $this->voting_end->isFuture()) {
            return;
        }

        DB::transaction(function () {
            
            
            $election = self::where("id", $this->id)->lockForUpdate()->first();

            if ($election->status !== "voting") {
                return; 
            }

            $candidates = $election
                ->activeCampaigns()
                ->with(["candidate", "candidate.stats"])
                ->get();

            if ($candidates->isEmpty()) {
                $election->update(["status" => "completed"]);
                Log::info(
                    "Election #{$election->id} completed with no candidates.",
                );
                return;
            }

            $totalVotes = max(1, $election->total_votes);

            $scored = $candidates
                ->filter(fn (Campaign $campaign) => $campaign->candidate !== null)
                ->map(function (Campaign $campaign) use ($totalVotes) {
                    $influenceBonus = min(
                        150,
                        (float) ($campaign->candidate->stats->influence ?? 0) *
                            2,
                    );

                    $score =
                        $campaign->votes * 65 +
                        $influenceBonus +
                        ($campaign->campaign_fund / 100_000) * 35;

                    $pct = round(($campaign->votes / $totalVotes) * 100, 2);

                    return [
                        "campaign" => $campaign,
                        "score" => $score,
                        "pct" => $pct,
                    ];
                })
                ->sortByDesc("score");

      
            foreach ($scored as $item) {
                $candidate = $item['campaign']->candidate;
                if ($candidate->corporation_id !== null) {
                    $item['campaign']->update(['status' => 'disqualified']);
                    JournalService::custom($candidate->id, 'election_disqualified', [
                        'message'   => "You have been disqualified from the mayoral election due to an investigation turning up unacknowledged association with a corporation!",
                    ]);
                    Log::warning('Election candidate disqualified — joined corporation during campaign.', [
                        'election_id'  => $election->id,
                        'candidate_id' => $candidate->id,
                    ]);
                }
            }

            
            $scored = $scored->filter(fn ($item) => $item['campaign']->status !== 'disqualified');

            if ($scored->isEmpty()) {
                $election->update(['status' => 'completed']);
                Log::warning('The Election has been suspended due to no eligible candidates after disqualification.', [
                    'election_id' => $election->id,
                ]);
                return;
            }

            $winner = $scored->first()["campaign"];
            $termStart = now();
            $termEnd = $termStart->copy()->addDays(self::TERM_DURATION_DAYS);

            foreach ($scored as $item) {
                $item["campaign"]->update([
                    "vote_percentage" => $item["pct"],
                    "status" =>
                        $item["campaign"]->id === $winner->id ? "won" : "lost",
                ]);
            }

            
            $city = $election->city;
            
            $winnerCharacter = $winner->candidate;
            

            $winnerCharacter->startCareer('politics');
            $winnerCharacter->addXp(1000);
            $city->setMayor($winnerCharacter);

            // ── Achievement: elected mayor ────────────────────────────────
            try {
                $mayorAchievement = \App\Models\Achievement::where('slug', 'elected_mayor')->first();
                if ($mayorAchievement && ! $winnerCharacter->user->hasAchievement('elected_mayor')) {
                    $winnerCharacter->user->achievements()->attach(
                        $mayorAchievement->id,
                        ['unlocked_at' => now()]
                    );
                    $winnerCharacter->user->notifyAchievement($mayorAchievement, $winnerCharacter);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Achievement] elected_mayor failed silently.', [
                    'character_id' => $winnerCharacter->id,
                    'error'        => $e->getMessage(),
                ]);
            }

            
            \App\Models\MayorTerm::createForElection($city, $winnerCharacter, $election);

            $election->update([
                "status" => "completed",
                "winner_id" => $winnerCharacter->id,
                "term_start" => $termStart,
                "term_end" => $termEnd,
            ]);

            $ordinal = function (int $n): string {
                if ($n >= 11 && $n <= 13) return "th";
                switch ($n % 10) {
                    case 1: return "st";
                    case 2: return "nd";
                    case 3: return "rd";
                    default: return "th";
                }
            };

            foreach ($scored as $item) {
                $campaign = $item["campaign"];
                $won = $campaign->id === $winner->id;

                JournalService::custom(
                    $campaign->candidate_id,
                    "election_result",
                    [
                        "city_name" => $city->name,
                        "cycle" => $election->cycle_number,
                        "won" => $won,
                        "winner_name" => $winnerCharacter->display_name,
                        "votes_received" => $campaign->votes,
                        "total_votes" => $totalVotes,
                        "vote_percentage" => $item["pct"],
                        "campaign_fund" => $campaign->campaign_fund,
                        "term_end" => $won ? $termEnd->toIso8601String() : null,
                        "message" => $won
                            ? "You Campaigned well, winning the {$ordinal($election->cycle_number)} {$city->name} mayoral election! Voters have decided to make you the new {$city->name} Mayor!, Your Term ends {$termEnd->toIso8601String()}."
                            : "It was a hard fought fight, but you lost the {$city->name} mayoral election to  {$winnerCharacter->display_name}.",
                    ],
                );
            }

            
            $city
                ->residents()
                ->whereNotIn(
                    "id",
                    $scored->pluck("campaign.candidate_id")->all(),
                )
                ->pluck("id")
                ->each(function (int $residentId) use (
                    $city,
                    $winnerCharacter,
                    $election
                ) {
                    JournalService::custom(
                        $residentId,
                        "election_city_result",
                        [
                            "city_name" => $city->name,
                            "cycle" => $election->cycle_number,
                            "winner_name" => $winnerCharacter->display_name,
                            "message" =>
                                "{$winnerCharacter->display_name} has campaigned successfully and won the " .
                                match ($election->cycle_number) {
                                    1 => "1st",
                                    2 => "2nd",
                                    3 => "3rd",
                                    default => "{$election->cycle_number}th",
                                } .
                                " {$city->name} mayoral election!",
                        ],
                    );
                });

            Log::info("Election finalized", [
                "election_id" => $election->id,
                "winner" => $winnerCharacter->display_name,
                "city" => $city->name,
                "term_end" => $termEnd,
            ]);
        });
    }
}
