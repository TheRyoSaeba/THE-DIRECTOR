<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\City;
use App\Models\CityHallAide;
use App\Models\Election;
use App\Models\MayorTerm;
use App\Services\JournalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
//! DEPRECIATED
class ProcessElections extends Command
{
    protected $signature = "elections:process";
    protected $description = "Advance election phases and expire mayor terms";

    public function handle(): int
    {
        $this->expireMayorTerms();
        $this->advanceToVoting();
        $this->finalizeElections();

        return 0;
    }
    private function expireMayorTerms(): void
    {
        $expired = City::whereNotNull("mayor_id")
            ->whereExists(function ($query) {
            $query
                ->select(DB::raw(1))
                ->from("elections")
                ->whereColumn("elections.winner_id", "cities.mayor_id")
                ->whereColumn("elections.city_id", "cities.id")
                ->where("elections.status", "completed")
                ->whereNotNull("elections.term_end")
                ->where("elections.term_end", "<=", now());
        })
            ->with("mayor")
            ->get();

        foreach ($expired as $city) {
            try {
                DB::transaction(function () use ($city) {
                    $mayor = $city->mayor()->lockForUpdate()->first();
                    if (!$mayor) {
                        return;
                    }

                    $term = MayorTerm::where('city_id', $city->id)
                        ->whereNull('ended_at')
                        ->first();

                    if ($term) {
                        CityHallAide::where('mayor_term_id', $term->id)->delete();
                        $term->update(['ended_at' => now(), 'end_reason' => 'term_expired']);
                    }

                    $mayor->quitCareer(true);

                    Business::where('owner_id', $mayor->id)
                        ->where('city_id', $city->id)
                        ->where('code', 'city-hall')
                        ->update(['owner_id' => null, 'is_purchasable' => true]);

                    $city->removeMayor();

                    JournalService::custom($mayor->id, "mayor_term_expired", [
                        "city_name" => $city->name,
                        "message" => "Your mayoral term in {$city->name} has expired. You have been removed from office.",
                    ]);

                    Log::info("Mayor term expired", [
                        "city" => $city->name,
                        "mayor" => $mayor->display_name,
                    ]);

                    $this->info(
                        "Term expired: {$mayor->display_name} removed from {$city->name}.",
                    );
                });
            }
            catch (\Exception $e) {
                $this->error(
                    "Failed to expire mayor in {$city->name}: {$e->getMessage()}",
                );
                Log::error("Mayor expiry failed", [
                    "city_id" => $city->id,
                    "error" => $e->getMessage(),
                ]);
            }
        }
    }

    private function advanceToVoting(): void
    {
        Election::where("status", "registration")
            ->where("registration_end", "<=", now())
            ->get()
            ->each(function (Election $election) {
            try {
                $election->startVoting();
                $this->info(
                    "Election #{$election->id} (city #{$election->city_id}) moved to voting.",
                );
            }
            catch (\Exception $e) {
                $this->error(
                    "Failed to start voting for election #{$election->id}: {$e->getMessage()}",
                );
                Log::error("Voting start failed", [
                    "election_id" => $election->id,
                    "error" => $e->getMessage(),
                ]);
            }
        });
    }

    private function finalizeElections(): void
    {
        Election::where("status", "voting")
            ->where("voting_end", "<=", now())
            ->get()
            ->each(function (Election $election) {
            try {
                $election->finalize();
                $this->info("Election #{$election->id} finalized.");
            }
            catch (\Exception $e) {
                $this->error(
                    "Failed to finalize election #{$election->id}: {$e->getMessage()}",
                );
                Log::error("Election finalization failed", [
                    "election_id" => $election->id,
                    "error" => $e->getMessage(),
                ]);
            }
        });
    }
}
