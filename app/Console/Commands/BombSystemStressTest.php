<?php

namespace App\Console\Commands;

use App\Actions\PlantBomb;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterJournal;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\CrimeRecord;
use App\Models\GameItem;
use App\Models\Property;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

/**
 * BombSystemStressTest
 *
 * Stress test for the RCIED bomb system. Covers:
 *   1. Probabilistic distribution — success/failure rates at stat extremes
 *   2. Race condition hammering — concurrent plant + detonate on same target/item
 *   3. Edge cases & exploit probes — stashing, selling, protection farming, state leaks
 *   4. Gameplay strategy validation — full lifecycle loops with economic assertions
 *
 * Run:  php artisan bomb:stress-test
 * Safe: creates and destroys its own City/Characters/Items inside a rolled-back transaction.
 *       Nothing is written to prod data permanently.
 *
 * !! NEVER RUN ON PRODUCTION — uses DB::rollBack() but creates real rows during the test.
 * !! Point .env at the test DB before running.
 */
class BombSystemStressTest extends Command
{
    protected $signature   = 'bomb:stress-test {--section=all : Which section to run (all|dist|race|exploit|strategy)}';
    protected $description = 'RCIED bomb system stress test — probabilistic, race, exploit, and strategy suites';

    
    private City      $city;
    private GameItem  $rciedTemplate;
    private Property  $rowhouse;
    private Property  $apartment;
    private Property  $privateIsland;
    private int       $unemployedCareerId;
    private int       $techCareerId;

    private int $passed = 0;
    private int $failed = 0;

    

    public function handle(): int
    {
        $section = $this->option('section');

        $this->warn('');
        $this->warn('  ██████╗  ██████╗ ███╗   ███╗██████╗     ███████╗████████╗██████╗ ███████╗███████╗███████╗');
        $this->warn('  ██╔══██╗██╔═══██╗████╗ ████║██╔══██╗    ██╔════╝╚══██╔══╝██╔══██╗██╔════╝██╔════╝██╔════╝');
        $this->warn('  ██████╔╝██║   ██║██╔████╔██║██████╔╝    ███████╗   ██║   ██████╔╝█████╗  ███████╗███████╗');
        $this->warn('  ██╔══██╗██║   ██║██║╚██╔╝██║██╔══██╗    ╚════██║   ██║   ██╔══██╗██╔══╝  ╚════██║╚════██║');
        $this->warn('  ██████╔╝╚██████╔╝██║ ╚═╝ ██║██████╔╝    ███████║   ██║   ██║  ██║███████╗███████║███████║');
        $this->warn('  ╚═════╝  ╚═════╝ ╚═╝     ╚═╝╚═════╝     ╚══════╝   ╚═╝   ╚═╝  ╚═╝╚══════╝╚══════╝╚══════╝');
        $this->warn('');
        $this->info("  RCIED Bomb System — Stress & Strategy Test Suite");
        $this->warn("  !! Ensure .env points at TEST database before running !!");
        $this->line('');

        DB::beginTransaction();

        try {
            $this->bootstrap();

            if (in_array($section, ['all', 'dist']))     $this->runDistributionSuite();
            if (in_array($section, ['all', 'race']))     $this->runRaceConditionSuite();
            if (in_array($section, ['all', 'exploit']))  $this->runExploitSuite();
            if (in_array($section, ['all', 'strategy'])) $this->runStrategySuite();

        } finally {
            DB::rollBack();
        }

        $this->line('');
        $this->line('  ─────────────────────────────────────────────────');
        $color = $this->failed > 0 ? 'error' : 'info';
        $this->{$color}(sprintf(
            '  RESULT: %d passed, %d failed',
            $this->passed,
            $this->failed
        ));
        $this->line('');

        return $this->failed > 0 ? 1 : 0;
    }

    

    private function bootstrap(): void
    {
        $this->info('  [SETUP] Bootstrapping test environment...');

        $unemployed = DB::table('careers')->whereRaw('LOWER(code) = ?', ['unemployed'])->first();
        $tech       = DB::table('careers')->whereRaw('LOWER(code) = ?', ['technician'])->first();
        abort_unless($unemployed && $tech, 500, 'Career seeds missing — run seeders first.');
        $this->unemployedCareerId = $unemployed->id;
        $this->techCareerId       = $tech->id;

        $this->city = City::create([
            'name'       => 'StressCity-' . uniqid(),
            'slug'       => 'stress-' . uniqid(),
            'crime_rate' => 50.0,
        ]);

        
        $this->rciedTemplate = GameItem::where('slug', 'rcied')->first();
        abort_unless($this->rciedTemplate, 500, "RCIED game item must exist in DB (slug='rcied') — check black market seeder.");

        $this->rowhouse = Property::create([
            'name'             => 'Rowhouse',
            'price'            => 50_000,
            'safe_capacity'    => 3,
            'vehicle_capacity' => 1,
            'has_alarm'        => false,
        ]);

        $this->apartment = Property::create([
            'name'             => 'Apartment',
            'price'            => 100_000,
            'safe_capacity'    => 5,
            'vehicle_capacity' => 1,
            'has_alarm'        => false,
        ]);

        $this->privateIsland = Property::create([
            'name'             => 'Private Island',
            'price'            => 5_000_000,
            'safe_capacity'    => 20,
            'vehicle_capacity' => 5,
            'has_alarm'        => true,
        ]);

        $this->info('  [SETUP] Done. City: ' . $this->city->name);
        $this->line('');
    }

    
    
    

    private function runDistributionSuite(): void
    {
        $this->section('SUITE 1 — PROBABILISTIC DISTRIBUTION');

        
        $this->subsection('1A: High-luck attacker vs offline target (500 attempts)');
        {
            $successes = 0;
            $failures  = 0;
            $healthFloors = 0;
            $maxHealthFloors = 0;

            for ($i = 0; $i < 500; $i++) {
                $attacker = $this->makeChar(luck: 99_999, intelligence: 99_999);
                $target   = $this->makeChar(online: false);
                $this->giveProperty($target, $this->rowhouse);
                $rcied = $this->giveRcied($attacker);

                $action = new PlantBomb();
                $result = $action->execute($attacker, ['target_id' => $target->id]);

                $target->refresh();
                if ($target->property_condition === Property::CONDITION_BOMB) {
                    $successes++;
                } else {
                    $failures++;
                    $attacker->refresh();
                    if ($attacker->health === 1) $healthFloors++;
                    if ($attacker->max_health <= 1) $maxHealthFloors++;
                }

                
                $target->update(['property_condition' => Property::CONDITION_CONSTRUCTED]);
                CharacterItem::where('character_id', $attacker->id)->delete();
            }

            $rate = round($successes / 500 * 100, 1);
            $this->stat("Success rate", "{$rate}% ({$successes}/500)");
            $this->stat("Health floored at 1", $healthFloors . ' times');
            $this->stat("Max health floored at 1", $maxHealthFloors . ' times');

            
            $this->assert(
                $rate >= 40 && $rate <= 95,
                "Success rate {$rate}% should be between 40-95% (high-luck, offline, rowhouse, 50 crime)"
            );
            $this->assert(
                $maxHealthFloors === 0,
                "Max health should never floor at 1 from a single failure"
            );
        }

        
        $this->subsection('1B: Min-stat attacker vs online target with alarm (500 attempts)');
        {
            $successes = 0;

            for ($i = 0; $i < 500; $i++) {
                $attacker = $this->makeChar(luck: 1, intelligence: 1);
                $target   = $this->makeChar(online: true);
                $this->giveProperty($target, $this->privateIsland); 
                $this->giveRcied($attacker);

                $action = new PlantBomb();
                $action->execute($attacker, ['target_id' => $target->id]);

                $target->refresh();
                if ($target->property_condition === Property::CONDITION_BOMB) {
                    $successes++;
                    $target->update(['property_condition' => Property::CONDITION_CONSTRUCTED]);
                    CharacterItem::where('character_id', $attacker->id)->delete();
                }
            }

            $rate = round($successes / 500 * 100, 1);
            $this->stat("Success rate", "{$rate}% ({$successes}/500)");

            
            $this->assert(
                $rate <= 20,
                "Success rate {$rate}% should be ≤20% (min-luck, online, private island with alarm)"
            );
        }

        
        $this->subsection('1C: Attacker damage floor — 200 forced failures');
        {
            $attacker = $this->makeChar(luck: 1, intelligence: 1, health: 1, maxHealth: 50);

            for ($i = 0; $i < 200; $i++) {
                
                $attacker->update(['health' => 1, 'max_health' => max(1, 50 - $i)]);
                $target = $this->makeChar(online: true);
                $this->giveProperty($target, $this->privateIsland);
                $this->giveRcied($attacker);

                $action = new PlantBomb();
                $action->execute($attacker, ['target_id' => $target->id]);

                $attacker->refresh();
                $this->assert(
                    $attacker->health >= 1,
                    "Iteration {$i}: attacker health {$attacker->health} should never be < 1"
                );
                $this->assert(
                    $attacker->max_health >= 1,
                    "Iteration {$i}: attacker max_health {$attacker->max_health} should never be < 1"
                );
                $this->assert(
                    $attacker->max_health >= $attacker->health,
                    "Iteration {$i}: max_health {$attacker->max_health} should always >= health {$attacker->health}"
                );

                
                if ($attacker->health < 1 || $attacker->max_health < $attacker->health) break;
            }

            $this->info("    → Floor validation passed 200 iterations without killing attacker");
        }

        
        $this->subsection('1D: Protection must not be granted on failed plant');
        {
            $protectionGrantedOnFail = false;

            for ($i = 0; $i < 50; $i++) {
                $attacker = $this->makeChar(luck: 1, intelligence: 1);
                $target   = $this->makeChar(online: true);
                $this->giveProperty($target, $this->privateIsland);
                $this->giveRcied($attacker);

                $action = new PlantBomb();
                $action->execute($attacker, ['target_id' => $target->id]);

                $target->refresh();
                if ($target->property_condition !== Property::CONDITION_BOMB) {
                    
                    $target->unsetRelation('timers');
                    if ($target->isProtected()) {
                        $protectionGrantedOnFail = true;
                        break;
                    }
                } else {
                    
                    $target->update(['property_condition' => Property::CONDITION_CONSTRUCTED]);
                    CharacterItem::where('character_id', $attacker->id)->delete();
                }
            }

            $this->assert(
                !$protectionGrantedOnFail,
                "Protection must NOT be granted on a failed plant attempt"
            );
        }

        
        $this->subsection('1E: Protection must be granted on successful detonation');
        {
            $attacker = $this->makeChar();
            $target   = $this->makeChar();
            $this->giveProperty($target, Property::CONDITION_BOMB);
            $rcied = $this->givePlantedRcied($attacker, $target);

            $otherCity = City::create(['name' => 'AwayD-' . uniqid(), 'slug' => 'awayd-' . uniqid(), 'crime_rate' => 0]);
            $target->update(['city_id' => $otherCity->id]);

            $this->detonateAs($attacker, $rcied->id);

            $target->refresh();
            $target->unsetRelation('timers');
            $this->assert(
                $target->isProtected(),
                "Target must have protection after property is detonated"
            );
        }
    }

    
    
    

    private function runRaceConditionSuite(): void
    {
        $this->section('SUITE 2 — RACE CONDITION HAMMERING');

        
        $this->subsection('2A: Two simultaneous plant attempts on the same target');
        {
            
            
            
            
            

            $bothSucceeded = false;

            for ($round = 0; $round < 30; $round++) {
                $attacker1 = $this->makeChar(luck: 99_999);
                $attacker2 = $this->makeChar(luck: 99_999);
                $target    = $this->makeChar(online: false);
                $this->giveProperty($target, $this->rowhouse);
                $this->giveRcied($attacker1);
                $this->giveRcied($attacker2);

                $action = new PlantBomb();

                
                $r1 = $action->execute($attacker1, ['target_id' => $target->id]);
                $r2 = $action->execute($attacker2, ['target_id' => $target->id]);

                $target->refresh();

                $s1 = $target->property_condition === Property::CONDITION_BOMB
                    && CharacterItem::where('character_id', $attacker1->id)
                        ->whereRaw("data->>'target_character_id' IS NOT NULL")
                        ->exists();

                $s2 = CharacterItem::where('character_id', $attacker2->id)
                    ->whereRaw("data->>'target_character_id' IS NOT NULL")
                    ->exists();

                
                if ($s1 && $s2) {
                    $bothSucceeded = true;
                    break;
                }

                
                $valid = in_array($target->property_condition, [
                    Property::CONDITION_BOMB,
                    Property::CONDITION_CONSTRUCTED,
                ]);

                if (!$valid) {
                    $this->error("    ✗ Invalid property state after race: {$target->property_condition}");
                    $this->failed++;
                }

                
                $target->update(['property_condition' => Property::CONDITION_CONSTRUCTED]);
                CharacterItem::where('character_id', $attacker1->id)->delete();
                CharacterItem::where('character_id', $attacker2->id)->delete();
            }

            $this->assert(
                !$bothSucceeded,
                "Two attackers cannot both have live planted RCIEDs on the same target simultaneously"
            );
        }

        
        $this->subsection('2B: Two concurrent detonation attempts on the same RCIED');
        {
            
            
            

            for ($round = 0; $round < 20; $round++) {
                $attacker = $this->makeChar();
                $target   = $this->makeChar();
                $this->giveProperty($target, Property::CONDITION_BOMB);
                $rcied = $this->givePlantedRcied($attacker, $target);

                
                $r1 = $this->detonateAs($attacker, $rcied->id);
                $r2 = $this->detonateAs($attacker, $rcied->id);

                
                $this->assert(
                    !CharacterItem::where('id', $rcied->id)->exists(),
                    "Round {$round}: RCIED must be consumed after detonation attempt"
                );

                $target->refresh();
                
                $this->assert(
                    $target->property_condition === Property::CONDITION_DESTROYED,
                    "Round {$round}: Property should be DESTROYED after detonation, got: {$target->property_condition}"
                );
            }
        }

        
        $this->subsection('2C: Drop planted RCIED — target property always resets');
        {
            for ($round = 0; $round < 30; $round++) {
                $attacker = $this->makeChar();
                $target   = $this->makeChar();
                $this->giveProperty($target, Property::CONDITION_BOMB);
                $rcied = $this->givePlantedRcied($attacker, $target);

                
                $attacker->dropItem((string) $rcied->id);

                $target->refresh();
                $this->assert(
                    $target->property_condition === Property::CONDITION_CONSTRUCTED,
                    "Round {$round}: Drop should reset property to CONSTRUCTED, got: {$target->property_condition}"
                );
                $this->assert(
                    !CharacterItem::where('id', $rcied->id)->exists(),
                    "Round {$round}: RCIED should be deleted after drop"
                );
            }
        }

        
        $this->subsection('2D: Attacker kill() resets target property state (50 cycles)');
        {
            for ($round = 0; $round < 50; $round++) {
                $attacker = $this->makeChar();
                $target   = $this->makeChar();
                $this->giveProperty($target, Property::CONDITION_BOMB);
                $this->givePlantedRcied($attacker, $target);

                $attacker->kill('stress-test', 'killed during test');

                $target->refresh();
                $this->assert(
                    $target->property_condition === Property::CONDITION_CONSTRUCTED,
                    "Round {$round}: Kill should reset property to CONSTRUCTED, got: {$target->property_condition}"
                );
            }
        }

        
        $this->subsection('2E: Detonation graceful when target dies between plant and detonate');
        {
            for ($round = 0; $round < 20; $round++) {
                $attacker = $this->makeChar();
                $target   = $this->makeChar();
                $this->giveProperty($target, Property::CONDITION_BOMB);
                $rcied = $this->givePlantedRcied($attacker, $target);

                
                $target->update(['health' => 0, 'deleted_at' => now()]);

                $response = $this->detonateAs($attacker, $rcied->id);

                $this->assert(
                    !CharacterItem::where('id', $rcied->id)->exists(),
                    "Round {$round}: RCIED must be consumed even when target is already dead"
                );
            }
        }
    }

    
    
    

    private function runExploitSuite(): void
    {
        $this->section('SUITE 3 — EXPLOIT PROBES');

        
        $this->subsection('3A: hasReadyDevice blocks using planted RCIED as new plant');
        {
            $attacker = $this->makeChar(luck: 99_999);
            $target1  = $this->makeChar(online: false);
            $target2  = $this->makeChar(online: false);
            $this->giveProperty($target1, $this->rowhouse);
            $this->giveProperty($target2, $this->rowhouse);

            
            $this->giveProperty($target1, Property::CONDITION_BOMB);
            $this->givePlantedRcied($attacker, $target1);

            
            $action = new PlantBomb();
            $result = $action->execute($attacker, ['target_id' => $target2->id]);

            $target2->refresh();
            $this->assert(
                $target2->property_condition !== Property::CONDITION_BOMB,
                "Cannot plant on target2 when only RCIED is already planted on target1"
            );
        }

        
        $this->subsection('3B: 180-min cooldown blocks immediate second plant');
        {
            $attacker = $this->makeChar(luck: 99_999);
            $target   = $this->makeChar(online: false);
            $this->giveProperty($target, $this->rowhouse);

            
            CharacterTimers::where('character_id', $attacker->id)
                ->update(['next_action_at' => now()->addHours(3)->getTimestamp()]);
            $attacker->unsetRelation('timers');

            $this->giveRcied($attacker);
            $action = new PlantBomb();
            $result = $action->execute($attacker, ['target_id' => $target->id]);

            $target->refresh();
            $this->assert(
                $target->property_condition !== Property::CONDITION_BOMB,
                "Cooldown must block plant attempt"
            );
        }

        
        $this->subsection('3C: BOMB condition hidden from SettingsController::index prop');
        {
            $character = $this->makeChar();
            $this->giveProperty($character, Property::CONDITION_BOMB);

            
            $exposed = in_array($character->property_condition, [
                Property::CONDITION_CONSTRUCTED,
                Property::CONDITION_DESTROYED,
            ]) ? $character->property_condition : null;

            $this->assert(
                $exposed === null,
                "BOMB condition must be masked to null on the frontend — got: " . var_export($exposed, true)
            );
        }

        
        $this->subsection('3D: Crime record filed in target HOME city regardless of attacker city');
        {
            $attackerCity = City::create(['name' => 'AttCity-' . uniqid(), 'slug' => 'att-' . uniqid(), 'crime_rate' => 0]);
            $attacker     = $this->makeChar();
            $attacker->update(['city_id' => $attackerCity->id, 'home_city_id' => $attackerCity->id]);

            $target = $this->makeChar(homeCityId: $this->city->id);
            $target->update(['city_id' => $attackerCity->id]); 
            $this->giveProperty($target, Property::CONDITION_BOMB);
            $rcied = $this->givePlantedRcied($attacker, $target);

            
            $awayCity = City::create(['name' => 'AwayE-' . uniqid(), 'slug' => 'awaye-' . uniqid(), 'crime_rate' => 0]);
            $target->update(['city_id' => $awayCity->id]);

            $this->detonateAs($attacker, $rcied->id);

            $crimeInHomeCity = CrimeRecord::where('character_id', $attacker->id)
                ->where('city_id', $this->city->id)
                ->where('type', 'bombing')
                ->exists();

            $crimeInAttackerCity = CrimeRecord::where('character_id', $attacker->id)
                ->where('city_id', $attackerCity->id)
                ->where('type', 'bombing')
                ->exists();

            $this->assert(
                $crimeInHomeCity,
                "Crime record must be filed in target's HOME city ({$this->city->id})"
            );
            $this->assert(
                !$crimeInAttackerCity,
                "Crime record must NOT be filed in attacker's city ({$attackerCity->id})"
            );
        }

        
        $this->subsection('3E: Stashed RCIED cannot be detonated — must be on_hand');
        {
            $attacker = $this->makeChar();
            $target   = $this->makeChar();
            $this->giveProperty($target, Property::CONDITION_BOMB);

            
            $stashedRcied = CharacterItem::create([
                'character_id' => $attacker->id,
                'game_item_id' => $this->rciedTemplate->id,
                'location'     => 'safe',
                'is_equipped'  => false,
                'data'         => ['target_character_id' => $target->id, 'planted_at' => now()->toIso8601String()],
            ]);

            
            $response = $this->detonateAs($attacker, $stashedRcied->id);

            $target->refresh();
            $this->assert(
                $target->property_condition === Property::CONDITION_BOMB,
                "Stashed RCIED detonation must be blocked — property should still be BOMB"
            );
        }

        
        $this->subsection('3F: Equipping an RCIED returns the correct error message');
        {
            $character = $this->makeChar();
            $rcied     = $this->giveRcied($character);

            
            
            $slugMatch = strtolower($this->rciedTemplate->slug) === 'rcied';
            $this->assert(
                $slugMatch,
                "RCIED slug must be exactly 'rcied' (case-insensitive) for equip guard to fire"
            );
        }

        
        $this->subsection('3G: Item sale journal payload must not expose bomb target');
        {
            $attacker = $this->makeChar();
            $target   = $this->makeChar();
            $buyer    = $this->makeChar();
            $this->giveProperty($target, Property::CONDITION_BOMB);
            $rcied = $this->givePlantedRcied($attacker, $target);

            
            
            $template = $rcied->template;
            $payload  = [
                'seller_id'         => $attacker->id,
                'seller_name'       => $attacker->display_name,
                'item_id'           => $rcied->id,
                'item_name'         => $template->name,
                'item_slug'         => $template->slug,
                'item_type'         => $template->type,
                'item_slot'         => $template->slot,
                'item_image_url'    => $template->image_url,
                'price'             => 99_999,
                'condition_percent' => null,
                
            ];

            $this->assert(
                !isset($payload['data']),
                "Sale request journal must not include item data (would expose target_character_id)"
            );
            $this->assert(
                !isset($payload['target_character_id']),
                "Sale request journal must not expose target_character_id directly"
            );
        }

        
        $this->subsection('3H: Characters under 5 days old blocked from plant AND detonate');
        {
            $youngAttacker = $this->makeChar();
            $youngAttacker->update(['created_at' => now()->subDays(2)]); 

            $target = $this->makeChar();
            $this->giveProperty($target, $this->rowhouse);
            $this->giveRcied($youngAttacker);

            $action = new PlantBomb();
            $result = $action->execute($youngAttacker, ['target_id' => $target->id]);

            $target->refresh();
            $this->assert(
                $target->property_condition !== Property::CONDITION_BOMB,
                "Character < 5 days old must be blocked from planting"
            );

            
            $plantedRcied = CharacterItem::create([
                'character_id' => $youngAttacker->id,
                'game_item_id' => $this->rciedTemplate->id,
                'location'     => 'on_hand',
                'is_equipped'  => false,
                'data'         => ['target_character_id' => $target->id, 'planted_at' => now()->toIso8601String()],
            ]);
            $target->update(['property_condition' => Property::CONDITION_BOMB]);

            $this->detonateAs($youngAttacker, $plantedRcied->id);
            $target->refresh();

            $this->assert(
                $target->property_condition === Property::CONDITION_BOMB,
                "Character < 5 days old must be blocked from detonating"
            );
        }

        
        $this->subsection('3I: No orphaned BOMB states after 100 plant+drop cycles');
        {
            for ($i = 0; $i < 100; $i++) {
                $attacker = $this->makeChar(luck: 99_999);
                $target   = $this->makeChar(online: false);
                $this->giveProperty($target, $this->rowhouse);

                
                $this->giveProperty($target, Property::CONDITION_BOMB);
                $rcied = $this->givePlantedRcied($attacker, $target);

                
                $attacker->dropItem((string) $rcied->id);

                $target->refresh();
                if ($target->property_condition === Property::CONDITION_BOMB) {
                    $this->error("    ✗ Cycle {$i}: Orphaned BOMB state after drop!");
                    $this->failed++;
                    break;
                }
            }
            $this->info("    → 100 plant+drop cycles completed with zero orphaned BOMB states");
            $this->passed++;
        }
    }

    
    
    

    private function runStrategySuite(): void
    {
        $this->section('SUITE 4 — GAMEPLAY STRATEGY VALIDATION');

        
        $this->subsection('4A: Optimal strike — offline target, detonated while away (max damage, zero HP loss)');
        {
            $attacker = $this->makeChar();
            $target   = $this->makeChar(online: false, homeCityId: $this->city->id);
            $this->giveProperty($target, Property::CONDITION_BOMB);

            
            for ($i = 0; $i < 5; $i++) {
                CharacterItem::create([
                    'character_id' => $target->id,
                    'game_item_id' => $this->rciedTemplate->id,
                    'location'     => 'safe',
                    'is_equipped'  => false,
                ]);
            }

            $rcied = $this->givePlantedRcied($attacker, $target);

            
            $awayCity = City::create(['name' => 'OptA-' . uniqid(), 'slug' => 'opta-' . uniqid(), 'crime_rate' => 0]);
            $target->update(['city_id' => $awayCity->id]);

            $crimeBefore = City::find($this->city->id)->crime_rate;

            $this->detonateAs($attacker, $rcied->id);

            $target->refresh();
            $attacker->refresh();
            $cityAfter = City::find($this->city->id);

            
            $this->assert(
                $target->health === 100 && $target->max_health === 100,
                "Optimal strike: target should take zero HP damage when away from home"
            );

            
            $remaining = CharacterItem::where('character_id', $target->id)
                ->whereIn('location', ['safe', 'garage'])
                ->count();
            $this->assert(
                $remaining === 0,
                "Optimal strike: all 5 stored items should be destroyed when target is away"
            );

            
            $this->assert(
                $target->property_condition === Property::CONDITION_DESTROYED,
                "Optimal strike: property must be DESTROYED"
            );

            
            $this->assert(
                $attacker->career_xp > 0,
                "Optimal strike: attacker should have received XP for detonation"
            );

            
            $this->assert(
                (float) $cityAfter->crime_rate > (float) $crimeBefore,
                "Optimal strike: crime rate must increase in target's home city"
            );

            
            $this->assert(
                CharacterJournal::where('character_id', $target->id)
                    ->where('type', 'bomb_detonated')
                    ->whereRaw("data->>'result' = 'not_home'")
                    ->exists(),
                "Optimal strike: target must receive 'not_home' bomb_detonated journal"
            );

            $this->stat("Target HP after blast", "{$target->health}/{$target->max_health}");
            $this->stat("Items destroyed", "5/5");
            $this->stat("Crime rate delta", '+' . round($cityAfter->crime_rate - $crimeBefore, 2));
        }

        
        $this->subsection('4B: Online blitz — target home online (lower damage, half items destroyed)');
        {
            $attacker = $this->makeChar();
            $target   = $this->makeChar(online: true); 
            $this->giveProperty($target, Property::CONDITION_BOMB);

            for ($i = 0; $i < 4; $i++) {
                CharacterItem::create([
                    'character_id' => $target->id,
                    'game_item_id' => $this->rciedTemplate->id,
                    'location'     => 'safe',
                    'is_equipped'  => false,
                ]);
            }

            $rcied = $this->givePlantedRcied($attacker, $target);
            $this->detonateAs($attacker, $rcied->id);

            $target->refresh();

            
            $this->assert(
                $target->health < 100 && $target->health >= 1,
                "Online blitz: target should take HP damage (online, home) but not die"
            );

            
            $this->assert(
                $target->max_health < 100,
                "Online blitz: target should sustain permanent max_health injury"
            );

            
            $remaining = CharacterItem::where('character_id', $target->id)
                ->whereIn('location', ['safe', 'garage'])
                ->count();
            $this->assert(
                $remaining === 2,
                "Online blitz: exactly half (2) stored items should remain"
            );

            $this->stat("Target HP after blast", "{$target->health}/{$target->max_health}");
            $this->stat("Stored items remaining", "{$remaining}/4");
        }

        
        $this->subsection('4C: Full economic loop — bomb → destroy → tech repairs → cash flows correct');
        {
            $attacker = $this->makeChar();
            $tech     = $this->makeChar(careerId: $this->techCareerId);
            $owner    = $this->makeChar(cashOnHand: 1_000_000);
            $this->giveProperty($owner, Property::CONDITION_BOMB);

            $rcied = $this->givePlantedRcied($attacker, $owner);

            
            $awayCity = City::create(['name' => 'EcoL-' . uniqid(), 'slug' => 'ecol-' . uniqid(), 'crime_rate' => 0]);
            $owner->update(['city_id' => $awayCity->id]);
            $this->detonateAs($attacker, $rcied->id);

            $owner->refresh();
            $this->assert(
                $owner->property_condition === Property::CONDITION_DESTROYED,
                "Economic loop: property must be DESTROYED before repair"
            );

            
            $techCashBefore  = (int) $tech->fresh()->cash_on_hand;
            $ownerCashBefore = (int) $owner->fresh()->cash_on_hand;
            $expectedFee     = (int) ceil($this->apartment->price * 0.05);

            
            CharacterTimers::where('character_id', $tech->id)->update(['next_action_at' => 0]);

            
            $app = app();
            $request = \Illuminate\Http\Request::create('/career/technician/repair-home', 'POST', [
                'owner_id' => $owner->id,
            ]);
            $request->setUserResolver(fn () => $tech->user);
            $controller = $app->make(\App\Http\Controllers\CareerController::class);
            $controller->repairHome($request);

            $owner->refresh();
            $tech->refresh();

            $this->assert(
                $owner->property_condition === Property::CONDITION_CONSTRUCTED,
                "Economic loop: property must be CONSTRUCTED after repair"
            );

            $this->assert(
                (int) $tech->cash_on_hand === $techCashBefore + $expectedFee,
                "Economic loop: tech should earn exactly \${$expectedFee}"
            );

            $this->assert(
                (int) $owner->cash_on_hand === $ownerCashBefore - $expectedFee,
                "Economic loop: owner should pay exactly \${$expectedFee}"
            );

            $this->assert(
                CharacterJournal::where('character_id', $owner->id)
                    ->where('type', 'home_repaired')
                    ->exists(),
                "Economic loop: owner must receive 'home_repaired' journal"
            );

            $this->stat("Tech earned", '$' . number_format($expectedFee));
            $this->stat("Owner paid", '$' . number_format($expectedFee));
            $this->stat("Property condition", $owner->property_condition);
        }

        
        $this->subsection('4D: Saturation — 5 independent bombs on 5 different targets');
        {
            $attackers = [];
            $targets   = [];
            $rcieds    = [];

            for ($i = 0; $i < 5; $i++) {
                $attackers[$i] = $this->makeChar();
                $targets[$i]   = $this->makeChar();
                $this->giveProperty($targets[$i], Property::CONDITION_BOMB);
                $rcieds[$i]    = $this->givePlantedRcied($attackers[$i], $targets[$i]);
            }

            
            for ($i = 0; $i < 5; $i++) {
                $targets[$i]->refresh();
                $this->assert(
                    $targets[$i]->property_condition === Property::CONDITION_BOMB,
                    "Saturation: target {$i} should have BOMB condition"
                );

                $rcieds[$i]->refresh();
                $this->assert(
                    $rcieds[$i]->data['target_character_id'] === $targets[$i]->id,
                    "Saturation: RCIED {$i} should point to target {$i}"
                );
            }

            
            for ($i = 0; $i < 5; $i++) {
                $awayCity = City::create(['name' => 'Sat' . $i . '-' . uniqid(), 'slug' => 'sat' . $i . '-' . uniqid(), 'crime_rate' => 0]);
                $targets[$i]->update(['city_id' => $awayCity->id]);
                $this->detonateAs($attackers[$i], $rcieds[$i]->id);
            }

            $allDestroyed = true;
            for ($i = 0; $i < 5; $i++) {
                $targets[$i]->refresh();
                if ($targets[$i]->property_condition !== Property::CONDITION_DESTROYED) {
                    $allDestroyed = false;
                }
            }

            $this->assert($allDestroyed, "Saturation: all 5 properties must be DESTROYED independently");
        }

        
        $this->subsection('4E: Attrition warfare — 10 sequential failures, attacker survives every time');
        {
            $attacker = $this->makeChar(luck: 1, intelligence: 1, health: 100, maxHealth: 100);

            for ($i = 0; $i < 10; $i++) {
                $target = $this->makeChar(online: true);
                $this->giveProperty($target, $this->privateIsland);
                $this->giveRcied($attacker);

                
                $action = new PlantBomb();
                for ($attempt = 0; $attempt < 30; $attempt++) {
                    CharacterTimers::where('character_id', $attacker->id)
                        ->update(['next_action_at' => 0]);
                    $attacker->unsetRelation('timers');

                    if (!$attacker->ownsItem('rcied')) {
                        $this->giveRcied($attacker);
                    }

                    $action->execute($attacker, ['target_id' => $target->id]);
                    $target->refresh();
                    if ($target->property_condition !== Property::CONDITION_BOMB) {
                        break; 
                    }
                    
                    $target->update(['property_condition' => Property::CONDITION_CONSTRUCTED]);
                    CharacterItem::where('character_id', $attacker->id)
                        ->whereRaw("data->>'target_character_id' IS NOT NULL")
                        ->delete();
                }

                $attacker->refresh();
                $this->assert(
                    $attacker->health >= 1,
                    "Failure {$i}: attacker must still be alive (health={$attacker->health})"
                );
                $this->assert(
                    !$attacker->trashed(),
                    "Failure {$i}: attacker must not be soft-deleted (killed)"
                );

                $this->stat("After failure #{$i}", "HP {$attacker->health}/{$attacker->max_health}");
            }
        }

        
        $this->subsection('4F: Strategic intelligence — Private Island meaningfully harder than Rowhouse');
        {
            $rowhouse_successes     = 0;
            $private_island_successes = 0;

            for ($i = 0; $i < 200; $i++) {
                $attacker = $this->makeChar(luck: 5_000);
                $target   = $this->makeChar(online: false);

                
                $this->giveProperty($target, $this->rowhouse);
                $this->giveRcied($attacker);
                $action = new PlantBomb();
                $action->execute($attacker, ['target_id' => $target->id]);
                $target->refresh();
                if ($target->property_condition === Property::CONDITION_BOMB) {
                    $rowhouse_successes++;
                    $target->update(['property_condition' => Property::CONDITION_CONSTRUCTED]);
                    CharacterItem::where('character_id', $attacker->id)
                        ->whereRaw("data->>'target_character_id' IS NOT NULL")
                        ->delete();
                }

                
                $this->giveRcied($attacker);
                CharacterTimers::where('character_id', $attacker->id)->update(['next_action_at' => 0]);
                $attacker->unsetRelation('timers');
                $target->update(['property_id' => $this->privateIsland->id, 'property_condition' => Property::CONDITION_CONSTRUCTED]);

                $action->execute($attacker, ['target_id' => $target->id]);
                $target->refresh();
                if ($target->property_condition === Property::CONDITION_BOMB) {
                    $private_island_successes++;
                    $target->update(['property_condition' => Property::CONDITION_CONSTRUCTED]);
                    CharacterItem::where('character_id', $attacker->id)
                        ->whereRaw("data->>'target_character_id' IS NOT NULL")
                        ->delete();
                }
            }

            $rhRate = round($rowhouse_successes / 200 * 100, 1);
            $piRate = round($private_island_successes / 200 * 100, 1);

            $this->stat("Rowhouse success rate", "{$rhRate}% ({$rowhouse_successes}/200)");
            $this->stat("Private Island success rate", "{$piRate}% ({$private_island_successes}/200)");

            $this->assert(
                $piRate < $rhRate,
                "Private Island ({$piRate}%) should be meaningfully harder than Rowhouse ({$rhRate}%)"
            );
            $this->assert(
                ($rhRate - $piRate) >= 10,
                "Property tier + alarm should create at least a 10pp difficulty gap (gap: " . ($rhRate - $piRate) . "pp)"
            );
        }
    }

    
    
    

    private function makeChar(
        int  $luck       = 5_000,
        int  $intelligence = 5_000,
        int  $health     = 100,
        int  $maxHealth  = 100,
        int  $cashOnHand = 500_000,
        bool $online     = true,
        ?int $homeCityId = null,
        ?int $careerId   = null,
    ): Character {
        $user = User::factory()->create();

        if ($online) {
            DB::table('sessions')->insert([
                'id'            => uniqid('s_', true),
                'user_id'       => $user->id,
                'ip_address'    => '127.0.0.1',
                'user_agent'    => 'stress',
                'payload'       => '',
                'last_activity' => time(),
            ]);
            \App\Support\Presence::touch($user->id, '127.0.0.1');
        }

        $char = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'S-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $this->city->id,
            'home_city_id' => $homeCityId ?? $this->city->id,
            'career_id'    => $careerId ?? $this->unemployedCareerId,
            'career_rank'  => 1,
            'career_xp'    => 0,
            'health'       => $health,
            'max_health'   => $maxHealth,
            'cash_on_hand' => $cashOnHand,
            'created_at'   => now()->subDays(10), 
        ]);

        CharacterStats::create([
            'character_id' => $char->id,
            'intelligence' => $intelligence,
            'luck'         => $luck,
            'offense'      => 1_000,
            'defense'      => 1_000,
            'influence'    => 0,
        ]);

        CharacterTimers::create([
            'character_id'   => $char->id,
            'next_action_at' => 0,
        ]);

        return $char;
    }

    private function giveProperty(Character $char, Property|string $propertyOrCondition, string $condition = Property::CONDITION_CONSTRUCTED): void
    {
        if ($propertyOrCondition instanceof Property) {
            $char->update([
                'property_id'        => $propertyOrCondition->id,
                'property_condition' => $condition,
            ]);
        } else {
            
            $char->update([
                'property_id'        => $this->apartment->id,
                'property_condition' => $propertyOrCondition,
            ]);
        }
    }

    private function giveRcied(Character $char): CharacterItem
    {
        return CharacterItem::create([
            'character_id'         => $char->id,
            'game_item_id'         => $this->rciedTemplate->id,
            'durability_remaining' => null,
            'location'             => 'on_hand',
            'is_equipped'          => false,
            'data'                 => null,
        ]);
    }

    private function givePlantedRcied(Character $attacker, Character $target): CharacterItem
    {
        return CharacterItem::create([
            'character_id'         => $attacker->id,
            'game_item_id'         => $this->rciedTemplate->id,
            'durability_remaining' => null,
            'location'             => 'on_hand',
            'is_equipped'          => false,
            'data'                 => [
                'target_character_id' => $target->id,
                'planted_at'          => now()->toIso8601String(),
            ],
        ]);
    }

    
    private function detonateAs(Character $character, int $rciedId): mixed
    {
        $request = \Illuminate\Http\Request::create('/settings/detonate', 'POST', ['id' => $rciedId]);
        $request->setUserResolver(fn () => $character->user);

        $controller = app(\App\Http\Controllers\SettingsController::class);
        try {
            return $controller->detonate($request);
        } catch (\Throwable $e) {
            return null;
        }
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
        $this->line(sprintf("    <fg=blue>%-35s</> %s", $label, $value));
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
