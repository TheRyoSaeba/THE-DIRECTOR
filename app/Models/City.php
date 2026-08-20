<?php

namespace App\Models;

use App\Support\SafeCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
//!TODO DON'R FORGET NUMERIC(5,2)

class City extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'image_url',
        'crime_rate',
        'mayor_id',
        'mayor_display_name',

        'income_tax_rate',
        'corporate_tax_rate',
        'corp_regulation_active',
        'bonds_active',
        'death_sentence_active',
    ];

    protected $casts = [
        'crime_rate' => 'decimal:2',
        'income_tax_rate' => 'integer',
        'corporate_tax_rate' => 'integer',
        'corp_regulation_active' => 'boolean',
        'bonds_active' => 'boolean',
        'death_sentence_active' => 'boolean',
    ];


    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function mayor(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'mayor_id');
    }

    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    public function hqCorporations(): HasMany
    {
        return $this->hasMany(Corporation::class, 'home_city_id');
    }



    public function holdingCompany(): HasOne
    {
        return $this->hasOne(Corporation::class, 'home_city_id')
            ->where('is_holding_company', true);
    }

    public function residents(): HasMany
    {
        return $this->hasMany(Character::class, 'home_city_id');
    }

    public function getCareerByCity(string $careerCode)
    {
        $career = Career::findByCode($careerCode);

        if (!$career) {
            return null;
        }

        return $this->residents()
            ->where('career_id', $career->id)
            ->alive();
    }

    public function elections(): HasMany
    {
        return $this->hasMany(Election::class);
    }

    public function scopeWithJailedCount($query)
    {
        return $query->withCount([
            'characters as jailed_count' => function ($q) {
                $q->whereHas('timers', function ($timerQuery) {
                    $timerQuery->where('jail_until', '>', now());
                });
            }
        ]);
    }

    public function getServiceLeader(string $careerCode): ?array
    {

        $careerCode = strtolower($careerCode);

        $cacheKey = "city_{$this->id}_leader_{$careerCode}";
        $requestMemoKey = "service_leader:{$this->id}:{$careerCode}";

        if (app()->bound('request')) {
            $memo = request()->attributes->get('city_service_leaders', []);

            if (array_key_exists($requestMemoKey, $memo)) {
                return $memo[$requestMemoKey];
            }
        }

        $leader = SafeCache::remember($cacheKey, 10, function () use ($careerCode) {
            $career = Career::findByCode($careerCode);
            if (!$career) {
                return null;
            }

            $leader = Character::where('home_city_id', $this->id)
                ->where('career_id', $career->id)
                ->where('career_rank', 4)
                ->alive()
                ->orderByDesc('career_xp')
                ->first();

            if (!$leader) {
                return null;
            }

            $rank = $leader->current_rank;

            return [
                'id' => $leader->id,
                'name' => $leader->display_name,
                'rank' => $rank?->rank_name ?? 'Entry Level',
                'since' => $leader->created_at->format('M Y'),
                'avatar_url' => $leader->custom_avatar_url ?: $rank?->avatar_url,
            ];
        });

        if (app()->bound('request')) {
            $memo = request()->attributes->get('city_service_leaders', []);
            $memo[$requestMemoKey] = $leader;
            request()->attributes->set('city_service_leaders', $memo);
        }

        return $leader;
    }

    public function setMayor(Character $character): void
    {
        $this->update([
            'mayor_id' => $character->id,
            'mayor_display_name' => $character->display_name,
        ]);
    }

    public function removeMayor(): void
    {
        $this->update([
            'mayor_id' => null,
            'mayor_display_name' => null,


            'income_tax_rate' => 5,
            'corporate_tax_rate' => 0,
            'corp_regulation_active' => false,
            'bonds_active' => false,
            'death_sentence_active' => false,
        ]);
    }


    public function syncPoliciesFromTerm(MayorTerm $term): void
    {
        $this->update([
            'income_tax_rate' => $term->income_tax_rate,
            'corporate_tax_rate' => $term->corporate_tax_rate,
            'corp_regulation_active' => $term->corp_regulation_active,
            'bonds_active' => $term->bonds_active,
            'death_sentence_active' => $term->death_sentence_active,
        ]);
    }






    public static function decreaseCrimeRateById(int $cityId, float $amount): void
    {
        \Illuminate\Support\Facades\DB::table('cities')
            ->where('id', $cityId)
            ->update([
                'crime_rate' => \Illuminate\Support\Facades\DB::raw(
                    'GREATEST(crime_rate - ' . number_format($amount, 4, '.', '') . ', 0)'
                )
            ]);
    }


    public static function increaseCrimeRateById(int $cityId, float $amount): void
    {
        \Illuminate\Support\Facades\DB::table('cities')
            ->where('id', $cityId)
            ->update([
                'crime_rate' => \Illuminate\Support\Facades\DB::raw(
                    'LEAST(crime_rate + ' . number_format($amount, 4, '.', '') . ', 100)'
                )
            ]);
    }


    public function decreaseCrimeRate(float $amount): void
    {
        static::decreaseCrimeRateById($this->id, $amount);
        $this->crime_rate = max(0, (float) $this->crime_rate - $amount);
    }


    public function increaseCrimeRate(float $amount): void
    {
        static::increaseCrimeRateById($this->id, $amount);
        $this->crime_rate = min(100, (float) $this->crime_rate + $amount);
    }

}
