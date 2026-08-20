<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\CharacterHistory;
use App\Models\CareerRank;
use App\Models\City;
use App\Models\Corporation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ProfileController extends Controller
{
    public function index(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return redirect()->route('character.create');
        }

        if ($character->trashed()) {
            return redirect()->route('dashboard')->with('error', 'Your character is deceased');
        }

        $this->prepareProfileCharacter($character, $request->user());

        return $this->showProfile($character, true, $character->city?->slug);
    }

    public function show(Request $request, string $displayName)
    {
        $character = Character::withTrashed()
            ->with($this->profileRelations())
            ->where(function ($q) use ($displayName) {
                $q->whereRaw('LOWER(display_name) = LOWER(?)', [$displayName])
                    ->orWhereHas('user', function ($uq) use ($displayName) {
                        $uq->whereRaw('LOWER(username) = LOWER(?)', [$displayName]);
                    });
            })
            ->first();

        if (!$character) {
            return redirect()->route('dashboard')->with('error');
        }

        if (!$character->isAlive() || $character->user?->is_banned) {
            return $this->showDeadProfile($character, $request->user()->getLoadedCharacter());
        }

        $this->prepareProfileCharacter($character);

        $viewer = $request->user()->getLoadedCharacter();
        return $this->showProfile($character, $request->user()->id === $character->user_id, $viewer?->city?->slug);
    }

    private function profileRelations(): array
    {
        return [
            'user.achievements:id,name,icon,icon_url,description',
            'city:id,name,slug',
            'career:id,name,code',
            'property:id,name,image_url',
            'stats:character_id,influence,intelligence,offense,defense,luck',
            'businesses:id,owner_id,name,image_url,slug',
            'corporation:id,name,image_url,ceo_id,founder_id,home_city_id,is_holding_company,parent_trust_id,hq_tier',
            'corporation.properties:id,corporation_id,type,tier,condition',
            'corporation.members:id,display_name,custom_avatar_url,career_id,career_rank,corporation_position,corporation_id,corporation_reports_to_id,health,deleted_at',
            'corporation.parentTrust:id,name,image_url,home_city_id,is_holding_company,parent_trust_id,hq_tier',
            'corporation.activeSubsidiaries:id,name,image_url,home_city_id,parent_trust_id,ceo_id',
        ];
    }

    private function prepareProfileCharacter(Character $character, $user = null): void
    {
        if ($user) {
            $character->setRelation('user', $user);
        }

        $character->loadMissing($this->profileRelations());

        if ($character->stats) {
            $character->stats->setRelation('character', $character);
        }

        $this->hydrateHomeCity($character);
        $this->hydrateCorporationCities($character);
    }

    private function hydrateHomeCity(Character $character): void
    {
        if ($character->relationLoaded('homeCity')) {
            return;
        }

        if (
            $character->home_city_id
            && (int) $character->city_id === (int) $character->home_city_id
            && $character->relationLoaded('city')
        ) {
            $character->setRelation('homeCity', $character->city);
            return;
        }

        $character->loadMissing('homeCity:id,name');
    }

    private function hydrateCorporationCities(Character $character): void
    {
        $corporations = collect();
        $corp = $character->corporation;

        if (!$corp) {
            return;
        }

        $corporations->push($corp);

        if ($corp->parentTrust) {
            $corporations->push($corp->parentTrust);
        }

        if ($corp->relationLoaded('activeSubsidiaries')) {
            $corporations = $corporations->merge($corp->activeSubsidiaries);
        }

        $cityIds = $corporations
            ->pluck('home_city_id')
            ->filter()
            ->unique()
            ->values();

        if ($cityIds->isEmpty()) {
            return;
        }

        $cities = collect();

        foreach (['city', 'homeCity'] as $relation) {
            if ($character->relationLoaded($relation) && $character->{$relation}) {
                $cities->put($character->{$relation}->id, $character->{$relation});
            }
        }

        $missingCityIds = $cityIds
            ->reject(fn($id) => $cities->has($id))
            ->values();

        if ($missingCityIds->isNotEmpty()) {
            City::query()
                ->whereIn('id', $missingCityIds)
                ->get(['id', 'name'])
                ->each(fn($city) => $cities->put($city->id, $city));
        }

        foreach ($corporations as $corporation) {
            $city = $cities->get($corporation->home_city_id);
            if ($city) {
                $corporation->setRelation('city', $city);
            }
        }
    }
    //! TODO Misleadingly shows last active time not when they were kicked off. sometimes says unknown for it maybe cron.
    private function showProfile(Character $character, bool $isOwnProfile, ?string $viewerCitySlug = null)
    {
        $isOnline = $character->isOnline();
        $lastActivity = null;

        if (!$isOnline && $character->user) {
            $lastSession = DB::table('sessions')
                ->where('user_id', '=', $character->user_id, 'and')
                ->orderByDesc('last_activity')
                ->first();

            $lastActivity = $lastSession ? (is_object($lastSession) && property_exists($lastSession, 'last_activity') ? $lastSession->last_activity :
                (is_array($lastSession) && isset($lastSession['last_activity']) ? $lastSession['last_activity'] : null)) :
                ($character->user?->last_login_at?->timestamp ?? null);
        }


        $profileData = [

            'displayName' => $character->display_name,
            'gender' => $character->gender,
            'avatarUrl' => $character->avatar_url,
            'career' => $character->career?->name ?? 'Unknown',
            'rank' => $character->current_rank?->rank_name ?? 'Entry Level',
            'homeCity' => $character->homeCity?->name ?? 'Unknown',
            'isOnline' => $isOnline,
            'lastActivity' => $lastActivity,
            'biography' => $character->biography,
            'additionalInfo' => $character->additional_info,

            'influence' => (int) ($character->stats?->influence ?? 0),
            'wealthStatus' => $this->calculateWealthTier($character),
            'glowColor' => $character->glow_color ?? 'cyan',
            'achievements' => $character->user->hide_achievements ? [] : $character->user?->achievements?->map(fn($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'icon' => $a->icon,
                'icon_url' => $a->icon_url,
                'description' => $a->description,
                'unlocked_at' => $a->pivot->unlocked_at,
            ]) ?? collect(),
            //!property should only display if it exists and is constructed
            'property' => $character->property && $character->property_condition == 'CONSTRUCTED' ? [
                'name' => $character->property->name,
                'image' => $character->property->image_url,
            ] : null,
            'businesses' => $character->businesses->map(fn($b) => [
                'name' => $b->name,
                'image' => $b->image_url,
                'slug' => $b->slug,
            ]),
            'corporation' => $character->corporation ? [
                'name' => $character->corporation->name,
                'imageUrl' => $character->corporation->image_url,
                'homeCity' => $character->corporation->city?->name,
                'position' => $character->corporation_position,
                'isCeo' => $character->corporation->ceo_id === $character->id,
                'isFounder' => $character->corporation->founder_id === $character->id,
                'chain' => $this->buildCorpChain($character),
            ] : null,
        ];

        return Inertia::render('Profile', [
            'profile' => $profileData,
            'isOwnProfile' => $isOwnProfile,
            'isDead' => false,
            'targetCharacterId' => $isOwnProfile ? null : $character->id,
            'viewerCitySlug' => $viewerCitySlug,
            'characterItems' => $character->items()
                ->with('template')
                ->whereHas('template', function ($q) {
                    $q->where('type', 'clothing');
                })
                ->get()
                ->map(fn($item) => [
                    'id' => $item->id,
                    'name' => $item->template->name,
                    'slot' => $item->template->slot,
                    'type' => $item->template->type,
                    'is_equipped' => $item->is_equipped,
                    'equipped_slot' => $item->equipped_slot,
                    'image_url' => $item->template->image_url,
                ]),
        ]);
    }

    private function buildCorpChain(Character $character): array
    {
        $corp = $character->corporation;
        if (!$corp)
            return [];

        $isCeo = $corp->ceo_id === $character->id;
        $position = $character->corporation_position;
        $isDirectorOfBoard = $corp->is_holding_company
            && $position === Corporation::POSITION_DIRECTOR_OF_BOARD
            && (int) ($character->career_rank ?? 0) >= 7;
        $directorOfBoard = $corp->directorOfBoard();

        $members = $corp->relationLoaded('members')
            ? $corp->members
            : $corp->members()
                ->whereNull('deleted_at')
                ->get(['id', 'display_name', 'custom_avatar_url', 'career_id', 'career_rank', 'corporation_position', 'corporation_id', 'corporation_reports_to_id', 'health', 'deleted_at']);

        $members = $members
            ->filter(fn($m) => (int) $m->id !== (int) $character->id && !$m->trashed())
            ->values();

        $serialize = fn($m) => [
            'id' => $m->id,
            'displayName' => $m->display_name,
            'avatarUrl' => $m->avatar_url,
            'position' => $m->corporation_position,
            'isCeo' => $corp->ceo_id === $m->id,
            'isFounder' => $corp->founder_id === $m->id,
        ];

        $reportsTo = collect();
        if (!$isCeo && !$isDirectorOfBoard) {
            $supervisor = null;

            if (
                $corp->is_holding_company
                && $corp->isBoardMember($character)
                && $directorOfBoard
                && (int) $directorOfBoard->id !== (int) $character->id
            ) {
                $supervisor = $members->first(fn($m) => (int) $m->id === (int) $directorOfBoard->id) ?? $directorOfBoard;
            } elseif (in_array($position, ['cfo', 'cto'], true)) {
                $supervisor = $members->first(fn($m) => (int) $m->id === (int) $corp->ceo_id);
            } elseif ($character->corporation_reports_to_id) {
                $supervisor = $members->first(fn($m) => (int) $m->id === (int) $character->corporation_reports_to_id);
            }

            if (!$supervisor) {
                $supervisor = $members->first(fn($m) => (int) $m->id === (int) $corp->ceo_id);
            }

            if ($supervisor) {
                $reportsTo = collect([$supervisor]);
            }
        }

        if ($isDirectorOfBoard) {
            $manages = $members->filter(fn($m) => in_array($m->corporation_position, Corporation::BOARD_POSITIONS, true));
        } elseif ($isCeo) {
            $manages = $members->filter(fn($m) => in_array($m->corporation_position, ['cfo', 'cto'], true));
            if ($manages->isEmpty()) {
                $manages = $members->filter(fn($m) => $m->corporation_position === 'vp');
            }
        } else {
            $manages = $members->filter(fn($m) => (int) $m->corporation_reports_to_id === (int) $character->id);
        }

        $primary = $manages->isNotEmpty() ? $manages : $reportsTo;

        return [
            'label' => $manages->isNotEmpty() ? 'MANAGING' : 'REPORTS TO',
            'members' => $primary->values()->map($serialize)->all(),
            'reportsTo' => $reportsTo->values()->map($serialize)->all(),
            'manages' => $manages->values()->map($serialize)->all(),
            'reportsToCorporation' => ($isCeo && $corp->parentTrust)
                ? $this->serializeCorporationForProfile($corp->parentTrust)
                : null,
            'managesCorporations' => $corp->isBoardMember($character)
                ? $corp->activeSubsidiaries
                    ->values()
                    ->map(fn($subsidiary) => $this->serializeCorporationForProfile($subsidiary))
                    ->all()
                : [],
        ];
    }

    private function serializeCorporationForProfile($corporation): array
    {
        return [
            'id' => $corporation->id,
            'name' => $corporation->name,
            'imageUrl' => $corporation->image_url,
            'homeCity' => $corporation->city?->name,
        ];
    }

    private function calculateWealthTier(Character $character): string
    {
        $totalWealth = $character->cash_on_hand + $character->cash_in_bank;



        return match (true) {
            $totalWealth < 250000 => 'poor',
            $totalWealth < 500000 => 'comfortable',
            $totalWealth < 1000000 => 'bourgeoise',
            $totalWealth < 5000000 => 'ultra_high',
            $totalWealth < 100000000 => 'wealth_beyond',
            default => 'wealth_beyond',
        };
    }

    private function showDeadProfile(Character $character, ?\App\Models\Character $viewer = null)
    {
        $userData = [
            'is_banned' => $character->user?->is_banned ?? false,
            'ban_reason' => $character->user?->ban_reason ?? null,
            'banned_at' => $character->user?->banned_at ?? null,
        ];

        $timelineJson = DB::table('character_histories')
            ->where('character_id', $character->id)
            ->value('career_timeline');

        $finalCareerRank = CharacterHistory::finalCareerAndRankFromTimeline($timelineJson);
        $finalRank = $finalCareerRank['rank'] ?? null;

        $canRevive = false;
        if ($viewer && !$character->user?->is_banned) {
            $healthcareId = \App\Models\Career::findByCode('healthcare')?->id;
            $inWindow = $character->deleted_at && $character->deleted_at->addHours(12)->isFuture();
            $neverRevived = $character->revived_at === null;
            $notSuicide = $character->death_cause !== 'Suicide';
            $talentActive = $viewer->hasTalentActive('lazarus_connection');
            $isHealthcare = $healthcareId && $viewer->career_id === $healthcareId;

            $canRevive = $isHealthcare && $talentActive && $inWindow && $neverRevived && $notSuicide;
        }

        return Inertia::render('Profile', [
            'profile' => [
                'displayName' => $character->display_name,
                'avatarUrl' => $character->custom_avatar_url ?: CareerRank::where('rank_name', $finalRank)->first()?->avatar_url,
                'rank' => $finalRank ?? 'Entry Level',
                'biography' => $character->biography,
                'deathDate' => $character->deleted_at?->toIso8601String() ?? null,
                'deathCause' => $character->death_cause ?? null,
                'deathReason' => $character->death_reason ?? null,
                'user' => $userData,
            ],
            'isDead' => true,
            'isOwnProfile' => false,
            'can_revive' => $canRevive,
        ]);
    }

    public function update(Request $request)
    {
        $character = $request->user()->character;

        if (!$character || $character->trashed()) {
            return back()->with('error', 'Character not found');
        }

        try {
            $validated = $request->validate([
                'biography' => 'nullable|string|max:30',
                'additional_info' => 'nullable|string|max:500',
            ]);

            $character->update($validated);

            return back()->with('success', 'Profile updated successfully!');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()
                ->withErrors($e->errors())
                ->with('error', collect($e->errors())->flatten()->first());
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to update profile.');
        }
    }
}
