<?php

namespace App\Http\Controllers;

use App\Models\BankCertificate;
use App\Models\BankTransaction;
use App\Services\CrimeService;
use App\Models\Business;
use App\Models\Career;
use App\Models\Character;
use App\Models\City;
use App\Services\DerivativeTrading;
use App\Services\JournalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class BankController extends CityController
{
    private function getBank(City $city): ?Business
    {
        return Business::forCity($city, 'bank');
    }

    public function terminal(Request $request, DerivativeTrading $derivatives): \Inertia\Response
    {
        $character = $request->user()->character;
        $character->loadMissing(['homeCity', 'timers', 'career']);

        $homeCityBank = $character->homeCity ? $this->getBank($character->homeCity) : null;
        if ($homeCityBank) {
            $homeCityBank->loadMissing('owner');
        }

        $manager   = $character->homeCity ? $character->homeCity->getServiceLeader('banking') : null;
        $managerId = $manager !== null ? (int) ($manager['id'] ?? 0) ?: null : null;

        if ($homeCityBank) {
            $homeCityBank->setRelation('city', $character->homeCity);
            $this->autoSettleCd($homeCityBank, $managerId);
            $fresh = DB::table('characters')->where('id', $character->id)->first(['cash_on_hand', 'cash_in_bank']);
            if ($fresh) {
                $character->cash_on_hand = (int) $fresh->cash_on_hand;
                $character->cash_in_bank = (int) $fresh->cash_in_bank;
            }
        }

        $rankInt   = (int) ($character->career_rank ?? 1);
        $rankLabel = $character->current_rank?->rank_name ?? 'Associate';
        $bankBalance = $homeCityBank ? (int) $homeCityBank->balance : 0;
        $isManager = $manager !== null && (int) ($manager['id'] ?? 0) === (int) $character->id;
        $marketTime = (int) now()->getTimestamp();

        $financialReports   = null;
        $dismissableBankers = [];

        if ($rankInt >= 2 && $character->homeCity) {
            $financialReports = BankTransaction::tradeReport($character->homeCity->id);

            $bankingCareerId = Career::findByCode('banking')?->id;
            if ($bankingCareerId) {
                $bankingRanks = \App\Models\CareerRank::getRanksForCareer($bankingCareerId)->keyBy('rank_level');

                $dismissableBankers = Character::select(['id', 'display_name', 'custom_avatar_url', 'career_rank'])
                    ->where('career_id', $bankingCareerId)
                    ->where('home_city_id', $character->home_city_id)
                    ->where('id', '!=', $character->id)
                    ->where('career_rank', '<', 4)
                    ->orderByDesc('career_rank')
                    ->orderBy('display_name')
                    ->get()
                    ->map(function ($c) use ($bankingRanks) {
                        $rankEntry = $bankingRanks->get((int) $c->career_rank);
                        return [
                            'id'         => $c->id,
                            'name'       => $c->display_name,
                            'avatar_url' => $c->custom_avatar_url ?? $rankEntry?->avatar_url ?? null,
                            'rank_name'  => $rankEntry?->rank_name ?? 'Banker',
                            'rank'       => (int) $c->career_rank,
                        ];
                    })
                    ->values()
                    ->all();
            }
        }

        return Inertia::render('Careers/Banking', [
            'bank' => $homeCityBank ? [
                'name'        => $homeCityBank->name,
                'image'       => $homeCityBank->image_url ?? $homeCityBank->image ?? null,
                'description' => $homeCityBank->description,
                'balance'     => $bankBalance,
                'position_cap_percent' => $derivatives->positionCapPercent($homeCityBank),
            ] : null,
            'owner' => $homeCityBank && $homeCityBank->owner ? [
                'name'       => $homeCityBank->owner->display_name,
                'avatar_url' => $homeCityBank->owner->avatar_url,
            ] : null,
            'manager'             => $manager,
            'rank'                => $rankInt,
            'rank_label'          => $rankLabel,
            'is_manager'          => $isManager,
            'is_owner'            => $homeCityBank && $homeCityBank->owner_id === $character->id,
            'financial_reports'   => $financialReports,
            'dismissable_bankers' => $dismissableBankers,
            'launder_clients' => \App\Models\LaunderOffer::forBanker($character->id)
                ->active()
                ->with(['client:id,display_name,custom_avatar_url,career_id,career_rank'])
                ->latest()
                ->get()
                ->map(fn ($o) => [
                    'id'            => $o->id,
                    'client_id'     => $o->client_id,
                    'client_name'   => $o->client?->display_name,
                    'client_avatar' => $o->client?->avatar_url,
                    'amount'        => $o->amount,
                    'cut_pct'       => (float) $o->cut_pct,
                    'amount_sent'   => $o->amount_sent ?? 0,
                    'status'        => $o->status,
                ])
                ->values()
                ->all(),
            'launder_relationships' => \App\Models\LaunderOffer::forClient($character->id)
                ->active()
                ->with(['banker:id,display_name,custom_avatar_url,career_id,career_rank,home_city_id'])
                ->latest()
                ->get()
                ->map(fn ($o) => [
                    'id'            => $o->id,
                    'banker_id'     => $o->banker_id,
                    'banker_name'   => $o->banker?->display_name,
                    'banker_avatar' => $o->banker?->avatar_url,
                    'amount'        => $o->amount,
                    'cut_pct'       => (float) $o->cut_pct,
                    'amount_sent'   => $o->amount_sent ?? 0,
                    'status'        => $o->status,
                    'bank_overhead' => \App\Actions\BankerLaunderClient::OVERHEAD,
                ])
                ->values()
                ->all(),
            'launder_constants' => [
                'overhead'   => \App\Actions\BankerLaunderClient::OVERHEAD,
                'cut_min'    => \App\Actions\BankerLaunderClient::CUT_MIN,
                'cut_max'    => \App\Actions\BankerLaunderClient::CUT_MAX,
                'amount_max' => $homeCityBank ? \App\Actions\BankerLaunderClient::maxAmount((int) $homeCityBank->balance) : 0,
                'amount_min' => 1000,
            ],
            'market_server_time' => $marketTime,
            'ticker_snapshots' => $derivatives->publicSnapshots($marketTime),
        ]);
    }

    public function tickerSnapshots(Request $request, DerivativeTrading $derivatives): \Illuminate\Http\JsonResponse
    {
        $request->session()->reflash();

        $marketTime = (int) now()->getTimestamp();

        return response()->json([
            'server_time' => $marketTime,
            'snapshots' => $derivatives->publicSnapshots($marketTime),
        ]);
    }

    public function updateSettings(Request $request, City $city): \Illuminate\Http\RedirectResponse
    {
        [$character, $city] = $this->getContext($request, $city);

        $bank = $this->getBank($city);
        if (! $bank) {
            return back()->with('error', 'Bank not found in this city.');
        }
        if ($bank->owner_id !== $character->id) {
            return back()->with('error', 'You do not own this bank.');
        }

        $validated = $request->validate([
            'loan_interest' => 'required|numeric|min:0.5|max:15.0',
            'wire_fee'      => 'required|numeric|min:0|max:15.0',
        ]);

        $bank->setSetting('loan_interest', (float) $validated['loan_interest']);
        $bank->setSetting('wire_fee', (float) $validated['wire_fee']);

        return back()->with('success', 'Bank rates updated.');
    }

    public function index(Request $request, City $city): \Illuminate\Http\RedirectResponse|\Inertia\Response
    {
        if ($this->isOnlyInertiaPartial($request, 'transactions')) {
            $character = $request->user()->getLoadedCharacter();

            return Inertia::render('City/Bank', [
                'transactions' => Inertia::optional(
                    fn() => $character
                        ? BankTransaction::forCharacter($character->id)->toArray()
                        : [],
                ),
            ]);
        }

        [$character, $city] = $this->getContext($request, $city);

        $bank = $this->getBank($city);
        if (! $bank) {
            return redirect()->route('city.show', $city->slug)->with('error', 'No bank in this city.');
        }

        $character->loadMissing('homeCity');
        $isHomeCity = (int) $character->home_city_id === (int) $city->id;

        $homeCityBank      = $isHomeCity ? $bank : ($character->homeCity ? $this->getBank($character->homeCity) : null);
        $applicableWireFee = $homeCityBank ? (float) $homeCityBank->getSetting('wire_fee', 10) : 0.0;

        $manager   = $city->getServiceLeader('banking');
        $managerId = $manager !== null ? (int) ($manager['id'] ?? 0) : null;
        $isManager = $managerId !== null && $managerId === (int) $character->id;

        $bank->loadMissing('owner');
        $bank->setRelation('city', $city);
        $this->autoSettleCd($bank, $managerId);

        $fresh = DB::table('characters')->where('id', $character->id)->first(['cash_on_hand', 'cash_in_bank']);
        if ($fresh) {
            $character->cash_on_hand = (int) $fresh->cash_on_hand;
            $character->cash_in_bank = (int) $fresh->cash_in_bank;
        }

        $activeCert = $isHomeCity
            ? BankCertificate::active()->forBank($bank->id)->where('character_id', $character->id)->first()
            : null;

        $pastCerts = $isHomeCity
            ? BankCertificate::forBank($bank->id)
                ->where('character_id', $character->id)
                ->whereNotNull('settled_at')
                ->latest('settled_at')
                ->limit(10)
                ->get(['id', 'principal', 'rate', 'interest_owed', 'settled_at', 'outcome'])
                ->toArray()
            : [];

        return Inertia::render('City/Bank', [
            'bankData' => [
                'balance'            => (int) $character->cash_in_bank,
                'cash_on_hand'       => (int) $character->cash_on_hand,
                'city_name'          => $city->name,
                'city_slug'          => $city->slug,
                'bank_image'         => $bank->image_url ?? null,
                'home_city_slug'     => $character->homeCity?->slug,
                'loan_interest'      => (float) $bank->getSetting('loan_interest', 15),
                'wire_fee'           => (float) $bank->getSetting('wire_fee', 10),
                'my_wire_fee'        => $applicableWireFee,
                'is_purchasable'     => (bool) $bank->is_purchasable,
                'is_owner'           => $bank->owner_id === $character->id,
                'is_manager'         => $isManager,
                'bank_manager'       => $manager,
                'owner'              => $bank->owner ? [
                    'name'       => $bank->owner->display_name,
                    'avatar_url' => $bank->owner->avatar_url,
                ] : null,
                'is_home_city'       => $isHomeCity,
                'active_certificate' => $activeCert ? [
                    'id'            => $activeCert->id,
                    'principal'     => $activeCert->principal,
                    'rate'          => $activeCert->rate,
                    'interest_owed' => $activeCert->interest_owed,
                    'matures_at'    => $activeCert->matures_at->toIso8601String(),
                    'is_matured'    => $activeCert->isMatured(),
                ] : null,
                'past_certificates'  => $pastCerts,
            ],
            'transactions' => Inertia::optional(
                fn() => BankTransaction::forCharacter($character->id)->toArray(),
            ),
        ]);
    }

    public function deposit(Request $request, City $city): \Illuminate\Http\RedirectResponse
    {
        [$character, $city] = $this->getContext($request, $city);
        $request->validate(['amount' => 'required|integer|min:1|max:999999999']);
        $amount = (int) $request->input('amount');
        if (! $character->depositToBank($amount)) {
            return back()->with('error', 'Insufficient cash on hand.');
        }
        return back()->with('success', 'Successfully deposited $' . number_format($amount) . '.');
    }

    public function withdraw(Request $request, City $city): \Illuminate\Http\RedirectResponse
    {
        [$character, $city] = $this->getContext($request, $city);
        $request->validate(['amount' => 'required|integer|min:1|max:999999999']);
        $amount = (int) $request->input('amount');
        if (! $character->withdrawFromBank($amount)) {
            return back()->with('error', 'Insufficient bank balance.');
        }
        return back()->with('success', 'Successfully withdrew $' . number_format($amount) . '.');
    }

    public function transfer(Request $request, City $city): \Illuminate\Http\RedirectResponse
    {
        [$character, $city] = $this->getContext($request, $city);

        $request->validate([
            'amount'    => 'required|integer|min:1|max:999999999',
            'recipient' => 'required|string|max:30',
            'note'      => 'nullable|string|max:20',
        ]);

        $amount        = (int) $request->input('amount');
        $recipientName = trim((string) $request->input('recipient'));
        $note          = $request->input('note');

        $recipient = Character::findByName($recipientName);
        if (! $recipient || $recipient->trashed()) {
            return back()->with('error', 'Recipient not found.');
        }
        if ($recipient->id === $character->id) {
            return back()->with('error', 'Cannot transfer to yourself.');
        }

        $senderHomeBank = ((int) $character->home_city_id !== (int) $recipient->home_city_id && $character->homeCity)
            ? $this->getBank($character->homeCity)
            : null;
        $wireFeeRate  = $senderHomeBank ? (float) $senderHomeBank->getSetting('wire_fee', 10) : 0.0;
        $fee          = $wireFeeRate > 0 ? max(1, (int) round($amount * ($wireFeeRate / 100))) : 0;
        $totalDebited = $amount + $fee;

        try {
            $senderHomeBankId = $senderHomeBank?->id;

            $result = DB::transaction(function () use (
                $character, $recipient, $amount, $fee, $totalDebited,
                $senderHomeBankId, $wireFeeRate, $note
            ) {
                $ids = [$character->id, $recipient->id];
                sort($ids);
                DB::table('characters')->whereIn('id', $ids)->lockForUpdate()->get();

                $senderFresh    = DB::table('characters')->where('id', $character->id)->first(['cash_in_bank', 'cash_on_hand', 'deleted_at']);
                $recipientFresh = DB::table('characters')->where('id', $recipient->id)->first(['cash_in_bank', 'cash_on_hand', 'deleted_at']);

                if ($senderFresh)    { $character->cash_in_bank = (int) $senderFresh->cash_in_bank; $character->cash_on_hand = (int) $senderFresh->cash_on_hand; }
                if ($recipientFresh) { $recipient->cash_in_bank = (int) $recipientFresh->cash_in_bank; $recipient->cash_on_hand = (int) $recipientFresh->cash_on_hand; }

                if (! $recipient->isAlive()) {
                    return ['error' => 'Recipient is deceased.'];
                }
                if ($character->cash_in_bank < $totalDebited) {
                    $feeNote = $fee > 0 ? ' (includes $' . number_format($fee) . ' wire fee)' : '';
                    return ['error' => 'Insufficient bank balance. Need $' . number_format($totalDebited) . $feeNote . '.'];
                }

                $senderBalanceAfter    = $character->cash_in_bank - $totalDebited;
                $recipientBalanceAfter = $recipient->cash_in_bank + $amount;

                $character->decrement('cash_in_bank', $totalDebited);
                $recipient->increment('cash_in_bank', $amount);

                if ($fee > 0 && $senderHomeBankId !== null) {
                    DB::table('businesses')->where('id', $senderHomeBankId)->increment('balance', $fee);
                }

                BankTransaction::record(characterId: $character->id, type: BankTransaction::TYPE_TRANSFER_SENT,     amount: $amount, balanceAfter: $senderBalanceAfter,    fee: $fee, counterparty: $recipient->display_name, note: $note ?: null);
                BankTransaction::record(characterId: $recipient->id, type: BankTransaction::TYPE_TRANSFER_RECEIVED, amount: $amount, balanceAfter: $recipientBalanceAfter, counterparty: $character->display_name, note: $note ?: null);

                \App\Services\JournalService::moneyReceived(receiverId: $recipient->id, senderName: $character->display_name, amount: $amount, note: $note ?: '');

                return ['success' => true, 'receiver_name' => $recipient->display_name, 'fee' => $fee, 'fee_rate' => $wireFeeRate];
            });

            if (isset($result['error'])) {
                return back()->with('error', $result['error']);
            }

            $flash = ['success' => 'You successfully transferred $' . number_format($amount) . ' to ' . $result['receiver_name'] . '.'];
            if ($result['fee'] > 0) {
                $flash['warning'] = 'The Bank took $' . number_format($result['fee']) . ' in cross-city wire fees.';
            }
            return back()->with($flash);

        } catch (\Exception $e) {
            Log::error('Bank transfer failed', ['sender_id' => $character->id, 'recipient_name' => $recipientName, 'amount' => $amount, 'error' => $e->getMessage()]);
            return back()->with('error', 'Transfer failed. Please try again.');
        }
    }

    public function ledgerResidents(Request $request, City $city): \Illuminate\Http\JsonResponse
    {
        [$character, $city] = $this->getContext($request, $city);
        if (! $this->isManagerOf($character, $city)) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }
        return response()->json(BankTransaction::cityResidents($city->id));
    }

    public function ledgerCharacter(Request $request, City $city): \Illuminate\Http\JsonResponse
    {
        [$character, $city] = $this->getContext($request, $city);
        if (! $this->isManagerOf($character, $city)) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }
        $targetId = (int) $request->input('character_id');
        if ($targetId <= 0) {
            return response()->json(['error' => 'Invalid character.'], 422);
        }
        $data = BankTransaction::forOneCharacter($targetId);
        if (empty($data)) {
            return response()->json(['error' => 'Character not found.'], 404);
        }
        return response()->json($data);
    }

    
    

    public function executeTrade(Request $request, DerivativeTrading $derivatives): \Illuminate\Http\RedirectResponse
    {
        $character = $request->user()->character;
        $character->loadMissing(['homeCity', 'timers', 'stats']);
        if ($character->stats) {
            $character->stats->setRelation('character', $character);
        }

        if ((int) ($character->career_rank ?? 0) < 2) {
            return back()->with('error', 'Equities trading requires rank 2 or above.');
        }

         

        $bank = $character->homeCity ? $this->getBank($character->homeCity) : null;
        if (! $bank) {
            return back()->with('error', 'No bank found in your home city.');
        }

        $request->validate([
            'ticker'    => 'required|string|max:10',
            'direction' => 'required|in:call,put',
            'amount'    => 'required|integer|min:1000|max:999999999',
            'market_time' => 'nullable|integer|min:1',
        ]);

        $stake     = (int) $request->input('amount');
        $ticker    = strtoupper(trim((string) $request->input('ticker')));
        $direction = (string) $request->input('direction');

        $utcNow      = (int) now()->getTimestamp();
        $luck        = max(1, (int) ($character->stats?->effectiveStats()['luck'] ?? 1));
        $quote       = $derivatives->quote($ticker, $direction, $utcNow, $luck);
        if (! $quote) {
            return back()->with('error', 'Unknown ticker symbol.');
        }

       

        $regimeName  = (string) $quote['regime'];
        $winChance   = (float) $quote['win_chance'];
        $won         = (random_int(1, 10000) / 100.0) <= $winChance;

        try {
            $result = DB::transaction(function () use (
                $character, $bank, $stake, $ticker, $direction,
                $quote, $derivatives, $regimeName, $winChance, $won, $utcNow
            ) {
                $lockedChar = DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();
                $lockedBank = Business::whereKey($bank->id)->lockForUpdate()->first(['id', 'balance', 'data']);

                if ($lockedChar === null || $lockedBank === null) {
                    return ['error' => 'Trade could not be executed. Please try again.'];
                }
                if ($lockedChar->deleted_at !== null || (int) $lockedChar->health <= 0) {
                    return ['error' => 'You cannot trade in your current state.'];
                }

                $timers       = DB::table('character_timers')->where('character_id', $character->id)->first();
                $nowTs        = $utcNow;

                if ($timers?->hospital_until && $timers->hospital_until > $nowTs) {
                    return ['error' => 'You cannot trade while incapacitated.'];
                }
                if ($timers?->jail_until && $timers->jail_until > $nowTs) {
                    return ['error' => 'You cannot trade while incapacitated.'];
                }
                if ($timers?->next_action_at && $timers->next_action_at > $nowTs) {
                    return ['error' => 'You must wait before performing another action.'];
                }
                $bankBalance = (int) $lockedBank->balance;
                $positionCapPercent = $derivatives->positionCapPercent($lockedBank);
                $maxStake = $derivatives->maxStake($bankBalance, $positionCapPercent);

                if ($maxStake < 1) {
                    return ['error' => 'The bank does not have enough capital to open a position.'];
                }
                if ($stake > $maxStake) {
                    return ['error' => 'Position size cannot exceed ' . $positionCapPercent . '% of bank capital ($' . number_format($maxStake) . ').'];
                }
                if ($stake > $bankBalance) {
                    return ['error' => 'Insufficient bank capital.'];
                }

                $display     = (string) $quote['display'];
                $mult        = (float) $quote['multiplier'];
                $balanceAfter = (int) $lockedChar->cash_in_bank;

                if ($won) {
                    $payouts = $derivatives->winPayouts($stake, $mult, $positionCapPercent);
                    $payoutToBanker = (int) $payouts['banker'];
                    $payoutToBank   = (int) $payouts['bank'];

                    DB::table('businesses')->where('id', $bank->id)->increment('balance', $payoutToBank);
                    DB::table('characters')->where('id', $character->id)->increment('cash_on_hand', $payoutToBanker);

                    $character->addXp(mt_rand(50, 150));

                    BankTransaction::record(characterId: $character->id, type: BankTransaction::TYPE_TRADE_WIN, amount: $payoutToBanker, balanceAfter: $balanceAfter, counterparty: $ticker, note: strtoupper($direction));

                    \App\Models\CharacterHistory::addHistory($character, 'trades_won');
                    \App\Models\CharacterHistory::addHistory($character, 'earned_career', $payoutToBanker);

                    DB::table('character_timers')->where('character_id', $character->id)->update(['next_action_at' => $nowTs + 1800]);

                    Log::info('[Banking] Trade win.', ['banker' => $character->id, 'ticker' => $ticker, 'direction' => $direction, 'regime' => $regimeName, 'win_chance' => round($winChance, 2), 'stake' => $stake, 'bank_gain' => $payoutToBank, 'my_gain' => $payoutToBanker]);

                    return ['won' => true, 'display' => $display, 'direction' => $direction, 'regime' => $regimeName, 'stake' => $stake, 'payout_banker' => $payoutToBanker, 'payout_bank' => $payoutToBank];
                }

                $actualLoss = (int) floor($stake * (float) $quote['loss_depth']);
                $salvage    = $stake - $actualLoss;

                DB::table('businesses')->where('id', $bank->id)->decrement('balance', $actualLoss);

                $character->addXp(25);

                BankTransaction::record(characterId: $character->id, type: BankTransaction::TYPE_TRADE_LOSS, amount: $actualLoss, balanceAfter: $balanceAfter, counterparty: $ticker, note: strtoupper($direction));

                \App\Models\CharacterHistory::addHistory($character, 'trades_lost');
                DB::table('character_timers')->where('character_id', $character->id)->update(['next_action_at' => $nowTs + 1800]);

                Log::info('[Banking] Trade loss.', ['banker' => $character->id, 'ticker' => $ticker, 'direction' => $direction, 'regime' => $regimeName, 'win_chance' => round($winChance, 2), 'stake' => $stake, 'bank_loss' => $actualLoss, 'salvage' => $salvage]);

                return ['won' => false, 'display' => $display, 'direction' => $direction, 'regime' => $regimeName, 'stake' => $stake, 'actual_loss' => $actualLoss, 'salvage' => $salvage];
            });

            if (isset($result['error'])) {
                return back()->with('error', $result['error']);
            }

            $dir      = strtoupper($result['direction']);
            $display  = $result['display'];
            $regime   = $result['regime'];
            $fmtStake = '$' . number_format($result['stake']);

            if ($result['won']) {
                $fmtBankGain = '$' . number_format($result['payout_bank']);
                $fmtMyGain   = '$' . number_format($result['payout_banker']);
                $msg = match (true) {
                    $regime === 'bull'     && $dir === 'CALL' => "You bought a CALL on {$display} for {$fmtStake} expecting it to climb — and it did. The bank earned {$fmtBankGain}, your cut is {$fmtMyGain}.",
                    $regime === 'bull'     && $dir === 'PUT'  => "You bought a PUT on {$display} for {$fmtStake} against the trend — the market surprised you and flipped. The bank earned {$fmtBankGain}, your cut is {$fmtMyGain}.",
                    $regime === 'bear'     && $dir === 'PUT'  => "You bought a PUT on {$display} for {$fmtStake} expecting it to fall — and it did. The bank earned {$fmtBankGain}, your cut is {$fmtMyGain}.",
                    $regime === 'bear'     && $dir === 'CALL' => "You bought a CALL on {$display} for {$fmtStake} against the trend — a surprise reversal paid off. The bank earned {$fmtBankGain}, your cut is {$fmtMyGain}.",
                    $regime === 'sideways'                    => "You bought a {$dir} on {$display} for {$fmtStake} and caught a breakout from a flat market. The bank earned {$fmtBankGain}, your cut is {$fmtMyGain}.",
                    $regime === 'volatile'                    => "You bought a {$dir} on {$display} for {$fmtStake} during high volatility, perfectly timing the price swings. The bank earned {$fmtBankGain}, your cut is {$fmtMyGain}.",
                    default                                   => "Your {$dir} on {$display} for {$fmtStake} closed in the money. The Bank earned {$fmtBankGain}, your cut is {$fmtMyGain}.",
                };
                return back()->with('success', $msg);
            }

            $fmtLoss    = '$' . number_format($result['actual_loss']);
            $salvageMsg = $result['salvage'] > 0 ? ', but the bank recovered $' . number_format($result['salvage']) . ' of the stake.' : '';
            $msg = match (true) {
                $regime === 'bull'     && $dir === 'PUT'  => "You bought a PUT on {$display} for {$fmtStake} hoping it would drop, but the uptrend held. Your position closed at a loss of {$fmtLoss}{$salvageMsg}",
                $regime === 'bull'     && $dir === 'CALL' => "You bought a CALL on {$display} for {$fmtStake} expecting the rally to continue, but it stalled. Your position closed at a loss of {$fmtLoss}{$salvageMsg}",
                $regime === 'bear'     && $dir === 'CALL' => "You bought a CALL on {$display} for {$fmtStake} hoping for a recovery, but the sell-off continued. Your position closed at a loss of {$fmtLoss}{$salvageMsg}",
                $regime === 'bear'     && $dir === 'PUT'  => "You bought a PUT on {$display} for {$fmtStake} expecting it to keep falling, but it pushed through your level. Your position closed at a loss of {$fmtLoss}{$salvageMsg}",
                $regime === 'sideways'                    => "You bought a {$dir} on {$display} for {$fmtStake} looking for a breakout, but the market stayed flat. Your position closed at a loss of {$fmtLoss}{$salvageMsg}",
                $regime === 'volatile'                    => "You bought a {$dir} on {$display} for {$fmtStake} into high volatility, but got chopped out as erratic price action and IV crush decayed your premium. Your position closed at a loss of {$fmtLoss}{$salvageMsg}",
                default                                   => "Your {$dir} on {$display} for {$fmtStake} closed at a loss of {$fmtLoss}{$salvageMsg}",
            };
            return back()->with('error', $msg);

        } catch (\Exception $e) {
            Log::error('[Banking] Trade failed.', ['banker' => $character->id, 'error' => $e->getMessage()]);
            return back()->with('error', 'Trade execution failed. Please try again.');
        }
    }

    

    public function dismissBanker(Request $request): \Illuminate\Http\RedirectResponse
    {
        $character = $request->user()->character;
        $character->loadMissing('homeCity');

        $bankingCareerId = Career::findByCode('banking')?->id;
        $isManager       = $bankingCareerId
            && $character->career_id === $bankingCareerId
            && $character->home_city_id !== null
            && (int) $character->career_rank === 4;

        if (! $isManager) {
            return back()->with('error', 'Only the Bank Manager can dismiss banking staff.');
        }

        $validated = $request->validate(['character_id' => 'required|integer|exists:characters,id']);
        $targetId  = (int) $validated['character_id'];

        if ($targetId === $character->id) {
            return back()->with('error', 'You cannot dismiss yourself.');
        }

        try {
            return DB::transaction(function () use ($character, $bankingCareerId, $targetId) {
                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();
                $character->refresh();

                if ($character->career_id !== $bankingCareerId || (int) $character->career_rank !== 4) {
                    return back()->with('error', 'Your manager status has changed.');
                }

                $target = Character::lockForUpdate()->find($targetId);

                if (! $target)                                           return back()->with('error', 'Banker not found.');
                if ($target->career_id !== $bankingCareerId)            return back()->with('error', 'That character is not in the banking career.');
                if ($target->home_city_id !== $character->home_city_id) return back()->with('error', 'That banker does not work in your city.');
                if ((int) $target->career_rank >= 4)                    return back()->with('error', 'You cannot dismiss another Bank Manager.');

                $rankName = $target->current_rank?->rank_name ?? 'Banker';
                $target->quitCareer(preserveExp: true);

                JournalService::careerDismissed($target->id, $character->display_name, $character->current_rank?->rank_name ?? 'Bank Manager', 'Bank');

                Log::info('[Bank] Manager dismissed banker.', ['manager' => $character->id, 'target' => $targetId, 'rank' => $rankName]);

                return back()->with('success', "{$target->display_name} has been dismissed from the bank.");
            });
        } catch (\Throwable $e) {
            Log::error('[Bank] Dismiss banker failed.', ['manager' => $character->id, 'error' => $e->getMessage()]);
            return back()->with('error', 'Dismissal failed. Please try again.');
        }
    }

    public function updatePositionCap(Request $request): \Illuminate\Http\RedirectResponse
    {
        $character = $request->user()->character;
        $character->loadMissing('homeCity');

        if (! $character->homeCity || ! $this->isManagerOf($character, $character->homeCity)) {
            return back()->with('error', 'Only the Bank Manager can change trading risk controls.');
        }

        $validated = $request->validate([
            'position_cap_percent' => ['required', 'integer', 'min:5', 'max:25'],
        ]);

        $bank = $this->getBank($character->homeCity);
        if (! $bank) {
            return back()->with('error', 'No bank found in your home city.');
        }

        $bank->setSetting('position_cap_percent', (int) $validated['position_cap_percent']);

        return back()->with('success', 'Trading risk updated.');
    }

    

    public function launderAddClient(Request $request): \Illuminate\Http\RedirectResponse
    {
        $character = $request->user()->character;
        $character->loadMissing(['homeCity', 'timers', 'career']);

        $bankingCareerId = Career::findByCode('banking')?->id;
        if (!$bankingCareerId || $character->career_id !== $bankingCareerId || (int) $character->career_rank < 2) {
            return back()->with('error', 'You must be a rank 2 or higher banker to add clients.');
        }
        if (!$character->isInHomeCity()) {
            return back()->with('error', 'You must be in your home city to add clients.');
        }

        $validated = $request->validate([
            'client_name' => 'required|string|max:30',
            'cut_pct'     => 'required|numeric|min:0.05|max:0.30',
        ]);

        $client = Character::findByName($validated['client_name']);
        if (!$client || $client->trashed()) {
            return back()->with('error', 'Client not found.');
        }
        if ($client->id === $character->id) {
            return back()->with('error', 'You cannot add yourself as a client.');
        }
        if (!$client->isAlive()) {
            return back()->with('error', 'Client is deceased.');
        }

        $bank = $character->homeCity ? $this->getBank($character->homeCity) : null;
        if (!$bank) {
            return back()->with('error', 'No bank found in your home city.');
        }

        $maxAmount = \App\Actions\BankerLaunderClient::maxAmount((int) $bank->balance);

        try {
            return DB::transaction(function () use ($character, $client, $maxAmount, $validated) {
                
                Character::lockForUpdate()->find($character->id);

                $existing = \App\Models\LaunderOffer::where('banker_id', $character->id)
                    ->where('client_id', $client->id)
                    ->active()
                    ->exists();
                if ($existing) {
                    return back()->with('error', 'You already have an active arrangement with this client.');
                }

                \App\Models\LaunderOffer::create([
                    'banker_id'   => $character->id,
                    'client_id'   => $client->id,
                    'amount'      => $maxAmount,
                    'cut_pct'     => (float) $validated['cut_pct'],
                    'amount_sent' => 0,
                    'status'      => \App\Models\LaunderOffer::STATUS_PENDING,
                ]);

                $cutPct = round((float) $validated['cut_pct'] * 100);

                JournalService::custom($client->id, 'banker_launder_added', [
                    'banker_name' => $character->display_name,
                    'max_amount'  => $maxAmount,
                    'cut_pct'     => (float) $validated['cut_pct'],
                    'message'     => "{$character->display_name} has added you as a laundering client. You can now send dirty money to be cleaned through their bank.",
                ]);

                Log::info('[BankerLaunder] Client added.', ['banker' => $character->id, 'client' => $client->id, 'amount' => $maxAmount, 'cut' => $validated['cut_pct']]);

                return back()->with('success', " You have successfully added {$client->display_name} as a laundering client.");
            });
        } catch (\Throwable $e) {
            Log::error('[BankerLaunder] Add client failed.', ['banker' => $character->id, 'error' => $e->getMessage()]);
            return back()->with('error', 'Could not add client. Please try again.');
        }
    }

    public function launderUpdateCut(Request $request, \App\Models\LaunderOffer $offer): \Illuminate\Http\RedirectResponse
    {
        $character = $request->user()->character;

        if ($offer->banker_id !== $character->id) {
            return back()->with('error', 'Unauthorized.');
        }
        if (!$offer->isActive()) {
            return back()->with('error', 'This arrangement is no longer active.');
        }
        if (($offer->amount_sent ?? 0) > 0) {
            return back()->with('error', 'Cannot change the cut while funds are queued — the client must have their dirty cash returned first.');
        }

        $validated = $request->validate(['cut_pct' => 'required|numeric|min:0.05|max:0.30']);
        $offer->update(['cut_pct' => (float) $validated['cut_pct']]);

        return back()->with('success', ' You have updated the cut to ' . round((float) $validated['cut_pct'] * 100) . '%');
    }

    public function launderExecute(Request $request, \App\Models\LaunderOffer $offer): \Illuminate\Http\RedirectResponse
    {
        $character = $request->user()->character;
        $character->loadMissing(['homeCity', 'timers', 'stats', 'career']);

        if ($offer->banker_id !== $character->id) {
            return back()->with('error', 'Unauthorized.');
        }
        if ($offer->status !== \App\Models\LaunderOffer::STATUS_CLIENT_SENT) {
            return back()->with('error', 'No funds have been queued for this arrangement yet.');
        }

        if($character->timers?->next_action_at && $character->timers->next_action_at->isFuture()){
            return back()->with('error', 'You must wait before performing another action.');
        }

 

        if (($offer->amount_sent ?? 0) <= 0) {
            return back()->with('error', 'No funds to launder.');
        }
        if ($character->isHospitalized() || $character->isJailed()) {
            return back()->with('error', 'You cannot execute a launder while incapacitated.');
        }
        if (!$character->isInHomeCity()) {
            return back()->with('error', 'You must be in your home city to execute this.');
        }
        try {
            return DB::transaction(function () use ($character, $offer) {
                [$firstId, $secondId] = $character->id < $offer->client_id
                    ? [$character->id, $offer->client_id]
                    : [$offer->client_id, $character->id];

                $locked = Character::with(['timers', 'stats', 'homeCity'])->lockForUpdate()->whereIn('id', [$firstId, $secondId])->get()->keyBy('id');

                $banker = $locked->get($character->id);
                $client = $locked->get($offer->client_id);
                $offer  = \App\Models\LaunderOffer::lockForUpdate()->find($offer->id);

                if (!$banker || !$client || !$offer) {
                    return back()->with('error', 'Could not lock records. Please try again.');
                }

                
              //check for ready action timer



                if ($offer->status !== \App\Models\LaunderOffer::STATUS_CLIENT_SENT || ($offer->amount_sent ?? 0) <= 0) {
                    return back()->with('error', 'No funds to launder.');
                }

                $bankingCareerId = Career::findByCode('banking')?->id;
                if (!$bankingCareerId || $banker->career_id !== $bankingCareerId || (int) $banker->career_rank < 2) {
                    return back()->with('error', 'You are no longer a qualified banker.');
                }
                if ($banker->isHospitalized() || $banker->isJailed()) {
                    return back()->with('error', 'You cannot execute a launder while incapacitated.');
                }
                if (!$banker->isInHomeCity()) {
                    return back()->with('error', 'You must be in your home city to execute this.');
                }

                $amount = (int) $offer->amount_sent;
                $cutPct = (float) $offer->cut_pct;

                $action  = new \App\Actions\BankerLaunderClient();
                $chance  = $action->chance($banker);
                $roll    = mt_rand(1, 100);
                $success = $roll <= $chance;

                Log::info('[BankerLaunder] Execute roll.', ['banker' => $banker->id, 'client' => $client->id, 'offer' => $offer->id, 'amount' => $amount, 'chance' => $chance, 'roll' => $roll, 'success' => $success]);

                $offer->update(['amount_sent' => 0, 'status' => \App\Models\LaunderOffer::STATUS_PENDING]);

                DB::table('character_timers')->where('character_id', $banker->id)
                    ->update(['next_action_at' => now()->addMinutes(15)->getTimestamp(), 'strength' => 0, 'strength_updated_at' => now()->getTimestamp()]);

                if (!$success) {
                    \App\Models\CharacterHistory::addHistory($banker, 'launders_failed');
                   JournalService::custom($client->id, 'banker_launder_failed', [
                        'banker_name' => $banker->display_name,
                        'amount'      => $amount,
                        'message'     => "{$banker->display_name}'s attempt to launder $" . number_format($amount) . " for you through a trade over‑invoicing scheme failed. The authorities flagged the trade invoice and the money was frozen.",
                    ]);
                    return back()->with('error', "The authorities decided now was the perfect time to flag the suspicious trade invoice, unfortunately the money was frozen and could not be retrieved.");
                }

                $banker->stats?->addLuck(mt_rand(25, 75), true);

                $banker->addXp(mt_rand(25, 50));

                $overheadAmount   = (int) floor($amount * \App\Actions\BankerLaunderClient::OVERHEAD);
                $cutAmount        = (int) floor($amount * $cutPct);
                $cleanAmount      = $amount - $overheadAmount - $cutAmount;

                $wireFee = 0;
                $bank    = $this->getBank($banker->homeCity ?? City::find($banker->home_city_id));
                if ($bank && (int) $client->home_city_id !== (int) $banker->home_city_id) {
                    $wireFeeRate = (float) $bank->getSetting('wire_fee', 0);
                    $wireFee     = $wireFeeRate > 0 ? max(1, (int) round($cleanAmount * ($wireFeeRate / 100))) : 0;
                }

                $finalCleanAmount = $cleanAmount - $wireFee;

                DB::table('characters')->where('id', $client->id)->increment('cash_in_bank', $finalCleanAmount);
                if ($bank) {
                    DB::table('businesses')->where('id', $bank->id)->increment('balance', $overheadAmount + $wireFee);
                }
                DB::table('characters')->where('id', $banker->id)->increment('cash_in_bank', $cutAmount);

                BankTransaction::record(characterId: $client->id, type: BankTransaction::TYPE_DEPOSIT, amount: $finalCleanAmount, balanceAfter: (int) $client->cash_in_bank + $finalCleanAmount, counterparty: $bank?->name ?? 'Bank', note: 'Trade Refund');
                BankTransaction::record(characterId: $banker->id, type: BankTransaction::TYPE_DEPOSIT, amount: $cutAmount, balanceAfter: (int) $banker->cash_in_bank + $cutAmount, counterparty: $bank?->name ?? 'Bank', note: 'Advisory Fee');
                
                City::increaseCrimeRateById($banker->home_city_id ?? $client->city_id, 0.1);

                \App\Models\CharacterHistory::addHistory($banker, 'launders_succeeded');
                \App\Models\CharacterHistory::addHistory($banker, 'earned_career', $cutAmount);

                $wireNotice = $wireFee > 0 ? " (after $" . number_format($wireFee) . " cross-city wire fee)" : '';

                JournalService::custom($client->id, 'banker_launder_executed', [
                    'banker_name'  => $banker->display_name,
                    'amount'       => $amount,
                    'clean_amount' => $finalCleanAmount,
                    'message'      => "Your laundering deal with {$banker->display_name} was successful. \$" . number_format($amount) . " in dirty cash was cleaned through the bank. You received \$" . number_format($finalCleanAmount) . " clean in your account{$wireNotice}.",
                ]);

               return back()->with('success', "You issued a Letter of Credit and laundered $" . number_format($amount) . " for {$client->display_name} through a trade over‑invoicing scheme. Your fee of $" . number_format($cutAmount) . " has been deposited into your account.");
            });
        } catch (\Throwable $e) {
            Log::error('[BankerLaunder] Execute failed.', ['banker' => $character->id, 'offer' => $offer->id, 'error' => $e->getMessage()]);
            return back()->with('error', 'Execution failed. Please try again.');
        }
    }

    public function launderCancel(Request $request, \App\Models\LaunderOffer $offer): \Illuminate\Http\RedirectResponse
    {
        $character = $request->user()->character;

        $isBanker = $offer->banker_id === $character->id;
        $isClient = $offer->client_id === $character->id;

        if (!$isBanker && !$isClient) {
            return back()->with('error', 'Unauthorized.');
        }
        if (!$offer->isActive()) {
            return back()->with('error', 'This arrangement is not active.');
        }

        try {
            return DB::transaction(function () use ($offer, $character, $isBanker) {
                $offer = \App\Models\LaunderOffer::lockForUpdate()->find($offer->id);
                if (!$offer || !$offer->isActive()) {
                    return back()->with('error', 'This arrangement is no longer active.');
                }

                $refundAmount = 0;
                if ($offer->status === \App\Models\LaunderOffer::STATUS_CLIENT_SENT && ($offer->amount_sent ?? 0) > 0) {
                    $refundAmount = (int) $offer->amount_sent;
                    $clientRow    = Character::lockForUpdate()->find($offer->client_id);
                    if ($clientRow) {
                        DB::table('characters')->where('id', $clientRow->id)->increment('dirty_cash', $refundAmount);
                    }
                }

                $offer->update(['status' => \App\Models\LaunderOffer::STATUS_CANCELLED, 'amount_sent' => 0]);

                $refundMsg        = $refundAmount > 0 ? ", and  $" . number_format($refundAmount) . " has been sent back to you" : '';
                $otherPartyId     = $isBanker ? $offer->client_id : $offer->banker_id;
                $clientRefundNote = ($refundAmount > 0 && $isBanker) ? ", and $" . number_format($refundAmount) . " has been sent back to you." : '';

                if ($otherPartyId) {
                    $msg = $isBanker
                        ? "{$character->display_name} has ended your laundering arrangement." . $clientRefundNote
                        : "{$character->display_name} has ended their laundering arrangement with you.";
                    JournalService::custom($otherPartyId, 'banker_launder_cancelled', ['message' => $msg]);
                }

                return back()->with('success', ' You have cancelled your laundering arrangement' . $refundMsg);
            });
        } catch (\Throwable $e) {
            Log::error('[BankerLaunder] Cancel failed.', ['character' => $character->id, 'offer' => $offer->id, 'error' => $e->getMessage()]);
            return back()->with('error', 'Cancellation failed. Please try again.');
        }
    }

    

    public function purchaseCd(Request $request, City $city): \Illuminate\Http\RedirectResponse
    {
        [$character, $city] = $this->getContext($request, $city);

        if ((int) $character->home_city_id !== (int) $city->id) {
            return back()->with('error', 'Certificates are only available at your home city bank.');
        }

        $bank = $this->getBank($city);
        if (! $bank) {
            return back()->with('error', 'No bank found in this city.');
        }

        $request->validate(['amount' => 'required|integer|min:1000']);
        $principal   = (int) $request->input('amount');
        $freeCapital = max(0, (int) $bank->balance - BankCertificate::totalLiabilityForBank($bank->id));

        if ($freeCapital <= 0) {
            return back()->with('error', 'This bank is not accepting new certificates at this time.');
        }

        $maxPrincipal = max(1_000, (int) floor($freeCapital * 0.25));
        if ($principal > $maxPrincipal) {
            return back()->with('error', 'The bank cannot accept a deposit of this size due to capital adequacy requirements.');
        }

        try {
            $result = DB::transaction(function () use ($character, $bank, $principal) {
                $charLock = DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();
                DB::table('businesses')->where('id', $bank->id)->lockForUpdate()->first();

                if ((int) $charLock->cash_in_bank < $principal) return 'insufficient';
                if (BankCertificate::activeCountForCharacter($character->id, $bank->id) > 0) return 'duplicate';

                $rateBp       = (int) round((float) $bank->getSetting('loan_interest', 15) * 100);
                $interestOwed = (int) floor($principal * $rateBp / 10_000);

                DB::table('characters')->where('id', $character->id)->decrement('cash_in_bank', $principal);
                DB::table('businesses')->where('id', $bank->id)->increment('balance', $principal);

                BankCertificate::create(['business_id' => $bank->id, 'character_id' => $character->id, 'principal' => $principal, 'rate' => $rateBp, 'interest_owed' => $interestOwed, 'matures_at' => now()->addHours(24)]);
               
                //a purchase is a 
                BankTransaction::record(characterId: $character->id, type: BankTransaction::TYPE_WITHDRAW, amount: $principal, balanceAfter: (int) $charLock->cash_in_bank - $principal, note: 'CD purchase');

                return 'ok';
            });

            return match ($result) {
                'ok'          => back()->with('success', 'Certificate of Deposit purchased. It will mature in 24 hours.'),
                'insufficient' => back()->with('error', 'Insufficient account balance.'),
                'duplicate'   => back()->with('error', 'You already hold an active certificate at this bank.'),
                default       => back()->with('error', 'Could not issue certificate. Please try again.'),
            };
        } catch (\Exception $e) {
            Log::error('[Bank] CD purchase failed.', ['character_id' => $character->id, 'bank_id' => $bank->id, 'principal' => $principal, 'error' => $e->getMessage()]);
            return back()->with('error', 'Could not issue certificate. Please try again.');
        }
    }

    

    private function autoSettleCd(Business $bank, ?int $managerId = null): void
    {
        $maturedIds = BankCertificate::matured()->forBank($bank->id)->orderBy('matures_at')->pluck('id');

        if ($managerId === null) {
            $bank->loadMissing('city');
            $managerId = $bank->city ? (int) ($bank->city->getServiceLeader('banking')['id'] ?? 0) ?: null : null;
        }

        foreach ($maturedIds as $cdId) {
            try {
                DB::transaction(function () use ($bank, $cdId, $managerId) {
                    $bankLock = DB::table('businesses')->where('id', $bank->id)->lockForUpdate()->first();

                    $cd = BankCertificate::where('id', $cdId)->whereNull('settled_at')->where('matures_at', '<=', now())->lockForUpdate()->first();
                    if (! $cd) return;

                    $holderId = $cd->character_id;
                    $charLock = DB::table('characters')->where('id', $holderId)->lockForUpdate()->first();
                    if (! $charLock) return;

                    $fullPayout = $cd->principal + $cd->interest_owed;
                    $remaining  = $fullPayout;
                    $paid       = 0;
                    $fromBank   = 0;

                    $fromBank = max(0, min((int) $bankLock->balance, $remaining));
                    if ($fromBank > 0) {
                        DB::table('businesses')->where('id', $bank->id)->decrement('balance', $fromBank);
                        $bankLock->balance -= $fromBank;
                        $paid      += $fromBank;
                        $remaining -= $fromBank;
                    }

                    if ($remaining > 0 && $bankLock->owner_id && (int) $bankLock->owner_id !== $holderId) {
                        $ownerLock = DB::table('characters')->where('id', $bankLock->owner_id)->lockForUpdate()->first();
                        if ($ownerLock) {
                            $ownerTotal = max(0, (int) $ownerLock->cash_on_hand) + max(0, (int) $ownerLock->cash_in_bank);
                            $fromOwner  = min($ownerTotal, $remaining);
                            if ($fromOwner > 0) {
                                $fromHand  = min((int) $ownerLock->cash_on_hand, $fromOwner);
                                $fromVault = $fromOwner - $fromHand;
                                if ($fromHand  > 0) DB::table('characters')->where('id', $bankLock->owner_id)->decrement('cash_on_hand', $fromHand);
                                if ($fromVault > 0) DB::table('characters')->where('id', $bankLock->owner_id)->decrement('cash_in_bank', $fromVault);
                                $paid      += $fromOwner;
                                $remaining -= $fromOwner;
                            }
                        }
                    }

                    if ($remaining > 0 && $managerId && (int) $managerId !== $holderId && (int) $managerId !== (int) ($bankLock->owner_id ?? 0)) {
                        $mgrLock = DB::table('characters')->where('id', $managerId)->lockForUpdate()->first();
                        if ($mgrLock) {
                            $mgrTotal = max(0, (int) $mgrLock->cash_on_hand) + max(0, (int) $mgrLock->cash_in_bank);
                            $fromMgr  = min($mgrTotal, $remaining);
                            if ($fromMgr > 0) {
                                $fromHand  = min((int) $mgrLock->cash_on_hand, $fromMgr);
                                $fromVault = $fromMgr - $fromHand;
                                if ($fromHand  > 0) DB::table('characters')->where('id', $managerId)->decrement('cash_on_hand', $fromHand);
                                if ($fromVault > 0) DB::table('characters')->where('id', $managerId)->decrement('cash_in_bank', $fromVault);
                                $paid      += $fromMgr;
                                $remaining -= $fromMgr;
                            }
                        }
                    }

                    $outcome = $remaining <= 0 ? BankCertificate::OUTCOME_MATURED : BankCertificate::OUTCOME_DEFAULTED;
                    if ($paid > 0) {
                        DB::table('characters')->where('id', $holderId)->increment('cash_in_bank', $paid);
                    }
                    $cd->update(['settled_at' => now(), 'outcome' => $outcome]);

                    $cityId = $bank->city?->id;
                    if ($cityId) {
                        if ($outcome === BankCertificate::OUTCOME_DEFAULTED) {
                            City::increaseCrimeRateById($cityId, 5.0);
                        } elseif ($fromBank < $fullPayout) {
                            City::increaseCrimeRateById($cityId, 2.0);
                        }
                    }

                    BankTransaction::record(characterId: $holderId, type: BankTransaction::TYPE_DEPOSIT, amount: $paid, balanceAfter: (int) $charLock->cash_in_bank + $paid, note: $outcome === BankCertificate::OUTCOME_MATURED ? 'CD matured' : 'CD default');

                    JournalService::custom($holderId, $outcome === BankCertificate::OUTCOME_MATURED ? 'cd_matured' : 'cd_defaulted', ['bank_name' => $bank->name, 'principal' => $cd->principal, 'interest' => $cd->interest_owed, 'payout' => $paid]);
                });
            } catch (\Exception $e) {
                Log::error('[Bank] CD auto-settle failed.', ['cd_id' => $cdId, 'bank_id' => $bank->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function isManagerOf(Character $character, City $city): bool
    {
        $manager = $city->getServiceLeader('banking');
        return $manager !== null && (int) ($manager['id'] ?? 0) === (int) $character->id;
    }
}
