<?php

namespace App\Models;

use App\Support\SafeCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;


/*TODO all the ways to change careers
 - University Startcareer()
 - Settings quitcareer()
 - Police training ()
 - Corporation invite/create ()
 - Mayoral join and exit()
 - police/law/step down/dismiss.() */

class Career extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
    ];

    public function ranks(): HasMany
    {
        return $this->hasMany(CareerRank::class)->orderBy('rank_level');
    }

    public function earns(): HasMany
    {
        return $this->hasMany(CareerEarn::class);
    }

    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    public function getRankByLevel(int $level): ?CareerRank
    {
        return CareerRank::getRanksForCareer($this->id)->where('rank_level', $level)->first();
    }

    public function getMaxRank(): ?CareerRank
    {
        return CareerRank::getRanksForCareer($this->id)->sortByDesc('rank_level')->first();
    }

    public static function findByCode(string $code): ?self
    {
        $code = strtolower($code);
        $cacheKey = "career_def_by_code_{$code}";

        $ttlSeconds = 5;
        return SafeCache::remember($cacheKey, $ttlSeconds, fn() => self::whereRaw('LOWER(code) = ?', [$code])->first());
    }
}
