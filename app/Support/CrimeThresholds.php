<?php

namespace App\Support;


final class CrimeThresholds
{
    
    public const SAFE_MAX     = 15;
    public const LOW_MAX      = 35;
    public const MODERATE_MAX = 55;
    public const HIGH_MAX     = 75;
    

    
    public const LOW_MIN      = 16;
    public const MODERATE_MIN = 36;
    public const HIGH_MIN     = 56;
    public const CRITICAL_MIN = 76;

    
    
    public const BOND_DEFAULT_THRESHOLD = 65;

    
    public const LABEL_SAFE     = 'Safe';
    public const LABEL_LOW      = 'Low';
    public const LABEL_MODERATE = 'Moderate';
    public const LABEL_HIGH     = 'High';
    public const LABEL_CRITICAL = 'Critical';

    

    public static function label(float $rate): string
    {
        return match (true) {
            $rate <= self::SAFE_MAX     => self::LABEL_SAFE,
            $rate <= self::LOW_MAX      => self::LABEL_LOW,
            $rate <= self::MODERATE_MAX => self::LABEL_MODERATE,
            $rate <= self::HIGH_MAX     => self::LABEL_HIGH,
            default                     => self::LABEL_CRITICAL,
        };
    }

    public static function isSafe(float $rate): bool
    {
        return $rate <= self::SAFE_MAX;
    }

    public static function isLow(float $rate): bool
    {
        return $rate > self::SAFE_MAX && $rate <= self::LOW_MAX;
    }

    public static function isModerate(float $rate): bool
    {
        return $rate > self::LOW_MAX && $rate <= self::MODERATE_MAX;
    }

    public static function isHigh(float $rate): bool
    {
        return $rate > self::MODERATE_MAX && $rate <= self::HIGH_MAX;
    }

    public static function isCritical(float $rate): bool
    {
        return $rate > self::HIGH_MAX;
    }

    public static function isAtOrAbove(float $rate, float $threshold): bool
    {
        return $rate >= $threshold;
    }
}
