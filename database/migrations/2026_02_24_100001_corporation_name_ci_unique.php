<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        
        DB::statement('ALTER TABLE corporations DROP CONSTRAINT IF EXISTS corporations_name_unique;');

        
        
        DB::statement('CREATE UNIQUE INDEX corporations_name_lower_unique ON corporations (LOWER(name));');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS corporations_name_lower_unique;');
        DB::statement('ALTER TABLE corporations ADD CONSTRAINT corporations_name_unique UNIQUE (name);');
    }
};
