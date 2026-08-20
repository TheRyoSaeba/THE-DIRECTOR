<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Career;
use App\Models\Character;
use App\Models\Property;
use App\Services\ConflictService;
use App\Services\CrimeService;
use App\Services\JournalService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $character = $user->character;

        return Inertia::render('Settings', [
            'settings' => [
                'email' => $user->email,
                'avatarUrl' => $character->custom_avatar_url ?? null,
                'glowColor' => $character->glow_color ?? 'cyan',
                'hideAchievements' => (bool) $user->hide_achievements,
                'disableCardFlip' => (bool) $user->disable_card_flip,
                'emailOptOut' => $user->email_opt_out_at !== null,
            ],
            'catalogItems' => \App\Models\GameItem::active()->orderBy('name')->limit(1000)->get()->map(fn($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
                'type' => $item->type,
                'slot' => $item->slot,
                'description' => $item->description,
                'image_url' => $item->image_url,
                'price' => $item->price,
                'stock' => $item->stock,
                'condition_percent' => $item->durability > 0 ? 100 : null,
            ]),
            'characterItems' => $character->items()
                ->whereHas('template')
                ->with('template')
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'name' => $item->template->name,
                        'slug' => $item->template->slug,
                        'slot' => $item->template->slot,
                        'type' => $item->template->type,
                        'condition_percent' => $item->conditionPercent(),
                        'is_equipped' => $item->is_equipped,
                        'equipped_slot' => $item->equipped_slot,
                        'location' => $item->location,
                        'image_url' => $item->template->image_url,
                        'data' => $item->data,
                    ];
                })->values(),
            'citySlug' => $character->homeCity?->slug ?? 'metropolis',
            'isInHomeCity' => $character->isInHomeCity(),
            'degrees' => $character->degrees ?? [],



            'property_condition' => in_array($character->property_condition, [
                Property::CONDITION_CONSTRUCTED,
                Property::CONDITION_DESTROYED,
            ]) ? $character->property_condition : null,
            'character_stats' => \App\Models\CharacterHistory::forSettingsPage($character),
        ]);
    }

    public function updateAccount(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => 'required|string',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'new_password' => ['sometimes', 'confirmed', Password::min(8)->numbers()],
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'The provided password is incorrect.']);
        }

        if (isset($data['email'])) {
            $user->email = $data['email'];
        }

        if (isset($data['new_password'])) {
            if (Hash::check($data['new_password'], $user->password)) {
                return back()->withErrors(['new_password' => 'The new password must be different from the current password.']);
            }
            $user->password = Hash::make($data['new_password']);
        }

        $user->save();

        Log::info('[AccountAudit] UPDATE', [
            'user_id' => $user->id,
            'character_id' => $user->character?->id,
            'fields' => array_keys($request->only(['email', 'new_password'])),
        ]);

        return back()->with('success', 'Account settings updated successfully.');
    }

    public function updateAppearance(Request $request)
    {
        $user = $request->user();
        $character = $user->character;

        if (!$character) {
            return back()->withErrors(['error' => 'No character found']);
        }

        $data = $request->validate([
            'avatar_url' => 'nullable|url|max:2048',
            'glow_color' => 'sometimes|in:cyan,red,green,blue,purple,yellow,pink',
            'hide_achievements' => 'sometimes|boolean',
            'disable_card_flip' => 'sometimes|boolean',
            'email_opt_out' => 'sometimes|boolean',
        ]);

        if (array_key_exists('avatar_url', $data)) {
            $resolvedUrl = empty($data['avatar_url']) ? null : \App\Services\ProxyService::resolveDirectUrl($data['avatar_url']);
            $character->custom_avatar_url = $resolvedUrl;
        }

        if (isset($data['glow_color'])) {
            $character->glow_color = $data['glow_color'];
        }

        $character->save();

        // hide_achievements is a user-level preference — persists across characters.
        if (isset($data['hide_achievements'])) {
            $user->hide_achievements = (bool) $data['hide_achievements'];
        }

        if (isset($data['disable_card_flip'])) {
            $user->disable_card_flip = (bool) $data['disable_card_flip'];
        }

        if (isset($data['email_opt_out'])) {
            // null = subscribed, timestamp = unsubscribed-at. We don't
            // clear-and-rewrite the timestamp on re-opt-out so we don't
            // lose history of when they originally unsubscribed.
            if ($data['email_opt_out'] && !$user->email_opt_out_at) {
                $user->email_opt_out_at = now();
            } elseif (!$data['email_opt_out']) {
                $user->email_opt_out_at = null;
            }
        }

        if (isset($data['hide_achievements']) || isset($data['disable_card_flip']) || isset($data['email_opt_out'])) {
            $user->save();
        }

        Log::info('[AppearanceAudit] UPDATE', [
            'character_id' => $character->id,
            'avatar_url' => $character->custom_avatar_url,
            'glow_color' => $character->glow_color,
            'hide_achievements' => $user->hide_achievements,
            'disable_card_flip' => $user->disable_card_flip,
        ]);

        return back()->with('success', 'User Settings Updated.');
    }

    public function equip(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:character_items,id',
            'slot' => 'required|string',
        ]);

        $character = $request->user()->character;

        try {
            $item = $character->items()->with('template')->find($request->id);
            if (!$item) {
                return back()->with('error', 'Item not found in your inventory.');
            }



            if (strtolower($item->template?->slug ?? '') === 'rcied') {
                return back()->with('error', 'Trying to equip a bomb on yourself is pretty stupid eh?');
            }

            $character->equipItem($item->id, $request->slot);
            return back()->with('success', 'Equipped item.');
        } catch (\Throwable $e) {
            Log::error('Item equipment failed', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'item_id' => $request->id,
                'slot' => $request->slot,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $isDomainError = ($e instanceof \Exception && !($e instanceof \Error));
            return back()->with('error', $isDomainError ? $e->getMessage() : 'Failed to equip item.');
        }
    }

    public function unequip(Request $request)
    {
        $request->validate(['slot' => 'required|string']);

        $character = $request->user()->character;

        try {
            $character->unequipItem($request->slot);
            return back()->with('success', 'Your ' . $request->slot . ' has been unequipped.');
        } catch (\Throwable $e) {
            Log::error('Item unequipment failed', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'slot' => $request->slot,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $isDomainError = ($e instanceof \Exception && !($e instanceof \Error));
            return back()->with('error', $isDomainError ? $e->getMessage() : 'Failed to unequip item.');
        }
    }

    public function drop(Request $request)
    {
        $request->validate(['id' => 'required|exists:character_items,id']);

        $character = $request->user()->character;

        try {
            $item = $character->items()->with('template')->find($request->id);
            if (!$item) {
                return back()->with('error', 'Item not found in your inventory.');
            }
            $character->dropItem($item->id);
            return back()->with('success', 'You have destroyed the item.');
        } catch (\Throwable $e) {
            Log::error('Item drop failed', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'item_id' => $request->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $isDomainError = ($e instanceof \Exception && !($e instanceof \Error));
            return back()->with('error', $isDomainError ? $e->getMessage() : 'Failed to drop item.');
        }
    }

    public function stash(Request $request)
    {
        $request->validate(['id' => 'required|exists:character_items,id']);

        $character = $request->user()->character;
        if (!$character) {
            return back()->with('error', 'No character found');
        }

        if (!$character->property_id) {
            return back()->with('error', 'You do not have a property to store items in.');
        }

        if (!$character->isInHomeCity()) {
            $propertyName = $character->property->name ?? 'property';
            return back()->with('error', "You cannot store this item in your {$propertyName} while you're away.");
        }

        if (in_array($character->property_condition, [null, Property::CONDITION_DESTROYED])) {
            $reason = $character->property_condition === Property::CONDITION_DESTROYED
                ? 'Your property was destroyed. Have it repaired by a technician before using it for storage.'
                : 'Your property has not been inspected yet. A technician must certify it before you can use it for storage.';
            return back()->with('error', $reason);
        }

        $item = $character->items()->with('template')->find($request->id);
        if (!$item) {
            return back()->with('error', 'Item not found in your inventory.');
        }

        if ($item->template->type === 'vehicle' && $item->durability_remaining !== null && $item->durability_remaining <= 0) {
            return back()->with('error', 'You cannot stash a totaled vehicle. It must be repaired first.');
        }

        try {
            $character->storageDeposit($request->id);
            return back()->with('success', 'Item stashed in safe.');
        } catch (\Throwable $e) {
            Log::error('Item stash failed', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'item_id' => $request->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $isDomainError = ($e instanceof \Exception && !($e instanceof \Error));
            return back()->with('error', $isDomainError ? $e->getMessage() : 'Failed to stash item.');
        }
    }

    public function unstash(Request $request)
    {
        $request->validate(['id' => 'required|exists:character_items,id']);

        $character = $request->user()->character;

        if (!$character->isInHomeCity()) {
            return back()->with('error', "You cannot retrieve items from your {$character->property->name} while you're away!");
        }

        if ($character->property_condition === Property::CONDITION_DESTROYED) {
            return back()->with('error', 'Your property was destroyed. Unless you want to crawl through the rubble to retrieve it, you should have it repaired first.');
        }

        try {
            $character->storageWithdraw($request->id);
            return back()->with('success', 'Your item is back in your hands.');
        } catch (\Throwable $e) {
            Log::error('Item unstash failed', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'item_id' => $request->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $isDomainError = ($e instanceof \Exception && !($e instanceof \Error));
            return back()->with('error', $isDomainError ? $e->getMessage() : 'Failed to withdraw item.');
        }
    }

    public function sell(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:character_items,id',
            'buyer_name' => 'required|string|max:30',
            'price' => 'required|integer|min:1|max:999999999',
        ]);

        $character = $request->user()->character;
        if (!$character) {
            return back()->with('error', 'No character found');
        }

        $buyerName = trim($request->input('buyer_name'));
        $price = (int) $request->input('price');
        $itemId = $request->input('id');

        $buyer = Character::findByName($buyerName);
        if (!$buyer) {
            return back()->with('error', 'Buyer not found.');
        }

        if ($buyer->id === $character->id) {
            return back()->with('error', 'Cannot sell to yourself.');
        }

        if (!$buyer->isAlive()) {
            return back()->with('error', 'Buyer is deceased.');
        }

        DB::beginTransaction();
        try {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

            $item = $character->items()->with('template')->find($itemId);
            if (!$item) {
                DB::rollBack();
                return back()->with('error', 'Item not found.');
            }

            if ($item->is_equipped) {
                DB::rollBack();
                return back()->with('error', 'Cannot sell equipped items. Unequip first.');
            }

            if ($item->location !== 'on_hand') {
                DB::rollBack();
                return back()->with('error', 'Item must be on hand to sell.');
            }

            if ($item->template->type === 'vehicle' && $item->durability_remaining !== null && $item->durability_remaining <= 0) {
                DB::rollBack();
                return back()->with('error', 'You cannot sell a totaled vehicle. It must be repaired first.');
            }

            $template = $item->template;

            \App\Services\JournalService::custom($buyer->id, 'item_sale_request', [
                'seller_id' => $character->id,
                'seller_name' => $character->display_name,
                'item_id' => $item->id,
                'item_name' => $template->name,
                'item_slug' => $template->slug,
                'item_type' => $template->type,
                'item_slot' => $template->slot,
                'item_image_url' => $template->image_url,
                'price' => $price,
                'condition_percent' => $item->conditionPercent(),
            ]);

            DB::commit();

            return back()->with('success', "Sale request sent to {$buyer->display_name} for \$" . number_format($price) . '. They can accept or decline in their journal.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Item sale request failed', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'item_id' => $itemId,
                'buyer_name' => $buyerName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->with('error', 'Sale request failed. Please try again.');
        }
    }

    // TODO DOES NOT RETURN FLASH MESSAGES
    public function quitCareer(Request $request)
    {
        $character = $request->user()->character;
        if (!$character) {
            return back()->withErrors(['error' => 'No character found']);
        }

        $request->validate([
            'confirmation_name' => [
                'required',
                'string',
                function ($attribute, $value, $fail) use ($character) {
                    if (strtolower($value) !== strtolower($character->display_name)) {
                        $fail("That's the wrong Name! Are you that scared?");
                    }
                }
            ],
        ]);
        //todo block if already unemployed
        if ($character->career_id === 13) {
            return back()->withErrors(['error' => 'You are already unemployed.']);
        }


        if ($character->homeCity->mayor_id === $character->id) {
            return back()->withErrors(['error' => 'You cannot quit your career while serving as Mayor.']);
        }

        if ($character->corporation_id) {
            return back()->with('error', 'You must leave your corporation before quitting your career.');
        }

        $policeCareerId = Career::findByCode('police')?->id;
        if ($policeCareerId && $character->career_id === $policeCareerId && $character->career_rank >= 4) {
            Business::where('owner_id', $character->id)
                ->where('city_id', $character->home_city_id)
                ->where('code', 'police')
                ->update(['owner_id' => null, 'is_purchasable' => true]);
        }

        Business::where('owner_id', $character->id)
            ->where('city_id', $character->home_city_id)
            ->where('code', 'city-hall')
            ->update(['owner_id' => null, 'is_purchasable' => true]);

        if (!$character->quitCareer()) {
            return back()->withErrors(['error' => 'Something went wrong, You can not quit your career.']);
        }

        return back()->with('success', 'You have quit your career. You are now unemployed. Good luck!');
    }

    public function quitLife(Request $request)
    {
        $character = $request->user()->character;
        if (!$character) {
            return back()->withErrors(['error' => 'No character found']);
        }

        $request->validate([
            'confirmation_name' => [
                'required',
                'string',
                function ($attribute, $value, $fail) use ($character) {
                    if (strtolower($value) !== strtolower($character->display_name)) {
                        $fail("That's the wrong Name! Are you that scared??");
                    }
                }
            ],
        ]);

        $character->kill('Suicide', 'Ended by your own Hand');

        return redirect()->route('death')->with('success', 'You have ended your life.');
    }

    public function quitStudy(Request $request)
    {
        $character = $request->user()->character;
        if (!$character) {
            return back()->withErrors(['error' => 'No character found']);
        }

        $request->validate([
            'degree_code' => 'required|string',
            'confirmation_name' => [
                'required',
                'string',
                function ($attribute, $value, $fail) use ($character) {
                    if (strtolower($value) !== strtolower($character->display_name)) {
                        $fail("That's the wrong Name! Are you that scared??");
                    }
                }
            ],
        ]);

        $code = strtolower($request->input('degree_code'));
        $degree = $character->getDegree($code);

        if (!$degree) {
            return back()->withErrors(['error' => 'You are not enrolled in this degree.']);
        }

        if (!empty($degree['completed_at'])) {
            return back()->withErrors(['error' => 'You cannot quit a completed degree.']);
        }

        if (!$character->quitDegree($code)) {
            return back()->withErrors(['error' => 'Failed to drop out. Please try again.']);
        }

        return back()->with('success', "You have dropped out of " . ucfirst($code) . ". Your progress has been erased.");
    }

    public function consume(Request $request)
    {
        $request->validate(['id' => 'required|integer']);

        $character = $this->requireCharacter($request);

        try {
            return DB::transaction(function () use ($character, $request) {
                $character = Character::with(['stats', 'timers'])
                    ->where('id', $character->id)
                    ->lockForUpdate()
                    ->first();

                $charItem = $character->items()->where('id', $request->input('id'))->lockForUpdate()->first();

                if (!$charItem) {
                    return back()->with('error', 'Item not found.');
                }

                if ($charItem->location !== 'on_hand') {
                    return back()->with('error', 'Item must be on hand to consume.');
                }

                $template = $charItem->template;

                if (!$template || $template->type !== 'item') {
                    return back()->with('error', 'This item cannot be consumed.');
                }

                $data = $template->data;

                if (!$data || empty($data['consumable']) || empty($data['effect'])) {
                    return back()->with('error', 'This item has no consumable effect.');
                }

                $effect = (string) ($data['effect'] ?? '');
                $isTimerReducer = in_array($effect, ['reduceStudyTimer', 'reduceTravelTimer'], true);

                if (!$isTimerReducer && !$character->canActivateTalent()) {
                    return back()->with('error', $character->hasTalentActive()
                        ? 'You must wait before attempting to consume another item.'
                        : 'You are on cooldown. Try again later.');
                }

                $stats = $character->stats;
                $message = null;
                $error = null;

                switch ($effect) {
                    case 'addDefense':
                        $stats->addDefense((int) ($data['amount'] ?? 100));
                        $message = "You used the drug. You feel more resilient.";
                        break;

                    case 'addIntelligence':
                        $stats->addIntelligence((int) ($data['amount'] ?? 100));
                        $message = "You used the drug. Your mind feels sharper.";
                        break;

                    case 'heal':
                        $amount = random_int((int) ($data['min'] ?? 5), (int) ($data['max'] ?? 15));
                        $character->heal($amount);
                        $message = "You used the drug as soon as you could, you already feel better since you recovered {$amount} HP.";
                        break;

                    case 'reduceStudyTimer':
                        if (!$this->reduceCharacterTimer($character, 'next_study_at')) {
                            $error = 'Your study timer is already ready!';
                        } else {
                            $message = 'You took the drug as soon as you could, you felt more focused and alert than you\'ve ever felt your entire life!';
                        }
                        break;

                    case 'reduceTravelTimer':
                        if (!$this->reduceCharacterTimer($character, 'next_travel_at')) {
                            $error = 'Your travel timer is already ready!';
                        } else {
                            $message = 'You injected the drug as soon as you could and felt much more awake and significantly less lethargic!';
                        }
                        break;

                    default:
                        $message = null;
                }

                if ($error) {
                    return back()->with('error', $error);
                }

                if (!$message) {
                    return back()->with('error', 'Unknown effect.');
                }

                if (!$isTimerReducer) {
                    $character->timers()->update(['next_talents_at' => now()->addHours(2)->getTimestamp()]);
                }

                $itemData = $charItem->data ?? [];
                $maxUnits = max(1, (int) ($data['pack_units'] ?? 1));
                $units = max(1, min($maxUnits, (int) ($itemData['units'] ?? $maxUnits)));

                if ($units > 1) {
                    $itemData['units'] = $units - 1;
                    $charItem->update(['data' => $itemData]);
                } else {
                    $charItem->delete();
                }

                Log::info('[ItemConsumed] Success', [
                    'user_id' => $request->user()->id,
                    'character_id' => $character->id,
                    'item_id' => $charItem->id,
                    'item_slug' => $template->slug,
                    'item_name' => $template->name,
                    'effect' => $data['effect'],
                    'units_remaining' => $units > 1 ? $units - 1 : 0,
                ]);

                return back()->with('success', $message);
            });
        } catch (\Throwable $e) {
            Log::error('Item consumption failed', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'item_id' => $request->input('id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->with('error', 'Failed to consume item.');
        }
    }

    private function reduceCharacterTimer(Character $character, string $column): bool
    {
        if (!in_array($column, ['next_study_at', 'next_travel_at'], true)) {
            return false;
        }

        $timer = $character->timers()->lockForUpdate()->first();
        $current = $timer?->{$column};

        if (!$current?->isFuture()) {
            return false;
        }

        $timer->update([$column => now()->getTimestamp()]);

        return true;
    }



    public function detonate(Request $request)
    {
        $request->validate(['id' => 'required|integer']);

        $character = $this->requireCharacter($request);

        if (!$character->ownsItem('rcied')) {
            return back()->with('error', 'Device not found. It must be on your person to detonate.');
        }

        if ($character->isHospitalized() || $character->isJailed()) {
            return back()->with('error', 'You cannot detonate a device from a hospital bed or a jail cell.');
        }

        if ($character->created_at->diffInDays(now()) < 5) {
            return back()->with('error', 'Your character must be at least 5 days old to detonate explosives.');
        }


        $rcied = $character->items()
            ->with('template')
            ->where('id', $request->input('id'))
            ->where('location', 'on_hand')
            ->first();

        if (!$rcied) {
            return back()->with('error', 'Device not found. It must be on your person to detonate.');
        }

        $template = $rcied->template;

        if (!$template || $template->type !== 'gadget' || strtolower($template->slug) !== 'rcied') {
            return back()->with('error', 'This item cannot be detonated.');
        }

        $itemData = $rcied->data ?? [];
        $targetCharId = $itemData['target_character_id'] ?? null;

        if (!$targetCharId) {
            return back()->with('error', 'This device has not been planted yet.');
        }

        try {
            return DB::transaction(function () use ($character, $rcied, $targetCharId) {

                $character = Character::with(['stats'])->lockForUpdate()->find($character->id);


                if ($targetCharId === $character->id) {
                    $rcied->delete();
                    return back()->with('error', 'You cannot detonate a bomb on yourself.');
                }


                $target = Character::with(['property', 'items.template', 'stats'])->withTrashed()->lockForUpdate()->find($targetCharId);


                $attackerInfBefore = $character->stats?->influence ?? 0;
                $defenderInfBefore = $target->stats?->influence ?? 0;


                if (!$target->isAlive()) {
                    $rcied->delete();
                    return back()->with('error', "You went to press the button, ready to watch {$target?->display_name}'s world go up in smoke — only to find out they'd already checked out permanently. The device has been neutralized.");
                }


                if (!$target->property_id || !$target->property) {
                    $rcied->delete();
                    return back()->with('error', "When you went to detonate the bomb, instead of {$target->display_name} blowing up, some random person who seemed to have bought their house did! Oh well — at least someone got the fireworks.");
                }

                $isExpired = isset($rcied->data['planted_at']) && Carbon::parse($rcied->data['planted_at'])->addHours(2)->isPast();

                if (!Property::hasBomb($target) || $isExpired) {
                    $rcied->delete();
                    $target->update(['property_condition' => Property::CONDITION_CONSTRUCTED]);
                    return back()->with('error', "You pressed the detonator, but nothing happened — just an embarrassing silence. Looks like someone swept {$target->display_name}'s place and found the device before you could use it.");
                }


                $healthBefore = $target->health;
                $maxHealthBefore = $target->max_health;
                $targetOnline = $target->isOnline();
                $isSafeFromBlast = $target->isHospitalized() || $target->isJailed();
                $targetInHomeCity = $target->isInHomeCity();


                $rcied->delete();

                $target->property_condition = Property::CONDITION_DESTROYED;
                $target->save();

                \App\Models\CharacterHistory::addHistory($target, 'times_hit');
                \App\Models\CharacterHistory::addHistory($character, 'attacks_landed');


                $giveProtection = (!$targetOnline && $targetInHomeCity && !$isSafeFromBlast);
                if ($giveProtection) {
                    $protMin = config('timers.protection_min');
                    $protMax = config('timers.protection_max');
                    $target->setProtection(rand($protMin * 2, $protMax * 2));
                }

                $propertyName = $target->property->name ?? 'their residence';


                $storedItems = $target->items()
                    ->whereIn('location', ['safe', 'garage'])
                    ->with('template')
                    ->lockForUpdate()
                    ->get();

                $plantedRcieds = $storedItems->filter(
                    fn($i) => strtolower($i->template?->slug ?? '') === 'rcied'
                    && !empty($i->data['target_character_id'])
                );


                foreach ($plantedRcieds as $plantedRcied) {
                    $bombVictim = Character::lockForUpdate()->find($plantedRcied->data['target_character_id']);
                    if ($bombVictim && \App\Models\Property::hasBomb($bombVictim)) {
                        $bombVictim->property_condition = Property::CONDITION_CONSTRUCTED;
                        $bombVictim->save();
                        JournalService::custom($bombVictim->id, 'bomb_plant_failed', [
                            'result' => 'detonator_destroyed',
                            'attacker_name' => $target->display_name,
                        ]);
                    }
                }

                $otherItems = $storedItems->reject(fn($i) => $plantedRcieds->contains('id', $i->id));
                $halfItems = ($targetInHomeCity && !$isSafeFromBlast)
                    ? $otherItems->shuffle()->take((int) ceil($otherItems->count() / 2))
                    : $otherItems;
                $itemsToDestroy = $plantedRcieds->merge($halfItems);

                if ($itemsToDestroy->isNotEmpty()) {
                    $target->items()->whereIn('id', $itemsToDestroy->pluck('id'))->delete();
                }

                $itemsLost = $itemsToDestroy->count();


                CrimeService::bombing($character, $target, $target->home_city_id);
                \App\Models\City::increaseCrimeRateById($target->home_city_id, 0.5);

                $character->addXp(random_int(50, 150));
                $character->halveProtection();


                $healthLoss = 0;
                $maxHealthLoss = 0;
                $isKill = false;

                if (!$targetInHomeCity || $isSafeFromBlast) {
                    $journalResult = $isSafeFromBlast ? 'home_safe_haven' : 'not_home';
                    JournalService::custom($target->id, 'bomb_detonated', [
                        'result' => $journalResult,
                        'attacker_name' => $character->display_name,
                        'property_name' => $propertyName,
                        'items_lost' => $itemsLost,
                    ]);

                    $this->logBombAudit($character, $target, [
                        'health_before' => $healthBefore,
                        'max_health_before' => $maxHealthBefore,
                        'target_online' => $targetOnline,
                        'target_safe' => $isSafeFromBlast,
                        'target_in_city' => $targetInHomeCity,
                        'items_lost' => $itemsLost,
                        'health_lost' => $healthLoss,
                        'is_kill' => $isKill,
                        'attacker_inf_before' => $attackerInfBefore,
                        'defender_inf_before' => $defenderInfBefore,
                    ]);

                    $itemsText = $itemsLost > 0 ? " {$itemsLost} " . ($itemsLost === 1 ? 'item was' : 'items were') . " lost in the rubble." : "";
                    if ($isSafeFromBlast) {
                        $reason = $target->isJailed() ? 'behind bars' : 'in the hospital';
                        return back()->with('success', "The {$propertyName} came down while {$target->display_name} was {$reason}. They escaped the blast, but their possessions were destroyed.{$itemsText}");
                    }
                    return back()->with('success', "{$target->display_name}'s {$propertyName} was reduced to rubble — too bad they weren't home to enjoy it.{$itemsText}");
                }


                $healthLoss = (int) floor($target->health * ($targetOnline ? 0.25 : 0.45));
                $maxHealthLoss = (int) floor($target->max_health * ($targetOnline ? 0.04 : 0.06));
                $isKill = ($target->health - $healthLoss <= 20) && (random_int(1, 100) <= 33);

                if ($character->stats && $target->stats) {
                    $attackerStats = $character->stats->effectiveStats();
                    $defenderStats = $target->stats->effectiveStats();
                    $damagePercent = $isKill ? 1.0 : ($healthLoss / 100);
                    ConflictService::applyInfluenceChanges($character, $target, $attackerStats, $defenderStats, $damagePercent);
                }

                if ($isKill) {
                    $target->kill('Explosive', "Eliminated by an RCIED detonation at {$propertyName}.");
                } else {
                    $target->update([
                        'health' => max(1, $target->health - $healthLoss),
                        'max_health' => max(max(1, $target->health - $healthLoss), max(1, $target->max_health - $maxHealthLoss)),
                    ]);
                }

                $this->logBombAudit($character, $target, [
                    'health_before' => $healthBefore,
                    'max_health_before' => $maxHealthBefore,
                    'target_online' => $targetOnline,
                    'target_safe' => $isSafeFromBlast,
                    'target_in_city' => $targetInHomeCity,
                    'items_lost' => $itemsLost,
                    'health_lost' => $healthLoss,
                    'max_health_lost' => $maxHealthLoss,
                    'is_kill' => $isKill,
                ]);

                if ($isKill) {
                    return back()->with('success', "The explosion was absolute. {$target->display_name} didn't stand a chance — they were eliminated instantly.");
                }

                $journalResult = $targetOnline ? 'home_online' : 'home_offline';
                JournalService::custom($target->id, 'bomb_detonated', [
                    'result' => $journalResult,
                    'attacker_name' => $character->display_name,
                    'property_name' => $propertyName,
                    'health_lost' => $healthLoss,
                    'max_health_lost' => $maxHealthLoss,
                    'items_lost' => $itemsLost,
                ]);

                $itemsText = $itemsLost > 0 ? " Some of their items were also lost." : "";
                $injuryText = $maxHealthLoss > 0 ? " They also sustained a permanent injury (-{$maxHealthLoss} max HP)." : "";

                if ($targetOnline) {
                    return back()->with('success', "{$target->display_name} was inside when the device went off for {$healthLoss} damage.{$injuryText}{$itemsText}");
                }
                return back()->with('success', "{$target->display_name} was home when their {$propertyName} came down on them, causing  {$healthLoss} damage.{$injuryText}{$itemsText}");
            });
        } catch (\Exception $e) {
            Log::error('[BombAudit] Detonation failed', [
                'attacker' => $character->display_name,
                'target_id' => $targetCharId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->with('error', 'An internal error occurred during detonation.');
        }
    }
    private function logBombAudit(Character $attacker, Character $defender, array $data): void
    {
        Log::info('[BombAudit] DETONATION', [
            'attacker' => [
                'id' => $attacker->id,
                'name' => $attacker->display_name,
                'influence_before' => $data['attacker_inf_before'] ?? null,
                'influence_after' => $attacker->stats?->influence ?? null,
            ],
            'defender' => [
                'id' => $defender->id,
                'name' => $defender->display_name,
                'health_before' => $data['health_before'] ?? null,
                'health_after' => $defender->health,
                'max_health_before' => $data['max_health_before'] ?? null,
                'max_health_after' => $defender->max_health,
                'influence_before' => $data['defender_inf_before'] ?? null,
                'influence_after' => $defender->stats?->influence ?? null,
                'is_kill' => $data['is_kill'] ?? false,
            ],
            'context' => [
                'target_online' => $data['target_online'] ?? false,
                'target_safe' => $data['target_safe'] ?? false,
                'target_in_city' => $data['target_in_city'] ?? false,
                'items_lost' => $data['items_lost'] ?? 0,
                'health_lost' => $data['health_lost'] ?? 0,
                'max_health_lost' => $data['max_health_lost'] ?? 0,
            ]
        ]);
    }
}
