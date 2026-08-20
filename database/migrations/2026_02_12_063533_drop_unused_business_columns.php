<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    
    
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['owner_title', 'leader_career_code', 'leader_title', 'icon']);
        });
    }

    
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('owner_title')->nullable();
            $table->string('leader_career_code')->nullable();
            $table->string('leader_title')->nullable();
            $table->string('icon')->nullable();
        });
    }
};
