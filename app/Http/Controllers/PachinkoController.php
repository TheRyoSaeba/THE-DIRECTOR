<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Character;
use App\Models\City;
use App\Support\SafeCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PachinkoController extends CityController
{
    private const HIT_CHANCE = 0.29;        
    private const HIT_PAYOUT_MULTIPLIER = 2.7; 
    private const WIN_CHANCE_FLOOR = 0.10;       
    private const WIN_CHANCE_CEILING = 0.30;     

    private const JACKPOT_PAYOUT_MULTIPLIER = 15;
    private const JACKPOT_STAKE_DIVISOR = 8_000_000;
    private const JACKPOT_MAX_CHANCE = 0.012;

    private const GREED_MIN = -0.05;
    private const GREED_MAX = 0.05; 
    private const GREED_DEFAULT = 0.05;

    private const COST_MIN = 100;
    private const COST_MAX = 1_000;
    private const BALLS_MIN = 1;
    private const BALLS_MAX = 1_000;

    private const BJ_DECKS_MIN = 1;
    private const BJ_DECKS_MAX = 8;
    private const BJ_DECKS_DEFAULT = 6;
    private const BJ_BIAS_MIN = 0.08;
    private const BJ_BIAS_MAX = 0.45;
    private const BJ_PENETRATION = 0.75;
    private const BJ_BLACKJACK_PAYOUT = 1.5;
    private const BJ_BET_MIN = 1_000;
    private const BJ_BET_MAX = 10_000;
    private const BJ_STATE_TTL = 3_600;

 
    public function index(Request $request, City $city): RedirectResponse|Response
    {
        [$character, $city] = $this->getContext($request, $city);

        $pachinko = Business::forCity($city, 'pachinko');

        if (! $pachinko) {
            return redirect()
                ->route('city.show', $city->slug)
                ->with('error', 'There is no Pachinko parlor in this city.');
        }

        $isOwner = $pachinko->owner_id === $character->id;

        return Inertia::render('City/Pachinko', [
            'cityData' => [
                'name' => $city->name,
                'slug' => $city->slug,
            ],
            'business' => [
                'name' => $pachinko->name,
                'image_url' => $pachinko->image_url,
                'owner_name' => $pachinko->owner?->display_name ?? 'STATE OWNED',
                'is_owner' => $isOwner,
                'settings' => [
                    'cost_per_ball' => $this->costPerBall($pachinko),
                    'bj_bet_min' => self::BJ_BET_MIN,
                    'bj_bet_max' => self::BJ_BET_MAX,
                    'bj_blackjack_payout' => self::BJ_BLACKJACK_PAYOUT,
                ],
                'owner_settings' => $isOwner ? [
                    'cost_per_ball' => $this->costPerBall($pachinko),
                    'owner_greed' => $this->ownerGreed($pachinko),
                    'number_of_decks' => $this->numberOfDecks($pachinko),
                ] : null,
            ],
            'blackjack_hand' => ($hand = $this->loadHand($character->id)) ? $this->presentHand($hand) : null,
        ]);
    }

    
    public function spin(Request $request, City $city): RedirectResponse
    {
        [$character, $city] = $this->getContext($request, $city);

        $balls = (int) $request->validate([
            'balls' => 'required|integer|min:' . self::BALLS_MIN . '|max:' . self::BALLS_MAX,
        ])['balls'];

        try {
            $outcome = DB::transaction(function () use ($character, $city, $balls) {
                $pachinko = Business::byCity($city)
                    ->where('code', 'ILIKE', 'pachinko')
                    ->lockForUpdate()
                    ->first();

                if (! $pachinko) {
                    return ['error' => 'Pachinko parlor not found in this city.'];
                }

                $costPerBall = $this->costPerBall($pachinko);
                $totalCost = $balls * $costPerBall;

                if (! $character->removeCash($totalCost, false)) {
                    return ['error' => 'Insufficient funds.'];
                }

            
                $pachinko->balance += $totalCost;

                
                if ($pachinko->balance < $this->worstCasePayout($balls, $costPerBall)) {
                    $character->addCash($totalCost, false, false);
                    return ['error' => 'The owner cannot possibly afford to make that bet!'];
                }

                $result = $this->playRound($balls, $costPerBall, $this->ownerGreed($pachinko));

                $pachinko->balance -= $result['winnings'];
                if ($result['winnings'] > 0) {
                    $character->addCash($result['winnings'], false, false);
                }

                if ($result['jackpot']) {
                    $character->stats->addLuck(100, false);
                    $character->stats->save();
                }

                $character->save();
                $pachinko->save();

                return [
                    'balls' => $balls,
                    'totalCost' => $totalCost,
                    ...$result,
                ];
            });

            if (isset($outcome['error'])) {
                return back()->with('error', $outcome['error']);
            }

            return back()->with(
                $outcome['net'] >= 0 ? 'success' : 'error',
                $this->resultMessage($outcome),
            );
        } catch (Throwable $e) {
            Log::error('Pachinko spin failed', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'city_slug' => $city->slug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Unable to process your request. Please try again.');
        }
    }

    
    public function updateSettings(Request $request, City $city): RedirectResponse
    {
        [$character, $city] = $this->getContext($request, $city);

        try {
            $pachinko = Business::forCity($city, 'pachinko');

            if (! $pachinko) {
                abort(404);
            }

            if ($pachinko->owner_id !== $character->id) {
                abort(403, 'You do not own this business.');
            }

            $validated = $request->validate([
                'cost_per_ball' => 'required|integer|min:' . self::COST_MIN . '|max:' . self::COST_MAX,
                'owner_greed' => 'required|numeric|min:' . self::GREED_MIN . '|max:' . self::GREED_MAX,
                'number_of_decks' => 'required|integer|min:' . self::BJ_DECKS_MIN . '|max:' . self::BJ_DECKS_MAX,
            ]);

            $pachinko->setSetting('cost_per_ball', (int) $validated['cost_per_ball']);
            $pachinko->setSetting('owner_greed', (float) $validated['owner_greed']);
            $pachinko->setSetting('number_of_decks', (int) $validated['number_of_decks']);

            return back()->with('success', 'Pachinko settings updated.');
        } catch (Throwable $e) {
            Log::error('Pachinko settings update failed', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'city_slug' => $city->slug,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Unable to update settings. Please try again.');
        }
    }

    // ===================== BLACKJACK =====================

    public function blackjackDeal(Request $request, City $city): RedirectResponse
    {
        [$character, $city] = $this->getContext($request, $city);

        $bet = (int) $request->validate([
            'bet' => 'required|integer|min:' . self::BJ_BET_MIN . '|max:' . self::BJ_BET_MAX,
        ])['bet'];

        try {
            $outcome = DB::transaction(function () use ($character, $city, $bet) {
                if ($this->loadHand($character->id) !== null) {
                    return ['error' => 'Finish your current hand first.'];
                }

                $pachinko = Business::byCity($city)
                    ->where('code', 'ILIKE', 'pachinko')
                    ->lockForUpdate()
                    ->first();

                if (! $pachinko) {
                    return ['error' => 'Pachinko parlor not found in this city.'];
                }

                // The parlor must be able to cover a blackjack payout (bet x 2.5
                // returned) before accepting the wager.
                $maxReturn = (int) ceil($bet * (1 + self::BJ_BLACKJACK_PAYOUT));
                if ($pachinko->balance + $bet < $maxReturn) {
                    return ['error' => 'The house cannot cover that bet right now.'];
                }

                if (! $character->removeCash($bet, false)) {
                    return ['error' => 'Insufficient funds.'];
                }

                $pachinko->balance += $bet;

                $decks = $this->numberOfDecks($pachinko);
                $shoe = $this->buildShoe($decks);

                $player = [array_pop($shoe), array_pop($shoe)];
                $dealer = [array_pop($shoe), array_pop($shoe)];

                $state = [
                    'bet' => $bet,
                    'decks' => $decks,
                    'shoe' => $shoe,
                    'player' => $player,
                    'dealer' => $dealer,
                    'phase' => 'player_turn',
                    'result' => null,
                    'payout' => 0,
                    'doubled' => false,
                ];

                // Natural blackjack check on the deal.
                $playerBJ = $this->isBlackjack($player);
                $dealerBJ = $this->isBlackjack($dealer);

                if ($playerBJ || $dealerBJ) {
                    $this->settle($state, $character, $pachinko);
                } else {
                    $character->save();
                    $pachinko->save();
                    $this->saveHand($character->id, $state);
                }

                return ['state' => $state];
            });

            return $this->blackjackResponse($outcome);
        } catch (Throwable $e) {
            return $this->blackjackError($e, $request, $character, $city, 'deal');
        }
    }

    public function blackjackHit(Request $request, City $city): RedirectResponse
    {
        [$character, $city] = $this->getContext($request, $city);

        try {
            $outcome = DB::transaction(function () use ($character, $city) {
                $state = $this->loadHand($character->id);
                if ($state === null) {
                    return ['error' => 'No hand in progress.'];
                }
                if ($state['phase'] !== 'player_turn') {
                    return ['error' => 'You cannot hit right now.'];
                }

                $state['player'][] = array_pop($state['shoe']);

                if ($this->handValue($state['player'])['total'] > 21) {
                    // Player busts: the bet already sits in the parlor, nothing
                    // to pay out, so no parlor lock/write is needed.
                    $state['phase'] = 'settled';
                    $state['result'] = 'lose';
                    $state['payout'] = 0;
                    $this->forgetHand($character->id);
                } else {
                    $this->saveHand($character->id, $state);
                }

                return ['state' => $state];
            });

            return $this->blackjackResponse($outcome);
        } catch (Throwable $e) {
            return $this->blackjackError($e, $request, $character, $city, 'hit');
        }
    }

    public function blackjackStand(Request $request, City $city): RedirectResponse
    {
        [$character, $city] = $this->getContext($request, $city);

        try {
            $outcome = DB::transaction(function () use ($character, $city) {
                $state = $this->loadHand($character->id);
                if ($state === null) {
                    return ['error' => 'No hand in progress.'];
                }
                if ($state['phase'] !== 'player_turn') {
                    return ['error' => 'You cannot stand right now.'];
                }

                $pachinko = Business::byCity($city)
                    ->where('code', 'ILIKE', 'pachinko')
                    ->lockForUpdate()
                    ->first();
                if (! $pachinko) {
                    return ['error' => 'Pachinko parlor not found in this city.'];
                }

                $this->playDealer($state, $this->houseBias($state['decks']));
                $this->settle($state, $character, $pachinko);

                return ['state' => $state];
            });

            return $this->blackjackResponse($outcome);
        } catch (Throwable $e) {
            return $this->blackjackError($e, $request, $character, $city, 'stand');
        }
    }

    public function blackjackDouble(Request $request, City $city): RedirectResponse
    {
        [$character, $city] = $this->getContext($request, $city);

        try {
            $outcome = DB::transaction(function () use ($character, $city) {
                $state = $this->loadHand($character->id);
                if ($state === null) {
                    return ['error' => 'No hand in progress.'];
                }
                if ($state['phase'] !== 'player_turn' || count($state['player']) !== 2) {
                    return ['error' => 'You can only double on your first two cards.'];
                }

                $pachinko = Business::byCity($city)
                    ->where('code', 'ILIKE', 'pachinko')
                    ->lockForUpdate()
                    ->first();
                if (! $pachinko) {
                    return ['error' => 'Pachinko parlor not found in this city.'];
                }

                // Match the original bet. Solvency check before taking it.
                $maxReturn = (int) ceil($state['bet'] * 2 * 2);
                if ($pachinko->balance + $state['bet'] < $maxReturn) {
                    return ['error' => 'The house cannot cover a double right now.'];
                }

                if (! $character->removeCash($state['bet'], false)) {
                    return ['error' => 'Insufficient funds to double.'];
                }

                $pachinko->balance += $state['bet'];
                $state['bet'] *= 2;
                $state['doubled'] = true;

                // Exactly one card, then the hand is forced to stand.
                $state['player'][] = array_pop($state['shoe']);

                if ($this->handValue($state['player'])['total'] > 21) {
                    $state['phase'] = 'settled';
                    $state['result'] = 'lose';
                    $state['payout'] = 0;
                    $character->save();
                    $pachinko->save();
                    $this->forgetHand($character->id);
                } else {
                    $this->playDealer($state, $this->houseBias($state['decks']));
                    $this->settle($state, $character, $pachinko);
                }

                return ['state' => $state];
            });

            return $this->blackjackResponse($outcome);
        } catch (Throwable $e) {
            return $this->blackjackError($e, $request, $character, $city, 'double');
        }
    }

    // ===================== BLACKJACK INTERNALS =====================

    /**
     * Settle a finished hand: compare totals (applying the house bias on
     * marginal non-losses), pay the player, clear the stored hand. Money in:
     * the bet already sits in the parlor balance. Money out: the player's
     * total return (stake back + winnings) is added to their cash and removed
     * from the parlor.
     */
    private function settle(array &$state, Character $character, Business $pachinko): void
    {
        $bet = $state['bet'];
        $player = $state['player'];
        $dealer = $state['dealer'];

        $playerBJ = $this->isBlackjack($player);
        $dealerBJ = $this->isBlackjack($dealer);
        $playerTotal = $this->handValue($player)['total'];
        $dealerTotal = $this->handValue($dealer)['total'];

        $result = 'lose';
        $payout = 0; // total returned to the player (includes stake)

        if ($playerBJ && $dealerBJ) {
            $result = 'push';
            $payout = $bet;
        } elseif ($playerBJ) {
            $result = 'blackjack';
            $payout = (int) ceil($bet * (1 + self::BJ_BLACKJACK_PAYOUT));
        } elseif ($dealerBJ || $playerTotal > 21) {
            $result = 'lose';
            $payout = 0;
        } elseif ($dealerTotal > 21 || $playerTotal > $dealerTotal) {
            $result = 'win';
            $payout = $bet * 2;
        } elseif ($playerTotal < $dealerTotal) {
            $result = 'lose';
            $payout = 0;
        } else {
            $result = 'push';
            $payout = $bet;
        }

        if ($payout > 0) {
            $character->addCash($payout, false, false);
            $pachinko->balance -= $payout;
        }

        // A natural blackjack (21 on the deal) grants luck, mirroring the
        // pachinko jackpot. Only the natural — not an ordinary win.
        if ($result === 'blackjack') {
            $character->stats->addLuck(100, false);
            $character->stats->save();
        }

        $state['phase'] = 'settled';
        $state['result'] = $result;
        $state['payout'] = $payout;
        $state['net'] = $payout - $bet;

        $character->save();
        $pachinko->save();
        $this->forgetHand($character->id);
    }

    /**
     * Dealer draws to 17, hitting soft 17 (H17). The house bias (Option B) is
     * expressed HERE, through the cards — never by overriding the final
     * comparison. A player can see the cards and do the math, so a post-hoc
     * flip would look rigged. Instead, when the dealer would otherwise stand on
     * a total the player beats, the loaded shoe occasionally lets the dealer
     * take one more card chosen to improve (not bust) the hand. Every dealt
     * card is real and the table always adds up.
     */
    private function playDealer(array &$state, float $bias = 0.0): void
    {
        $state['phase'] = 'dealer_turn';

        $playerTotal = $this->handValue($state['player'])['total'];

        while (! empty($state['shoe'])) {
            $value = $this->handValue($state['dealer']);

            $mustHit = $value['total'] < 17
                || ($value['total'] === 17 && $value['soft']); // H17

            if ($mustHit) {
                $state['dealer'][] = array_pop($state['shoe']);
                continue;
            }

            // Dealer would normally stand here. If the player is currently
            // ahead, the loaded shoe gets a chance to deal the dealer a
            // rescue card — but only a real card from the shoe that improves
            // the hand without busting it. If no such card exists, the dealer
            // stands honestly and the player keeps their win.
            $dealerWinning = $value['total'] >= $playerTotal && $playerTotal <= 21;
            if (! $dealerWinning && $bias > 0.0 && $this->randomFloat() < $bias) {
                $rescueIndex = $this->findRescueCard($state['shoe'], $value['total'], $playerTotal);
                if ($rescueIndex !== null) {
                    $card = $state['shoe'][$rescueIndex];
                    array_splice($state['shoe'], $rescueIndex, 1);
                    $state['dealer'][] = $card;
                    continue;
                }
            }

            break;
        }
    }

    /**
     * Find a card already in the shoe that, added to the dealer's current
     * total, brings them level-or-ahead of the player without busting. Returns
     * the shoe index of the first such card, or null if none qualifies. This
     * keeps the bias honest: it only ever surfaces a card that genuinely
     * exists in the remaining shoe.
     */
    private function findRescueCard(array $shoe, int $dealerTotal, int $playerTotal): ?int
    {
        foreach ($shoe as $i => $card) {
            // Aces are worth 11 unless that busts, then 1.
            $add = ($card === 11 && $dealerTotal + 11 > 21) ? 1 : $card;
            $newTotal = $dealerTotal + $add;

            if ($newTotal <= 21 && $newTotal >= $playerTotal) {
                return $i;
            }
        }

        return null;
    }

    private function houseBias(int $decks): float
    {
        $t = ($decks - self::BJ_DECKS_MIN) / (self::BJ_DECKS_MAX - self::BJ_DECKS_MIN);

        return self::BJ_BIAS_MIN + $t * (self::BJ_BIAS_MAX - self::BJ_BIAS_MIN);
    }

    /**
     * Build and shuffle a shoe of N decks. Each card is its blackjack value
     * (11 for ace, 10 for face cards); aces are softened later in handValue.
     * We trim to the penetration point so deep-count play isn't possible.
     */
    private function buildShoe(int $decks): array
    {
        $single = [];
        // 4 suits each of 2..10, J/Q/K (=10), A (=11)
        foreach (range(1, 4) as $ignored) {
            foreach ([2, 3, 4, 5, 6, 7, 8, 9, 10, 10, 10, 10, 11] as $card) {
                $single[] = $card;
            }
        }

        $shoe = [];
        for ($d = 0; $d < $decks; $d++) {
            foreach ($single as $card) {
                $shoe[] = $card;
            }
        }

        // Fisher–Yates with the CSPRNG.
        for ($i = count($shoe) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$shoe[$i], $shoe[$j]] = [$shoe[$j], $shoe[$i]];
        }

        // Keep only the playable portion (penetration); cards are drawn via
        // array_pop from the end.
        $keep = (int) floor(count($shoe) * self::BJ_PENETRATION);

        return array_slice($shoe, 0, max($keep, 20));
    }

    /**
     * Total a hand, softening aces from 11 to 1 as needed. Returns the best
     * total <= 21 where possible, plus whether the hand is soft.
     */
    private function handValue(array $cards): array
    {
        $total = array_sum($cards);
        $aces = count(array_filter($cards, fn ($c) => $c === 11));

        while ($total > 21 && $aces > 0) {
            $total -= 10;
            $aces--;
        }

        return ['total' => $total, 'soft' => $aces > 0];
    }

    private function isBlackjack(array $cards): bool
    {
        return count($cards) === 2 && $this->handValue($cards)['total'] === 21;
    }

    private function numberOfDecks(Business $pachinko): int
    {
        $decks = (int) data_get($pachinko->data ?? [], 'number_of_decks', self::BJ_DECKS_DEFAULT);

        return max(self::BJ_DECKS_MIN, min(self::BJ_DECKS_MAX, $decks));
    }

    // ── Hand state (Redis via SafeCache) ──────────────────────────────────────

    private function handKey(int $characterId): string
    {
        return "blackjack:hand:{$characterId}";
    }

    private function loadHand(int $characterId): ?array
    {
        $state = SafeCache::get($this->handKey($characterId));

        return is_array($state) ? $state : null;
    }

    private function saveHand(int $characterId, array $state): void
    {
        SafeCache::put($this->handKey($characterId), $state, self::BJ_STATE_TTL);
    }

    private function forgetHand(int $characterId): void
    {
        SafeCache::forget($this->handKey($characterId));
    }

    /**
     * The view of the hand safe to send to the client. While the player is
     * acting, the dealer's hole card (and the shoe) are never revealed — this
     * is both correct blackjack and anti-cheat. Once settled, everything shows.
     */
    private function presentHand(array $state): array
    {
        $settled = $state['phase'] === 'settled';
        $revealDealer = $settled || $state['phase'] === 'dealer_turn';

        $dealerCards = $revealDealer
            ? $state['dealer']
            : [$state['dealer'][0]];

        return [
            'phase' => $state['phase'],
            'bet' => $state['bet'],
            'doubled' => $state['doubled'] ?? false,
            'player' => array_values($state['player']),
            'player_total' => $this->handValue($state['player'])['total'],
            'dealer' => array_values($dealerCards),
            'dealer_total' => $revealDealer ? $this->handValue($state['dealer'])['total'] : null,
            'dealer_hidden' => ! $revealDealer,
            'result' => $state['result'] ?? null,
            'payout' => $state['payout'] ?? 0,
            'net' => $state['net'] ?? null,
            'can_double' => $state['phase'] === 'player_turn' && count($state['player']) === 2,
        ];
    }

    private function blackjackResponse(array $outcome): RedirectResponse
    {
        if (isset($outcome['error'])) {
            return back()->with('error', $outcome['error']);
        }

        return back()->with('blackjack', $this->presentHand($outcome['state']));
    }

    private function blackjackError(Throwable $e, Request $request, ?Character $character, City $city, string $action): RedirectResponse
    {
        Log::error('Blackjack action failed', [
            'action' => $action,
            'user_id' => $request->user()->id,
            'character_id' => $character->id ?? null,
            'city_slug' => $city->slug,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return back()->with('error', 'Unable to process your request. Please try again.');
    }

 
 
    private function playRound(int $balls, int $costPerBall, float $greed): array
    {
        $totalCost = $balls * $costPerBall;

        $winChance = $this->winChance($greed);
        $hits = $this->rollHits($balls, $winChance);

        $winnings = $hits * (int) round($costPerBall * self::HIT_PAYOUT_MULTIPLIER);

        $jackpot = $this->rollJackpot($totalCost);
        $jackpotAmount = $jackpot ? (int) ceil($totalCost * self::JACKPOT_PAYOUT_MULTIPLIER) : 0;
        $winnings += $jackpotAmount;

        return [
            'hits' => $hits,
            'winnings' => $winnings,
            'jackpot' => $jackpot,
            'jackpotAmount' => $jackpotAmount,
            'net' => $winnings - $totalCost,
        ];
    }
 
    private function winChance(float $greed): float
    {
        return max(
            self::WIN_CHANCE_FLOOR,
            min(self::WIN_CHANCE_CEILING, self::HIT_CHANCE - $greed),
        );
    }

   
    private function rollHits(int $balls, float $winChance): int
    {
        if ($balls <= 100) {
            $hits = 0;
            for ($i = 0; $i < $balls; $i++) {
                if ($this->randomFloat() <= $winChance) {
                    $hits++;
                }
            }
            return $hits;
        }

        $mean = $balls * $winChance;
        $stdDev = sqrt($balls * $winChance * (1 - $winChance));

        $u1 = $this->randomFloat();
        $u2 = $this->randomFloat();
        $gaussian = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);

        $hits = (int) round($mean + $gaussian * $stdDev);

        return max(0, min($balls, $hits));
    }

    
    private function rollJackpot(int $totalCost): bool
    {
        $chance = min($totalCost / self::JACKPOT_STAKE_DIVISOR, self::JACKPOT_MAX_CHANCE);

        return $this->randomFloat() <= $chance;
    }

   
    private function worstCasePayout(int $balls, int $costPerBall): int
    {
        $maxHits = (int) ceil($balls * $costPerBall * self::HIT_PAYOUT_MULTIPLIER);
        $maxJackpot = (int) ceil($balls * $costPerBall * self::JACKPOT_PAYOUT_MULTIPLIER);

        return $maxHits + $maxJackpot;
    }
 
    private function randomFloat(): float
    {
        return random_int(1, 1_000_000) / 1_000_000;
    }

   

    private function costPerBall(Business $pachinko): int
    {
        $cost = (int) data_get($pachinko->data ?? [], 'cost_per_ball', 100);

        return max(self::COST_MIN, min(self::COST_MAX, abs($cost)));
    }

    private function ownerGreed(Business $pachinko): float
    {
        $greed = (float) data_get($pachinko->data ?? [], 'owner_greed', self::GREED_DEFAULT);

        return max(self::GREED_MIN, min(self::GREED_MAX, $greed));
    }

    
    private function resultMessage(array $outcome): string
    {
        $balls = $outcome['balls'];
        $hits = $outcome['hits'];
        $winnings = number_format($outcome['winnings']);
        $net = number_format(abs($outcome['net']));

        if ($outcome['jackpot']) {
            $jackpot = number_format($outcome['jackpotAmount']);

            return "JACKPOT! {$balls} balls in, the machine erupts — a \${$jackpot} windfall on top of your hits, "
                . "returning \${$winnings} for a net profit of \${$net}!";
        }

        $opening = "You launched {$balls} balls and watched {$hits} hit, returning \${$winnings} to you, ";

        return $opening . match (true) {
            $outcome['net'] > 0 => "making a net profit of \${$net}!",
            $outcome['net'] < 0 => "but you lost \${$net}.",
            default => 'and managing to break even!',
        };
    }
}
