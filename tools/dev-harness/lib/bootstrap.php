<?php
// Shared bootstrap for dev-harness CLI scripts: boots the Laravel app and enforces the
// local-DB guard. Define DEV_HARNESS_HTTP before requiring to bootstrap the HTTP kernel
// (like an Octane worker) instead of the console kernel. Returns the Application instance.

declare(strict_types=1);

$repo = dirname(__DIR__, 3);
require $repo . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $repo . '/bootstrap/app.php';
$app->make(defined('DEV_HARNESS_HTTP')
    ? \Illuminate\Contracts\Http\Kernel::class
    : \Illuminate\Contracts\Console\Kernel::class)->bootstrap();

dev_harness_guard($app);

function dev_harness_guard(\Illuminate\Foundation\Application $app): void
{
    $default = config('database.default');
    $conn = config("database.connections.$default");
    $host = (string) ($conn['host'] ?? '');
    $url = (string) ($conn['url'] ?? '');
    $ok = in_array($host, ['127.0.0.1', 'localhost'], true) && $url === '';
    if (!$ok) {
        fwrite(STDERR, "REFUSING: database host is '" . ($url ?: $host) . "' - dev-harness only runs against 127.0.0.1/localhost.\n");
        exit(1);
    }
    if (!$app->isLocal()) {
        fwrite(STDERR, "REFUSING: APP_ENV must be 'local' (is '" . $app->environment() . "').\n");
        exit(1);
    }
}

return $app;
