<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Property;
use App\Models\Character;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;


class PropertyController extends CityController
{
    public function purchase(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        $request->validate([
            'property_id' => 'required|integer|exists:properties,id',
        ]);

        $propertyId = $request->input('property_id');

        DB::beginTransaction();
        try {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

            if ($character->property_id) {
                DB::rollBack();
                return back()->with('error', 'You already own a property. Sell it first.');
            }

            if (!$character->isInHomeCity()) {
                DB::rollBack();
                return back()->with('error', 'You can only purchase a home in your home city.');
            }

            $property = Property::findOrFail($propertyId);

            if ($character->cash_on_hand < $property->price) {
                DB::rollBack();
                return back()->with('error', 'Insufficient funds.');
            }

            if (!$character->removeCash($property->price)) {
                DB::rollBack();
                return back()->with('error', 'Insufficient funds.');
            }

            $character->property_id = $property->id;
            $character->property_condition = null;
            $character->save();

            DB::commit();

            return back()->with('success', "You have successfully purchased the {$property->name}. A technician must inspect it before you can use it.");

        }
        catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Property purchase failed', [
                'character_id' => $character->id ?? null,
                'property_id' => $propertyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $isDomainError = ($e instanceof \Exception && !($e instanceof \Error));
            $message = $isDomainError ? $e->getMessage() : 'Purchase failed. Please try again.';

            return back()->with('error', $message);
        }
    }

    public function sell(Request $request, City $city)
    {
        [$character, $city] = $this->getContext($request, $city);

        DB::beginTransaction();
        try {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

            if (!$character->property_id) {
                DB::rollBack();
                return back()->with('error', 'You do not own any property.');
            }
            $property = $character->property;
            $isDestroyed = $character->property_condition === Property::CONDITION_DESTROYED;
            $sellPrice = $isDestroyed ? 0 : (int)($property->price * 0.50);

            $character->addCash($sellPrice);

            $vehicles = $character->items()->where('location', 'garage')->count();
            $items = $character->items()->where('location', 'safe')->count();

            $character->items()->whereIn('location', ['safe', 'garage'])->delete();

            $character->property_id = null;
            $character->property_condition = null;
            $character->save();

            DB::commit();

            $message = "Your {$property->name} was successfully sold for \$" . number_format($sellPrice);

            if ($isDestroyed) {
                $message .= " — since no one wants to pay that much for a destroyed home!";
                return back()->with('error', $message);
            }

            if ($vehicles > 0 || $items > 0) {
                $parts = [];
                if ($vehicles > 0)
                    $parts[] = "{$vehicles} vehicle" . ($vehicles > 1 ? 's' : '');
                if ($items > 0)
                    $parts[] = "{$items} item" . ($items > 1 ? 's' : '');

                $totalLost = $vehicles + $items;
                $verb = $totalLost > 1 ? 'were' : 'was';
                $message .= ", but unfortunately, " . implode(' and ', $parts) . " {$verb} lost in the move!";

                return back()->with('warning', $message);
            }

            return back()->with('success', $message . '.');

        }
        catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Property sale failed', [
                'character_id' => $character->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $isDomainError = ($e instanceof \Exception && !($e instanceof \Error));
            $message = $isDomainError ? $e->getMessage() : 'Sale failed. Please try again.';

            return back()->with('error', $message);
        }
    }
}
