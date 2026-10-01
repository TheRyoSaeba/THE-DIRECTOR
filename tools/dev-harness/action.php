<?php
// Measure a POST action the way Inertia performs it: POST (X-Inertia) then follow the redirect
// with a GET. Before every run the character's work/action timers are reset to the past and the
// 'actions'/'players' rate limiters are cleared, so the action is always available.
// Afterwards the character's XP/cash snapshot and timers are restored.
//
//   php tools/dev-harness/action.php <career> <path> [key=value ...] [--n=5] [--method=POST]
//   e.g. php tools/dev-harness/action.php police /work/attempt earn_id=<id> --n=5

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/** @var HarnessClient $client */
$client = require __DIR__ . '/lib/http.php';
[$pos, $opts] = harness_args($argv);
if (count($pos) < 2) {
    fwrite(STDERR, "usage: php tools/dev-harness/action.php <career> <path> [key=value ...] [--n=5] [--method=POST]\n");
    exit(2);
}
$career = array_shift($pos);
$path = array_shift($pos);
$data = [];
foreach ($pos as $kv) {
    [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
    $data[$k] = $v;
}
$n = max(1, (int) ($opts['n'] ?? 5));
$method = strtoupper((string) ($opts['method'] ?? 'POST'));

$client->login($career);
$charId = $client->characterId();
// Snapshot progress columns so repeated runs don't drift the seeded player (e.g. into a promotion).
$snapshot = (array) DB::table('characters')->where('id', $charId)->first(['career_xp', 'total_character_exp', 'cash_on_hand', 'cash_in_bank', 'dirty_cash']);
$referer = '/' . trim((string) ($opts['from'] ?? explode('/', trim($path, '/'))[0]), '/');

printf("p_%s@test.local (character %d)  %s %s %s  n=%d\n\n", $career, $charId, $method, $path, json_encode($data), $n);
printf("%-4s %-30s %6s %5s %6s %9s %8s  %s\n", 'run', 'step', 'status', 'DBq', 'Redis', 'bytes', 'ms', 'flash/top repeated query');
for ($i = 1; $i <= $n; $i++) {
    DB::table('character_timers')->where('character_id', $charId)->update(['next_work_at' => time() - 60, 'next_action_at' => time() - 60]);
    $client->clearRateLimiters();

    $post = $client->request($method, $path, $data, ['Referer' => 'http://' . parse_url((string) config('app.url'), PHP_URL_HOST) . $referer]);
    printf("%-4d %-30s %6d %5d %6d %9d %8.1f  %s\n", $i, "$method $path", $post['status'], $post['db'], $post['redis'], $post['bytes'], $post['ms'], top_repeated($post['queries']));
    $loc = $post['response']->headers->get('Location');
    if ($loc && $post['status'] >= 300 && $post['status'] < 400) {
        $target = (string) parse_url($loc, PHP_URL_PATH) . (($q = parse_url($loc, PHP_URL_QUERY)) ? "?$q" : '');
        $get = $client->request('GET', $target);
        $page = json_decode($get['body'], true);
        $flash = is_array($page) ? json_encode(array_filter((array) ($page['props']['flash'] ?? []))) : '';
        $errors = is_array($page) ? ($page['props']['errors'] ?? []) : [];
        printf("%-4s %-30s %6d %5d %6d %9d %8.1f  %s%s\n", '', "-> GET $target", $get['status'], $get['db'], $get['redis'], $get['bytes'], $get['ms'],
            mb_strimwidth((string) $flash, 0, 90, '...'), $errors ? ' errors=' . json_encode($errors) : '');
    }
}

DB::table('characters')->where('id', $charId)->update($snapshot);
DB::table('character_timers')->where('character_id', $charId)->update(['next_work_at' => 0, 'next_action_at' => 0]);
echo "\n(restored career_xp/cash and timers of character $charId)\n";
