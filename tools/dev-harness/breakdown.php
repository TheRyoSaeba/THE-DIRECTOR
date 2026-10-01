<?php
// Per-prop JSON size of one Inertia page response.
//
//   php tools/dev-harness/breakdown.php <career> <path> [--except-once=propA,propB] [--queries]
//     --except-once  sends X-Inertia-Except-Once-Props (props the client already holds)
//     --queries      also dump every SQL statement executed

declare(strict_types=1);

/** @var HarnessClient $client */
$client = require __DIR__ . '/lib/http.php';
[$pos, $opts] = harness_args($argv);
if (count($pos) !== 2) {
    fwrite(STDERR, "usage: php tools/dev-harness/breakdown.php <career> <path> [--except-once=a,b] [--queries]\n");
    exit(2);
}
[$career, $path] = $pos;
$client->login($career);
$headers = [];
if (!empty($opts['except-once']) && $opts['except-once'] !== true) {
    $headers['X-Inertia-Except-Once-Props'] = $opts['except-once'];
}
$client->clearRateLimiters();
$res = $client->request('GET', $path, [], $headers);
$page = json_decode($res['body'], true);
if (!is_array($page) || !isset($page['props'])) {
    printf("status %d, not an Inertia JSON page (%d bytes)\n", $res['status'], $res['bytes']);
    exit(1);
}
printf("%s  component=%s  status=%d  total=%d bytes  DBq=%d  Redis=%d  %.1fms\n\n", $path, $page['component'] ?? '?',
    $res['status'], $res['bytes'], $res['db'], $res['redis'], $res['ms']);

$rows = [];
$walk = function (array $props, string $prefix) use (&$walk, &$rows) {
    foreach ($props as $k => $v) {
        $size = strlen(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $rows[] = [$prefix . $k, $size, is_array($v) ? (array_is_list($v) ? 'list[' . count($v) . ']' : 'obj') : gettype($v)];
        if ($prefix === '' && is_array($v) && !array_is_list($v) && $size > 2000) {
            $walk($v, $k . '.');
        }
    }
};
$walk($page['props'], '');
usort($rows, fn ($a, $b) => $b[1] <=> $a[1]);
printf("%-48s %9s %6s  %s\n", 'prop', 'bytes', '%', 'type');
foreach ($rows as [$name, $size, $type]) {
    printf("%-48s %9d %5.1f%%  %s\n", $name, $size, 100 * $size / max(1, $res['bytes']), $type);
}
foreach (['deferredProps', 'mergeProps', 'onceProps', 'sharedProps'] as $meta) {
    if (!empty($page[$meta])) {
        echo "\n$meta: " . json_encode($page[$meta]) . "\n";
    }
}
if (isset($opts['queries'])) {
    echo "\nqueries:\n";
    foreach ($res['queries'] as $i => $q) {
        printf("%3d  %s\n", $i + 1, $q);
    }
}
