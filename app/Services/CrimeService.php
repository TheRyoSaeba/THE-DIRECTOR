<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Corporation;
use App\Models\CrimeRecord;


class CrimeService
{

    public static function kidnapping(
        Character $initiator,
        array $participantIds,
        Character $victim,
        int $cityId,
        int $amount
    ): CrimeRecord {
        return self::write([
            'character_id' => $initiator->id,
            'city_id' => $cityId,
            'type' => CrimeRecord::TYPE_KIDNAPPING,
            'severity' => CrimeRecord::SEV_FELONY,
            'evidence_level' => CrimeRecord::DEFAULT_EVIDENCE[CrimeRecord::TYPE_KIDNAPPING],
            'data' => [
                'perpetrator_name' => $initiator->display_name,
                'participants' => $participantIds,
                'victim_id' => $victim->id,
                'victim_name' => $victim->display_name,
                'amount' => $amount,
            ],
        ]);
    }


    public static function rugPull(
        Character $perpetrator,
        Character $victim,
        int $cityId,
        int $amount
    ): CrimeRecord {
        return self::write([
            'character_id' => $perpetrator->id,
            'city_id' => $cityId,
            'type' => CrimeRecord::TYPE_RUG_PULL,
            'severity' => CrimeRecord::SEV_MISDEMEANOR,
            'evidence_level' => CrimeRecord::DEFAULT_EVIDENCE[CrimeRecord::TYPE_RUG_PULL],
            'data' => [
                'perpetrator_name' => $perpetrator->display_name,
                'victim_id' => $victim->id,
                'victim_name' => $victim->display_name,
                'amount' => $amount,
            ],
        ]);
    }


    public static function investmentFraud(
        Character $ceo,
        Corporation $corp,
        array $participantIds,
        int $cityId,
        int $amountStolen
    ): CrimeRecord {
        return self::write([
            'character_id' => $ceo->id,
            'corporation_id' => $corp->id,
            'city_id' => $cityId,
            'type' => CrimeRecord::TYPE_INVESTMENT_FRAUD,
            'severity' => CrimeRecord::SEV_FELONY,
            'evidence_level' => CrimeRecord::DEFAULT_EVIDENCE[CrimeRecord::TYPE_INVESTMENT_FRAUD],
            'data' => [
                'perpetrator_name' => $ceo->display_name,
                'corporation_name' => $corp->name,
                'participants' => $participantIds,
                'victim_name' => 'bank depositors',
                'amount' => $amountStolen,
            ],
        ]);
    }


    public static function assault(
        Character $attacker,
        Character $victim,
        int $cityId,
        string $severity = CrimeRecord::SEV_FELONY
    ): CrimeRecord {
        return self::write([
            'character_id' => $attacker->id,
            'city_id' => $cityId,
            'type' => CrimeRecord::TYPE_ASSAULT,
            'severity' => $severity,
            'evidence_level' => CrimeRecord::DEFAULT_EVIDENCE[CrimeRecord::TYPE_ASSAULT],
            'data' => [
                'perpetrator_name' => $attacker->display_name,
                'victim_id' => $victim->id,
                'victim_name' => $victim->display_name,
            ],
        ]);
    }


    public static function murder(
        Character $killer,
        Character $victim,
        int $cityId
    ): CrimeRecord {
        return self::write([
            'character_id' => $killer->id,
            'city_id' => $cityId,
            'type' => CrimeRecord::TYPE_MURDER,
            'severity' => CrimeRecord::SEV_CAPITAL,
            'evidence_level' => CrimeRecord::DEFAULT_EVIDENCE[CrimeRecord::TYPE_MURDER],
            'data' => [
                'perpetrator_name' => $killer->display_name,
                'victim_id' => $victim->id,
                'victim_name' => $victim->display_name,
            ],
        ]);
    }



    public static function moneyLaunderingBanker(
        Character $perpetrator,
        int $cityId,
        int $amount,
        Character $client,
    ): CrimeRecord {
        $evidence = max(1, CrimeRecord::DEFAULT_EVIDENCE[CrimeRecord::TYPE_MONEY_LAUNDERING] - 30);

        return self::write([
            'character_id' => $perpetrator->id,
            'city_id' => $cityId,
            'type' => CrimeRecord::TYPE_MONEY_LAUNDERING,
            'severity' => CrimeRecord::SEV_FELONY,
            'evidence_level' => $evidence,
            'data' => [
                'perpetrator_name' => $perpetrator->display_name,
                'participants' => [$client->id],
                'victim_name' => 'The Public',
                'amount' => $amount,
            ],
        ]);
    }

    public static function moneyLaundering(
        Character $perpetrator,
        int $cityId,
        int $amount,
        string $businessName,
        bool $failed,
    ): CrimeRecord {
        return self::write([
            'character_id' => $perpetrator->id,
            'city_id' => $cityId,
            'type' => CrimeRecord::TYPE_MONEY_LAUNDERING,
            'severity' => CrimeRecord::SEV_FELONY,
            'evidence_level' => CrimeRecord::DEFAULT_EVIDENCE[CrimeRecord::TYPE_MONEY_LAUNDERING],
            'data' => [
                'perpetrator_name' => $perpetrator->display_name,
                'business_name' => $businessName,
                'victim_name' => 'The Public',
                'amount' => $amount,
                'failed' => $failed,
            ],
        ]);
    }


    public static function bombing(
        Character $attacker,
        Character $target,
        int $cityId,
        string $severity = CrimeRecord::SEV_FELONY
    ): CrimeRecord {
        return self::write([
            'character_id' => $attacker->id,
            'city_id' => $cityId,
            'type' => CrimeRecord::TYPE_BOMBING,
            'severity' => $severity,
            'evidence_level' => CrimeRecord::DEFAULT_EVIDENCE[CrimeRecord::TYPE_BOMBING],
            'data' => [
                'perpetrator_name' => $attacker->display_name,
                'victim_id' => $target->id,
                'victim_name' => $target->display_name,
            ],
        ]);
    }


    public static function organizedHit(
        Character $initiator,
        array $participantIds,
        Character $victim,
        int $cityId
    ): CrimeRecord {
        return self::write([
            'character_id' => $initiator->id,
            'city_id' => $cityId,
            'type' => CrimeRecord::TYPE_ORGANIZED_HIT,
            'severity' => CrimeRecord::SEV_CAPITAL,
            'evidence_level' => CrimeRecord::DEFAULT_EVIDENCE[CrimeRecord::TYPE_ORGANIZED_HIT],
            'data' => [
                'perpetrator_name' => $initiator->display_name,
                'participants' => $participantIds,
                'victim_id' => $victim->id,
                'victim_name' => $victim->display_name,
            ],
        ]);
    }


    public static function taxEvasion(
        ?Character $perpetrator,
        Corporation $corp,
        int $cityId,
        string $cityName,
        bool $success,
        int $slushFundAtAudit,
        string $auditOrderNote,
    ): CrimeRecord {
        $baseEvidence = CrimeRecord::DEFAULT_EVIDENCE[CrimeRecord::TYPE_TAX_EVASION];

        return self::write([
            'character_id' => $perpetrator?->id,
            'corporation_id' => $corp->id,
            'city_id' => $cityId,
            'type' => CrimeRecord::TYPE_TAX_EVASION,
            'severity' => CrimeRecord::SEV_FELONY,
            'evidence_level' => $success ? $baseEvidence : (int) floor($baseEvidence * 0.5),
            'data' => [
                'perpetrator_name' => $perpetrator?->display_name ?? 'Unknown',
                'victim_name' => 'City of ' . $cityName,
                'corporation_name' => $corp->name,
                'slush_fund_at_audit' => $slushFundAtAudit,
                'investigation_notes' => [
                    [
                        'note' => $auditOrderNote,
                        'at' => now()->utc()->format('d/m/y H:i:s') . ' UTC',
                    ]
                ],
            ],
        ]);
    }


    public static function extortion(
        Character $perpetrator,
        int $cityId,
        int $amount,
        string $businessName
    ): CrimeRecord {
        return self::write([
            'character_id' => $perpetrator->id,
            'city_id' => $cityId,
            'type' => CrimeRecord::TYPE_EXTORTION,
            'severity' => CrimeRecord::SEV_FELONY,
            'evidence_level' => CrimeRecord::DEFAULT_EVIDENCE[CrimeRecord::TYPE_EXTORTION],
            'data' => [
                'perpetrator_name' => $perpetrator->display_name,
                'victim_name' => $businessName,
                'amount' => $amount,
            ],
        ]);
    }

    private static function write(array $attributes): CrimeRecord
    {
        $record = CrimeRecord::create(array_merge([
            'status' => CrimeRecord::STATUS_OPEN,
            'committed_at' => now()->utc(),
            'data' => [],
        ], $attributes));

        self::pushDispatch($record);

        return $record;
    }

    /**
     * 911-style dispatch chatter. One Redis list per city, pruned on every
     * write so entries older than 15 minutes never resurface. The list
     * powers the Police Crime Log tab — deliberately incomplete info: a
     * 50% gate decides whether *any* name leaks, and when there are
     * participants another 50/50 picks between the lead and a random one.
     * First two words only.
     *
     * Cache failures are swallowed: a Redis outage must never stop a crime
     * from being recorded.
     */
    private static function pushDispatch(CrimeRecord $record): void
    {
        try {
            $cityId = (int) $record->city_id;
            if ($cityId <= 0) {
                return;
            }

            $entry = [
                'at' => now()->utc()->toIso8601String(),
                'type' => $record->type,
                'severity' => $record->severity,
            ];

            // 33% gate on whether any name leaks at all.
            if (random_int(0, 3) === 1) {
                $data = $record->data ?? [];
                $participants = $data['participants'] ?? [];
                $name = null;

                // If participants exist, another 50/50: leak the lead or one of them.
                if (is_array($participants) && !empty($participants) && random_int(0, 1) === 1) {
                    $participantId = $participants[array_rand($participants)];
                    $name = Character::withTrashed()
                        ->where('id', $participantId)
                        ->value('display_name');
                } else {
                    $name = $data['perpetrator_name'] ?? null;
                }

                if ($name) {
                    // First 2 characters only — enough to hint, not enough to identify.
                    $entry['name'] = strtoupper(substr(trim($name), 0, 2));
                }
            }

            $key = 'crime_log:' . $cityId;
            $existing = \Illuminate\Support\Facades\Cache::get($key, []);
            if (!is_array($existing)) {
                $existing = [];
            }

            // Prune anything older than 15 minutes on write — means the read
            // path never has to filter. List stays naturally small.
            $cutoffIso = now()->subMinutes(15)->utc()->toIso8601String();
            $existing = array_filter($existing, fn($e) => isset($e['at']) && $e['at'] >= $cutoffIso);

            array_unshift($existing, $entry);
            $existing = array_slice($existing, 0, 20);

            \Illuminate\Support\Facades\Cache::put($key, $existing, 900); // 15 min safety TTL
        } catch (\Throwable $e) {
            // Dispatch is decorative — must never break the crime write.
            \Illuminate\Support\Facades\Log::warning('[CrimeService] dispatch cache push failed.', [
                'record_id' => $record->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
