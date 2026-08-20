<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('characters')->onDelete('cascade');
            $table->foreignId('recipient_id')->nullable()->constrained('characters')->onDelete('cascade');
            $table->string('group_id')->nullable(); 
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            
            $table->index(['sender_id', 'created_at']);
            $table->index(['recipient_id', 'read_at', 'created_at']);
            $table->index(['group_id', 'created_at']);
            $table->index(['sender_id', 'recipient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
