<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    
    public function up(): void
    {
        DB::unprepared('
            CREATE OR REPLACE FUNCTION check_business_ownership_limit()
            RETURNS TRIGGER AS $$
            BEGIN
                -- Only check if we are assigning an owner (owner_id is not null)
                IF NEW.owner_id IS NOT NULL THEN
                    IF (SELECT COUNT(*) FROM businesses WHERE owner_id = NEW.owner_id) >= 3 THEN
                        RAISE EXCEPTION \'Business ownership limit reached. A character cannot own more than 3 businesses.\';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ');

        DB::unprepared('
            CREATE TRIGGER trg_check_business_limit
            BEFORE UPDATE OF owner_id ON businesses
            FOR EACH ROW
            WHEN (NEW.owner_id IS NOT NULL AND (OLD.owner_id IS NULL OR OLD.owner_id != NEW.owner_id))
            EXECUTE FUNCTION check_business_ownership_limit();
        ');
    }

    
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_check_business_limit ON businesses');
        DB::unprepared('DROP FUNCTION IF EXISTS check_business_ownership_limit');
    }
};
