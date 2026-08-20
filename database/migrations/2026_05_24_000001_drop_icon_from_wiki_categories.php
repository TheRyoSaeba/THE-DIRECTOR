<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Drops wiki_categories.icon — the original table shipped with an emoji/icon
// column to decorate the sidebar, but the user dislikes that "slop emoji"
// aesthetic, so we're stripping it. Done as a follow-up migration (not by
// editing the original create migration) because the create migration has
// already shipped to prod and rewriting history would diverge envs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wiki_categories', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }

    public function down(): void
    {
        Schema::table('wiki_categories', function (Blueprint $table) {
            $table->string('icon', 32)->nullable();
        });
    }
};
