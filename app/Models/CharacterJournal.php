<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

//! TODO REMEMBER CLOUDFLARE IMAGE GATING
//! fix the animate critical glitch 
class CharacterJournal extends Model
{
    use HasFactory;

    protected $table = "character_journals";

    protected $fillable = ["character_id", "type", "data", "is_read", "is_saved"];

    protected $casts = ["data" => "array", "is_read" => "boolean", "is_saved" => "boolean"];

    protected $appends = [
        "title",
        "description",
        "icon",
        "color_class",
        "actor_avatar",
        "item_image",
    ];

    /**
     * Per-row values injected by preloadForDisplay() so the appended accessors
     * (actor_avatar, description) don't run one query per journal row.
     * Not model attributes, so they are never serialized.
     */
    protected bool $actorAvatarPreloaded = false;
    protected ?string $preloadedActorAvatar = null;
    protected bool $cityNamePreloaded = false;
    protected ?string $preloadedCityName = null;

    /**
     * Resolve, in bulk, everything the appended accessors would otherwise look up
     * per row: actor avatars (one characters query) and city names for
     * investment_fraud_request descriptions (one cities query).
     * Output is identical to the per-row fallbacks in the accessors.
     */
    public static function preloadForDisplay(iterable $journals): void
    {
        $actorIds = [];
        $cityIds = [];
        foreach ($journals as $journal) {
            if ($actorId = $journal->resolveActorId()) {
                $actorIds[] = $actorId;
            }
            if ($journal->type === "investment_fraud_request") {
                $cityIds[] = $journal->data["city_id"] ?? 1;
            }
        }

        $avatars = [];
        if ($actorIds) {
            // Same scope as Character::find() (soft-deleted excluded); only the
            // columns Character::getAvatarUrlAttribute() needs.
            $avatars = Character::whereIn("id", array_values(array_unique($actorIds)))
                ->get(["id", "custom_avatar_url", "career_id", "career_rank"])
                ->mapWithKeys(fn(Character $c) => [$c->id => $c->avatar_url])
                ->all();
        }

        $cityNames = [];
        if ($cityIds) {
            $cityNames = City::whereIn("id", array_values(array_unique($cityIds)))
                ->pluck("name", "id")
                ->all();
        }

        foreach ($journals as $journal) {
            $actorId = $journal->resolveActorId();
            $journal->preloadedActorAvatar = $actorId ? ($avatars[$actorId] ?? null) : null;
            $journal->actorAvatarPreloaded = true;

            if ($journal->type === "investment_fraud_request") {
                $journal->preloadedCityName = $cityNames[$journal->data["city_id"] ?? 1] ?? null;
                $journal->cityNamePreloaded = true;
            }
        }
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function scopeUnread($query)
    {
        return $query->whereRaw('"is_read" IS FALSE');
    }

    public function getFormattedDateAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }




    public function getTitleAttribute(): string
    {
        $data = $this->data;
        $type = $this->type;

        switch ($type) {
            case "attack_received":
                $result = $data["result"] ?? "unknown";
                $weapon = $data["weapon_used"] ?? "";

                if ($result === "hospitalized") {
                    $weaponStr = $weapon ? " ({$weapon})" : "";
                    return "GBH ASSAULT{$weaponStr}";
                } elseif ($result === "gbh_miss") {
                    $weaponStr = $weapon ? " ({$weapon})" : "";
                    return "GBH FAILED{$weaponStr}";
                } elseif ($result === "hit") {
                    $permanentInjury = (!empty($data["max_health_lost"]) && $data["max_health_lost"] > 0);
                    $critical = !empty($data["was_critical"]) && $data["was_critical"] === true;
                    $weaponStr = $weapon ? " ({$weapon})" : "";

                    $prefixes = "";
                    if ($critical)
                        $prefixes .= "[CRITICAL HIT]";
                    if ($permanentInjury)
                        $prefixes .= "[MAX HEALTH LOST]";

                    if ($prefixes)
                        $prefixes .= " ";

                    return "{$prefixes}ASSASSINATION ATTEMPT{$weaponStr}";

                } elseif ($result === "miss") {
                    $weaponStr = $weapon ? " ({$weapon})" : "";
                    return "ASSASSINATION DODGED{$weaponStr}";
                }
                return "COMBAT EVENT";

            case "money_transfer_received":
                return "MONEY TRANSFER";

            case "promotion_achieved":
                return "PROMOTION ACHIEVED";

            case "business_acquisition_complete":
                return "COMMERCIAL ASSET TRANSACTION";

            case "item_sale_request":
                $sellerName = strtoupper($data["seller_name"] ?? "UNKNOWN");
                return "SALE REQUEST FROM {$sellerName}";

            case "corporate_medicine_sale_request":
                return "MEDICINE OFFER";

            case "corporate_mirror_transaction_request":
                return "CORPORATE LAUNDERING REQUEST";

            case "item_sold":
                return "ITEM SOLD";

            case "corporate_medicine_sold":
                return "MEDICINE SOLD";

            case "corporate_mirror_transaction_completed":
                return "MIRROR TRANSACTION COMPLETED";

            case "corporate_mirror_transaction_accepted":
                return "MIRROR TRANSACTION ACCEPTED";

            case "corporate_mirror_transaction_declined":
                return "MIRROR TRANSACTION DECLINED";

            case "achievement_unlocked":
                return "ACHIEVEMENT UNLOCKED";

            case "vehicle_repaired":
                return "VEHICLE REPAIRED";

            case "home_inspected":
                return "HOME INSPECTION COMPLETE";

            case "home_repaired":
                return empty($data['failed'])
                    ? "HOME RECONSTRUCTION COMPLETE"
                    : "RECONSTRUCTION FAILED — PROPERTY LOST";

            case "corporation_property_constructed":
                return "PROPERTY CONSTRUCTION COMPLETE";

            case "election_result":
                $won = $data["won"] ?? false;
                $city = strtoupper($data["city_name"] ?? "YOUR CITY");
                return $won ? "ELECTED MAYOR OF {$city}" : "ELECTION RESULTS — {$city}";

            case "election_city_result":
                $city = strtoupper($data["city_name"] ?? "YOUR CITY");
                return "NEW MAYOR ELECTED — {$city}";

            case "election_disqualified":
                return "ELECTION DISQUALIFIED";

            case "mayor_term_expired":
                $city = strtoupper($data["city_name"] ?? "YOUR CITY");
                return "MAYORAL TERM EXPIRED — {$city}";

            case "mayor_removed":
                $city = strtoupper($data["city_name"] ?? "YOUR CITY");
                return "REMOVED FROM OFFICE — {$city}";

            case "corporation_founded":
                return "CORPORATION INCORPORATED";

            case "corporation_invite_request":
                $corp = strtoupper($data["corporation_name"] ?? "A CORPORATION");
                return "INVITATION — {$corp}";

            case "corporation_member_joined":
                $member = strtoupper($data["member_name"] ?? "A MEMBER");
                return "{$member} JOINED THE CORPORATION";

            case "corporation_joined":
                $corpName = strtoupper($data["corporation_name"] ?? "A CORPORATION");
                return "JOINED {$corpName}";

            case "corporation_kicked":
                return "REMOVED FROM CORPORATION";

            case "corporation_position_assigned":
                $pos = strtoupper($data["position"] ?? "OFFICER");
                return "APPOINTED {$pos}";

            case "demoted":
                return "DEMOTED";

            case "corporation_deposit":
                $amount = number_format($data["amount"] ?? 0);
                return "SLUSH FUND DEPOSIT — \${$amount}";

            case "corporation_distribution":
                $amount = number_format($data["amount"] ?? 0);
                return "FUNDS DISTRIBUTED — \${$amount}";

            case "corporation_reserve_deposit":
                $amount = number_format($data["amount"] ?? 0);
                return "RESERVE DEPOSIT - \${$amount}";

            case "corporation_reserve_distribution":
                $amount = number_format($data["amount"] ?? 0);
                return "RESERVES DISTRIBUTED - \${$amount}";

            case "corporation_ceo_transfer":
                return "APPOINTED CEO";

            case "corporation_ceo_transferred":
                return "CEO ROLE TRANSFERRED";

            case "corporation_merger_proposed":
                return "MERGER PROPOSAL";

            case "corporation_merger_completed":
                return "HOLDING COMPANY FORMED";

            case "corporation_merger_cancelled":
                return "MERGER PROPOSAL DECLINED";

            case "corporation_subsidiary_invite":
                return "SUBSIDIARY INVITATION";

            case "corporation_subsidiary_invite_accepted":
                return "SUBSIDIARY INVITATION ACCEPTED";

            case "corporation_subsidiary_invite_declined":
                return "SUBSIDIARY INVITATION DECLINED";

            case "corporation_board_promotion_pending":
                return "BOARD PROMOTION READY";

            case "corporation_board_successor_named":
                return "SUCCESSOR NAMED";

            case "corporation_board_successor_installed":
                return "CEO HANDOFF COMPLETE";

            case "corporation_subsidiary_kicked":
                return "SUBSIDIARY REMOVED";

            case "corporation_holding_collapsed":
                return "HOLDING COMPANY COLLAPSED";

            case "corporation_trust_vote_opened":
                return "DIRECTOR VOTE OPENED";

            case "corporation_trust_vote_completed":
                return "DIRECTOR VOTE COMPLETED";

            case "corporation_trust_vote_failed":
                return "DIRECTOR VOTE FAILED";

            case "corporation_trust_created":
                return "DIRECTOR OF THE BOARD INSTALLED";

            case "corporation_dissolved":
                return "CORPORATION DISSOLVED";

            case "corporation_member_quit":
                $member = strtoupper($data["member_name"] ?? "A MEMBER");
                return "{$member} LEFT THE CORPORATION";

            case "corporation_quit":
                return "LEFT CORPORATION";

            case "kidnapping_request":
                $initiator = strtoupper($data['initiator_name'] ?? 'UNKNOWN');
                return "KIDNAPPING REQUEST — {$initiator}";

            case "defense_request":
                $caseId = $data['case_id'] ?? '?';
                return "CASE #{$caseId} — DEFENCE OFFER";

            case "defense_outcome":
                $caseId = $data['case_id'] ?? '?';
                $won = ($data['outcome'] ?? '') === 'acquitted';
                return $won ? "CASE #{$caseId} — CLIENT ACQUITTED" : "CASE #{$caseId} — CLIENT CONVICTED";

            case "defense_declined":
                $caseId = $data['case_id'] ?? '?';
                return "CASE #{$caseId} — DEFENCE DECLINED";

            case "defense_retained":
                $caseId = $data['case_id'] ?? '?';
                return "CASE #{$caseId} — DEFENCE RETAINED";


            case "case_rewarded":
            case "case_sentenced":
            case "case_outcome":
            case "case_auto_charged":
            case "case_appeal_resolved":
            case "appeal_outcome":
            case "appeal_filed":
            case "case_closed":
            case "case_defense_request_expired":
                $caseId = $data['case_id'] ?? '?';
                if ($type === 'appeal_outcome') {
                    $convictionStands = ($data['outcome'] ?? '') === 'sentenced';
                    return $convictionStands
                        ? "CASE #{$caseId} — APPEAL DENIED "
                        : "CASE #{$caseId} — APPEAL GRANTED";
                }
                if ($type === 'case_outcome') {
                    $outcome = $data['outcome'] ?? '';
                    if ($outcome === 'acquitted')
                        return "CASE #{$caseId} — ACQUITTED";
                    if ($outcome === 'convicted')
                        return "CASE #{$caseId} — CONVICTED";
                }
                return match ($type) {
                    'case_rewarded' => "CASE #{$caseId} — COMPENSATION DEPOSITED",
                    'case_sentenced' => "CASE #{$caseId} — SENTENCE ISSUED",
                    'case_outcome' => "CASE #{$caseId} — VERDICT",
                    'case_auto_charged' => "CASE #{$caseId} — FORWARDED TO JUDGE",
                    'case_appeal_resolved' => "CASE #{$caseId} — APPEAL CONCLUDED",
                    'appeal_filed' => "CASE #{$caseId} — APPEAL SUBMITTED",
                    'case_closed' => "CASE #{$caseId} — CLOSED",
                    'case_defense_request_expired' => "CASE #{$caseId} — DEFENCE REQUEST EXPIRED",
                    default => "CASE #{$caseId}",
                };

            case "criminal_charges_filed":
                $caseId = $data['crime_record_id'] ?? $data['case_id'] ?? '?';
                return "CASE #{$caseId} — CHARGES FILED";

            case "defense_accepted":
                $caseId = $data['case_id'] ?? '?';
                $outcome = ($data['outcome'] ?? 'convicted') === 'acquitted' ? 'ACQUITTED' : 'CONVICTED';
                return "CASE #{$caseId} — DEFENCE {$outcome}";

            case "investment_fraud_request":
                return "FRAUD ACTION: HELP REQUESTED";

            case "banker_launder_added":
                return "LAUNDERING ARRANGEMENT ADDED";

            case "banker_launder_cancelled":
                return "LAUNDERING ARRANGEMENT CANCELLED";



            case "banker_launder_executed":
                return "BANK LAUNDERING COMPLETED";

            case "banker_launder_failed":
                return "BANK LAUNDERING FAILED";

            case "banker_launder_declined":
                return "OFFER DECLINED";







            case "investment_fraud_result":
                $status = $data["status"] ?? "failed";
                return $status === "success" ? "OPERATION SUCCESSFUL" : "OPERATION FAILED";

            case "bank_fraud":
                return "BANK FRAUD DETECTED";

            case "action_result":
                $status = $data["status"] ?? "failed";
                return $status === "success" ? "OPERATION SUCCESSFUL" : "OPERATION FAILED";

            case "action_victim":
                return "ACTION  VICTIM";

            case "action_ransomware_attack":
                return ($data['status'] ?? 'failure') === 'success'
                    ? 'ACTION — RANSOMWARE ATTACK'
                    : 'ACTION — RANSOMWARE BLOCKED';

            case "action_crypto_rug_pull":
                return ($data['status'] ?? 'failure') === 'success'
                    ? 'ACTION — CRYPTO RUG PULL'
                    : 'ACTION — SCAM FAILED';

            case "action_kidnapping":
                return ($data['status'] ?? 'failure') === 'success'
                    ? 'ACTION — KIDNAPPING'
                    : 'ACTION — ABDUCTION FAILED';

            case "arrested":
                $status = $data['status'] ?? 'success';
                if ($status === 'success') {
                    $caseId = $data['case_id'] ?? '?';
                    return "CASE #{$caseId} — ARRESTED";
                }
                $caseId = $data['case_id'] ?? null;
                return $caseId ? "CASE #{$caseId} — ARREST EVADED" : 'ARREST EVADED';

            case "cd_matured":
                $amount = number_format($data['payout'] ?? 0);
                return "CERTIFICATE MATURED — \${$amount}";

            case "cd_defaulted":
                return "CERTIFICATE — BANK DEFAULT";

            case "dismissed_by":
                $careerName = strtoupper($data['career_name'] ?? 'YOUR CAREER');
                return "DISMISSED FROM {$careerName}";

            case "mugging":
                $cityName = strtoupper($data['city_name'] ?? 'THE STREETS');
                return "STREET MUGGING — {$cityName}";

            case "ngri_success":
                $caseId = $data['case_id'] ?? '?';
                return "CASE #{$caseId} — NGRI PLEA ACCEPTED";

            case "hospital_treated":
                return "HOSPITAL — TREATMENT RECEIVED";

            case "hospital_surgery":
                return "HOSPITAL — OUTPATIENT SURGERY";

            case "hospital_gender_reassignment":
                return "HOSPITAL — GENDER REASSIGNMENT";

            case 'career_step_down':
                return 'CAREER — HONOURABLE DISCHARGE';

            case 'relocation_approved':
                return 'RELOCATION APPROVED';

            case 'relocation_denied':
                return 'RELOCATION DENIED';


            case 'audit_initiated':
                return 'CORPORATE AUDIT — INITIATED';

            case 'audit_success':
                return 'CORPORATE AUDIT — FUNDS RECOVERED';

            case 'audit_target':
                return 'CORPORATE AUDIT — YOUR FIRM INVESTIGATED';

            case 'pardoned':
                return 'EXECUTIVE PARDON GRANTED';

            case 'bond_matured':
                return 'MUNICIPAL BOND — MATURED';

            case 'bond_defaulted':
                return 'MUNICIPAL BOND — DEFAULT';



            case 'bomb_plant_failed':
                return ($data['result'] ?? '') === 'detonator_destroyed'
                    ? 'EXPLOSIVE DEVICE NEUTRALIZED'
                    : 'ATTEMPTED BOMBING';

            case 'bomb_detonated':
                return 'PROPERTY DESTROYED — EXPLOSIVE DETONATED';

            case 'revived':
                return 'SAVED ON THE OPERATING TABLE';

            case 'busted_at_customs':
                return 'CUSTOMS BUST';




            case 'organized_hit_invite':
                $initiator = strtoupper($data['initiator_name'] ?? 'UNKNOWN');
                return "ORGANIZED HIT — INVITED BY {$initiator}";

            case 'organized_hit_accepted':
                $name = strtoupper($data['accomplice_name'] ?? 'CREW MEMBER');
                return "{$name} ACCEPTED — OPERATION STANDING BY";

            case 'organized_hit_declined':
                $name = strtoupper($data['accomplice_name'] ?? 'CREW MEMBER');
                return "{$name} DECLINED — OPERATION ABORTED";

            case 'organized_hit_warning':
                return 'VIGILANCE REQUIRED';

            case 'organized_hit_collapsed':
                return 'ORGANIZED HIT — OPERATION ABORTED';

            case 'organized_hit_result':
                return 'ORGANIZED HIT — OPERATION CONCLUDED';

            case 'organized_attack_received':
                $result = $data['result'] ?? 'hit';
                $names = $data['attacker_names'] ?? [];
                $count = count($names);
                $label = $count > 0 ? strtoupper($names[0]) . ($count > 1 ? ' + ' . ($count - 1) . ' others' : '') : 'UNKNOWN';
                return "ORGANIZED HIT ATTEMPT — BY {$label}";

            case 'corporation_move_completed':
                return 'CORPORATION RELOCATED';

            case 'corporation_move_denied':
                return 'RELOCATION DENIED';

            case 'journal_shared':
                $sender = strtoupper($data['sender_name'] ?? 'UNKNOWN');
                return "FROM {$sender} — " . ($data['orig_title'] ?? 'JOURNAL ENTRY');

            default:
                return 'JOURNAL ENTRY';
        }
    }


    public function getDescriptionAttribute(): string
    {
        $data = $this->data;
        $type = $this->type;

        switch ($type) {
            case "attack_received":
                $result = $data["result"] ?? "unknown";
                $attackerName = $data["attacker_name"] ?? "An unknown assailant";
                $weapon = $data["weapon_used"] ?? "their bare hands";
                $customMessage = $data["custom_message"] ?? "";

                if ($result === "hospitalized") {
                    $durationHours = ($data["hospital_duration"] ?? 7200) / 3600;
                    $msg = "{$attackerName} followed you into a deserted alley and beat you with their {$weapon} so badly you were hospitalized for {$durationHours} hour.";
                    if (!empty($customMessage))
                        $msg .= " They said: \"{$customMessage}\"";
                    return $msg;
                } elseif ($result === "gbh_miss") {
                    $msg = "{$attackerName} attempted a brutal GBH assault on you with their {$weapon} but failed to subdue you!";
                    if (!empty($customMessage))
                        $msg .= " They said: \"{$customMessage}\"";
                    return $msg;
                } elseif ($result === "hit") {
                    $damage = $data["damage"] ?? 0;
                    $weaponMessage = $data["weapon_message"] ?? null;

                    if (!empty($weaponMessage)) {

                        $msg = str_replace('They lost', 'You suffered a permanent injury of', $weaponMessage);

                    } else {
                        $msg = "{$attackerName} attacked you with their {$weapon} for {$damage} damage.";
                        if (!empty($data["was_critical"]) && $data["was_critical"] === true)
                            $msg = "Critical hit! " . $msg;
                        if (!empty($data["max_health_lost"]) && $data["max_health_lost"] > 0)
                            $msg .= " You suffered a permanent injury (-{$data["max_health_lost"]} max HP).";
                    }
                    if (!empty($customMessage))
                        $msg .= " They said: \"{$customMessage}\"";
                    return $msg;
                } elseif ($result === "miss") {
                    $msg = "{$attackerName} attempted to attack you with their {$weapon} but missed.";
                    if (!empty($customMessage))
                        $msg .= " They said: \"{$customMessage}\"";
                    return $msg;
                }
                return "Attack occurred.";

            case "money_transfer_received":
                $amount = number_format($data["amount"] ?? 0);
                $from = strtoupper($data["sender_name"] ?? "UNKNOWN");
                $note = $data["note"] ?? "";

                if (empty($note)) {
                    return "You received \${$amount} FROM {$from}";
                }
                $msg = "You received \${$amount} FROM {$from} with the message :";
                $msg .= "\"{$note}\"";
                return $msg;

            case "promotion_achieved":
                $oldRank = $data["old_rank"] ?? "Previous Rank";
                $newRank = $data["new_rank"] ?? "New Rank";
                return "Congratulations on your promotion to {$newRank} from {$oldRank}!";

            case "business_acquisition_complete":
                $buyer = $data["buyer_name"] ?? "A buyer";
                $businessName = $data["business_name"] ?? "venture";
                $total = number_format($data["total_received"] ?? 0);
                return "{$buyer} has bought your  {$businessName}. \${$total} has been wired to your bank account.";

            case "item_sale_request":
                $sellerName = $data["seller_name"] ?? "A seller";
                $itemName = $data["item_name"] ?? "an item";
                $price = number_format($data["price"] ?? 0);
                $condition = $data["condition_percent"] !== null ? " ({$data["condition_percent"]}% condition)" : "";
                return "{$sellerName} wants to sell you a {$itemName}{$condition} for \${$price}. You can accept or decline this request.";

            case "corporate_medicine_sale_request":
                $sellerName = $data["seller_name"] ?? "A company employee";
                $corpName = $data["corporation_name"] ?? "a corporation";
                $productName = $data["product_name"] ?? "company medicine";
                $price = number_format($data["price"] ?? 0);
                $packUnits = (int) ($data["pack_units"] ?? 3);
                $packCount = max(1, (int) ($data["pack_count"] ?? 1));
                $packLabel = $packCount === 1 ? "one pack" : "{$packCount} packs";
                return "{$sellerName} from {$corpName} is offering you {$packLabel} of {$productName} ({$packUnits} Capsules per pack) for \${$price}.";

            case "corporate_mirror_transaction_request":
                $cfoName = $data["cfo_name"] ?? "A CFO";
                $corpName = $data["corporation_name"] ?? "a corporation";
                $amount = number_format($data["amount"] ?? 0);
                $percentage = (int) ($data["banker_percentage"] ?? 0);
                return "{$cfoName} from {$corpName} wants you to help launder \${$amount} of their company's offshore funds through a mirror transaction scheme. They're offering you {$percentage}% of the funds after they finalize the transfer,";

            case "item_sold":
                $buyerName = $data["buyer_name"] ?? "A buyer";
                $itemName = $data["item_name"] ?? "an item";
                $price = number_format($data["price"] ?? 0);
                return "You successfully sold a {$itemName} to {$buyerName} for \${$price}.";

            case "corporate_medicine_sold":
                $buyerName = $data["buyer_name"] ?? "A buyer";
                $productName = $data["product_name"] ?? "company medicine";
                $packCount = max(1, (int) ($data["pack_count"] ?? 1));
                $packLabel = $packCount === 1 ? "pack" : "packs";
                $price = number_format($data["price"] ?? 0);
                $corpName = $data["corporation_name"] ?? "your corporation";
                return "{$buyerName} bought {$packCount} {$packLabel} of {$productName} for \${$price} from your corporation. The funds have been routed into your company's slush funds.";

            case "corporate_mirror_transaction_completed":
                $cfoName = $data["cfo_name"] ?? "A CFO";
                $corpName = $data["corporation_name"] ?? "a corporation";
                $amount = number_format($data["amount"] ?? 0);
                $fee = number_format($data["banker_fee"] ?? 0);
                return "{$cfoName} from {$corpName} has finished the mirror transaction money laundering scheme. The money has been settled as clean profits and your \${$fee} fee was deposited.";

            case "corporate_mirror_transaction_accepted":
                $bankerName = $data["banker_name"] ?? "A banker";
                $amount = number_format($data["amount"] ?? 0);
                return "{$bankerName} accepted the mirror transaction money laundering scheme. Return to company properties to move the funds.";

            case "corporate_mirror_transaction_declined":
                $bankerName = $data["banker_name"] ?? "A banker";
                return "{$bankerName} declined the mirror transaction. The funds remain inside the offshore trust.";

            case "achievement_unlocked":
                $name = $data["achievement_name"] ?? "achievement";
                return "The Creator of TheDirector has recognized your achievement and has awarded you with The {$name} badge.";

            case "vehicle_repaired":
                $techName = $data["technician_name"] ?? "A technician";
                $vName = $data["vehicle_name"] ?? "your vehicle";
                $cost = number_format($data["repair_cost"] ?? 0);
                $restored = $data["durability_restored"] ?? 0;
                $maxDur = $data["max_durability"] ?? 0;
                return "{$techName} repaired your {$vName} to {$restored}/{$maxDur} durability. \${$cost} was deducted from your funds.";

            case "home_inspected":
                $techName = $data["technician_name"] ?? "A technician";
                $propName = $data["property_name"] ?? "your property";
                return "{$techName} has inspected and certified your {$propName} as ready to move into. Enjoy your new home!";

            case "corporation_property_constructed":
                $engineerName = $data["engineer_name"] ?? "An engineer";
                $propName = $data["property_name"] ?? "your property";
                return "{$engineerName} has constructed your new {$propName}. It is ready to be used by your corporation.";

            case "home_repaired": {
                $techName = $data['technician_name'] ?? 'A technician';
                $propName = $data['property_name'] ?? 'your property';
                $fee = number_format($data['fee'] ?? 0);

                if (!empty($data['failed'])) {
                    $survivingLost = (int) ($data['surviving_items_lost'] ?? 0);
                    $itemsClause = $survivingLost > 0
                        ? " The collapse also destroyed the rest of the items that had survived the original explosion."
                        : '';
                    return "{$techName} attempted to rebuild your {$propName} but the structure was too badly damaged to salvage. "
                        . "The property has been lost entirely and you were charged the reconstruction cost of \${$fee} for the attempt."
                        . $itemsClause;
                }

                return "{$techName} was able to repair your {$propName} after the ruinous explosion. "
                    . "Unfortunately they could not save any of your items or vehicles that were destroyed in the explosion — "
                    . "but your home is standing again. Reconstruction cost: \${$fee}.";
            }

            case "election_result":
                $won = $data["won"] ?? false;
                $winner = $data["winner_name"] ?? "Unknown";
                $city = $data["city_name"] ?? "your city";
                $votes = $data["votes_received"] ?? 0;
                $total = $data["total_votes"] ?? 0;
                $pct = number_format($data["vote_percentage"] ?? 0, 1);
                $termEnd = $data["term_end"] ?? null;
                if ($won) {
                    $termFormatted = $termEnd ? \Carbon\Carbon::parse($termEnd)->utc()->format('d/m/y H:i:s') . ' UTC' : null;
                    $termStr = $termFormatted ? " Your term ends {$termFormatted}." : "";
                    return "You won the {$city} mayoral election with {$votes}/{$total} votes ({$pct}%).{$termStr}";
                }
                return "You lost the {$city} mayoral election. {$winner} became mayor. You received {$votes}/{$total} votes ({$pct}%).";

            case "election_city_result":
                $winner = $data["winner_name"] ?? "Unknown";
                $city = $data["city_name"] ?? "your city";
                return $data["message"] ?? "{$winner} has been elected mayor of {$city}.";

            case "mayor_term_expired":
                $city = $data["city_name"] ?? "your city";
                return $data["message"] ?? "Your mayoral term in {$city} has expired. You have been removed from office.";

            case "mayor_removed":
                return $data["message"] ?? "You have been removed from mayoral office.";

            case "election_disqualified":
                return $data["message"] ?? "You have been disqualified from the mayoral election.";

            case "corporation_founded":
                $corpName = $data["corporation_name"] ?? "your corporation";
                return "You have successfully incubated your company {$corpName}. Welcome to the corporate world.";

            case "corporation_invite_request":
                $inviter = $data["inviter_name"] ?? "Someone";
                $corpName = $data["corporation_name"] ?? "a corporation";
                return "{$inviter} has extended an invitation for you to join {$corpName}. Accept to join the company.";

            case "corporation_member_joined":
                $member = $data["member_name"] ?? "A new member";
                $corpName = $data["corporation_name"] ?? "your corporation";
                return "{$member} has accepted your invitation and joined {$corpName}.";

            case "corporation_joined":
                $corpName = $data["corporation_name"] ?? "a corporation";
                return "You have joined {$corpName}. Welcome to the corporate world.";

            case "corporation_kicked":
                $corpName = $data["corporation_name"] ?? "your corporation";
                return "You have been removed from {$corpName} by the CEO.";

            case "corporation_position_assigned":
                $pos = $data["position"] ?? "Officer";
                $corpName = $data["corporation_name"] ?? "your corporation";
                return "Congratulations! You have been appointed {$pos} of {$corpName}.";

            case "demoted":
                $actor = $data["actor_name"] ?? "A manager";
                $actorPosition = $data["actor_position"] ?? "Manager";
                $target = $data["target_name"] ?? "an employee";
                $rankName = $data["rank_name"] ?? "a lower rank";

                if (($data["audience"] ?? "target") === "manager") {
                    return "{$actorPosition} {$actor} has demoted your employee {$target} to {$rankName}.";
                }

                return "{$actorPosition} {$actor} has demoted you to {$rankName}.";

            case "corporation_deposit":
                $depositor = $data["depositor_name"] ?? "A member";
                $amount = number_format($data["amount"] ?? 0);
                $corpName = $data["corporation_name"] ?? "your corporation";
                return "{$depositor} deposited \${$amount} into the {$corpName} slush fund.";

            case "corporation_distribution":
                $amount = number_format($data["amount"] ?? 0);
                $distributor = $data["distributor_name"] ?? "A member";
                $corpName = $data["corporation_name"] ?? "your corporation";
                return "{$distributor} sent you \${$amount} from the {$corpName} slush fund.";

            case "corporation_reserve_deposit":
                $depositor = $data["depositor_name"] ?? "A member";
                $amount = number_format($data["amount"] ?? 0);
                $corpName = $data["corporation_name"] ?? "your corporation";
                return "{$depositor} deposited \${$amount} into {$corpName} reserves.";

            case "corporation_reserve_distribution":
                $amount = number_format($data["amount"] ?? 0);
                $distributor = $data["distributor_name"] ?? "A member";
                $corpName = $data["corporation_name"] ?? "your corporation";
                return "{$distributor} sent you \${$amount} from {$corpName} reserves.";

            case "corporation_ceo_transfer":
                $prev = $data["previous_ceo"] ?? "the previous CEO";
                $corpName = $data["corporation_name"] ?? "your corporation";
                return "You have been appointed CEO of {$corpName}. {$prev} stepped down.";

            case "corporation_ceo_transferred":
                $newCeo = $data["new_ceo"] ?? "a member";
                $corpName = $data["corporation_name"] ?? "your corporation";
                return "You transferred CEO of {$corpName} to {$newCeo}. You are now VP.";

            case "corporation_merger_proposed":
                $requester = $data["requester_name"] ?? "A CEO";
                $requesterCorp = $data["requester_corporation_name"] ?? "another company";
                $holdingName = $data["holding_name"] ?? "a new holding company";
                return "{$requester} of {$requesterCorp} has proposed a merger to form a Holding Company named  {$holdingName}. Go to your Boardroom to accept or decline.";

            case "corporation_merger_completed":
                $holdingName = $data["holding_name"] ?? "A holding company";
                return "{$holdingName} has been formed. The operating companies have been reorganized under the new board.";

            case "corporation_merger_cancelled":
                $holdingName = $data["holding_name"] ?? "the proposed holding company";
                return "The merger proposal for {$holdingName} was declined.";

            case "corporation_subsidiary_invite":
                $holdingName = $data["holding_name"] ?? "the holding company";
                $requester = $data["requester_name"] ?? "A board member";
                return "{$requester} has invited your company to become a subsidiary of {$holdingName}. Go to your Boardroom to accept or decline.";

            case "corporation_subsidiary_invite_accepted":
                $targetName = $data["target_corporation_name"] ?? "A company";
                $ceoName = $data["target_ceo_name"] ?? "its CEO";
                return "{$targetName} has accepted the subsidiary invitation. {$ceoName} is now pending a board handoff.";

            case "corporation_subsidiary_invite_declined":
                $targetName = $data["target_corporation_name"] ?? "The company";
                return "{$targetName} declined the subsidiary invitation.";

            case "corporation_board_promotion_pending":
                $holdingName = $data["holding_name"] ?? "the holding company";
                $successor = $data["successor_name"] ?? "your successor";
                return "The board of {$holdingName} has nominated you for a board seat. Complete your promotion to hand the company to {$successor}.";

            case "corporation_board_successor_named":
                $ceoName = $data["ceo_name"] ?? "your CEO";
                $subsidiaryName = $data["subsidiary_name"] ?? "your company";
                return "{$ceoName} has been nominated for the holding board. You have been named successor for {$subsidiaryName}.";



            case "corporation_board_successor_installed":
                $ceoName = $data["ceo_name"] ?? "the former CEO";
                $subsidiaryName = $data["subsidiary_name"] ?? "your company";
                return "{$ceoName} has joined the holding board. You now lead {$subsidiaryName}.";

            case "corporation_subsidiary_kicked":
                $holdingName = $data["holding_name"] ?? "the holding company";
                $subsidiaryName = $data["subsidiary_name"] ?? "a subsidiary";
                return "{$subsidiaryName} has been kicked out from the holding company {$holdingName}.";

            case "corporation_holding_collapsed":
                $holdingName = $data["holding_name"] ?? "The holding company";
                return "{$holdingName} has collapsed. Surviving board members have been returned to the corporation career pool.";

            case "corporation_trust_vote_opened":
                $holdingName = $data["holding_name"] ?? "the holding company";
                $initiator = $data["initiator_name"] ?? "A board member";
                return "{$initiator} has opened a Director of the Board vote for {$holdingName}.";

            case "corporation_trust_vote_completed":
                $holdingName = $data["holding_name"] ?? "the holding company";
                $winner = $data["winner_name"] ?? "A board member";
                return "{$winner} has been elected as the new Director of the Board, now presiding over {$holdingName} and all of its subsidiaries.";

            case "corporation_trust_vote_failed":
                $holdingName = $data["holding_name"] ?? "the holding company";
                return "The Director of the Board vote for {$holdingName} closed without a majority.";

            case "corporation_trust_created":
                $holdingName = $data["holding_name"] ?? "the holding company";
                $directorName = $data["director_name"] ?? "The Director of the Board";
                return "{$directorName} has taken control of {$holdingName} as Director of the Board.";

            case "corporation_dissolved":
                $corpName = $data["corporation_name"] ?? "Your corporation";
                return "{$corpName} has been dissolved. All operations have ceased.";

            case "corporation_member_quit":
                $member = $data["member_name"] ?? "A member";
                $corpName = $data["corporation_name"] ?? "your corporation";
                return "{$member} has left {$corpName}.";

            case "corporation_quit":
                $corpName = $data["corporation_name"] ?? "your corporation";
                return "You have left {$corpName}.";

            case "kidnapping_request":
                $initiatorName = $data['initiator_name'] ?? 'Someone';
                $targetName = $data['target_name'] ?? 'a mark';
                return "{$initiatorName} has brought you in on a kidnapping operation targeting {$targetName}. "
                    . "They have figured out his routes , secured a safe house, and need your hands on deck to pull this off. "
                    . "Accept if you are in — the window is short and he won't wait for you.";

            case "defense_request":
                $attorney = $data['attorney_name'] ?? 'An attorney';
                $crimeLabel = $data['crime_label'] ?? 'criminal charges';
                $severity = $data['severity'] ?? '';
                $fee = (int) ($data['fee'] ?? 1000);
                $prosecutorBy = $data['prosecutor_name'] ?? null;
                $sevStr = $severity ? " ({$severity})" : '';
                $prosStr = $prosecutorBy
                    ? " The prosecution is being led by {$prosecutorBy}."
                    : '';
                return "{$attorney} has offered to represent you on the charge of "
                    . "{$crimeLabel}{$sevStr}.{$prosStr} "
                    . 'You may accept their representation or decline and face the charges alone. '
                    . 'Their retainer is $' . number_format($fee) . ' and is paid immediately if you accept.';

            case "defense_outcome":
                return $data['message'] ?? 'The trial for your client has concluded.';

            case "defense_declined":
                return $data['message'] ?? 'The defendant has declined your representation offer.';

            case "defense_retained":
                $defendant = $data['defendant_name'] ?? 'Your client';
                $crimeLabel = $data['crime_label'] ?? 'the case';
                $fee = (int) ($data['fee'] ?? 0);
                $feeText = $fee > 0 ? ' The $' . number_format($fee) . ' retainer has been paid.' : '';
                return "{$defendant} retained you for {$crimeLabel}. Return to your defence docket to argue the case.{$feeText}";

            case "case_rewarded":
            case "case_sentenced":
            case "case_outcome":
            case "case_auto_charged":
            case "case_appeal_resolved":
            case "appeal_outcome":
            case "appeal_filed":
            case "case_closed":
            case "case_defense_request_expired":
                return $data['message'] ?? 'A case update has been recorded.';

            case "criminal_charges_filed":
                return $data['message'] ?? 'Formal criminal charges have been filed against you.';

            case "defense_accepted":
                $defendant = $data['defendant_name'] ?? 'Your client';
                $crimeLabel = $data['crime_label'] ?? 'the case';
                $outcome = $data['outcome'] ?? 'resolved';
                $outcomeText = $outcome === 'acquitted'
                    ? 'argued successfully — your client walks free.'
                    : 'argued the case but the evidence held — the case proceeds to sentencing.';
                return "{$defendant} accepted your representation on the charge of {$crimeLabel}. You {$outcomeText}";

            case "investment_fraud_request":
                $corpName = $data["corporation_name"] ?? "The Corporation";
                if ($this->cityNamePreloaded) {
                    $cityName = $this->preloadedCityName ?? "the city's";
                } else {
                    $city = \App\Models\City::find($data["city_id"] ?? 1);
                    $cityName = $city ? $city->name : "the city's";
                }
                return "Your CEO, {$data['ceo_name']} has initiated a scheme to issue fraudulent commercial paper through {$cityName} bank. They need your help in hatching the plan.";

            case "investment_fraud_result":
                return $data["message"] ?? "The operation concluded.";

            case "bank_fraud":
                return $data["message"] ?? "Unusual banking activity has drained funds from your account.";

            case "banker_launder_added":
                return $data["message"] ?? "A banker has added you as a laundering client.";

            case "banker_launder_cancelled":
                return $data["message"] ?? "A laundering arrangement has been cancelled.";



            case "banker_launder_executed":
                if (isset($data["message"]))
                    return $data["message"];
                $exClient = $data["client_name"] ?? "A client";
                $exAmt = number_format((int) ($data["amount"] ?? 0));
                $exCut = number_format((int) ($data["cut_amount"] ?? 0));
                return "The bank laundering operation for {$exClient} was successful. "
                    . "The amount of \${$exAmt} was laundered. The Bank earned \${$exCut}.";

            case "banker_launder_failed":
                return $data["message"] ?? "The bank laundering operation failed.";

            case "banker_launder_declined":
                return $data["message"] ?? "Your laundering offer was declined.";

            case "action_result":
                return $data["message"] ?? "The operation concluded.";

            case "action_victim":
                return $data["message"] ?? "You were targeted by a person.";

            case "action_ransomware_attack":
            case "action_crypto_rug_pull":
            case "action_kidnapping":
                return $data['message'] ?? 'You were targeted in an operation.';

            case "arrested":
                return $data['message'] ?? 'You were involved in an arrest.';

            case "cd_matured":
                $principal = number_format($data['principal'] ?? 0);
                $interest = number_format($data['interest'] ?? 0);
                $payout = number_format($data['payout'] ?? 0);
                $bankName = $data['bank_name'] ?? 'your bank';
                return "Your certificate of deposit at {$bankName} has matured. "
                    . "You have recieved the full amount of \${$payout} in your account.";

            case "cd_defaulted":
                $principal = number_format($data['principal'] ?? 0);
                $payout = number_format($data['payout'] ?? 0);
                $bankName = $data['bank_name'] ?? 'your bank';
                return "Your certificate of deposit at {$bankName} could not be honoured in full. "
                    . "The bank had insufficient capital. You recovered \${$payout} of your \${$principal} principal.";

            case "dismissed_by":
                $dismissedBy = $data['dismissed_by'] ?? 'The commissioner';
                $dismisserRank = $data['dismisser_rank_name'] ?? 'The commissioner';
                $careerName = $data['career_name'] ?? 'your career';
                return "{$dismisserRank} {$dismissedBy} has dismissed you from the {$careerName}. You are no longer employed.";

            case "mugging":
                return $data['message'] ?? 'You were mugged in the streets.';

            case "ngri_success":
                return $data['message'] ?? 'A physician successfully filed an insanity plea on your behalf and had your case dismissed.';

            case "hospital_treated":
                return $data['message'] ?? 'A healthcare worker treated you and reduced your recovery time.';

            case "hospital_surgery":
                return $data['message'] ?? 'A surgeon performed an operation and fully discharged you.';

            case "hospital_gender_reassignment":
                return $data['message'] ?? 'A gender reassignment procedure was completed on your behalf.';

            case 'career_step_down':
                return $data['message'] ?? 'You have voluntarily stepped down from your position. Your duty has been noted.';

            case 'relocation_approved':
                $cityName = $data['city_name'] ?? 'the city';
                return "Your application to relocate to {$cityName} has been verified and approved by City Hall. Welcome to your new home.";

            case 'relocation_denied':
                $cityName = $data['city_name'] ?? 'the city';
                return "Your application to relocate to {$cityName} was denied by the City Hall. You may apply again later or wait for an automatic transfer if the office becomes vacant.";


            case 'audit_initiated':
            case 'audit_success':
            case 'audit_target':
            case 'pardoned':
            case 'bond_matured':
            case 'bond_defaulted':
                return $data['message'] ?? 'A mayoral action has been recorded.';

            case 'bomb_plant_failed': {
                $result = $data['result'] ?? 'failed_plant';
                $attackerName = $data['attacker_name'] ?? null;

                if ($result === 'detonator_destroyed') {
                    $ownerName = $attackerName ?? 'Someone';
                    return "{$ownerName}'s home was leveled in an explosion, and the authorities discovered a charred detonator in the rubble, luckily the authorities figured out the detonator was for your home and were able to rush over and disarm the bomb.";
                }


                if ($attackerName) {
                    return "{$attackerName} was caught red-handed trying to plant an explosive device on your property. The device went off in their hands before they could arm it properly — What great luck for you.";
                }
                return "An intruder was detected attempting to plant an explosive device on your property. They were repelled — What great luck for you.";
            }

            case 'bomb_detonated': {
                $result = $data['result'] ?? 'unknown';
                $attackerName = $data['attacker_name'] ?? 'An unknown assailant';
                $propertyName = $data['property_name'] ?? 'your property';
                $healthLost = $data['health_lost'] ?? 0;
                $maxHealthLost = $data['max_health_lost'] ?? 0;
                $itemsLost = (int) ($data['items_lost'] ?? 0);

                $itemsText = $itemsLost > 0
                    ? ' You also lost some of the items you had stored in the property.'
                    : '';

                if ($result === 'not_home') {
                    return "{$attackerName} remotely detonated an IED that had been planted on your {$propertyName}. You weren't home when it went off — small mercies. Your {$propertyName} has been reduced to rubble.{$itemsText}";
                }

                if ($result === 'home_offline') {
                    $injuryText = $maxHealthLost > 0 ? " You sustained a permanent injury (-{$maxHealthLost} max HP)." : '';
                    return "{$attackerName} detonated a device that had been planted inside your {$propertyName} while you were home. The blast ripped through the building and took {$healthLost} of your HP with it.{$injuryText}{$itemsText}";
                }

                if ($result === 'home_online') {
                    $injuryText = $maxHealthLost > 0 ? " You sustained a permanent injury (-{$maxHealthLost} max HP)." : '';
                    return "{$attackerName} waited until you were inside and detonated  a  bomb planted in your {$propertyName}. The explosion caught you square in the blast — {$healthLost} HP.{$injuryText}{$itemsText}";
                }

                if ($result === 'home_safe_haven') {
                    $reason = $this->character->isJailed() ? 'behind bars' : 'in the hospital';
                    return "{$attackerName} detonated a device at your {$propertyName} while you were safely {$reason}. You escaped physical harm, but  your stored possessions were obliterated.{$itemsText}";
                }

                return "{$attackerName} detonated an explosive device planted on your {$propertyName}.{$itemsText}";
            }

            case 'revived':
                return $data['message'] ?? 'A surgeon has brought you back from the dead. You are alive — barely.';

            case 'busted_at_customs':
                return $data['message'] ?? ' You were caught by a customs agent and your undeclared cash was seized.';




            case 'organized_hit_invite':
                $initiatorName = $data['initiator_name'] ?? 'Someone';
                $targetName = $data['target_name'] ?? 'a mark';
                $memberNames = $data['member_names'] ?? [];
                $others = collect($memberNames)
                    ->filter(fn($n) => $n !== $initiatorName)
                    ->values();

                return "{$initiatorName} is putting together a crew to kill {$targetName}. "
                    . "Head to the Conflict page to accept or decline — the window is short.";

            case 'organized_attack_received':
                $names = $data['attacker_names'] ?? ['Unknown assailants'];
                $result = $data['result'] ?? 'hit';
                $damage = $data['damage'] ?? 0;
                $weapon = $data['weapon_used'] ?? 'bare hands';
                $customMessage = $data['custom_message'] ?? '';

                $nameList = count($names) > 1
                    ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names)
                    : ($names[0] ?? 'Unknown assailants');
                $msg = "{$nameList} managed to ambush you on your daily route, hitting you with their {$weapon} for {$damage} damage.";
                if (!empty($data['was_critical'])) {
                    $msg = "Critical hit! " . $msg;
                }
                if (!empty($data['max_health_lost']) && $data['max_health_lost'] > 0) {
                    $msg .= " You suffered a permanent injury (-{$data['max_health_lost']} max HP).";
                }
                if (!empty($customMessage)) {
                    $msg .= " They said: \"{$customMessage}\"";
                }
                return $msg;

            case 'organized_hit_accepted':
                $name = $data['accomplice_name'] ?? 'A crew member';
                $target = $data['target_name'] ?? 'the mark';
                $allReady = !empty($data['all_ready']);
                $base = "{$name} has confirmed the operation against {$target}.";
                return $allReady
                    ? "{$base} Your full crew is assembled — you have one hour to execute."
                    : "{$base} Waiting for the remaining crew to confirm.";

            case 'organized_hit_declined':
                $name = $data['accomplice_name'] ?? 'A crew member';
                $target = $data['target_name'] ?? 'the mark';
                return "{$name} declined the invitation. The operation against {$target} has been called off.";

            case 'organized_hit_warning':
                return "Something odd is going on, you notice some shifty eyes and glances your way, people seem to be going out of their way to avoid you and your usual daily route is blocked, forcing you to take a detour. Stay vigilant.";

            case 'organized_hit_collapsed':
                return $data['message'] ?? 'The organized hit has been called off.';

            case 'organized_hit_result':
                return $data['message'] ?? 'The operation has concluded.';

            case 'corporation_move_completed': {
                $corpName = $data['corporation_name'] ?? 'your corporation';
                $fromCity = $data['from_city_name'] ?? 'the old city';
                $toCity = $data['to_city_name'] ?? 'a new city';
                $agent = $data['agent_name'] ?? 'a customs agent';
                return "Customs agent {$agent} approved the relocation of {$corpName} from {$fromCity} to {$toCity}. Your headquarters now operates out of the new city.";
            }

            case 'corporation_move_denied': {
                $corpName = $data['corporation_name'] ?? 'your corporation';
                $fromCity = $data['from_city_name'] ?? 'your home city';
                $toCity = $data['to_city_name'] ?? 'the destination';
                $agent = $data['agent_name'] ?? 'a customs agent';
                $refund = number_format((int) ($data['refund'] ?? 0));
                return "Customs agent {$agent} denied {$corpName}'s relocation from {$fromCity} to {$toCity}. The escrowed fee of \${$refund} has been refunded to cash reserves.";
            }

            case 'journal_shared':
                $sender = $data['sender_name'] ?? 'Someone';
                $desc = $data['orig_desc'] ?? '';
                return "{$sender} shared this with you." . ($desc ? "\n\n{$desc}" : '');

            default:
                return 'New activity logged.';
        }
    }


    public function getIconAttribute(): string
    {
        $data = $this->data;
        $type = $this->type;

        switch ($type) {
            case "attack_received":
                $result = $data["result"] ?? "unknown";
                if ($result === "hospitalized")
                    return "Skull";
                if ($result === "gbh_miss")
                    return "Hammer";
                if ($result === "hit")
                    return isset($data["max_health_lost"]) ? "Skull" : "Sword";
                return "Shield";

            case "money_transfer_received":
                return "ArrowCircleDown";
            case "promotion_achieved":
                return "Crown";
            case "business_acquisition_complete":
                return "Trophy";
            case "item_sale_request":
            case "corporate_medicine_sale_request":
            case "corporate_mirror_transaction_request":
                return "ShoppingBag";
            case "item_sold":
            case "corporate_medicine_sold":
                return "PillIcon";
            case "corporate_mirror_transaction_accepted":
                return "Handshake";
            case "corporate_mirror_transaction_completed":
                return "Vault";
            case "corporate_mirror_transaction_declined":
                return "Files";
            case "achievement_unlocked":
                return "Trophy";
            case "vehicle_repaired":
                return "Wrench";
            case "home_inspected":
                return "House";
            case "home_repaired":
                return "Wrench";
            case "mayor_term_expired":
                return "Clock";
            case "mayor_removed":
                return "BootIcon";
            case "corporation_kicked":
                return "UserMinus";
            case "corporation_merger_proposed":
            case "corporation_merger_completed":
            case "corporation_merger_cancelled":
            case "corporation_subsidiary_invite":
            case "corporation_subsidiary_invite_accepted":
            case "corporation_subsidiary_invite_declined":
            case "corporation_board_promotion_pending":
            case "corporation_board_successor_named":
            case "corporation_board_successor_installed":
            case "corporation_subsidiary_kicked":
            case "corporation_holding_collapsed":
            case "corporation_trust_vote_opened":
            case "corporation_trust_vote_completed":
            case "corporation_trust_vote_failed":
            case "corporation_trust_created":
                return "Buildings";
            case "corporation_ceo_transferred":
                return "ArrowsClockwise";
            case "corporation_member_quit":
            case "corporation_quit":
                return "SignOut";
            case "bank_fraud":
                return "Warning";

            case "election_result":
                return ($data["won"] ?? false) ? "Crown" : "Scales";

            case "election_city_result":
                return "Crown";

            case "election_disqualified":
                return "Scales";

            case "corporation_founded":
            case "corporation_dissolved":
                return "Buildings";

            case "corporation_invite_request":
            case "corporation_member_joined":
            case "corporation_joined":
                return "Buildings";

            case "corporation_position_assigned":
            case "corporation_ceo_transfer":
                return "Crown";

            case "demoted":
                return "UserMinus";

            case "corporation_deposit":
            case "corporation_distribution":
            case "corporation_reserve_deposit":
            case "corporation_reserve_distribution":
                return "Vault";

            case "kidnapping_request":
                return "Detective";
            case "criminal_charges_filed":
                return "Scales";
            case "defense_request":
                return "Scales";
            case "defense_outcome":
                return ($data['outcome'] ?? '') === 'acquitted' ? 'Shield' : 'Scales';
            case "defense_declined":
                return "Warning";
            case "defense_retained":
                return "Scales";
            case "case_rewarded":
                return "CurrencyDollar";
            case "case_sentenced":
                return "Gavel";
            case "case_outcome":
                return ($data['outcome'] ?? '') === 'acquitted' ? 'ShieldCheck' : 'Gavel';
            case "case_auto_charged":
                return "Scales";
            case "case_appeal_resolved":
                return "Scales";
            case "appeal_outcome":
                return ($data['outcome'] ?? '') === 'acquitted' ? 'ShieldCheck' : 'Gavel';
            case "appeal_filed":
                return "Scales";
            case "case_closed":
                return "CheckCircle";
            case "case_defense_request_expired":
                return "Clock";
            case "defense_accepted":
                return "Scales";
            case "investment_fraud_request":
                return "Files";

            case "corporation_property_constructed":
            case "corporation_move_completed":
            case "corporation_move_denied":
                return "Buildings";

            case "investment_fraud_result":
                return ($data["status"] ?? "failed") === "success" ? "Vault" : "PoliceCar";

            case "banker_launder_added":
                return "Vault";
            case "banker_launder_cancelled":
                return "Warning";

            case "banker_launder_executed":
                return "CurrencyDollar";
            case "banker_launder_failed":
                return "Warning";
            case "banker_launder_declined":
                return "Files";



            case "action_result":
                return ($data["status"] ?? "failed") === "success" ? "CurrencyDollar" : "PoliceCar";

            case "action_victim":
                return match ($data["status"] ?? "unknown") {
                    "success" => "CurrencyDollar",
                    "failure" => "Shield",
                    default => "Warning",
                };

            case "action_ransomware_attack":
                return ($data['status'] ?? 'failure') === 'success' ? 'CurrencyDollar' : 'Desktop';

            case "action_crypto_rug_pull":
                return ($data['status'] ?? 'failure') === 'success' ? 'CurrencyBtc' : 'Shield';

            case "action_kidnapping":
                return ($data['status'] ?? 'failure') === 'success' ? 'Van' : 'Shield';

            case "arrested":
                return ($data['status'] ?? 'success') === 'success' ? 'PoliceCar' : 'Shield';

            case "cd_matured":
                return "Vault";

            case "cd_defaulted":
                return "Warning";

            case "dismissed_by":
                return "UserMinus";

            case "mugging":
                return "HandGrabbingIcon";

            case "ngri_success":
                return "Scales";

            case "hospital_treated":
            case "hospital_surgery":
            case "hospital_gender_reassignment":
                return "Heartbeat";

            case 'career_step_down':
                return 'SignOut';

            case 'relocation_approved':
                return 'SealCheck';

            case 'relocation_denied':
                return 'Newspaper';


            case 'audit_initiated':
            case 'audit_success':
                return 'Buildings';

            case 'audit_target':
                return 'Warning';

            case 'pardoned':
                return 'Handshake';

            case 'bond_matured':
                return 'TrendUp';

            case 'bond_defaulted':
                return 'Warning';



            case 'bomb_plant_failed':
                return 'Shield';

            case 'bomb_detonated':
                return 'Fire';

            case 'revived':
                return 'Heartbeat';

            case 'busted_at_customs':
                return 'SuitcaseRollingIcon';



            case 'organized_hit_invite':
                return 'EnvelopeIcon';

            case 'organized_hit_accepted':
                return 'CheckCircle';

            case 'organized_hit_declined':
                return 'HandPalmIcon';

            case 'organized_hit_warning':
                return 'EyesIcon';

            case 'organized_hit_collapsed':
                return 'ProhibitIcon';

            case 'organized_hit_result':
                return 'Skull';

            case 'organized_attack_received':
                return 'Skull';
            case 'journal_shared':
                return $data['orig_icon'] ?? 'Article';

            default:
                return 'Calendar';
        }
    }




    public function getColorClassAttribute(): string
    {
        $data = $this->data;
        $type = $this->type;

        switch ($type) {
            case "attack_received":
                $result = $data["result"] ?? "unknown";
                if ($result === "hospitalized") {
                    return "bg-gradient-to-br from-red-900/60 to-red-800/40 border-red-600/60 shadow-[0_0_10px_rgba(220,38,38,0.3)]";
                } elseif ($result === "gbh_miss") {
                    return "bg-gradient-to-br from-slate-800/40 to-slate-700/20 border-slate-600/40";
                } elseif ($result === "hit") {
                    $classes = "bg-attack-damage animate-blood-pulse blood-drip-container border-red-500/50";
                    $critical = !empty($data["was_critical"]) && $data["was_critical"] === true;
                    if ($critical)
                        $classes .= " animate-critical-glitch";
                    return $classes;
                }
                return "bg-gradient-to-br from-red-900/70 to-orange-950/50 border-red-500/60 shadow-[0_0_16px_rgba(239,68,68,0.2)]";

            case "action_result":
                return ($data["status"] ?? "failed") === "success"
                    ? "bg-gradient-to-br from-emerald-900/40 to-emerald-800/30 border-emerald-600/60"
                    : "bg-gradient-to-br from-rose-900/40 to-rose-800/30 border-rose-600/60";

            case "action_victim":
                return ($data["status"] ?? 'failure') === 'success'
                    ? 'bg-gradient-to-br from-rose-900/40 to-rose-800/30 border-rose-600/60'
                    : 'bg-gradient-to-br from-slate-800/40 to-slate-700/20 border-slate-600/40';

            case "action_ransomware_attack":
            case "action_crypto_rug_pull":
            case "action_kidnapping":
                return ($data['status'] ?? 'failure') === 'success'
                    ? 'bg-gradient-to-br from-rose-900/40 to-rose-800/30 border-rose-600/60'
                    : 'bg-gradient-to-br from-slate-800/40 to-slate-700/20 border-slate-600/40';

            case "money_transfer_received":
                return "bg-gradient-to-br from-emerald-900/30 to-emerald-800/20 border-emerald-600/40";

            case "promotion_achieved":
                return "bg-gradient-to-br from-emerald-800/50 to-emerald-700/40 border-emerald-600/60 animate-soft-pulse";

            case "business_acquisition_complete":
                return "bg-gold-acquisition";

            case "item_sale_request":
            case "corporate_medicine_sale_request":
                return "bg-gradient-to-br from-emerald-900/40 to-emerald-800/30 border-emerald-600/60";

            case "item_sold":
            case "corporate_medicine_sold":
                return "bg-gradient-to-br from-emerald-900/30 to-emerald-800/20 border-emerald-600/40";

            case "achievement_unlocked":
                return "bg-achievement-gold animate-achievement-sparkle border-yellow-500/60";

            case "vehicle_repaired":
                return "bg-gradient-to-br from-cyan-900/30 to-cyan-800/20 border-cyan-600/40";

            case "home_inspected":
                return "bg-gradient-to-br from-cyan-900/30 to-cyan-800/20 border-cyan-600/40";

            case "corporation_property_constructed":
            case "corporation_move_completed":
                return "bg-gradient-to-br from-cyan-900/30 to-cyan-800/20 border-cyan-600/40";

            case "corporation_move_denied":
                return "bg-gradient-to-br from-amber-900/45 to-amber-800/25 border-amber-500/45";

            case "home_repaired":
                return empty($data['failed'])
                    ? "bg-gradient-to-br from-amber-900/40 to-amber-800/20 border-amber-600/40"
                    : "bg-gradient-to-br from-red-950/60 to-red-900/30 border-red-700/50";

            case "election_result":
                return ($data["won"] ?? false)
                    ? "bg-gradient-to-br from-emerald-900/40 to-emerald-800/30 border-emerald-600/60"
                    : "bg-gradient-to-br from-slate-800/40 to-slate-700/20 border-slate-600/40";

            case "election_city_result":
                return "bg-gradient-to-br from-cyan-900/30 to-cyan-800/20 border-cyan-600/40";

            case "mayor_term_expired":
                return "bg-gradient-to-br from-slate-800/40 to-slate-700/20 border-slate-600/40";

            case "mayor_removed":
                return "bg-gradient-to-br from-amber-900/60 to-amber-800/40 border-amber-500/60 shadow-[0_0_12px_rgba(245,158,11,0.2)]";

            case "corporation_founded":
                return "bg-gold-acquisition";

            case "corporation_invite_request":
            case "corporation_member_joined":
            case "corporation_joined":
                return "bg-gradient-to-br from-slate-800/40 to-slate-700/20 border-slate-600/40";

            case "corporation_ceo_transfer":
            case "corporation_position_assigned":
                return "bg-gradient-to-br from-cyan-900/30 to-cyan-800/20 border-cyan-600/40";

            case "demoted":
                return "bg-gradient-to-br from-amber-900/45 to-amber-800/25 border-amber-500/45";

            case "corporation_deposit":
            case "corporation_distribution":
            case "corporation_reserve_deposit":
            case "corporation_reserve_distribution":
                return "bg-gradient-to-br from-amber-300/20 to-amber-200/10 border-amber-500/30 shadow-[0_0_8px_rgba(245,158,11,0.08)]";

            case "corporation_kicked":
            case "corporation_dissolved":
                return "bg-gradient-to-br from-rose-900/70 to-rose-800/50 border-rose-500/50 shadow-[0_0_12px_rgba(244,63,94,0.15)]";

            case "corporation_ceo_transferred":
            case "corporation_member_quit":
            case "corporation_quit":
                return "bg-gradient-to-br from-slate-800/40 to-slate-700/20 border-slate-600/40";

            case "corporation_merger_proposed":
                return "bg-gradient-to-br from-cyan-900/30 to-cyan-800/20 border-cyan-600/40";

            case "corporation_merger_completed":
                return "bg-gold-acquisition";

            case "corporation_merger_cancelled":
            case "corporation_subsidiary_invite":
            case "corporation_subsidiary_invite_accepted":
            case "corporation_subsidiary_invite_declined":
            case "corporation_subsidiary_kicked":
            case "corporation_holding_collapsed":
            case "corporation_trust_vote_opened":
                return "bg-gradient-to-br from-slate-800/40 to-slate-700/20 border-slate-600/40";

            case "corporation_trust_vote_completed":
            case "corporation_trust_created":
                return "bg-gold-acquisition";

            case "corporation_trust_vote_failed":
                return "bg-gradient-to-br from-rose-900/70 to-rose-800/50 border-rose-500/50 shadow-[0_0_12px_rgba(244,63,94,0.15)]";

            case "corporation_board_promotion_pending":
            case "corporation_board_successor_named":
            case "corporation_board_successor_installed":
                return "bg-gradient-to-br from-amber-900/30 to-yellow-800/20 border-amber-500/40";

            case "kidnapping_request":
                return "bg-gradient-to-br from-red-900/80 to-red-800/60 border-red-500/50 shadow-[0_0_15px_rgba(239,68,68,0.15)]";



            case "criminal_charges_filed":
            case "defense_request":
            case "case_auto_charged":
            case "case_defense_request_expired":
                return "bg-gradient-to-br from-amber-900/60 to-amber-800/40 border-amber-500/60 shadow-[0_0_12px_rgba(245,158,11,0.2)]";

            case "case_sentenced":
                return "bg-gradient-to-br from-rose-900/60 to-rose-800/40 border-rose-500/60 shadow-[0_0_10px_rgba(244,63,94,0.2)]";

            case "case_outcome":
                $outcome = $data['outcome'] ?? '';
                if ($outcome === 'acquitted') {
                    return 'bg-gradient-to-br from-emerald-900/40 to-emerald-800/30 border-emerald-600/60';
                }
                if ($outcome === 'convicted') {
                    return 'bg-gradient-to-br from-rose-900/60 to-rose-800/40 border-rose-500/60 shadow-[0_0_10px_rgba(244,63,94,0.2)]';
                }
                return 'bg-gradient-to-br from-slate-800/40 to-slate-700/20 border-slate-600/40';

            case "appeal_outcome":
                $upheld = ($data['outcome'] ?? '') === 'sentenced';
                return $upheld
                    ? 'bg-gradient-to-br from-rose-900/60 to-rose-800/40 border-rose-500/60 shadow-[0_0_10px_rgba(244,63,94,0.2)]'
                    : 'bg-gradient-to-br from-emerald-900/40 to-emerald-800/30 border-emerald-600/60';

            case "appeal_filed":
                return "bg-gradient-to-br from-yellow-900/40 to-yellow-800/20 border-yellow-600/40 shadow-[0_0_8px_rgba(234,179,8,0.1)]";

            case "case_rewarded":
                return "bg-gradient-to-br from-emerald-900/30 to-emerald-800/20 border-emerald-600/40";

            case "case_closed":
                return "bg-gradient-to-br from-slate-800/40 to-slate-700/20 border-slate-600/40";

            case "defense_accepted":
                $outcome = $data['outcome'] ?? 'convicted';
                return $outcome === 'acquitted'
                    ? 'bg-gradient-to-br from-emerald-900/40 to-emerald-800/30 border-emerald-600/60'
                    : 'bg-gradient-to-br from-rose-900/60 to-rose-800/40 border-rose-500/60 shadow-[0_0_10px_rgba(244,63,94,0.2)]';

            case "defense_declined":
                return "bg-gradient-to-br from-slate-800/40 to-slate-700/20 border-slate-600/40";

            case "investment_fraud_request":
                return "bg-gradient-to-br from-red-900/80 to-red-800/60 border-red-500/50 shadow-[0_0_15px_rgba(239,68,68,0.15)]";

            case "banker_launder_added":
                return "bg-gradient-to-br from-amber-900/50 to-amber-800/30 border-amber-500/40 shadow-[0_0_10px_rgba(245,158,11,0.12)]";

            case "banker_launder_cancelled":
                return "bg-gradient-to-br from-slate-800/50 to-slate-700/30 border-slate-500/40";



            case "banker_launder_executed":
                return "bg-gradient-to-br from-emerald-900/40 to-emerald-800/30 border-emerald-600/60";

            case "banker_launder_failed":
                return "bg-gradient-to-br from-red-900/40 to-red-800/30 border-red-600/60";

            case "banker_launder_declined":
                return "bg-gradient-to-br from-slate-800/40 to-slate-700/20 border-slate-600/40";

            case "corporate_mirror_transaction_request":
                return "bg-gradient-to-br from-cyan-900/50 to-slate-900/50 border-cyan-500/40 shadow-[0_0_10px_rgba(34,211,238,0.12)]";

            case "corporate_mirror_transaction_accepted":
                return "bg-gradient-to-br from-blue-900/40 to-slate-900/40 border-blue-500/40";

            case "corporate_mirror_transaction_completed":
                return "bg-gradient-to-br from-emerald-900/40 to-emerald-800/30 border-emerald-600/60";

            case "corporate_mirror_transaction_declined":
                return "bg-gradient-to-br from-slate-800/40 to-slate-700/20 border-slate-600/40";

            case "investment_fraud_result":
                return ($data["status"] ?? "failed") === "success"
                    ? "bg-gradient-to-br from-emerald-900/40 to-emerald-800/30 border-emerald-600/60"
                    : "bg-gradient-to-br from-red-900/40 to-red-800/30 border-red-600/60";

            case "bank_fraud":
                return "bg-gradient-to-br from-red-900/40 to-red-800/30 border-red-600/60";

            case "arrested":
                return ($data['status'] ?? 'success') === 'success'
                    ? 'bg-gradient-to-br from-rose-900/60 to-rose-800/40 border-rose-500/60 shadow-[0_0_10px_rgba(244,63,94,0.2)]'
                    : 'bg-gradient-to-br from-slate-800/40 to-slate-700/20 border-slate-600/40';

            case "cd_matured":
                return "bg-gradient-to-br from-emerald-900/40 to-emerald-800/30 border-emerald-600/60";

            case "cd_defaulted":
                return "bg-gradient-to-br from-red-900/40 to-red-800/30 border-red-600/60";

            case "dismissed_by":
                return "bg-gradient-to-br from-red-900/40 to-red-800/30 border-red-600/60";

            case "mugging":
                return "bg-gradient-to-br from-rose-900/60 to-rose-800/40 border-rose-500/60 shadow-[0_0_10px_rgba(244,63,94,0.2)]";

            case "ngri_success":
                return "bg-gradient-to-br from-emerald-900/40 to-emerald-800/30 border-emerald-600/60";

            case "hospital_treated":
            case "hospital_surgery":
            case "hospital_gender_reassignment":
                return "bg-gradient-to-br from-emerald-900/30 to-emerald-800/20 border-emerald-600/40";

            case 'career_step_down':
                return 'bg-gradient-to-br from-slate-800/40 to-slate-700/20 border-slate-600/40';

            case 'relocation_approved':
                return 'bg-gradient-to-br from-emerald-900/40 to-emerald-800/30 border-emerald-600/60';

            case 'relocation_denied':
                return 'bg-gradient-to-br from-red-900/40 to-red-800/30 border-red-600/60';


            case 'audit_initiated':
            case 'audit_success':
                return 'bg-gradient-to-br from-cyan-900/30 to-cyan-800/20 border-cyan-600/40';

            case 'audit_target':
                return 'bg-gradient-to-br from-amber-900/60 to-amber-800/40 border-amber-500/60 shadow-[0_0_12px_rgba(245,158,11,0.2)]';

            case 'pardoned':
                return 'bg-gradient-to-br from-emerald-900/40 to-emerald-800/30 border-emerald-600/60';

            case 'bond_matured':
                return 'bg-gradient-to-br from-emerald-900/40 to-emerald-800/30 border-emerald-600/60';

            case 'bond_defaulted':
                return 'bg-gradient-to-br from-red-900/40 to-red-800/30 border-red-600/60';



            case 'bomb_plant_failed':
                return ($data['result'] ?? '') === 'detonator_destroyed'
                    ? 'bg-gradient-to-br from-slate-800/40 to-slate-700/20 border-slate-600/40'
                    : 'bg-gradient-to-br from-amber-900/60 to-amber-800/40 border-amber-500/60 shadow-[0_0_12px_rgba(245,158,11,0.2)]';

            case 'bomb_detonated':
                return 'bg-gradient-to-br from-red-900/70 to-orange-950/50 border-red-500/60 shadow-[0_0_16px_rgba(239,68,68,0.2)]';

            case 'revived':
                return 'bg-gradient-to-br from-emerald-900/60 to-teal-900/40 border-emerald-500/50 shadow-[0_0_12px_rgba(16,185,129,0.2)]';



            case 'busted_at_customs':
                return 'bg-gradient-to-br from-yellow-900/60 to-yellow-800/40 border-yellow-500/60 shadow-[0_0_10px_rgba(244,63,94,0.2)]';






            case 'organized_hit_invite':
                return 'bg-gradient-to-br from-yellow-900/60 to-yellow-800/40 border-yellow-500/60 shadow-[0_0_10px_rgba(244,63,94,0.2)]';

            case 'organized_hit_accepted':
                return 'bg-gradient-to-br from-emerald-900/40 to-emerald-800/30 border-emerald-600/60';

            case 'organized_hit_declined':
                return 'bg-gradient-to-br from-slate-800/40 to-slate-700/20 border-slate-600/40';

            case 'organized_hit_warning':
                return 'bg-gradient-to-br from-amber-900/60 to-amber-800/40 border-amber-500/60 shadow-[0_0_12px_rgba(245,158,11,0.2)]';

            case 'organized_hit_collapsed':
                return 'bg-gradient-to-br from-slate-800/50 to-slate-700/30 border-slate-500/40';

            case 'organized_hit_result':
                return 'bg-gradient-to-br from-red-900/70 to-orange-950/50 border-red-500/60 shadow-[0_0_16px_rgba(239,68,68,0.2)]';

            case 'organized_attack_received':
                return 'bg-attack-damage animate-blood-pulse blood-drip-container border-red-500/50 animate-critical-glitch';
            case 'journal_shared':

                return $data['orig_color'] ?? 'bg-slate-800/20 border-slate-700/30';

            default:
                return 'bg-slate-800/20 border-slate-800/30';
        }
    }




    public function isRequest(): bool
    {
        return str_ends_with($this->type, "_request");
    }

    protected function resolveActorId(): mixed
    {
        return match ($this->type) {
            "item_sale_request" => $this->data["seller_id"] ?? null,
            "corporate_medicine_sale_request" => $this->data["seller_id"] ?? null,
            "corporate_mirror_transaction_request" => $this->data["cfo_id"] ?? null,
            "corporation_invite_request" => $this->data["inviter_id"] ?? null,
            "investment_fraud_request" => $this->data["ceo_id"] ?? null,
            "defense_request" => $this->data["attorney_id"] ?? null,
            "demoted" => $this->data["actor_id"] ?? null,
            default => null,
        };
    }

    public function getActorAvatarAttribute(): ?string
    {
        if ($this->actorAvatarPreloaded) {
            return $this->preloadedActorAvatar;
        }

        $actorId = $this->resolveActorId();

        if (!$actorId) {
            return null;
        }

        return Character::find($actorId)?->avatar_url;
    }

    public function getItemImageAttribute(): ?string
    {
        return in_array($this->type, ["item_sale_request", "corporate_medicine_sale_request"], true)
            ? ($this->data["item_image_url"] ?? null)
            : null;
    }
}
