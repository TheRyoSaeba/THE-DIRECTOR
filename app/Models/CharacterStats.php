<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
//TODO influence achievement is not working
class CharacterStats extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'influence',
        'intelligence',
        'offense',
        'defense',
        'luck',
    ];

    protected $casts = [
        'influence' => 'float',
        'intelligence' => 'integer',
        'offense' => 'integer',
        'defense' => 'integer',
        'luck' => 'integer',
    ];

    public $timestamps = true;

    protected static function booted(): void
    {
        static::updated(function (self $stats) {
            if ($stats->isDirty('influence')) {
                $new = (float) $stats->influence;
                $old = (float) $stats->getOriginal('influence');

                if (floor($new) > floor($old)) {
                    $character = $stats->character;
                    $user = (auth()->id() === $character?->user_id) ? auth()->user() : $character?->user;
                    $user?->checkAchievementThresholds('influence', (int) floor($new), $character);
                }
            }
        });
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function baseStats(): array
    {
        return [
            'influence' => (float) $this->influence,
            'intelligence' => $this->intelligence,
            'offense' => $this->offense,
            'defense' => $this->defense,
            'luck' => $this->luck,
        ];
    }

    /**
     * Character Stats Breakdown
     * 
     * ! Base Stats - Raw 
     *   influence: 27     
     *   intelligence: 3133 
     *   offense: 2592       
     *   defense: 7262       
     *   luck: 3147          
     * 
     * ? Bonuses - Temporary 
     *   influence: 0        
     *   intelligence: 0     
     *   offense: +7         
     *   defense: 0          
     *   luck: 0             
     * 
     * * Effective - Final 
     *   influence: 27       
     *   intelligence: 3133  
     *   offense: 2773       
     *   defense: 7262       
     *   luck: 3147          
     */

    public function effectiveStats(): array
    {
        $base = $this->baseStats();
        $bonuses = $this->calculateBonuses();

        $effective = [
            'influence' => round($base['influence'] * (1 + $bonuses['influence'] / 100), 2),
            'intelligence' => (int) round($base['intelligence'] * (1 + $bonuses['intelligence'] / 100)),
            'offense' => (int) round($base['offense'] * (1 + $bonuses['offense'] / 100)),
            'defense' => (int) round($base['defense'] * (1 + $bonuses['defense'] / 100)),
            'luck' => (int) round($base['luck'] * (1 + $bonuses['luck'] / 100)),
        ];

        if (config('app.debug')) {
            Log::debug('Character Stat Calculation', [
                'character_id' => $this->character_id,
                'base' => $base,
                'bonuses' => $bonuses,
                'effective' => $effective,
            ]);
        }

        return $effective;
    }

    public function calculateBonuses(): array
    {
        $bonuses = [
            'influence' => 0,
            'intelligence' => 0,
            'offense' => 0,
            'defense' => 0,
            'luck' => 0,
        ];

        // Defensive: any of these missing means the caller forgot to eager-load
        // and is about to fire 4-9 extra queries per character. Logged in debug
        // mode so we can grep `laravel.log | grep CharacterStats` and find the
        // call site. Production keeps the path silent so a missing relation
        // degrades to lazy load instead of an error.
        if (config('app.debug')) {
            $missing = [];
            if (!$this->relationLoaded('character')) {
                $missing[] = 'character';
            }
            $c = $this->relationLoaded('character') ? $this->getRelation('character') : null;
            if ($c) {
                if (!$c->relationLoaded('career'))                              $missing[] = 'character.career';
                if ($c->corporation_id && !$c->relationLoaded('corporation'))   $missing[] = 'character.corporation';
                if (!$c->relationLoaded('homeCity'))                            $missing[] = 'character.homeCity';
                if (!$c->relationLoaded('items'))                               $missing[] = 'character.items';
                if ($c->relationLoaded('corporation') && $c->corporation) {
                    if (!$c->corporation->relationLoaded('properties'))          $missing[] = 'character.corporation.properties';
                    if ($c->corporation->is_holding_company && !$c->corporation->relationLoaded('activeSubsidiaries')) {
                        $missing[] = 'character.corporation.activeSubsidiaries';
                    }
                }
            }
            if ($missing) {
                \Illuminate\Support\Facades\Log::warning('[CharacterStats] calculateBonuses() called without preloaded relations — N+1 risk.', [
                    'character_id'      => $this->character_id,
                    'missing_relations' => $missing,
                    'trace'             => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
                ]);
            }
        }

        $character = $this->relationLoaded('character')
            ? $this->getRelation('character')
            : $this->character;

        if ($character && $character->isInHomeCity()) {
            $propertyBonuses = Property::effectiveBonuses($character);
            $bonuses['influence'] += $propertyBonuses['influence'];
            $bonuses['intelligence'] += $propertyBonuses['intelligence'];
            $bonuses['offense'] += $propertyBonuses['offense'];
            $bonuses['defense'] += $propertyBonuses['defense'];
        }

        if ($character) {
            $equippedItems = $character->items
                ->where('is_equipped', true);

            foreach ($equippedItems as $item) {
                $template = $item->template;

                $bonuses['offense'] += $template->offense ?? 0;
                $bonuses['defense'] += $template->defense ?? 0;
                $bonuses['intelligence'] += $template->intelligence ?? 0;
                $bonuses['influence'] += $template->influence ?? 0;
                $bonuses['luck'] += $template->luck ?? 0;

                if ($template->data && isset($template->data['bonuses'])) {
                    foreach ($template->data['bonuses'] as $stat => $value) {
                        if (isset($bonuses[$stat])) {
                            $bonuses[$stat] += $value;
                        }
                    }
                }
            }
        }
        if ($character && $character->corporation_id) {
            $character->loadMissing('corporation.properties', 'corporation.activeSubsidiaries');
            $corp = $character->corporation;
            if (!$corp) {
                return $bonuses;
            }

            $inHomeCity         = $character->city_id === $corp->home_city_id;
            $isOperatingCompany = $corp->isOperatingCompany();

            if ($isOperatingCompany) {
                $memberCount    = $corp->member_count;
                $hasDefaultedHQ = $corp->hasDefaultedHQ();

                // HQ bonus only applies in the corporation home city with 2+ members.
                if ($inHomeCity && $memberCount >= 2) {
                    $hqBonus = CorporationProperty::HeadquartersBonus($corp);
                    if ($hqBonus > 0) {
                        $bonuses['intelligence'] += $hqBonus;
                        $bonuses['offense']      += $hqBonus;
                        $bonuses['defense']      += $hqBonus;
                        $bonuses['luck']         += $hqBonus;
                    }
                }

                // Solo/defaulted-HQ penalty; holding companies use phase-two rules.
                if ($memberCount < 2 || $hasDefaultedHQ) {
                    $bonuses['influence']    -= 15;
                    $bonuses['intelligence'] -= 15;
                    $bonuses['offense']      -= 15;
                    $bonuses['defense']      -= 15;
                    $bonuses['luck']         -= 15;
                }
            }

            // Holding company subsidiary bonus — 10% per active subsidiary.
            if ($inHomeCity && $corp->is_holding_company) {
                $subCount = $corp->activeSubsidiaries->count();
                if ($subCount > 0) {
                    $subBonus = min($subCount, Corporation::MAX_SUBSIDIARIES) * 10;
                    $bonuses['intelligence'] += $subBonus;
                    $bonuses['offense']      += $subBonus;
                    $bonuses['defense']      += $subBonus;
                    $bonuses['luck']         += $subBonus;
                }
            }
        }

        if ($character && strtolower($character->career?->code ?? '') === 'corporation' && !$character->corporation_id) {
            $corpPenalty = -25;
            $bonuses['influence']    += $corpPenalty;
            $bonuses['intelligence'] += $corpPenalty;
            $bonuses['offense']      += $corpPenalty;
            $bonuses['defense']      += $corpPenalty;
            $bonuses['luck']         += $corpPenalty;
        }

        return $bonuses;
    }

    public function addInfluence(float $amount, bool $save = true): void
    {
        $this->influence += $amount;
        if ($this->influence > 150) {
            $this->influence = 150;
        }
        if ($save)
            $this->save();
    }

    public function addIntelligence(int $amount, bool $save = true): void
    {
        $this->intelligence += $amount;
        if ($save)
            $this->save();
    }

    public function addOffense(int $amount, bool $save = true): void
    {
        $this->offense += $amount;
        if ($save)
            $this->save();
    }

    public function addDefense(int $amount, bool $save = true): void
    {
        $this->defense += $amount;
        if ($save)
            $this->save();
    }

    public function addLuck(int $amount, bool $save = true): void
    {
        $this->luck += $amount;
        if ($save)
            $this->save();
    }

    public function removeInfluence(float $amount, bool $save = true): void
    {
        $this->influence = max(0, $this->influence - $amount);
        if ($save)
            $this->save();
    }

    public function getTotalStats(): int
    {
        return (int) floor((float) $this->influence) + $this->intelligence + $this->offense + $this->defense + $this->luck;
    }
}
