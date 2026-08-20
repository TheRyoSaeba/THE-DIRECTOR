<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            
            
            $table->smallInteger('income_tax_rate')->default(5)->after('mayor_display_name');

            
            
            $table->smallInteger('corporate_tax_rate')->default(0)->after('income_tax_rate');

            
            $table->boolean('corp_regulation_active')->default(false)->after('corporate_tax_rate');

            
            $table->boolean('bonds_active')->default(false)->after('corp_regulation_active');

            
            $table->boolean('death_sentence_active')->default(false)->after('bonds_active');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn([
                'income_tax_rate',
                'corporate_tax_rate',
                'corp_regulation_active',
                'bonds_active',
                'death_sentence_active',
            ]);
        });
    }
};
