<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $earns = [

            'corp_hustle' => [
                'title' => 'Prep work',
                'success_message' => 'You spent the day doing prep work , reformatting the partner\'s pitch deck for the third time, and got told the slides looked sharp. You earned ${payout} for your time!',
                'failure_message' => 'You spent the day chasing data the partner needed for a Friday meeting and the source never came back to you. The deck went out incomplete. No pay!',
            ],
            'corp_first_deal' => [
                'title' => 'Help at Meeting',
                'success_message' => 'Your contribution to the model held up under scrutiny in the closing meeting — the deal signed and you earned ${payout}.',
                'failure_message' => 'The deal was as good as done, but the partners decided to go in a different direction and canceled the deal - you earned nothing.',
            ],
            'corp_expand' => [
                'title' => 'Run some Numbers',
                'success_message' => 'You ran the cost analysis for the new regional office and the numbers held — sign-off came down and you earned ${payout}.',
                'failure_message' => 'Your cost analysis for the regional office relied on numbers that turned out to be six months stale. The deal got pulled and you earned nothing.',
            ],
            'corp_acquisition' => [
                'title' => 'Due Dilligence',
                'success_message' => 'You spent the whole day doing due dilligence on a possible acquisition target, flagging discrepancies in their books and using it as leverage against them - You felt pretty good about the whole thing and managed to earn ${payout} in performance bonuses!',
                'failure_message' => 'You embarrased yourself pretty hard in the meetings with the target company, spouting out without a clue - no perfomance bonus today !',
            ],
            'corp_market_share' => [
                'title' => 'Run the Sales Floor',
                'success_message' => 'You closed the quarter at 140 to plan, two of your reps hit President\'s Club, and the regional VP took you to the steakhouse where the senior partners eat. Your performance bonus paid out ${payout}',
                'failure_message' => 'Two of your top three reps missed quota in the same week and your forecast came in 30 light on the Monday call — your VP didn\'t say anything, which was worse. No pay.',
            ],
            'corp_monopoly' => [
                'title' => 'Lock in some Sales',
                'success_message' => 'You walked your top suppliers into a tighter pricing tier, locked annual contracts twelve points cheaper than last year, and procurement\'s quarterly variance came in green for the first time in eighteen months. Your performance bonus paid out ${payout}',
                'failure_message' => 'Your lead supplier walked from the renegotiation — Per Se dinners with your competitor\'s procurement chief had been going on all month. Monday\'s spreadsheet is going to be ugly. No pay.',
            ],
            'corp_hostile' => [
                'title' => 'Force the Shareholder Vote',
                'success_message' => 'You spent half the day finalizing the bear hug you\'d been running on a competitor — watched their CEO glare at you across the conference table — and went home for lunch on the company jet. You take no salary, of course, but your shares appreciated and paid out ${payout} in dividends on the announcement',
                'failure_message' => 'The Target chairman had secretly lined up a poison pill for you - caught you off guard and ran you off like Gucci did Arnault - No pay',
            ],
            'corp_empire' => [
                'title' => 'Review your Subsidiaries',
                'success_message' => 'You pulled an underperforming subsidiary\'s CEO into the hot seat, walked him through your numbers, and watched him freeze in pure fear of you. Your equity tranche vested ${payout}',
                'failure_message' => 'The subsidiary CEO showed up to committee with his own numbers, a board ally and a cocky attitude you didn\'t know he had. You were the one who left the meeting looking small. No pay',
            ],
            'corp_conglomerate' => [
                'title' => 'Set the Agenda',
                'success_message' => 'You controlled the agenda — the proposal you wanted buried got tabled, the one you wanted passed sailed through on a voice vote. Your chairman\'s stipend cleared ${payout}',
                'failure_message' => 'A governor invoked Robert\'s Rules to force the proposal you wanted buried back onto the floor — your case cracked under close examination. You adjourned the meeting early. No pay',
            ],
            'corp_global' => [
                'title' => 'Dinner with the State',
                'success_message' => 'You had a quiet dinner with some Heads of State and three government departments adjusted their regulatory positions by the following Monday—  You protected your interests and the trust distributed ${payout} to you ',
                'failure_message' => 'The dinner ran late, two of the four people you needed never showed, and the conversation never got past pleasantries. Nothing concrete moved and you earned nothing.',
            ],
        ];

        foreach ($earns as $code => $fields) {
            DB::table('career_earns')
                ->where('code', $code)
                ->update($fields);
        }
    }

    public function down(): void
    {

        $earns = [


            'corp_hustle' => [
                'title' => 'Pitch to Angel Investors',
                'success_message' => 'You spent the entire day perfecting your pitch deck and presenting to angel investors. Your passion and vision convinced them to write a check for ${payout}!',
                'failure_message' => 'You spent hours in the waiting room of a VC firm only to get a 5-minute meeting where they passed on your idea. No funding today.',
            ],
            'corp_first_deal' => [
                'title' => 'Close Your First Major Client',
                'success_message' => 'After weeks of cold calls and follow-ups, you finally got the contract signed. Your first major enterprise client just wired ${payout} to your account!',
                'failure_message' => 'You made it to the final round of negotiations, but the client went with your competitor at the last minute. Back to the drawing board.',
            ],
            'corp_expand' => [
                'title' => 'Scale Operations',
                'success_message' => 'You successfully opened three new locations this quarter and streamlined your supply chain. The expansion generated ${payout} in new revenue!',
                'failure_message' => 'Your expansion into new markets was premature. High overhead costs and low demand forced you to close the new branches at a loss.',
            ],
            'corp_acquisition' => [
                'title' => 'Acquire Struggling Competitor',
                'success_message' => 'You identified a competitor hemorrhaging cash and swooped in with a lowball offer. The acquisition gave you their client list and assets worth ${payout}!',
                'failure_message' => 'Your acquisition target declared bankruptcy before you could close the deal. Their assets were sold off to creditors and you got nothing.',
            ],
            'corp_market_share' => [
                'title' => 'Launch Aggressive Marketing Campaign',
                'success_message' => 'Your marketing blitz across social media and traditional channels went viral. New customer signups skyrocketed and generated ${payout} in revenue!',
                'failure_message' => 'Your controversial marketing campaign backfired spectacularly. The PR disaster led to customer boycotts and you had to issue a public apology.',
            ],
            'corp_monopoly' => [
                'title' => 'Establish Market Monopoly',
                'success_message' => 'Through strategic pricing and exclusive contracts, you cornered your market segment. Competitors are struggling while you rake in ${payout}!',
                'failure_message' => 'Regulators launched an antitrust investigation into your business practices. Legal fees and fines drained your resources and forced you to scale back.',
            ],
            'corp_hostile' => [
                'title' => 'Execute Hostile Takeover',
                'success_message' => 'You bypassed the board and went directly to shareholders with a premium offer. After months of legal battles, you successfully acquired the company for ${payout} in net assets!',
                'failure_message' => 'The target company deployed a poison pill defense and found a white knight investor. Your takeover bid was rejected and you lost millions in advisory fees.',
            ],
            'corp_empire' => [
                'title' => 'Consolidate Subsidiary Holdings',
                'success_message' => 'You restructured your portfolio of companies, eliminated redundancies, and optimized operations across all subsidiaries. The efficiency gains generated ${payout}!',
                'failure_message' => 'Your restructuring plan caused chaos across the organization. Key executives resigned, employee morale plummeted, and productivity suffered across all subsidiaries.',
            ],
            'corp_conglomerate' => [
                'title' => 'Form Strategic Conglomerate',
                'success_message' => 'You orchestrated the merger of multiple companies under a unified holding structure. The combined entity now controls ${payout} in consolidated assets!',
                'failure_message' => 'Cultural clashes between the merging companies led to infighting and operational paralysis. The integration failed and board members are calling for your resignation.',
            ],
            'corp_global' => [
                'title' => 'Manage Family Trust Operations',
                'success_message' => 'Your diversified portfolio of companies, real estate, and investments performed exceptionally this quarter. The trust distributed ${payout} to beneficiaries!',
                'failure_message' => 'A major scandal involving one of your subsidiaries triggered investigations across the entire trust. Asset values plummeted and regulatory scrutiny intensified.',
            ],
        ];

        foreach ($earns as $code => $fields) {
            DB::table('career_earns')
                ->where('code', $code)
                ->update($fields);
        }
    }
};