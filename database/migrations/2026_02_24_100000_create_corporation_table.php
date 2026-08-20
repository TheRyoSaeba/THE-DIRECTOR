<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    public function up(): void
    {
        Schema::create('corporations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedBigInteger('home_city_id');
            $table->unsignedBigInteger('founder_id')->nullable();
            $table->unsignedBigInteger('ceo_id')->nullable();
            $table->bigInteger('slush_fund')->default(0);
            $table->boolean('is_holding_company')->default(false);
            $table->unsignedBigInteger('parent_trust_id')->nullable();
            $table->integer('max_members')->default(1);
            $table->string('image_url', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('home_city_id')->references('id')->on('cities');
            $table->foreign('founder_id')->references('id')->on('characters')->onDelete('set null');
            $table->foreign('ceo_id')->references('id')->on('characters')->onDelete('set null');
            $table->foreign('parent_trust_id')->references('id')->on('corporations')->onDelete('set null');
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedBigInteger('corporation_id')->nullable()->after('property_id');
            $table->string('corporation_position', 20)->nullable()->after('corporation_id');

            $table->foreign('corporation_id')->references('id')->on('corporations')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropForeign(['corporation_id']);
            $table->dropColumn(['corporation_id', 'corporation_position']);
        });

        Schema::dropIfExists('corporations');
    }
};
