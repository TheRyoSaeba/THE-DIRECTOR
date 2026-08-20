<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Business extends Model
{
    use HasFactory;

    protected $fillable = [
        'city_id',
        'code',
        'name',
        'slug',
        'description',
        'image_url',
        'is_purchasable',
        'base_price',
        'sort_order',
        'is_active',
        'owner_id',
        'owner_title',
        'balance',
        'data',
        'last_extorted_at',
    ];

    protected $casts = [
        'is_purchasable' => 'boolean',
        'is_active' => 'boolean',
        'base_price' => 'integer',
        'balance' => 'integer',
        'sort_order' => 'integer',
        'data' => 'array',
        'last_extorted_at' => 'datetime',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Character::class , 'owner_id');
    }

    public function getSellPriceAttribute(): int
    {
        return (int)round($this->base_price * 0.5);
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    public function setSetting(string $key, mixed $value): void
    {
        $data = $this->data ?? [];
        data_set($data, $key, $value);
        $this->update(['data' => $data]);
    }

    public function getShopItems(bool $includeInactive = false)
    {
        $query = \App\Models\GameItem::query();

        if (!$includeInactive) {
            $query->active();
        }

        if (str_starts_with(strtolower($this->code), 'shop-')) {
            $type = str_replace('shop-', '', strtolower($this->code));

            if ($type === 'turlington' || $type === 'plaza-ginza') {
                return $query->where('type', 'clothing')->orderBy('price', 'asc')->get();
            }

            if ($type === 'vehicle') {
                return $query->where('type', 'vehicle')->orderBy('price', 'asc')->get();
            }

            if ($type === 'pharmacy') {
                return $query
                    ->where('type', 'item')
                    ->where(function ($query) {
                        $query->whereNull('data')
                            ->orWhereRaw("COALESCE(data->>'corporate_only', 'false') != 'true'");
                    })
                    ->orderBy('price', 'asc')
                    ->get();
            }
            if ($type === 'blackmarket') {
                return $query->whereIn('type', ['gadget'])->orderBy('price', 'asc')->get();
            }

            return $query->whereIn('type', ['weapon', 'armor'])->orderBy('price', 'asc')->get();
        }

        return collect();
    }

    public function findShopItem(string $slug, bool $includeInactive = false)
    {
        return $this->getShopItems($includeInactive)->first(fn($item) => strtolower($item->slug) === strtolower($slug));
    }

    public function addBalance(int $amount): void
    {
        $this->increment('balance', $amount);
    }


    public function removeBalance(int $amount): bool
    {
        if ($this->balance < $amount) {
            return false;
        }

        $this->decrement('balance', $amount);

        return true;
    }

    public function scopeByCity($query, City $city)
    {
        return $query->where('city_id', $city->id);
    }

    public function scopeActive($query)
    {
        return $query->whereRaw('"is_active" IS TRUE');
    }

    /**
     * Look up a business by city + code. Optional $with eager-loads
     * relations on the returned model so callers that need ->owner or
     * ->manager don't trigger a follow-up SELECT.
     */
    public static function forCity(City $city, string $code, array $with = []): ?self
    {
        return static::where('city_id', $city->id)
            ->where(DB::raw('LOWER(code)'), strtolower($code))
            ->with($with)
            ->first();
    }

    public function canManualRestock(): bool
    {
        $restockAt = $this->getSetting('manual_restock_at');
        if (!$restockAt) {
            return true;
        }

        return \Carbon\Carbon::parse($restockAt)->addHours(12)->isPast();
    }


    public function restock(): void
    {
        DB::transaction(function () {
            $items = $this->getShopItems();

            foreach ($items as $item) {
                if (!isset($item->max_stock)) {
                    continue;
                }

                $currentStock = $item->stock ?? 0;

                if ($currentStock >= $item->max_stock) {
                    continue;
                }

                $price = $item->price;
                $chance = match (true) {
                        $price < 10000 => 80,
                        $price >= 100000 => 5,
                        $price >= 50000 => 15,
                        default => (int)max(15, min(80, 80 + ($price - 10000) * (-65.0 / 40000.0))),
                    };

                if (rand(1, 100) <= $chance) {
                    $room = $item->max_stock - $currentStock;
                    $newStock = min($item->max_stock, $currentStock + rand(1, $room));
                    $item->update(['stock' => $newStock]);
                }
            }

            $this->manualRestock();
        });
    }

    public function manualRestock(): void
    {
        $this->setSetting('manual_restock_at', now()->toIso8601String());
    }
}
