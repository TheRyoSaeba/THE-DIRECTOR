<?php



use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->onDelete('cascade');
            $table->string('type'); 
            $table->json('data'); 
            $table->boolean('is_read')->default(false);
            $table->timestamps(); 
        });

        Schema::table('character_journals', function (Blueprint $table) {
            $table->index(['character_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_journals');
    }
};
