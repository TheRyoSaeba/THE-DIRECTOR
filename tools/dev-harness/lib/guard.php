<?php
// Exits non-zero unless Laravel resolves the DB to 127.0.0.1/localhost and APP_ENV=local.
require __DIR__ . '/bootstrap.php';
echo "guard ok: DB " . config('database.default') . '@' . config('database.connections.' . config('database.default') . '.host') . "\n";
