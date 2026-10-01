<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Missing FK / lookup indexes found by the write-contention audit.
 *
 * Every index is built with CREATE INDEX CONCURRENTLY (no write lock on the
 * table), so this migration runs outside a transaction. On Neon run it over
 * the DIRECT (non -pooler) connection: CONCURRENTLY cannot run inside a
 * transaction block and pgbouncer transaction pooling may interleave
 * statements across server connections. If a concurrent build fails it
 * leaves an INVALID index; IF NOT EXISTS would then skip it, so drop the
 * invalid index (see down()) and re-run.
 *
 * Each column was verified to exist and no equivalent index (as leading
 * column) exists in earlier migrations.
 *
 * Flagged but intentionally NOT dropped here (owner to decide):
 *  - users: users_username_unique + idx_users_username (duplicate of the
 *    unique index); users_is_banned_index + idx_users_is_banned (duplicates).
 *  - character_timers_next_work_at_index: next_work_at is rewritten on every
 *    /work, so the index prevents HOT updates on the hottest table.
 *  - characters_unread_journal_count_index: counter bumped on every journal
 *    insert; same HOT-update penalty, low selectivity.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->postgresIndexes() as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys($this->postgresIndexes()) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }

    private function postgresIndexes(): array
    {
        return [
            'idx_characters_corporation_id'            => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_characters_corporation_id ON characters (corporation_id) WHERE corporation_id IS NOT NULL',
            'idx_characters_corporation_reports_to_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_characters_corporation_reports_to_id ON characters (corporation_reports_to_id) WHERE corporation_reports_to_id IS NOT NULL',
            'idx_businesses_owner_id'                  => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_businesses_owner_id ON businesses (owner_id) WHERE owner_id IS NOT NULL',
            'idx_crime_records_prosecutor_id'          => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_crime_records_prosecutor_id ON crime_records (prosecutor_id) WHERE prosecutor_id IS NOT NULL',
            'idx_crime_records_defense_id'             => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_crime_records_defense_id ON crime_records (defense_id) WHERE defense_id IS NOT NULL',
            'idx_crime_records_judge_id'               => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_crime_records_judge_id ON crime_records (judge_id) WHERE judge_id IS NOT NULL',
            'idx_crime_records_status_charged_at'      => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_crime_records_status_charged_at ON crime_records (status, charged_at)',
            'idx_character_journals_type'              => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_character_journals_type ON character_journals (type)',
            'idx_users_username_lower'                 => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_username_lower ON users (LOWER(username))',
            'idx_forum_posts_character_id'             => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_forum_posts_character_id ON forum_posts (character_id)',
            'idx_forum_replies_character_id'           => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_forum_replies_character_id ON forum_replies (character_id)',
            'idx_forum_votes_character_id'             => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_forum_votes_character_id ON forum_votes (character_id)',
            'idx_campaigns_candidate_id'               => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_campaigns_candidate_id ON campaigns (candidate_id)',
            'idx_campaign_votes_voter_id'              => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_campaign_votes_voter_id ON campaign_votes (voter_id)',
            'idx_corporations_ceo_id'                  => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_corporations_ceo_id ON corporations (ceo_id) WHERE ceo_id IS NOT NULL',
            'idx_corporations_founder_id'              => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_corporations_founder_id ON corporations (founder_id) WHERE founder_id IS NOT NULL',
            'idx_corporations_parent_trust_id'         => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_corporations_parent_trust_id ON corporations (parent_trust_id) WHERE parent_trust_id IS NOT NULL',
        ];
    }
};
