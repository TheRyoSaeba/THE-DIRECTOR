<?php

namespace App\Models;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Services\JournalService;


class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        "username",
        "email",
        "password",
        "google_id",
        "google_token",
        "google_refresh_token",
        "last_ip",
        "last_login_at",
        "is_banned",
        "ban_reason",
        "banned_at",
        "banned_until",
        "hide_achievements",
        "disable_card_flip",
        "email_opt_out_at",
    ];

    protected $hidden = [
        "password",
        "remember_token",
        "is_admin",
        "google_token",
        "google_refresh_token",
    ];

    protected $casts = [
        "last_login_at" => "datetime",
        "banned_at" => "datetime",
        "banned_until" => "datetime",
        "email_opt_out_at" => "datetime",
        "password" => "hashed",
        "is_banned" => "boolean",
        "is_admin" => "boolean",
        "hide_achievements" => "boolean",
        "disable_card_flip" => "boolean",
    ];

    protected static function booted(): void
    {
    }

    public function character()
    {
        return $this->hasOne(Character::class);
    }

    public function getLoadedCharacter()
    {
        if ($this->relationLoaded('character')) {
            return $this->getRelation('character');
        }

        try {
            $character = $this->loadCharacterJoined();
        } catch (QueryException $e) {
            if ($this->getConnection()->transactionLevel() > 0) {
                throw $e; // a failed statement aborts the Postgres transaction
            }

            // Schema drift between a migration and the Octane reload (e.g. a
            // dropped column still in the cached column list): forget the list
            // and use the classic eager-load path for this request.
            self::$joinedColumns = null;
            Log::warning('[User] joined character load failed; falling back to eager loads.', [
                'user_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            $character = $this->loadCharacterEager();
        }

        $this->setRelation('character', $character);

        return $character;
    }

    /**
     * Relation => [table alias, related model class] hydrated from the single
     * joined character query. `corporation` is intentionally not here: it is
     * only needed by a few code paths, which lazy-load it on first access.
     */
    private const JOINED_RELATIONS = [
        'timers' => ['_jt', CharacterTimers::class],
        'career' => ['_jca', Career::class],
        'city' => ['_jci', City::class],
        'homeCity' => ['_jhc', City::class],
    ];

    /**
     * Per-worker cache of the joined tables' column names (schema metadata
     * only — never row data, so nothing user-specific survives a request).
     * Reset on every worker boot/reload, i.e. on every deploy.
     *
     * @var array<string, list<string>>|null
     */
    private static ?array $joinedColumns = null;

    /**
     * One round trip instead of five: the character row plus its timers,
     * career, current city and home city, all read fresh from the database on
     * every request (pg_cron mutates timers and cities behind Laravel's back,
     * so none of this is cached across requests). Each related model is
     * hydrated with newFromBuilder() from exactly the raw column values its
     * own `select *` would have returned, so attributes, casts and `exists`
     * are identical to the previous eager loads.
     */
    private function loadCharacterJoined(): ?Character
    {
        $relation = $this->character();
        $related = $relation->getRelated();
        $characterTable = $related->getTable();
        $columns = self::joinedColumns($related->getConnection());

        if ($columns === null) {
            return $this->loadCharacterEager();
        }

        $query = $relation->getQuery()->withTrashed()->select("{$characterTable}.*");

        foreach (self::JOINED_RELATIONS as $name => [$alias, $class]) {
            $model = new $class();
            $foreignKey = $related->{$name}()->getForeignKeyName();
            $first = $name === 'timers'
                ? ["{$alias}.{$foreignKey}", "{$characterTable}.{$related->getKeyName()}"]
                : ["{$alias}.{$model->getKeyName()}", "{$characterTable}.{$foreignKey}"];

            $query->leftJoin("{$model->getTable()} as {$alias}", $first[0], '=', $first[1]);

            foreach ($columns[$model->getTable()] as $column) {
                $query->addSelect("{$alias}.{$column} as {$alias}__{$column}");
            }
        }

        $row = $query->toBase()->first();

        if ($row === null) {
            return null;
        }

        $row = (array) $row;
        $buckets = array_fill_keys(array_keys(self::JOINED_RELATIONS), []);
        $own = [];

        foreach ($row as $key => $value) {
            if (str_starts_with($key, '_j') && ($pos = strpos($key, '__')) !== false) {
                $alias = substr($key, 0, $pos);
                foreach (self::JOINED_RELATIONS as $name => [$relAlias]) {
                    if ($relAlias === $alias) {
                        $buckets[$name][substr($key, $pos + 2)] = $value;
                        continue 2;
                    }
                }
            }
            $own[$key] = $value;
        }

        $character = $related->newFromBuilder($own, $related->getConnection()->getName());

        $hydrate = function (string $name) use ($buckets): ?Model {
            [, $class] = self::JOINED_RELATIONS[$name];
            $model = new $class();
            $attributes = $buckets[$name];

            if (($attributes[$model->getKeyName()] ?? null) === null) {
                return null;
            }

            return $model->newFromBuilder($attributes, $model->getConnection()->getName());
        };

        $character->setRelation('timers', $hydrate('timers'));
        $character->setRelation('career', $hydrate('career'));
        $character->setRelation('city', $hydrate('city'));
        $character->setRelation(
            'homeCity',
            (int) $character->city_id === (int) $character->home_city_id
                ? $character->getRelation('city')
                : $hydrate('homeCity')
        );

        return $character;
    }

    /** The pre-join implementation, kept as a fallback. */
    private function loadCharacterEager(): ?Character
    {
        $character = $this->character()
            ->withTrashed()
            ->with(['timers', 'career', 'city'])
            ->first();

        if ($character) {
            $character->setRelation(
                'homeCity',
                (int) $character->city_id === (int) $character->home_city_id
                    ? $character->city
                    : City::find($character->home_city_id)
            );
        }

        return $character;
    }

    /** @return array<string, list<string>>|null */
    private static function joinedColumns(ConnectionInterface $connection): ?array
    {
        if (self::$joinedColumns !== null) {
            return self::$joinedColumns;
        }

        $tables = [];
        foreach (self::JOINED_RELATIONS as [, $class]) {
            $tables[] = (new $class())->getTable();
        }
        $tables = array_values(array_unique($tables));

        $placeholders = implode(', ', array_fill(0, count($tables), '?'));
        $rows = $connection->select(
            "select table_name, column_name from information_schema.columns
             where table_schema = current_schema() and table_name in ({$placeholders})
             order by table_name, ordinal_position",
            $tables,
        );

        $columns = array_fill_keys($tables, []);
        foreach ($rows as $row) {
            $columns[$row->table_name][] = $row->column_name;
        }

        foreach ($columns as $list) {
            if ($list === []) {
                return null; // unexpected schema layout: don't cache, use eager loads
            }
        }

        return self::$joinedColumns = $columns;
    }

    public function achievements()
    {
        return $this->belongsToMany(
            Achievement::class,
            "user_achievements",
        )->withPivot("unlocked_at");
    }

    public function hasAchievement($achievementSlug)
    {
        return $this->achievements()->where("slug", $achievementSlug)->exists();
    }

    public function checkAchievementThresholds(string $type, int $currentValue, ?\App\Models\Character $character = null): void
    {
        $ownedIds = $this->achievements()->pluck('achievements.id');

        Achievement::where('trigger_type', $type)
            ->where('trigger_value', '<=', $currentValue)
            ->whereNotIn('id', $ownedIds)
            ->each(function ($achievement) use ($character) {
                $this->achievements()->attach($achievement->id, ['unlocked_at' => now()]);
                $this->notifyAchievement($achievement, $character);
            });
    }

    public function notifyAchievement(Achievement $achievement, ?\App\Models\Character $character = null): void
    {
        $targetCharacter = $character ?? $this->character;
        if (!$targetCharacter)
            return;

        JournalService::custom(
            $targetCharacter->id,
            'achievement_unlocked',
            [
                'achievement_name' => $achievement->name,
                'achievement_icon' => $achievement->icon,
            ]
        );
    }

    public function scopeActive($query)
    {
        return $query->whereRaw('"is_banned" IS FALSE');
    }

    public function scopeBanned($query)
    {
        return $query->whereRaw('"is_banned" IS TRUE');
    }

    public function getHasCharacterAttribute(): bool
    {
        return $this->character()->withTrashed()->exists();
    }

    /**
     * Ban the account. A null $until is a permanent ban; a future timestamp
     * is a temporary ban that auto-lifts once it passes (see isCurrentlyBanned
     * and the CheckPlayerState middleware).
     */
    public function ban(?string $reason = null, ?\DateTimeInterface $until = null): void
    {
        $this->update([
            "is_banned" => true,
            "ban_reason" => $reason,
            "banned_at" => now(),
            "banned_until" => $until,
        ]);
    }

    public function unban(): void
    {
        $this->update([
            "is_banned" => false,
            "ban_reason" => null,
            "banned_at" => null,
            "banned_until" => null,
        ]);

        $character = $this->character()->withTrashed()->first();
        if ($character && $character->trashed()) {
            $character->restore();
            $character->update(["health" => 100]);
        } elseif ($character) {
            $character->update(["health" => 100]);
        }
    }

    /**
     * Whether the account ban is in force right now. A temp ban whose
     * banned_until has passed is no longer active even though is_banned is
     * still true in the row — the middleware lifts it lazily on next request.
     */
    public function isCurrentlyBanned(): bool
    {
        if (! $this->is_banned) {
            return false;
        }

        return $this->banned_until === null || $this->banned_until->isFuture();
    }
}
