<?php

namespace App\Services;

use App\Models\CharacterJournal;
use Illuminate\Support\Facades\Cache;


class JournalService
{
    public static function organizedAttackReceived(
        int     $defenderId,
        array   $attackerNames,  
        string  $result,    
        string  $weaponUsed,
        int     $damage,
        bool    $isCritical,
        int     $maxHealthLost = 0,
        ?string $customMessage = null,
    ): void {
        $data = [
            'attacker_names'   => $attackerNames,
            'result'           => $result,
            'weapon_used'      => $weaponUsed,
            'damage'           => $damage,
            'was_critical'     => $isCritical,
            'max_health_lost'  => $maxHealthLost,
        ];

        if ($customMessage !== null) {
            $data['custom_message'] = $customMessage;
        }

        self::write($defenderId, 'organized_attack_received', $data);
    }

    public static function attackReceived(
        int $defenderId,
        string $attackerName,
        string $result,
        string $weaponUsed,
        ?int $damage = null,
        ?bool $wasCritical = null,
        ?int $maxHealthLost = null,
        ?int $hospitalDuration = null,
        ?string $customMessage = null,
        ?string $weaponMessage = null
        ): void
    {
        $data = [
            'attacker_name' => $attackerName,
            'result' => $result,
            'weapon_used' => $weaponUsed,
        ];

        if ($damage !== null) {
            $data['damage'] = $damage;
        }
        if ($wasCritical !== null) {
            $data['was_critical'] = $wasCritical;
        }
        if ($maxHealthLost !== null) {
            $data['max_health_lost'] = $maxHealthLost;
        }
        if ($hospitalDuration !== null) {
            $data['hospital_duration'] = $hospitalDuration;
        }
        if ($customMessage !== null) {
            $data['custom_message'] = $customMessage;
        }
        
        
        if ($weaponMessage !== null) {
            $data['weapon_message'] = $weaponMessage;
        }

        self::write($defenderId, 'attack_received', $data);
    }

    public static function moneyReceived(
        int $receiverId,
        string $senderName,
        int $amount,
        ?string $note = null
        ): void
    {
        $data = [
            'sender_name' => $senderName,
            'amount' => $amount,
        ];

        if ($note !== null) {
            $data['note'] = $note;
        }

        self::write($receiverId, 'money_transfer_received', $data);
    }

    public static function promotion(
        int $characterId,
        string $oldRank,
        string $newRank
        ): void
    {
        self::write($characterId, 'promotion_achieved', [
            'old_rank' => $oldRank,
            'new_rank' => $newRank,
        ]);
    }

    public static function businessSold(
        int $ownerId,
        string $buyerName,
        string $businessName,
        int $price,
        int $balance
        ): void
    {
        self::write($ownerId, 'business_acquisition_complete', [
            'buyer_name' => $buyerName,
            'business_name' => $businessName,
            'price' => $price,
            'vault_payout' => $balance,
            'total_received' => $price
        ]);
    }

    public static function vehicleRepaired(
        int $ownerId,
        string $technicianName,
        string $vehicleName,
        int $repairCost,
        int $durabilityRestored,
        int $maxDurability
        ): void
    {
        self::write($ownerId, 'vehicle_repaired', [
            'technician_name' => $technicianName,
            'vehicle_name' => $vehicleName,
            'repair_cost' => $repairCost,
            'durability_restored' => $durabilityRestored,
            'max_durability' => $maxDurability,
        ]);
    }


    public static function defenseRequest(
        int $defendantId,
        int $attorneyId,
        string $attorneyName,
        int $caseId,
        string $crimeLabel,
        string $crimeType,
        string $severity,
        ?string $prosecutorName = null,
        ?int $offerId = null,
        int $fee = 1000
        ): void
    {
        self::write($defendantId, 'defense_request', [
            'case_id' => $caseId,
            'offer_id' => $offerId,
            'attorney_id' => $attorneyId,
            'attorney_name' => $attorneyName,
            'crime_type' => $crimeType,
            'crime_label' => $crimeLabel,
            'severity' => $severity,
            'prosecutor_name' => $prosecutorName,
            'fee' => $fee,
        ]);
    }

    public static function defenseRetained(
        int $attorneyId,
        string $defendantName,
        int $caseId,
        string $crimeLabel,
        int $fee
        ): void
    {
        self::write($attorneyId, 'defense_retained', [
            'case_id' => $caseId,
            'defendant_name' => $defendantName,
            'crime_label' => $crimeLabel,
            'fee' => $fee,
        ]);
    }

    public static function defenseDeclined(
        int $attorneyId,
        string $defendantName,
        int $caseId,
        string $crimeLabel
        ): void
    {
        self::write($attorneyId, 'defense_declined', [
            'case_id' => $caseId,
            'defendant_name' => $defendantName,
            'crime_label' => $crimeLabel,
        ]);
    }

    public static function careerDismissed(
        int $characterId,
        string $dismissedByName,
        string $dismisserRankName,
        string $careerName
        ): void
    {
        self::write($characterId, 'dismissed_by', [
            'dismissed_by' => $dismissedByName,
            'dismisser_rank_name' => $dismisserRankName,
            'career_name' => $careerName,
        ]);
    }

    
     
    public static function homeInspected(
        int $ownerId,
        string $technicianName,
        string $propertyName,
        int $fee
        ): void
    {
        self::write($ownerId, 'home_inspected', [
            'technician_name' => $technicianName,
            'property_name'   => $propertyName,
            'fee'             => $fee,
        ]);
    }

    public static function custom(
        int $characterId,
        string $type,
        array $data
        ): void
    {
        self::write($characterId, $type, $data);
    }

    private static function write(int $characterId, string $type, array $data): void
    {
        
        
        
        
        //! USE RAW EXISTS CHECK

        $character = \App\Models\Character::withTrashed()->find($characterId);

        if (!$character) {
            return;
        }

        CharacterJournal::create([
            'character_id' => $characterId,
            'type' => $type,
            'data' => $data,
            'is_read' => false,
        ]);

        Cache::forget("unread_journals_{$characterId}");


    }
}
