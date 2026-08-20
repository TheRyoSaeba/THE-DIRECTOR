<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Message extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sender_id',
        'recipient_id',
        'group_id',
        'subject',
        'body',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'recipient_id');
    }

    public function scopeUnread($query, ?int $characterId = null)
    {
        if ($characterId) {
            return $query->where('recipient_id', $characterId)
                ->whereNull('read_at');
        }
        return $query->whereNull('read_at');
    }

    public function scopeForConversation($query, int $characterId, ?int $otherCharacterId = null, ?string $groupId = null)
    {
        if ($groupId) {
            
            
            
            
            
            
            $deduped = DB::table('messages')
                ->selectRaw('MIN(id) as id')
                ->where('group_id', $groupId)
                ->whereNull('deleted_at')
                ->groupBy('sender_id', 'created_at');

            return $query->where('group_id', $groupId)
                ->whereIn('id', $deduped)
                ->orderBy('created_at', 'asc');
        }

        if ($otherCharacterId) {
            return $query->where(function ($q) use ($characterId, $otherCharacterId) {
                $q->where(function ($subQ) use ($characterId, $otherCharacterId) {
                    $subQ->where('sender_id', $characterId)
                         ->where('recipient_id', $otherCharacterId);
                })->orWhere(function ($subQ) use ($characterId, $otherCharacterId) {
                    $subQ->where('sender_id', $otherCharacterId)
                         ->where('recipient_id', $characterId);
                });
            })->whereNull('group_id')
              ->orderBy('created_at', 'asc');
        }

        return $query->whereRaw('1 = 0');
    }

    public function markAsRead(): void
    {
        if (!$this->read_at) {
            $this->update(['read_at' => now()]);
        }
    }
}
