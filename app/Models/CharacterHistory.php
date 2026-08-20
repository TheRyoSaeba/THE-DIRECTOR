<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;


class CharacterHistory extends Model
{
    protected $table      = 'character_histories';
    protected $primaryKey = 'character_id';
    public    $incrementing = false;

    protected $casts = [
        'career_timeline'  => 'array',
        'kill_log'         => 'array',
        'case_type_counts' => 'array',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    

    
    public static function addHistory(Character $character, string $column, int $amount = 1): void
    {
        DB::statement("
            INSERT INTO character_histories (character_id, {$column}, created_at, updated_at)
            VALUES (?, ?, now(), now())
            ON CONFLICT (character_id) DO UPDATE
            SET {$column}  = character_histories.{$column} + excluded.{$column},
                updated_at = now()
        ", [$character->id, $amount]);
    }

    
    public static function addHistoryCaseType(Character $character, string $crimeType): void
    {
        
        DB::statement("
            INSERT INTO character_histories (character_id, case_type_counts, created_at, updated_at)
            VALUES (?, jsonb_build_object('{$crimeType}', 1), now(), now())
            ON CONFLICT (character_id) DO UPDATE
            SET case_type_counts = jsonb_set(
                    COALESCE(character_histories.case_type_counts, '{}'::jsonb),
                    ARRAY['{$crimeType}']::text[],
                    to_jsonb(COALESCE((character_histories.case_type_counts->>'{$crimeType}')::int, 0) + 1)
                ),
                updated_at = now()
        ", [$character->id]);
    }

    
    public static function appendKill(Character $character, string $targetName): void
    {
        $entry = json_encode([[
            'target' => $targetName,
            'at'     => now()->utc()->format('d/m/y H:i:s') . ' UTC',
        ]]);

        DB::statement("
            INSERT INTO character_histories (character_id, kill_log, created_at, updated_at)
            VALUES (?, ?::jsonb, now(), now())
            ON CONFLICT (character_id) DO UPDATE
            SET kill_log   = character_histories.kill_log || excluded.kill_log,
                updated_at = now()
        ", [$character->id, $entry]);
    }

    
    public static function appendCareerEvent(Character $character, array $event): void
    {
        $event['at'] = now()->utc()->format('d/m/y H:i:s') . ' UTC';
        $entry = json_encode([$event]);

        DB::statement("
            INSERT INTO character_histories (character_id, career_timeline, created_at, updated_at)
            VALUES (?, ?::jsonb, now(), now())
            ON CONFLICT (character_id) DO UPDATE
            SET career_timeline = character_histories.career_timeline || excluded.career_timeline,
                updated_at = now()
        ", [$character->id, $entry]);
    }

    

    
    public static function finalCareerAndRank(int $characterId): array
    {
        $timelineJson = DB::table('character_histories')
            ->where('character_id', $characterId)
            ->value('career_timeline');

        return self::finalCareerAndRankFromTimeline($timelineJson);
    }

    public static function finalCareerAndRankFromTimeline(mixed $timeline): array
    {
        $events = is_array($timeline)
            ? $timeline
            : (json_decode($timeline ?? '[]', true) ?: []);

        $finalCareer = null;
        $finalRank   = null;

        foreach ($events as $event) {
            $type = $event['type'] ?? '';
            if ($type === 'career_started') {
                $finalCareer = $event['career_name'] ?? null;
                $finalRank   = $event['rank_name']   ?? null;
            } elseif ($type === 'promoted') {
                $finalCareer = $event['career_name'] ?? $finalCareer;
                $finalRank   = $event['new_rank']    ?? $finalRank;
            }
        }

        return ['career' => $finalCareer, 'rank' => $finalRank];
    }

    
    public static function forSettingsPage(Character $character): array
    {
        $character->loadMissing(['stats']);

        $ageDays = $character->created_at
            ? (int) floor($character->created_at->diffInDays(now()))
            : null;

        
        $h = DB::table('character_histories')
            ->where('character_id', $character->id)
            ->first();

        
        $events        = $h ? json_decode($h->career_timeline ?? '[]', true) : [];
        $careerHistory = [];
        $current       = null;

        foreach ($events as $event) {
            if (($event['type'] ?? '') === 'career_started') {
                if ($current !== null) {
                    $careerHistory[] = $current;
                }
                $current = [
                    'career_name' => $event['career_name'] ?? '—',
                    'ranks'       => [['rank' => $event['rank_name'] ?? '—', 'at' => $event['at'] ?? null]],
                ];
            } elseif (($event['type'] ?? '') === 'promoted') {
                if ($current === null) {
                    $current = [
                        'career_name' => $event['career_name'] ?? '—',
                        'ranks'       => [['rank' => $event['old_rank'] ?? '—', 'at' => null]],
                    ];
                }
                $current['ranks'][] = ['rank' => $event['new_rank'] ?? '—', 'at' => $event['at'] ?? null];
            }
        }

        if ($current !== null) {
            $careerHistory[] = $current;
        }

        
        if (empty($careerHistory) && $character->career?->code !== 'unemployed') {
            $careerHistory[] = [
                'career_name' => $character->career?->name ?? '—',
                'ranks'       => [['rank' => $character->current_rank?->rank_name ?? '—', 'at' => null]],
            ];
        }

        $careerHistory = array_reverse($careerHistory);

        
        $careerActivity = [];
        if ($h) {
            $candidates = [
                'police' => [
                    'cases_investigated' => (int) $h->cases_investigated,
                    'cases_closed'       => (int) $h->cases_closed,
                    'arrests_made'       => (int) $h->arrests_made,
                ],
                'law' => [
                    'cases_prosecuted' => (int) $h->cases_prosecuted,
                    'cases_defended'   => (int) $h->cases_defended,
                    'cases_acquitted'  => (int) $h->cases_acquitted,
                    'cases_sentenced'  => (int) $h->cases_sentenced,
                    'cases_appealed'   => (int) $h->cases_appealed,
                ],
                'healthcare' => [
                    'surgeries_performed'            => (int) $h->surgeries_performed,
                    'gender_reassignments_performed' => (int) $h->gender_reassignments_performed,
                    'ngri_successes'                 => (int) $h->ngri_successes,
                ],
                'banking' => [
                    'trades_won'         => (int) $h->trades_won,
                    'trades_lost'        => (int) $h->trades_lost,
                    'launders_succeeded' => (int) $h->launders_succeeded,
                    'launders_failed'    => (int) $h->launders_failed,
                ],
                'politics' => [
                    'pardons_issued'     => (int) $h->pardons_issued,
                    'officers_dismissed' => (int) $h->officers_dismissed,
                    'policies_enacted'   => (int) $h->policies_enacted,
                    'terms_served'       => (int) $h->terms_served,
                ],
                'technician' => [
                    'vehicles_repaired'      => (int) $h->vehicles_repaired,
                    'homes_inspected'        => (int) $h->homes_inspected,
                    'properties_constructed' => (int) ($h->properties_constructed ?? 0),
                ],
                'customs' => [
                    'travelers_inspected' => (int) ($h->travelers_inspected ?? 0),
                ],
            ];

            
            foreach ($candidates as $career => $stats) {
                $filtered = array_filter($stats);
                if (! empty($filtered)) {
                    $careerActivity[$career] = $filtered;
                }
            }
        }

        
        $talents = [
            [
                'id'         => 'over_educated',
                'name'       => 'Over Educated',
                'unlocked'   => $character->hasAllDegrees(),
                'times_used' => (int) ($h?->talent_over_educated_used ?? 0),
            ],
            [
                'id'         => 'defense_in_depth',
                'name'       => 'Defense in Depth',
                'unlocked'   => $character->hasDefenseInDepthRequirements(),
                'times_used' => (int) ($h?->talent_defense_in_depth_used ?? 0),
            ],
            [
                'id'         => 'goal_of_all_life',
                'name'       => 'The Goal of All Life is Death',
                'unlocked'   => $character->hasGoalOfAllLifeRequirements(),
                'times_used' => (int) ($h?->talent_goal_of_all_life_used ?? 0),
            ],
            [
                'id'         => 'lazarus_connection',
                'name'       => 'The Lazarus Connection',
                'unlocked'   => $character->hasLazarusConnectionRequirements(),
                'times_used' => (int) ($h?->talent_lazarus_connection_used ?? 0),
            ],
        ];

        return [
            'display_name'   => $character->display_name,
            'gender'         => $character->gender,
            'career'         => $character->career?->name ?? 'Unemployed',
            'rank'           => $character->current_rank?->rank_name ?? 'Entry Level',
            'cash_on_hand'   => $character->cash_on_hand,
            'cash_in_bank'   => $character->cash_in_bank,
            'dirty_cash'     => $character->dirty_cash,
            'age_days'       => $ageDays,
            'created_at'     => $character->created_at?->toIso8601String(),
            'total_earns'    => $character->total_earns ?? 0,
            'finance' => [
                'earned_career'   => (int) ($h?->earned_career   ?? 0),
                'earned_actions'  => (int) ($h?->earned_actions  ?? 0),
                'earned_business' => (int) ($h?->earned_business ?? 0),
            ],
            'convictions'    => \App\Models\CrimeRecord::convictionCount($character->id),
            'combat' => [
                'attacks_landed' => (int) ($h?->attacks_landed ?? 0),
                'attacks_missed' => (int) ($h?->attacks_missed ?? 0),
                'kills'          => (int) ($h?->kills          ?? 0),
                'instant_kills'  => (int) ($h?->instant_kills  ?? 0),
                'critical_hits'  => (int) ($h?->critical_hits  ?? 0),
                'gbh_landed'     => (int) ($h?->gbh_landed     ?? 0),
                'gbh_missed'     => (int) ($h?->gbh_missed     ?? 0),
                'times_hit'      => (int) ($h?->times_hit      ?? 0),
            ],
            'talents'        => $talents,
            'career_history' => $careerHistory,
            'career_activity'=> $careerActivity,
            
            'kill_log'       => $h ? (json_decode($h->kill_log ?? '[]', true) ?: []) : [],
        ];
    }
}
