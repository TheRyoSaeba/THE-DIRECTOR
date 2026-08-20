<?php

namespace App\Traits;

use App\Models\CharacterItem;
use App\Models\GameItem;
use Illuminate\Support\Facades\DB;

trait HasInventory
{

    //! Clothing is TYPE CLOTHING SLOT : HEAD, BODY, BOTTOM, SHOES ACCESSORY */
    //! WEAPONS ARE  TYPE WEAPON  SLOT WEAPON */
    //! ARMOR IS TYPE ARMOR SLOT ARMOR */
    //! VEHICLES ARE TYPE VEHICLE SLOT VEHICLE */
    //! ALL  TYPE  ITEMS SLOT ITEM  ARE CONSUMABLE ;   THE DATA IS STRUCTURED {"consumable":true,"effect":"addDefense","amount":10}*/
    //! ALL NON CONSUMABLE ITEMS ARE TYPE GADGET SLOT GADGET */ SO anything that is not clothing or weapon or armor or an item is a gadget(think pets or unique tools)
//! gadgets are all illegal 





    private const GEAR_TYPES = ['weapon', 'armor', 'gadget', 'item'];

    public function canCarryItem(string $itemType, ?string $itemSlug = null): array
    {
        return $this->canCarryItemQuantity($itemType, 1, $itemSlug);
    }

    public function canCarryItemQuantity(string $itemType, int $quantity = 1, ?string $itemSlug = null): array
    {
        $quantity = max(1, $quantity);

        if ($itemType === 'vehicle') {
            if (($this->onHandVehicleCount() + $quantity) > self::MAX_ON_HAND_VEHICLES) {
                return [
                    'valid' => false,
                    'error' => 'You already have a vehicle and have no where to put this one!',
                ];
            }
            return ['valid' => true, 'error' => null];
        }

        if (in_array($itemType, self::GEAR_TYPES, true)) {
            $gearCount = $this->onHandGearCount();

            if (($gearCount + $quantity) > self::MAX_ON_HAND_ITEMS) {
                return [
                    'valid' => false,
                    'error' => $quantity === 1
                        ? 'Your hands are full since you can only carry 2 items!'
                        : 'You cannot carry that many items!',
                ];
            }
        }

        return ['valid' => true, 'error' => null];
    }

    private function onHandGearCount(): int
    {
        if ($this->relationLoaded('items')) {
            return $this->items
                ->filter(fn($item) => $item->location === 'on_hand'
                    && in_array($item->template?->type, self::GEAR_TYPES, true))
                ->count();
        }

        return $this->items()
            ->whereHas('template', fn($q) => $q->whereIn('type', self::GEAR_TYPES))
            ->where('location', 'on_hand')
            ->count();
    }

    private function onHandVehicleCount(): int
    {
        if ($this->relationLoaded('items')) {
            return $this->items
                ->filter(fn($item) => $item->location === 'on_hand'
                    && $item->template?->type === 'vehicle')
                ->count();
        }

        return $this->getOnHandVehicles()->count();
    }

    public function ownsItem(string $slug): bool
    {
        return $this->items()->whereHas('template', function ($query) use ($slug) {
            $query->where('slug', 'ILIKE', $slug);
        })->exists();
    }


    public function getOnHandItems()
    {
        return $this->items()->onHand()->with('template')->get();
    }

    public function getOnHandVehicles()
    {
        return $this->items()->onHand()
            ->whereHas('template', fn($q) => $q->where('type', 'vehicle'))
            ->get();
    }

    public function getSafeItems()
    {
        return $this->items()->inSafe()->with('template')->get();
    }

    public function getGarageVehicles()
    {
        return $this->items()->inGarage()->with('template')->get();
    }


    /**
     * Get the equipped vehicle (or null if none equipped). Mirrors the
     * relation-loaded short-circuit in getEquippedWeaponName so callers
     * with an already-eager-loaded `items` collection don't trigger a
     * fresh query.
     */
    public function getEquippedVehicle(): ?CharacterItem
    {
        if ($this->relationLoaded('items')) {
            return $this->items->first(
                fn($i) => $i->is_equipped && $i->equipped_slot === 'vehicle'
            );
        }

        return $this->items()
            ->equipped()
            ->with('template')
            ->where('equipped_slot', 'vehicle')
            ->first();
    }

    public function getEquippedWeaponName(): string
    {
        if ($this->relationLoaded('items')) {
            $equipped = $this->items->firstWhere(function ($item) {
                return $item->is_equipped && $item->equipped_slot === 'weapon';
            });
        } else {
            $equipped = $this->items()
                ->equipped()
                ->with('template')
                ->where('equipped_slot', 'weapon')
                ->first();
        }

        return ($equipped && $equipped->template) ? $equipped->template->name : 'bare hands';
    }


    public function giveItem(string $slug): void
    {
        $this->addToInventory($slug);
    }


    public function addToInventory(string $slug): void
    {
        DB::transaction(function () use ($slug) {
            $template = GameItem::where('slug', 'ILIKE', $slug)->first();
            if (!$template) {
                throw new \Exception("Item template not found: {$slug}");
            }

            $capacityCheck = $this->canCarryItem($template->type);
            if (!$capacityCheck['valid']) {
                throw new \Exception($capacityCheck['error']);
            }

            $this->items()->create([
                'game_item_id' => $template->id,
                'durability_remaining' => $template->durability,
                'location' => 'on_hand',
                'is_equipped' => false,
            ]);
        });
    }


    public function equipItem(string $inventoryId, string $slot): void
    {
        DB::transaction(function () use ($inventoryId, $slot) {
            $item = $this->items()->with('template')
                ->where('location', 'on_hand')
                ->lockForUpdate()
                ->find($inventoryId);

            if (!$item) {
                throw new \Exception("Item not found in your inventory.");
            }

            if ($item->template->type === 'item') {
                throw new \Exception("Consumable items cannot be equipped.");
            }


            if ($item->template->type === 'gadget') {
                throw new \Exception("Gadgets cannot be equipped.");
            }

            if ($item->template->slot !== $slot) {
                throw new \Exception("Item '{$item->template->name}' cannot be equipped in the '{$slot}' slot. It belongs in '{$item->template->slot}'.");
            }

            if ($item->template->type === 'vehicle' && $item->durability_remaining !== null && $item->durability_remaining <= 0) {
                throw new \Exception("This vehicle is totaled and needs repairs before it can be used!");
            }

            $this->items()->where('equipped_slot', $slot)->update([
                'is_equipped' => false,
                'equipped_slot' => null
            ]);

            $item->update([
                'is_equipped' => true,
                'equipped_slot' => $slot
            ]);
        });
    }

    public function unequipItem(string $slot): void
    {
        $this->items()->where('equipped_slot', $slot)->update([
            'is_equipped' => false,
            'equipped_slot' => null
        ]);
    }


    public function storageDeposit(string $inventoryId): void
    {
        DB::transaction(function () use ($inventoryId) {
            if (!$this->property_id) {
                throw new \Exception("You don't have a property.");
            }

            if (!$this->isInHomeCity()) {
                $propertyName = $this->property?->name ?? 'property';
                throw new \Exception("You cannot store this item in your {$propertyName} while you're away.");
            }

            if (in_array($this->property_condition, [null, \App\Models\Property::CONDITION_DESTROYED], true)) {
                $reason = $this->property_condition === \App\Models\Property::CONDITION_DESTROYED
                    ? 'Your property was destroyed. Have it repaired by a technician before using it for storage.'
                    : 'Your property has not been inspected yet. A technician must certify it before you can use it for storage.';
                throw new \Exception($reason);
            }

            $item = $this->items()->with('template')->lockForUpdate()->find($inventoryId);
            if (!$item) {
                throw new \Exception("Item not found in your inventory.");
            }

            $isVehicle = $item->template->type === 'vehicle';
            $capacity = $isVehicle ? $this->property->vehicle_capacity : $this->property->safe_capacity;
            $current = $isVehicle ? $this->getGarageVehicles()->count() : $this->getSafeItems()->count();

            if ($current >= $capacity) {
                throw new \Exception("You cannot stash anymore items in your {$this->property->name}!");
            }

            $item->update([
                'location' => $isVehicle ? 'garage' : 'safe',
                'is_equipped' => false,
                'equipped_slot' => null
            ]);
        });
    }

    public function storageWithdraw(string $inventoryId): void
    {
        DB::transaction(function () use ($inventoryId) {
            if (!$this->isInHomeCity()) {
                $propertyName = $this->property?->name ?? 'property';
                throw new \Exception("You cannot retrieve items from your {$propertyName} while you're away!");
            }

            if ($this->property_condition === \App\Models\Property::CONDITION_DESTROYED) {
                throw new \Exception('Your property was destroyed, Unless you want to crawl through the rubble to retrieve it, you should have it repaired first.');
            }

            $item = $this->items()->with('template')->lockForUpdate()->find($inventoryId);
            if (!$item) {
                throw new \Exception("Item not found in your storage.");
            }

            $capacityCheck = $this->canCarryItem($item->template->type);
            if (!$capacityCheck['valid']) {
                throw new \Exception($capacityCheck['error']);
            }

            $item->update(['location' => 'on_hand']);
        });
    }


    public function releasePlantedBombs(?int $itemId = null): void
    {
        if (!$this->ownsItem('rcied'))
            return;

        $query = $this->items()->plantedRcieds();
        if ($itemId) {
            $query->where('id', $itemId);
        }

        $query->get()->each(function ($rcied) {
            $bombTarget = \App\Models\Character::lockForUpdate()->find($rcied->data['target_character_id']);
            if (!$bombTarget || !\App\Models\Property::hasBomb($bombTarget))
                return;

            $bombTarget->property_condition = \App\Models\Property::CONDITION_CONSTRUCTED;
            $bombTarget->save();
            \App\Services\JournalService::custom($bombTarget->id, 'bomb_plant_failed', [
                'result' => 'detonator_destroyed',
                'attacker_name' => $this->display_name,
            ]);
        });
    }

    public function dropItem(string $inventoryId): void
    {
        DB::transaction(function () use ($inventoryId) {
            $item = $this->items()->lockForUpdate()->find($inventoryId);
            if (!$item) {
                throw new \Exception("Item not found in your inventory.");
            }
            if ($item->is_equipped) {
                throw new \Exception("Unequip before destroying.");
            }
            $this->releasePlantedBombs((int) $inventoryId);
            $item->delete();
        });
    }


    public function consumeDurability(string $slot, ?int $amount = null): void
    {
        $item = $this->items()
            ->with('template')
            ->where('is_equipped', true)
            ->where('equipped_slot', $slot)
            ->first();

        if ($item && $item->template && $item->durability_remaining !== null) {
            $isVehicle = $item->template->type === 'vehicle';

            if ($amount === null) {
                $decrementAmount = $isVehicle ? 2 : 1;

                if ($isVehicle && rand(1, 100) <= 5) {
                    $item->update([
                        'durability_remaining' => 0,
                        'is_equipped' => false,
                        'equipped_slot' => null,
                    ]);
                    return;
                }
            } else {
                $decrementAmount = $amount;
            }

            $newDurability = max(0, $item->durability_remaining - $decrementAmount);
            $item->update(['durability_remaining' => $newDurability]);

            if ($newDurability <= 0) {
                if ($isVehicle) {
                    $item->update([
                        'is_equipped' => false,
                        'equipped_slot' => null,
                    ]);
                } else {
                    $item->delete();
                }
            }
        }
    }
}
