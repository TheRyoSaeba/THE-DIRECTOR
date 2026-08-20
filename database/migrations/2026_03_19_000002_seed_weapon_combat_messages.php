<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;


return new class extends Migration 
{
    public function up(): void
    {
        $messages = [
            'police-baton' => [
                'kill_result_message' =>
                'You cornered {defender} in a narrow alley, baton swinging. They went down and didn\'t get back up.',

                'damage_result_message' =>
                '{crit}You caught {defender} off‑guard and brought the baton across their back for {damage} damage.{injury}',

                'damage_journal_message' =>
                '{crit}{attacker} came out of nowhere, a baton whistling through the air. It caught you square for {damage} damage before you could react.{injury}',
            ],

            'unmarked-pistol' => [
                'kill_result_message' =>
                'Clean, quiet, untraceable. {defender} dropped without a sound — two shots, centre mass, job done.',

                'damage_result_message' =>
                '{crit}You squeezed off a round from the shadows, hitting {defender} for {damage} damage. They’re still on their feet, but they know you’re there now.{injury}',

                'damage_journal_message' =>
                '{crit}A muffled pop – {attacker} had you in their crosshairs. The bullet tore through you for {damage} damage, no muzzle flash, no warning.{injury}',
            ],

            'rex-aureus-rifle' => [
                'kill_result_message' =>
                'The Rex Aureus doesn’t miss twice. {defender} crumpled before they hit the ground, the echo of the shot swallowed by the city.',

                'damage_result_message' =>
                '{crit}You lined up the shot and squeezed. The Rex Aureus connected with {defender} for {damage} damage – expensive taste, expensive consequences.{injury}',

                'damage_journal_message' =>
                '{crit}{attacker} lined you up from their spot and squeezed off a round from their Rex Aureus. You took {damage} damage before you even knew what hit you.{injury}',
            ],

            'savior-legacy-sniper' => [
                'kill_result_message' =>
                ' You managed to find the perfect spot from a rooftop across the street and had {defender} in the scope. One trigger pull. Done.',

                'damage_result_message' =>
                '{crit}You managed to find a high spot and put a round through {defender} from a  distance — They took {damage} damage before they could run for cover.{injury}',

                'damage_journal_message' =>
                '{crit}{attacker} was waiting on a rooftop with a Savior Legacy Sniper. They managed to hit you for {damage} damage before you could dive for cover.{injury}',
            ],

            'end-permian' => [
                'kill_result_message' =>
                'You cornered {defender}, scoped in with your M4 End Permian and let loose. The M4 End Permian doesn’t leave survivors, they were torn to shreds and down for good.',

                'damage_result_message' =>
                '{crit}You let loose with the End Permian, stitching rounds across {defender} for {damage} damage. They will surely remember this.{injury}',

                'damage_journal_message' =>
                '{crit}{attacker} opened up with an M4 End Permian – a wall of automatic fire. You caught {damage} damage before you could get behind anything solid.{injury}',
            ],        ];

        foreach ($messages as $slug => $cols) {
            DB::table('game_items')
                ->where('slug', $slug)
                ->update($cols);
        }
    }

    public function down(): void
    {
        DB::table('game_items')
            ->whereIn('slug', [
            'police-baton',
            'unmarked-pistol',
            'rex-aureus-rifle',
            'savior-legacy-sniper',
            'end-permian',
        ])
            ->update([
            'kill_result_message' => null,
            'damage_result_message' => null,
            'damage_journal_message' => null,
        ]);
    }
};
