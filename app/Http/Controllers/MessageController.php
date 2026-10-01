<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

//!  NEAR PERFECTION
class MessageController extends Controller
{
    public function index(Request $request)
    {
        $character = $request->user()->getLoadedCharacter();
        if (!$character) {
            return redirect()->route('character.create');
        }

        $conversations = $this->getConversations($character->id);

        return Inertia::render('Messages/Messages', [
            'conversations' => $conversations,
        ]);
    }

    public function show(Request $request, string $conversationId)
    {
        $character = $request->user()->getLoadedCharacter();
        if (!$character) {
            return redirect()->route('character.create');
        }

        $isGroup = str_starts_with($conversationId, 'group_');
        $otherCharacterId = $isGroup ? null : (int) str_replace('user_', '', $conversationId);
        $groupId = $isGroup ? $conversationId : null;

        if (!$isGroup && $otherCharacterId) {
            $otherCharacter = Character::find($otherCharacterId);
            if (!$otherCharacter) {
                return back()->with('error', 'Character not found');
            }
        }

        // Latest 50 messages, displayed oldest-first (the scope's own ASC ordering is
        // replaced so the LIMIT keeps the newest rows rather than the oldest).
        $messages = Message::forConversation($character->id, $otherCharacterId, $groupId)
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->reverse()
            ->values();

        $senderIds = $messages->pluck('sender_id')->unique()->toArray();
        if (!empty($senderIds)) {
            $senders = Character::whereIn('id', $senderIds)
                ->get(['id', 'display_name', 'custom_avatar_url', 'career_id', 'career_rank'])
                ->keyBy('id');

            $careerIds = $senders->pluck('career_id')->unique()->toArray();
            $rankLevels = $senders->pluck('career_rank')->unique()->toArray();
            $ranks = \App\Models\CareerRank::bulkLoadForCharacters($careerIds, $rankLevels);
        } else {
            $senders = collect();
            $ranks = [];
        }

        $messages = $messages->map(function ($msg) use ($character, $senders, $ranks) {
            $sender = $senders[$msg->sender_id] ?? null;
            if (!$sender) {
                return [
                    'id'         => $msg->id,
                    'sender_id'  => $msg->sender_id,
                    'sender'     => null,
                    'body'       => $msg->body,
                    'created_at' => $msg->created_at->toIso8601String(),
                    'isMe'       => $msg->sender_id === $character->id,
                ];
            }

            $rankKey = "{$sender->career_id}_{$sender->career_rank}";
            $rank    = $ranks[$rankKey] ?? null;

            return [
                'id'         => $msg->id,
                'sender_id'  => $msg->sender_id,
                'sender'     => [
                    'id'           => $sender->id,
                    'display_name' => $sender->display_name,
                    'avatar_url'   => $sender->custom_avatar_url ?: ($rank['avatar_url'] ?? null),
                ],
                'body'       => $msg->body,
                'created_at' => $msg->created_at->toIso8601String(),
                'isMe'       => $msg->sender_id === $character->id,
            ];
        })->values();

        $this->markConversationAsRead($character->id, $otherCharacterId, $groupId);
        Cache::forget("unread_messages_{$character->id}");

        $conversation = $this->getConversationInfo($character->id, $otherCharacterId, $groupId);

        return Inertia::render('Messages/Messages', [
            'selectedConversation' => $conversationId,
            'conversation'         => $conversation,
            'messages'             => $messages,
            'conversations'        => $this->getConversations($character->id),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'recipients'   => 'required|array|min:1',
            'recipients.*' => 'required|string|max:30',
            'message'      => 'required|string|max:1000',
            'subject'      => 'nullable|string|max:100',
        ]);

        $character = $request->user()->getLoadedCharacter();
        if (!$character) {
            return back()->with('error', 'No character found');
        }

        $recipientNames = $request->input('recipients');
        $messageBody    = $request->input('message');
        $subject        = $request->input('subject') ? trim($request->input('subject')) : null;

        try {
            $recipients = Character::where('id', '!=', $character->id)
                ->whereIn(
                    DB::raw('LOWER(TRIM(display_name))'),
                    array_map(fn($name) => strtolower(trim($name)), $recipientNames)
                )
                ->get(['id']);

            if ($recipients->count() !== count($recipientNames)) {
                return back()->with('error', 'One or more recipients not found');
            }

            $isGroup = $recipients->count() > 1;
            $groupId = $isGroup ? 'group_' . Str::random(16) : null;

            
            
            $messages = [];
            $now      = now();
            foreach ($recipients as $recipient) {
                $messages[] = [
                    'sender_id'    => $character->id,
                    'recipient_id' => $recipient->id,
                    'group_id'     => $groupId,
                    'subject'      => $subject,
                    'body'         => $messageBody,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ];
            }

            Message::insert($messages);

            foreach ($recipients as $recipient) {
                Cache::forget("unread_messages_{$recipient->id}");
            }

            $conversationId = $isGroup ? $groupId : 'user_' . $recipients->first()->id;
            return redirect()->route('messages.show', $conversationId)
                ->with('success', 'Message sent successfully');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to send message');
        }
    }

    public function sendMessage(Request $request, string $conversationId)
    {
        $request->validate([
            'body' => 'required|string|max:1000',
        ]);

        $character = $request->user()->getLoadedCharacter();
        if (!$character) {
            return back()->with('error', 'No character found');
        }

        $isGroup          = str_starts_with($conversationId, 'group_');
        $otherCharacterId = $isGroup ? null : (int) str_replace('user_', '', $conversationId);
        $groupId          = $isGroup ? $conversationId : null;

        try {
            if ($isGroup) {
                
                
                
                
                $participantIds = DB::table('messages')
                    ->where('group_id', $groupId)
                    ->whereNull('deleted_at')
                    ->select(DB::raw('sender_id as pid'))
                    ->union(
                        DB::table('messages')
                            ->where('group_id', $groupId)
                            ->whereNull('deleted_at')
                            ->select(DB::raw('recipient_id as pid'))
                    )
                    ->pluck('pid')
                    ->unique()
                    ->reject(fn($id) => $id == $character->id)
                    ->filter()
                    ->values();

                
                if ($participantIds->isEmpty()) {
                    return back()->with('error', 'You are not part of this conversation.');
                }

                $messages = [];
                $now      = now();
                foreach ($participantIds as $participantId) {
                    $messages[] = [
                        'sender_id'    => $character->id,
                        'recipient_id' => $participantId,
                        'group_id'     => $groupId,
                        'body'         => $request->input('body'),
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ];
                }

                Message::insert($messages);

                foreach ($participantIds as $pid) {
                    Cache::forget("unread_messages_{$pid}");
                }
            } else {
                Message::create([
                    'sender_id'    => $character->id,
                    'recipient_id' => $otherCharacterId,
                    'body'         => $request->input('body'),
                ]);
                Cache::forget("unread_messages_{$otherCharacterId}");
            }

            return back()->with('success', 'Message sent');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to send message');
        }
    }

    private function getConversations(int $characterId): array
    {
        $individualConvs = DB::table('messages')
            ->select([
                DB::raw("CASE WHEN sender_id = {$characterId} THEN recipient_id ELSE sender_id END as other_id"),
                DB::raw('MAX(created_at) as last_message_at'),
                DB::raw('COUNT(CASE WHEN recipient_id = ' . $characterId . ' AND read_at IS NULL THEN 1 END) as unread_count'),
            ])
            ->where(function ($q) use ($characterId) {
                $q->where('sender_id', $characterId)
                  ->orWhere('recipient_id', $characterId);
            })
            ->whereNull('group_id')
            ->whereNull('deleted_at')
            ->groupBy(DB::raw("CASE WHEN sender_id = {$characterId} THEN recipient_id ELSE sender_id END"))
            ->get();

        $groupConvs = DB::table('messages')
            ->select([
                'group_id',
                DB::raw('MAX(created_at) as last_message_at'),
                DB::raw('COUNT(CASE WHEN recipient_id = ' . $characterId . ' AND read_at IS NULL THEN 1 END) as unread_count'),
            ])
            ->whereNotNull('group_id')
            ->where(function ($q) use ($characterId) {
                $q->where('sender_id', $characterId)
                  ->orWhere('recipient_id', $characterId);
            })
            ->whereNull('deleted_at')
            ->groupBy('group_id')
            ->get();

        $characterIds = $individualConvs->pluck('other_id')->filter()->unique()->toArray();
        $lastMessages = [];
        if (!empty($characterIds)) {
            $lastMsgs = DB::table('messages')
                ->select([
                    DB::raw("CASE WHEN sender_id = {$characterId} THEN recipient_id ELSE sender_id END as other_id"),
                    'body',
                    'created_at',
                ])
                ->whereIn(DB::raw("CASE WHEN sender_id = {$characterId} THEN recipient_id ELSE sender_id END"), $characterIds)
                ->where(function ($q) use ($characterId) {
                    $q->where('sender_id', $characterId)
                      ->orWhere('recipient_id', $characterId);
                })
                ->whereNull('group_id')
                ->whereNull('deleted_at')
                ->orderBy('created_at', 'desc')
                ->get()
                ->groupBy('other_id')
                ->map(fn($msgs) => $msgs->first()->body);
            $lastMessages = $lastMsgs->toArray();
        }

        $groupIds          = $groupConvs->pluck('group_id')->filter()->unique()->toArray();
        $groupLastMessages = [];
        $groupSubjects     = [];
        if (!empty($groupIds)) {
            
            $groupRows = DB::table('messages')
                ->whereIn('group_id', $groupIds)
                ->whereNull('deleted_at')
                ->orderBy('created_at', 'asc')
                ->get(['group_id', 'body', 'subject', 'created_at']);

            foreach ($groupRows->groupBy('group_id') as $gid => $rows) {
                $groupLastMessages[$gid] = $rows->last()->body;
                
                $groupSubjects[$gid] = $rows->firstWhere('subject', '!=', null)?->subject ?? null;
            }
        }

        $characters = Character::whereIn('id', $characterIds)
            ->get(['id', 'display_name', 'custom_avatar_url', 'career_id', 'career_rank'])
            ->keyBy('id');

        $careerIds  = $characters->pluck('career_id')->unique()->toArray();
        $rankLevels = $characters->pluck('career_rank')->unique()->toArray();
        $ranks      = \App\Models\CareerRank::bulkLoadForCharacters($careerIds, $rankLevels);

        $result = [];

        foreach ($individualConvs as $conv) {
            $otherChar = $characters[$conv->other_id] ?? null;
            if (!$otherChar) continue;

            $rankKey   = "{$otherChar->career_id}_{$otherChar->career_rank}";
            $rank      = $ranks[$rankKey] ?? null;
            $avatarUrl = $otherChar->custom_avatar_url ?: ($rank['avatar_url'] ?? null);

            $result[] = [
                'id'            => 'user_' . $otherChar->id,
                'isGroup'       => false,
                'name'          => $otherChar->display_name,
                'avatar'        => $avatarUrl,
                'lastMessage'   => Str::limit($lastMessages[$conv->other_id] ?? '', 50),
                'lastMessageAt' => strtotime($conv->last_message_at),
                'unread'        => (int) $conv->unread_count,
            ];
        }

        foreach ($groupConvs as $conv) {
            $result[] = [
                'id'            => $conv->group_id,
                'isGroup'       => true,
                'name'          => $groupSubjects[$conv->group_id] ?? 'Group Chat',
                'avatar'        => null,
                'lastMessage'   => Str::limit($groupLastMessages[$conv->group_id] ?? '', 50),
                'lastMessageAt' => strtotime($conv->last_message_at),
                'unread'        => (int) $conv->unread_count,
            ];
        }

        usort($result, fn($a, $b) => $b['lastMessageAt'] <=> $a['lastMessageAt']);

        return $result;
    }

    private function markConversationAsRead(int $characterId, ?int $otherCharacterId, ?string $groupId): void
    {
        $query = Message::where('recipient_id', $characterId)
            ->whereNull('read_at');

        if ($groupId) {
            $query->where('group_id', $groupId);
        } elseif ($otherCharacterId) {
            $query->where('sender_id', $otherCharacterId)
                ->whereNull('group_id');
        } else {
            return;
        }

        $query->update(['read_at' => now()]);
    }

    private function getConversationInfo(int $characterId, ?int $otherCharacterId, ?string $groupId): ?array
    {
        if ($groupId) {
            
            $subject = DB::table('messages')
                ->where('group_id', $groupId)
                ->whereNotNull('subject')
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->value('subject');

            return [
                'id'      => $groupId,
                'isGroup' => true,
                'name'    => $subject ?? 'Group Chat',
                'avatar'  => null,
            ];
        }

        if ($otherCharacterId) {
            $otherChar = Character::find($otherCharacterId, ['id', 'display_name', 'custom_avatar_url', 'career_id', 'career_rank']);
            if (!$otherChar) return null;

            $ranks     = \App\Models\CareerRank::bulkLoadForCharacters([$otherChar->career_id], [$otherChar->career_rank]);
            $rankKey   = "{$otherChar->career_id}_{$otherChar->career_rank}";
            $rank      = $ranks[$rankKey] ?? null;
            $avatarUrl = $otherChar->custom_avatar_url ?: ($rank['avatar_url'] ?? null);

            return [
                'id'      => 'user_' . $otherChar->id,
                'isGroup' => false,
                'name'    => $otherChar->display_name,
                'avatar'  => $avatarUrl,
            ];
        }

        return null;
    }
}
