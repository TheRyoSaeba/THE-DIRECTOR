<?php

namespace App\Http\Controllers;

use App\Models\BannedUser;
use App\Models\Announcement;
use App\Models\Business;
use App\Models\Character;
use App\Models\Message;
use App\Models\User;
use App\Support\SafeCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function admined(Request $request): \Illuminate\Http\RedirectResponse|Response
    {
        $ip = $request->ip();
        $cookieValue = $request->cookie('trust_token');

        $isBanned = BannedUser::isBanned('ip', $ip)
            || ($cookieValue && BannedUser::isBanned('cookie', $cookieValue));

        if (!$isBanned) {
            return redirect()->route('login');
        }

        // Whether the restriction on this identifier is temporary. We expose
        // only the boolean — never the exact lift time — to anonymous visitors
        // on this page: a real ban-evader shouldn't be handed a retry schedule,
        // but a falsely-flagged user (e.g. on a shared IP) deserves to know the
        // block isn't permanent.
        $temporary = BannedUser::query()
            ->where(function ($q) use ($ip, $cookieValue) {
                $q->where(fn($w) => $w->where('type', 'ip')->where('value', $ip));
                if ($cookieValue) {
                    $q->orWhere(fn($w) => $w->where('type', 'cookie')->where('value', $cookieValue));
                }
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereNotNull('expires_at')
            ->exists();

        return Inertia::render('Conflict/Admined', [
            'temporary' => $temporary,
        ]);
    }

    public function dashboard(Request $request): \Illuminate\Http\RedirectResponse|Response
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return redirect()->route('character.create');
        }

        if ($this->isOnlyInertiaPartial($request, 'census')) {
            return Inertia::render('Dashboard', [
                'census' => Inertia::optional(fn() => $this->buildCensus()),
            ]);
        }

        if ($character->can_promote) {
            return redirect()->route('career.promote');
        }

        $city = $character->city;

        $journals = $character->journals()->latest()->take(5)->get()->map(
            fn($j) => [
                'id' => $j->id,
                'title' => $j->title,
                'description' => $j->description,
                'created_at' => $j->formatted_date,
                'type' => $j->type,
                'icon' => $j->icon,
                'color_class' => $j->color_class,
            ],
        );

        $messages = Message::where('recipient_id', $character->id)
            ->with('sender:id,display_name')
            ->latest()
            ->take(2)
            ->get()
            ->map(
                fn($m) => [
                    'id' => $m->id,
                    'sender_name' => $m->sender?->display_name ?? 'Unknown',
                    'body' => Str::limit($m->body, 50),
                    'created_at' => $m->created_at->diffForHumans(),
                ],
            );

        $unreadMessageCount = SafeCache::remember(
            "unread_messages_{$character->id}",
            30,
            fn() => Message::where('recipient_id', $character->id)
                ->whereNull('read_at')
                ->count(),
            0,
        );

        $bankInterest = Business::forCity($city, 'bank')?->getSetting(
            'loan_interest',
            0.5,
        ) ?? 0.5;

        // Must match DEGREE_CONFIG hex values in University.tsx exactly.
        $regaliaColors = [
            'finance' => '#B87333', // copper
            'law' => '#800080', // purple
            'medicine' => '#4CBB17', // kelly green
            'engineering' => '#e86231', // engineering orange
            'business' => '#af795f', // commerce drab
            'police_academy' => '#38bdf8',
            'customs' => '#22d3ee',
        ];

        $allDegrees = collect($character->degrees ?? [])
            ->map(function ($d, $code) use ($regaliaColors) {
                $code = strtolower((string) $code);
                $requiredCycles = $this->educationRequiredCycles($code);
                $cycles = (int) ($d['cycles'] ?? 0);

                return [
                    'code' => $code,
                    'name' => Str::headline($code),
                    'color' => $regaliaColors[$code] ?? '#22d3ee',
                    'completed' => ! empty($d['completed_at']),
                    'progress' => $requiredCycles > 0
                        ? min(100, (int) round(($cycles / $requiredCycles) * 100))
                        : 0,
                ];
            });

        return Inertia::render('Dashboard', [
            'dashboard' => [
                'journal' => $journals,
                'messages' => $messages,
                'unreadMessageCount' => $unreadMessageCount,
                'bank_interest_rate' => $bankInterest,
                'active_degrees' => $allDegrees->filter(fn($d) => !$d['completed'])->values(),
                'completed_degrees' => $allDegrees->filter(fn($d) => $d['completed'])->values(),
            ],
           
            'census' => Inertia::optional(fn() => $this->buildCensus()),
        ]);
    }

    /**
     * Unsubscribe handler for system announcement emails.
     *
     * No auth: the URL is protected by Laravel's signed-URL middleware
     * (the SHA hash in the query string is verified against the app key).
     * A user who's drifted off and lost their password can still opt out
     * with a click — which is the compliance requirement we can't satisfy
     * with an in-app preference toggle alone.
     */
    public function unsubscribe(Request $request, User $user): Response
    {
        if (!$user->email_opt_out_at) {
            $user->forceFill(['email_opt_out_at' => now()])->save();
        }

        return Inertia::render('Unsubscribed', [
            'email' => $user->email,
        ]);
    }

    public function announcements(Request $request): \Illuminate\Http\RedirectResponse|Response
    {
        if (!$request->user()->getLoadedCharacter()) {
            return redirect()->route('character.create');
        }

        return Inertia::render('Announcements', [
            'announcements' => Announcement::visible()
                ->orderByDesc('published_at')
                ->paginate(5, [
                    'id',
                    'title',
                    'message',
                    'type',
                    'published_at',
                    'expires_at',
                ])
                ->withQueryString(),
        ]);
    }

 
    private function buildCensus(): array
    {
        return SafeCache::remember('census:global', 600, fn() => $this->computeCensus(), $this->emptyCensus());
    }

    private function computeCensus(): array
    {
        $totalPlayers = (int) DB::table('characters')->whereNull('deleted_at')->count();
        $totalCorps = (int) DB::table('corporations')->whereNull('deleted_at')->count();

        $careerCounts = DB::table('characters')
            ->join('careers', 'characters.career_id', '=', 'careers.id')
            ->whereNull('characters.deleted_at')
            ->select('careers.name as label', DB::raw('COUNT(*) as count'))
            ->groupBy('careers.id', 'careers.name')
            ->orderByDesc('count')
            ->get()
            ->map(fn($r) => ['label' => $r->label, 'count' => (int) $r->count])
            ->all();

        $cityCounts = DB::table('characters')
            ->join('cities', 'characters.home_city_id', '=', 'cities.id')
            ->whereNull('characters.deleted_at')
            ->select('cities.name as label', DB::raw('COUNT(*) as count'))
            ->groupBy('cities.id', 'cities.name')
            ->orderByDesc('count')
            ->get()
            ->map(fn($r) => ['label' => $r->label, 'count' => (int) $r->count])
            ->all();

        $genderCounts = DB::table('characters')
            ->whereNull('deleted_at')
            ->select('gender as label', DB::raw('COUNT(*) as count'))
            ->groupBy('gender')
            ->orderByDesc('count')
            ->get()
            ->map(fn($r) => ['label' => $r->label, 'count' => (int) $r->count])
            ->all();

        return [
            'total_players' => $totalPlayers,
            'total_corps' => $totalCorps,
            'by_career' => $careerCounts,
            'by_city' => $cityCounts,
            'by_gender' => $genderCounts,
            'computed_at' => now()->utc()->toIso8601String(),
        ];
    }

    private function emptyCensus(): array
    {
        return [
            'total_players' => 0,
            'total_corps' => 0,
            'by_career' => [],
            'by_city' => [],
            'by_gender' => [],
            'computed_at' => now()->utc()->toIso8601String(),
        ];
    }

    public function help(): Response
    {
        $staff = User::where('is_admin', true)
            ->with([
                'character:id,display_name,custom_avatar_url,career_id,career_rank,glow_color,user_id',
                'character.user:id,is_admin,username',
            ])
            ->get()
            ->map(fn(User $user) => [
                'name' => $user->character?->display_name ?? $user->name,
                'role' => 'Admin',
                'avatar_url' => $user->character?->avatar_url,
                'glow_color' => $user->character?->glow_color ?? 'cyan',
                'contact' => null,
            ])
            ->values()
            ->all();

        return Inertia::render('Help', compact('staff'));
    }

    public function openMessageByName(string $displayName): \Illuminate\Http\RedirectResponse
    {
        $character = Character::whereRaw('LOWER(display_name) = ?', [strtolower($displayName)])->first();

        if (!$character) {
            return redirect()->route('messages')->with('error', 'Character not found');
        }

        return redirect()->route('messages.show', ['conversationId' => 'user_' . $character->id]);
    }

    public function banned(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Conflict/Banned', [
            'ban_reason' => $user->ban_reason ?? 'No reason provided',
            'banned_at' => $user->banned_at?->toIso8601String(),
            'banned_until' => $user->banned_until?->toIso8601String(),
        ]);
    }

    public function hospital(Request $request): Response
    {
        $character = $request->user()->getLoadedCharacter();

        return Inertia::render('Conflict/Hospital', [
            'release_time' => $character?->timers?->hospital_until?->getTimestamp(),
            'reason' => $character?->timers?->hospital_reason,
        ]);
    }

    private function educationRequiredCycles(string $code): int
    {
        return match (strtolower($code)) {
            'police_academy' => (int) config('timers.police_training_cycles', 30),
            'customs' => (int) config('timers.customs_training_cycles', 10),
            default => (int) config("timers.degree_cycles.$code", 30),
        };
    }
}
