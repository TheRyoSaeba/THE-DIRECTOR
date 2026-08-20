<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the blanket unique constraint that prevents a corp from having
        // both a CONSTRUCTED HQ and a PENDING upgrade row at the same time.
        // The partial index below replaces it with a constraint that is
        // scoped only to non-PENDING owned rows, allowing exactly:
        //
        //   - One CONSTRUCTED row per (corporation_id, type)          ← enforced
        //   - One PENDING row alongside it during an upgrade build     ← allowed
        //   - Multiple template rows (corporation_id IS NULL)          ← always allowed
        //     (Postgres unique indexes treat NULLs as distinct, so
        //      template rows were never affected by the old constraint)
        //
        // Double-PENDING is prevented at the application layer in
        // CorporationProperty::purchaseTemplate(), not here.

        Schema::table('corporation_properties', function ($table) {
            $table->dropUnique('corporation_properties_corporation_id_type_unique');
        });

        // Partial unique index: only fires for owned, non-PENDING rows.
        // corporation_id IS NOT NULL excludes template rows explicitly.
        DB::statement("
            CREATE UNIQUE INDEX corp_properties_owned_singleton
            ON corporation_properties (corporation_id, type)
            WHERE corporation_id IS NOT NULL
              AND condition != 'PENDING'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS corp_properties_owned_singleton');

        Schema::table('corporation_properties', function ($table) {
            $table->unique(['corporation_id', 'type']);
        });
    }
};
