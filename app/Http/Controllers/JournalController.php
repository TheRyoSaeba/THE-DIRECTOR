<?php

namespace App\Http\Controllers;

use App\Models\CharacterJournal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class JournalController extends Controller
{
    public function index(Request $request): Response
    {
        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return Inertia::render('Messages/Journal', [
                'entries' => [],
                'unread_count' => 0,
            ]);
        }

        $unreadRequests = $character->journals()->unread()->whereRaw("type LIKE '%\\_request' ESCAPE '\\'")->count();

        $character->journals()->unread()->whereRaw("type NOT LIKE '%\\_request' ESCAPE '\\'")->update(['is_read' => true]);
        Cache::forget("unread_journals_{$character->id}");

        $entries = $character->journals()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->makeHidden(['character_id']);

        // Resolve actor avatars / city names for all rows in bulk (avoids a query per row).
        CharacterJournal::preloadForDisplay($entries);

        $requests        = $entries->filter(fn($e) => $e->isRequest());
        $savedEntries    = $entries->filter(fn($e) => !$e->isRequest() && $e->is_saved);
        $activityEntries = $entries->filter(fn($e) => !$e->isRequest() && !$e->is_saved);

        return Inertia::render('Messages/Journal', [
            'entries'      => $activityEntries->values(),
            'requests'     => $requests->values(),
            'saved'        => $savedEntries->values(),
            'unread_count' => $unreadRequests,
        ]);
    }

    public function markAsRead(Request $request, int $id)
    {
        $character = $request->user()->character;

        if (!$character) {
            return back()->with('error', 'No character found');
        }

        $entry = $character->journals()->find($id);

        if (!$entry) {
            return back()->with('error', 'Entry not found');
        }

        $entry->update(['is_read' => true]);
        Cache::forget("unread_journals_{$character->id}");

        return back()->with('success', 'Entry marked as read');
    }

    public function markAllAsRead(Request $request)
    {
        $character = $request->user()->character;

        if (!$character) {
            return back()->with('error', 'No character found');
        }

        $character->journals()->unread()->update(['is_read' => true]);
        Cache::forget("unread_journals_{$character->id}");

        return back()->with('success', 'All entries marked as read');
    }

    public function saveEntry(Request $request, int $id)
    {
        $character = $request->user()->character;
        $entry = $character?->journals()->find($id);
        if (!$entry) return back()->with('error', 'Entry not found');
        $entry->update(['is_saved' => true]);
        return back()->with('success', 'Entry saved');
    }

    public function deleteEntry(Request $request, int $id)
    {
        $character = $request->user()->character;

        if (!$character) {
            return back()->with('error', 'No character found');
        }

        $entry = $character->journals()->find($id);

        if (!$entry) {
            return back()->with('error', 'Entry not found');
        }

        $entry->delete();
        Cache::forget("unread_journals_{$character->id}");

        return back()->with('success', 'Entry deleted');
    }

    public function deleteAll(Request $request)
    {
        $character = $request->user()->character;

        if (!$character) {
            return back()->with('error', 'No character found');
        }

        $character->journals()->where('is_saved', false)->delete();
        Cache::forget("unread_journals_{$character->id}");

        return back()->with('success', 'All entries deleted');
    }

    public function acceptRequest(Request $request, int $id)
    {
        $character = $request->user()->getLoadedCharacter();
        if (!$character) {
            return back()->with('error', 'No character found');
        }

        $journal = $character->journals()->find($id);
        if (!$journal || !$journal->isRequest()) {
            return back()->with('error', 'Request not found or invalid.');
        }

        if ($journal->type === 'corporation_invite_request') {
            $result = app(\App\Http\Controllers\CorporationController::class)->acceptInviteFromJournal($request, $journal);
            return is_array($result) ? back()->with($result['status'], $result['message']) : $result;
        }

        if ($journal->type === 'investment_fraud_request') {
            $result = app(\App\Http\Controllers\ActionController::class)->acceptInvestmentFraud($request, $journal);
            return is_array($result) ? back()->with($result['status'], $result['message']) : $result;
        }

        if ($journal->type === 'kidnapping_request') {
            $result = app(\App\Http\Controllers\ActionController::class)->acceptKidnapping($request, $journal);
            return is_array($result) ? back()->with($result['status'], $result['message']) : $result;
        }

        if ($journal->type === 'corporate_medicine_sale_request') {
            $result = app(\App\Http\Controllers\ActionController::class)->acceptMedicineSale($request, $journal);
            return is_array($result) ? back()->with($result['status'], $result['message']) : $result;
        }

        if ($journal->type === 'corporate_mirror_transaction_request') {
            return app(\App\Http\Controllers\CorporationProfitController::class)
                ->acceptMirrorTransaction($character, $journal);
        }

        if ($journal->type === 'defense_request') {
            $result = app(\App\Http\Controllers\LawCaseController::class)->acceptDefense($request, $journal);
            return is_array($result) ? back()->with($result['status'], $result['message']) : $result;
            //!TODO null check
        }

        if ($journal->type !== 'item_sale_request') {
            return back()->with('error', 'Unsupported request type.');
        }

        $data = $journal->data;
        $sellerId = $data['seller_id'] ?? null;
        $itemId = $data['item_id'] ?? null;
        $price = $data['price'] ?? 0;

        if (!$sellerId || !$itemId) {
            return back()->with('error', 'Invalid request data.');
        }

        DB::beginTransaction();
        try {
            $ids = [$character->id, $sellerId];
            sort($ids);
            DB::table('characters')->whereIn('id', $ids)->lockForUpdate()->get();

            $buyer = $character;
            $seller = \App\Models\Character::with('items.template')->find($sellerId);

            if (!$seller) {
                DB::rollBack();
                $journal->delete();
                return back()->with('error', 'This item is no longer available.');
            }

            if ($buyer->city_id !== $seller->city_id) {
                DB::rollBack();
                return back()->with('error', 'You must be in the same city as the seller to complete the transaction.');
            }

            if ($buyer->cash_on_hand < $price) {
                DB::rollBack();

                return back()->with('error', 'Insufficient funds.');
            }

            $item = $seller->items->where('id', $itemId)->first();
            if (!$item || $item->character_id !== $seller->id) {
                DB::rollBack();
                $journal->delete();

                return back()->with('error', 'That item no longer exists or belongs to the seller.');
            }

            if ($item->is_equipped || $item->location !== 'on_hand') {
                DB::rollBack();

                return back()->with('error', 'The seller has stashed or is currently using it, tell them to be serious about the sale!');
            }

            if (!$item->template) {
                DB::rollBack();

                return back()->with('error', 'That item no longer exists or belongs to the seller');
            }

            $template = $item->template;
            $capacityCheck = $buyer->canCarryItem($template->type);
            if (!$capacityCheck['valid']) {
                DB::rollBack();

                return back()->with('error', $capacityCheck['error']);
            }

            if (!$buyer->removeCash($price)) {
                DB::rollBack();

                return back()->with('error', 'An error has occured with the sale. Please try again later.');
            }

            $seller->addCash($price);

            $item->character_id = $buyer->id;
            $item->location = 'on_hand';
            $item->is_equipped = false;
            $item->equipped_slot = null;
            $item->save();

            $journal->delete();

            \App\Services\JournalService::custom(
                $seller->id,
                'item_sold',
            [
                'buyer_name' => $buyer->display_name,
                'item_name' => $template->name,
                'price' => $price,
            ]
            );

            DB::commit();

            $formattedPrice = number_format($price);

            return back()->with('success', "Purchase completed! You received your new  {$template->name} for \${$formattedPrice}.");

        }
        catch (\Exception $e) {
            DB::rollBack();
            Log::error('Item sale accept failed', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'journal_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'An error has occured with the sale. Please try again later.');
        }
    }

    public function forward(Request $request, int $id)
    {
        $character = $request->user()->character;
        if (!$character) {
            return back()->with('error', 'No character found.');
        }

        $request->validate([
            'recipient_name' => 'required|string|max:30',
        ]);

        $entry = $character->journals()->find($id);
        if (!$entry) {
            return back()->with('error', 'Journal entry not found.');
        }

        
        if ($entry->type === 'journal_shared') {
            return back()->with('error', 'Forwarded entries cannot be re-forwarded.');
        }

        $recipient = \App\Models\Character::findByName(trim($request->recipient_name));
        if (!$recipient || $recipient->trashed()) {
            return back()->with('error', 'Recipient not found.');
        }
        if ($recipient->id === $character->id) {
            return back()->with('error', 'You cannot send a journal entry to yourself.');
        }

        
        
        \App\Services\JournalService::custom($recipient->id, 'journal_shared', [
            'sender_name'   => $character->display_name,
            'orig_title'    => $entry->title,
            'orig_desc'     => $entry->description,
            'orig_icon'     => $entry->icon,
            'orig_color'    => $entry->color_class,
            'orig_created_at' => $entry->created_at?->toIso8601String(),
        ]);

        return back()->with('success', "Journal entry forwarded to {$recipient->display_name}.");
    }

    public function declineRequest(Request $request, int $id)
    {
        $character = $request->user()->getLoadedCharacter();
        if (!$character) {
            return back()->with('error', 'No character found');
        }

        $journal = $character->journals()->find($id);
        if (!$journal || !$journal->isRequest()) {
            return back()->with('error', 'Request not found or invalid.');
        }

        if ($journal->type === 'investment_fraud_request') {

            return app(\App\Http\Controllers\ActionController::class)
                ->declineInvestmentFraud($request, $journal);
        }

        if ($journal->type === 'kidnapping_request') {
            return app(\App\Http\Controllers\ActionController::class)
                ->declineKidnapping($request, $journal);
        }

        if ($journal->type === 'corporate_medicine_sale_request') {
            return app(\App\Http\Controllers\ActionController::class)
                ->declineMedicineSale($request, $journal);
        }

        if ($journal->type === 'corporate_mirror_transaction_request') {
            return app(\App\Http\Controllers\CorporationProfitController::class)
                ->declineMirrorTransaction($character, $journal);
        }

        if ($journal->type === 'defense_request') {
            return app(\App\Http\Controllers\LawCaseController::class)->declineDefense($request, $journal);
        }

        $journal->delete();

        $message = $journal->type === 'item_sale_request'
            ? 'Sale request declined.'
            : 'Request declined.';

        return back()->with('success', $message);
    }
}
