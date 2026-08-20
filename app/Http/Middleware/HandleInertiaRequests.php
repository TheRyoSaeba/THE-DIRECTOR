<?php

namespace App\Http\Middleware;

use App\Models\Character;
use App\Models\Message;
use App\Models\Announcement;
use App\Support\SafeCache;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = "app";

    public function share(Request $request): array
    {
        if ($request->routeIs("logout")) {
            return array_merge(parent::share($request), [
                "auth" => null,
                "onlinePlayers" => null,
            ]);
        }

        $user = $request->user();
        $character = $user ? $user->getLoadedCharacter() : null;



        $flashShare = [
            "flash" => fn() => [
                "success" => $request->session()->get("success"),
                "error" => $request->session()->get("error"),
                "warning" => $request->session()->get("warning"),
                "conflict_outcome" => $request->session()->get("conflict_outcome"),
                "blackjack" => $request->session()->get("blackjack"),
            ],
        ];

        if (!$request->isMethod("GET")) {
            return array_merge(parent::share($request), $flashShare);
        }


        $memoizedUnreadJournalCount = null;
        $unreadJournalCount = function () use ($character, &$memoizedUnreadJournalCount): int {
            if ($memoizedUnreadJournalCount !== null) {
                return $memoizedUnreadJournalCount;
            }

            return $memoizedUnreadJournalCount = $character
                ? SafeCache::remember(
                    "unread_journals_{$character->id}",
                    30,
                    fn() => $character
                        ->journals()
                        ->whereRaw('"is_read" IS FALSE')
                        ->count(),
                    0,
                )
                : 0;
        };

        $memoizedUnreadMessageCount = null;
        $unreadMessageCount = function () use ($character, &$memoizedUnreadMessageCount): int {
            if ($memoizedUnreadMessageCount !== null) {
                return $memoizedUnreadMessageCount;
            }

            return $memoizedUnreadMessageCount = $character
                ? SafeCache::remember(
                    "unread_messages_{$character->id}",
                    10,
                    fn() => Message::where("recipient_id", $character->id)
                        ->whereNull("read_at")
                        ->count(),
                    0,
                )
                : 0;
        };

        return array_merge(parent::share($request), $flashShare, [
            "ziggy" => function () use ($user, $request) {
                $role = match (true) {
                    $user?->is_admin => "admin",
                    $user !== null => "user",
                    default => "guest",
                };

                $filtered = SafeCache::remember("ziggy_routes_{$role}_v1", 3600, function () use ($role) {
                    $ziggy = new Ziggy();

                    return match ($role) {
                        "admin" => $ziggy->toArray(),
                        "user" => $ziggy->filter(["!admin.*"])->toArray(),
                        default => $ziggy->filter(["public"])->toArray(),
                    };
                }, []);

                return array_merge($filtered, ["location" => $request->url()]);
            },
            "serverTime" => Inertia::always(fn() => Carbon::now("UTC")->format('Y-m-d\TH:i:s\Z')),
            "auth" => $user
                ? [
                    "user" => [
                        "username" => $user->username,
                        "disableCardFlip" => (bool) $user->disable_card_flip,
                        // is_admin removed — was only ever read by the React
                        // wiki, which is now Blade. The remaining server-side
                        // admin gates use $user->is_admin directly, and the
                        // ziggy bundle is still role-split (line ~87), so
                        // admins still see their admin routes — they just
                        // don't need a client-side flag to do it.
                    ],
                    "navigation" => fn() => $this->getNavigation(
                        $user,
                        $character,
                        ($unreadJournalCount)(),
                        ($unreadMessageCount)(),
                        Announcement::hasActive(),
                    ),
                    "character" => fn() => $this->getCharacterData(
                        $character,
                        ($unreadJournalCount)(),
                        $user,
                        $request,
                    ),
                ]
                : null,
            "onlinePlayers" => fn() => $this->getOnlinePlayers($user, $character),
        ]);
    }

    private function getCharacterData(
        $character,
        $unreadCount,
        $user = null,
        ?Request $request = null,
    ): ?array {
        if (!$character) {
            return null;
        }

        if ($character->trashed()) {
            return null;
        }
        //! hospital reason is basically not used
        if ($character->timers?->hospital_until?->isFuture()) {
            return [
                "is_hospitalized" => true,
                "displayName" => $character->display_name,
                "avatarUrl" => $character->avatar_url,
                "hospital_until" => $character->timers->hospital_until->getTimestamp(),
                "hospital_reason" => $character->timers->hospital_reason,
            ];
        }

        if ($character->timers?->jail_until?->isFuture()) {
            return [
                "is_jailed" => true,
                "displayName" => $character->display_name,
                "avatarUrl" => $character->avatar_url,
                "jail_until" => $character->timers->jail_until->getTimestamp(),
            ];
        }

        $currentRank = $character->current_rank;

        $timers = $character->timers;

        $timerData = [];

        if ($timers) {
            $timerKeys = [
                "next_work_at",
                "next_action_at",
                "next_travel_at",
                "next_talents_at",
                "next_study_at",
                "next_conflict_at",
            ];

            foreach ($timerKeys as $key) {
                $val = $timers->{$key};
                $timerData[$key] = ($val instanceof Carbon) ? $val->getTimestamp() : 0;
            }
        }

        $rankReqs = $character->rank_requirements;
        $progress = (int) ($rankReqs["progress"] ?? 0);
        $promotionBlocker = ((int) $request?->attributes->get('promotion_checker_character_id') === (int) $character->id)
            ? $request->attributes->get('promotion_checker')
            : $character->getPromotionChecker();
        $canPromote = $promotionBlocker === null;
        $nextRank = $canPromote ? $character->nextRank : null;

        return [
            "displayName" => $character->display_name,
            "avatarUrl" => $character->avatar_url,
            "rank" => $currentRank?->rank_name ?? "Entry Level",
            "career" => $character->career->name ?? "Unknown",
            "cityName" => $character->city->name ?? "Unknown",
            "citySlug" => $character->city->slug ?? "unknown",
            "homeCity" => $character->homeCity->name ?? "Unknown",
            "health" => $character->health,
            "maxHealth" => $character->max_health,
            "strength" => $timers->strength ?? 100.0,
            "rankProgress" => $progress,
            "canPromote" => $canPromote,
            "promotionBlocker" => $promotionBlocker,
            "nextRank" => $nextRank
                ? ['name' => $nextRank->rank_name]
                : null,
            "glowColor" => $character->glow_color ?? "cyan",
            "cleanCash" => (int) $character->cash_on_hand,
            "dirtyCash" => (int) $character->dirty_cash,
            "works24h" => $character->getWorks24h(),
            "timers" => $timerData,
            "unreadJournalCount" => $unreadCount,
        ];
    }

    private function getNavigation(
        $user,
        $character,
        $unreadJournalCount,
        $unreadMessageCount,
        $hasActiveAnnouncement = false,
    ): array {
        if (!$character) {
            return [];
        }

        $careerName = $character->career?->name ?? "";

        $personalItems = [
            [
                "label" => "Journal",
                "icon" => "Newspaper",
                "href" => "/journal",
                "badge" => $unreadJournalCount ?: null,
            ],
            [
                "label" => "Messages",
                "icon" => "MessageSquare",
                "href" => "/messages",
                "badge" => $unreadMessageCount ?: null,
            ],
            [
                "label" => "Work",
                "icon" => "Briefcase",
                "href" => "/work",
                "isTechnician" => $careerName === "Technician",
                "isCustoms" => $careerName === "Customs",
                "hasActions" => true,
                "actionsUrl" => "/actions",
            ],
            ["label" => "Talents", "icon" => "bulb", "href" => "/talents"],
        ];

        $powerItems = [
            ["label" => "Conflict", "icon" => "Swords", "href" => "/conflict"],
            [
                "label" => "Politics",
                "icon" => "Scale",
                "href" => "/{$character->city?->slug}/election",
            ],
            [
                "label" => "Business",
                "icon" => "Building",
                "href" => "/{$character->city?->slug}/business",
            ],
        ];

        $careerItem = match ($careerName) {
            "Police" => [
                "label" => "Police Station",
                "icon" => "Shield",
                "href" => "/career/police",
            ],
            "Healthcare" => [
                "label" => "Emergency Room",
                "icon" => "HeartPulse",
                "href" => "/career/healthcare",
            ],
            "Corporation" => [
                "label" => "Boardroom",
                "icon" => "OfficeChair",
                "href" => "/career/corporate",
            ],
            "Law" => [
                "label" => "Courthouse",
                "icon" => "Gavel",
                "href" => "/career/law",
            ],
            "Banking" => [
                "label" => "The Terminal",
                "icon" => "Trading",
                "href" => "/career/banking",
            ],
            "Politics" => [
                "label" => "Mayor's Desk",
                "icon" => "Building2",
                "href" => "/career/politics",
            ],
            default => null,
        };

        if ($careerItem) {
            $powerItems[] = $careerItem;
        }

        $sections = [
            [
                "section" => "Personal",
                "items" => $personalItems,
            ],
            [
                "section" => "World",
                "items" => [
                    [
                        "label" => "Transit Hub",
                        "icon" => "Car",
                        "href" => "/{$character->city?->slug}/transit-hub",
                    ],
                    [
                        "label" => $character->city?->name ?? "City",
                        "icon" => "MapPin",
                        "href" => "/{$character->city?->slug}",
                    ],
                    [
                        "label" => "Bank",
                        "icon" => "Landmark",
                        "href" => "/{$character->city?->slug}/bank",
                        "hasWithdraw" => true,
                        "withdrawUrl" => "/{$character->city?->slug}/bank/withdraw",
                    ],
                ],
            ],
            [
                "section" => "Power",
                "items" => $powerItems,
            ],
            [
                "section" => "System",
                "items" => [
                    [
                        "label" => "Announcements",
                        "icon" => "Megaphone",
                        "href" => "/announcements",
                        "isAlert" => $hasActiveAnnouncement,
                    ],
                    [
                        "label" => "Leaderboard",
                        "icon" => "ChartBar",
                        "href" => "/leaderboard",
                    ],
                ],
            ],
        ];

        if ($user->is_admin) {
            $sections[] = [
                "section" => "Administrator",
                "items" => [
                    [
                        "label" => "Admin Panel",
                        "icon" => "Shield",
                        "href" => "/admin",
                    ],
                ],
            ];
        }

        return $sections;
    }

    private function getOnlinePlayers($user, $character): ?array
    {
        if (
            !$character ||
            $character->trashed() ||
            $character->timers?->hospital_until?->isFuture() ||
            $character->timers?->jail_until?->isFuture()
        ) {
            return null;
        }

        $cityId = $character->city_id;

        $cacheKey = "online_players_city_{$cityId}";
        $cacheTtl = 10;

        return SafeCache::remember($cacheKey, $cacheTtl, function () use ($cityId) {
            return Character::getOnlinePlayersOptimized($cityId);
        }, [
            'cityList' => [],
            'globalList' => [],
        ]);
    }
}
