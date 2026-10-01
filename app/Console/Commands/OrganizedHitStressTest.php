<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Models\CharacterJournal;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\CrimeRecord;
use App\Models\User;
use App\Services\OrganizedHitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * OrganizedHitStressTest
 *
 * Realistic, end-to-end exercise of the OrganizedHit feature. The Pest tests
 * cover discrete units cleanly; this suite hammers the *flows* — the messy
 * stuff that bites in production:
 *
 *   1. Lifecycle — full happy paths, every collapse path, expiry, double-runs
 *   2. Real-life chaos — initiator forgets, member dies/leaves/jails mid-flight,
 *      target deleted from under us, rapid re-initiate after collapse
 *   3. Concurrency probes — competing accepts, simultaneous executes, decline-
 *      during-execute timing windows
 *   4. State integrity — active set never leaks, no zombie keys after collapse,
 *      no double-attached crime records, journals always converge
 *   5. Crime + payload — organized_hit crime record, participant array, severity,
 *      no legacy murder row
 *
 * Run:  php artisan org-hit:stress-test
 *       php artisan org-hit:stress-test --section=lifecycle
 *
 * Safe: All work happens inside a transaction that rolls back at the end.
 *       Cache is real Redis though — clears state-keys on teardown.
 *
 * !! NEVER RUN ON PRODUCTION — point .env at the test DB.
 */
class OrganizedHitStressTest extends Command
{
    protected $signature   = 'org-hit:stress-test {--section=all : Which section to run (all|lifecycle|chaos|race|integrity|crime)}';
    protected $description = 'OrganizedHit feature stress test — real-life messy flows';

    private City $city;
    private int  $unemployedCareerId;

    /** Track all initiator IDs we touch so we can sweep their cache keys at the end. */
    private array $cacheKeysToClear = [];

    private int $passed = 0;
    private int $failed = 0;

    public function handle(): int
    {
        $section = $this->option('section');

        $this->warn('');
        $this->warn('  ██████╗ ██████╗  ██████╗  ██████╗     ██╗  ██╗██╗████████╗');
        $this->warn('  ██╔═══██╗██╔══██╗██╔════╝ ██╔════╝     ██║  ██║██║╚══██╔══╝');
        $this->warn('  ██║   ██║██████╔╝██║  ███╗██║  ███╗    ███████║██║   ██║   ');
        $this->warn('  ██║   ██║██╔══██╗██║   ██║██║   ██║    ██╔══██║██║   ██║   ');
        $this->warn('  ╚██████╔╝██║  ██║╚██████╔╝╚██████╔╝    ██║  ██║██║   ██║   ');
        $this->warn('   ╚═════╝ ╚═╝  ╚═╝ ╚═════╝  ╚═════╝     ╚═╝  ╚═╝╚═╝   ╚═╝   ');
        $this->warn('');
        $this->info('  Organized Hit — End-to-End Stress Suite');
        $this->warn('  !! Ensure .env points at TEST database before running !!');
        $this->line('');

        DB::beginTransaction();

        try {
            $this->bootstrap();

            if (in_array($section, ['all', 'lifecycle'])) $this->runLifecycleSuite();
            if (in_array($section, ['all', 'chaos']))     $this->runChaosSuite();
            if (in_array($section, ['all', 'race']))      $this->runRaceSuite();
            if (in_array($section, ['all', 'integrity'])) $this->runIntegritySuite();
            if (in_array($section, ['all', 'crime']))     $this->runCrimeSuite();

        } finally {
            DB::rollBack();
            $this->sweepCache();
        }

        $this->line('');
        $this->line('  ─────────────────────────────────────────────────');
        $color = $this->failed > 0 ? 'error' : 'info';
        $this->{$color}(sprintf('  RESULT: %d passed, %d failed', $this->passed, $this->failed));
        $this->line('');

        return $this->failed > 0 ? 1 : 0;
    }

    

    private function bootstrap(): void
    {
        $this->info('  [SETUP] Bootstrapping test environment...');

        $unemployed = DB::table('careers')->whereRaw('LOWER(code) = ?', ['unemployed'])->first();
        abort_unless($unemployed, 500, 'Unemployed career seed missing.');
        $this->unemployedCareerId = $unemployed->id;

        $this->city = City::create([
            'name'       => 'OrgHitCity-' . uniqid(),
            'slug'       => 'orghit-' . uniqid(),
            'crime_rate' => 30.0,
        ]);

        $this->info('  [SETUP] Done. City: ' . $this->city->name);
        $this->line('');
    }

    
    
    

    private function runLifecycleSuite(): void
    {
        $this->section('SUITE 1 — LIFECYCLE FLOWS');

        $this->subsection('1A: Full happy path — initiate → 2× accept → execute (damage)');
        {
            [$i, $t, $a, $b] = $this->makeCrew(targetMaxHealth: 100_000);
            $this->initiate($i, $t, $a, $b);

            $err1 = OrganizedHitService::accept($a, $i->id);
            $err2 = OrganizedHitService::accept($b, $i->id);
            $this->assert($err1 === null && $err2 === null, 'Both accomplices accept cleanly');

            $state = OrganizedHitService::getState($i->id);
            $this->assert($state['is_ready'] === true, 'State flips to is_ready after final accept');

            $result = OrganizedHitService::execute($i);
            $this->assert(($result['outcome'] ?? null) === 'damage', "Execute returns damage outcome (got: " . ($result['outcome'] ?? 'error: '.($result['error']??'?')) . ")");
            $this->assert($t->fresh()->health < 100_000, 'Target took damage');
            $this->assert(OrganizedHitService::getState($i->id) === null, 'State cleared post-execute');
        }

        $this->subsection('1B: Full happy path — kill outcome');
        {
            [$i, $t, $a, $b] = $this->makeCrew(targetHealth: 1, targetMaxHealth: 1);
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);
            OrganizedHitService::accept($b, $i->id);

            $result = OrganizedHitService::execute($i);
            $this->assert(($result['outcome'] ?? null) === 'kill', 'Kill outcome on glass-cannon target');
            $this->assert($t->fresh()->trashed(), 'Target soft-deleted');
            $this->assert(! $this->isInActiveSet($i->id), 'Active set cleaned on kill');
        }

        $this->subsection('1C: Decline collapse — penalises accepted, not declining-only');
        {
            [$i, $t, $a, $b] = $this->makeCrew();
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);
            OrganizedHitService::decline($b, $i->id);

            $i->fresh()->loadMissing('timers');
            $a->fresh()->loadMissing('timers');
            $b->fresh()->loadMissing('timers');

            $this->assert($this->isOnCooldown($i), 'Initiator (accepted) on cooldown');
            $this->assert($this->isOnCooldown($a), 'Accomplice that accepted on cooldown');
            $this->assert(! $this->isOnCooldown($b), 'Decliner who never accepted: NO cooldown');
        }

        $this->subsection('1D: Cancel — clean collapse, zero cooldowns');
        {
            [$i, $t, $a, $b] = $this->makeCrew();
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);
            OrganizedHitService::cancel($i);

            $this->assert(OrganizedHitService::getState($i->id) === null, 'State cleared on cancel');
            $this->assert(! $this->isOnCooldown($i->fresh()->loadMissing('timers')), 'Initiator no cooldown after cancel');
            $this->assert(! $this->isOnCooldown($a->fresh()->loadMissing('timers')), 'Accomplice no cooldown after cancel');
        }

        $this->subsection('1E: Acceptance window expiry — auto-collapses on next read');
        {
            [$i, $t, $a, $b] = $this->makeCrew();
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);

            $this->forceExpire($i->id);
            $state = OrganizedHitService::getState($i->id);

            $this->assert($state === null, 'getState() returns null for expired op');
            $this->assert(! $this->isInActiveSet($i->id), 'Expired op removed from active set');
            $this->assert($this->isOnCooldown($a->fresh()->loadMissing('timers')), 'Accepted accomplice penalised on expiry');
        }
    }

    
    
    

    private function runChaosSuite(): void
    {
        $this->section('SUITE 2 — REAL-LIFE CHAOS');

        $this->subsection('2A: Initiator forgets to execute, then comes back after window');
        {
            [$i, $t, $a, $b] = $this->makeCrew();
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);
            OrganizedHitService::accept($b, $i->id);

            $this->forceExpire($i->id, expireExecuteToo: true);

            $result = OrganizedHitService::execute($i);
            $this->assert(isset($result['error']), 'Execute past expiry returns error');
            $this->assert(stripos($result['error'] ?? '', 'expired') !== false, "Error mentions 'expired' (got: " . ($result['error'] ?? '') . ')');
            $this->assert(OrganizedHitService::getState($i->id) === null, 'State cleared after expire-on-execute');
        }

        $this->subsection('2B: Member dies between accept and execute');
        {
            [$i, $t, $a, $b] = $this->makeCrew();
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);
            OrganizedHitService::accept($b, $i->id);

            $a->kill('chaos', 'died before the hit');

            $result = OrganizedHitService::execute($i);
            $this->assert(isset($result['error']) && stripos($result['error'], 'alive') !== false, 'Execute aborts citing dead member');
            $this->assert(OrganizedHitService::getState($i->id) === null, 'Op collapsed after dead-member abort');
        }

        $this->subsection('2C: Member leaves the city right before execute');
        {
            [$i, $t, $a, $b] = $this->makeCrew();
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);
            OrganizedHitService::accept($b, $i->id);

            $other = City::create(['name' => 'Bolt-' . uniqid(), 'slug' => 'bolt-' . uniqid(), 'crime_rate' => 0]);
            $a->update(['city_id' => $other->id]);

            $result = OrganizedHitService::execute($i);
            $this->assert(isset($result['error']) && stripos($result['error'], 'city') !== false, 'Execute aborts citing wrong city');
        }

        $this->subsection('2D: Member gets jailed mid-flight');
        {
            [$i, $t, $a, $b] = $this->makeCrew();
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);
            OrganizedHitService::accept($b, $i->id);

            DB::table('character_timers')
                ->where('character_id', $b->id)
                ->update(['jail_until' => now()->addHours(2)->getTimestamp()]);

            $result = OrganizedHitService::execute($i);
            $this->assert(isset($result['error']) && stripos($result['error'], 'unavailable') !== false, 'Execute aborts on jailed member');
        }

        $this->subsection('2E: Target hospitalized between accept and execute');
        {
            [$i, $t, $a, $b] = $this->makeCrew();
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);
            OrganizedHitService::accept($b, $i->id);

            DB::table('character_timers')
                ->where('character_id', $t->id)
                ->update(['hospital_until' => now()->addHours(2)->getTimestamp()]);

            $result = OrganizedHitService::execute($i);
            
            $this->assert(isset($result['error']), 'Hospitalized target rejected by canFight()');
        }

        $this->subsection('2F: Target deleted from under the op');
        {
            [$i, $t, $a, $b] = $this->makeCrew();
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);
            OrganizedHitService::accept($b, $i->id);

            $t->delete(); 

            $result = OrganizedHitService::execute($i);
            $this->assert(isset($result['error']), 'Deleted target rejected gracefully (no NPE)');
        }

        $this->subsection('2G: Rapid re-initiate after a clean cancel');
        {
            [$i, $t, $a, $b] = $this->makeCrew();
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::cancel($i);

            $err = OrganizedHitService::initiate($i, $t, [$a->id, $b->id]);
            $this->assert($err === null, 'Re-initiate immediately after cancel succeeds (got: ' . ($err ?? 'null') . ')');
        }

        $this->subsection('2H: Re-initiate blocked while previous op still pending');
        {
            [$i, $t, $a, $b] = $this->makeCrew();
            $this->initiate($i, $t, $a, $b);

            $t2  = $this->makeChar();
            $a2  = $this->makeChar();
            $b2  = $this->makeChar();

            $err = OrganizedHitService::initiate($i, $t2, [$a2->id, $b2->id]);
            $this->assert($err !== null, 'Cannot start second op while first is still active');
        }

        $this->subsection('2I: Accomplice tries to be in two ops at once');
        {
            [$i1, $t1, $a, $b] = $this->makeCrew();
            $this->initiate($i1, $t1, $a, $b);

            $i2 = $this->makeChar();
            $t2 = $this->makeChar();
            $c  = $this->makeChar();

            $err = OrganizedHitService::initiate($i2, $t2, [$a->id, $c->id]);
            $this->assert($err !== null && stripos($err, 'another operation') !== false, 'initiate() blocks shared accomplice');
        }

        $this->subsection('2J: Stale invite journal does not phantom-revive a cancelled op');
        {
            [$i, $t, $a, $b] = $this->makeCrew();
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::cancel($i);

            
            
            $surviving = CharacterJournal::where('character_id', $a->id)
                ->where('type', 'organized_hit_invite')
                ->whereJsonContains('data->initiator_id', $i->id)
                ->exists();

            $this->assert(! $surviving, 'No stale organized_hit_invite rows survive cancel');
            $this->assert(OrganizedHitService::findOpForMember($a->id) === null, 'findOpForMember returns null for cancelled op');
        }
    }

    
    
    

    private function runRaceSuite(): void
    {
        $this->section('SUITE 3 — CONCURRENCY PROBES');

        $this->subsection('3A: Two accepts back-to-back — both succeed, state consistent');
        {
            for ($r = 0; $r < 20; $r++) {
                [$i, $t, $a, $b] = $this->makeCrew();
                $this->initiate($i, $t, $a, $b);

                
                
                $err1 = OrganizedHitService::accept($a, $i->id);
                $err2 = OrganizedHitService::accept($b, $i->id);

                $state = OrganizedHitService::getState($i->id);
                $this->assert(
                    $err1 === null && $err2 === null
                        && in_array($a->id, $state['accepted_by'], true)
                        && in_array($b->id, $state['accepted_by'], true)
                        && $state['is_ready'] === true,
                    "Round {$r}: both accepts persist, is_ready set"
                );
            }
        }

        $this->subsection('3B: Execute called twice — second is a no-op error');
        {
            [$i, $t, $a, $b] = $this->makeCrew(targetHealth: 1, targetMaxHealth: 1);
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);
            OrganizedHitService::accept($b, $i->id);

            $r1 = OrganizedHitService::execute($i);
            $r2 = OrganizedHitService::execute($i);

            $this->assert(! isset($r1['error']), 'First execute succeeds');
            $this->assert(isset($r2['error']), 'Second execute errors out (no active op)');
        }

        $this->subsection('3C: Decline arrives during execute window — execute then cancel are both deterministic');
        {
            
            for ($r = 0; $r < 10; $r++) {
                [$i, $t, $a, $b] = $this->makeCrew(targetMaxHealth: 100_000);
                $this->initiate($i, $t, $a, $b);
                OrganizedHitService::accept($a, $i->id);
                OrganizedHitService::accept($b, $i->id);

                
                
                
                $exec    = OrganizedHitService::execute($i);
                $declineErr = null;
                try {
                    OrganizedHitService::decline($b, $i->id);
                } catch (\Throwable $e) {
                    $declineErr = $e->getMessage();
                }

                $this->assert(! isset($exec['error']), "Round {$r}: execute succeeded");
                $this->assert($declineErr === null, "Round {$r}: post-execute decline did not throw (silent no-op)");
                $this->assert(OrganizedHitService::getState($i->id) === null, "Round {$r}: no zombie state");
            }
        }

        $this->subsection('3D: 30 sequential ops, each on a fresh crew — no cross-contamination');
        {
            $stateLeaks = 0;
            $crimeMisses = 0;

            for ($r = 0; $r < 30; $r++) {
                [$i, $t, $a, $b] = $this->makeCrew(targetHealth: 1, targetMaxHealth: 1);
                $this->initiate($i, $t, $a, $b);
                OrganizedHitService::accept($a, $i->id);
                OrganizedHitService::accept($b, $i->id);
                OrganizedHitService::execute($i);

                if (OrganizedHitService::getState($i->id) !== null)              $stateLeaks++;
                if (! CrimeRecord::where('character_id', $i->id)
                        ->where('type', CrimeRecord::TYPE_ORGANIZED_HIT)->exists()) $crimeMisses++;
            }

            $this->assert($stateLeaks === 0,  "Zero state leaks across 30 ops (got: {$stateLeaks})");
            $this->assert($crimeMisses === 0, "Every op produced an organized_hit crime record (misses: {$crimeMisses})");
        }
    }

    
    
    

    private function runIntegritySuite(): void
    {
        $this->section('SUITE 4 — STATE INTEGRITY');

        $this->subsection('4A: Active set never grows unboundedly across 50 collapses');
        {
            $sizeBefore = count(Cache::get('org_hits_active', []));

            for ($r = 0; $r < 50; $r++) {
                [$i, $t, $a, $b] = $this->makeCrew();
                $this->initiate($i, $t, $a, $b);
                
                $reason = $r % 3;
                if ($reason === 0)      OrganizedHitService::cancel($i);
                elseif ($reason === 1)  OrganizedHitService::decline($a, $i->id);
                else                    $this->forceExpire($i->id) && OrganizedHitService::getState($i->id);
            }

            $sizeAfter = count(Cache::get('org_hits_active', []));
            $delta = $sizeAfter - $sizeBefore;

            $this->stat('Active set delta', $delta);
            $this->assert($delta === 0, "Active set returns to baseline after 50 collapses (delta: {$delta})");
        }

        $this->subsection('4B: getState() collapses expired ops idempotently (10 calls, one collapse)');
        {
            [$i, $t, $a, $b] = $this->makeCrew();
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);
            $this->forceExpire($i->id);

            
            for ($r = 0; $r < 10; $r++) {
                OrganizedHitService::getState($i->id);
            }

            
            $count = CharacterJournal::where('character_id', $a->id)
                ->where('type', 'organized_hit_collapsed')
                ->count();

            $this->assert($count === 1, "Exactly one collapse journal after 10 getState calls (got: {$count})");
        }

        $this->subsection('4C: findOpForMember scans without mutating non-expired ops');
        {
            [$i, $t, $a, $b] = $this->makeCrew();
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);

            
            $stateBefore = OrganizedHitService::getState($i->id);

            
            for ($r = 0; $r < 20; $r++) {
                OrganizedHitService::findOpForMember($a->id);
            }

            $stateAfter = OrganizedHitService::getState($i->id);
            $this->assert(
                $stateBefore['accepted_by'] === $stateAfter['accepted_by']
                    && $stateBefore['is_ready'] === $stateAfter['is_ready'],
                'findOpForMember does not mutate live state across 20 reads'
            );
        }

        $this->subsection('4D: Per-accomplice journal exclusion uses the right name on identical-prefixed crews');
        {
            
            
            [$i, $t, $a, $b] = $this->makeCrew(targetMaxHealth: 100_000);
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);
            OrganizedHitService::accept($b, $i->id);
            OrganizedHitService::execute($i);

            $journals = CharacterJournal::whereIn('character_id', [$a->id, $b->id])
                ->where('type', 'organized_hit_result')
                ->get();

            $this->assert($journals->count() === 2, 'Both accomplices got a result journal');

            
            
            foreach ($journals as $j) {
                $member = $j->character_id === $a->id ? $a : $b;
                $msg    = $j->data['message'] ?? '';
                $this->assert(
                    stripos($msg, $member->display_name) === false,
                    "Result journal for {$member->display_name} does not address themselves in 3rd person"
                );
            }
        }
    }

    
    
    

    private function runCrimeSuite(): void
    {
        $this->section('SUITE 5 — CRIME RECORD INTEGRITY');

        $this->subsection('5A: Kill produces organized_hit, NOT murder');
        {
            [$i, $t, $a, $b] = $this->makeCrew(targetHealth: 1, targetMaxHealth: 1);
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);
            OrganizedHitService::accept($b, $i->id);
            OrganizedHitService::execute($i);

            $orgHit = CrimeRecord::where('character_id', $i->id)->where('type', CrimeRecord::TYPE_ORGANIZED_HIT)->first();
            $murder = CrimeRecord::where('character_id', $i->id)->where('type', CrimeRecord::TYPE_MURDER)->exists();

            $this->assert($orgHit !== null, 'organized_hit crime record exists');
            $this->assert(! $murder, 'No legacy murder record produced');
            $this->assert($orgHit->severity === CrimeRecord::SEV_CAPITAL, 'Severity is capital');
            $this->assert($orgHit->city_id === $this->city->id, 'Filed in initiator city');
        }

        $this->subsection('5B: Participants array contains both accomplices, not the initiator');
        {
            [$i, $t, $a, $b] = $this->makeCrew(targetHealth: 1, targetMaxHealth: 1);
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);
            OrganizedHitService::accept($b, $i->id);
            OrganizedHitService::execute($i);

            $crime = CrimeRecord::where('character_id', $i->id)
                ->where('type', CrimeRecord::TYPE_ORGANIZED_HIT)
                ->firstOrFail();

            $participants = array_map('intval', $crime->data['participants'] ?? []);

            $this->assert(count($participants) === 2, 'Exactly 2 participants recorded');
            $this->assert(in_array($a->id, $participants, true) && in_array($b->id, $participants, true), 'Both accomplices listed');
            $this->assert(! in_array($i->id, $participants, true), 'Initiator not duplicated into participants');
        }

        $this->subsection('5C: isConflicted() recognises every crew member');
        {
            [$i, $t, $a, $b] = $this->makeCrew(targetHealth: 1, targetMaxHealth: 1);
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);
            OrganizedHitService::accept($b, $i->id);
            OrganizedHitService::execute($i);

            $crime = CrimeRecord::where('character_id', $i->id)
                ->where('type', CrimeRecord::TYPE_ORGANIZED_HIT)
                ->firstOrFail();

            $this->assert($crime->isConflicted($i), 'Initiator is conflicted');
            $this->assert($crime->isConflicted($a), 'Accomplice A is conflicted');
            $this->assert($crime->isConflicted($b), 'Accomplice B is conflicted');

            $bystander = $this->makeChar();
            $this->assert(! $crime->isConflicted($bystander), 'Random bystander is NOT conflicted');
        }

        $this->subsection('5D: Damage path produces NO crime record (only kills file)');
        {
            [$i, $t, $a, $b] = $this->makeCrew(targetMaxHealth: 100_000);
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);
            OrganizedHitService::accept($b, $i->id);
            $result = OrganizedHitService::execute($i);

            $this->assert(($result['outcome'] ?? null) === 'damage', 'Damage outcome reached');

            $hasCrime = CrimeRecord::where('character_id', $i->id)
                ->where('type', CrimeRecord::TYPE_ORGANIZED_HIT)
                ->exists();

            $this->assert(! $hasCrime, 'No organized_hit crime record on damage-only outcome (assault might be appropriate; flagged for review)');
        }

        $this->subsection('5E: City crime rate increases on a kill');
        {
            $rateBefore = (float) City::find($this->city->id)->crime_rate;

            [$i, $t, $a, $b] = $this->makeCrew(targetHealth: 1, targetMaxHealth: 1);
            $this->initiate($i, $t, $a, $b);
            OrganizedHitService::accept($a, $i->id);
            OrganizedHitService::accept($b, $i->id);
            OrganizedHitService::execute($i);

            $rateAfter = (float) City::find($this->city->id)->crime_rate;
            $this->assert($rateAfter > $rateBefore, "Crime rate climbed: {$rateBefore} → {$rateAfter}");
        }
    }

    
    
    

    /** Build a 4-tuple: [initiator, target, accomplice0, accomplice1]. */
    private function makeCrew(
        int $targetHealth = 100,
        int $targetMaxHealth = 100,
    ): array {
        $i = $this->makeChar();
        $t = $this->makeChar(health: $targetHealth, maxHealth: $targetMaxHealth);
        $a = $this->makeChar();
        $b = $this->makeChar();
        return [$i, $t, $a, $b];
    }

    private function initiate(Character $initiator, Character $target, Character $a, Character $b): void
    {
        $err = OrganizedHitService::initiate($initiator, $target, [$a->id, $b->id]);
        $this->cacheKeysToClear[] = $initiator->id;
        if ($err !== null) {
            $this->error("    [SETUP FAIL] initiate() returned: {$err}");
        }
    }

    private function makeChar(
        bool $online    = true,
        int  $health    = 100,
        int  $maxHealth = 100,
        int  $offense   = 5_000,
        int  $defense   = 500,
        int  $intelligence = 3_000,
        int  $luck      = 3_000,
    ): Character {
        $user = User::factory()->create();

        if ($online) {
            DB::table('sessions')->insert([
                'id'            => uniqid('s_', true),
                'user_id'       => $user->id,
                'ip_address'    => '127.0.0.1',
                'user_agent'    => 'orghit-stress',
                'payload'       => '',
                'last_activity' => time(),
            ]);
            \App\Support\Presence::touch($user->id, '127.0.0.1');
        }

        $char = Character::create([
            'user_id'             => $user->id,
            'display_name'        => 'OH-' . uniqid(),
            'gender'              => 'male',
            'city_id'             => $this->city->id,
            'home_city_id'        => $this->city->id,
            'career_id'           => $this->unemployedCareerId,
            'career_rank'         => 1,
            'health'              => $health,
            'max_health'          => $maxHealth,
            'cash_on_hand'        => 100_000,
            'dirty_cash'          => 10_000,
            'total_character_exp' => 50_000,
        ]);

        DB::table('characters')->where('id', $char->id)->update(['created_at' => now()->subDays(10)]);

        CharacterStats::create([
            'character_id' => $char->id,
            'intelligence' => $intelligence,
            'luck'         => $luck,
            'offense'      => $offense,
            'defense'      => $defense,
            'influence'    => 10,
        ]);

        CharacterTimers::create([
            'character_id'   => $char->id,
            'next_action_at' => 0,
        ]);

        return $char;
    }

    /**
     * Force an op's expires_at into the past. If $expireExecuteToo, also
     * push execute_expires_at, so execute() will hit the post-expiry path
     * even if all members already accepted.
     */
    private function forceExpire(int $initiatorId, bool $expireExecuteToo = false): bool
    {
        $key = OrganizedHitService::cacheKey($initiatorId);
        $state = Cache::get($key);
        if (! $state) return false;

        $state['expires_at'] = now()->subSeconds(1)->timestamp;
        if ($expireExecuteToo) {
            $state['execute_expires_at'] = now()->subSeconds(1)->timestamp;
        }
        Cache::put($key, $state, 3600);
        return true;
    }

    private function isInActiveSet(int $initiatorId): bool
    {
        return in_array($initiatorId, Cache::get('org_hits_active', []), true);
    }

    private function isOnCooldown(Character $char): bool
    {
        $next = $char->timers?->next_conflict_at;
        return $next !== null && $next->isFuture();
    }

    /** Sweep any cache keys we created so we don't leave Redis state behind. */
    private function sweepCache(): void
    {
        foreach (array_unique($this->cacheKeysToClear) as $id) {
            Cache::forget(OrganizedHitService::cacheKey($id));
        }
        
        $active = array_values(array_diff(
            Cache::get('org_hits_active', []),
            $this->cacheKeysToClear
        ));
        Cache::put('org_hits_active', $active, 86400);
    }

    

    private function section(string $title): void
    {
        $this->line('');
        $this->line('  ╔══════════════════════════════════════════════════════╗');
        $this->line("  ║  <fg=yellow;options=bold>{$title}</>");
        $this->line('  ╚══════════════════════════════════════════════════════╝');
        $this->line('');
    }

    private function subsection(string $title): void
    {
        $this->line('');
        $this->line("  <fg=cyan>▶ {$title}</>");
    }

    private function stat(string $label, mixed $value): void
    {
        $this->line(sprintf('    <fg=blue>%-35s</> %s', $label, $value));
    }

    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            $this->line("    <fg=green>✓</> {$message}");
            $this->passed++;
        } else {
            $this->line("    <fg=red>✗ FAIL: {$message}</>");
            $this->failed++;
        }
    }
}
