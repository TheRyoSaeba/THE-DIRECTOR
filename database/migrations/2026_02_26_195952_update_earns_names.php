<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration 
{
    public function up(): void
    {
        $earns = [
            
            'bank_teller' => [
                'success_message' => 'You processed over 200 customer transactions without a single complaint—the branch manager approved your bonus of ${payout}!',
                'failure_message' => 'A customer complained about the wait time and you were sent home early—no pay today.',
            ],
            'bank_accounts' => [
                'success_message' => 'You reconciled a month\'s worth of government accounts in one shift, and the senior accountant slipped you a bonus of ${payout}.',
                'failure_message' => 'You misposted a transaction and the discrepancy wasn\'t found until end of day—your pay was docked.',
            ],
            'bank_loans' => [
                'success_message' => 'You closed three commercial loans this week, bringing in ${payout} in fees for the bank.',
                'failure_message' => 'A loan you approved defaulted within 30 days—the underwriting committee is reviewing your file and you got no bonus.',
            ],
            'bank_reserves' => [
                'success_message' => 'Your liquidity forecast was accurate to within 0.1%, and the CFO noticed—you received a ${payout} bonus.',
                'failure_message' => 'You misallocated reserve funds and triggered a minor regulatory inquiry—your pay was frozen.',
            ],
            'bank_monetary' => [
                'success_message' => 'Your analysis of overnight rates saved the bank from a bad swap position, earning you a ${payout} bonus.',
                'failure_message' => 'Your recommendation led to a losing position in the bond market—you were pulled from the desk and earned nothing.',
            ],
            'bank_sovereign' => [
                'success_message' => 'You successfully restructured a sovereign debt tranche, netting the bank ${payout} in fees.',
                'failure_message' => 'A key detail in the debt covenants was overlooked—the deal fell apart and you earned nothing.',
            ],
            'bank_chairman' => [
                'success_message' => 'Your testimony before the banking committee defused a potential regulatory crisis—the board awarded you a ${payout} bonus.',
                'failure_message' => 'Your comments at the press conference were misconstrued and sparked a sell‑off—you were asked to resign and received no pay.',
            ],
            'health_rounds' => [
                'success_message' => 'You completed your patient rounds efficiently and earned a ${payout} bonus.',
                'failure_message' => 'You fell behind on charting—your pay was docked.',
            ],
            'health_emergency' => [
                'success_message' => 'You stabilized three critical patients in the ER today and earned a ${payout} bonus.',
                'failure_message' => 'A patient coded while you were on break—you were sent home without pay.',
            ],
            'health_specialty' => [
                'success_message' => 'Your consultation on a complex case led to a correct diagnosis and you earned a ${payout} bonus.',
                'failure_message' => 'You misdiagnosed a patient and the family is threatening to sue—no pay today.',
            ],
            'health_surgery' => [
                'success_message' => 'The surgery was a success and you earned a ${payout} bonus.',
                'failure_message' => 'You left a sponge in the patient—your pay was docked.',
            ],
            'health_trauma' => [
                'success_message' => 'You led the trauma team through a mass casualty event and earned a ${payout} bonus.',
                'failure_message' => 'You froze during a critical moment and were relieved of duty—no pay.',
            ],
            'health_research' => [
                'success_message' => 'Your research data contributed to a breakthrough and you earned a ${payout} bonus.',
                'failure_message' => 'Your trial data was compromised—the study was halted and you got nothing.',
            ],
            'health_director' => [
                'success_message' => 'Your strategic initiatives improved patient outcomes and you earned a ${payout} bonus.',
                'failure_message' => 'Your budget cuts led to a staffing crisis—the board asked for your resignation and you received no pay.',
            ],

            
            'law_notarize' => [
                'success_message' => 'You notarized dozens of documents without error and earned a ${payout} bonus.',
                'failure_message' => 'Your notary stamp was expired—every document today is invalid and you earned nothing.',
            ],
            'law_contract' => [
                'success_message' => 'You drafted a complex contract that satisfied all parties and earned a ${payout} bonus.',
                'failure_message' => 'You missed a critical clause and the deal fell apart—you earned nothing.',
            ],
            'law_civil' => [
                'success_message' => 'You won a civil case on behalf of your client and earned a ${payout} bonus.',
                'failure_message' => 'You failed to file a motion on time—the case was dismissed and you got no pay.',
            ],
            'law_criminal' => [
                'success_message' => 'Your legal strategy led to an acquittal and you earned a ${payout} bonus.',
                'failure_message' => 'You didn’t object to hearsay—the conviction stands and your client is furious—no pay.',
            ],
            'law_judge' => [
                'success_message' => 'Your ruling was upheld on appeal and you earned a ${payout} bonus.',
                'failure_message' => 'Your decision was overturned for procedural error—you earned nothing.',
            ],
            'law_appellate' => [
                'success_message' => 'Your appellate brief was so persuasive the court reversed the lower decision; you earned a ${payout} bonus.',
                'failure_message' => 'Your brief misstated the record—the appeal was denied and you got no pay.',
            ],
            'law_chief' => [
                'success_message' => 'You managed the court’s docket efficiently and earned a ${payout} bonus.',
                'failure_message' => 'A backlog of cases grew under your watch—the judicial council is unhappy and you received no pay.',
            ],

            
            'police_patrol' => [
                'success_message' => 'Your proactive patrols led to several arrests and you earned a ${payout} bonus.',
                'failure_message' => 'You missed a domestic disturbance call—the lieutenant sent you home without pay.',
            ],
            'police_traffic' => [
                'success_message' => 'You reduced accidents on a dangerous stretch of road and earned a ${payout} bonus.',
                'failure_message' => 'You spent the whole shift parked behind a billboard and wrote zero tickets—no pay.',
            ],
            'police_investigation' => [
                'success_message' => 'You solved a cold case and received a ${payout} bonus.',
                'failure_message' => 'You mishandled evidence and the case was thrown out—you got nothing.',
            ],
            'police_raid' => [
                'success_message' => 'The raid was successful and you seized evidence that led to multiple convictions; you earned a ${payout} bonus.',
                'failure_message' => 'The warrant was invalid—the raid was thrown out and you were suspended without pay.',
            ],
            'police_taskforce' => [
                'success_message' => 'Your work on the task force disrupted a major criminal operation and you earned a ${payout} bonus.',
                'failure_message' => 'A wiretap you authorized was ruled illegal—the whole operation collapsed and you earned nothing.',
            ],
            'police_corruption' => [
                'success_message' => 'Your investigation uncovered corruption within the department and you earned a ${payout} bonus.',
                'failure_message' => 'You accused the wrong officer—internal affairs is now investigating you and you got no pay.',
            ],
            'police_commissioner' => [
                'success_message' => 'Your policies led to a drop in crime rates and you earned a ${payout} bonus.',
                'failure_message' => 'Your reform efforts sparked a union revolt—the mayor asked for your resignation and you received no pay.',
            ],

            
            'corp_hustle' => [
                'title' => 'Work the Industry Circuit',
                'success_message' => 'Three conferences, two dinners, and one very long taxi ride later—the right conversation happened at the right moment and you earned yourself a ${payout} bonus!',
                'failure_message' => 'You spent the week at every event on the calendar and handed out enough business cards to wallpaper a conference room—nobody called and you earned nothing.',
            ],
            'corp_first_deal' => [
                'title' => 'Close the First Contract',
                'success_message' => 'Months of calls, follow-ups, and revised terms later—the contract finally landed and you earned yourself a ${payout} bonus!',
                'failure_message' => 'The deal was as good as done, then their side went quiet for a week and came back with a single line—they decided to go in a different direction and you earned nothing.',
            ],
            'corp_expand' => [
                'title' => 'Open the Regional Office',
                'success_message' => 'The new office is operational three weeks ahead of schedule—lease signed, local team hired, first clients already through the door and you earned yourself a ${payout} bonus!',
                'failure_message' => 'The permit office sat on the application for six weeks—by the time it cleared the landlord had leased the space to someone else and you earned nothing.',
            ],
            'corp_acquisition' => [
                'title' => 'Run Due Diligence on an Acquisition Target',
                'success_message' => 'A close read of the target\'s books surfaced a liability their side hoped nobody would find—it became the lever at the table and you earned yourself a ${payout} bonus!',
                'failure_message' => 'A contingent liability buried in a footnote on page 94 was missed—legal flagged it post-signing and you earned nothing.',
            ],
            'corp_market_share' => [
                'title' => 'Dominate the Division Sales Target',
                'success_message' => 'Three exclusive distribution agreements locked in, 140% of quarterly quota hit, and your name mentioned in the executive briefing without prompting—you earned yourself a ${payout} bonus!',
                'failure_message' => 'A competitor quietly undercut your pricing on the three accounts you had been cultivating for months—you lost all of them in the same week and you earned nothing.',
            ],
            'corp_monopoly' => [
                'title' => 'Lock Down the Supply Chain',
                'success_message' => 'Exclusive supplier contracts cut the main competitor\'s material access off at the source—their margins collapsed within two quarters and you earned yourself a ${payout} bonus!',
                'failure_message' => 'The Korea Fair Trade Commission opened a preliminary inquiry into the supplier agreements—legal froze everything and you earned nothing.',
            ],
            'corp_hostile' => [
                'title' => 'Force the Shareholder Vote',
                'success_message' => 'A blocking stake accumulated quietly through nominee accounts—the shareholder vote was called before the incumbent board could organize a defense and you earned yourself a ${payout} bonus!',
                'failure_message' => 'The target\'s chairman made three phone calls—by morning institutional investors had formed a defensive bloc and you earned nothing.',
            ],
            'corp_empire' => [
                'title' => 'Restructure the Group Portfolio',
                'success_message' => 'Four redundant subsidiaries eliminated, procurement centralized across the group, syndicated debt renegotiated at better terms—you earned yourself a ${payout} bonus!',
                'failure_message' => 'Two subsidiary CEOs resigned rather than accept the new reporting structure—without their operational knowledge the integration has stalled and you earned nothing.',
            ],
            'corp_conglomerate' => [
                'title' => 'Move the Group into a New Sector',
                'success_message' => 'An undervalued industrial sector identified and group capital deployed ahead of the cycle—the new vertical is generating revenue and you earned yourself a ${payout} bonus!',
                'failure_message' => 'The timing was wrong—a regulatory overhaul landed three weeks after the acquisition closed and you earned nothing.',
            ],
            'corp_global' => [
                'title' => 'Direct the Dynasty\'s Interests',
                'success_message' => 'A quiet dinner with the right people and three departments adjusted their regulatory positions by the following Monday—the dynasty\'s interests are protected and you earned yourself a ${payout} bonus!',
                'failure_message' => 'A senior aide inside the group leaked internal correspondence to a financial journalist—the resulting exposé triggered parliamentary hearings and you earned nothing.',
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
            
            'bank_teller' => [
                'success_message' => 'You served citizens efficiently and earned ${payout}.',
                'failure_message' => 'Transaction errors delayed public services.',
            ],
            'bank_accounts' => [
                'success_message' => 'Public funds were managed properly. You earned ${payout}.',
                'failure_message' => 'Accounting discrepancies triggered an audit.',
            ],
            'bank_loans' => [
                'success_message' => 'Development projects received funding. You earned ${payout}.',
                'failure_message' => 'Multiple loans defaulted on state projects.',
            ],
            'bank_reserves' => [
                'success_message' => 'Reserve ratios remained stable. You earned ${payout}.',
                'failure_message' => 'Currency fluctuations destabilized reserves.',
            ],
            'bank_monetary' => [
                'success_message' => 'Economic indicators improved. You earned ${payout}.',
                'failure_message' => 'Inflation concerns arose from policy decisions.',
            ],
            'bank_sovereign' => [
                'success_message' => 'Debt auctions were successful. You earned ${payout}.',
                'failure_message' => 'Bond yields spiked. International confidence wavered.',
            ],
            'bank_chairman' => [
                'success_message' => 'You managed to keep the banking system and interests rates reasonable today and earned  ${payout}.',
                'failure_message' => 'You were found to have personally approved a disastrous banking policy and faced a lot of shame !',
            ],

            
            'health_rounds' => [
                'success_message' => 'You treated patients successfully and earned ${payout}.',
                'failure_message' => 'Patient outcomes were poor during your shift.',
            ],
            'health_emergency' => [
                'success_message' => 'You stabilized critical patients earning ${payout}.',
                'failure_message' => 'The ER was overwhelmed. Treatment delays occurred.',
            ],
            'health_specialty' => [
                'success_message' => 'Your diagnoses improved patient care. Earned ${payout}.',
                'failure_message' => 'Misdiagnoses led to patient complaints.',
            ],
            'health_surgery' => [
                'success_message' => 'All surgeries succeeded. You earned ${payout}.',
                'failure_message' => 'Surgical complications arose during procedures.',
            ],
            'health_trauma' => [
                'success_message' => 'Your team saved critical patients and earned ${payout} for the day.',
                'failure_message' => 'Trauma cases overwhelmed your team.',
            ],
            'health_research' => [
                'success_message' => 'Research trials showed promise earning you ${payout} in grants.',
                'failure_message' => 'The clinical trial failed safety protocols.',
            ],
            'health_director' => [
                'success_message' => 'Public health outcomes improved. You earned ${payout}.',
                'failure_message' => 'Healthcare access declined under your administration.',
            ],

            
            'law_notarize' => [
                'success_message' => 'You notarized documents and earned ${payout}.',
                'failure_message' => 'Your Document contained errors and was rejected!',
            ],
            'law_contract' => [
                'success_message' => 'Your contracts were executed successfully. You earned ${payout}.',
                'failure_message' => 'Your Contract dispute fell apart from ambiguous language!',
            ],
            'law_civil' => [
                'success_message' => 'You won the case and earned ${payout} in fees.',
                'failure_message' => 'You lost the case. Your client is seeking new counsel.',
            ],
            'law_criminal' => [
                'success_message' => 'You managed to defend some poor soul from the fingers of the law and earned  ${payout}.',
                'failure_message' => 'Your client was convicted and had little to pay anyways so you recieved nothing !',
            ],
            'law_judge' => [
                'success_message' => 'Your rulings were upheld on appeal. You earned ${payout}.',
                'failure_message' => 'Your decisions were overturned by higher courts.',
            ],
            'law_appellate' => [
                'success_message' => 'Your judgment set legal precedent. You earned ${payout}.',
                'failure_message' => 'The appeal was granted. Your ruling was reversed.',
            ],
            'law_chief' => [
                'success_message' => 'The Supreme Court operated with integrity. You earned ${payout}.',
                'failure_message' => 'Judicial gridlock plagued the court under your tenure.',
            ],

            
            'police_patrol' => [
                'success_message' => 'You spent all day patroling the beat and mean mugging strangers and felt pretty good since it made you ${payout}.',
                'failure_message' => 'You got handsy with a belligerent teenager and had to leave your shift without pay',
            ],
            'police_traffic' => [
                'success_message' => 'You managed to pull over and cite a couple ne\'er do wells and earned ${payout}.',
                'failure_message' => 'You sat around in the bushes all day waiting for a violater and didn\'t find a single one !',
            ],
            'police_investigation' => [
                'success_message' => 'You closed the case and earned ${payout}.',
                'failure_message' => 'The investigation went cold with no leads.',
            ],
            'police_raid' => [
                'success_message' => 'The raid was successful and you earned ${payout}.',
                'failure_message' => 'Suspects escaped during the raid. No arrests made.',
            ],
            'police_taskforce' => [
                'success_message' => 'You managed to RICO several notorious street gangs and earned yourself ${payout}.',
                'failure_message' => 'Your attempted operation failed due to bad warrants and  a terrible chain of command ',
            ],
            'police_corruption' => [
                'success_message' => 'You exposed corruption within the force. Earned ${payout}.',
                'failure_message' => 'A couple of fellow operators who considered  you a traitor intimidated you and left the precient without pay !',
            ],
            'police_commissioner' => [
                'success_message' => 'Your leadership managed to maintain some  public order. You earned ${payout}.',
                'failure_message' => 'Crime rates increased under your tenure.',
            ],

            
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