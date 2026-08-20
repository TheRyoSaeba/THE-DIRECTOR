<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;


use Illuminate\Support\Facades\Log;
use App\Models\{
    ActivityLog,
    Announcement,
    Business,
    Career,
    CareerEarn,
    Character,
    City,
    ForumCategory,
    ForumPost,
    GameItem,
    Property,
    User,
};
use App\Services\{CronJobService, UserService};
use App\Services\PostgresDiagnosticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Storage};
use Illuminate\Support\Str;
use Inertia\Inertia;

class AdminController extends Controller
{

    private const ADMIN_STATS_CACHE_KEY = 'admin.activity.stats.v1';
    private const ADMIN_STATS_CACHE_TTL_SECONDS = 45;
    private const LARAVEL_LOG_MAX_LINES = 100;
    private const LARAVEL_LOG_MAX_BYTES = 262144; 

    public function __construct(
        private UserService $userService,
        private CronJobService $cronService,
        private PostgresDiagnosticsService $pgDiagnostics,
    ) {}

    public function index(Request $request)
    {
        $section = $request->input("section", "users");
        $data = ["section" => $section];

        switch ($section) {
            case "users":
                $data = array_merge($data, $this->getUsersData($request));
                break;
            case "activity":
                $data = array_merge($data, $this->getActivityData($request));
                break;
            case "career":
                $data = array_merge($data, $this->getCareerData($request));
                break;
            case "world":
                $data = array_merge($data, $this->getWorldData($request));
                break;
            case "engine":
                $data = array_merge($data, $this->getEngineData($request));
                break;
            case "wal":
                $data["wal"] = $this->getHealthDiagnostics();
                break;
            case "forum":
                $data = array_merge($data, $this->getForumData($request));
                break;
        }

        return Inertia::render("Admin/Index", $data);
    }

    private function getHealthDiagnostics(): array
    {
        $diagnostics = $this->pgDiagnostics->getDatabaseHealth();
        $diagnostics['runtime'] = $this->getRuntimeHealth();
        $diagnostics['logs'] = $this->getRuntimeLogs();

        return $diagnostics;
    }

    private function getRuntimeHealth(): array
    {
        $opcache = [
            'enabled' => false,
            'hit_rate' => null,
            'used_memory' => null,
            'free_memory' => null,
            'jit_enabled' => false,
        ];

        if (function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);
            if (is_array($status)) {
                $opcache = [
                    'enabled' => (bool) ($status['opcache_enabled'] ?? false),
                    'hit_rate' => isset($status['opcache_statistics']['opcache_hit_rate'])
                        ? round((float) $status['opcache_statistics']['opcache_hit_rate'], 2)
                        : null,
                    'used_memory' => isset($status['memory_usage']['used_memory'])
                        ? (int) $status['memory_usage']['used_memory']
                        : null,
                    'free_memory' => isset($status['memory_usage']['free_memory'])
                        ? (int) $status['memory_usage']['free_memory']
                        : null,
                    'jit_enabled' => (bool) ($status['jit']['enabled'] ?? false),
                ];
            }
        }

        $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? null;
        $isFrankenPhp = stripos((string) $serverSoftware, 'FrankenPHP') !== false
            || function_exists('frankenphp_handle_request');

        return [
            'server' => $isFrankenPhp ? 'FrankenPHP' : ($serverSoftware ?: PHP_SAPI),
            'octane' => $isFrankenPhp || filter_var(env('LARAVEL_OCTANE', false), FILTER_VALIDATE_BOOL),
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'memory' => [
                'usage_bytes' => memory_get_usage(true),
                'peak_bytes' => memory_get_peak_usage(true),
                'limit' => ini_get('memory_limit') ?: null,
            ],
            'opcache' => $opcache,
            'cache' => [
                'default_store' => config('cache.default'),
                'limiter_store' => config('cache.limiter'),
                'redis_client' => config('database.redis.client'),
                'session_driver' => config('session.driver'),
            ],
            'cloud_run' => [
                'service' => env('K_SERVICE'),
                'revision' => env('K_REVISION'),
                'configuration' => env('K_CONFIGURATION'),
            ],
        ];
    }

    private function getRuntimeLogs(): array
    {
        // Same rule as getLaravelLogs: on Cloud Run the on-disk log is stale
        // or ephemeral, so go straight to Cloud Logging there.
        if (! env('K_SERVICE')) {
            $logPath = storage_path('logs/laravel.log');
            if (is_file($logPath) && is_readable($logPath)) {
                return $this->parseLaravelLogFile($logPath);
            }
        }

        $filter = [
            'resource.type="cloud_run_revision"',
            '(logName=~"stderr" OR logName=~"stdout" OR logName=~"requests")',
            '(severity>=WARNING OR httpRequest.status>=400)',
        ];

        if ($service = env('K_SERVICE')) {
            $filter[] = 'resource.labels.service_name="' . addcslashes($service, '"\\') . '"';
        }

        return $this->fetchCloudLogs($filter, 80);
    }

    private function getUsersData(Request $request): array
    {
        $query = User::query()
            ->withCount("character")
            ->with([
                "character" => fn($q) => $q
                    ->withTrashed()
                    ->select([
                        "id",
                        "user_id",
                        "display_name",
                        "health",
                        "max_health",
                        "cash_on_hand",
                        "cash_in_bank",
                        "dirty_cash",
                        "career_xp",
                    ]),
            ])
            ->latest("created_at");

        if ($search = $request->input("search")) {
            $query->where(function ($q) use ($search) {
                $q->where("username", "ILIKE", "%{$search}%")->orWhere(
                    "email",
                    "ILIKE",
                    "%{$search}%",
                );
            });
        }

        $query = match ($request->input("filter", "all")) {
            "banned" => $query->whereRaw('"is_banned" IS TRUE'),
            "admin" => $query->whereRaw('"is_admin" IS TRUE'),
            "active" => $query->whereRaw('"is_banned" IS FALSE'),
            default => $query,
        };

        $users = $query->paginate(50)->through(fn($user) => $user->makeVisible(['is_admin']));

        return [
            "users" => $users,
            "filters" => [
                "search" => $search ?? "",
                "filter" => $request->input("filter", "all"),
            ],
        ];
    }

    private function getActivityData(Request $request): array
    {
        $query = ActivityLog::with("user:id,username")->latest("created_at");

        if ($action = $request->input("action_filter")) {
            $query->where("action", $action);
        }

        $logFiles = $this->getLogFiles();
        $requestedLog = $request->input("log_file");
        $currentLog = $this->resolveLogFileName($requestedLog, $logFiles);

        return [
            "logs" => $query->paginate(8)->appends(["section" => "activity"]),
            "laravel_logs" => $this->getLaravelLogs($currentLog),
            "log_files" => $logFiles,
            "current_log" => $currentLog,
            "stats" => Cache::remember(
                self::ADMIN_STATS_CACHE_KEY,
                self::ADMIN_STATS_CACHE_TTL_SECONDS,
                fn() => [
                    "total_users" => User::count(),
                    "active_users" => User::where(
                        "last_login_at",
                        ">=",
                        now()->subDays(7),
                    )->count(),
                    "banned_users" => User::where("is_banned", true)->count(),
                    "total_characters" => Character::count(),
                    "alive_characters" => Character::whereNull("deleted_at")->count(),
                    "total_economy" => Character::sum("cash_on_hand") +
                        Character::sum("cash_in_bank"),
                    "dirty_economy" => Character::sum("dirty_cash"),
                ],
            ),
        ];
    }

    private function resolveLogFileName(
        ?string $requested,
        array $logFiles,
    ): string {
        $default = "laravel";

        if (!$requested) {
            return $default;
        }

        $requested = trim($requested);
        if ($requested === "") {
            return $default;
        }

        $allowed = collect($logFiles)->pluck("name")->all();

        return in_array($requested, $allowed, true) ? $requested : $default;
    }

    private function getLaravelLogs(string $logFile = "laravel"): array
    {
        // On Cloud Run the filesystem is ephemeral and per-instance: any
        // storage/logs/*.log is either a stale copy baked into the image at
        // build time or a fragment from a single container that vanishes on
        // the next cold start. So when we detect Cloud Run (K_SERVICE is set
        // by the runtime), always read from Cloud Logging, which is where
        // stderr actually lands. Only fall back to the on-disk file for local
        // development.
        if (env('K_SERVICE')) {
            return $this->fetchCloudLogs();
        }

        $logPath = storage_path("logs/{$logFile}.log");

        if (is_file($logPath) && is_readable($logPath)) {
            return $this->parseLaravelLogFile($logPath);
        }

        // Last resort for non-Cloud-Run hosts configured to log to the
        // platform's stream rather than a file.
        if (in_array(config('logging.default', 'stack'), ['stderr', 'syslog', 'errorlog'], true)) {
            return $this->fetchCloudLogs();
        }

        return [];
    }

    private function parseLaravelLogFile(string $logPath): array
    {
        $lines = $this->tailFileLines(
            $logPath,
            self::LARAVEL_LOG_MAX_LINES,
            self::LARAVEL_LOG_MAX_BYTES,
        );

        $logs = [];

        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");

            if (
                preg_match(
                    "/^\\[(\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2})\\]\\s+[^\\.]+\\.(\\w+):/m",
                    $line,
                    $matches,
                )
            ) {
                $logs[] = [
                    "timestamp" => $matches[1],
                    "level"    => strtolower($matches[2]),
                    "message"  => $line,
                ];
            } else {
                if (!empty($logs)) {
                    $logs[count($logs) - 1]["message"] .= "\n" . $line;
                }
            }
        }

        return array_reverse($logs);
    }

    private function fetchCloudLogs(?array $filterParts = null, int $pageSize = self::LARAVEL_LOG_MAX_LINES): array
    {
        $projectId = config('services.google_cloud.project_id') ?: $this->getGcpProjectId();

        if (!$projectId) {
            return [[
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'level'     => 'notice',
                'message'   => 'Cloud Logging unavailable: GOOGLE_CLOUD_PROJECT env var not set.',
            ]];
        }

        $token = $this->getGcpAccessToken();
        if (!$token) {
            return [[
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'level'     => 'notice',
                'message'   => 'Cloud Logging unavailable: service account credentials missing.',
            ]];
        }

        try {
            $filter = implode(' AND ', $filterParts ?? [
                'resource.type="cloud_run_revision"',
                'logName=~"stderr"',
                'severity>=DEFAULT',
            ]);

            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->timeout(5)
                ->post("https://logging.googleapis.com/v2/entries:list", [
                    'resourceNames' => ["projects/{$projectId}"],
                    'filter'        => $filter,
                    'orderBy'       => 'timestamp desc',
                    'pageSize'      => $pageSize,
                ]);

            if (!$response->successful()) {
                return [[
                    'timestamp' => now()->format('Y-m-d H:i:s'),
                    'level'     => 'error',
                    'message'   => 'Cloud Logging API error: ' . $response->status() . ' - ' . $response->body(),
                ]];
            }

            $entries = $response->json('entries', []);

            return collect($entries)->map(function (array $entry) {
                $ts      = $entry['timestamp'] ?? now()->toIso8601String();
                $level   = strtolower($entry['severity'] ?? 'info');
                $payload = $this->formatCloudLogPayload($entry);

                $level = match ($level) {
                    'default', 'notice'  => 'notice',
                    'warning'            => 'warning',
                    'error'              => 'error',
                    'critical', 'alert', 'emergency' => 'critical',
                    'debug'              => 'debug',
                    default              => 'info',
                };

                return [
                    'timestamp' => \Carbon\Carbon::parse($ts)->format('Y-m-d H:i:s'),
                    'level'     => $level,
                    'message'   => $payload,
                ];
            })->values()->all();

        } catch (\Throwable $e) {
            Log::error('[Admin] Cloud Logging fetch failed.', ['error' => $e->getMessage()]);
            return [[
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'level'     => 'error',
                'message'   => 'Failed to fetch Cloud Logging entries: ' . $e->getMessage(),
            ]];
        }
    }

    private function formatCloudLogPayload(array $entry): string
    {
        if (isset($entry['textPayload'])) {
            return (string) $entry['textPayload'];
        }

        if (isset($entry['jsonPayload']['message'])) {
            return (string) $entry['jsonPayload']['message'];
        }

        if (isset($entry['httpRequest']) && is_array($entry['httpRequest'])) {
            $request = $entry['httpRequest'];
            $method = $request['requestMethod'] ?? 'REQUEST';
            $url = $request['requestUrl'] ?? '';
            $path = $url !== '' ? parse_url($url, PHP_URL_PATH) : '';
            $status = $request['status'] ?? '-';
            $latency = $request['latency'] ?? null;
            $userAgent = $request['userAgent'] ?? null;

            return trim(sprintf(
                '%s %s status %s%s%s',
                $method,
                $path ?: $url ?: '/',
                $status,
                $latency ? " latency {$latency}" : '',
                $userAgent ? " agent {$userAgent}" : '',
            ));
        }

        if (isset($entry['jsonPayload'])) {
            return json_encode($entry['jsonPayload']) ?: '';
        }

        return json_encode($entry) ?: '';
    }

    private function getGcpAccessToken(): ?string
    {
        
        
        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Metadata-Flavor' => 'Google',
            ])->timeout(2)->get(
                'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token'
            );

            if ($response->successful()) {
                return $response->json('access_token');
            }
        } catch (\Throwable) {
            
        }

        return null;
    }

    /**
     * Resolve the GCP project ID from the metadata server. Cloud Run does not
     * reliably inject GOOGLE_CLOUD_PROJECT as an env var (unlike K_SERVICE),
     * but the metadata server always knows the project the instance runs in.
     * Cached for the request lifetime via a static.
     */
    private function getGcpProjectId(): ?string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached ?: null;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Metadata-Flavor' => 'Google',
            ])->timeout(2)->get(
                'http://metadata.google.internal/computeMetadata/v1/project/project-id'
            );

            if ($response->successful()) {
                return $cached = trim($response->body());
            }
        } catch (\Throwable) {
            
        }

        $cached = '';
        return null;
    }

    private function tailFileLines(
        string $path,
        int $maxLines,
        int $maxBytes,
    ): array {
        $fh = fopen($path, "rb");
        if ($fh === false) {
            return [];
        }

        try {
            fseek($fh, 0, SEEK_END);
            $pos = ftell($fh);
            if ($pos === false) {
                return [];
            }

            $buffer = "";
            $bytesRead = 0;
            $chunkSize = 8192;

            while ($pos > 0 && substr_count($buffer, "\n") <= $maxLines) {
                $readSize = min($chunkSize, $pos);
                $pos -= $readSize;
                fseek($fh, $pos);
                $chunk = fread($fh, $readSize);
                if ($chunk === false) {
                    break;
                }
                $buffer = $chunk . $buffer;

                $bytesRead += $readSize;
                if ($bytesRead >= $maxBytes) {
                    break;
                }
            }

            $buffer = ltrim($buffer, "\r\n");
            $lines = preg_split("/\\r\\n|\\n|\\r/", $buffer) ?: [];

            if (count($lines) > $maxLines) {
                $lines = array_slice($lines, -$maxLines);
            }

            return $lines;
        } finally {
            fclose($fh);
        }
    }

    private function getLogFiles(): array
    {
        $logPath = storage_path("logs");
        $files = glob($logPath . "/*.log") ?: [];

        return array_map(function ($file) {
            return [
                "name" => basename($file, ".log"),
                "size" => filesize($file),
                "modified" => filemtime($file),
            ];
        }, $files);
    }

    private function getCareerData(Request $request): array
    {
        $data = [
            "careers" => Career::withCount(["earns", "ranks"])->get(),
            "items" => GameItem::orderBy('name')->limit(500)->get(),
        ];

        if ($careerId = $request->input("career_id")) {
            $career = Career::withCount(["ranks"])->findOrFail($careerId);
            $data["selectedCareer"] = $career;
            $data["earns"] = $career->earns()->orderBy("min_rank")->orderBy("min_career_xp")->get();
            $data["ranks"] = $career->ranks()->orderBy("rank_level")->get();
        }

        return $data;
    }

    private function getWorldData(Request $request): array
    {
        return [
            "cities" => City::withCount("characters")->get(),
            "properties" => Property::orderBy('name')->limit(500)->get(),
            "businesses" => Business::with("city:id,name")->orderBy('name')->limit(500)->get(),
        ];
    }

    private function getEngineData(Request $request): array
    {
        $category = $request->input("category", "Clothing");

        return [
            'isInstalled' => $this->cronService->isInstalled(),
            'jobs' => $this->cronService->getAllJobs(),
            'announcements' => Announcement::latest('published_at')->paginate(20),
            'category' => $category,
            'categories' => ['Clothing', 'cities', 'promotional', 'silhouettes', 'patterns'],
            'images' => $this->listImages($category),
        ];
    }

    private function listImages(string $category): array
    {
        $path = public_path("images/{$category}");
        if (!is_dir($path)) {
            return [];
        }

        $files = scandir($path);
        $images = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $images[] = asset("images/{$category}/{$file}");
        }

        return $images;
    }


    public function createEarn(Request $request, Career $career)
    {
        $validated = $request->validate([
            "code" => "required|string|max:50",
            "title" => "required|string|max:200",
            "min_rank" => "required|integer|min:1",
            "min_career_xp" => "required|integer|min:0",
            "rng_min" => "required|integer",
            "rng_max" => "required|integer",
            "payout_min" => "required|integer|min:0",
            "payout_max" => "required|integer|min:0",
            "xp_gain_min" => "required|integer|min:0",
            "xp_gain_max" => "required|integer|min:0",
            "stat_intelligence_min" => "nullable|integer|min:0",
            "stat_intelligence_max" => "nullable|integer|min:0",
            "stat_offense_min" => "nullable|integer|min:0",
            "stat_offense_max" => "nullable|integer|min:0",
            "stat_defense_min" => "nullable|integer|min:0",
            "stat_defense_max" => "nullable|integer|min:0",
            "stat_luck_min" => "nullable|integer|min:0",
            "stat_luck_max" => "nullable|integer|min:0",
            "stat_influence_min" => "nullable|integer|min:0",
            "stat_influence_max" => "nullable|integer|min:0",
            "success_message" => "required|string",
            "failure_message" => "required|string",
        ]);

        $earn = $career->earns()->create($validated);
        $this->logAction($request->user(), "earn.create", $earn, $validated);

        return redirect()
            ->route("admin.index", [
                "section" => "career",
                "career_id" => $career->id,
            ])
            ->with("success", "Created job: {$validated["title"]}");
    }

    public function updateEarn(
        Request $request,
        Career $career,
        CareerEarn $earn,
    ) {
        $validated = $request->validate([
            "title" => "required|string|max:200",
            "min_rank" => "required|integer|min:1",
            "min_career_xp" => "required|integer|min:0",
            "rng_min" => "required|integer",
            "rng_max" => "required|integer",
            "payout_min" => "required|integer|min:0",
            "payout_max" => "required|integer|min:0",
            "xp_gain_min" => "required|integer|min:0",
            "xp_gain_max" => "required|integer|min:0",
            "stat_intelligence_min" => "nullable|integer|min:0",
            "stat_intelligence_max" => "nullable|integer|min:0",
            "stat_offense_min" => "nullable|integer|min:0",
            "stat_offense_max" => "nullable|integer|min:0",
            "stat_defense_min" => "nullable|integer|min:0",
            "stat_defense_max" => "nullable|integer|min:0",
            "stat_luck_min" => "nullable|integer|min:0",
            "stat_luck_max" => "nullable|integer|min:0",
            "stat_influence_min" => "nullable|integer|min:0",
            "stat_influence_max" => "nullable|integer|min:0",
            "success_message" => "required|string",
            "failure_message" => "required|string",
        ]);

        $old = $earn->only(array_keys($validated));
        $earn->update($validated);
        $this->logAction($request->user(), "earn.update", $earn, [
            "old" => $old,
            "new" => $validated,
        ]);

        return redirect()
            ->route("admin.index", [
                "section" => "career",
                "career_id" => $career->id,
            ])
            ->with("success", "Updated {$earn->title}");
    }

    public function deleteEarn(
        Request $request,
        Career $career,
        CareerEarn $earn,
    ) {
        $title = $earn->title;
        $earn->delete();
        $this->logAction($request->user(), "earn.delete", $earn);

        return back()->with("success", "Deleted job: {$title}");
    }

    public function createItem(Request $request)
    {
        $validated = $request->validate([
            "name" => "required|string|max:100",
            "slug" => "required|string|max:100|unique:game_items,slug",
            "type" => "required|in:weapon,armor,gadget,item,vehicle,clothing",
            "slot" => "nullable|string|max:50",
            "description" => "nullable|string",
            "image_url" => "nullable|url|max:500",
            "price" => "required|integer|min:0",
            "durability" => "required|integer|min:1",
            "stock" => "nullable|integer|min:0",
            "max_stock" => "nullable|integer|min:0",
            "offense" => "nullable|integer|min:0",
            "defense" => "nullable|integer|min:0",
            "intelligence" => "nullable|integer|min:0",
            "influence" => "nullable|integer|min:0",
            "luck" => "nullable|integer|min:0",
            "is_active" => "required|boolean",
        ]);

        $dataStr = trim($request->input('data', ''));
        if (!empty($dataStr)) {
            $decoded = json_decode($dataStr, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return back()->withErrors(['data' => 'Invalid JSON format.'])->withInput();
            }
            $validated['data'] = $decoded;
        } else {
            $validated['data'] = null;
        }

        $item = GameItem::create($validated);
        $this->logAction($request->user(), "item.create", $item, $validated);

        return redirect()
            ->route("admin.index", ["section" => "career"])
            ->with("success", "Created item: {$validated["name"]}");
    }

    public function updateItem(Request $request, GameItem $item)
    {
        $validated = $request->validate([
            "name" => "required|string|max:100",
            "type" => "required|in:weapon,armor,gadget,item,vehicle,clothing",
            "slot" => [
                "nullable",
                "string",
                "max:50",
                function ($attribute, $value, $fail) use ($request) {
                    $type = $request->input('type');
                    if ($type === 'vehicle' && $value !== 'vehicle') {
                        $fail('Vehicles must have the slot "vehicle".');
                    }
                    if ($type === 'weapon' && $value !== 'weapon') {
                        $fail('Weapons must have the slot "weapon".');
                    }
                    if ($type === 'armor' && $value !== 'armor') {
                        $fail('Armor must have the slot "armor".');
                    }
                },
            ],
            "price" => "required|integer|min:0",
            "durability" => [
                "required",
                "integer",
                "min:1",
                function ($attribute, $value, $fail) use ($request) {
                    if (($request->input('type') === 'vehicle' || $request->input('type') === 'weapon') && $value > 10) {
                        $fail('Vehicles and weapons cannot have durability greater than 10.');
                    }
                },
            ],
            "offense" => "nullable|integer|min:0",
            "defense" => "nullable|integer|min:0",
            "intelligence" => "nullable|integer|min:0",
            "influence" => "nullable|integer|min:0",
            "luck" => "nullable|integer|min:0",
            "stock" => "nullable|integer|min:0",
            "max_stock" => "nullable|integer|min:0",
            "is_active" => "required|boolean",
        ]);

        $dataStr = trim($request->input('data', ''));
        if (!empty($dataStr)) {
            $decoded = json_decode($dataStr, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return back()->withErrors(['data' => 'Invalid JSON format.'])->withInput();
            }
            $validated['data'] = $decoded;
        } else {
            $validated['data'] = null;
        }

        
        if ($request->input('type') === 'weapon') {
            $weaponMessages = $request->validate([
                'kill_result_message'    => 'nullable|string|max:500',
                'damage_result_message'  => 'nullable|string|max:500',
                'damage_journal_message' => 'nullable|string|max:500',
            ]);
            $validated = array_merge($validated, array_map(
                fn($v) => ($v === '' ? null : $v),
                $weaponMessages
            ));
        } else {
            
            $validated['kill_result_message']    = null;
            $validated['damage_result_message']  = null;
            $validated['damage_journal_message'] = null;
        }

        $old = $item->only(array_keys($validated));
        $item->update($validated);
        $this->logAction($request->user(), "item.update", $item, [
            "old" => $old,
            "new" => $validated,
        ]);

        return redirect()
            ->route("admin.index", ["section" => "career"])
            ->with("success", "Updated {$item->name}");
    }

    public function deleteItem(Request $request, GameItem $item)
    {
        $name = $item->name;
        $item->delete();
        $this->logAction($request->user(), "item.delete", $item);

        return back()->with("success", "Deleted item: {$name}");
    }

    public function updateCity(Request $request, City $city)
    {
        $validated = $request->validate([
            "name" => "required|string|max:100",
            "description" => "nullable|string",
            "image_url" => "nullable|url|max:500",
        ]);

        $old = $city->only(array_keys($validated));
        $city->update($validated);
        $this->logAction($request->user(), "city.update", $city, [
            "old" => $old,
            "new" => $validated,
        ]);

        return back()->with("success", "Updated {$city->name}");
    }

    public function createProperty(Request $request)
    {
        $validated = $request->validate([
            "name" => "required|string|max:100",
            "price" => "required|integer|min:0",
            "image_url" => "nullable|url|max:500",
            "vehicle_capacity" => "required|integer|min:0",
            "safe_capacity" => "required|integer|min:0",
            "has_alarm" => "required|boolean",
            "influence_bonus_pct" => "nullable|integer|min:0",
            "intelligence_bonus_pct" => "nullable|integer|min:0",
            "offense_bonus_pct" => "nullable|integer|min:0",
            "defense_bonus_pct" => "nullable|integer|min:0",
        ]);

        $property = Property::create($validated);
        $this->logAction(
            $request->user(),
            "property.create",
            $property,
            $validated,
        );

        return back()->with("success", "Created property: {$property->name}");
    }

    public function updateProperty(Request $request, Property $property)
    {
        $validated = $request->validate([
            "name" => "required|string|max:100",
            "price" => "required|integer|min:0",
            "image_url" => "nullable|url|max:500",
            "vehicle_capacity" => "required|integer|min:0",
            "safe_capacity" => "required|integer|min:0",
            "has_alarm" => "required|boolean",
        ]);

        $old = $property->only(array_keys($validated));
        $property->update($validated);
        $this->logAction($request->user(), "property.update", $property, [
            "old" => $old,
            "new" => $validated,
        ]);

        return back()->with("success", "Updated {$property->name}");
    }

    public function updateBusiness(Request $request, Business $business)
    {
        $validated = $request->validate([
            "name" => "required|string|max:100",
            "description" => "nullable|string",
            "base_price" => "required|integer|min:0",
            "is_purchasable" => "required|boolean",
            "is_active" => "required|boolean",
            "owner_id" => "nullable|exists:characters,id",
        ]);

        $old = $business->only(array_keys($validated));
        $business->update($validated);
        $this->logAction($request->user(), "business.update", $business, [
            "old" => $old,
            "new" => $validated,
        ]);

        return back()->with("success", "Updated {$business->name}");
    }

    public function clearBusinessOwner(Request $request, Business $business)
    {
        $oldOwner = $business->owner_id;
        $business->update(["owner_id" => null]);
        
        $this->logAction($request->user(), "business.clear_owner", $business, [
            "old_owner_id" => $oldOwner,
        ]);

        return back()->with("success", "Cleared owner of {$business->name}");
    }

    public function createAnnouncement(Request $request)
    {
        $validated = $request->validate([
            "title" => "required|string|max:200",
            "message" => "required|string",
            "type" => "required|in:info,warning",
            "is_active" => "required|boolean",
            "published_at" => "nullable|date",
            "expires_at" => "nullable|date",
            "also_email" => "sometimes|boolean",
        ]);

        $alsoEmail = (bool) ($validated['also_email'] ?? false);
        unset($validated['also_email']);

        $data = $validated;

        if ($data["is_active"]) {
            $data["published_at"] = $data["published_at"] ?? now();
        } else {
            $data["published_at"] = null;
        }

        // Default expiry: 24 hours from publication. Admins who want a
        // permanent announcement must clear the field on edit; defaulting
        // to +24h prevents the historical mess of permanent stale notices.
        if (empty($data["expires_at"])) {
            $data["expires_at"] = ($data["published_at"] ?? now())->copy()->addDay();
        }

        $announcement = Announcement::create($data);
        $this->logAction(
            $request->user(),
            "announcement.create",
            $announcement,
            $data,
        );

        // Fire-and-forget email broadcast. The actual send runs AFTER the
        // HTTP response has been delivered to the admin, so this handler
        // returns in well under a second regardless of how many users we
        // need to email. Without this, large broadcasts blocked the
        // request past the 30-second Global ALB backend timeout.
        $message = "Created announcement: {$announcement->title}";
        if ($alsoEmail && $announcement->is_active) {
            $this->scheduleAnnouncementBroadcast($announcement);
            $message .= ' — emailing users in the background.';
        }

        return back()->with("success", $message);
    }

    /**
     * Schedule the email broadcast to run AFTER the current HTTP response
     * has been delivered. Under FrankenPHP/Octane the worker process
     * survives past response flush, so PHP's register_shutdown_function
     * gives us a clean "fire and forget" without spawning subprocesses or
     * standing up a queue worker. The admin sees an instant response, the
     * broadcast continues in the same worker for however long it takes.
     */
    private function scheduleAnnouncementBroadcast(Announcement $announcement): void
    {
        $announcementId = $announcement->id;

        register_shutdown_function(function () use ($announcementId) {
            // Re-fetch from DB inside the closure. We can't carry the
            // Eloquent instance into the shutdown phase safely because
            // Octane may have reset container state by then.
            try {
                $fresh = Announcement::find($announcementId);
                if (!$fresh) {
                    Log::warning('[AnnouncementBroadcast] announcement gone before background send', [
                        'announcement_id' => $announcementId,
                    ]);
                    return;
                }

                // Bump PHP's per-script time limit. FrankenPHP/Octane
                // ignores most CLI limits but defensive code is cheap.
                if (function_exists('set_time_limit')) {
                    @set_time_limit(900);
                }

                $this->broadcastAnnouncementEmail($fresh);
            } catch (\Throwable $e) {
                Log::error('[AnnouncementBroadcast] background send crashed', [
                    'announcement_id' => $announcementId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });
    }

    /**
     * Email the announcement to every opted-in user with a valid email.
     * Returns the number of emails actually dispatched.
     *
     * Called from a post-response shutdown function so this can run for
     * minutes without blocking the HTTP request. Cloud Run's request
     * lifecycle is generous about post-response work in FrankenPHP
     * worker mode — the worker stays alive until the next request, and
     * shutdown functions run before that handoff.
     */
    //! TODO NOT SURE THE EMAIL ANNOUNCEMENTS IS WORKING
    private function broadcastAnnouncementEmail(Announcement $announcement): int
    {
        $sent = 0;
        $skipped = 0;

        User::query()
            ->whereNull('email_opt_out_at')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereRaw('"is_banned" IS FALSE')
            ->select(['id', 'username', 'email'])
            ->orderBy('id')
            ->chunk(10, function ($chunk) use (&$sent, &$skipped, $announcement) {
                foreach ($chunk as $user) {
                    try {
                        \Illuminate\Support\Facades\Mail::to($user->email)->send(
                            new \App\Mail\SystemAnnouncementMail(
                                $announcement->title,
                                $announcement->message,
                                $user,
                            )
                        );
                        $sent++;
                    } catch (\Throwable $e) {
                        // Known Octane + Symfony Mailer interaction: the
                        // Stopwatch profiler throws on stop() because the
                        // start event was lost between requests. The SMTP
                        // transport has already delivered by this point
                        // — the exception fires only on post-send cleanup.
                        // Treat this specific error as success.
                        if (str_contains($e->getMessage(), 'Failed stopping measure')) {
                            $sent++;
                            continue;
                        }

                        $skipped++;
                        Log::warning('[AnnouncementBroadcast] send failed', [
                            'user_id' => $user->id,
                            'email' => $user->email,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                // Gentle pause between batches to stay under Gmail's
                // per-second send caps. ~10 emails/sec is well within limits.
                usleep(800_000);
            });

        Log::info('[AnnouncementBroadcast] complete', [
            'announcement_id' => $announcement->id,
            'sent' => $sent,
            'skipped' => $skipped,
        ]);

        return $sent;
    }

    public function updateAnnouncement(
        Request $request,
        Announcement $announcement,
    ) {
        $validated = $request->validate([
            "title" => "required|string|max:200",
            "message" => "required|string",
            "type" => "required|in:info,warning",
            "is_active" => "required|boolean",
            "published_at" => "nullable|date",
            "expires_at" => "nullable|date",
        ]);

        $data = $validated;

        if ($data["is_active"]) {
            $data["published_at"] = $data["published_at"] ?? $announcement->published_at ?? now();
        } else {
            $data["published_at"] = null;
        }

        $old = $announcement->only(array_keys($data));
        $announcement->update($data);
        $this->logAction(
            $request->user(),
            "announcement.update",
            $announcement,
            ["old" => $old, "new" => $data],
        );

        return back()->with("success", "Updated announcement");
    }

    public function deleteAnnouncement(
        Request $request,
        Announcement $announcement,
    ) {
        $announcement->delete();
        $this->logAction(
            $request->user(),
            "announcement.delete",
            $announcement,
        );

        return back()->with("success", "Deleted announcement");
    }
 

    public function userDetail(Request $request, User $user)
    {
        if (!$request->expectsJson()) {
            abort(404);
        }

        $user->load([
            'character' => fn($q) => $q->withTrashed()->with([
                'stats',
                'career',
                'journals' => fn($j) => $j->latest()->limit(20),
            ]),
            'achievements' => fn($q) => $q->select('achievements.id', 'name', 'icon', 'icon_url', 'description')
                ->withPivot('unlocked_at'),
        ]);

        $character = $user->character;
        $messages = [];

        if ($character) {
            $messages = \App\Models\Message::where(function ($q) use ($character) {
                    $q->where('sender_id', $character->id)
                      ->orWhere('recipient_id', $character->id);
                })
                ->with('sender:id,display_name', 'recipient:id,display_name')
                ->latest()
                ->limit(30)
                ->get()
                ->map(fn($m) => [
                    'id' => $m->id,
                    'sender' => $m->sender?->display_name ?? 'Unknown',
                    'recipient' => $m->recipient?->display_name ?? 'Unknown',
                    'body' => $m->body,
                    'created_at' => $m->created_at->toDateTimeString(),
                    'is_outgoing' => $m->sender_id === $character->id,
                ]);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'is_banned' => $user->is_banned,
                'ban_reason' => $user->ban_reason,
                'last_ip' => $user->last_ip,
                'last_login_at' => $user->last_login_at,
                'created_at' => $user->created_at,
            ],
            'character' => $character ? [
                'id' => $character->id,
                'display_name' => $character->display_name,
                'health' => $character->health,
                'max_health' => $character->max_health,
                'cash_on_hand' => $character->cash_on_hand,
                'cash_in_bank' => $character->cash_in_bank,
                'dirty_cash' => $character->dirty_cash,
                'career' => $character->career?->name ?? 'Unemployed',
                'career_rank' => $character->career_rank,
                'career_xp' => $character->career_xp,
                'total_character_exp' => $character->total_character_exp,
                'total_earns' => $character->total_earns ?? 0,
                'degrees' => $character->degrees,
                'is_dead' => $character->trashed(),
                'deleted_at' => $character->deleted_at,
                'stats' => $character->stats ? [
                    'influence' => $character->stats->influence,
                    'intelligence' => $character->stats->intelligence,
                    'offense' => $character->stats->offense,
                    'defense' => $character->stats->defense,
                    'luck' => $character->stats->luck,
                ] : null,
            ] : null,
            'achievements' => $user->achievements->map(fn($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'icon' => $a->icon,
                'icon_url' => $a->icon_url,
                'description' => $a->description,
                'unlocked_at' => $a->pivot->unlocked_at,
            ]),
            'journals' => $character?->journals->map(fn($j) => [
                'id' => $j->id,
                'type' => $j->type,
                'title' => $j->title,
                'description' => $j->description,
                'created_at' => $j->created_at->toDateTimeString(),
            ]) ?? [],
            'messages' => $messages,
        ]);
    }

    public function banUser(Request $request, User $user)
    {
        $validated = $request->validate([
            "reason" => "required|string|max:500",
            "duration" => "nullable|in:1week,1month,permanent",
        ]);

        if ($user->id === $request->user()->id) {
            return back()->with("error", "Cannot ban yourself");
        }

        if ($user->is_admin) {
            return back()->with("error", "Cannot ban administrators");
        }

        $until = match ($validated["duration"] ?? "permanent") {
            "1week" => now()->addWeek(),
            "1month" => now()->addMonth(),
            default => null, // permanent
        };

        $this->userService->banUser($user, $validated["reason"], $until);
        $this->logAction($request->user(), "user.ban", $user, [
            "reason" => $validated["reason"],
            "duration" => $validated["duration"] ?? "permanent",
            "banned_until" => $until?->toDateTimeString(),
        ]);

        $label = $until ? "until {$until->toDayDateTimeString()}" : "permanently";
        return back()->with("success", "Banned {$user->username} {$label}");
    }

    public function unbanUser(Request $request, User $user)
    {
        $this->userService->unbanUser($user);
        $this->logAction($request->user(), "user.unban", $user);

        return back()->with("success", "Unbanned {$user->username}");
    }

    

    public function syncCron(Request $request)
    {
        if ($this->cronService->resetGameJobs()) {
            $this->logAction($request->user(), "cron.sync", null);
            return back()->with("success", "Cron jobs synced successfully.");
        }

        return back()->with("error", "Failed to sync cron jobs.");
    }

    public function toggleCron(Request $request)
    {
        $validated = $request->validate([
            "jobid" => "required|integer",
            "active" => "required|boolean",
        ]);

        if ($this->cronService->toggleJob($validated["jobid"], $validated["active"])) {
            $this->logAction($request->user(), "cron.toggle", null, $validated);
            return back()->with("success", $validated["active"] ? "Enabled cron job." : "Disabled cron job.");
        }

        return back()->with("error", "Failed to toggle cron job.");
    }

    public function updateCron(Request $request)
    {
        $validated = $request->validate([
            "jobid" => "required|integer",
            "jobname" => "required|string",
            "schedule" => "required|string",
            "command" => "nullable|string",
            "active" => "boolean",
        ]);

        if (!empty($validated['command'])) {
            $blocked = ['DROP', 'DELETE', 'TRUNCATE', 'ALTER', 'GRANT', 'REVOKE'];
            foreach ($blocked as $keyword) {
                if (stripos($validated['command'], $keyword) !== false) {
                    return back()->with("error", "Command contains blocked keyword: {$keyword}");
                }
            }
        }

        if ($this->cronService->updateJobById($validated["jobid"], $validated)) {
            $this->logAction($request->user(), "cron.update", null, $validated);
            return back()->with("success", "Updated cron job.");
        }

        return back()->with("error", "Failed to update cron job.");
    }

     

    private function getForumData(Request $request): array
    {
        $categories = ForumCategory::orderBy('sort_order')
            ->withCount('posts')
            ->get();

        $recentPosts = ForumPost::with('character:id,display_name')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'author' => $p->character?->display_name,
                'category_id' => $p->category_id,
                'is_pinned' => $p->is_pinned,
                'is_locked' => $p->is_locked,
                'votes' => $p->votes,
                'created_at' => $p->created_at->toDateTimeString(),
            ]);

        return [
            'forumCategories' => $categories,
            'forumPosts' => $recentPosts,
            'forumStats' => [
                'totalPosts' => ForumPost::count(),
                'totalCategories' => ForumCategory::count(),
            ],
        ];
    }

    public function createForumCategory(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'icon'        => 'nullable|string|max:50',
            'sort_order'  => 'nullable|integer|min:0',
            'admin_only'  => 'boolean',
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        if (ForumCategory::where('slug', $validated['slug'])->exists()) {
            return back()->with('error', 'A category with that name already exists.');
        }

        $category = ForumCategory::create($validated);
        Cache::forget('forum_categories');
        $this->logAction($request->user(), 'forum.category.create', $category, $validated);

        return back()->with('success', "Category '{$category->name}' created.");
    }

    public function updateForumCategory(Request $request, ForumCategory $category)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'icon'        => 'nullable|string|max:50',
            'sort_order'  => 'nullable|integer|min:0',
            'is_active'   => 'boolean',
            'admin_only'  => 'boolean',
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        $category->update($validated);
        Cache::forget('forum_categories');
        $this->logAction($request->user(), 'forum.category.update', $category, $validated);

        return back()->with('success', "Category '{$category->name}' updated.");
    }

    public function deleteForumCategory(Request $request, ForumCategory $category)
    {
        if ($category->posts()->count() > 0) {
            return back()->with('error', 'Cannot delete category with existing posts. Move or delete posts first.');
        }

        $this->logAction($request->user(), 'forum.category.delete', $category);
        $category->delete();

        return back()->with('success', 'Category deleted.');
    }

    public function createForumPost(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|integer|exists:forum_categories,id',
            'title' => 'required|string|min:3|max:200',
            'body' => 'required|string|min:3|max:5000',
            'is_pinned' => 'boolean',
            'is_locked' => 'boolean',
        ]);

        $character = $request->user()->character;
        if (!$character) {
            return back()->with('error', 'No character found for admin user.');
        }

        $post = ForumPost::create([
            'category_id' => $validated['category_id'],
            'character_id' => $character->id,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'is_pinned' => $validated['is_pinned'] ?? true,
            'is_locked' => $validated['is_locked'] ?? false,
        ]);

        $this->logAction($request->user(), 'forum.post.create', $post, $validated);

        return back()->with('success', "Thread '{$post->title}' created.");
    }

    public function deleteForumPost(Request $request, ForumPost $post)
    {
        $this->logAction($request->user(), 'forum.post.delete', $post, ['title' => $post->title]);
        $post->delete();

        return back()->with('success', 'Post deleted.');
    }

    public function toggleForumPin(Request $request, ForumPost $post)
    {
        $post->update(['is_pinned' => !$post->is_pinned]);
        $this->logAction($request->user(), 'forum.post.pin', $post, ['pinned' => $post->is_pinned]);

        return back()->with('success', $post->is_pinned ? 'Post pinned.' : 'Post unpinned.');
    }

    public function toggleForumLock(Request $request, ForumPost $post)
    {
        $post->update(['is_locked' => !$post->is_locked]);
        $this->logAction($request->user(), 'forum.post.lock', $post, ['locked' => $post->is_locked]);

        return back()->with('success', $post->is_locked ? 'Post locked.' : 'Post unlocked.');
    }

    private function logAction(
        $admin,
        string $action,
        $subject,
        array $changes = [],
    ) {
        ActivityLog::create([
            "user_id" => $admin->id,
            "action" => $action,
            "subject_id" => $subject?->id,
            "subject_type" => $subject ? get_class($subject) : null,
            "changes" => $changes,
            "ip_address" => request()->ip(),
        ]);
    }

}
