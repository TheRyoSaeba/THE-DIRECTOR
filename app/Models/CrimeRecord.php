<?php

namespace App\Models;

use App\Services\JournalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;


class CrimeRecord extends Model
{
    use HasFactory;


    public const TYPE_ASSAULT = 'assault';
    public const TYPE_MURDER = 'murder';
    public const TYPE_KIDNAPPING = 'kidnapping';
    public const TYPE_INVESTMENT_FRAUD = 'investment_fraud';
    public const TYPE_RUG_PULL = 'rug_pull';
    public const TYPE_MONEY_LAUNDERING = 'money_laundering';
    public const TYPE_TAX_EVASION = 'tax_evasion';
    public const TYPE_BOMBING = 'bombing';
    public const TYPE_ORGANIZED_HIT = 'organized_hit';
    public const TYPE_EXTORTION = 'extortion';


    public const SEV_MISDEMEANOR = 'misdemeanor';
    public const SEV_FELONY = 'felony';
    public const SEV_CAPITAL = 'capital';


    public const STATUS_OPEN = 'open';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_REFERRED = 'referred';
    public const STATUS_CHARGED = 'charged';
    public const STATUS_CONVICTED = 'convicted';
    public const STATUS_ACQUITTED = 'acquitted';
    public const STATUS_SENTENCED = 'sentenced';
    public const STATUS_APPEALED = 'appealed';
    public const STATUS_SUPPRESSED = 'suppressed';
    public const STATUS_CLOSED = 'closed';


    public const DEFAULT_EVIDENCE = [
        self::TYPE_INVESTMENT_FRAUD => 70,
        self::TYPE_MURDER => 60,
        self::TYPE_KIDNAPPING => 55,
        self::TYPE_BOMBING => 55,
        self::TYPE_ORGANIZED_HIT => 65,
        self::TYPE_ASSAULT => 40,
        self::TYPE_MONEY_LAUNDERING => 40,
        self::TYPE_TAX_EVASION => 60,
        self::TYPE_RUG_PULL => 25,
        self::TYPE_EXTORTION => 45,
    ];


    public const SENTENCE_BOUNDS = [
        self::SEV_MISDEMEANOR => ['min_fine' => 100, 'max_fine' => 999, 'min_jail' => 0, 'max_jail' => 1_000],
        self::SEV_FELONY => ['min_fine' => 1_000, 'max_fine' => 4_999, 'min_jail' => 0, 'max_jail' => 1_800],
        self::SEV_CAPITAL => ['min_fine' => 5_000, 'max_fine' => 9_999, 'min_jail' => 1_000, 'max_jail' => 3_600],
    ];


    public static function sentenceBounds(string $severity): array
    {
        return self::SENTENCE_BOUNDS[$severity] ?? ['min_fine' => 100, 'max_fine' => 100, 'min_jail' => 100, 'max_jail' => 100];
    }


    public const SEVERITY_MAP = [
        self::TYPE_INVESTMENT_FRAUD => self::SEV_FELONY,
        self::TYPE_KIDNAPPING => self::SEV_FELONY,
        self::TYPE_MURDER => self::SEV_CAPITAL,
        self::TYPE_ASSAULT => self::SEV_FELONY,
        self::TYPE_MONEY_LAUNDERING => self::SEV_FELONY,
        self::TYPE_TAX_EVASION => self::SEV_FELONY,
        self::TYPE_RUG_PULL => self::SEV_MISDEMEANOR,
        self::TYPE_BOMBING => self::SEV_FELONY,
        self::TYPE_ORGANIZED_HIT => self::SEV_CAPITAL,
        self::TYPE_EXTORTION => self::SEV_FELONY,
    ];


    public const TYPE_LABELS = [
        self::TYPE_ASSAULT => 'Grievous Bodily Harm',
        self::TYPE_MURDER => 'Murder',
        self::TYPE_KIDNAPPING => 'Kidnapping',
        self::TYPE_INVESTMENT_FRAUD => 'Investment Fraud',
        self::TYPE_RUG_PULL => 'Cryptocurrency Fraud',
        self::TYPE_MONEY_LAUNDERING => 'Money Laundering',
        self::TYPE_TAX_EVASION => 'Corporate Tax Evasion',
        self::TYPE_BOMBING => 'Property Bombing',
        self::TYPE_ORGANIZED_HIT => 'Organized Hit',
        self::TYPE_EXTORTION => 'Business Extortion',
    ];



    protected $fillable = [
        'character_id',
        'corporation_id',
        'city_id',
        'type',
        'severity',
        'evidence_level',
        'status',
        'detective_id',
        'prosecutor_id',
        'defense_id',
        'judge_id',
        'sentence',
        'data',
        'committed_at',
        'referred_at',
        'charged_at',
        'resolved_at',
        'sentenced_at',
        'appealed_at',
    ];

    protected $casts = [
        'evidence_level' => 'integer',
        'sentence' => 'array',
        'data' => 'array',
        'committed_at' => 'datetime',
        'referred_at' => 'datetime',
        'charged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'sentenced_at' => 'datetime',
        'appealed_at' => 'datetime',
    ];



    public function perpetrator(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'character_id');
    }

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function detective(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'detective_id');
    }

    public function prosecutor(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'prosecutor_id');
    }

    public function defense(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'defense_id');
    }

    public function judge(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'judge_id');
    }

    public function defenseOffers(): HasMany
    {
        return $this->hasMany(DefenseOffer::class);
    }



    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_OPEN);
    }

    public function scopeInvestigating(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_INVESTIGATING);
    }

    public function scopeReferred(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_REFERRED);
    }

    public function scopeCharged(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_CHARGED);
    }

    public function scopeConvicted(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_CONVICTED);
    }

    public function scopeSentenced(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_SENTENCED);
    }

    public function scopeAppealed(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_APPEALED);
    }


    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', [
            self::STATUS_OPEN,
            self::STATUS_INVESTIGATING,
            self::STATUS_REFERRED,
            self::STATUS_CHARGED,
            self::STATUS_CONVICTED,
            self::STATUS_APPEALED,
        ]);
    }


    public function scopeResolved(Builder $q): Builder
    {
        return $q->whereIn('status', [
            self::STATUS_SENTENCED,
            self::STATUS_ACQUITTED,
            self::STATUS_SUPPRESSED,
            self::STATUS_CLOSED,
        ]);
    }

    public function scopeInCity(Builder $q, int $cityId): Builder
    {
        return $q->where('city_id', $cityId);
    }

    public function scopeOfSeverity(Builder $q, string $severity): Builder
    {
        return $q->where('severity', $severity);
    }

    public function scopeOfType(Builder $q, string $type): Builder
    {
        return $q->where('type', $type);
    }




    public static function convictionCount(int $characterId, ?string $type = null): int
    {
        $q = static::whereIn('status', [self::STATUS_SENTENCED, self::STATUS_APPEALED, self::STATUS_CONVICTED])
            ->where(function (Builder $query) use ($characterId) {
                $query->where('character_id', $characterId)
                    ->orWhereJsonContains('data->participants', $characterId);
            });

        if ($type) {
            $q->ofType($type);
        }

        return $q->count();
    }

    public static function pardonConvictionsFor(int $characterId): int
    {
        $records = static::whereIn('status', [self::STATUS_SENTENCED, self::STATUS_APPEALED, self::STATUS_CONVICTED])
            ->where(function (Builder $query) use ($characterId) {
                $query->where('character_id', $characterId)
                    ->orWhereJsonContains('data->participants', $characterId);
            })
            ->lockForUpdate()
            ->get();

        $cleared = 0;

        foreach ($records as $record) {
            $data = $record->data ?? [];
            $currentParticipants = $data['participants'] ?? [];
            $participants = array_values(array_filter(
                $currentParticipants,
                fn($participant) => (int) $participant !== $characterId
            ));

            if ((int) $record->character_id === $characterId) {
                if (empty($participants)) {
                    $record->delete();
                } else {
                    $data['participants'] = $participants;
                    $record->character_id = null;
                    $record->data = $data;
                    $record->save();
                }

                $cleared++;
                continue;
            }

            if ($participants !== $currentParticipants) {
                $data['participants'] = $participants;
                if (!$record->character_id && empty($participants)) {
                    $record->delete();
                } else {
                    $record->data = $data;
                    $record->save();
                }
                $cleared++;
            }
        }

        return $cleared;
    }




    public function isConflicted(Character $character): bool
    {
        if ($this->character_id && $this->character_id === $character->id) {
            return true;
        }

        $data = $this->data ?? [];

        if (isset($data['victim_id']) && (int) $data['victim_id'] === $character->id) {
            return true;
        }

        if (in_array($character->id, $data['participants'] ?? [], true)) {
            return true;
        }

        return false;
    }


    public function isAccessibleAtPoliceRank(int $rank): bool
    {
        return match ($this->severity) {
            self::SEV_MISDEMEANOR => $rank >= 1,
            self::SEV_FELONY => $rank >= 2,
            self::SEV_CAPITAL => $rank >= 3,
            default => false,
        };
    }


    public function isAccessibleAtRank(int $rank): bool
    {
        return $this->isAccessibleAtPoliceRank($rank);
    }


    public function isAccessibleAtLawRank(int $rank): bool
    {
        return match ($this->severity) {
            self::SEV_MISDEMEANOR => $rank >= 2,
            self::SEV_FELONY => $rank >= 2,
            self::SEV_CAPITAL => $rank >= 2,
            default => false,
        };
    }




    public function assignDetective(Character $officer): bool
    {
        if ($this->detective_id && $this->detective_id !== $officer->id) {
            return false;
        }

        $this->detective_id = $officer->id;

        if ($this->status === self::STATUS_OPEN) {
            $this->status = self::STATUS_INVESTIGATING;
        }

        $this->save();
        return true;
    }


    public function addEvidence(int $amount, ?string $note = null): int
    {
        $this->evidence_level = min(100, $this->evidence_level + $amount);

        if ($this->status === self::STATUS_OPEN) {
            $this->status = self::STATUS_INVESTIGATING;
        }

        if ($note !== null) {
            $data = $this->data ?? [];
            $data['investigation_notes'][] = [
                'note' => $note,
                'at' => now()->utc()->format('d/m/Y H:i:s') . ' UTC',
            ];
            $this->data = $data;
        }

        $this->save();
        return $this->evidence_level;
    }


    public function removeEvidence(int $amount): int
    {
        $this->evidence_level = max(0, $this->evidence_level - $amount);
        $this->save();
        return $this->evidence_level;
    }


    public function refer(Character $detective, array $suspectNames): bool
    {
        if (!in_array($this->status, [self::STATUS_OPEN, self::STATUS_INVESTIGATING])) {
            return false;
        }
        if ($this->isConflicted($detective)) {
            return false;
        }
        if ($this->detective_id && $this->detective_id !== $detective->id) {
            return false;
        }

        $this->detective_id = $detective->id;
        $this->status = self::STATUS_REFERRED;
        $this->referred_at = now()->utc();
        $this->data = array_merge($this->data ?? [], ['suspect_names' => $suspectNames]);

        $this->save();
        return true;
    }


    public function fileCharges(Character $prosecutor): bool
    {
        if ($this->status !== self::STATUS_REFERRED) {
            return false;
        }
        if (in_array($prosecutor->id, $this->partyIds(), true)) {
            return false;
        }
        if (!$this->prepareChargeTargets()) {
            return false;
        }
        if ($this->isConflicted($prosecutor)) {
            return false;
        }
        if ($this->detective_id && $this->detective_id === $prosecutor->id) {
            return false;
        }

        if ($this->defense_id && $this->defense_id === $prosecutor->id) {
            return false;
        }

        if ($this->severity === self::SEV_CAPITAL && $prosecutor->career_rank < 2) {
            return false;
        }

        $this->prosecutor_id = $prosecutor->id;
        $this->status = self::STATUS_CHARGED;
        $this->charged_at = now()->utc();
        $this->save();

        $this->notifyCharges($prosecutor);
        return true;
    }

    public function prepareChargeTargets(): bool
    {
        $data = $this->data ?? [];
        $suspectIds = array_values(array_unique(array_filter(
            array_map('intval', $data['suspect_ids'] ?? [])
        )));

        if (!empty($suspectIds)) {
            $existing = Character::withTrashed()
                ->whereIn('id', $suspectIds)
                ->whereNotNull('user_id')
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->all();
            $existing = array_flip($existing);

            $resolved = array_values(array_filter($suspectIds, fn($id) => isset($existing[$id])));

            if (count($resolved) !== count($suspectIds)) {
                return false;
            }
            $suspectIds = $resolved;
        } else {
            $suspectNames = array_values(array_unique(array_filter(array_map(
                fn($name) => strtolower(trim((string) $name)),
                $data['suspect_names'] ?? []
            ))));

            if (empty($suspectNames)) {
                $suspectIds = $this->partyIds();
            } else {
                $characters = Character::withTrashed()
                    ->select('id', 'display_name')
                    ->whereNotNull('user_id')
                    ->whereIn(DB::raw('LOWER(display_name)'), $suspectNames)
                    ->get()
                    ->keyBy(fn(Character $character) => strtolower($character->display_name));

                $suspectIds = [];
                foreach ($suspectNames as $name) {
                    $character = $characters->get($name);
                    if (!$character) {
                        return false;
                    }
                    $suspectIds[] = (int) $character->id;
                }
                $suspectIds = array_values(array_unique($suspectIds));
            }
        }

        if (empty($suspectIds)) {
            return false;
        }

        if (!array_key_exists('actual_character_id', $data)) {
            $data['actual_character_id'] = $this->character_id;
        }
        if (!array_key_exists('actual_participants', $data)) {
            $data['actual_participants'] = array_values(array_map('intval', $data['participants'] ?? []));
        }

        $this->character_id = $suspectIds[0];
        $data['participants'] = array_slice($suspectIds, 1);
        $data['charged_suspect_ids'] = $suspectIds;
        $this->data = $data;

        return true;
    }


    public function assignDefense(Character $defender): bool
    {
        if ($this->status !== self::STATUS_CHARGED) {
            return false;
        }
        if ($this->defense_id !== null) {
            return false;
        }
        if ($this->isConflicted($defender)) {
            return false;
        }
        if ($this->prosecutor_id === $defender->id) {
            return false;
        }

        $this->defense_id = $defender->id;
        $this->save();
        return true;
    }


    public function resolveFromDefense(Character $defender, bool $defenseWins): bool
    {
        if ($this->status !== self::STATUS_CHARGED) {
            return false;
        }
        if ($this->defense_id !== $defender->id) {
            return false;
        }

        $this->status = $defenseWins ? self::STATUS_ACQUITTED : self::STATUS_CONVICTED;
        $this->resolved_at = now()->utc();
        $this->save();
        return true;
    }


    public function convict(Character $judge): bool
    {
        if ($this->status !== self::STATUS_CHARGED) {
            return false;
        }
        if ($judge->career_rank < 3 ) {
            return false;
        }
        if ($this->isConflicted($judge)) {
            return false;
        }

        $this->judge_id = $judge->id;
        $this->status = self::STATUS_CONVICTED;

        $this->save();
        return true;
    }


    public function acquit(Character $judge): bool
    {
        if (!in_array($this->status, [self::STATUS_CHARGED, self::STATUS_CONVICTED])) {
            return false;
        }
        if ($judge->career_rank < 3) {
            return false;
        }
        if ($this->isConflicted($judge)) {
            return false;
        }

        $this->judge_id = $judge->id;
        $this->status = self::STATUS_ACQUITTED;
        $this->resolved_at = now()->utc();
        $this->save();
        return true;
    }


    public function applySentence(Character $judge, array $sentencePayload): bool
    {
        if ($this->status !== self::STATUS_CONVICTED) {
            return false;
        }
        if ($judge->career_rank < 3) {
            return false;
        }
        if ($this->isConflicted($judge)) {
            return false;
        }

        if ($this->judge_id && $this->judge_id !== $judge->id) {
            return false;
        }

        $this->judge_id = $judge->id;
        $this->sentence = $sentencePayload;
        $this->status = self::STATUS_SENTENCED;
        $this->sentenced_at = now()->utc();
        $this->save();
        return true;
    }




    public function appeal(): bool
    {
        if ($this->status !== self::STATUS_SENTENCED) {
            return false;
        }
        if ($this->severity === self::SEV_MISDEMEANOR) {
            return false;
        }

        $this->status = self::STATUS_APPEALED;
        $this->appealed_at = now()->utc();
        $this->save();
        return true;
    }


    public function resolveAppeal(Character $chiefJustice, bool $upheld): bool
    {
        if ($this->status !== self::STATUS_APPEALED) {
            return false;
        }
        if ($chiefJustice->career_rank < 4) {
            return false;
        }
        if ($this->isConflicted($chiefJustice)) {
            return false;
        }

        if ($this->judge_id && $this->judge_id === $chiefJustice->id) {
            return false;
        }

        $data = $this->data ?? [];
        $data['appeal'] = [
            'chief_justice_id' => $chiefJustice->id,
            'chief_justice_name' => $chiefJustice->display_name,
            'upheld' => $upheld,
            'resolved_at' => now()->utc()->toIso8601String(),
        ];
        $this->data = $data;
        $this->status = $upheld ? self::STATUS_SENTENCED : self::STATUS_ACQUITTED;
        $this->save();
        return true;
    }


    public function validateAppealAccuracy(Character $chiefJustice, bool $upheld): bool
    {
        $wasGuilty = $this->chargeIsValid();

        if ($wasGuilty === null) {
            $suspectNames = array_map('strtolower', $this->data['suspect_names'] ?? []);
            $perpetratorName = strtolower($this->data['perpetrator_name'] ?? '');

            if (empty($suspectNames) || empty($perpetratorName)) {
                return false;
            }

            $wasGuilty = in_array($perpetratorName, $suspectNames, true);
        }

        $chiefJusticeCorrect = ($wasGuilty === $upheld);

        $originalChainIds = array_values(array_filter([
            $this->detective_id,
            $this->prosecutor_id,
            $this->judge_id,
        ]));

        if ($chiefJusticeCorrect) {
            $chiefJustice->loadMissing(['stats']);
            $chiefJustice->stats?->setRelation('character', $chiefJustice);

            $chiefJustice->increment('cash_on_hand', 3000);

            $chiefJustice->addXp(250);

            if (!empty($originalChainIds)) {
                $chainWasWrong = !$wasGuilty && !$upheld;

                if ($chainWasWrong) {


                    $chiefJustice->stats?->addIntelligence(200, true);

                    $chiefJustice->stats?->addDefense(100, true);

                    $chiefJustice->stats?->addInfluence(0.05, true);

                    $chiefJustice->addXp(250);

                    $chiefJustice->increment('cash_on_hand', 3000);
                    //! make sure this is safe 
                    DB::table('characters')
                        ->whereIn('id', $originalChainIds)
                        ->update(['career_xp' => DB::raw('GREATEST(career_xp - 500, 0)')]);

                    Character::with('stats')
                        ->whereIn('id', $originalChainIds)
                        ->get()
                        ->each(function (Character $c) {
                            $c->stats?->setRelation('character', $c);
                            $c->stats?->removeInfluence(0.03);
                        });
                }


                foreach ($originalChainIds as $participantId) {

                    $chiefJusticeName = $chiefJustice->display_name;
                    JournalService::custom($participantId, 'case_appeal_resolved', [
                        'case_id' => $this->id,
                        'message' => "The Chief Justice {$chiefJusticeName} has reviewed the appeal for {$this->typeLabel()} "
                            . 'and issued a final ruling. Your involvement in this case has concluded.',
                    ]);
                }
            }
        } else {


            DB::table('characters')
                ->where('id', $chiefJustice->id)
                ->update([
                    'career_xp' => DB::raw('GREATEST(career_xp - 1600, 0)'),
                    'total_character_exp' => DB::raw('GREATEST(total_character_exp - 1600, 0)'),
                ]);

            $chiefJustice->loadMissing(['stats']);
            $chiefJustice->stats?->setRelation('character', $chiefJustice);
            $chiefJustice->stats?->removeInfluence(0.05);
            $chiefJustice->increment('cash_on_hand', 750);
        }

        return $chiefJusticeCorrect;
    }




    public function clearDefensePending(?int $attorneyId = null): void
    {
        $offerQuery = DefenseOffer::where('crime_record_id', $this->id)->active();
        if ($attorneyId !== null) {
            $offerQuery->where('attorney_id', $attorneyId);
        }

        $cancelled = $offerQuery->update(['status' => DefenseOffer::STATUS_CANCELLED]);

        if ($attorneyId !== null && $this->defense_id !== $attorneyId && $cancelled === 0) {
            return;
        }

        $data = $this->data ?? [];
        unset($data['defense_pending']);
        unset($data['defense_pending_since']);

        $this->data = $data;
        if ($attorneyId === null || $this->defense_id === $attorneyId) {
            $this->defense_id = null;
        }
        $this->save();
    }




    public function reject(): bool
    {
        if ($this->status !== self::STATUS_CHARGED) {
            return false;
        }

        $this->status = self::STATUS_CLOSED;
        $this->resolved_at = now()->utc();
        $this->save();
        return true;
    }


    public function suppress(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        $this->status = self::STATUS_SUPPRESSED;
        $this->resolved_at = now()->utc();
        $this->save();
        return true;
    }


    public function close(): void
    {
        $this->status = self::STATUS_CLOSED;
        $this->resolved_at = now()->utc();
        $this->save();
    }




    public function validateSuspectAccuracy(?array $participantIds = null): void
    {
        $isGuiltyInSuspects = $this->chargeIsValid();

        if ($isGuiltyInSuspects === null) {
            $suspectNames = array_map('strtolower', $this->data['suspect_names'] ?? []);
            $perpetratorName = strtolower($this->data['perpetrator_name'] ?? '');

            if (empty($suspectNames) || empty($perpetratorName)) {
                return;
            }

            $isGuiltyInSuspects = in_array($perpetratorName, $suspectNames, true);
        }

        $wasConvicted = in_array($this->status, [self::STATUS_SENTENCED, self::STATUS_APPEALED, self::STATUS_CONVICTED]);
        $wasAcquitted = $this->status === self::STATUS_ACQUITTED;

        // Legal outcome is correct if:
        // 1. A guilty suspect was convicted
        // 2. An innocent suspect was acquitted
        $outcomeIsCorrect = ($isGuiltyInSuspects && $wasConvicted) || (!$isGuiltyInSuspects && $wasAcquitted);

        $participantIds ??= array_values(array_filter([
            $this->detective_id,
            $this->prosecutor_id,
            $this->judge_id,
        ]));

        if (empty($participantIds)) {
            return;
        }

        $participants = Character::with('stats')
            ->whereIn('id', $participantIds)
            ->get()
            ->each(fn(Character $c) => $c->stats?->setRelation('character', $c));

        $factor = max(0.3, $this->evidence_level / 100);

        if ($outcomeIsCorrect) {






            [$minXp, $maxXp, $baseCashMin, $baseCashMax, $minStat, $maxStat] = match ($this->severity) {
                self::SEV_CAPITAL => [200, 400, 6_000, 10_000, 10, 20],
                self::SEV_FELONY => [100, 250, 4_000, 6_000, 5, 10],
                default => [50, 150, 2_000, 4_000, 2, 5],
            };




            $roleCashMultiplier = function (int $characterId): float {
                if ($characterId === $this->judge_id)
                    return 1.5;
                if ($characterId === $this->prosecutor_id)
                    return 1.2;
                return 1.0;
            };


            foreach ($participants as $participant) {
                $multiplier = $roleCashMultiplier($participant->id);
                $cashMin = (int) round($baseCashMin * $multiplier);
                $cashMax = (int) round($baseCashMax * $multiplier);

                $xp = (int) round(mt_rand($minXp, $maxXp) * $factor);
                $cash = (int) round(mt_rand($cashMin, $cashMax) * $factor);
                $stat = mt_rand($minStat, $maxStat);

                $participant->addXp($xp);
                $participant->stats?->addIntelligence($stat, true);
                $participant->increment('cash_on_hand', $cash);
                \App\Models\CharacterHistory::addHistory($participant, 'earned_career', $cash);


                JournalService::custom($participant->id, 'case_rewarded', [
                    'case_id' => $this->id,
                    'message' => 'Your work on the ' . $this->typeLabel()
                        . ' case has been completed and your compensation totaled $'
                        . number_format($cash, 2) . '.',
                ]);
            }

            if ($this->city_id) {
                City::decreaseCrimeRateById($this->city_id, 1.0);
            }
        } else {


            [$minCash, $maxCash] = match ($this->severity) {
                self::SEV_CAPITAL => [3_000, 7_000],
                self::SEV_FELONY => [1_000, 3_000],
                default => [500, 1_000],
            };


            foreach ($participants as $participant) {
                $cash = (int) round(mt_rand($minCash, $maxCash) * $factor);
                $participant->increment('cash_on_hand', $cash);
                \App\Models\CharacterHistory::addHistory($participant, 'earned_career', $cash);


                $participant->stats?->removeInfluence(0.02);


                JournalService::custom($participant->id, 'case_rewarded', [
                    'case_id' => $this->id,
                    'message' => 'Your work on the ' . $this->typeLabel()
                        . ' case has been completed and your compensation totaled $'
                        . number_format($cash, 2) . '.',
                ]);
            }


            DB::table('characters')
                ->whereIn('id', $participantIds)
                ->update(['career_xp' => DB::raw('GREATEST(career_xp - 500, 0)')]);
        }
    }

    private function chargeIsValid(): ?bool
    {
        $charged = $this->partyIds();
        $actual = $this->actualIds();

        if (empty($charged) || empty($actual)) {
            return null;
        }

        $actual = array_flip($actual);
        foreach ($charged as $id) {
            if (!isset($actual[$id])) {
                return false;
            }
        }

        return true;
    }

    public function partyIds(): array
    {
        $data = $this->data ?? [];
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $data['charged_suspect_ids'] ?? [])
        )));

        if (!empty($ids)) {
            return $ids;
        }

        return array_values(array_unique(array_filter([
            $this->character_id ? (int) $this->character_id : null,
            ...array_map('intval', $data['participants'] ?? []),
        ])));
    }

    private function actualIds(): array
    {
        $data = $this->data ?? [];
        $ids = [];

        if (!empty($data['actual_character_id'] ?? null)) {
            $ids[] = (int) $data['actual_character_id'];
        }

        if (array_key_exists('actual_participants', $data)) {
            $ids = array_merge($ids, array_map('intval', $data['actual_participants'] ?? []));
        }

        if (!empty($ids)) {
            return array_values(array_unique(array_filter($ids)));
        }

        return array_values(array_unique(array_filter([
            $this->character_id ? (int) $this->character_id : null,
            ...array_map('intval', $data['participants'] ?? []),
        ])));
    }

    public function isActive(): bool
    {
        return !in_array($this->status, [
            self::STATUS_SENTENCED,
            self::STATUS_ACQUITTED,
            self::STATUS_SUPPRESSED,
            self::STATUS_CLOSED,
        ]);
    }

    public function isResolved(): bool
    {
        return !$this->isActive();
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? ucwords(str_replace('_', ' ', $this->type));
    }




    private function notifyCharges(Character $prosecutor): void
    {
        if (!$this->character_id) {
            return;
        }
        $rankName = $prosecutor->current_rank?->rank_name ?? 'Attorney';
        $crimeLabel = $this->typeLabel();
        $victimName = $this->data['victim_name'] ?? 'an individual';
        $committedAt = $this->committed_at->utc()->format('d/m/y H:i:s') . ' UTC';
        $cityName = $this->city?->name ?? 'The city';

        $toNotify = $this->partyIds();
        $leadId = (int) ($toNotify[0] ?? $this->character_id);

        foreach ($toNotify as $recipientId) {
            $isLead = ((int) $recipientId) === $leadId;
            $subject = $isLead
                ? "{$rankName} {$prosecutor->display_name} of {$cityName} has formally filed charges against you "
                . "for {$crimeLabel} committed on {$committedAt}. "
                . 'You may contact  a defence attorney to represent your case.'
                : "{$rankName} {$prosecutor->display_name} of {$cityName} has filed charges related to {$crimeLabel} "
                . "committed on {$committedAt}. You are named as a co-conspirator. "
                . 'The lead defendant may retain a defence attorney on behalf of all parties.';

            JournalService::custom((int) $recipientId, 'criminal_charges_filed', [
                'crime_record_id' => $this->id,
                'prosecutor_name' => $prosecutor->display_name,
                'prosecutor_rank' => $rankName,
                'crime_type' => $this->type,
                'crime_label' => $crimeLabel,
                'victim_name' => $victimName,
                'committed_at_utc' => $committedAt,
                'severity' => $this->severity,
                'is_lead' => $isLead,
                'message' => $subject,
            ]);
        }
    }


    public function applySentenceToCharacter(Character $character): void
    {
        $fine = (int) ($this->sentence['fine'] ?? 0);
        $jailSeconds = (int) ($this->sentence['jail_seconds'] ?? 0);

        if ($fine > 0) {
            $fromHand = min($fine, (int) $character->cash_on_hand);
            $fromBank = min($fine - $fromHand, (int) $character->cash_in_bank);

            $updates = [];
            if ($fromHand > 0)
                $updates['cash_on_hand'] = $character->cash_on_hand - $fromHand;
            if ($fromBank > 0)
                $updates['cash_in_bank'] = $character->cash_in_bank - $fromBank;
            if (!empty($updates)) {
                $character->update($updates);
            }




            $collected = $fromHand + $fromBank;
            if ($collected > 0 && $this->city_id) {
                $cityRevenue = (int) floor($collected * 0.60);
                if ($cityRevenue > 0) {
                    $term = \App\Models\MayorTerm::activeForCity($this->city_id);
                    $term?->addFunds($cityRevenue, 'fine');
                }
            }
        }

        if ($jailSeconds > 0) {
            $character->jail($jailSeconds, $this->typeLabel());
        }
    }

}
