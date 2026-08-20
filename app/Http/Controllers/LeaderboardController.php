<?php

namespace App\Http\Controllers;

use App\Models\Leaderboard;
use Inertia\Inertia;

class LeaderboardController extends Controller
{
    public function index()
    {
        $base = fn ($e) => [
            'display_name'          => $e->display_name ?? '—',
            'avatar_url'            => $e->avatar_url ?? '',
            'glow_color'            => $e->glow_color ?? 'cyan',
            'career_name'           => $e->career_name ?? '—',
            'rank_name'             => $e->rank_name ?? '—',
            'home_city_name'        => $e->home_city_name ?? '—',
            'corporation_name'      => $e->corporation_name,
            'corporation_image_url' => $e->corporation_image_url,
            'corporation_position'  => $e->corporation_position,
            'rating'                => (int) ($e->rating ?? 1),
        ];

        $current = Leaderboard::where('is_historical', false)
            ->orderByDesc('rating')
            ->orderByDesc('total_earns')
            ->limit(100)
            ->get()
            ->map($base)
            ->values();

        $historical = Leaderboard::where('is_historical', true)
            ->orderByDesc('rating')
            ->orderByDesc('total_earns')
            ->limit(100)
            ->get()
            ->map(fn ($e) => $base($e) + [
                'kills'       => (int) ($e->kills ?? 0),
                'total_works' => (int) ($e->total_earns ?? 0),
                'born_at'     => $e->born_at?->utc()->format('d/m/y'),
                'died_at'     => $e->died_at?->utc()->format('d/m/y'),
            ])
            ->values();

        return Inertia::render('Leaderboard', [
            'current'    => $current,
            'historical' => $historical,
        ]);
    }
}
