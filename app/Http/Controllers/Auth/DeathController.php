<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\CharacterHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;

class DeathController extends Controller
{
    public function index(Request $request)
    {
        $character = $request->user()->character()->withTrashed()->first();

        if (!$character || !$character->trashed()) {
            return redirect()->route('dashboard');
        }

        $canReincarnate = $character->deleted_at->addHours(12)->isPast();





        $lastWords = $character->biography ?: null;

        return Inertia::render('Conflict/Death', [
            'character_name' => $character->display_name,
            'died_at' => $character->deleted_at->toIso8601String(),
            'death_cause' => $character->death_cause,
            'death_reason' => $character->death_reason,
            'last_words' => $lastWords,
            'can_reincarnate' => $canReincarnate,
        ]);
    }


    // TODO you can technically still edit last words after submitting them programmatically
    public function saveLastWords(Request $request)
    {
        $character = $request->user()->character()->withTrashed()->first();

        if (!$character || !$character->trashed()) {
            return back()->with('error', 'No dead character found.');
        }

        $validated = $request->validate([
            'last_words' => 'required|string|min:1|max:300',
        ]);

        DB::table('characters')
            ->where('id', $character->id)
            ->update(['biography' => trim($validated['last_words'])]);

        Log::info('[Death] Last words saved.', ['character_id' => $character->id]);

        return back()->with('success', 'Your last words have been recorded.');
    }



    public function reincarnate(Request $request)
    {
        Log::info('[DeathController] Reincarnation attempt', ['user_id' => $request->user()->id]);
        $character = $request->user()->character()->withTrashed()->first();

        if (!$character || !$character->trashed()) {
            return redirect()->route('dashboard');
        }

        if (!$character->deleted_at->addHours(12)->isPast()) {
            return back()->with('error', 'You must wait 12 hours before reincarnating.');
        }

        try {
            $character->forceDelete();
            return redirect()->route('character.create')->with('success', 'You have been reincarnated. Start your new life.');
        } catch (\Exception $e) {
            Log::error('[DeathController] Reincarnation failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Reincarnation failed. Please try again.');
        }
    }
}
