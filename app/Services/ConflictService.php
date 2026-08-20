<?php

namespace App\Services;

use App\Models\Character;
use Illuminate\Support\Facades\Log;

class ConflictService
{



    public static function applyInfluenceChanges(
        Character $attacker,
        Character $defender,
        array $attackerStats,
        array $defenderStats,
        float $damagePercent
    ): void {
        $targetStrength = $defender->total_character_exp / max($attacker->total_character_exp, 1);
        $influenceDiff = max(0.1, abs($attackerStats['influence'] - $defenderStats['influence']));
        $xpDiff = max(100, abs($attacker->total_character_exp - $defender->total_character_exp));

        if ($targetStrength < 0.5) {
            $attackerInfluenceCost = $influenceDiff * 0.4 * $damagePercent;
            $defenderInfluenceGain = $attackerInfluenceCost * 0.7;
            $defenderXpGain = min(100, ($xpDiff * 0.4 * $damagePercent) * 0.7);

            if ($attackerInfluenceCost > 0)
                $attacker->stats->removeInfluence($attackerInfluenceCost);
            if ($defenderInfluenceGain > 0)
                $defender->stats->addInfluence($defenderInfluenceGain);
            if ($defenderXpGain > 0)
                $defender->addXp((int) round($defenderXpGain));

        } elseif ($targetStrength < 0.8) {
            $attackerInfluenceCost = $influenceDiff * 0.15 * $damagePercent;
            $defenderInfluenceGain = $attackerInfluenceCost * 0.7;
            $defenderXpGain = min(100, ($xpDiff * 0.15 * $damagePercent) * 0.7);

            if ($attackerInfluenceCost > 0)
                $attacker->stats->removeInfluence($attackerInfluenceCost);
            if ($defenderInfluenceGain > 0)
                $defender->stats->addInfluence($defenderInfluenceGain);
            if ($defenderXpGain > 0)
                $defender->addXp((int) round($defenderXpGain));

        } elseif ($targetStrength <= 1.2) {
            $signedDiff = $attackerStats['influence'] - $defenderStats['influence'];
            $absDiff = abs($signedDiff);

            if ($absDiff > 0) {
                if ($signedDiff < 0) {
                    $attackerInfluenceGain = $absDiff * 0.2 * $damagePercent;
                    $defenderInfluenceLoss = $attackerInfluenceGain * 0.3;
                    if ($attackerInfluenceGain > 0)
                        $attacker->stats->addInfluence($attackerInfluenceGain);
                    if ($defenderInfluenceLoss > 0)
                        $defender->stats->removeInfluence($defenderInfluenceLoss);
                } else {
                    $attackerInfluenceCost = $absDiff * 0.2 * $damagePercent;
                    $defenderInfluenceGain = $attackerInfluenceCost * 0.3;
                    if ($attackerInfluenceCost > 0)
                        $attacker->stats->removeInfluence($attackerInfluenceCost);
                    if ($defenderInfluenceGain > 0)
                        $defender->stats->addInfluence($defenderInfluenceGain);
                }
            }

        } else {
            $multiplier = $targetStrength <= 2.0 ? 0.35 : 0.5;
            $attackerInfluenceGain = $influenceDiff * $multiplier * $damagePercent;
            $defenderInfluenceLoss = $attackerInfluenceGain * 0.7;
            $attackerXpGain = min(100, ($xpDiff * $multiplier * $damagePercent) * 0.7);

            if ($attackerInfluenceGain > 0)
                $attacker->stats->addInfluence($attackerInfluenceGain);
            if ($defenderInfluenceLoss > 0)
                $defender->stats->removeInfluence($defenderInfluenceLoss);
            if ($attackerXpGain > 0)
                $attacker->addXp((int) round($attackerXpGain));
        }
    }





    public static function luckyRoll(float $min, float $max, int $attackerLuck, int $defenderLuck): float
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        $min = max(0.01, $min);
        $baseRoll = random_int((int) round($min * 100), (int) round($max * 100)) / 100;
        $luckDiff = max(-50.0, min(50.0, log(max(1, $attackerLuck) / max(1, $defenderLuck), 2) * 50));
        $skew = ($luckDiff / 50) * (($max - $min) * 0.05);

        return max($min, min($max, round(($baseRoll - $skew) * 100) / 100));
    }

    public static function calculateCombatPower(Character $character, array $stats, bool $isDefender): float
    {
        if ($isDefender) {
            return $stats['defense'] * 1.5
                + $stats['offense'] * 1.0
                + $stats['intelligence'] * 1.5
                + $stats['luck'] * 1.2
                + $character->total_character_exp / 1000;
        }

        return $stats['offense'] * 3.0
            + $stats['defense'] * 1.0
            + $stats['intelligence'] * 1.5
            + $stats['luck'] * 1.2
            + $character->total_character_exp / 1000;
    }

    public static function calculateHitChance(array $attackerStats, array $defenderStats, float $advantage, float $influenceDiff): float
    {
        $offenseRatio = $attackerStats['offense'] / max(1, $defenderStats['defense']);
        $baseHitChance = 70 + (log10(max(0.1, $offenseRatio)) * 30);
        $advantageBonus = $advantage * 10;

        $influenceBonus = $influenceDiff > 0
            ? min(25, sqrt($influenceDiff) * 8)
            : max(-30, -sqrt(abs($influenceDiff)) * 8);

        return max(33, min(99, $baseHitChance + $advantageBonus + $influenceBonus));
    }

    public static function calculateKillChance(Character $attacker, Character $defender, float $advantage, float $influenceDiff): float
    {
        $baseKill = match (true) {
            $advantage >= 1.5 => 25,
            $advantage >= 0.5 => 12,
            $advantage >= 0 => 6,
            $advantage >= -0.2 => 4,
            default => 2,
        };

        $targetStrength = $defender->total_character_exp / max($attacker->total_character_exp, 1);

        $influenceModifier = match (true) {
            $targetStrength < 0.5 => -0.3,
            $targetStrength < 0.8 => -0.1,
            $targetStrength <= 1.2 => 0,
            $targetStrength <= 2.0 => 0.2,
            default => 0.4,
        };

        $influenceGap = abs($influenceDiff);
        $volatilityBonus = match (true) {
            $influenceGap >= 15 => min(25, ($influenceGap - 5) * 0.4),
            $influenceGap >= 10 => min(15, ($influenceGap - 5) * 0.4),
            $influenceGap > 5 => min(5, ($influenceGap - 5) * 0.4),
            default => 0,
        };

        $defenderProtection = ($influenceDiff < 0 && !($targetStrength >= 1.5 && $influenceGap <= 15))
            ? min(20, abs($influenceDiff) * 0.5)
            : 0;

        $killChance = $baseKill + ($baseKill * $influenceModifier);
        $killChance += $volatilityBonus;
        $killChance -= $defenderProtection;

        $ceiling = $attacker->hasTalentActive('goal_of_all_life') ? 40 : 25;

        if ($attacker->hasTalentActive('goal_of_all_life')) {
            $maxHp = max(1, (int) $attacker->max_health);
            $hp = max(1, min($maxHp, (int) $attacker->health));
            $healthBonus = min(10.0, 10.0 * log10(100 / $hp) / log10(5));
            $killChance += $healthBonus;
        }

        return max(1, min($ceiling, $killChance));
    }

    //! fix armor not mattering right now only matters for calculateDamage for others just defense column
    public static function calculateDamage(array $attackerStats, array $defenderStats, float $advantage, ?Character $defender = null): array
    {
        $baseDamage = sqrt($attackerStats['offense']) / 5;
        //! damagemultiplier name is misleading.
        $damageMultiplier = 1 / (1 + (sqrt($defenderStats['defense']) / 250));

        if ($defender?->hasTalentActive('defense_in_depth')) {
            $damageMultiplier *= $damageMultiplier;
        }

        $armorReduction = (float) ($defender?->items?->first(fn($i) => $i->is_equipped && $i->equipped_slot === 'armor')?->template?->data['damage_reduction'] ?? 0);
        $damageMultiplier *= (1 - $armorReduction);

        $damageAfterDefense = $baseDamage * $damageMultiplier;

        $intRatio = max(0.1, $attackerStats['intelligence']) / max(1, $defenderStats['intelligence']);
        $critChance = max(10, min(90, 20 + (log10($intRatio) * 40)));
        $critRoll = self::luckyRoll(0.01, 100.0, $attackerStats['luck'], $defenderStats['luck']);
        $isCritical = $critRoll <= $critChance;

        if ($isCritical) {
            $baseCritMultiplier = self::luckyRoll(1.5, 2.0, $defenderStats['luck'], $attackerStats['luck']);
            $critReduction = 1 / (1 + (sqrt($defenderStats['defense']) / 1500));
            $critMultiplier = max(1.15, 1 + (($baseCritMultiplier - 1) * $critReduction));
        } else {
            $critMultiplier = 1.0;
        }

        $advantageFactor = 1 + ($advantage * 0.3);
        $finalDamage = $damageAfterDefense * $critMultiplier * $advantageFactor;

        return [
            'damage' => max(1, min(99, $finalDamage)),
            'is_critical' => $isCritical,
        ];
    }

   public static function calculateMaxHealthReduction(int $damage, bool $isCritical, int $attackerInt, int $defenderInt): int
{
    if ($damage < 10) {
        return 0;
    }

    
    $intRatio = max(0.1, $attackerInt) / max(1, $defenderInt);
    $intelligenceChance = max(-25, min(50, log10($intRatio) * 40));
    $damageChance = min(50, $damage * 0.75);
    $critBonus = $isCritical ? 10 : 0;
    $totalChance = max(1, min(95, $intelligenceChance + $damageChance + $critBonus));

    if (random_int(1, 100) > $totalChance) {
        return 0;
    }

    $baseSeverity = match (true) {
        $damage >= 80 => 4,
        $damage >= 65 => 3,
        $damage >= 40 => 2,
        default      => 1,
    };

    if ($baseSeverity === 0) return 0;

    $severity = $isCritical ? min(4, $baseSeverity + 1) : $baseSeverity;

   

    return $severity * 2;
}

    public static function loot(Character $attacker, Character $defender, array $attackerStats, array $defenderStats): array
    {
        $lootResult = ['cash_looted' => 0, 'dirty_cash_looted' => 0, 'messages' => []];

        $luckRatio = max(1, $attackerStats['luck']) / max(1, $defenderStats['luck']);
        $luckEdge = max(-1.0, min(1.0, log($luckRatio, 2)));
        $cashLootChance = (int) round(max(5, min(90, 70 + ($luckEdge * 20))));

        if (random_int(1, 100) <= $cashLootChance) {
            $lootPercentage = min(50, 5 + ($luckEdge > 0 ? $luckEdge * 45 : 0));
            $lootedCash = (int) round($defender->cash_on_hand * ($lootPercentage / 100));

            if ($lootedCash > 0) {
                $attacker->addCash($lootedCash, true);
                $defender->cash_on_hand -= $lootedCash;
                $defender->save();

                $lootResult['cash_looted'] = $lootedCash;
                $lootResult['messages'][] = "While looting their body, you found \${$lootedCash} on them.";
            }
        }

        if ($defender->dirty_cash > 0) {
            $dirtyLoot = min($defender->dirty_cash, random_int(100, 1000));

            $attacker->addCash($dirtyLoot, true);
            $defender->dirty_cash -= $dirtyLoot;
            $defender->save();

            $lootResult['dirty_cash_looted'] = $dirtyLoot;
            $lootResult['messages'][] = "You also discovered \${$dirtyLoot} in dirty cash.";
        }

        return $lootResult;
    }
}
