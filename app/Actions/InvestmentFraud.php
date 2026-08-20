<?php

namespace App\Actions;

use App\Models\Business;
use App\Models\Character;
use App\Models\CharacterJournal;
use App\Models\City;
use App\Models\Corporation;
use App\Models\CorporationProperty;
use App\Services\JournalService;
use App\Services\CrimeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
//TODO add earned_actions to character history
class InvestmentFraud extends Action
{
    private const FEE = 50000;
    private const CACHE_TTL = 1200;
    private const MAX_PARTICIPANTS = 5;
    private const MIN_TO_EXECUTE = 2;

    public function getId(): string
    {
        return 'investment_fraud';
    }
    public function getIcon(): string
    {
        return 'Buildings';
    }
    public function getButtonLabel(): string
    {
        return 'Execute Fraud';
    }
    public function getGroupInitLabel(): ?string
    {
        return 'Initiate Conspiracy';
    }

    public function isGroupAction(): bool
    {
        return true;
    }

    public function getShape(Character $character): array
    {
        $corp = $character->corporation;
        $isWaiting = false;
        $isReady = false;
        $activeMembers = null;

        if ($corp) {
            $corp->loadMissing('city');
            $cacheData = $this->getState($corp);

            if ($cacheData) {
                if (($cacheData['status'] ?? '') === 'ready_for_execution') {
                    $isReady = true;
                } else {
                    $isWaiting = true;
                }

                $participantMap = Character::whereIn('id', $cacheData['required_members'])
                    ->get(['id', 'display_name'])
                    ->keyBy('id');

                $activeMembers = array_map(fn(int $id) => [
                    'id' => $id,
                    'name' => $participantMap->get($id)?->display_name ?? 'Unknown',
                    'accepted' => in_array($id, $cacheData['accepted_by']),
                ], $cacheData['required_members']);
            }
        }

        $isCeo = $corp && $corp->isCeo($character);
        $cancelRoute = ($isCeo && ($isWaiting || $isReady))
            ? route('career.corporation.actions.investment-fraud.cancel')
            : null;

        // Only the CEO can see the execute button — invited members see the
        // waiting state only, not the execute route or ready trigger.
        $executeRoute = ($isCeo && $isReady)
            ? route('career.corporation.actions.investment-fraud')
            : ($isWaiting ? null : route('career.corporation.actions.investment-fraud'));

        $cityName = $corp?->city?->name ?? 'the city';

        return [
            'id' => $this->getId(),
            'title' => 'Investment Fraud',
         
            'category' => 'Corporate Operations',
            'description' => "Coordinate with your company employees  to issue fraudulent commercial paper through {$cityName} bank, then convince them to aggressively sell it to retail depositors as high-yield savings. Requires \$50,000 from the corporate slush fund.",
            'image_url' => 'https://images.thedirector.app/actions/investment.jpg',
            'icon' => $this->getIcon(),
            'button_label' => $this->getButtonLabel(),
            'group_init_label' => $this->getGroupInitLabel(),
            'execute_route' => $executeRoute,
            'cancel_route' => $cancelRoute,
            'is_group_action' => true,
            'available' => true,
            'blocker' => null,
            'is_waiting' => $isWaiting,
            'is_ready' => $isCeo && $isReady,
            'active_members' => $activeMembers,
            'targets' => null,
            'accomplices' => null,
            'has_amount_input' => false,
            'amount_label' => null,
            'pick_label' => null,
            'target_icon' => null,
        ];
    }

    public function canExecute(Character $character): array
    {


        if (!$character->corporation) {
            return ['valid' => false, 'error' => 'You must be in a corporation to orchestrate this level of fraud.'];
        }

        $corp = $character->corporation;

        if (!$corp) {
            return ['valid' => false, 'error' => 'You must be in a corporation to orchestrate this level of fraud.'];
        }

       
        if ($corp->is_holding_company) {
            return ['valid' => false, 'error' => 'Your holding company is too sophisticated for such a crude scheme.'];
        }

        if (!$corp->isCeo($character)) {
            return ['valid' => false, 'error' => 'Only the CEO holds the authority to initiate this action.'];
        }



        if (($character->career_rank ?? 0) < 2) {
            return ['valid' => false, 'error' => 'You must be rank 2 to engage in such a scheme.'];
        }
        if ($character->city_id !== $corp->home_city_id) {
            $hqCity = $corp->city?->name ?? 'your corporation\'s headquarters';
            return ['valid' => false, 'error' => "You must be in {$hqCity} to carry out this operation."];
        }
        if ($this->isOnCooldown($character)) {
            return ['valid' => false, 'error' => 'You need to wait before performing another action.'];
        }

        return ['valid' => true, 'error' => null];
    }

    public function execute(Character $character, array $params): RedirectResponse
    {
        $corp = $character->corporation;

        if (!$corp) {
            return $this->error('You must be the CEO of a corporation to orchestrate this level of fraud.');
        }

        $cacheKey = $this->getCacheKey($corp);

        if ($this->cacheHas($cacheKey)) {
            $cacheData = $this->cacheGet($cacheKey);
            if (($cacheData['status'] ?? '') === 'ready_for_execution') {
                return $this->executeOperation($corp, $cacheData);
            }
            return $this->error('You cannot run this operation at the moment.');
        }

        return $this->initiate($character, $corp);
    }

    public function initiate(Character $character, Corporation $corp): RedirectResponse
    {
        $check = $this->canExecute($character);
        if (!$check['valid']) {
            return $this->error($check['error']);
        }

        try {
            return DB::transaction(function () use ($character, $corp) {
                $corp = Corporation::where('id', $corp->id)->lockForUpdate()->first();

                if (!$corp) {
                    return $this->error('Corporation no longer exists.');
                }

                $members = $corp->members()->with('timers')->lockForUpdate()->get();

                if ($members->count() < 2) {
                    return $this->error('You need at least 2 active members to pull this off.');
                }
                if ($corp->slush_fund < self::FEE) {
                    return $this->error('The corporate slush fund requires $50,000 for operational capital.');
                }

                $participatingMembers = $members
                    ->filter(fn(Character $m) => ($m->career_rank ?? 0) >= 2)
                    ->sortByDesc('career_rank')
                    ->take(self::MAX_PARTICIPANTS);

                if ($participatingMembers->count() < 2) {
                    return $this->error('You cannot do this operation without at least 1 other member who is a senior staff member.');
                }

                $corp->decrement('slush_fund', self::FEE);
                $this->setCooldown($character, config('timers.action', 180));

                $memberIds = $participatingMembers->pluck('id')->all();

                foreach ($participatingMembers as $member) {
                    if ($member->id === $character->id)
                        continue;
                    JournalService::custom($member->id, 'investment_fraud_request', [
                        'ceo_id' => $character->id,
                        'ceo_name' => $character->display_name,
                        'corporation_name' => $corp->name,
                        'corporation_id' => $corp->id,
                        'city_id' => $corp->home_city_id,
                    ]);
                }

                $this->cachePut($this->getCacheKey($corp), [
                    'expires_at' => now()->addHour()->timestamp,
                    'required_members' => $memberIds,
                    'accepted_by' => [$character->id],
                    'city_id' => $corp->home_city_id,
                    'fee_paid' => self::FEE,
                    'status' => 'pending',
                ], self::CACHE_TTL);

                Log::info('[InvestmentFraud] Initiated.', [
                    'corp_id' => $corp->id,
                    'ceo_id' => $character->id,
                    'participants' => count($memberIds),
                ]);

                $cityName = $corp->city?->name ?? 'the city';
                return $this->success("You have initiated a conspiracy to defraud the bank of {$cityName}. You are now waiting to see who in the company decides to join in on the fun.");
            });
        } catch (\Throwable $e) {
            Log::error('[InvestmentFraud] Initiation failed.', [
                'character_id' => $character->id,
                'corp_id' => $corp->id,
                'error' => $e->getMessage(),
            ]);
            return $this->error('Failed to initiate operation due to an unexpected error.');
        }
    }

    public function accept(Character $character, CharacterJournal $journal): RedirectResponse
    {
        $corp = $character->corporation;
        if (!$corp) {
            $journal->delete();
            return $this->error('You are no longer in a corporation.');
        }

        $cacheKey = $this->getCacheKey($corp);
        $cacheData = $this->cacheGet($cacheKey);

        if (!$cacheData || now()->timestamp > $cacheData['expires_at']) {
            if ($cacheData)
                self::fail($corp, $cacheData['required_members'], $cacheData['accepted_by'], 'expiry');
            $journal->delete();
            return $this->error('The operational window has expired. The regulators caught wind of the delay and froze the assets.');
        }
        if (!in_array($character->id, $cacheData['required_members'])) {
            $journal->delete();
            return $this->error('You are not a participant in this operation.');
        }
        if (($character->career_rank ?? 0) < 2) {
            $journal->delete();
            return $this->error('You are no longer rank 2 and cannot participate.');
        }
        if ($this->isOnCooldown($character)) {
            return $this->error('You cannot accept while on cooldown.');
        }

        if (!in_array($character->id, $cacheData['accepted_by'])) {
            $cacheData['accepted_by'][] = $character->id;
            $this->setCooldownHours($character, 2);
        }

        $journal->delete();

        $acceptedCount = count($cacheData['accepted_by']);
        $invitedCount = count($cacheData['required_members']);

        Log::info('[InvestmentFraud] Member accepted.', [
            'character_id' => $character->id,
            'corp_id' => $corp->id,
            'accepted' => $acceptedCount,
            'invited' => $invitedCount,
        ]);

        $quorumMet = $acceptedCount >= self::MIN_TO_EXECUTE;
        $allResponded = $acceptedCount >= $invitedCount;

        if ($quorumMet || $allResponded) {
            $cacheData['status'] = 'ready_for_execution';
            $this->cachePut($cacheKey, $cacheData, self::CACHE_TTL);
            Log::info('[InvestmentFraud] Quorum reached — ready for CEO execution.', [
                'corp_id' => $corp->id,
                'accepted' => $acceptedCount,
                'invited' => $invitedCount,
                'min_required' => self::MIN_TO_EXECUTE,
            ]);
        } else {
            $this->cachePut($cacheKey, $cacheData, self::CACHE_TTL);
        }

        return $this->success('You have accepted the invitation to participate in this fraudulent scheme.');
    }

    public function decline(Character $character, CharacterJournal $journal): RedirectResponse
    {
        $journal->delete();
        $corp = $character->corporation;

        if (!$corp) {
            return $this->success('You declined the operation.');
        }

        $cacheKey = $this->getCacheKey($corp);
        $cacheData = $this->cacheGet($cacheKey);

        if (!$cacheData || now()->timestamp > $cacheData['expires_at']) {
            if ($cacheData)
                self::fail($corp, $cacheData['required_members'], $cacheData['accepted_by'], 'expiry');
            return $this->success('The operation had already expired.');
        }

        $cacheData['required_members'] = array_values(
            array_filter($cacheData['required_members'], fn(int $id) => $id !== $character->id)
        );

        $remainingCount = count($cacheData['required_members']);

        Log::info('[InvestmentFraud] Member declined.', [
            'character_id' => $character->id,
            'corp_id' => $corp->id,
            'remaining' => $remainingCount,
        ]);

        if ($remainingCount < 2) {
            self::fail($corp, $cacheData['required_members'], $cacheData['accepted_by'], 'board_collapse');
            return $this->success('You vetoed the operation. The board has collapsed — the operation has been abandoned.');
        }

        $this->cachePut($cacheKey, $cacheData, self::CACHE_TTL);
        return $this->success('You declined participation. The other members who received an invite may still proceed.');
    }

    public function cancel(Character $character): RedirectResponse
    {
        $corp = $character->corporation;
        if (!$corp || !$corp->isCeo($character)) {
            return $this->error('Only the CEO holds the authority to abort this operation.');
        }

        $cacheKey = $this->getCacheKey($corp);
        $cacheData = $this->cacheGet($cacheKey);
        if (!$cacheData) {
            return $this->error('There is no active operation to cancel.');
        }

        $this->cacheForget($cacheKey);

        CharacterJournal::where('type', 'investment_fraud_request')
            ->whereJsonContains('data->corporation_id', $corp->id)
            ->delete();

        Log::info('[InvestmentFraud] Operation cancelled by CEO.', ['corp_id' => $corp->id, 'ceo_id' => $character->id]);

        return $this->error('You have aborted the operation. The $50,000 operational funds somehow got lost in all the paperwork.');
    }

    public static function fail(Corporation $corp, array $memberIds, array $acceptedIds, string $reason = 'unknown'): void
    {
        self::safeForget(self::cacheKey($corp));
        $ceoId = $corp->ceo_id;

        DB::transaction(function () use ($memberIds, $acceptedIds, $corp, $reason, $ceoId) {
            $members = Character::whereIn('id', $memberIds)->with('timers')->lockForUpdate()->get();

            foreach ($members as $member) {

                if ($member->id === $ceoId || in_array($member->id, $acceptedIds, true)) {
                    $member->timers
                        ? $member->timers->update(['next_action_at' => now()->addHours(2), 'strength' => 0])
                        : $member->timers()->create(['next_action_at' => now()->addHours(2), 'strength' => 0]);
                }

                JournalService::custom($member->id, 'investment_fraud_result', [
                    'status' => 'failed',
                    'message' => 'The Regulators flagged our commercial paper issuances due to a delay in filing authorizations from the board. Accounts have been frozen and the $50,000 operational capital is permanently lost.',
                ]);
            }

            if (!empty($memberIds)) {
                CharacterJournal::whereIn('character_id', $memberIds)
                    ->where('type', 'investment_fraud_request')
                    ->delete();
            }

            Log::info('[InvestmentFraud] Operation collapsed.', [
                'corp_id' => $corp->id,
                'reason' => $reason,
                'notified' => count($memberIds),
                'penalized' => count($acceptedIds) + 1,
            ]);
        });
    }

    private function executeOperation(Corporation $corp, array $cacheData): RedirectResponse
    {
        try {
            $memberIds = $cacheData['required_members'];
            $acceptedIds = $cacheData['accepted_by'];

            if (count($acceptedIds) < self::MIN_TO_EXECUTE) {
                self::fail($corp, $memberIds, $acceptedIds, 'quorum_lost');
                return $this->error('The operation has lost quorum and has been abandoned.');
            }

            $check = $this->canExecute($corp->ceo);
            if (!$check['valid']) {
                return $this->error($check['error']);
            }

            $this->cacheForget($this->getCacheKey($corp));

            $members = Character::whereIn('id', $acceptedIds)->with(['timers', 'stats'])->get();
            if ($members->count() !== count(array_unique($acceptedIds))) {
                self::fail($corp, $memberIds, $acceptedIds, 'member_missing');
                return $this->error('One of your Corporation members is no longer available. The operation has been aborted.');
            }

            foreach ($members as $member) {
                if (!$member->isAlive() || $member->corporation_id !== $corp->id) {
                    self::fail($corp, $acceptedIds, $acceptedIds, 'member_no_longer_in_corp');
                    return $this->error('One of your Corporation members is no longer available. The operation has been aborted.');
                }
                if (!$member->isOnline()) {
                    self::fail($corp, $acceptedIds, $acceptedIds, 'member_offline');
                    return $this->error('One of your Corporation members went offline. The operation has been aborted.');
                }
                if (!$this->isAvailable($member)) {
                    self::fail($corp, $acceptedIds, $acceptedIds, 'member_unavailable');
                    return $this->error('One of your Corporation members is currently unavailable (hospitalized or jailed). The operation has been aborted.');
                }
            }
            foreach ($members as $member) {
                if ($member->city_id !== $corp->home_city_id) {
                    self::fail($corp, $acceptedIds, $acceptedIds, 'member_not_in_corp_city');
                    return $this->error('One of your Corporation members is not at the corporation headquarters. The operation has been aborted.');
                }
            }
            if ($corp->ceo->city_id !== $corp->home_city_id) {
                self::fail($corp, $acceptedIds, $acceptedIds, 'ceo_not_in_corp_city');
                return $this->error('You must be in the corporation headquarters to execute the operation. The operation has been aborted.');
            }

            $avgStrength = $members->avg(fn(Character $m) => (float) ($m->timers?->strength ?? 0));

            $city = City::find($cacheData['city_id']);
            if (!$city) {
                Log::error('[InvestmentFraud] Execution aborted — city not found.', ['city_id' => $cacheData['city_id'], 'corp_id' => $corp->id]);
                return $this->error('Critical intel failure. The target city could not be verified.');
            }

            $totalBankDeposits = (int) Character::where('home_city_id', $city->id)->where('cash_in_bank', '>', 0)->sum('cash_in_bank');
            $difficultyModifier = log10(max($totalBankDeposits, 1)) * 0.5;


            $corpStrength = Corporation::CorporationStrength($members);
            $crimeBonus = $this->crimeRateBonus($city, 0.50);
            $statChance = $crimeBonus + (($corpStrength / 500) * 20);
            $strengthMultiplier = max(0.15, sqrt($avgStrength / 100));
            $baseChance = $statChance * $strengthMultiplier;
            $successChance = (int) max(5, min(85, $baseChance - $difficultyModifier));
            $roll = mt_rand(1, 100);
            $isSuccess = $roll <= $successChance;
            $rewards = $this->calculateRewards($totalBankDeposits);

            Log::info('[InvestmentFraud] Formula evaluated.', [
                'corp_id' => $corp->id,
                'city' => $city->name,
                'participants' => count($memberIds),
                'avg_strength' => round($avgStrength, 2),
                'corp_strength' => $corpStrength,
                'crime_rate' => $city->crime_rate,
                'crime_bonus' => round($crimeBonus, 2),
                'total_bank_deposits' => $totalBankDeposits,
                'difficulty_modifier' => round($difficultyModifier, 2),
                'success_chance' => $successChance,
                'roll' => $roll,
                'outcome' => $isSuccess ? 'SUCCESS' : 'FAILURE',
            ]);

            foreach ($members as $member) {
                $timerReset = ['strength' => 0];
                if ($member->id === $corp->ceo_id) {
                    $timerReset['next_action_at'] = now()->addHours(2);
                }
                $member->timers
                    ? $member->timers->update($timerReset)
                    : $member->timers()->create($timerReset);
            }

            return $isSuccess
                ? $this->handleSuccess($corp, $city, $members, $rewards)
                : $this->handleFailure($corp, $members, $rewards);

        } catch (\Throwable $e) {
            Log::error('[InvestmentFraud] Execution crashed.', ['corp_id' => $corp->id, 'error' => $e->getMessage()]);
            return $this->error('Operation failed due to unforeseen technical difficulties.');
        }
    }
    //! SUCCESS OR FAILURE SHOULD SEND JOURNAL TO BANK OWNER IF EXISTS

    private function handleSuccess(Corporation $corp, City $city, $members, array $rewards): RedirectResponse
    {
        $victimIds = [];

        [$stolenFromDepositors, $stolenFromBank] = DB::transaction(function () use ($city, $corp, &$victimIds) {
            $depositorCap = 5_000_000;
            $depositorStolen = 0;

            Character::where('home_city_id', $city->id)
                ->where('cash_in_bank', '>', 0)
                ->lockForUpdate()
                ->orderBy('cash_in_bank', 'desc')
                ->chunkById(100, function ($victims) use (&$depositorStolen, &$victimIds, $depositorCap) {
                    $journals = [];
                    foreach ($victims as $victim) {
                        if ($depositorStolen >= $depositorCap)
                            break;
                        $loss = (int) floor($victim->cash_in_bank * 0.10);
                        $loss = min($loss, $depositorCap - $depositorStolen);
                        if ($loss <= 0)
                            continue;
                        $victim->decrement('cash_in_bank', $loss);
                        $depositorStolen += $loss;
                        $victimIds[] = $victim->id;
                        $journals[] = [
                            'character_id' => $victim->id,
                            'type' => 'bank_fraud',
                            'data' => json_encode([
                                'message' => "Your bank was defrauded by a shadow corporation's toxic commercial paper. The catastrophic losses were passed onto your account. You lost $" . number_format($loss) . ' of your uninsured deposits.',
                                'loss' => $loss,
                            ]),
                            'is_read' => false,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                    if (!empty($journals))
                        CharacterJournal::insert($journals);
                });

            $bankStolen = 0;
            $bank = Business::forCity($city, 'bank');
            if ($bank && $bank->balance > 0) {
                $bank = Business::where('id', $bank->id)->lockForUpdate()->first();
                if ($bank && $bank->balance > 0) {
                    $bankStolen = (int) floor($bank->balance * 0.05);
                    if ($bankStolen > 0) {
                        DB::table('businesses')->where('id', $bank->id)->decrement('balance', $bankStolen);
                    }
                }
            }

            $totalStolen = $depositorStolen + $bankStolen;
            if ($totalStolen > 0) {
                Corporation::where('id', $corp->id)
                    ->increment('slush_fund', $totalStolen);
                Corporation::where('id', $corp->id)
                    ->increment('total_profits', $totalStolen);
            }

            return [$depositorStolen, $bankStolen];
        });

        foreach ($victimIds as $id)
            self::safeForget("unread_journals_{$id}");

        $totalStolen = $stolenFromDepositors + $stolenFromBank;
        $ceo = $members->firstWhere('id', $corp->ceo_id);

        if ($ceo && $totalStolen > 0) {
            CrimeService::investmentFraud($ceo, $corp, $members->pluck('id')->all(), $city->id, $totalStolen);
        }

        City::increaseCrimeRateById($city->id, 0.3);

        $fmtDepositors = number_format($stolenFromDepositors);
        $fmtBank = number_format($stolenFromBank);
        $fmtTotal = number_format($totalStolen);
        $bankLine = $stolenFromBank > 0 ? " You also managed to scam an additional \${$fmtBank} directly from the bank's investment arm itself." : '';
        $successMsg = "The toxic commercial paper was successfully offloaded onto retail depositors before defaulting. Your company managed to scam \${$fmtDepositors} from depositor accounts and rerouted into the corporate slush fund.{$bankLine}";

        foreach ($members as $member) {
            $member->addXp($rewards['xp']);
            $member->stats?->addOffense($rewards['offense']);
            if ($member->id === $corp->ceo_id)
                continue;
            JournalService::custom($member->id, 'investment_fraud_result', ['status' => 'success', 'message' => $successMsg]);
        }


        Log::info('[InvestmentFraud] Execution succeeded.', [
            'corp_id' => $corp->id,
            'stolen_from_depositors' => $stolenFromDepositors,
            'stolen_from_bank' => $stolenFromBank,
            'total_stolen' => $totalStolen,
            'xp_awarded' => $rewards['xp'],
            'offense_awarded' => $rewards['offense'],
        ]);

        return $this->success("Execution successful. You convinced the bank to buy your toxic commercial paper before it defaulted, scamming \${$fmtDepositors} from depositor accounts{$bankLine}");
    }

    private function handleFailure(Corporation $corp, $members, array $rewards): RedirectResponse
    {
        $consolationXp = (int) floor($rewards['xp'] * 0.1);
        $failMsg = 'The authorities flagged our bond issuances before the bank could offload them to retail depositors. Regulatory bodies froze the target accounts and the $50,000 operational capital is lost.';

        foreach ($members as $member) {
            if ($consolationXp > 0)
                $member->addXp($consolationXp);
            if ($member->id === $corp->ceo_id)
                continue;
            JournalService::custom($member->id, 'investment_fraud_result', ['status' => 'failed', 'message' => $failMsg]);
        }

        Log::info('[InvestmentFraud] Execution failed.', ['corp_id' => $corp->id, 'consolation_xp' => $consolationXp]);
        return $this->error('Regulators flagged your fraudulent commercial paper before it could be sold to retail depositors. The operation has failed.');
    }

    private function calculateRewards(int $totalBankDeposits): array
    {
        $log = log10(max($totalBankDeposits, 10));
        return [
            'xp' => (int) min(100, max(10, (int) floor($log * 111))),
            'offense' => (int) min(150, max(1, (int) floor($log * 5))),
        ];
    }

    public static function cacheKey(Corporation $corp): string
    {
        return "corp_{$corp->id}_investment_fraud";
    }

    private function getCacheKey(Corporation $corp): string
    {
        return self::cacheKey($corp);
    }

    private function getState(Corporation $corp): ?array
    {
        $cacheKey = $this->getCacheKey($corp);
        $cacheData = $this->cacheGet($cacheKey);
        if (!$cacheData)
            return null;
        if (now()->timestamp > $cacheData['expires_at']) {
            self::fail($corp, $cacheData['required_members'], $cacheData['accepted_by'], 'expiry');
            return null;
        }
        return $cacheData;
    }
}
