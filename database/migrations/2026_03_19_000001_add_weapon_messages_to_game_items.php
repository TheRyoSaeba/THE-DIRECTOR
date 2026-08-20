<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds weapon-specific combat flavour message columns to game_items.
 *
 * Three columns — one per surface, null-safe (generic fallback used when null):
 *
 *   kill_result_message    — shown on the attacker's ConflictResults page on kill.
 *                            Defender is dead so there is NO kill journal message.
 *
 *   damage_result_message  — shown on the attacker's ConflictResults page on a hit.
 *
 *   damage_journal_message — written to the DEFENDER'S journal on a hit (second
 *                            person). References attacker, weapon, damage dealt.
 *
 * Supported tokens (resolved at runtime in ConflictController):
 *   {attacker}  — attacker display_name
 *   {defender}  — defender display_name
 *   {damage}    — numeric damage dealt
 *   {crit}      — "CRITICAL HIT! " prefix string, or empty string when not crit
 *   {injury}    — injury text e.g. " They lost 2 max HP!", or empty string
 *
 * All three columns are nullable. ConflictController falls back to the
 * existing generic strings when any column is null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_items', function (Blueprint $table) {
            $table->text('kill_result_message')->nullable()->after('data');
            $table->text('damage_result_message')->nullable()->after('kill_result_message');
            $table->text('damage_journal_message')->nullable()->after('damage_result_message');
        });
    }

    public function down(): void
    {
        Schema::table('game_items', function (Blueprint $table) {
            $table->dropColumn([
                'kill_result_message',
                'damage_result_message',
                'damage_journal_message',
            ]);
        });
    }
};
