<?php
// In-process HTTP client for measurement: boots the app ONCE (like an Octane worker) and
// replays requests through the HTTP kernel, carrying cookies between requests and counting
// DB queries + Redis commands per request.

declare(strict_types=1);

const DEV_HARNESS_HTTP = true;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__ . '/bootstrap.php';

final class HarnessClient
{
    /** @var array<string,string> cookie name => (encrypted) value */
    public array $cookies = [];
    public array $queries = [];
    public int $redisCommands = 0;
    public ?int $userId = null;
    public string $inertiaVersion;
    private Kernel $kernel;
    private string $host;

    public function __construct(private \Illuminate\Foundation\Application $app)
    {
        $this->kernel = $app->make(Kernel::class);
        $this->host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '127.0.0.1';
        $this->inertiaVersion = (string) $app->make(HandleInertiaRequests::class)->version(Request::create('/'));

        DB::listen(function (QueryExecuted $q) {
            $this->queries[] = $q->sql;
        });
        $redis = $app['redis'];
        $redis->enableEvents();
        foreach ($redis->connections() ?? [] as $conn) { // connections resolved during boot
            $conn->setEventDispatcher($app['events']);
        }
        $app['events']->listen(CommandExecuted::class, function () {
            $this->redisCommands++;
        });
    }

    /** Log in through GET + POST /dev-login (APP_ENV=local only). */
    public function login(string $career): void
    {
        $this->request('GET', '/dev-login', [], [], false);
        $email = "p_{$career}@test.local";
        $res = $this->request('POST', '/dev-login', ['email' => $email, 'password' => 'password'], [], false);
        $loc = (string) $res['response']->headers->get('Location');
        if ($res['status'] !== 302 || !str_contains($loc, '/dashboard')) {
            fwrite(STDERR, "login as $email failed: {$res['status']} -> $loc\n");
            exit(1);
        }
        $this->userId = (int) DB::table('users')->where('email', $email)->value('id');
        $this->queries = [];
    }

    public function characterId(): int
    {
        return (int) DB::table('characters')->where('user_id', $this->userId)->value('id');
    }

    /**
     * Clear the per-user throttles so repeated requests never hit 429. The app uses
     * throttleWithRedis(): limiter state lives on the default Redis connection under
     * md5(<limiter name> . <limit key>), not in the cache store.
     */
    public function clearRateLimiters(): void
    {
        $keys = [];
        foreach (['actions', 'players', 'guest'] as $name) {
            foreach ([(string) $this->userId, '127.0.0.1'] as $key) {
                $keys[] = md5($name . $key);
                RateLimiter::clear(md5($name . $key)); // cache-based limiter, in case throttleWithRedis is dropped
            }
        }
        $this->app['redis']->connection()->del(...$keys);
    }

    /**
     * @return array{status:int,bytes:int,ms:float,db:int,redis:int,queries:array,response:\Symfony\Component\HttpFoundation\Response,body:string}
     */
    public function request(string $method, string $path, array $data = [], array $headers = [], bool $inertia = true): array
    {
        $server = ['HTTP_HOST' => $this->host, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'dev-harness'];
        $headers += ['Accept' => $inertia ? 'text/html, application/xhtml+xml' : 'text/html'];
        if ($inertia) {
            $headers += ['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion, 'X-Requested-With' => 'XMLHttpRequest'];
        }
        if ($method !== 'GET' && isset($this->cookies['XSRF-TOKEN'])) {
            $headers['X-XSRF-TOKEN'] = $this->cookies['XSRF-TOKEN'];
        }
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $request = Request::create($path, $method, $data, $this->cookies, [], $server);

        $this->queries = [];
        $this->redisCommands = 0;
        $t0 = hrtime(true);
        $response = $this->kernel->handle($request);
        $ms = (hrtime(true) - $t0) / 1e6;
        $body = (string) $response->getContent();
        $this->kernel->terminate($request, $response);

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
            }
        }
        $result = ['status' => $response->getStatusCode(), 'bytes' => strlen($body), 'ms' => $ms,
            'db' => count($this->queries), 'redis' => $this->redisCommands, 'queries' => $this->queries,
            'response' => $response, 'body' => $body];
        $this->resetBetweenRequests();
        return $result;
    }

    /** What an Octane worker does between requests (the subset this app needs). */
    private function resetBetweenRequests(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();
        // Like Octane's FlushSessionState: keep the store instance (the Redirector singleton holds a
        // reference to it, so replacing it would send flash data to a dead store) but drop its data.
        if ($this->app->resolved('session')) {
            $driver = $this->app['session']->driver();
            $driver->flush();
            $driver->regenerate();
        }
        $this->app['cookie']->flushQueuedCookies();
        if ($this->app->resolved(\Inertia\ResponseFactory::class)) {
            $this->app->make(\Inertia\ResponseFactory::class)->flushShared();
        }
    }
}

/** Most repeated SQL statement (normalized literals) in a query list. */
function top_repeated(array $queries): string
{
    $counts = [];
    foreach ($queries as $sql) {
        $norm = preg_replace(['/\s+/', "/'[^']*'/", '/\b\d+\b/'], [' ', '?', '?'], $sql);
        $counts[$norm] = ($counts[$norm] ?? 0) + 1;
    }
    arsort($counts);
    $sql = array_key_first($counts);
    if ($sql === null || $counts[$sql] < 2) {
        return '-';
    }
    return $counts[$sql] . 'x ' . mb_strimwidth($sql, 0, 110, '...');
}

function percentile(array $xs, float $p): float
{
    sort($xs);
    return $xs ? $xs[(int) floor((count($xs) - 1) * $p)] : 0.0;
}

/** Parse "--key=value" options out of argv; returns [positional[], options[]]. */
function harness_args(array $argv): array
{
    $pos = $opts = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) {
            $opts[$m[1]] = $m[2] ?? true;
        } else {
            $pos[] = $a;
        }
    }
    return [$pos, $opts];
}

return new HarnessClient($app);
