<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_promotions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('career_id');
            $table->integer('target_rank');
            $table->text('scenario');
            $table->text('option_1');
            $table->text('option_2');
            $table->jsonb('consequences')->default('{}');
            $table->jsonb('rewards')->default('{}');
            $table->timestamps();

            $table->foreign('career_id')->references('id')->on('careers')->onDelete('cascade');
            $table->unique(['career_id', 'target_rank']);
        });

        $this->seed();
    }

    private function seed(): void
    {
        $careers = DB::table('careers')->pluck('id', 'code');

        $promotions = [
            [
                'career_code'  => 'customs',
                'target_rank'  => 2,
                'scenario'     => 'Two weeks before your promotion board, you catch a fellow officer waving a flagged passenger through without a secondary scan. The passenger\'s luggage matched a contraband profile and the colleague pocketed something small from the belt. He doesn\'t know you saw it. He has a sick wife. He has three years until his pension.',
                'option_1'     => 'You file an incident report before the shift ends. Whatever happens to him is on him — the checkpoint has to mean something.',
                'option_2'     => 'You pull him aside in the locker room and tell him exactly what you saw. One warning. He gets out of whatever he\'s in, quietly, on his own terms.',
                'consequences' => [
                    '1' => ' He was suspended pending investigation. Three weeks later he was dismissed. He didn\'t fight it. You heard through the airport grapevines that his wife left him shortly after. You told yourself you did the right thing.',
                    '2' => 'He showed up the next morning, hands clean. He retired quietly eighteen months later. At his sendoff he shook your hand and said nothing. You both knew what that meant.',
                ],
                'rewards' => [
                    '1' => ['intelligence' => 100],
                    '2' => ['luck' => 100],
                ],
            ],

            [
                'career_code'  => 'technician',
                'target_rank'  => 2,
                'scenario'     => 'A property owner hands you an envelope with your inspection fee and a little bonus before you\'ve even looked at the building. Inside the foundation you find a stress fracture that should ground the property immediately — but it\'s his family home and the repair cost would bankrupt him. He\'s watching you from the doorway.',
                'option_1'     => 'You certify it conditionally and give him a detailed report. He gets 30 days to address the fracture before your notation becomes permanent. Technically defensible. Probably fine.',
                'option_2'     => 'You flag it as a failed inspection and hand him the report on the spot. You\'ve seen what happens to inspectors who sign off on things they shouldn\'t.',
                'consequences' => [
                    '1' => 'He shook your hand on the way out. The envelope contained $10,000 in cash — you counted it in the car. He never filed the remediation report. Six months later a different inspector flagged the same fracture and condemned the building. Nobody came looking for you. You haven\'t gone back past that street.',
                    '2' => 'He couldn\'t afford the repair. He lost the property only a week later and you heard he also went bankrupt. You filed correctly, but that didn\'t make you sleep any better.',
                ],
                'rewards' => [
                    '1' => ['dirty_cash' => 10000],
                    '2' => ['intelligence' => 200],
                ],
            ],

            [
                'career_code'  => 'banking',
                'target_rank'  => 2,
                'scenario'     => 'A young woman applies for a business loan to open a restaurant. Her credit file is thin but her business plan is solid. Your risk model spits out a borderline reject. Your branch manager tells you the decision is yours — but also mentions she\'s the niece of a city councillor. He doesn\'t need to say anything else.',
                'option_1'     => 'You approve the loan on the merits and document your reasoning. If the councillor connection helped tip the borderline, it was incidental.',
                'option_2'     => 'You reject it and route the file to another branch. You\'re not making lending decisions based on who people know.',
                'consequences' => [
                    '1' => 'The councillor called your manager the next day. Nothing came of it professionally, but she is said to have considered you a great friend from now on. It pays to have great friends.',
                    '2' => 'The manager thanked you for your objectivity and then approved it himself through a different channel. The restaurant opened anyway. So much for tough choices.',
                ],
                'rewards' => [
                    '1' => ['intelligence' => 300],
                    '2' => ['defense' => 30],
                ],
            ],

            [
                'career_code'  => 'banking',
                'target_rank'  => 3,
                'scenario'     => 'A hedge fund manager you went to university with calls to discuss liquidity positions over dinner. Nothing in writing, nothing binding — just two old classmates catching up. His fund is positioned short on a corporate bond you happen to be sitting on. He mentions he\'s heard something. He doesn\'t say what.',
                'option_1'     => 'You end the conversation, log the call, and report it to compliance the next morning.',
                'option_2'     => 'You listen. You say nothing material, letting only glances and frowns do the talking. What he does with the body language is his business.',
                'consequences' => [
                    '1' => 'The compliance report generated a routine inquiry that went nowhere. Your name appeared in the file as the reporting officer — proof you did the right thing and nothing more. He hasn\'t called since.',
                    '2' => 'The fund moved its position three days later. Your bond held. You never spoke again. Nothing was ever proven. Nothing was ever asked.',
                ],
                'rewards' => [
                    '1' => ['intelligence' => 50],
                    '2' => ['luck' => 200],
                ],
            ],


            [
                'career_code'  => 'banking',
                'target_rank'  => 4,
                'scenario'     => 'The government announces it will mint new emergency currency to settle short-term debts, effectively diluting the value of bonds and cash. You were warned an hour ago by a senior official — off the record. Your treasury is holding significant reserves that will be hit hard if you do nothing.',
                'option_1'     => 'You liquidate holdings and move to hard assets before the announcement goes public. You take a short-term reputational hit but preserve value.',
                'option_2'     => 'You hold. This is a temporary measure and the market will stabilize. You trust the system and you won\'t act on a tip you can\'t document.',
                'consequences' => [
                    '1' => 'The markets stabilized faster than expected. You missed the partial recovery but avoided the worst of the devaluation. The board reviewed your decision as overly cautious. You kept your job and your opinion to yourself.',
                    '2' => 'The devaluation was deeper than anyone expected and lasted for months. You absorbed the loss on principle, knowing rebuilding the  trust of the public would take longer than rebuilding the treasury.',
                ],
                'rewards' => [
                    '1' => ['luck' => 350],
                    '2' => ['defense' => 100],
                ],
            ],

            
            [
                'career_code'  => 'healthcare',
                'target_rank'  => 2,
                'scenario'     => 'A defense attorney contacts you through a mutual acquaintance. His client is facing a serious assault charge. The prosecution\'s psychiatric evaluation found the defendant legally sane at the time of the offense. The defense needs a rebuttal — a credible physician willing to testify to a dissociative episode or acute psychotic break. The attorney slides a case file across the table and tells you the fee is $50,000, as payment for your services of course. You review the file. The diagnosis is possible. With the right framing.',
                'option_1'     => 'You decline. You will testify if subpoenaed, based solely on what you actually find — not on a conclusion you were paid to reach.',
                'option_2'     => 'You take the case. You find the angle, prepare the testimony, and take the stand.',
                'consequences' => [
                    '1' => 'The attorney sent a terse email thanking you for your time and never contacted you again. The defendant was convicted. Your license is intact. So is your ability to look at yourself in the mirror.',
                    '2' => 'The defendant walked. Your testimony was persuasive — it was designed to be. The $50,000 arrived across two deposits over the following five weeks, routed through a medical consulting firm the attorney\'s office controlled. The DA\'s office requested your full case history four months later. They found nothing worth making a fuss about.',
                ],
                'rewards' => [
                    '1' => ['defense' => 300],
                    '2' => ['dirty_cash' => 50000],
                ],
            ],

            
            [
                'career_code'  => 'healthcare',
                'target_rank'  => 3,
                'scenario'     => 'You are closing an abdominal patient when the instrument count comes back wrong. The scrub nurse counts twice. One scalpel blade is unaccounted for. The patient is fully sutured. The family is in the waiting room. You can call it now — order imaging, locate the blade, reopen, and file the incident report before the patient leaves recovery. Or you can sign off the count as reconciled, attribute the discrepancy to a counting error, and discharge with routine follow-up instructions.',
                'option_1'     => 'You call it immediately. You order the X-ray, locate the blade, and reopen. You file the incident report yourself before anyone asks.',
                'option_2'     => 'You sign the count as reconciled. You note a counting discrepancy in the chart and discharge the patient with instructions for follow-up imaging described as precautionary.',
                'consequences' => [
                    '1' => 'The patient underwent a second surgery the same afternoon. The blade was retrieved. You filed the report before the family was told anything else. Your attending privileges were suspended pending review for six weeks and then reinstated. The patient never sued. You still hear the instrument count in your head before every close.',
                    '2' => 'The patient was discharged. Three weeks later she returned to emergency with severe abdominal pain and a 104-degree fever. The blade appeared on imaging. By then it was not your OR anymore — but you were still the attending of record. The malpractice claim settled for millions. The hospital paid it. Your name remained in the file.',
                ],
                'rewards' => [
                    '1' => ['intelligence' => 200],
                    '2' => ['luck' => 350],
                ],
            ],

            
            [
                'career_code'  => 'healthcare',
                'target_rank'  => 4,
                'scenario'     => 'A new pathogen has emerged. Early data suggests it is highly contagious and lethal, but vaccine development is months away. You can implement a strict quarantine now — the economic damage will be severe. Or you can let the city function, reinforcing hospital capacity and hoping the outbreak remains manageable.',
                'option_1'     => 'You implement a city-wide lockdown immediately. The economy will suffer, but lives will be saved.',
                'option_2'     => 'You let the city operate normally while surging medical resources. The economy continues. Some people will die who wouldn\'t have otherwise.',
                'consequences' => [
                    '1' => 'The economic fallout lasted eight months. Unemployment peaked at 14%. The pathogen was contained in six weeks. The city council blamed you for the economic damage publicly and privately thanked you for saving their constituents.',
                    '2' => 'The hospitals held. Barely. The official death toll will almost certainly be contested for years. The economy ran. You told yourself the numbers would have been worse with a lockdown. Some mornings you believed it.',
                ],
                'rewards' => [
                    '1' => ['intelligence' => 500],
                    '2' => ['offense' => 200],
                ],
            ],

            
            [
                'career_code'  => 'law',
                'target_rank'  => 2,
                'scenario'     => 'Your client, charged with assault, tells you in a privileged conversation that he did it and plans to take the stand and lie. The evidence is thin enough that he might walk without perjury. You have an obligation to defend him. You do not have an obligation to assist a fraud.',
                'option_1'     => 'You tell him he will not testify. You will make the best argument available from the facts. He finds another way to win or he doesn\'t.',
                'option_2'     => 'You do not call him. But you do not stop him if he insists — you walk the line between zealous advocacy and complicity.',
                'consequences' => [
                    '1' => 'He didn\'t walk. But the conviction was on the facts, not a perjury trap. He fired you anyway and hired someone who let him testify. That attorney is still practicing. You sleep fine.',
                    '2' => 'He testified. He walked. The DA\'s office flagged your name for future cases. You told yourself it was the adversarial system working as designed. Technically that was true.',
                ],
                'rewards' => [
                    '1' => ['luck' => 200],
                    '2' => ['intelligence' => 200],
                ],
            ],

            
            [
                'career_code'  => 'law',
                'target_rank'  => 3,
                'scenario'     => 'A case lands on your bench involving a close friend from college. The evidence is serious — clear financial misconduct, nothing violent. You know his reputation, his family. He\'s a good person who made a terrible mistake. He\'s hoping you\'ll go easy on him given your background.The prosecutor is pushing hard for the maximum.',
                'option_1'     => 'You recuse yourself. The appearance of impropriety is too high a risk regardless of your ability to be fair.',
                'option_2'     => 'You stay. You trust yourself to be fair. Your oath is to the law, not to appearances.',
                'consequences' => [
                    '1' => 'The replacement judge sentenced him to three years. When you went to pay him a visit, he promised to do everything in his power to destroy your personally. You considered that a fair response given the circumstances.',
                    '2' => 'You handed down a fair sentence — lighter than the prosecution asked, heavier than the defense hoped. The bar association reviewed the case and found no misconduct. The friendship didn\'t survive the verdict despite your leniency.',
                ],
                'rewards' => [
                    '1' => ['intelligence' => 350],
                    '2' => ['defense' => 100],
                ],
            ],

            
            [
                'career_code'  => 'law',
                'target_rank'  => 4,
                'scenario'     => 'An emergency appeal lands on your desk. You review the trial record from a colleague\'s courtroom and find the conviction rests on witness testimony that is demonstrably coordinated — identical phrasing across three separate depositions, all prepared through the same intermediary. The judge who signed the verdict has twenty-two years on the bench and a reputation to match. The defendant has been remanded and has two weeks before sentencing.',
                'option_1'     => 'You overturn the case, knowing it will open an inquiry into a colleague you have to share a building with and increase some tensions.',
                'option_2'     => 'You note it in your own file and hold it, perhaps the evidence isn\'t really as strong as it seems to you. People make mistakes after all.',
                'consequences' => [
                    '1' => 'You overturned the case and your report of the colleague\'s deficient ruling was so thorough and damning it triggered an inquiry. Your colleague was placed under review. Three of his convictions from the past eighteen months were flagged for re-examination. The defendant was released pending retrial. You made an enemy of a man with twenty years of friends on the bench.',
                    '2' => 'The defendant was sentenced to seven years in prison. You told yourself he was probably quilty anyway, and it wouldn\'t make much of a difference to initiate an inquiry at the point . You knew it wasn\'t true though . You knew that.',
                ],
                'rewards' => [
                    '1' => ['intelligence' => 500],
                    '2' => ['defense' => 200],
                ],
            ],

           
            [
                'career_code'  => 'police',
                'target_rank'  => 2,
                'scenario'     => 'You\'ve been chasing a local wanna-be crime boss for months. An NSA contact slips you a tip — a location, a time, a name. It\'s solid. But you know they got it through unauthorized surveillance. There\'s no chance the chain of custody holds in court, but if you don\'t move on it now, it might be a while before you get another chance.',
                'option_1'     => 'You refuse to act on an untraceable tip. You let him go for now and find another way.',
                'option_2'     => 'You move and manage to disguise the origin of the tip through parallel construction, hoping the chain holds and nobody looks too deeply into it .',
                'consequences' => [
                    '1' => 'He was in the wind by the time a clean warrant cleared. The case went cold. You were told informally the tip had been good. You knew that. You\'d known that when you walked away.',
                    '2' => 'He was taken in for three counts thanks to your tip, unfortunately an insightful defense attorney saw through the nonexistent chain and the judge dismissed  not only the case but the mountains of evidence you had managed to collect. The fruit of the poisoned tree, as they call it.',
                ],
                'rewards' => [
                    '1' => ['intelligence' => 500],
                    '2' => ['intelligence' => 50],
                ],
            ],

            
            [
                'career_code'  => 'police',
                'target_rank'  => 3,
                'scenario'     => 'A protest is planned in a district where tensions are already high. Intelligence suggests a small faction within the larger peaceful group is planning a disruption. You can flood the area with uniformed and heavily armed officers — visible deterrence — or keep a smaller plainclothes presence and let it develop.',
                'option_1'     => 'You deploy visibly and heavily. The community board knows you\'re watching. It sends a message.',
                'option_2'     => 'You hold back. A large uniform presence in a charged crowd is as likely to escalate as to contain.',
                'consequences' => [
                    '1' => 'The protest passed without incident. The community liaison board filed a formal complaint about the deployment level. Both things were true at the same time.',
                    '2' => 'The disruption happened in the third hour — broken windows, one arrest, a couple officers roughed up, one officer in the hospital. The commissioner was pissed and accosted the mayor who let your department keep some kevlar for future problems. The community board cited it as proof the  plainclothes model works regardless',
                ],
                'rewards' => [
                    '1' => ['offense' => 250],
                    '2' => ['item_slug' => 'kevlar-armor'],
                ],
            ],

            
            [
                'career_code'  => 'police',
                'target_rank'  => 4,
                'scenario'     => 'The mayor requests a meeting. He wants to discuss the priorities of your department. He frames it as guidance. It is not guidance. A prominent donor has a case pending that has moved slowly. He doesn\'t say that directly but he doesn\'t need to.',
                'option_1'     => 'You end the meeting politely and have the conversation documented by your superintendent before you leave the building.',
                'option_2'     => 'You listen, say nothing committal, but hint that the case might be expedited if the mayor found a way to increase the department\'s budget.',
                'consequences' => [
                    '1' => 'The mayor never mentioned it again. The case moved at its own pace. Six months later a different donor\'s file crossed your desk and you made the same call. The documentation folder grew.',
                    '2' => 'The budget increase came through the following quarter — the largest in seven years. The case was quietly deprioritized. You told yourself you hadn\'t made a commitment. That was technically true.',
                ],
                'rewards' => [
                    '1' => ['defense' => 500],
                    '2' => ['intelligence' => 500],
                ],
            ],

            
            [
                'career_code'  => 'corporation',
                'target_rank'  => 2,
                'scenario'     => 'You find an error in a proposal your CEO is about to present to a major client — a pricing miscalculation that overstates margin by 15%. Correcting it in the room will embarrass him in front of a client he\'s been cultivating for six months. Staying quiet means the contract is won on false numbers.',
                'option_1'     => 'You pass him a note before he gets to the slide. He can decide what to do with it.',
                'option_2'     => 'You say nothing. The number is close enough. You will fix the contract language in drafting.',
                'consequences' => [
                    '1' => 'He caught it. He adjusted the slide without acknowledging you in the room. The client signed anyway. The CEO remembered. You were assigned to his next three major pitches.',
                    '2' => 'The client signed on the false margin. The contract revision was subtle enough that nobody pushed back. Eighteen months later when the numbers came in low the CEO blamed market conditions. You said nothing then either.',
                ],
                'rewards' => [
                    '1' => ['offense' => 500],
                    '2' => ['luck' => 100],
                ],
            ],

            [
                'career_code'  => 'corporation',
                'target_rank'  => 3,
                'scenario'     => 'A city official contacts you out of the blue and asks to meet. He hears your company has an opening for third party cybersecurity vendor for some overseas work and wants to introduce you to his cousin who runs a small IT firm. The cousin\'s pricing is competitive but not the strongest. The official makes clear that his goodwill comes with accepting the proposal, and that his department handles several licensing renewals your company relies on. He doesn\'t need to say the rest.',
                'option_1'     => 'You decline, award to the strongest bidder, and document the conversation with legal.',
                'option_2'     => 'You award it to the cousin\'s firm. The terms are defensible. The official\'s goodwill is worth more than the margin difference.',
                'consequences' => [
                    '1' => 'The official made your life difficult for six months — permit delays, a surprise audit, a licensing renewal that dragged four times longer than it should have.',
                    '2' => 'An envelope arrived at your office three days after the contract was signed. $100,000 in cash, no notes. The cousin\'s firm did an adequate job on the contract from what you heard from your remote overseas employees.',
                ],
                'rewards' => [
                    '1' => ['defense' => 300],
                    '2' => ['dirty_cash' => 100000],
                ],
            ],

           
            [
                'career_code'  => 'corporation',
                'target_rank'  => 4,
                'scenario'     => 'The company is building a new regional headquarters and you are leading the government relations push. The city is offering the standard commercial development package. Your CEO wants more — specifically a ten-year property tax abatement and a zoning variance on a parcel currently designated as community green space. The councillor who controls both has made it known, through an intermediary, that his re-election campaign fund is short.',
                'option_1'     => 'You negotiate within legal limits. Push hard on infrastructure commitments and a reduced permitting timeline. Accept the standard package. The green space stays.',
                'option_2'     => 'You route an above-limit contribution through a PAC the intermediary controls. The variance and abatement are approved at the next council session.',
                'consequences' => [
                    '1' => 'The standard package was signed. The headquarters opened on schedule. The CEO expressed quiet disappointment at the missed abatement. Three years later the company in the adjacent block — who had paid for the same treatment through less careful channels — was under federal investigation. You sent the CEO the press clipping.',
                    '2' => 'The variance cleared. The abatement was signed. The new headquarters deal  will save the company millions over the next few years  in tax breaks alone. The councillor was re-elected comfortably. The intermediary relocated shortly after. Nobody connected anything.',
                ],
                'rewards' => [
                    '1' => ['defense' => 1000],
                    '2' => ['offense' => 1000],
                ],
            ],

            [
                'career_code'  => 'corporation',
                'target_rank'  => 5,
                'scenario'     => 'A whistleblower inside one of your subsidiaries contacts you directly, bypassing the board. She has documented evidence that the CFO in that subsidiary has been systematically under-reporting environmental violations at three manufacturing plants — enough exposure to trigger regulatory action across multiple jurisdictions. She wants formal protection before going to the authorities.',
                'option_1'     => 'You meet with her, document everything, and bring it to the board with a full remediation plan. The CFO is terminated. Voluntary disclosure gets you a reduced settlement.',
                'option_2'     => 'You move her to a different department with a pay increase. Legal buries the violations in an internal review. The CFO stays. The problem disappears on paper.',
                'consequences' => [
                    '1' => 'The CFO\'s termination made the financial press. The environmental settlement cost $4.2 million. The voluntary disclosure kept three executives out of jail. The whistleblower was cited in a corporate ethics report two years later. She never thanked you personally.',
                    '2' => 'She accepted the transfer. The internal review concluded there were minor procedural gaps that had since been corrected. The CFO ran two more record quarters. The violations are still there. So is she.',
                ],
                'rewards' => [
                    '1' => ['luck' => 1000],
                    '2' => ['intelligence' => 750],
                ],
            ],

        
            [
                'career_code'  => 'corporation',
                'target_rank'  => 6,
                'scenario'     => 'One of your subsidiaries has been dealing with persistent labor protests and work stoppages in a remote region with no independent press and weak state enforcement. Your local management has been using contracted security to eliminate who they term as agitators. A journalist has been investigating for three months and is about to publish. Your local manager is asking you to authorize a team to retrieve her equipment and ensure the story never reaches print.',
                'option_1'     => 'You refuse. You fire the local manager immediately, increase wages at the facility, and issue a preemptive public statement on labor standards before the story runs.',
                'option_2'     => 'You give silent approval. The local manager is the named authority. Your name is nowhere on the directive.',
                'consequences' => [
                    '1' => 'The story ran anyway — but your statement ran first. The coverage was damaging but survivable. Two activist investor groups divested. The local manager sued for wrongful termination after you fired him and lost. Your name stayed clean.',
                    '2' => 'The journalist\'s equipment was recovered. The story was never published. The local manager spent fourteen months in a regional detention facility before quietly being released. You received a handwritten note from him afterward. You destroyed it unread.',
                ],
                'rewards' => [
                    '1' => ['intelligence' => 1000],
                    '2' => ['offense' => 750],
                ],
            ],

            [
                'career_code'  => 'corporation',
                'target_rank'  => 7,
                'scenario'     => 'Several regulators have expressed concern about one of your companies\'s tax structuring arrangements and are threatening to launch a full-scale investigation. The measures are aggressive but defensible. Your board is divided — some want to fight it publicly, others suggest a more personal approach to managing the outcome.',
                'option_1'     => 'You engage outside counsel, prepare full disclosure, and contest the investigation on its merits. Transparency is the only defensible position at this level.',
                'option_2'     => 'You arrange personal meetings with three of the regulators over the course of two weeks — a dinner here, a private event there, a sizeable donation to one of their favorite charities, a short flight on your gulfstream to a new art installation, and a few rounds of golf, hoping they would see things your way.',
                'consequences' => [
                    '1' => 'The investigation ran for six months. You won on six of eight counts. The two findings resulted in a negotiated settlement and minor structural changes. The press called it a draw. Your institutional investors called it a victory.',
                    '2' => 'No charges were filed. The tax arrangements remain in place. Two of the three regulators accepted paid advisory positions at group subsidiaries within eighteen months of the meetings. No one connected the sequence publicly.',
                ],
                'rewards' => [
                    '1' => ['offense' => 1000],
                    '2' => ['defense' => 1000],
                ],
            ],

        ];

        foreach ($promotions as $p) {
            $careerId = $careers[$p['career_code']] ?? null;
            if (!$careerId) continue;

            DB::table('career_promotions')->insert([
                'career_id'    => $careerId,
                'target_rank'  => $p['target_rank'],
                'scenario'     => $p['scenario'],
                'option_1'     => $p['option_1'],
                'option_2'     => $p['option_2'],
                'consequences' => json_encode($p['consequences']),
                'rewards'      => json_encode($p['rewards']),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('career_promotions');
    }
};