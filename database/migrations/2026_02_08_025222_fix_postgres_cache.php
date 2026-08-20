// database/migrations/2025_02_07_000003_fix_postgres_cache.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('DISCARD ALL');

        DB::statement('DEALLOCATE ALL');

        config(['database.connections.pgsql.options' => [
            PDO::ATTR_EMULATE_PREPARES => false,
        ]]);

        DB::reconnect();
    }

    public function down(): void
    {
    }
};
