<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only inspection command for corporation career_ranks + career_earns
 * as they actually live in the DB. We don't trust migration files alone
 * since rows can drift via later patches, manual edits, or seeders.
 *
 *   php artisan inspect:corp-earns
 */
class InspectCorpEarns extends Command
{
    protected $signature   = 'inspect:corp-earns';
    protected $description = 'Dump corporation career ranks + earns straight from the DB.';

    public function handle(): int
    {
        $career = DB::table('careers')->where('code', 'corporation')->first();

        if (! $career) {
            $this->error('No career found with code = "corporation".');
            return 1;
        }

        $this->info("Career: {$career->name} (id={$career->id}, code={$career->code})");
        $this->line('');

        // Ranks
        $this->line('<fg=yellow;options=bold>RANKS</>');
        $ranks = DB::table('career_ranks')
            ->where('career_id', $career->id)
            ->orderBy('rank_level')
            ->get(['rank_level', 'rank_name', 'xp_required']);

        $this->table(
            ['lvl', 'name', 'xp_required'],
            $ranks->map(fn($r) => [
                $r->rank_level,
                $r->rank_name,
                number_format($r->xp_required),
            ])->toArray()
        );

        // Earns
        $this->line('');
        $this->line('<fg=yellow;options=bold>EARNS</>');

        $earns = DB::table('career_earns')
            ->where('career_id', $career->id)
            ->orderBy('min_rank')
            ->orderBy('min_career_xp')
            ->get();

        if ($earns->isEmpty()) {
            $this->warn('No earns rows for this career.');
            return 0;
        }

        $this->table(
            ['code', 'title', 'min_rank', 'min_xp', 'payout'],
            $earns->map(fn($e) => [
                $e->code,
                $e->title,
                $e->min_rank,
                number_format($e->min_career_xp),
                '$' . number_format($e->payout_min) . ' - $' . number_format($e->payout_max),
            ])->toArray()
        );

        // Full message dump per earn
        $this->line('');
        $this->line('<fg=yellow;options=bold>MESSAGES</>');

        foreach ($earns as $e) {
            $this->line('');
            $this->line("<fg=cyan;options=bold>[{$e->code}]</>  rank {$e->min_rank}+   xp ≥ " . number_format($e->min_career_xp));
            $this->line("  <fg=gray>title:</> {$e->title}");
            $this->line('  <fg=green>success:</> ' . ($e->success_message ?? '<null>'));
            $this->line('  <fg=red>failure:</>  ' . ($e->failure_message ?? '<null>'));
        }

        return 0;
    }
}
