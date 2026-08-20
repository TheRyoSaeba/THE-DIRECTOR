<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    
    public function up(): void
    {
        Schema::table('outfit_items', function (Blueprint $table) {
            $table->integer('stock')->default(0);
            $table->integer('max_stock')->default(10);
        });
    }

    
    public function down(): void
    {
        Schema::table('outfit_items', function (Blueprint $table) {
        });
    }
};
