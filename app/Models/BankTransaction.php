<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;


class BankTransaction extends Model
{
    public const KEEP_LAST       = 10;   
    public const KEEP_LAST_TRADE  = 10;  

    public const TYPE_DEPOSIT           = 'deposit';
    public const TYPE_WITHDRAW          = 'withdraw';
    public const TYPE_TRANSFER_SENT     = 'transfer_sent';
    public const TYPE_TRANSFER_RECEIVED = 'transfer_received';
    public const TYPE_TRADE_WIN         = 'trade_win';   
    public const TYPE_TRADE_LOSS        = 'trade_loss';  

    public $timestamps = false;          
    protected $table   = 'bank_transactions';

    protected $fillable = [
        'character_id',
        'type',
        'amount',
        'fee',
        'counterparty',
        'note',
        'balance_after',
        'created_at',
    ];

    protected $casts = [
        'amount'        => 'integer',
        'fee'           => 'integer',
        'balance_after' => 'integer',
        'created_at'    => 'datetime',
    ];

    

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    

    
    public static function record(
        int     $characterId,
        string  $type,
        int     $amount,
        int     $balanceAfter,
        int     $fee          = 0,
        ?string $counterparty = null,
        ?string $note         = null,
    ): void {
        DB::table('bank_transactions')->insert([
            'character_id'  => $characterId,
            'type'          => $type,
            'amount'        => $amount,
            'fee'           => $fee,
            'counterparty'  => $counterparty,
            'note'          => $note,
            'balance_after' => $balanceAfter,
            'created_at'    => \Carbon\Carbon::now('UTC'),
        ]);

        
        
        
        
        DB::table('bank_transactions')
            ->where('character_id', $characterId)
            ->whereNotIn('type', [self::TYPE_TRADE_WIN, self::TYPE_TRADE_LOSS])
            ->where('id', '<', function ($q) use ($characterId) {
                $q->select('id')
                    ->from('bank_transactions')
                    ->where('character_id', $characterId)
                    ->whereNotIn('type', [self::TYPE_TRADE_WIN, self::TYPE_TRADE_LOSS])
                    ->orderByDesc('id')
                    ->limit(1)
                    ->offset(self::KEEP_LAST - 1);
            })
            ->delete();

        
        DB::table('bank_transactions')
            ->where('character_id', $characterId)
            ->whereIn('type', [self::TYPE_TRADE_WIN, self::TYPE_TRADE_LOSS])
            ->where('id', '<', function ($q) use ($characterId) {
                $q->select('id')
                    ->from('bank_transactions')
                    ->where('character_id', $characterId)
                    ->whereIn('type', [self::TYPE_TRADE_WIN, self::TYPE_TRADE_LOSS])
                    ->orderByDesc('id')
                    ->limit(1)
                    ->offset(self::KEEP_LAST_TRADE - 1);
            })
            ->delete();
    }

    

    
    public static function forCharacter(int $characterId, int $limit = self::KEEP_LAST): \Illuminate\Support\Collection
    {
        return DB::table('bank_transactions')
            ->where('character_id', $characterId)
            ->whereNotIn('type', [self::TYPE_TRADE_WIN, self::TYPE_TRADE_LOSS])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    

    
    public static function cityResidents(int $cityId): array
    {
        return DB::table('characters')
            ->where('home_city_id', $cityId)
            ->whereNull('deleted_at')
            ->orderBy('display_name')
            ->pluck('display_name', 'id')
            ->map(fn ($name, $id) => ['id' => $id, 'name' => $name])
            ->values()
            ->toArray();
    }

    
    public static function tradeReport(int $cityId, int $limit = 50): array
    {
        return DB::table('bank_transactions')
            ->join('characters', 'bank_transactions.character_id', '=', 'characters.id')
            ->where('characters.home_city_id', $cityId)
            ->whereNull('characters.deleted_at')
            ->whereIn('bank_transactions.type', [self::TYPE_TRADE_WIN, self::TYPE_TRADE_LOSS])
            ->orderByDesc('bank_transactions.id')
            ->limit($limit)
            ->get([
                'bank_transactions.id',
                'characters.display_name as banker',
                'bank_transactions.type',
                'bank_transactions.counterparty as ticker',
                'bank_transactions.note         as direction',
                'bank_transactions.amount',
                'bank_transactions.created_at',
            ])
            ->map(fn ($row) => [
                'id'         => (int) $row->id,
                'banker'     => (string) $row->banker,
                'type'       => (string) $row->type,
                'ticker'     => (string) ($row->ticker ?? ''),
                'direction'  => (string) ($row->direction ?? ''),
                'amount'     => (int) $row->amount,
                'created_at' => $row->created_at,
            ])
            ->values()
            ->toArray();
    }

    
    public static function forOneCharacter(int $characterId): ?array
    {
        $char = DB::table('characters')
            ->where('id', $characterId)
            ->whereNull('deleted_at')
            ->select(['id', 'display_name', 'custom_avatar_url', 'cash_in_bank'])
            ->first();

        if (! $char) {
            return null;
        }

        $transactions = DB::table('bank_transactions')
            ->where('character_id', $characterId)
            ->whereNotIn('type', [self::TYPE_TRADE_WIN, self::TYPE_TRADE_LOSS])
            ->orderByDesc('id')
            ->limit(self::KEEP_LAST)
            ->get()
            ->values()
            ->toArray();

        return [
            'id'           => $char->id,
            'name'         => $char->display_name,
            'avatar_url'   => $char->custom_avatar_url,
            'bank_balance' => (int) $char->cash_in_bank,
            'transactions' => $transactions,
        ];
    }
}
