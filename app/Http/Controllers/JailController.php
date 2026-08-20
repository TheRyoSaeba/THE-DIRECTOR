<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\CareerRank;
use App\Support\SafeCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class JailController extends Controller
{

    private const WORKS = [
        [
            'id' => 'warehouse_work',
            'label' => 'Warehouse Work',
            'min_exp' => 0,
            'xp' => 75,
            'offense' => 25,
            'payout_min' => 5,
            'payout_max' => 15,
            'message' => 'You spent your shift hauling crates around the prison warehouse, stacking pallets until your back ached. The work is dehumanizing and heartbreaking but at least you made $:payout this time.',
        ],
        [
            'id' => 'inmate_orderly',
            'label' => 'Inmate Orderly',
            'min_exp' => 20000,
            'xp' => 150,
            'offense' => 50,
            'payout_min' => 25,
            'payout_max' => 30,
            'message' => 'You worked the block as an orderly, mopping floors and running errands for the guards. The trust you\'ve earned pays out $:payout this shift.',
        ],
    ];

    public function index(Request $request): \Inertia\Response
    {
        $character = $request->user()->character;

        $inmateRows = Character::whereHas('timers', function ($q) {
                $q->whereNotNull('jail_until')
                  ->where('jail_until', '>', now()->getTimestamp());
            })
            ->where('id', '!=', $character->id)
            ->with([
                'timers:character_id,jail_until',
                'city:id,name',
            ])
            ->get(['id', 'display_name', 'custom_avatar_url', 'career_id', 'career_rank', 'city_id']);

        $careerIds  = $inmateRows->pluck('career_id')->filter()->unique()->values()->all();
        $rankLevels = $inmateRows->pluck('career_rank')->filter()->unique()->values()->all();
        $rankMap    = CareerRank::bulkLoadForCharacters($careerIds, $rankLevels);

        $inmates = $inmateRows->map(function (Character $c) use ($rankMap) {
            $rankKey = "{$c->career_id}_{$c->career_rank}";
            $rankArt = $rankMap[$rankKey] ?? null;

            return [
                'display_name' => $c->display_name ?? 'Unknown',
                'avatar_url'   => $c->custom_avatar_url ?: ($rankArt['avatar_url'] ?? null),
                'city'         => $c->city?->name ?? 'Unknown',
                'jail_until'   => $c->timers?->jail_until?->getTimestamp() ?? null,
                'cell_block'   => self::cellBlock($c->id, $c->timers?->jail_until?->getTimestamp()),
            ];
        });

        $jailUntil = $character->timers?->jail_until?->getTimestamp();
        $totalExp  = (int) $character->total_character_exp;

        $works = array_map(fn (array $w) => [
            'id'      => $w['id'],
            'label'   => $w['label'],
            'locked'  => $totalExp < $w['min_exp'],
            'min_exp' => $w['min_exp'],
        ], self::WORKS);

        return Inertia::render('Conflict/Jail', [
            'release_time' => $jailUntil,
            'cell_block'   => self::cellBlock($character->id, $jailUntil),
            'inmates'      => $inmates,
            'works'        => $works,
        ]);
    }

    public function work(Request $request): RedirectResponse
    {
        $workId = (string) $request->validate([
            'work_id' => 'required|string',
        ])['work_id'];

        $character = $request->user()->character;

        return DB::transaction(function () use ($character, $workId) {
            $timer = DB::table('character_timers')
                ->where('character_id', $character->id)
                ->lockForUpdate()
                ->first(['next_work_at', 'jail_until']);

            if ($timer->jail_until <= now()->getTimestamp()) {
                return back()->with('error', 'You can only do prison work while jailed.');
            }

            if ($character->timers->next_work_at->isFuture()) {
                return back()->with('error', 'You need to wait before working again');
            }

            $work = $this->findWork($workId);
            if (! $work) {
                return back()->with('error', 'You must choose a work detail.');
            }

            if ((int) $character->total_character_exp < (int) $work['min_exp']) {
                return back()->with('error', $work['label'] . ' is not available to you yet.');
            }

            $payout = random_int($work['payout_min'], $work['payout_max']);

            DB::table('characters')
                ->where('id', $character->id)
                ->update([
                    'cash_on_hand' => DB::raw('cash_on_hand + ' . $payout),
                    'total_character_exp' => DB::raw('total_character_exp + ' . (int) $work['xp']),
                ]);

            $stats = $character->stats;
            if ($stats) {
                $stats->addOffense((int) $work['offense'], false);
                $stats->save();
            }

            DB::table('character_timers')
                ->where('character_id', $character->id)
                ->update(['next_work_at' => now()->addSeconds(config('timers.work'))->getTimestamp()]);

            $message = str_replace(':payout', number_format($payout), $work['message']);

            return back()->with('success', $message);
        });
    }

    private function findWork(string $id): ?array
    {
        foreach (self::WORKS as $work) {
            if ($work['id'] === $id) {
                return $work;
            }
        }

        return null;
    }

    private const CELL_BLOCKS = ['A', 'B', 'C'];

    public static function cellBlock(int $characterId, ?int $jailUntil): string
    {
        if (! $jailUntil) {
            return self::CELL_BLOCKS[0];
        }

        $key = "jail:block:{$characterId}:{$jailUntil}";

        return SafeCache::remember(
            $key,
            $jailUntil - now()->getTimestamp() + 3600,
            fn () => self::CELL_BLOCKS[array_rand(self::CELL_BLOCKS)],
        );
    }
}
