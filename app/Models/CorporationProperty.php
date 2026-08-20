<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class CorporationProperty extends Model
{
    public const TYPE_HQ = 'hq';
    public const TYPE_MEDICAL = 'medical';
    public const TYPE_LAUNDERING = 'laundering';

    public const CONDITION_CONSTRUCTED = 'CONSTRUCTED';
    public const CONDITION_PENDING = 'PENDING';
    public const CONDITION_BOMBED = 'BOMBED';
    public const CONDITION_DESTROYED = 'DESTROYED';
    public const CONDITION_SEIZED = 'SEIZED';
    public const CONDITION_DEFAULTED = 'DEFAULTED';

    public const OPERATIONAL_CONDITIONS = [self::CONDITION_CONSTRUCTED];

    public const MEDICINE_CX717 = 'cx-717';
    public const MEDICINE_TAK925 = 'tak-925';

    public const MEDICAL_PRODUCTS = [
        self::MEDICINE_CX717 => 'CX-717',
        self::MEDICINE_TAK925 => 'TAK-925',
    ];
    public const MEDICAL_STOCK_STOCKED = 'stocked';
    public const MEDICAL_STOCK_RESERVED = 'reserved';
    public const MEDICAL_PACK_UNITS = 3;
    public const MIN_MEDICAL_PAYOUT_PER_PACK = 1_000;
    public const MAX_MEDICAL_PAYOUT_PER_PACK = 5_000;
    public const MIN_MIRROR_BANKER_PERCENTAGE = 1;
    public const MAX_MIRROR_BANKER_PERCENTAGE = 5;
    public const DEFAULT_MIRROR_BANKER_PERCENTAGE = 2;
    public const MIRROR_SPREAD_PERCENT_MIN = 10;
    public const MIRROR_SPREAD_PERCENT_MAX = 20;
    public const MIRROR_TRANSACTION_TTL_SECONDS = 600;
    public const MIRROR_STATUS_PENDING = 'pending';
    public const MIRROR_STATUS_ACCEPTED = 'accepted';
    public const MEDICAL_PRODUCT_MARKETS = [
        self::MEDICINE_CX717 => [
            'production_weight' => 25,
            'npc_price_min' => 12_000,
            'npc_price_max' => 18_000,
        ],
        self::MEDICINE_TAK925 => [
            'production_weight' => 75,
            'npc_price_min' => 4_000,
            'npc_price_max' => 7_000,
        ],
    ];

    protected $fillable = [
        'corporation_id',
        'type',
        'tier',
        'name',
        'image_url',
        'price',
        'condition',
        'seized_until',
        'protection_until',
        'last_upkeep_at',
        'data',
    ];

    protected $casts = [
        'tier' => 'integer',
        'price' => 'integer',
        'seized_until' => 'datetime',
        'protection_until' => 'datetime',
        'last_upkeep_at' => 'datetime',
        'data' => 'array',
    ];

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    public function scopeTemplates(Builder $query): Builder
    {
        return $query->whereNull('corporation_id');
    }

    public function scopeOwned(Builder $query): Builder
    {
        return $query->whereNotNull('corporation_id');
    }

    public function isTemplate(): bool
    {
        return $this->corporation_id === null;
    }

    public static function template(string $type, int $tier): ?self
    {
        return self::query()
            ->templates()
            ->where('type', $type)
            ->where('tier', $tier)
            ->first();
    }


    public static function createStartingHeadquarters(Corporation $corp, int $tier = 1): self
    {
        $template = self::template(self::TYPE_HQ, $tier);
        if (!$template) {
            throw new RuntimeException("Starting corporation headquarters template (tier {$tier}) is missing.");
        }

        $hq = self::updateOrCreate(
            [
                'corporation_id' => $corp->id,
                'type' => self::TYPE_HQ,
            ],
            self::ownedAttributesFromTemplate($template)
        );

        $corp->update(['hq_tier' => $hq->tier]);

        return $hq;
    }

    public static function purchasableTemplatesFor(Corporation $corp)
    {
        $owned = $corp->relationLoaded('properties')
            ? $corp->properties
            : $corp->properties()->get();

        $templates = collect();

    
        $hasPendingHq = $owned
            ->where('type', self::TYPE_HQ)
            ->where('condition', self::CONDITION_PENDING)
            ->isNotEmpty();
        $currentHqTier = (int) ($corp->hq_tier ?? 0);


        if (!$hasPendingHq) {
            $nextHq = self::template(self::TYPE_HQ, $currentHqTier + 1);

            if ($nextHq) {
                $templates->push($nextHq);
            }
        }

        $ownedTypes = $owned->pluck('type')->all();

        self::query()
            ->templates()
            ->where('type', '!=', self::TYPE_HQ)
            ->orderBy('type')
            ->orderBy('tier')
            ->get()
            ->filter(fn(self $template) => !in_array($template->type, $ownedTypes, true))
            ->each(fn(self $template) => $templates->push($template));

        return $templates->values();
    }

    public static function quoteFor(self $template, int $taxRate): array
    {
        $price = (int) $template->price;
        $taxRate = max(0, $taxRate);
        $tax = (int) floor($price * ($taxRate / 100));

        return [
            'price' => $price,
            'taxRate' => $taxRate,
            'tax' => $tax,
            'total' => $price + $tax,
        ];
    }

    public static function purchaseTemplate(Corporation $corp, self $template): self
    {
        if (!$template->isTemplate()) {
            throw new RuntimeException('Choose a valid corporation property.');
        }

        $attrs = self::ownedAttributesFromTemplate($template, construct: false);

        if ($template->type === self::TYPE_HQ) {

            $hqRows = self::query()
                ->where('corporation_id', $corp->id)
                ->where('type', self::TYPE_HQ)
                ->lockForUpdate()
                ->get();

            $pending = $hqRows->firstWhere('condition', self::CONDITION_PENDING);
            if ($pending) {
                throw new RuntimeException('Your current headquarters upgrade is still under construction.');
            }

            $currentTier = (int) ($corp->hq_tier ?? 0);
            if ((int) $template->tier !== $currentTier + 1) {
                throw new RuntimeException('That headquarters upgrade is not available.');
            }

            return self::create(array_merge([
                'corporation_id' => $corp->id,
                'type' => $template->type,
            ], $attrs));
        }

        $owned = self::query()
            ->where('corporation_id', $corp->id)
            ->where('type', $template->type)
            ->lockForUpdate()
            ->first();

        if ($owned) {
            throw new RuntimeException('That property is already owned.');
        }

        return self::create(array_merge([
            'corporation_id' => $corp->id,
            'type' => $template->type,
        ], $attrs));
    }

    public function isOperational(): bool
    {
        return in_array($this->condition, self::OPERATIONAL_CONDITIONS, true);
    }

    public function dailyUpkeepCost(): int
    {
        return (int) ceil(max(0, (int) $this->price) * 0.10);
    }

    public static function normalizeMedicalData(?array $data): array
    {
        $data = $data ?? [];
        $stock = is_array($data['stock'] ?? null) ? $data['stock'] : [];

        foreach (array_keys(self::MEDICAL_PRODUCTS) as $slug) {
            $rawProduct = is_array($stock[$slug] ?? null) ? $stock[$slug] : [];

            $stockedData = is_array($rawProduct[self::MEDICAL_STOCK_STOCKED] ?? null)
                ? $rawProduct[self::MEDICAL_STOCK_STOCKED]
                : [];
            $product = [
                self::MEDICAL_STOCK_STOCKED => [
                    'packs' => max(0, (int) ($stockedData['packs'] ?? 0)),
                    'cost' => max(0, (int) ($stockedData['cost'] ?? 0)),
                ],
            ];

            $reserved = [];
            $reservedRows = is_array($rawProduct[self::MEDICAL_STOCK_RESERVED] ?? null)
                ? $rawProduct[self::MEDICAL_STOCK_RESERVED]
                : [];
            $now = now()->timestamp;

            foreach ($reservedRows as $key => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $sources = [];
                $rawSources = is_array($row['sources'] ?? null) ? $row['sources'] : [];

                foreach ($rawSources as $source) {
                    if (!is_array($source)) {
                        continue;
                    }

                    $sourcePacks = max(0, (int) ($source['packs'] ?? 0));
                    if ($sourcePacks <= 0) {
                        continue;
                    }

                    $sources[] = [
                        'packs' => $sourcePacks,
                        'cost' => max(0, (int) ($source['cost'] ?? 0)),
                    ];
                }

                if (empty($sources)) {
                    $packs = max(0, (int) ($row['packs'] ?? 0));
                    if ($packs <= 0) {
                        continue;
                    }

                    $sources[] = [
                        'packs' => $packs,
                        'cost' => max(0, (int) ($row['cost'] ?? 0)),
                    ];
                }

                $packs = collect($sources)->sum('packs');
                $cost = collect($sources)->sum('cost');
                $expiresAt = (int) ($row['expires_at'] ?? 0);

                if ($expiresAt > 0 && $expiresAt <= $now) {
                    foreach ($sources as $source) {
                        $product[self::MEDICAL_STOCK_STOCKED]['packs'] += $source['packs'];
                        $product[self::MEDICAL_STOCK_STOCKED]['cost'] += $source['cost'];
                    }
                    continue;
                }

                $reserved[(string) $key] = [
                    'packs' => $packs,
                    'cost' => $cost,
                    'sources' => $sources,
                    'expires_at' => $expiresAt,
                ];
            }

            $product[self::MEDICAL_STOCK_RESERVED] = $reserved;

            $stock[$slug] = $product;
        }

        $data['stock'] = $stock;
        $payoutPerPack = $data['medical_payout_per_pack']
            ?? $data['medical_payout_rate']
            ?? 1_000;

        $data['medical_payout_per_pack'] = min(
            self::MAX_MEDICAL_PAYOUT_PER_PACK,
            max(self::MIN_MEDICAL_PAYOUT_PER_PACK, (int) $payoutPerPack)
        );
        unset($data['medical_payout_rate']);

        return $data;
    }

    public static function chooseMedicalProduct(): string
    {
        $totalWeight = collect(self::MEDICAL_PRODUCT_MARKETS)
            ->sum(fn(array $market) => max(0, (int) ($market['production_weight'] ?? 0)));

        if ($totalWeight <= 0) {
            return array_key_first(self::MEDICAL_PRODUCTS);
        }

        $roll = random_int(1, $totalWeight);

        foreach (self::MEDICAL_PRODUCT_MARKETS as $slug => $market) {
            $roll -= max(0, (int) ($market['production_weight'] ?? 0));

            if ($roll <= 0) {
                return $slug;
            }
        }

        return array_key_first(self::MEDICAL_PRODUCTS);
    }

    public static function medicalProductMarket(string $product): array
    {
        return self::MEDICAL_PRODUCT_MARKETS[$product]
            ?? [
                'production_weight' => 1,
                'npc_price_min' => 4_000,
                'npc_price_max' => 7_000,
            ];
    }

    public static function rollMedicalNpcPricePerPack(string $product): int
    {
        $market = self::medicalProductMarket($product);
        $min = max(0, (int) ($market['npc_price_min'] ?? 0));
        $max = max($min, (int) ($market['npc_price_max'] ?? $min));

        $roll = random_int($min, $max);

        \Illuminate\Support\Facades\Log::info('[CorporationProfit] NPC price-per-pack rolled.', [
            'product' => $product,
            'min' => $min,
            'max' => $max,
            'rolled_price' => $roll,
        ]);

        return $roll;
    }

    public function addStockedMedicalPacks(string $product, int $packs, int $cost): void
    {
        $this->assertMedicalProduct($product);

        $data = self::normalizeMedicalData($this->data);
        $data['stock'][$product][self::MEDICAL_STOCK_STOCKED]['packs'] += max(0, $packs);
        $data['stock'][$product][self::MEDICAL_STOCK_STOCKED]['cost'] += max(0, $cost);

        $this->update(['data' => $data]);
    }

    public function reserveMedicalPacks(string $product, string $requestKey, int $packCount, int $ttlSeconds): ?array
    {
        $this->assertMedicalProduct($product);

        if ($packCount < 1) {
            return null;
        }

        $data = self::normalizeMedicalData($this->data);
        $stocked = self::MEDICAL_STOCK_STOCKED;
        $reserved = self::MEDICAL_STOCK_RESERVED;
        $availablePacks = (int) ($data['stock'][$product][$stocked]['packs'] ?? 0);

        if ($availablePacks < $packCount) {
            return null;
        }

        $availableCost = (int) ($data['stock'][$product][$stocked]['cost'] ?? 0);
        $reservedCost = $packCount === $availablePacks
            ? $availableCost
            : (int) ceil($availableCost * ($packCount / $availablePacks));

        $data['stock'][$product][$stocked]['packs'] = $availablePacks - $packCount;
        $data['stock'][$product][$stocked]['cost'] = max(0, $availableCost - $reservedCost);
        $data['stock'][$product][$reserved][$requestKey] = [
            'packs' => $packCount,
            'cost' => $reservedCost,
            'sources' => [
                [
                    'packs' => $packCount,
                    'cost' => $reservedCost,
                ]
            ],
            'expires_at' => now()->addSeconds($ttlSeconds)->timestamp,
        ];

        $this->update(['data' => $data]);

        return [
            'cost' => $reservedCost,
            'packs' => $packCount,
        ];
    }

    public function consumeMedicalReservation(string $product, string $requestKey): bool
    {
        $this->assertMedicalProduct($product);

        $data = self::normalizeMedicalData($this->data);
        if (empty($data['stock'][$product][self::MEDICAL_STOCK_RESERVED][$requestKey])) {
            return false;
        }

        unset($data['stock'][$product][self::MEDICAL_STOCK_RESERVED][$requestKey]);
        $this->update(['data' => $data]);

        return true;
    }

    public function releaseMedicalReservation(string $product, string $requestKey): void
    {
        $this->assertMedicalProduct($product);

        $data = self::normalizeMedicalData($this->data);
        $reservation = $data['stock'][$product][self::MEDICAL_STOCK_RESERVED][$requestKey] ?? null;
        if (!$reservation) {
            return;
        }

        $sources = is_array($reservation['sources'] ?? null)
            ? $reservation['sources']
            : [
                [
                    'packs' => $reservation['packs'] ?? 1,
                    'cost' => $reservation['cost'] ?? 0,
                ]
            ];

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $data['stock'][$product][self::MEDICAL_STOCK_STOCKED]['packs'] += max(1, (int) ($source['packs'] ?? 1));
            $data['stock'][$product][self::MEDICAL_STOCK_STOCKED]['cost'] += max(0, (int) ($source['cost'] ?? 0));
        }

        unset($data['stock'][$product][self::MEDICAL_STOCK_RESERVED][$requestKey]);
        $this->update(['data' => $data]);
    }

    public function clearMedicalProductStock(string $product): array
    {
        $this->assertMedicalProduct($product);

        $data = self::normalizeMedicalData($this->data);
        $stocked = self::MEDICAL_STOCK_STOCKED;
        $reserved = self::MEDICAL_STOCK_RESERVED;
        $reservedRows = is_array($data['stock'][$product][$reserved] ?? null)
            ? $data['stock'][$product][$reserved]
            : [];
        $stockedPacks = (int) ($data['stock'][$product][$stocked]['packs'] ?? 0);
        $reservedPacks = collect($reservedRows)->sum(fn($row) => is_array($row) ? max(0, (int) ($row['packs'] ?? 0)) : 0);

        $data['stock'][$product][$stocked] = ['packs' => 0, 'cost' => 0];
        $data['stock'][$product][$reserved] = [];
        $this->update(['data' => $data]);

        return [
            'stocked_packs' => $stockedPacks,
            'reserved_packs' => $reservedPacks,
            'reserved_keys' => array_map('strval', array_keys($reservedRows)),
            'total_packs' => $stockedPacks + $reservedPacks,
        ];
    }

    public static function normalizeLaunderingData(?array $data): array
    {
        $data = $data ?? [];
        $data['offshore_balance'] = max(0, (int) ($data['offshore_balance'] ?? 0));
        $data['banker_percentage'] = min(
            self::MAX_MIRROR_BANKER_PERCENTAGE,
            max(
                self::MIN_MIRROR_BANKER_PERCENTAGE,
                (int) ($data['banker_percentage'] ?? self::DEFAULT_MIRROR_BANKER_PERCENTAGE)
            )
        );

        $requests = [];
        $rawRequests = is_array($data['mirror_requests'] ?? null) ? $data['mirror_requests'] : [];
        $now = now()->timestamp;

        foreach ($rawRequests as $key => $request) {
            if (!is_array($request)) {
                continue;
            }

            $amount = max(0, (int) ($request['amount'] ?? 0));
            $expiresAt = (int) ($request['expires_at'] ?? 0);
            if ($amount <= 0 || ($expiresAt > 0 && $expiresAt <= $now)) {
                continue;
            }

            $status = $request['status'] ?? self::MIRROR_STATUS_PENDING;

            $requests[(string) $key] = [
                'amount' => $amount,
                'cfo_id' => max(0, (int) ($request['cfo_id'] ?? 0)),
                'banker_id' => max(0, (int) ($request['banker_id'] ?? 0)),
                'status' => in_array($status, [self::MIRROR_STATUS_PENDING, self::MIRROR_STATUS_ACCEPTED], true)
                    ? $status
                    : self::MIRROR_STATUS_PENDING,
                'banker_percentage' => min(
                    self::MAX_MIRROR_BANKER_PERCENTAGE,
                    max(
                        self::MIN_MIRROR_BANKER_PERCENTAGE,
                        (int) ($request['banker_percentage'] ?? $data['banker_percentage'])
                    )
                ),
                'created_at' => (int) ($request['created_at'] ?? $now),
                'accepted_at' => (int) ($request['accepted_at'] ?? 0),
                'expires_at' => $expiresAt,
            ];
        }

        $data['mirror_requests'] = $requests;

        return $data;
    }

    public function offshoreBalance(): int
    {
        $data = self::normalizeLaunderingData($this->data);

        return (int) $data['offshore_balance'];
    }

    public function reservedMirrorBalance(): int
    {
        $data = self::normalizeLaunderingData($this->data);

        return collect($data['mirror_requests'])
            ->sum(fn(array $request) => max(0, (int) ($request['amount'] ?? 0)));
    }

    public function availableOffshoreBalance(): int
    {
        return max(0, $this->offshoreBalance() - $this->reservedMirrorBalance());
    }

    public function reserveMirrorTransaction(
        string $requestKey,
        int $amount,
        int $cfoId,
        int $bankerId,
        int $bankerPercentage,
        int $ttlSeconds = self::MIRROR_TRANSACTION_TTL_SECONDS
    ): ?array {
        if ($this->type !== self::TYPE_LAUNDERING || $amount <= 0 || $requestKey === '') {
            return null;
        }

        $data = self::normalizeLaunderingData($this->data);
        $reserved = collect($data['mirror_requests'])
            ->sum(fn(array $request) => max(0, (int) ($request['amount'] ?? 0)));
        $available = max(0, (int) $data['offshore_balance'] - $reserved);

        if ($available < $amount || !empty($data['mirror_requests'])) {
            return null;
        }

        $data['mirror_requests'][$requestKey] = [
            'amount' => $amount,
            'cfo_id' => $cfoId,
            'banker_id' => $bankerId,
            'status' => self::MIRROR_STATUS_PENDING,
            'banker_percentage' => min(
                self::MAX_MIRROR_BANKER_PERCENTAGE,
                max(self::MIN_MIRROR_BANKER_PERCENTAGE, $bankerPercentage)
            ),
            'created_at' => now()->timestamp,
            'accepted_at' => 0,
            'expires_at' => now()->addSeconds($ttlSeconds)->timestamp,
        ];

        $this->update(['data' => $data]);

        return $data['mirror_requests'][$requestKey];
    }

    public function releaseMirrorTransaction(string $requestKey): void
    {
        if ($this->type !== self::TYPE_LAUNDERING || $requestKey === '') {
            return;
        }

        $data = self::normalizeLaunderingData($this->data);
        if (!isset($data['mirror_requests'][$requestKey])) {
            return;
        }

        unset($data['mirror_requests'][$requestKey]);
        $this->update(['data' => $data]);
    }

    public function acceptMirrorTransaction(string $requestKey): ?array
    {
        if ($this->type !== self::TYPE_LAUNDERING || $requestKey === '') {
            return null;
        }

        $data = self::normalizeLaunderingData($this->data);
        $request = $data['mirror_requests'][$requestKey] ?? null;
        if (!$request || ($request['status'] ?? self::MIRROR_STATUS_PENDING) !== self::MIRROR_STATUS_PENDING) {
            return null;
        }

        $data['mirror_requests'][$requestKey]['status'] = self::MIRROR_STATUS_ACCEPTED;
        $data['mirror_requests'][$requestKey]['accepted_at'] = now()->timestamp;
        $this->update(['data' => $data]);

        return $data['mirror_requests'][$requestKey];
    }

    public function consumeMirrorTransaction(string $requestKey): ?array
    {
        if ($this->type !== self::TYPE_LAUNDERING || $requestKey === '') {
            return null;
        }

        $data = self::normalizeLaunderingData($this->data);
        $request = $data['mirror_requests'][$requestKey] ?? null;
        if (!$request || ($request['status'] ?? self::MIRROR_STATUS_PENDING) !== self::MIRROR_STATUS_ACCEPTED) {
            return null;
        }

        $amount = max(0, (int) ($request['amount'] ?? 0));
        if ($amount <= 0 || (int) $data['offshore_balance'] < $amount) {
            return null;
        }

        $data['offshore_balance'] -= $amount;
        unset($data['mirror_requests'][$requestKey]);
        $this->update(['data' => $data]);

        return $request;
    }

    public function addOffshoreBalance(int $amount): void
    {
        if ($this->type !== self::TYPE_LAUNDERING || $amount <= 0) {
            return;
        }

        $data = self::normalizeLaunderingData($this->data);
        $data['offshore_balance'] += $amount;
        $this->update(['data' => $data]);
    }

    public function updateMirrorBankerPercentage(int $percentage): void
    {
        if ($this->type !== self::TYPE_LAUNDERING) {
            return;
        }

        $data = self::normalizeLaunderingData($this->data);
        $data['banker_percentage'] = min(
            self::MAX_MIRROR_BANKER_PERCENTAGE,
            max(self::MIN_MIRROR_BANKER_PERCENTAGE, $percentage)
        );
        $this->update(['data' => $data]);
    }

    private function assertMedicalProduct(string $product): void
    {
        if ($this->type !== self::TYPE_MEDICAL || !isset(self::MEDICAL_PRODUCTS[$product])) {
            throw new RuntimeException('Choose a valid medical product.');
        }
    }

    public function publicData(array $medicineImages = []): array
    {
        if ($this->type === self::TYPE_LAUNDERING) {
            $data = self::normalizeLaunderingData($this->data);
            $requests = collect($data['mirror_requests'] ?? []);
            $bankers = Character::query()
                ->whereIn('id', $requests->pluck('banker_id')->filter()->unique()->all())
                ->get(['id', 'display_name'])
                ->keyBy('id');

            return [
                'offshore_balance' => (int) $data['offshore_balance'],
                'offshore_available' => max(
                    0,
                    (int) $data['offshore_balance'] - $requests->sum(fn(array $request) => max(0, (int) ($request['amount'] ?? 0)))
                ),
                'banker_percentage' => (int) $data['banker_percentage'],
                'mirror_request' => $requests
                    ->map(function (array $request, string $key) use ($bankers) {
                        $bankerId = (int) ($request['banker_id'] ?? 0);

                        return [
                            'request_key' => $key,
                            'amount' => (int) ($request['amount'] ?? 0),
                            'banker_id' => $bankerId,
                            'banker_name' => $bankers->get($bankerId)?->display_name ?? 'Unknown Banker',
                            'status' => (string) ($request['status'] ?? self::MIRROR_STATUS_PENDING),
                            'banker_percentage' => (int) ($request['banker_percentage'] ?? self::DEFAULT_MIRROR_BANKER_PERCENTAGE),
                        ];
                    })
                    ->first(),
            ];
        }

        if ($this->type !== self::TYPE_MEDICAL) {
            return $this->data ?? [];
        }

        $data = self::normalizeMedicalData($this->data);
        $images = $medicineImages ?: GameItem::query()
            ->whereIn('slug', array_keys(self::MEDICAL_PRODUCTS))
            ->pluck('image_url', 'slug')
            ->all();
        $stock = [];

        foreach (self::MEDICAL_PRODUCTS as $slug => $label) {
            $market = self::medicalProductMarket($slug);
            $reservedPacks = collect($data['stock'][$slug][self::MEDICAL_STOCK_RESERVED] ?? [])
                ->sum(fn($row) => is_array($row) ? max(0, (int) ($row['packs'] ?? 0)) : 0);
            $stock[$slug] = [
                'label' => $label,
                'imageUrl' => $images[$slug] ?? null,
                'stocked_packs' => (int) ($data['stock'][$slug][self::MEDICAL_STOCK_STOCKED]['packs'] ?? 0),
                'reserved_packs' => $reservedPacks,

                'black_market_min' => (int) ($market['npc_price_min'] ?? 0),
                'black_market_max' => (int) ($market['npc_price_max'] ?? 0),
            ];
        }

        return [
            'stock' => $stock,
            'medical_payout_per_pack' => $data['medical_payout_per_pack'],
            'pack_units' => self::MEDICAL_PACK_UNITS,
        ];
    }

    public static function HeadquartersBonus(Corporation $corp): int
    {
        if (($corp->hq_tier ?? 0) === 0) {
            return 0;
        }


        $hq = $corp->relationLoaded('properties')
            ? $corp->properties->where('type', self::TYPE_HQ)->firstWhere('condition', self::CONDITION_CONSTRUCTED)
            : $corp->properties()
                ->where('type', self::TYPE_HQ)
                ->where('condition', self::CONDITION_CONSTRUCTED)
                ->first();

        if (!$hq) {
            return 0;
        }

        return $hq->tier * 5;
    }

    public function isProtected(): bool
    {
        return $this->protection_until !== null
            && $this->protection_until->isFuture();
    }

    public function seizureHasExpired(): bool
    {
        return $this->condition === self::CONDITION_SEIZED
            && $this->seized_until !== null
            && $this->seized_until->isPast();
    }


    private static function ownedAttributesFromTemplate(self $template, bool $construct = true): array
    {
        return [
            'tier' => $template->tier,
            'name' => $template->name,
            'image_url' => $template->image_url,
            'price' => $template->price,
            'condition' => $construct ? self::CONDITION_CONSTRUCTED : self::CONDITION_PENDING,
            'seized_until' => null,
            'protection_until' => null,
            'last_upkeep_at' => now(),
            'data' => $template->data,
        ];
    }
}
