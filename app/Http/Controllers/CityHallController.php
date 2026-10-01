<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Character;
use App\Models\CityHallAide;
use App\Models\CityHallPost;
use App\Models\MayorTerm;
use App\Support\ForumBodySanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class CityHallController extends Controller
{
    private const MAX_AIDES             = 4;
    private const MAX_ANNOUNCEMENTS     = 10;
    private const RELOCATION_COOLDOWN   = 1;
    private const RELOCATION_AUTO_HOURS = 1;

    // Per-post forum fee bounds. Owner of the City Hall business sets a value in this range
    // and it is charged to the author on every announcement / thread / reply.
    public const POST_FEE_MIN     = 5;
    public const POST_FEE_MAX     = 500;
    public const POST_FEE_DEFAULT = 25;

    

    public function index(Request $request, \App\Models\City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        // Page props are lazy closures: Inertia partial reloads (`only: [...]`)
        // run only the queries of the props they request. Shared lookups are
        // memoized so a full page load still runs each query once.
        $once = static function (\Closure $builder): \Closure {
            $resolved = false;
            $value = null;

            return function () use (&$resolved, &$value, $builder) {
                if (!$resolved) {
                    $value = $builder();
                    $resolved = true;
                }

                return $value;
            };
        };

        // Which page props this request will resolve (all of them unless it is
        // a partial reload of this component). Used only to size the shared
        // aide lookup for bulletin/forum author roles.
        $partialOnly = $request->header('X-Inertia-Partial-Component') === 'City/CityHall'
            ? array_values(array_filter(explode(',', (string) $request->header('X-Inertia-Partial-Data', ''))))
            : [];
        $wants = fn (string $prop) => $partialOnly === [] || in_array($prop, $partialOnly, true);

        $isMayor    = $city->mayor_id !== null && $city->mayor_id === $character->id;
        $isResident = $character->city_id === $city->id;
        $activeTerm = $once(fn () => MayorTerm::activeForCity($city->id));
        $isAide     = $once(fn () => $activeTerm() && CityHallAide::where('mayor_term_id', $activeTerm()->id)
            ->where('character_id', $character->id)
            ->exists());

        $mayorData = function () use ($city, $activeTerm) {
            if (!($city->mayor_id && $city->mayor)) {
                return null;
            }

            return [
                'id'                  => $city->mayor->id,
                'name'                => $city->mayor->display_name,
                'avatar_url'          => $city->mayor->avatar_url,
                'term_period'         => $activeTerm()?->period ?? 1,
                'term_days_remaining' => $activeTerm()
                    ? max(0, (int) ceil(
                        ($activeTerm()->started_at->getTimestamp()
                            + (MayorTerm::PERIODS_PER_TERM * MayorTerm::PERIOD_SECONDS)
                            - now()->getTimestamp()) / 86400
                    ))
                    : 0,
            ];
        };

        $aides = fn () => $activeTerm()
            ? CityHallAide::where('mayor_term_id', $activeTerm()->id)
                ->with('character:id,custom_avatar_url,gender,home_city_id')
                ->orderBy('created_at')
                ->get()
                ->filter(function ($aide) use ($city) {
                    if (! $aide->character || $aide->character->home_city_id !== $city->id) {
                        $aide->delete();
                        return false;
                    }
                    return true;
                })
                ->map(fn ($a) => [
                    'id'         => $a->id,
                    'name'       => $a->display_name,
                    'avatar_url' => $a->character?->avatar_url ?? null,
                ])
                ->values()
                ->all()
            : [];

        $bulletinRows = $once(fn () => CityHallPost::announcements()
            ->where('city_id', $city->id)
            ->with('author:id,custom_avatar_url,gender,career_id,career_rank')
            ->orderByDesc('created_at')
            ->limit(self::MAX_ANNOUNCEMENTS)
            ->get());

        $pastMayors = fn () => MayorTerm::where('city_id', $city->id)
            ->whereNotNull('ended_at')
            ->with('character:id,custom_avatar_url,gender')
            ->orderByDesc('ended_at')
            ->limit(3)
            ->get()
            ->map(fn ($t) => [
                'name'       => $t->character?->display_name ?? 'Former Mayor',
                'avatar_url' => $t->character?->avatar_url ?? null,
                'period'     => (int) $t->period,
                'ended_at'   => $t->ended_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        // Forum threads + their replies (single query each via eager-load).
        $threads = $once(fn () => CityHallPost::forumThreads()
            ->where('city_id', $city->id)
            ->with([
                // career_id + career_rank are needed for the avatar_url accessor
                // (rank avatar fallback) and the current_rank accessor.
                'author:id,custom_avatar_url,gender,career_id,career_rank',
                'replies' => fn ($q) => $q
                    ->with('author:id,custom_avatar_url,gender,career_id,career_rank')
                    ->orderBy('created_at'),
            ])
            ->forumOrdered()
            ->get());

        // Every distinct forum author id (thread + reply authors).
        $forumAuthorIds = $once(function () use ($threads) {
            $characterIds = collect();
            foreach ($threads() as $t) {
                if ($t->character_id) $characterIds->push($t->character_id);
                foreach ($t->replies as $r) {
                    if ($r->character_id) $characterIds->push($r->character_id);
                }
            }

            return $characterIds;
        });

        // ONE aide-set lookup covering every author this request renders
        // (bulletin and/or forum authors, depending on the props requested).
        $resolveRole = $once(function () use ($city, $activeTerm, $wants, $bulletinRows, $forumAuthorIds) {
            $authorIds = collect();
            if ($wants('forum_posts')) {
                $authorIds = $authorIds->merge($forumAuthorIds());
            }
            if ($wants('bulletin_posts')) {
                $authorIds = $authorIds->merge($bulletinRows()->pluck('character_id')->filter());
            }
            $allAuthorIds = $authorIds->unique()->values();

            $aideIds = $activeTerm() && $allAuthorIds->isNotEmpty()
                ? CityHallAide::where('mayor_term_id', $activeTerm()->id)
                    ->whereIn('character_id', $allAuthorIds->all())
                    ->pluck('character_id')
                    ->all()
                : [];
            $aideSet = array_flip($aideIds);
            $currentMayorId = $city->mayor_id;

            return function (?int $characterId) use ($currentMayorId, $aideSet): string {
                if ($characterId === null) return 'resident';
                if ($characterId === $currentMayorId) return 'mayor';
                if (isset($aideSet[$characterId])) return 'aide';
                return 'resident';
            };
        });

        // Bulletin payload.
        $bulletinPosts = function () use ($bulletinRows, $resolveRole) {
            $resolve = $resolveRole();

            return $bulletinRows()->map(function ($a) use ($resolve) {
                $live = $resolve($a->character_id);
                $role = $live === 'resident' ? ($a->author_role ?: 'aide') : $live;
                return [
                    'id'            => $a->id,
                    'author'        => $a->author_name,
                    'author_role'   => $role,
                    'author_avatar' => $a->author?->avatar_url ?? null,
                    'author_rank'   => $a->author?->current_rank?->rank_name,
                    'body'          => $a->body,
                    'pinned_at'     => $a->created_at->toIso8601String(),
                ];
            })->values()->all();
        };

        // Forum payload.
        $posts = function () use ($city, $threads, $forumAuthorIds, $resolveRole) {
            // Per-character forum post counts — one grouped query covering all
            // forum-author characters.
            $forumAuthors = $forumAuthorIds()->unique()->values();
            $postCounts = $forumAuthors->isEmpty()
                ? collect()
                : DB::table('city_hall_posts')
                    ->select('character_id', DB::raw('count(*) as c'))
                    ->where('city_id', $city->id)
                    ->where('type', 'forum')
                    ->whereIn('character_id', $forumAuthors->all())
                    ->groupBy('character_id')
                    ->pluck('c', 'character_id');
            $resolve = $resolveRole();

            return $threads()->map(function ($p) use ($postCounts, $resolve) {
                $lastReply = $p->replies->last();
                return [
                    'id'                 => $p->id,
                    'character_id'       => $p->character_id,
                    'author'             => $p->author_name,
                    'author_role'        => $resolve($p->character_id),
                    'author_avatar'      => $p->author?->avatar_url ?? null,
                    'author_rank'        => $p->author?->current_rank?->rank_name,
                    'author_post_count'  => (int) ($postCounts[$p->character_id] ?? 0),
                    'title'              => $p->title,
                    'body'               => $p->body,
                    'reply_count'        => $p->reply_count,
                    'views'              => (int) $p->views,
                    'is_pinned'          => (bool) $p->is_pinned,
                    'is_locked'          => (bool) $p->is_locked,
                    'created_at'         => $p->created_at->toIso8601String(),
                    'last_reply_at'      => $lastReply?->created_at->toIso8601String() ?? $p->created_at->toIso8601String(),
                    'last_reply_author'  => $lastReply?->author_name ?? $p->author_name,
                    'last_reply_role'    => $resolve($lastReply?->character_id ?? $p->character_id),
                    'last_reply_rank'    => $lastReply?->author?->current_rank?->rank_name ?? $p->author?->current_rank?->rank_name,
                    'last_reply_avatar'  => $lastReply?->author?->avatar_url ?? $p->author?->avatar_url ?? null,
                    'replies'            => $p->replies->map(fn ($r) => [
                        'id'                => $r->id,
                        'character_id'      => $r->character_id,
                        'author'            => $r->author_name,
                        'author_role'       => $resolve($r->character_id),
                        'author_avatar'     => $r->author?->avatar_url ?? null,
                        'author_rank'       => $r->author?->current_rank?->rank_name,
                        'author_post_count' => (int) ($postCounts[$r->character_id] ?? 0),
                        'body'              => $r->body,
                        'created_at'        => $r->created_at->toIso8601String(),
                    ])->values()->all(),
                ];
            })->values()->all();
        };

        // Relocation state stays eager: it can auto-apply a pending relocation
        // (side effect) and costs no queries in the common case.
        $timers          = $character->timers;
        $lastRelocation  = (int) ($timers?->last_relocation_at ?? 0);
        $pendingCityId   = $timers?->pending_relocation_city_id ?? null;
        $cooldownSeconds = self::RELOCATION_COOLDOWN * 86400;
        $cooldownDays    = 0;

        if ($lastRelocation > 0) {
            $remaining    = $cooldownSeconds - (now()->getTimestamp() - $lastRelocation);
            $cooldownDays = $remaining > 0 ? (int) ceil($remaining / 86400) : 0;
        }

        
        
        
        
        if ($pendingCityId !== null && $city->mayor_id === null) {
            $pendingSubmittedAt = $lastRelocation; 
            if ($pendingSubmittedAt > 0 && (now()->getTimestamp() - $pendingSubmittedAt) >= (self::RELOCATION_AUTO_HOURS * 3600)) {
                $this->applyRelocation($character, \App\Models\City::find($pendingCityId));
                $character->load('timers');
                $timers         = $character->timers;
                $lastRelocation = (int) ($timers?->last_relocation_at ?? 0);
                $pendingCityId  = null;
                $remaining      = $cooldownSeconds - (now()->getTimestamp() - $lastRelocation);
                $cooldownDays   = $remaining > 0 ? (int) ceil($remaining / 86400) : 0;
            }
        }

        $pendingIsThisCity = $pendingCityId !== null && $pendingCityId === $city->id;

        // Relocation tab (moderators only): not sent on first load — the page
        // requests it when the Relocation tab is opened.
        $pendingApplications = function () use ($city, $isMayor, $isAide) {
            if (!($isMayor || $isAide())) {
                return null;
            }

            return Character::whereHas('timers', fn ($q) => $q->where('pending_relocation_city_id', $city->id))
                ->with(['timers', 'property', 'corporation', 'businesses', 'career'])
                ->orderBy('display_name')
                ->paginate(10)
                ->through(fn ($c) => [
                    'id'            => $c->id,
                    'display_name'  => $c->display_name,
                    'submitted_at'  => $c->timers?->last_relocation_at,
                    'career'        => $c->career?->name ?? 'Unemployed',
                    'rank'          => $c->career_rank,
                    'wealth'        => [
                        'cash'           => ($c->cash_on_hand ?? 0) + ($c->cash_in_bank ?? 0),
                        'property_value' => $c->property?->price ?? 0,
                        'businesses'     => $c->businesses ? $c->businesses->pluck('name')->toArray() : [],
                        'corporation'    => $c->corporation ? $c->corporation->name : null,
                    ],
                    'convictions'   => \App\Models\CrimeRecord::convictionCount($c->id),
                ]);
        };

        $policies = $city->mayor_id ? [
            'income_tax_rate'        => $city->income_tax_rate,
            'corporate_tax_rate'     => $city->corporate_tax_rate,
            'corp_regulation_active' => $city->corp_regulation_active,
            'bonds_active'           => $city->bonds_active,
            'death_sentence_active'  => $city->death_sentence_active,
        ] : null;

        // Eager-load `owner` so the $cityHall?->owner access in the render
        // payload doesn't lazy-fire its own SELECT for what is
        // usually the same character we already loaded as $city->mayor.
        $cityHall = $once(fn () => Business::forCity($city, 'city-hall', ['owner']));

        return Inertia::render('City/CityHall', [
            'city' => fn () => [
                'name'      => $city->name,
                'slug'      => $city->slug,
                'image_url' => $cityHall()?->image_url ?? $city->image_url,
            ],
            'mayor'               => $mayorData,
            'aides'               => $aides,
            'policies'            => $policies,
            'bulletin_posts'      => $bulletinPosts,
            'past_mayors'         => $pastMayors,
            'current_user_avatar' => fn () => $character->avatar_url,
            'forum_posts'         => $posts,
            'forum_settings'      => fn () => [
                'post_fee' => $this->resolveForumPostFee($cityHall()),
                'fee_min'  => self::POST_FEE_MIN,
                'fee_max'  => self::POST_FEE_MAX,
            ],
            'relocation'          => fn () => [
                'home_city'               => $character->homeCity?->name ?? 'Unknown',
                'cooldown_days_remaining' => $cooldownDays,
                'pending_application'     => $pendingIsThisCity,
            ],
            'pending_applications' => Inertia::optional($pendingApplications),
            'is_mayor'             => $isMayor,
            'is_aide'              => $isAide,
            'is_resident'          => $isResident,
            'is_home_city'         => $character->home_city_id === $city->id,
            'is_city_hall_owner'   => fn () => $cityHall() && $cityHall()->owner_id === $character->id,
            'can_post_forum'       => fn () => $isResident
                && $character->isAlive()
                && ! $character->isJailed()
                && ! $character->isHospitalized(),
            'owner' => fn () => $cityHall()?->owner ? [
                'name'       => $cityHall()->owner->display_name,
                'avatar_url' => $cityHall()->owner->avatar_url,
            ] : null,
        ]);
    }

    

    public function announce(Request $request, \App\Models\City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        if ($blocked = $this->blockedByState($character)) {
            return back()->with('error', $blocked);
        }

        $request->validate(['body' => 'required|string|max:200']);

        [$isMayor, $isAide] = $this->moderatorFlags($city, $character);
        if (! $isMayor && ! $isAide) {
            return back()->with('error', 'Only the Mayor or their aides may post announcements.');
        }

        $cityHall = Business::forCity($city, 'city-hall');

        try {
            $result = DB::transaction(function () use ($city, $character, $cityHall, $isMayor, $request) {
                $charge = $this->chargePostFee($character, $cityHall);
                if (! $charge['charged']) {
                    return $charge;
                }

                $count = CityHallPost::announcements()->where('city_id', $city->id)->count();
                if ($count >= self::MAX_ANNOUNCEMENTS) {
                    CityHallPost::announcements()
                        ->where('city_id', $city->id)
                        ->orderBy('created_at')
                        ->first()
                        ?->delete();
                }

                CityHallPost::create([
                    'city_id'      => $city->id,
                    'character_id' => $character->id,
                    'author_name'  => $character->display_name,
                    'author_role'  => $isMayor ? 'mayor' : 'aide',
                    'type'         => 'announcement',
                    // [img] is system-admin only — Mayor/Aide are city-elected,
                    // not site admins. Sanitizer silently strips the tag for
                    // non-admins. URL inside is preserved as plain text.
                    'body'         => ForumBodySanitizer::sanitize(
                        $request->body,
                        (bool) $character->user?->is_admin,
                    ),
                ]);

                return $charge;
            });

            if (! $result['charged']) {
                return back()->with('error', "You need \${$result['fee']} on hand to post.");
            }

            $msg = $result['fee'] > 0
                ? "Announcement posted (\${$result['fee']} fee paid)."
                : 'Announcement posted.';
            return back()->with('success', $msg);
        } catch (\Throwable $e) {
            Log::error('[CityHall] Announcement failed.', [
                'character' => $character->id,
                'city'      => $city->id,
                'error'     => $e->getMessage(),
            ]);
            return back()->with('error', 'Failed to post announcement. Please try again.');
        }
    }

    public function deleteAnnouncement(Request $request, \App\Models\City $city, int $id)
    {
        [$character, $city] = $this->getContext($request, $city);

        $activeTerm = MayorTerm::activeForCity($city->id);
        $isMayor    = $city->mayor_id === $character->id;
        $isAide     = $activeTerm && CityHallAide::where('mayor_term_id', $activeTerm->id)
            ->where('character_id', $character->id)
            ->exists();

        if (! $isMayor && ! $isAide) {
            return back()->with('error', 'Only the Mayor or their aides may delete announcements.');
        }

        try {
            CityHallPost::where('id', $id)->where('city_id', $city->id)->where('type', 'announcement')->delete();

            return back()->with('success', 'Announcement removed.');
        } catch (\Throwable $e) {
            Log::error('[CityHall] Delete announcement failed.', [
                'character' => $character->id,
                'city'      => $city->id,
                'post_id'   => $id,
                'error'     => $e->getMessage(),
            ]);
            return back()->with('error', 'Failed to remove announcement. Please try again.');
        }
    }

    

    public function forumStore(Request $request, \App\Models\City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        if (! ($character->city_id === $city->id && $character->isAlive() && ! $character->isJailed() && ! $character->isHospitalized())) {
            return back()->with('error', 'You cannot post here.');
        }

        $request->validate([
            'title' => 'required|string|max:30',
            'body'  => 'required|string|max:500',
        ]);

        [$isMayor, $isAide] = $this->moderatorFlags($city, $character);
        $cityHall = Business::forCity($city, 'city-hall');

        try {
            $result = DB::transaction(function () use ($city, $character, $cityHall, $isMayor, $isAide, $request) {
                $charge = $this->chargePostFee($character, $cityHall);
                if (! $charge['charged']) {
                    return $charge;
                }

                CityHallPost::create([
                    'city_id'      => $city->id,
                    'character_id' => $character->id,
                    'author_name'  => $character->display_name,
                    'author_role'  => $isMayor ? 'mayor' : ($isAide ? 'aide' : 'resident'),
                    'type'         => 'forum',
                    'title'        => $request->title,
                    'body'         => ForumBodySanitizer::sanitize(
                        $request->body,
                        (bool) $character->user?->is_admin,
                    ),
                ]);

                return $charge;
            });

            if (! $result['charged']) {
                return back()->with('error', "You need \${$result['fee']} on hand to post.");
            }

            $msg = $result['fee'] > 0
                ? "Thread posted (\${$result['fee']} fee paid)."
                : 'Thread posted.';
            return back()->with('success', $msg);
        } catch (\Throwable $e) {
            Log::error('[CityHall] Forum post failed.', [
                'character' => $character->id,
                'city'      => $city->id,
                'error'     => $e->getMessage(),
            ]);
            return back()->with('error', 'Failed to submit post. Please try again.');
        }
    }

    public function forumReply(Request $request, \App\Models\City $city, CityHallPost $post)
    {
        [$character, $city] = $this->getContext($request, $city);

        if (! ($character->city_id === $city->id && $character->isAlive() && ! $character->isJailed() && ! $character->isHospitalized())) {
            return back()->with('error', 'You cannot reply here.');
        }

        $request->validate(['body' => 'required|string|max:500']);

        if ($post->city_id !== $city->id || $post->type !== 'forum' || $post->parent_id !== null) {
            return back()->with('error', 'Invalid post.');
        }

        if ($post->is_locked) {
            return back()->with('error', 'This thread has been locked.');
        }

        [$isMayor, $isAide] = $this->moderatorFlags($city, $character);
        $cityHall = Business::forCity($city, 'city-hall');

        try {
            $result = DB::transaction(function () use ($city, $character, $post, $request, $isMayor, $isAide, $cityHall) {
                $charge = $this->chargePostFee($character, $cityHall);
                if (! $charge['charged']) {
                    return $charge;
                }

                CityHallPost::create([
                    'city_id'      => $city->id,
                    'character_id' => $character->id,
                    'author_name'  => $character->display_name,
                    'author_role'  => $isMayor ? 'mayor' : ($isAide ? 'aide' : 'resident'),
                    'type'         => 'forum',
                    'parent_id'    => $post->id,
                    'body'         => ForumBodySanitizer::sanitize(
                        $request->body,
                        (bool) $character->user?->is_admin,
                    ),
                ]);

                // Bump reply_count AND updated_at so active threads float in ForumOrdered.
                DB::table('city_hall_posts')
                    ->where('id', $post->id)
                    ->update([
                        'reply_count' => DB::raw('reply_count + 1'),
                        'updated_at'  => now(),
                    ]);

                return $charge;
            });

            if (! $result['charged']) {
                return back()->with('error', "You need \${$result['fee']} on hand to reply.");
            }

            $msg = $result['fee'] > 0
                ? "Reply posted (\${$result['fee']} fee paid)."
                : 'Reply posted.';
            return back()->with('success', $msg);
        } catch (\Throwable $e) {
            Log::error('[CityHall] Forum reply failed.', [
                'character' => $character->id,
                'city'      => $city->id,
                'post_id'   => $post->id,
                'error'     => $e->getMessage(),
            ]);
            return back()->with('error', 'Failed to post reply. Please try again.');
        }
    }

    public function forumView(Request $request, \App\Models\City $city, CityHallPost $post)
    {
        [$character, $city] = $this->getContext($request, $city);

        if ($post->city_id === $city->id && $post->type === 'forum' && $post->parent_id === null && $post->character_id !== $character->id) {
            DB::table('city_hall_posts')->where('id', $post->id)->increment('views');
        }

        return back();
    }

    public function forumPin(Request $request, \App\Models\City $city, CityHallPost $post)
    {
        [$character, $city] = $this->getContext($request, $city);

        [$isMayor, $isAide] = $this->moderatorFlags($city, $character);
        if (! $isMayor && ! $isAide) {
            return back()->with('error', 'Only the Mayor or their aides may pin threads.');
        }

        if ($post->city_id !== $city->id || $post->type !== 'forum' || $post->parent_id !== null) {
            return back()->with('error', 'Invalid thread.');
        }

        $post->is_pinned = ! $post->is_pinned;
        $post->save();

        return back()->with('success', $post->is_pinned ? 'Thread pinned.' : 'Thread unpinned.');
    }

    public function forumLock(Request $request, \App\Models\City $city, CityHallPost $post)
    {
        [$character, $city] = $this->getContext($request, $city);

        [$isMayor, $isAide] = $this->moderatorFlags($city, $character);
        if (! $isMayor && ! $isAide) {
            return back()->with('error', 'Only the Mayor or their aides may lock threads.');
        }

        if ($post->city_id !== $city->id || $post->type !== 'forum' || $post->parent_id !== null) {
            return back()->with('error', 'Invalid thread.');
        }

        $post->is_locked = ! $post->is_locked;
        $post->save();

        return back()->with('success', $post->is_locked ? 'Thread locked.' : 'Thread unlocked.');
    }

    private function blockedByState(Character $character): ?string
    {
        if (! $character->isAlive())       return 'You cannot do this.';
        if ($character->isJailed())        return 'You cannot do this while in jail.';
        if ($character->isHospitalized())  return 'You cannot do this while hospitalized.';
        return null;
    }

    private function moderatorFlags(\App\Models\City $city, Character $character): array
    {
        $isMayor    = $city->mayor_id !== null && $city->mayor_id === $character->id;
        $activeTerm = MayorTerm::activeForCity($city->id);
        $isAide     = $activeTerm && CityHallAide::where('mayor_term_id', $activeTerm->id)
            ->where('character_id', $character->id)
            ->exists();

        return [$isMayor, $isAide];
    }

    /**
     * Owner of the City Hall business sets the per-post fee.
     */
    public function updateSettings(Request $request, \App\Models\City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        $cityHall = Business::forCity($city, 'city-hall');
        if (! $cityHall || $cityHall->owner_id !== $character->id) {
            return back()->with('error', 'You do not own this City Hall.');
        }

        $validated = $request->validate([
            'post_fee' => 'required|integer|min:' . self::POST_FEE_MIN . '|max:' . self::POST_FEE_MAX,
        ]);

        $cityHall->setSetting('forum_post_fee', (int) $validated['post_fee']);

        Log::info('[CityHall] Forum post fee updated.', [
            'owner'  => $character->id,
            'city'   => $city->id,
            'fee'    => $validated['post_fee'],
        ]);

        return back()->with('success', 'Forum fee updated to $' . $validated['post_fee'] . '.');
    }

    
    private function resolveForumPostFee(?Business $cityHall): int
    {
        if (! $cityHall || ! $cityHall->owner_id) {
            return 0;
        }
        $raw = (int) $cityHall->getSetting('forum_post_fee', self::POST_FEE_DEFAULT);
        return max(self::POST_FEE_MIN, min(self::POST_FEE_MAX, $raw));
    }

    private function chargePostFee(Character $character, ?Business $cityHall): array
    {
        $fee = $this->resolveForumPostFee($cityHall);

        if ($fee <= 0 || ! $cityHall) {
            return ['charged' => true, 'fee' => 0];
        }

        if (! $character->removeCash($fee)) {
            return ['charged' => false, 'fee' => $fee];
        }

        DB::table('businesses')->where('id', $cityHall->id)->increment('balance', $fee);

        return ['charged' => true, 'fee' => $fee];
    }

    public function forumDelete(Request $request, \App\Models\City $city, CityHallPost $post)
    {
        [$character, $city] = $this->getContext($request, $city);

        [$isMayor, $isAide] = $this->moderatorFlags($city, $character);
        $isOwner = $post->character_id === $character->id;

        if (! $isMayor && ! $isAide && ! $isOwner) {
            return back()->with('error', 'You cannot delete this post.');
        }

        if ($post->city_id !== $city->id) {
            return back()->with('error', 'Post not found.');
        }

        try {
            DB::transaction(function () use ($post) {
                if ($post->parent_id === null) {
                    
                    
                    CityHallPost::where('parent_id', $post->id)->delete();
                } else {
                    
                    DB::table('city_hall_posts')
                        ->where('id', $post->parent_id)
                        ->decrement('reply_count');
                }

                $post->delete();
            });

            return back()->with('success', 'Post deleted.');
        } catch (\Throwable $e) {
            Log::error('[CityHall] Forum delete failed.', [
                'character' => $character->id,
                'city'      => $city->id,
                'post_id'   => $post->id,
                'error'     => $e->getMessage(),
            ]);
            return back()->with('error', 'Failed to delete post. Please try again.');
        }
    }

    

   
    public function relocate(Request $request, \App\Models\City $city)
    {
        //relocate should cost money
        [$character, $city] = $this->getContext($request, $city);

        if ($blocked = $this->blockedByState($character)) {
            return back()->with('error', $blocked);
        }

        if ($character->home_city_id === $city->id) {
            return back()->with('error', 'This is already your home city.');
        }

        $eligibility = $this->checkRelocationEligibility($character);
        if ($eligibility !== null) {
            return back()->with('error', $eligibility);
        }

        $timers         = $character->timers;
        $lastRelocation = (int) ($timers?->last_relocation_at ?? 0);

        $hasPending = $timers?->pending_relocation_city_id !== null;

        if (! $hasPending && $lastRelocation > 0) {
            $remaining = (self::RELOCATION_COOLDOWN * 86400) - (now()->getTimestamp() - $lastRelocation);
            if ($remaining > 0) {
                $days = (int) ceil($remaining / 86400);
                return back()->with('error', "You must wait {$days} more day(s) before relocating.");
            }
        }

        if ($timers?->pending_relocation_city_id === $city->id) {
            return back()->with('error', 'You already have a pending relocation application for this city.');
        }

        $activeTerm = MayorTerm::activeForCity($city->id);
        $hasMayor   = $city->mayor_id !== null && $activeTerm !== null;

        try {
            DB::table('character_timers')
                ->where('character_id', $character->id)
                ->update([
                    'pending_relocation_city_id' => $city->id,
                    'last_relocation_at'         => now()->getTimestamp(),
                ]);

            Log::info('[CityHall] Relocation application submitted.', [
                'character'   => $character->id,
                'target_city' => $city->id,
                'has_mayor'   => $hasMayor,
            ]);

            if ($hasMayor) {
                return back()->with('success', 'Relocation application submitted. Awaiting mayoral approval.');
            }

            return back()->with('success', ' Your application has been approved automatically, since there is no mayor in office, you can come back here in 1 HOUR to confirm your relocation.');
        } catch (\Throwable $e) {
            Log::error('[CityHall] Relocation submission failed.', [
                'character'   => $character->id,
                'target_city' => $city->id,
                'error'       => $e->getMessage(),
            ]);
            return back()->with('error', 'Failed to submit relocation. Please try again.');
        }
    }
    
    private function checkRelocationEligibility(Character $character): ?string
    {
        $homeCity = $character->homeCity;
        if (! $homeCity) {
            return null;
        }

        if ($homeCity->mayor_id === $character->id) {
            return 'You cannot relocate while serving as mayor.';
        }

        $activeTerm = MayorTerm::activeForCity($homeCity->id);
        if ($activeTerm) {
            $isAide = CityHallAide::where('mayor_term_id', $activeTerm->id)
                ->where('character_id', $character->id)
                ->exists();
            if ($isAide) {
                return 'You cannot relocate while serving as a city hall aide.';
            }
        }

        $policeCareer = \App\Models\Career::findByCode('police');
        if ($policeCareer && $character->career_id === $policeCareer->id) {
            return 'Police officers must resign before relocating.';
        }

        $lawCareer = \App\Models\Career::findByCode('law');
        if ($lawCareer && $character->career_id === $lawCareer->id) {
            return 'Lawyers must resign before relocating.';
        }
        //check corporation

        $corporation = \App\Models\Career::findByCode('corporation');
        

        $hasActiveCampaign = \App\Models\Campaign::where('candidate_id', $character->id)
            ->where('status', 'active')
            ->exists();
        if ($hasActiveCampaign) {
            return 'You cannot relocate while running in an election.';
        }
         
        if ($character->career_rank > 2 && !$corporation) {
            return 'You must be rank 2 or lower in your career to relocate.';
        }

        return null;
    }

    
    public function approveRelocation(Request $request, \App\Models\City $city, int $characterId)
    {
        [$actor, $city] = $this->getContext($request, $city);

        if ($blocked = $this->blockedByState($actor)) {
            return back()->with('error', $blocked);
        }

        [$isMayor, $isAide] = $this->moderatorFlags($city, $actor);
        if (! $isMayor && ! $isAide) {
            return back()->with('error', 'Only the Mayor or their aides may approve relocations.');
        }

        $target = Character::with('timers')->find($characterId);
        if (! $target || $target->timers?->pending_relocation_city_id !== $city->id) {
            return back()->with('error', 'No pending application found for that character.');
        }

        $eligibility = $this->checkRelocationEligibility($target);
        if ($eligibility !== null) {
            return back()->with('error', $eligibility);
        }

        try {
            $this->applyRelocation($target, $city);

          

            Log::info('[CityHall] Relocation approved.', [
                'actor'     => $actor->id,
                'target'    => $target->id,
                'city'      => $city->id,
            ]);

            return back()->with('success', "{$target->display_name}'s relocation has been approved.");
        } catch (\Throwable $e) {
            Log::error('[CityHall] Relocation approval failed.', [
                'actor'  => $actor->id,
                'target' => $characterId,
                'city'   => $city->id,
                'error'  => $e->getMessage(),
            ]);
            return back()->with('error', 'Failed to approve relocation. Please try again.');
        }
    }

    
    public function denyRelocation(Request $request, \App\Models\City $city, int $characterId)
    {
        [$actor, $city] = $this->getContext($request, $city);

        $activeTerm = MayorTerm::activeForCity($city->id);
        $isMayor    = $city->mayor_id === $actor->id;
        $isAide     = $activeTerm && CityHallAide::where('mayor_term_id', $activeTerm->id)
            ->where('character_id', $actor->id)
            ->exists();

        if (! $isMayor && ! $isAide) {
            return back()->with('error', 'Only the Mayor or their aides may deny relocations.');
        }

        $target = Character::with('timers')->find($characterId);
        if (! $target || $target->timers?->pending_relocation_city_id !== $city->id) {
            return back()->with('error', 'No pending application found for that character.');
        }

        try {
            DB::table('character_timers')
                ->where('character_id', $target->id)
                ->update([
                    'pending_relocation_city_id' => null,
                    
                    
                    'last_relocation_at'         => 0,
                ]);

            \App\Services\JournalService::custom($target->id, 'relocation_denied', [
                'city_name' => $city->name,
            ]);

            Log::info('[CityHall] Relocation denied.', [
                'actor'  => $actor->id,
                'target' => $target->id,
                'city'   => $city->id,
            ]);

            return back()->with('success', "{$target->display_name}'s relocation has been denied.");
        } catch (\Throwable $e) {
            Log::error('[CityHall] Relocation denial failed.', [
                'actor'  => $actor->id,
                'target' => $characterId,
                'city'   => $city->id,
                'error'  => $e->getMessage(),
            ]);
            return back()->with('error', 'Failed to deny relocation. Please try again.');
        }
    }

    

    public function appointAide(Request $request, \App\Models\City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        if ($blocked = $this->blockedByState($character)) {
            return back()->with('error', $blocked);
        }

        if ($city->mayor_id !== $character->id) {
            return back()->with('error', 'Only the current mayor may appoint aides.');
        }

        
        $request->validate(['character_name' => 'required|string|max:50']);

        $activeTerm = MayorTerm::activeForCity($city->id);
        if (! $activeTerm) {
            return back()->with('error', 'No active term found.');
        }

        $aideCount = CityHallAide::where('mayor_term_id', $activeTerm->id)->count();
        if ($aideCount >= self::MAX_AIDES) {
            return back()->with('error', 'Maximum of ' . self::MAX_AIDES . ' aides already appointed.');
        }

        $target = Character::findByName($request->character_name);
        if (! $target || $target->trashed()) {
            return back()->with('error', 'Character not found.');
        }

        if ($target->id === $character->id) {
            return back()->with('error', 'You cannot appoint yourself.');
        }

        $already = CityHallAide::where('mayor_term_id', $activeTerm->id)
            ->where('character_id', $target->id)
            ->exists();

        if ($already) {
            return back()->with('error', 'That player is already an aide.');
        }

        if ($target->home_city_id !== $city->id) {
            return back()->with('error', 'You can only appoint aides who are residents of your city.');
        }

        try {
            CityHallAide::create([
                'city_id'       => $city->id,
                'mayor_term_id' => $activeTerm->id,
                'character_id'  => $target->id,
                'display_name'  => $target->display_name,
            ]);

            Log::info('[CityHall] Aide appointed.', [
                'mayor' => $character->id,
                'aide'  => $target->id,
                'city'  => $city->id,
            ]);

            return back()->with('success', "{$target->display_name} appointed as aide.");
        } catch (\Throwable $e) {
            Log::error('[CityHall] Aide appointment failed.', [
                'mayor' => $character->id,
                'target'=> $target->id,
                'city'  => $city->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Failed to appoint aide. Please try again.');
        }
    }

    
    public function revokeAide(Request $request, \App\Models\City $city, int $aide)
    {
        [$character, $city] = $this->getContext($request, $city);

        if ($city->mayor_id !== $character->id) {
            return back()->with('error', 'Only the current mayor may remove aides.');
        }

        $activeTerm = MayorTerm::activeForCity($city->id);
        if (! $activeTerm) {
            return back()->with('error', 'No active term found.');
        }

        try {
            $deleted = CityHallAide::where('id', $aide)
                ->where('mayor_term_id', $activeTerm->id)
                ->delete();

            if (! $deleted) {
                return back()->with('error', 'Aide not found or already removed.');
            }

            Log::info('[CityHall] Aide revoked.', [
                'mayor'   => $character->id,
                'aide_id' => $aide,
                'city'    => $city->id,
            ]);

            return back()->with('success', 'Aide removed.');
        } catch (\Throwable $e) {
            Log::error('[CityHall] Aide revocation failed.', [
                'mayor'   => $character->id,
                'aide_id' => $aide,
                'city'    => $city->id,
                'error'   => $e->getMessage(),
            ]);
            return back()->with('error', 'Failed to remove aide. Please try again.');
        }
    }

    

    
    private function applyRelocation(Character $character, ?\App\Models\City $targetCity): void
    {
        if (! $targetCity) {
            return;
        }

        DB::transaction(function () use ($character, $targetCity) {
            $character->home_city_id = $targetCity->id;
            $character->save();

            \App\Services\JournalService::custom($character->id, 'relocation_approved', [
                'city_name' => $targetCity->name,
            ]);

            
            
            DB::table('character_timers')
                ->where('character_id', $character->id)
                ->update([
                    'last_relocation_at'         => now()->getTimestamp(),
                    'pending_relocation_city_id' => null,
                ]);
        });
    }
}
