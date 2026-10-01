<?php
// Replay Inertia visits in one booted app (Octane-worker style) and report per-page cost.
//
//   php tools/dev-harness/measure.php <career> <path>... [--n=10] [--full]
//     career  player to log in as (p_<career>@test.local), e.g. police
//     --n     timed iterations per path (after one warm-up), default 10
//     --full  plain HTML (first) load instead of an Inertia XHR visit
//
// Columns: status, DB queries, Redis commands, response bytes, p50/max ms, most repeated query.

declare(strict_types=1);

/** @var HarnessClient $client */
$client = require __DIR__ . '/lib/http.php';
[$pos, $opts] = harness_args($argv);
if (count($pos) < 2) {
    fwrite(STDERR, "usage: php tools/dev-harness/measure.php <career> <path>... [--n=10] [--full]\n");
    exit(2);
}
$career = array_shift($pos);
$n = max(1, (int) ($opts['n'] ?? 10));
$inertia = !isset($opts['full']);

$client->login($career);
printf("user p_%s@test.local  inertia-version %s  n=%d  mode=%s\n\n", $career, substr($client->inertiaVersion, 0, 12), $n, $inertia ? 'inertia' : 'full');
printf("%-26s %6s %5s %6s %9s %8s %8s  %s\n", 'path', 'status', 'DBq', 'Redis', 'bytes', 'p50ms', 'maxms', 'top repeated query');
foreach ($pos as $path) {
    $client->clearRateLimiters();
    $client->request('GET', $path, [], [], $inertia); // warm-up
    $times = [];
    $last = null;
    for ($i = 0; $i < $n; $i++) {
        $client->clearRateLimiters();
        $last = $client->request('GET', $path, [], [], $inertia);
        $times[] = $last['ms'];
    }
    $status = (string) $last['status'];
    if ($last['status'] >= 300 && $last['status'] < 400) {
        $status .= '>' . parse_url((string) $last['response']->headers->get('Location'), PHP_URL_PATH);
    }
    printf("%-26s %6s %5d %6d %9d %8.1f %8.1f  %s\n", $path, $status, $last['db'], $last['redis'], $last['bytes'],
        percentile($times, 0.5), max($times), top_repeated($last['queries']));
}
