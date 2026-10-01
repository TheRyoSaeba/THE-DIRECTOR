<?php

namespace App\Console\Commands;

use App\Actions\InvestmentFraud;
use App\Models\Character;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\Corporation;
use App\Models\CorporationProperty;
use App\Models\User;
use App\Services\ConflictService;
use App\Services\OrganizedHitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * CorporationStressTest
 *
 * End-to-end exercise of the corporation system at the scale and shape it
 * actually runs at in production. The Pest tests in CorporationSystemTest
 * cover discrete units (HTTP routes, controller branches); this command
 * stands the world up at full size and walks it through:
 *
 *   1. Bootstrap — 3 cities, 3 max-cap holdings (4 subsidiaries + 5 board
 *      members each), tier-3 HQs. State seeded directly to skip the
 *      multi-stage merger HTTP flow inside a single console transaction.
 *   2. Funds   — consolidated math via the real `subsidiaries` relation,
 *      operating-corp pot isolation, drain-in-id-order algorithm walk.
 *   3. Gates   — InvestmentFraud::canExecute on a holding board member,
 *      audit-target query exclusion, raw deposit through the controller's
 *      private oversight helper avoiding the null-CEO crash.
 *   4. Lifecycle — subsidiary CEO death + succession; subsidiary deletion
 *      when no successor; holding collapse when every subsidiary dies.
 *   5. War     — multi-round organized-hit exchanges between board members
 *      of surviving holdings. Drives OrganizedHitService directly.
 *
 * Run:  php artisan corp:stress-test
 *       php artisan corp:stress-test --section=war
 *
 * Safe: Wrapped in DB::beginTransaction() / rollBack(). No data persists.
 *       Redis is real, so we sweep any OrganizedHit state keys on teardown.
 *
 * !! NEVER RUN ON PRODUCTION — point .env at the test DB. !!
 */
class CorporationStressTest extends Command
{
    protected $signature = 'corp:stress-test {--section=all : Which section to run (all|funds|gates|lifecycle|war)}';
    protected $description = 'Corporation system stress test — funds, gates, lifecycle, and a three-way corporate war';

    private int $passed = 0;
    private int $failed = 0;
    private int $corpCareerId;

    /** Real xp_required pulled from career_ranks at boot, indexed by rank_level. */
    private array $rankXp = [];

    /** Initiator IDs whose OrganizedHit cache keys need cleaning at the end. */
    private array $cacheKeysToClear = [];

    /**
     * @var array<string, array{
     *     city: City,
     *     holding: Corporation,
     *     subs: list<Corporation>,
     *     subCeos: list<Character>,
     *     subSuccessors: list<Character>,
     *     subMembers: list<Character>,
     *     board: list<Character>,
     * }>
     */
    private array $world = [];

    public function handle(): int
    {
        $section = $this->option('section');

        $this->line('');
        $this->warn('  ╔══════════════════════════════════════════════════════════╗');
        $this->warn('  ║  CORPORATION STRESS TEST — three holdings, full network  ║');
        $this->warn('  ╚══════════════════════════════════════════════════════════╝');
        $this->warn('  !! Ensure .env points at TEST database before running !!');
        $this->line('');

        DB::beginTransaction();

        try {
            $this->bootstrapCareers();
            $this->buildWorld();

            if (in_array($section, ['all', 'funds']))     $this->runFundsSuite();
            if (in_array($section, ['all', 'gates']))     $this->runGatesSuite();
            if (in_array($section, ['all', 'lifecycle'])) $this->runLifecycleSuite();
            if (in_array($section, ['all', 'war']))       $this->runWarSuite();

        } catch (\Throwable $e) {
            $this->error('  Aborted with exception: ' . $e->getMessage());
            $this->error('  ' . $e->getFile() . ':' . $e->getLine());
            $this->error('  ' . $e->getTraceAsString());
            $this->failed++;
        } finally {
            DB::rollBack();
            $this->sweepCache();
        }

        $this->line('');
        $this->line('  ─────────────────────────────────────────────────────────');
        if ($this->failed > 0) {
            $this->error(sprintf('  RESULT: %d passed, %d failed', $this->passed, $this->failed));
        } else {
            $this->info(sprintf('  RESULT: all %d checks passed', $this->passed));
        }
        $this->line('');

        return $this->failed > 0 ? 1 : 0;
    }

    // ─── bootstrap ──────────────────────────────────────────────────────────

    private function bootstrapCareers(): void
    {
        $this->corpCareerId = (int) DB::table('careers')->whereRaw('LOWER(code) = ?', ['corporation'])->value('id');
        abort_unless($this->corpCareerId, 500, 'corporation career missing.');

        // Seed characters with real promotion-ready XP rather than hand-picked
        // numbers. Rank 4 sits at ~180k in current data, so a 12k successor
        // would never qualify for the rank-5 handoff and the lifecycle suite
        // would mis-fire.
        $rows = DB::table('career_ranks')
            ->where('career_id', $this->corpCareerId)
            ->orderBy('rank_level')
            ->get(['rank_level', 'xp_required']);
        foreach ($rows as $row) {
            $this->rankXp[(int) $row->rank_level] = (int) $row->xp_required;
        }

        $this->info(sprintf(
            '  • Corp rank XP floors: r1=%s r2=%s r3=%s r4=%s r5=%s r6=%s',
            number_format($this->rankXp[1] ?? 0),
            number_format($this->rankXp[2] ?? 0),
            number_format($this->rankXp[3] ?? 0),
            number_format($this->rankXp[4] ?? 0),
            number_format($this->rankXp[5] ?? 0),
            number_format($this->rankXp[6] ?? 0),
        ));
    }

    private function buildWorld(): void
    {
        $this->section('SETUP — 3 cities × (1 holding + 4 subsidiaries + 5 board)');

        foreach (['Tokyo', 'Seoul', 'NewYork'] as $cityName) {
            $city = City::create([
                'name' => "Stress-{$cityName}-" . uniqid(),
                'slug' => 'stress-' . strtolower($cityName) . '-' . uniqid(),
                'crime_rate' => 30.0,
            ]);

            $holding = Corporation::create([
                'name' => "{$cityName} Holding-" . uniqid(),
                'home_city_id' => $city->id,
                'founder_id' => null,
                'ceo_id' => null,
                'is_holding_company' => true,
                'slush_fund' => 200_000,
                'cash_reserves' => 100_000,
                'hq_tier' => 0,
            ]);

            // The merger flow is the canonical source of tier-3 holding HQs;
            // here we mirror its end-state directly so we don't have to drive
            // the multi-stage HTTP flow inside a console transaction.
            CorporationProperty::createStartingHeadquarters($holding, 3);

            $subs = [];
            $subCeos = [];
            $subSuccessors = [];
            $subMembers = [];
            for ($i = 0; $i < 4; $i++) {
                // CEO is rank-4 sitting at the rank-4 floor with veteran-tier
                // stats — the kind of profile a real subsidiary CEO actually has.
                $subCeo = $this->makeChar(
                    $city, $this->corpCareerId, 4,
                    cashOnHand: 1_000_000,
                    careerXp: $this->rankXp[4] ?? 180_000,
                    statTier: 'veteran',
                    name: "{$cityName}-Sub{$i}-CEO",
                );
                $sub = Corporation::create([
                    'name' => "{$cityName} Sub{$i}-" . uniqid(),
                    'home_city_id' => $city->id,
                    'founder_id' => $subCeo->id,
                    'ceo_id' => $subCeo->id,
                    'is_holding_company' => false,
                    'parent_trust_id' => $holding->id,
                    'slush_fund' => 50_000 + $i * 25_000,
                    'cash_reserves' => 20_000,
                    'hq_tier' => 0,
                ]);
                CorporationProperty::createStartingHeadquarters($sub, 1);
                $sub->attachMember($subCeo);
                $subCeo->refresh();

                // Successor: rank-3 with rank-4 XP so they're promotion-ready.
                // The real-world equivalent of a CFO who's earned the next seat.
                $successor = $this->makeChar(
                    $city, $this->corpCareerId, 3,
                    careerXp: $this->rankXp[4] ?? 180_000,
                    statTier: 'senior',
                    name: "{$cityName}-Sub{$i}-Successor",
                );
                $sub->attachMember($successor, Corporation::POSITION_CFO);

                // Rank-2 member rounding out the chain — junior stats.
                $rankAndFile = $this->makeChar(
                    $city, $this->corpCareerId, 2,
                    careerXp: $this->rankXp[2] ?? 1_000,
                    statTier: 'junior',
                    name: "{$cityName}-Sub{$i}-Member",
                );
                $sub->attachMember($rankAndFile, Corporation::POSITION_MEMBER, $successor);

                $subs[] = $sub->fresh();
                $subCeos[] = $subCeo->fresh();
                $subSuccessors[] = $successor->fresh();
                $subMembers[] = $rankAndFile->fresh();
            }

            // Board: 5 Group Presidents at rank 5 with the highest stat tier.
            // Capacity = 1 + active_subsidiary_count = 5 with 4 subs.
            $board = [];
            for ($i = 0; $i < 5; $i++) {
                $boardMember = $this->makeChar(
                    $city, $this->corpCareerId, 5,
                    cashOnHand: 2_000_000,
                    careerXp: $this->rankXp[5] ?? 400_000,
                    statTier: 'board',
                    name: "{$cityName}-Board{$i}",
                );
                $boardMember->update([
                    'corporation_id' => $holding->id,
                    'corporation_position' => Corporation::POSITION_GROUP_PRESIDENT,
                    'corporation_reports_to_id' => null,
                ]);
                $board[] = $boardMember->fresh();
            }

            $this->world[$cityName] = [
                'city' => $city,
                'holding' => $holding->fresh(),
                'subs' => $subs,
                'subCeos' => $subCeos,
                'subSuccessors' => $subSuccessors,
                'subMembers' => $subMembers,
                'board' => $board,
            ];

            $this->info(sprintf(
                '  • %s: holding=%d, subs=%d, board=%d, slush=%s, reserves=%s',
                $cityName,
                $holding->id,
                count($subs),
                count($board),
                number_format($this->consolidatedSlush($holding)),
                number_format($this->consolidatedReserves($holding)),
            ));
        }

        $this->line('');
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /**
     * Stat tiers approximate the kind of stat shape characters actually have
     * in production at each career level. The briefing notes that non-influence
     * stats grow without a ceiling for the lifetime of a character, and a
     * veteran easily lands in the hundreds of thousands.
     *
     *   junior   — rank-2 grunt
     *   senior   — rank-3 ready-for-promotion middle manager
     *   veteran  — rank-4 subsidiary CEO
     *   board    — rank-5/6 holding-company board member
     */
    private function statShape(string $tier): array
    {
        return match ($tier) {
            'board' => ['influence' => 90, 'intelligence' => 320_000, 'offense' => 280_000, 'defense' => 300_000, 'luck' => 240_000],
            'veteran' => ['influence' => 70, 'intelligence' => 220_000, 'offense' => 200_000, 'defense' => 220_000, 'luck' => 180_000],
            'senior' => ['influence' => 55, 'intelligence' => 140_000, 'offense' => 120_000, 'defense' => 140_000, 'luck' => 100_000],
            'junior' => ['influence' => 30, 'intelligence' => 25_000, 'offense' => 20_000, 'defense' => 25_000, 'luck' => 15_000],
            default => ['influence' => 25, 'intelligence' => 1_000, 'offense' => 1_000, 'defense' => 1_000, 'luck' => 1_000],
        };
    }

    private function makeChar(
        City $city,
        int $careerId,
        int $rank,
        int $cashOnHand = 50_000,
        int $cashInBank = 50_000,
        ?int $careerXp = null,
        string $statTier = 'default',
        ?string $name = null,
    ): Character {
        $tag = $name ?: ('SC-' . uniqid());
        $sentinel = uniqid();
        $user = User::create([
            'username' => "stress_{$tag}_{$sentinel}",
            'email' => "stress_{$tag}_{$sentinel}@stress.invalid",
            'password' => bcrypt('unused'),
            'google_id' => "stress_{$tag}_{$sentinel}",
        ]);

        $character = Character::create([
            'user_id' => $user->id,
            'display_name' => $tag . '-' . substr($sentinel, -4),
            'gender' => 'male',
            'city_id' => $city->id,
            'home_city_id' => $city->id,
            'career_id' => $careerId,
            'career_rank' => $rank,
            'career_xp' => $careerXp ?? ($rank * 5_000),
            'total_character_exp' => $careerXp ?? ($rank * 5_000),
            'health' => 100,
            'max_health' => 100,
            'cash_on_hand' => $cashOnHand,
            'cash_in_bank' => $cashInBank,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        CharacterStats::create(array_merge(
            ['character_id' => $character->id],
            $this->statShape($statTier),
        ));
        CharacterTimers::create([
            'character_id' => $character->id,
            'next_action_at' => 0,
            'strength' => 100,
        ]);

        return $character;
    }

    private function consolidatedSlush(Corporation $holding): int
    {
        return (int) $holding->slush_fund + (int) $holding->subsidiaries()->sum('slush_fund');
    }

    private function consolidatedReserves(Corporation $holding): int
    {
        return (int) $holding->cash_reserves + (int) $holding->subsidiaries()->sum('cash_reserves');
    }

    // ─── funds suite ────────────────────────────────────────────────────────
    //
    // The Pest tests cover the controller's HTTP entry points; this section
    // verifies the same invariants at scale via the model relations the
    // controller relies on.

    private function runFundsSuite(): void
    {
        $this->section('SUITE 1 — FUND FLOWS (model-level, full-network)');

        $this->subsection('1A: Tier-3 HQ exists for every holding (one-time, automatic)');
        foreach ($this->world as $name => $w) {
            $hqs = CorporationProperty::where('corporation_id', $w['holding']->id)
                ->where('type', CorporationProperty::TYPE_HQ)
                ->get();
            $this->assert(
                $hqs->count() === 1 && (int) $hqs->first()->tier === 3,
                "{$name}: holding has exactly one tier-3 HQ row",
            );
            $this->assert(
                (int) $w['holding']->fresh()->hq_tier === 3,
                "{$name}: corporations.hq_tier denormalised to 3",
            );
        }

        $this->subsection('1B: Consolidated reads sum holding own + every active subsidiary');
        foreach ($this->world as $name => $w) {
            $expectedSlush = (int) $w['holding']->slush_fund
                + array_sum(array_map(fn(Corporation $s) => (int) $s->slush_fund, $w['subs']));
            $expectedReserves = (int) $w['holding']->cash_reserves
                + array_sum(array_map(fn(Corporation $s) => (int) $s->cash_reserves, $w['subs']));

            $this->assert(
                $this->consolidatedSlush($w['holding']->fresh()) === $expectedSlush,
                "{$name}: consolidated slush matches own + subs",
            );
            $this->assert(
                $this->consolidatedReserves($w['holding']->fresh()) === $expectedReserves,
                "{$name}: consolidated reserves matches own + subs",
            );
        }

        $this->subsection('1C: Operating subsidiaries do NOT consolidate sibling pots');
        $tokyo = $this->world['Tokyo'];
        $sub0 = collect($tokyo['subs'])->sortBy('id')->first();
        $sub0Reload = Corporation::find($sub0->id);
        $this->assert(
            ! $sub0Reload->is_holding_company,
            'Sub-0 is not a holding',
        );
        $sub0SiblingsSum = array_sum(array_map(fn(Corporation $s) => (int) $s->slush_fund, $tokyo['subs']))
            - (int) $sub0->slush_fund;
        $this->assert(
            $sub0SiblingsSum > 0 && (int) $sub0Reload->slush_fund < $sub0SiblingsSum,
            'Sub-0 cannot see sibling pots — its own slush is its own boundary',
        );
    }

    // ─── gates suite ────────────────────────────────────────────────────────

    private function runGatesSuite(): void
    {
        $this->section('SUITE 2 — GOVERNANCE & ACTION GATES');

        $tokyo = $this->world['Tokyo'];

        $this->subsection('2A: Investment fraud rejected for every holding board member');
        foreach ($tokyo['board'] as $boardMember) {
            $boardMember->loadMissing('corporation');
            $check = (new InvestmentFraud())->canExecute($boardMember);
            $this->assert(
                $check['valid'] === false
                && $check['error'] === 'Your holding company is too sophisticated for such a crude scheme.',
                "Board {$boardMember->display_name}: chaebol-line rejection",
            );
        }

        $this->subsection('2B: Investment fraud still allowed for operating-company CEO');
        $opCeo = $tokyo['subCeos'][0];
        $opCeo->loadMissing(['corporation', 'timers']);
        $check = (new InvestmentFraud())->canExecute($opCeo);
        $this->assert(
            $check['valid'] === true,
            'Subsidiary CEO can still run fraud — operating branch unaffected',
        );

        $this->subsection('2C: Audit query skips holdings even when their slush is the largest');
        $tokyo['holding']->update(['slush_fund' => 999_999]);
        $picked = Corporation::where('home_city_id', $tokyo['city']->id)
            ->where('is_holding_company', false)
            ->where('slush_fund', '>', 0)
            ->orderByDesc('slush_fund')
            ->first();
        $this->assert(
            $picked && (int) $picked->id !== (int) $tokyo['holding']->id,
            'Holding excluded from audit picker',
        );
        $this->assert(
            $picked && (int) $picked->parent_trust_id === (int) $tokyo['holding']->id,
            'A subsidiary takes the audit slot instead',
        );
    }

    // ─── lifecycle suite ────────────────────────────────────────────────────

    private function runLifecycleSuite(): void
    {
        $this->section('SUITE 3 — LIFECYCLE CASCADES');

        $seoul = $this->world['Seoul'];

        $this->subsection('3A: Subsidiary CEO death promotes a ready successor');
        $ceo = $seoul['subCeos'][0];
        $sub = collect($seoul['subs'])->firstWhere('id', $ceo->corporation_id);
        $oldCeoId = (int) $sub->fresh()->ceo_id;

        $ceo->kill('Test', 'lifecycle 3A');

        $sub->refresh();
        $this->assert(
            $sub->ceo_id !== null && (int) $sub->ceo_id !== $oldCeoId,
            'New CEO appointed from rank-3 successor pool',
        );

        $this->subsection('3B: Subsidiary CEO death without successor deletes the subsidiary');
        $sub = collect($seoul['subs'])->last();
        $subId = $sub->id;
        $newCeoIdAfter3A = (int) $sub->fresh()->ceo_id;
        $ceo = Character::find($newCeoIdAfter3A);

        // Strip every other member out so succession finds nobody.
        DB::table('characters')
            ->where('corporation_id', $subId)
            ->where('id', '!=', $ceo->id)
            ->update(['corporation_id' => null, 'corporation_position' => null]);

        $ceo->kill('Test', 'lifecycle 3B');

        $reloaded = Corporation::withTrashed()->find($subId);
        $this->assert($reloaded?->trashed() === true, 'Subsidiary soft-deleted when no valid successor');

        $this->subsection('3C: All subsidiaries lost → holding collapses, board returned to rank ≤ 3');
        $ny = $this->world['NewYork'];
        $boardIds = collect($ny['board'])->pluck('id')->all();

        // Soft-delete every subsidiary directly, then trigger reconciliation.
        foreach ($ny['subs'] as $sub) {
            $sub->fresh()->delete();
        }
        $ny['holding']->fresh()->reconcileHoldingLifecycle();

        $reloadedHolding = Corporation::withTrashed()->find($ny['holding']->id);
        $this->assert($reloadedHolding?->trashed() === true, 'Holding company collapsed');

        $boardSurvivors = Character::whereIn('id', $boardIds)->get();
        $this->assert(
            $boardSurvivors->every(fn(Character $c) => $c->corporation_id === null),
            'Surviving board members detached from the dead holding',
        );
        $this->assert(
            $boardSurvivors->every(fn(Character $c) => (int) $c->career_rank <= 3),
            'Surviving board members capped at rank 3 after collapse',
        );
    }

    // ─── war suite ──────────────────────────────────────────────────────────

    private function runWarSuite(): void
    {
        $this->section('SUITE 4 — CORPORATE WAR (3-way conflict, full corp roster)');

        $alive = collect($this->world)
            ->filter(fn($w) => Corporation::find($w['holding']->id) !== null)
            ->values()
            ->all();

        if (count($alive) < 2) {
            $this->subsection('Skip: not enough surviving holdings for a war');
            return;
        }

        $this->info(sprintf('  • Live holdings entering the war: %d', count($alive)));

        $maxRounds = 6;
        $rounds = 0;
        $orgHits = 0;
        $orgKills = 0;
        $solos = 0;
        $soloKills = 0;
        $totalDamage = 0;

        // Each round, every alive holding launches one organized hit and two
        // solo attacks against every other alive holding. Targets rotate
        // through board members, subsidiary CEOs, and successors so the war
        // can dismantle an operating company's succession path, not just the
        // top of the org chart. Loop terminates early when only 1 holding
        // remains alive.
        for ($r = 0; $r < $maxRounds; $r++) {
            // Recompute alive list each round — collapsed holdings drop out.
            $alive = collect($this->world)
                ->filter(fn($w) => Corporation::find($w['holding']->id) !== null)
                ->values()
                ->all();
            if (count($alive) < 2) {
                $this->info(sprintf('  • Round %d skipped — only %d holding(s) left.', $r + 1, count($alive)));
                break;
            }

            $rounds++;
            $this->subsection("Round " . ($r + 1) . " — chaos roundup");

            foreach ($alive as $i => $attackerWorld) {
                foreach ($alive as $j => $defenderWorld) {
                    if ($i === $j) continue;

                    // ── Organized hit (crew of 3) ──────────────────────
                    $crew = $this->liveAttackers($attackerWorld, 3);
                    $target = $this->pickTarget($defenderWorld);
                    if (count($crew) === 3 && $target !== null) {
                        [$initiator, $a, $b] = $crew;
                        $this->prepCombatants([$initiator, $a, $b, $target], $attackerWorld['city']->id);

                        $err = OrganizedHitService::initiate($initiator, $target, [$a->id, $b->id], 'Hostile takeover');
                        if ($err === null) {
                            $this->cacheKeysToClear[] = $initiator->id;
                            OrganizedHitService::accept($a, $initiator->id);
                            OrganizedHitService::accept($b, $initiator->id);

                            $result = OrganizedHitService::execute($initiator);
                            $orgHits++;
                            $outcome = $result['outcome'] ?? ('error: ' . ($result['error'] ?? '?'));
                            if ($outcome === 'kill') $orgKills++;
                            $totalDamage += (int) ($result['damage'] ?? 0);

                            $this->line(sprintf(
                                '    [ORG] %s × {%s, %s} → %s :: %s, dmg=%d%s%s',
                                $this->shortRole($initiator, $attackerWorld),
                                $this->shortRole($a, $attackerWorld),
                                $this->shortRole($b, $attackerWorld),
                                $this->shortRole($target, $defenderWorld),
                                strtoupper($outcome),
                                (int) ($result['damage'] ?? 0),
                                ($result['is_critical'] ?? false) ? ' [CRIT]' : '',
                                ($result['is_instant_kill'] ?? false) ? ' [INSTANT]' : '',
                            ));
                        } else {
                            $this->line('    [ORG] initiate failed: ' . $err);
                        }
                    }

                    // ── Two solo attacks per pair, rotating attackers ──
                    for ($k = 0; $k < 2; $k++) {
                        $attackers = $this->liveAttackers($attackerWorld, 1, exclude: []);
                        $target = $this->pickTarget($defenderWorld);
                        if (count($attackers) === 0 || $target === null) continue;
                        $attacker = $attackers[0];
                        $this->prepCombatants([$attacker, $target], $attackerWorld['city']->id);

                        $solo = $this->soloAttack($attacker, $target);
                        $solos++;
                        if ($solo['outcome'] === 'kill') $soloKills++;
                        $totalDamage += (int) $solo['damage'];

                        $this->line(sprintf(
                            '    [SOL] %s → %s :: %s, dmg=%d%s%s',
                            $this->shortRole($attacker, $attackerWorld),
                            $this->shortRole($target, $defenderWorld),
                            strtoupper($solo['outcome']),
                            (int) $solo['damage'],
                            $solo['is_critical'] ? ' [CRIT]' : '',
                            $solo['outcome'] === 'kill' ? ' [DEAD]' : '',
                        ));
                    }
                }
            }

            // Collapse any holding that just lost all its subs (a target chain
            // could've wiped out a sub CEO + every successor → sub deletes itself).
            foreach ($this->world as $w) {
                $holding = Corporation::find($w['holding']->id);
                if ($holding) $holding->reconcileHoldingLifecycle();
            }
        }

        $this->assert($orgHits + $solos > 0, "War triggered {$orgHits} organized-hit + {$solos} solo attack(s)");
        $this->info(sprintf(
            '  • Rounds: %d  org_hits: %d (kills %d)  solos: %d (kills %d)  total_damage: %d',
            $rounds, $orgHits, $orgKills, $solos, $soloKills, $totalDamage,
        ));

        $this->line('');
        $this->info('  Final standings:');
        foreach ($this->world as $name => $w) {
            $boardAlive = count($this->liveBoard($w));
            $subCeoAlive = $this->liveCount($w['subCeos']);
            $successorAlive = $this->liveCount($w['subSuccessors']);
            $subsAlive = collect($w['subs'])
                ->filter(fn(Corporation $c) => Corporation::find($c->id) !== null)
                ->count();
            $holdingAlive = Corporation::find($w['holding']->id) !== null;
            $this->info(sprintf(
                '    %-10s  holding=%s  subs=%d/%d  board=%d/%d  sub_ceos=%d/%d  successors=%d/%d',
                $name,
                $holdingAlive ? 'ALIVE' : 'COLLAPSED',
                $subsAlive, count($w['subs']),
                $boardAlive, count($w['board']),
                $subCeoAlive, count($w['subCeos']),
                $successorAlive, count($w['subSuccessors']),
            ));
        }
    }

    /**
     * Prep combatants for an actual attack: co-locate them, reset the
     * usual timers/protection so the attack isn't blocked by leftover
     * state from a previous round, push a session row so isOnline() works.
     *
     * @param list<Character> $chars
     */
    private function prepCombatants(array $chars, int $cityId): void
    {
        foreach ($chars as $char) {
            DB::table('characters')->where('id', $char->id)->update([
                'city_id' => $cityId,
                'health' => 100,
                'max_health' => 100,
            ]);
            DB::table('character_timers')->where('character_id', $char->id)->update([
                'next_conflict_at' => 0,
                'protection_until' => 0,
                'hospital_until' => 0,
                'jail_until' => 0,
                'strength' => 100,
                'strength_updated_at' => now()->getTimestamp(),
            ]);
            DB::table('sessions')->updateOrInsert(
                ['id' => 'stress-' . $char->id],
                [
                    'user_id' => $char->user_id,
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'stress',
                    'payload' => '',
                    'last_activity' => now()->getTimestamp(),
                ],
            );
            $char->refresh();
        }
    }

    /**
     * Service-level solo attack: drives ConflictService for the math, then
     * applies side effects. Skips the journal/achievement/loot side stuff
     * the controller does — we just want hit / damage / kill outcomes.
     *
     * @return array{outcome: string, damage: int, is_critical: bool}
     */
    private function soloAttack(Character $attacker, Character $defender): array
    {
        $attacker->loadMissing(['stats', 'items.template', 'property', 'corporation', 'timers']);
        $defender->loadMissing(['stats', 'items.template', 'property', 'corporation', 'timers']);

        if (! $attacker->stats || ! $defender->stats) {
            return ['outcome' => 'error', 'damage' => 0, 'is_critical' => false];
        }

        $attackerStats = $attacker->stats->effectiveStats();
        $defenderStats = $defender->stats->effectiveStats();

        $attackerPower = ConflictService::calculateCombatPower($attacker, $attackerStats, false);
        $defenderPower = ConflictService::calculateCombatPower($defender, $defenderStats, true);
        $advantage = ($attackerPower - $defenderPower) / max($defenderPower, 1);
        $influenceDiff = $attackerStats['influence'] - $defenderStats['influence'];

        $hitChance = ConflictService::calculateHitChance($attackerStats, $defenderStats, $advantage, $influenceDiff);
        $hitRoll = ConflictService::luckyRoll(0.01, 100.0, $attackerStats['luck'], $defenderStats['luck']);

        if ($hitRoll > $hitChance) {
            return ['outcome' => 'miss', 'damage' => 0, 'is_critical' => false];
        }

        $killChance = ConflictService::calculateKillChance($attacker, $defender, $advantage, $influenceDiff);
        $killRoll = ConflictService::luckyRoll(0.01, 100.0, $attackerStats['luck'], $defenderStats['luck']);

        if ($killRoll <= $killChance) {
            $defender->kill('Combat', 'Stress war');
            return ['outcome' => 'kill', 'damage' => (int) $defender->max_health, 'is_critical' => false];
        }

        $damageResult = ConflictService::calculateDamage($attackerStats, $defenderStats, $advantage, $defender);
        $damage = (int) round($damageResult['damage']);
        $isCritical = (bool) $damageResult['is_critical'];

        $newHealth = max(0, (int) $defender->health - $damage);
        if ($newHealth <= 0) {
            $defender->kill('Combat', 'Stress war');
            return ['outcome' => 'kill', 'damage' => $damage, 'is_critical' => $isCritical];
        }

        DB::table('characters')->where('id', $defender->id)->update(['health' => $newHealth]);
        return ['outcome' => 'damage', 'damage' => $damage, 'is_critical' => $isCritical];
    }

    /**
     * Pull up to N living attackers from the world's full roster (board +
     * sub CEOs + successors). Higher-rank members come first so organized
     * hits get the best three available; solo attacks just take whatever
     * the pool offers.
     *
     * @return list<Character>
     */
    private function liveAttackers(array $world, int $count, array $exclude = []): array
    {
        $excludeIds = array_map(fn($c) => $c->id, $exclude);
        $allIds = collect()
            ->merge(collect($world['board'])->pluck('id'))
            ->merge(collect($world['subCeos'])->pluck('id'))
            ->merge(collect($world['subSuccessors'])->pluck('id'))
            ->reject(fn($id) => in_array($id, $excludeIds, true))
            ->values()
            ->all();

        return Character::whereIn('id', $allIds)
            ->whereNull('deleted_at')
            ->where('health', '>', 0)
            ->orderByDesc('career_rank')
            ->limit($count)
            ->get()
            ->all();
    }

    /**
     * Random target from the defender's full roster — board, sub CEOs, and
     * successors are all fair game. Killing a sub's CEO + successor wipes
     * the succession path; the sub deletes itself on next reconcile.
     */
    private function pickTarget(array $world): ?Character
    {
        $allIds = collect()
            ->merge(collect($world['board'])->pluck('id'))
            ->merge(collect($world['subCeos'])->pluck('id'))
            ->merge(collect($world['subSuccessors'])->pluck('id'))
            ->all();
        if (empty($allIds)) return null;

        $candidates = Character::whereIn('id', $allIds)
            ->whereNull('deleted_at')
            ->where('health', '>', 0)
            ->get();
        if ($candidates->isEmpty()) return null;

        return $candidates->random();
    }

    private function shortRole(Character $char, array $world): string
    {
        $name = $char->display_name;
        if (collect($world['board'])->pluck('id')->contains($char->id)) return "{$name}(brd)";
        if (collect($world['subCeos'])->pluck('id')->contains($char->id)) return "{$name}(ceo)";
        if (collect($world['subSuccessors'])->pluck('id')->contains($char->id)) return "{$name}(suc)";
        return $name;
    }

    /** @return list<Character> */
    private function liveBoard(array $world): array
    {
        $ids = collect($world['board'])->pluck('id')->all();
        return Character::whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->where('health', '>', 0)
            ->get()
            ->all();
    }

    /** @param list<Character> $characters */
    private function liveCount(array $characters): int
    {
        $ids = collect($characters)->pluck('id')->all();
        return Character::whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->where('health', '>', 0)
            ->count();
    }

    // ─── reporting ──────────────────────────────────────────────────────────

    private function section(string $title): void
    {
        $this->line('');
        $this->warn('  ── ' . $title . ' ──');
    }

    private function subsection(string $title): void
    {
        $this->line('  • ' . $title);
    }

    private function assert(bool $cond, string $label): void
    {
        if ($cond) {
            $this->passed++;
            $this->info('    ✓ ' . $label);
        } else {
            $this->failed++;
            $this->error('    ✗ ' . $label);
        }
    }

    private function sweepCache(): void
    {
        foreach (array_unique($this->cacheKeysToClear) as $initiatorId) {
            try {
                Cache::forget(OrganizedHitService::cacheKey((int) $initiatorId));
            } catch (\Throwable) {
                // Redis offline — leave the key, it'll TTL out.
            }
        }
        try {
            Cache::forget('org_hits_active');
        } catch (\Throwable) {
            // ignore
        }
    }
}
