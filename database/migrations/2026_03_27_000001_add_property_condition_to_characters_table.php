<?php
//TODO add to prod and  the black market as well

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('property_condition')->nullable()->after('property_id');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('property_condition');
        });
    }
};
