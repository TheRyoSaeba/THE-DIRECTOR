<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    
    public function up(): void
    {
        Schema::dropIfExists('outfit_items');

        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['outfit', 'inventory']);
        });
    }

    
    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->jsonb('outfit')->nullable();
            $table->jsonb('inventory')->default('[]');
        });
    }
};
