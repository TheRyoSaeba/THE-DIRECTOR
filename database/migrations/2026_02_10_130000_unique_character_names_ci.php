<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration 
{
    
    public function up(): void
    {
        try {
            DB::statement('CREATE UNIQUE INDEX characters_display_name_lower_unique ON characters (LOWER(display_name));');
        }
        catch (\Exception $e) {
        }
    }

    
    public function down(): void
    {
        try {
            DB::statement('DROP INDEX IF EXISTS characters_display_name_lower_unique;');
        }
        catch (\Exception $e) {
        }
    }
};
