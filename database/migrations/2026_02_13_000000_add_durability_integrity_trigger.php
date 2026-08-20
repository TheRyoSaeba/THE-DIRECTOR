<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration 
{
    
    public function up(): void
    {
        DB::unprepared("
            CREATE OR REPLACE FUNCTION enforce_item_durability_integrity()
            RETURNS TRIGGER AS $$
            DECLARE
                max_dur integer;
                item_type text;
            BEGIN
                -- Get the template info
                SELECT durability, type INTO max_dur, item_type 
                FROM game_items 
                WHERE id = NEW.game_item_id;

                -- Rule 1: Clothes are indestructible (null durability)
                IF item_type = 'clothing' THEN
                    NEW.durability_remaining := NULL;
                END IF;

                -- Rule 2: Cannot exceed max durability
                IF max_dur IS NOT NULL AND NEW.durability_remaining > max_dur THEN
                    NEW.durability_remaining := max_dur;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ");

        DB::unprepared("
            CREATE TRIGGER trg_character_items_durability_integrity
            BEFORE INSERT OR UPDATE ON character_items
            FOR EACH ROW
            EXECUTE FUNCTION enforce_item_durability_integrity();
        ");

        DB::statement("
            UPDATE character_items ci
            SET durability_remaining = NULL
            FROM game_items gi
            WHERE ci.game_item_id = gi.id AND gi.type = 'clothing'
        ");

        DB::statement("
            UPDATE character_items ci
            SET durability_remaining = gi.durability
            FROM game_items gi
            WHERE ci.game_item_id = gi.id 
            AND gi.durability IS NOT NULL 
            AND ci.durability_remaining > gi.durability
        ");
    }

    
    public function down(): void
    {
        DB::unprepared("DROP TRIGGER IF EXISTS trg_character_items_durability_integrity ON character_items");
        DB::unprepared("DROP FUNCTION IF EXISTS enforce_item_durability_integrity()");
    }
};
