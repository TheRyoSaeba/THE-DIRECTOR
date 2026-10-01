<?php
// Synthetic seed for the local dev/measurement DB.
//
//   12 players (one per career): p_<code>@test.local / password, character P_<code>, city 1
//   300 NPCs Npc0..Npc299 across 3 cities and all careers
//   ~120 users marked online (Presence::touch)
//   120 journals and 400 messages per player
//   one police career_earns row ('patrol') so /work/attempt works
//   minimal businesses (bank, hospital, police, city-hall) per city (BusinessSeeder is broken)
//
// Skips if already seeded (p_police exists). `setup.sh --reseed` recreates the DB.

declare(strict_types=1);

use App\Support\Presence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/bootstrap.php';

if (DB::table('users')->where('email', 'p_police@test.local')->exists()) {
    echo "already seeded (p_police@test.local exists) - use setup.sh --reseed to start over\n";
    exit(0);
}

mt_srand(424242);
$now = now();
$ts = $now->toDateTimeString();

/** Drop keys the table does not have (the real schema drifted from the seeders). */
$colCache = [];
$filter = function (string $table, array $row) use (&$colCache): array {
    $colCache[$table] ??= array_flip(Schema::getColumnListing($table));
    return array_intersect_key($row, $colCache[$table]);
};
$insertChunked = function (string $table, array $rows) use ($filter): void {
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table($table)->insert(array_map(fn ($r) => $filter($table, $r), $chunk));
    }
};

$careers = DB::table('careers')->orderBy('id')->pluck('id', 'code')->all();          // code => id
$ranks = DB::table('career_ranks')->orderBy('rank_level')->get()->groupBy('career_id');
$cityIds = DB::table('cities')->orderBy('id')->pluck('id')->all();
$password = Hash::make('password');

/** Pick rank (<= target) and an XP value below the next promotion threshold. */
$rankAndXp = function (int $careerId, int $target) use ($ranks): array {
    $list = $ranks[$careerId] ?? collect();
    $levels = $list->pluck('rank_level')->all();
    $rank = $levels ? min($target, max($levels)) : 1;
    $cur = $list->firstWhere('rank_level', $rank);
    $next = $list->firstWhere('rank_level', $rank + 1);
    $xp = $rank * 900;
    if ($next) {
        $xp = min($xp, (int) $next->xp_required - 1);
    }
    if ($cur) {
        $xp = max($xp, (int) $cur->xp_required);
    }
    return [$rank, $xp];
};

DB::beginTransaction();

// ---------------------------------------------------------------------------
// Users + characters
// ---------------------------------------------------------------------------
$people = []; // [user row, character row]
foreach ($careers as $code => $careerId) {
    [$rank, $xp] = $rankAndXp($careerId, 3);
    $people[] = [
        ['username' => "p_$code", 'email' => "p_$code@test.local", 'last_ip' => '10.0.0.1'],
        ['display_name' => "P_$code", 'city_id' => $cityIds[0], 'career_id' => $careerId, 'career_rank' => $rank,
         'career_xp' => $xp, 'cash_on_hand' => 250000, 'cash_in_bank' => 900000, 'dirty_cash' => 20000, 'player' => true],
    ];
}
$careerIdsList = array_values($careers);
for ($i = 0; $i < 300; $i++) {
    $careerId = $careerIdsList[$i % count($careerIdsList)];
    [$rank, $xp] = $rankAndXp($careerId, 1 + ($i % 3));
    $people[] = [
        ['username' => "npc$i", 'email' => "npc$i@npc.local", 'last_ip' => '10.0.1.' . ($i % 250)],
        ['display_name' => "Npc$i", 'city_id' => $cityIds[$i % count($cityIds)], 'career_id' => $careerId,
         'career_rank' => $rank, 'career_xp' => $xp, 'cash_on_hand' => mt_rand(1000, 200000),
         'cash_in_bank' => mt_rand(0, 2000000), 'dirty_cash' => mt_rand(0, 50000), 'player' => false],
    ];
}

$playerChars = []; // code => character id
$npcChars = [];
$userIds = [];
foreach ($people as $idx => [$u, $c]) {
    $userId = DB::table('users')->insertGetId($filter('users', $u + [
        'password' => $password, 'google_id' => 'dev_harness_' . $u['username'],
        'email_verified_at' => $ts, 'last_login_at' => $ts, 'created_at' => $ts, 'updated_at' => $ts,
    ]));
    $userIds[] = $userId;
    $charId = DB::table('characters')->insertGetId($filter('characters', $c + [
        'user_id' => $userId, 'gender' => $idx % 2 ? 'female' : 'male', 'home_city_id' => $c['city_id'],
        'health' => 100, 'max_health' => 100, 'total_character_exp' => $c['career_xp'],
        'created_at' => $now->copy()->subDays(mt_rand(5, 90))->toDateTimeString(), 'updated_at' => $ts,
    ]));
    if ($c['player']) {
        $playerChars[substr($u['username'], 2)] = $charId;
    } else {
        $npcChars[] = $charId;
    }
}
$allChars = array_merge(array_values($playerChars), $npcChars);
echo 'users/characters: ' . count($allChars) . "\n";

$stats = $timers = [];
foreach ($allChars as $cid) {
    $stats[] = ['character_id' => $cid, 'intelligence' => mt_rand(500, 3000), 'luck' => mt_rand(500, 3000),
        'offense' => mt_rand(500, 3000), 'defense' => mt_rand(500, 3000), 'influence' => mt_rand(0, 40),
        'created_at' => $ts, 'updated_at' => $ts];
    $timers[] = ['character_id' => $cid, 'next_action_at' => 0, 'next_work_at' => 0, 'strength' => 100,
        'strength_updated_at' => time()];
}
// strength_updated_at may be a timestamp or an int column depending on the migration - adapt.
$suaType = Schema::hasColumn('character_timers', 'strength_updated_at') ? Schema::getColumnType('character_timers', 'strength_updated_at') : null;
if ($suaType !== null && !preg_match('/int/i', $suaType)) {
    foreach ($timers as &$t) { $t['strength_updated_at'] = $ts; } unset($t);
}
$insertChunked('character_stats', $stats);
$insertChunked('character_timers', $timers);

// ---------------------------------------------------------------------------
// Journals: fill every data key the accessors read
// ---------------------------------------------------------------------------
$src = file_get_contents(base_path('app/Models/CharacterJournal.php'));
preg_match_all('/data\[["\']([a-z0-9_]+)["\']\]/', $src, $m);
$keys = array_unique($m[1]);
$numeric = '/(amount|fee|percent|percentage|damage|restored|lost|duration|interest|durability|count|units|payout|price|principal|refund|cost|received|votes)$/';
$baseData = [];
foreach ($keys as $k) {
    if (str_ends_with($k, '_id')) {
        $baseData[$k] = null; // filled per row with a real character id
    } elseif (preg_match($numeric, $k)) {
        $baseData[$k] = 50;
    } elseif (str_ends_with($k, '_names')) {
        $baseData[$k] = ['Sample', 'Sample'];
    } else {
        $baseData[$k] = 'Sample';
    }
}
$types = ['money_transfer_received', 'attack_received', 'promotion_achieved', 'item_sale_request',
          'corporation_invite_request', 'defense_request'];
$attackResults = ['hit', 'miss', 'hospitalized', 'gbh_miss'];
$journals = [];
foreach ($playerChars as $code => $cid) {
    for ($j = 0; $j < 120; $j++) {
        $data = $baseData;
        foreach ($data as $k => $v) {
            if ($v === null) { $data[$k] = $npcChars[($j * 7 + strlen($k)) % count($npcChars)]; }
        }
        $data['result'] = $attackResults[$j % 4];
        $data['was_critical'] = $j % 5 === 0;
        $data['won'] = $j % 2 === 0;
        $created = $now->copy()->subMinutes($j * 37)->toDateTimeString();
        $journals[] = ['character_id' => $cid, 'type' => $types[$j % count($types)], 'data' => json_encode($data),
            'is_read' => $j >= 10, 'is_saved' => $j % 25 === 0, 'created_at' => $created, 'updated_at' => $created];
    }
}
$insertChunked('character_journals', $journals);
if (Schema::hasColumn('characters', 'unread_journal_count')) {
    DB::table('characters')->whereIn('id', array_values($playerChars))->update(['unread_journal_count' => 10]);
}
echo 'journals: ' . count($journals) . "\n";

// ---------------------------------------------------------------------------
// Messages: 400 per player, with NPCs, both directions
// ---------------------------------------------------------------------------
$messages = [];
foreach ($playerChars as $code => $cid) {
    for ($j = 0; $j < 400; $j++) {
        $npc = $npcChars[($j * 13 + $cid) % 40]; // ~40 distinct conversation partners
        $outgoing = $j % 3 === 0;
        $created = $now->copy()->subMinutes($j * 11)->toDateTimeString();
        $messages[] = ['sender_id' => $outgoing ? $cid : $npc, 'recipient_id' => $outgoing ? $npc : $cid,
            'subject' => "Subject $j", 'body' => "Sample message #$j between P_$code and Npc. Lorem ipsum dolor sit amet.",
            'read_at' => $j < 15 ? null : $created, 'created_at' => $created, 'updated_at' => $created];
    }
}
$insertChunked('messages', $messages);
echo 'messages: ' . count($messages) . "\n";

// ---------------------------------------------------------------------------
// Police work earn + businesses
// ---------------------------------------------------------------------------
$earn = ['career_id' => $careers['police'], 'title' => 'Patrol the Streets', 'min_rank' => 1, 'min_career_xp' => 0,
    'rng_min' => 0, 'rng_max' => 100, 'payout_min' => 100, 'payout_max' => 500, 'xp_gain_min' => 1, 'xp_gain_max' => 5,
    'success_message' => 'You patrolled the streets.', 'failure_message' => 'Nothing happened.', 'created_at' => $ts, 'updated_at' => $ts];
foreach (['intelligence', 'offense', 'defense', 'luck', 'influence'] as $s) {
    $earn["stat_{$s}_min"] = 0;
    $earn["stat_{$s}_max"] = 1;
}
DB::table('career_earns')->updateOrInsert(['code' => 'patrol'], $filter('career_earns', $earn));

$services = [
    ['code' => 'bank', 'name' => 'National Bank', 'description' => 'Deposit, withdraw, and transfer funds', 'is_purchasable' => true, 'base_price' => 500000, 'sort_order' => 1,
     'balance' => 5000000, 'data' => json_encode(['loan_rate' => 5, 'loan_amount' => 50000])],
    ['code' => 'hospital', 'name' => 'General Hospital', 'description' => 'Medical treatment and healing', 'is_purchasable' => true, 'base_price' => 400000, 'sort_order' => 2],
    ['code' => 'police', 'name' => 'Police HQ', 'description' => 'Law enforcement and bounties', 'is_purchasable' => false, 'sort_order' => 3],
    ['code' => 'city-hall', 'name' => 'City Hall', 'description' => 'Government services and elections', 'is_purchasable' => false, 'sort_order' => 4],
];
foreach ($cityIds as $cityId) {
    foreach ($services as $svc) {
        $row = $filter('businesses', $svc + ['slug' => $svc['code'], 'is_active' => true, 'updated_at' => $ts, 'created_at' => $ts]);
        DB::table('businesses')->updateOrInsert(['city_id' => $cityId, 'code' => $svc['code']], $row);
    }
}
echo 'businesses: ' . DB::table('businesses')->count() . "\n";

DB::commit();

// ---------------------------------------------------------------------------
// Presence (Redis): ~120 users online, including every player
// ---------------------------------------------------------------------------
foreach (array_slice($userIds, 0, 120) as $i => $uid) {
    Presence::touch((int) $uid, '10.0.0.' . (($i % 250) + 1));
}
echo "presence: 120 users online\n";
echo "seed ok\n";
