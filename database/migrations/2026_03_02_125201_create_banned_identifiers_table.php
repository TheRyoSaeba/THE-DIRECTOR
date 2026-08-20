<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::create('banned_users', function (Blueprint $table) {
            $table->id();
            $table->string('type'); 
            $table->string('value')->unique(); 
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('banned_by')->nullable(); 
            $table->timestamp('banned_at');
            $table->timestamp('expires_at')->nullable(); 
            $table->timestamps();

            $table->index(['type', 'value']);
            $table->index('banned_at');
        });
    }

    
    public function down(): void
    {
        Schema::dropIfExists('banned_users');
    }
};
