<?php

namespace Database\Seeders;

use App\Models\WikiCategory;
use App\Models\WikiPage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;


class WikiContentSeeder extends Seeder
{
    public function run(): void
    {
        // Wipe the public categories + their pages, but PRESERVE the _meta
        // category and the editable landing page so admin edits to the hero
        // survive re-seeds. Wiki content for the public categories is small;
        // a full re-seed is fine while we're scaffolding.
        DB::table('wiki_pages')
            ->whereNotIn('category_id', function ($q) {
                $q->select('id')->from('wiki_categories')->where('slug', '_meta');
            })
            ->delete();
        DB::table('wiki_categories')->where('slug', '!=', '_meta')->delete();

        $cats = $this->seedCategories();
        $this->seedLanding(); // updateOrCreate — seeder is authoritative
        $this->seedPageStubs($cats);
        $this->seedRealContent($cats); // overwrites specific stubs with real pages
    }

    /**
     * Real page content, dictated page-by-page. Each entry uses
     * updateOrCreate keyed on (category_id, slug) so it overwrites the
     * matching stub that was just seeded. Add entries here as the user
     * dictates them.
     */
    private function seedRealContent(array $cats): void
    {
        // ── Careers / Corporation ──────────────────────────────────────
        if (isset($cats['careers'])) {
            WikiPage::updateOrCreate(
                ['category_id' => $cats['careers']->id, 'slug' => 'corporation'],
                [
                    'title'        => 'Corporation',
                    'lede'         => 'See Properties & Medicine',
                    'body_markdown' => <<<'MD'
Corporation is the longest, most complicated and toughest career in The Director largely based on the amount of politics involved in working within and managing a corporation. This won't be easy but if you can tough it through and conjure up a bit of luck, you might just make it to the top kid!

From rank 1-3 you can either choose to join an existing corporation or stay as an unnamed corporation member, but in order to reach rank 4 you must either start your own company (from your boardroom page) or already be in one and have the position transferred to you.

**Phases of a corporation**

- Phase 1 (This type denotes a single operating company)
- Phase 2 (This type denotes a city-level Holding company created by a merger)
- Phase 3 (This denotes a trust created only when the last existing holding company in the game votes on a new Director of the Board)

**Corporation Positions**

- Member
- Vice President
- CTO/CFO

## PHASE 1

Phase one of a corporation is an operating company, either solo or as a subsidiary of a larger holding company. In the unfortunate incident that the managing director is killed, the position is transferred to the next person who is rank 3 and eligible to the promotion, if there are none, the entire corporation is dismantled.

## Staff

![Staff](https://images.thedirector.app/Careers/staff.png)

As a simple office drone, you diligently do your works and wait for the day where you can actually matter.

## Senior Staff

![SS](https://images.thedirector.app/Careers/seniorstaff.png)

You unlock better works and if you're in a corporation are eligible to join your boss in committing investment fraud actions.

## Department Head

![DH](https://images.thedirector.app/Careers/departmenthead.png)

Department Head is the furthest you can go as a Corporation member without a named corporation, if you're in a corporation though, you're eligible to be put in a position by your ceo (Vice president, C-SUITE), this position might have you managing other corporation members of your own!

## Managing Director (CEO)

![MD](https://images.thedirector.app/Careers/managingdirector.png)

You made it. As a managing director, you're the CEO of your own company, whether you started it yourself, it got transferred to you by the ceo,, it doesn't matter. As the managing director, you have a lot of responsibility, you must manage your corporation headquarters and any other properties that you own and generate profit from. You have to also keep up with the daily upkeep costs of your properties if you want to avoid penalities. You can also decide who deserves which positions, who deserves to be demoted and who deserves to be removed. It's also the farthest you can go in phase 1.

## PHASE 2

In order to become a Group president, you must merge with another company headquartered in the same city as yours, both Directors must be ready for rank 6 and have subordinates ready for rank 4 and there must not be another holding company already headquartered in that city. A CEO can then send a merger request and if accepted, a new holding company is formed with their former companies as subsidiaries and   their chosen subordinates as the CEO of each subsidiary. As a holding company, there are a max of 4 subsidiaries and 5 board seats. In the unfortunate situation a subsidiary is dismantled, a board seat is lost, but not retroactively, however if all subsidiaries are dismantled, the holding company is as well. On the other hand, if all board members of a holding company are killed, the holding company is dismantled and all subsidiaries are considered sole operating companies.

## Group President

![GP](https://images.thedirector.app/Careers/president.png)

As a group president, you are extremely valuable, mayors look up to you and your subordinates are jealous of your position. You occupy a position very few can. As a Group president, you can kick out a subsidiary, invite a company to become one, or promote a CEO under you to a board member seat if you've taken a liking to them. Your company's finances are also intertwined with all your subsidiaries giving you a larger pool of capital funds to play around with.

## Chairman

![CM](https://images.thedirector.app/Careers/chairman.png)

The chairman has all the same power as the group president, the only difference are better works and a shiny new name. Keep basking in the power.

## PHASE 3

The Director of the board is a singleton position. It can only be bestowed on you when the last holding company in the game meets the rest of the eligibility requirements, which means there must be at least 2 active subsidiaries, 3 members of the board, and all must be rank 6 chairman ready for rank 7 and must have an active Headquarters. When those conditions are met, the board members vote for their director of the board. Conceptually the DOTB is distinguished from chairman since the director of the board is also the sole beneficiary of the trust that owns the holding company.

## Director of the Board

![DOTB](https://images.thedirector.app/Careers/DOTB.png)

The only position of its kind anywhere. As the DOTB you preside over the board members who preside over a powerful holding company. The view at the top is exhilarating.
MD,
                    'sort_order'   => 50,
                    'published_at' => now(),
                ]
            );
        }

        // ── Cities / Properties ────────────────────────────────────────
        // Two groups (Residential / Corporate Properties) split via bold-line
        // dividers — each renders as its own row of section tiles below.
        if (isset($cats['cities'])) {
            WikiPage::updateOrCreate(
                ['category_id' => $cats['cities']->id, 'slug' => 'properties'],
                [
                    'title'        => 'Properties',
                    'lede'         => null,
                    'body_markdown' => <<<'MD'
Ah so you've decided to build a home then? Great choice. Alongside their storage and vehicle capacities, I've also been told these homes can protect you in times of danger. If you believe those stories it might be worth spending a little on one of these but keep in mind they're not cheap — but you already knew that!

**Residential**

## Rowhouse
![Rowhouse](https://images.thedirector.app/properties/rowhome.png)

A terrible, shoddily built home in the worst part of town, but hey it's better than nothing though, right?

## Apartment
![Apartment](https://images.thedirector.app/properties/Apartment.png)

A decent place to live, you still have to argue with the landlord every few weeks and the shower can never seem to get the right temperature, but it's got a real lived-in feeling to it.

## Estate
![Estate](https://images.thedirector.app/properties/estate.png)

A real home, and all it took was your time and soul. The tennis court is wonderful and the garden never seems to stop blooming but you've gotten used to it all — heck you've yelled at your maids for getting the wrong petunias so much your voice has gone sore. Oh well — hedonistic treadmill.

## Skyscraper Home
![SHDirector](https://images.thedirector.app/properties/skyscraper.png)

Awe-inspiring views overlooking a stunning city. What's the point of money if you can't enjoy it?

## Private Island
![PI](https://images.thedirector.app/properties/privateisland.png)

For the price you dropped, anything less than complete opulence would be an insult. Private islands for wealthy men like you have gotten a bad rap lately, but that hasn't stopped you. After all, if you had let the opinion of the common proletariat affect you, you wouldn't have gotten this far!

**Corporate Properties**

## Clemens Street HQ
![Clemens](https://images.thedirector.app/properties/hq1.jpg)

Your starter corporate office, good enough for the work you'll do, and it's what you could afford given your meager net profits.

## Baruch Avenue HQ
![Baruch](https://images.thedirector.app/properties/hq3.jpg)

A respectable office space sitting above a small outlet, you managed to lease the first 5 floors.

## Seung-gi Park World Headquarters
![SeungGi](https://images.thedirector.app/properties/hq2.jpg)

A proper Corporate Headquarters, given how much profit your company brings in every quarter — it's only fair you have a symbolic show of your strength.

## PanamCo Offshore Trust
![PanamCo](https://images.thedirector.app/properties/laundering.png)

Your offshore trust company, necessary for certain very large transactions.

## Turing Pharmaceuticals
![Turing](https://images.thedirector.app/properties/medical1.jpg)

Don't let the name spook you — you'll need this company if you want to start producing some less than approved medicines.
MD,
                    'sort_order'   => 30,
                    'published_at' => now(),
                ]
            );
        }

        // ── Cities / Services ──────────────────────────────────────────
        // Bold-line groups preserved so the page shows All Cities at the
        // top, then per-city exclusive sections. Controller routes this
        // page's slug ('cities/services') to the 'stack' grouped layout
        // (full-width image-rail rows under each group header) — bodies
        // are sparse for All Cities and richer for exclusives, so the
        // tile grid feels half-empty. Stack handles both cleanly.
        if (isset($cats['cities'])) {
            WikiPage::updateOrCreate(
                ['category_id' => $cats['cities']->id, 'slug' => 'services'],
                [
                    'title'        => 'Services',
                    'lede'         => null,
                    'body_markdown' => <<<'MD'
**All Cities**

## Bank
![Bank](https://images.thedirector.app/businesses/tokyobank.webp)

## Police
![Police](https://images.thedirector.app/businesses/tokyopolice.jpg)

## Hospital
![Hospital](https://images.thedirector.app/businesses/tokyohospital.jpg)

## University
![University](https://images.thedirector.app/businesses/seoulnational.jpg)

## City Hall
![CityHall](https://images.thedirector.app/businesses/nycityhall.webp)

## Transit Hub
![Transit](https://images.thedirector.app/businesses/nyctransithub.jpg)

**Tokyo Only**

## Plaza Ginza
![Plaza](https://images.thedirector.app/businesses/ginzaplaza.jpg)

## Golden Dragon Pachinko
![GoldenDragon](https://images.thedirector.app/businesses/pachinko.jpg)

**Seoul Only**

## Yongsan Black Market
![Yongsan](https://images.thedirector.app/businesses/seoulblackmarket.png)

## Gojeon's Vehicle Dealership
![Gojeon](https://images.thedirector.app/businesses/dealership.jpg)

**New York Only**

## NY Pharmacy
![NYPharmacy](https://images.thedirector.app/businesses/nypharmacy.jpg)

## Bullets and Mullets
![BulletsMullets](https://images.thedirector.app/businesses/bullets-mullets.png)
MD,
                    'sort_order'   => 10,
                    'published_at' => now(),
                ]
            );

            // ── Cities / Travel ────────────────────────────────────────
            // Plain ProseLayout — intro + 2 imageless H2 sections
            // (CUSTOMS ACADEMY, CONTRABAND). No images, no groups.
            WikiPage::updateOrCreate(
                ['category_id' => $cats['cities']->id, 'slug' => 'travel'],
                [
                    'title'        => 'Travel',
                    'lede'         => 'see Services & Customs',
                    'body_markdown' => <<<'MD'
The transit hub is how you can get around cities, you can choose to either take a commercial flight or use your vehicle which can help reduce your travel timer.

## CUSTOMS ACADEMY

Training in the customs academy from the transit hub page allows you to become a customs agent, once training is complete you can start your new career.

## CONTRABAND

Be careful about traveling between cities with items like corporate medicines, bombs or illegal cash on you. A Customs agent may be able to search and seize them within 15 minutes of your arrival.
MD,
                    'sort_order'   => 20,
                    'published_at' => now(),
                ]
            );
        }

        // ── Reference / All Actions ────────────────────────────────────
        // Two groups: General / Career-Specific. No images — short labels
        // with a sentence each. Uses the same group-row layout.
        if (isset($cats['reference'])) {
            WikiPage::updateOrCreate(
                ['category_id' => $cats['reference']->id, 'slug' => 'all-actions'],
                [
                    'title'        => 'All Actions',
                    'lede'         => null,
                    'body_markdown' => <<<'MD'
**General**

## Crypto Rug Pull

 

## Extortion
 

## Kidnapping

 

## Launder

 

## Banker Launder

A general action that requires a banker contact.

## Community Service

 

## Medicine Production

A general action, but requires a medical degree.

## Plant Bomb

 

**Career-Specific**

## Arrest

Police-only action.

## Corporate Audit

Police-only action.

## NGRI

Healthcare-only action.

## Medicine Sale

Named Corporation only.

## Investment Fraud

named Corporation only.
MD,
                    'sort_order'   => 10,
                    'published_at' => now(),
                ]
            );
        }

        // ── Reference / Talents ────────────────────────────────────────
        // Intro paragraph + a flat list of talent names. No images yet, no
        // bold-line groups — renders as plain prose / list under the H2.
        if (isset($cats['reference'])) {
            WikiPage::updateOrCreate(
                ['category_id' => $cats['reference']->id, 'slug' => 'talents'],
                [
                    'title'        => 'Talents',
                    'lede'         => null,
                    'body_markdown' => <<<'MD'
Talents are special abilities that give your character unique advantages in combat, career and more. Talents unlock automatically once you pass certain milestones and stay on your character for the duration of its existence.

- Over Educated
- Defense in Depth
- The Goal of all life is Death
- The Lazarus Connection
MD,
                    'sort_order'   => 30,
                    'published_at' => now(),
                ]
            );
        }

        // ── Careers / Police ───────────────────────────────────────────
        // Intro prose + 4 rank sections with image rails (GuideLayout).
        if (isset($cats['careers'])) {
            WikiPage::updateOrCreate(
                ['category_id' => $cats['careers']->id, 'slug' => 'police'],
                [
                    'title'        => 'Police',
                    'lede'         => null,
                    'body_markdown' => <<<'MD'
As a police officer, you have the power to investigate crimes, arrest people, and on occasion assist the mayor.

## Crime Dispatch

Crime dispatch updates every 15 minutes, displaying all the crimes that occurred within that time in the city.

## Solving a Case

When solving a case, take the time to do all 3 investigative choices — they can be helpful in piecing together the clues. You also have access to field intel if one of the clues managed to get an item off the person. If you're lucky, crime dispatch may have also gotten a partial of the name if it was committed within the past 15 minutes. After you have a suspect (or suspects), you can refer the case to the prosecution.

## What happens once I refer a case?

The case lands in the prosecution queue. An attorney picks it up and decides whether to file charges. If no attorney acts within the idle window, the system auto-charges and sends the case to a District Judge.

## How do I quit the Police career?

Ranks other than the commissioner can leave from the police settings page. Quitting via settings → life is not recommended since it incurs a penalty. Commissioners cannot step down via the police page without having a superintendent at 100% ready. They can step down via settings → life, but that incurs a penalty.

## Sergeant
![Sergeant](https://images.thedirector.app/Careers/sergeant.png)

As a lowly sergeant, you can do all your works and even investigate cases, although at your rank you can only handle simple misdemeanors.

## Inspector
![Inspector](https://images.thedirector.app/Careers/inspector.png)

Becoming an Inspector means you can now handle higher cases all the way up to Capital.

## Superintendent
![Superintendent](https://images.thedirector.app/Careers/superintendent.png)

The Superintendent has all the same powers as the Inspector, but is a rank higher with better works and a new name.

## Commissioner-General
![Commissioner](https://images.thedirector.app/Careers/commissioner.png)

You're now the Commissioner. You oversee the entire city's police force, can fire incompetent officers, and even have the chance to buy the HQ to collect enrollment fees.

## ARRESTS

Once a case has been sentenced by a judge for over an hour and no appeal is filed, a police officer can arrest the sentenced criminal as long as they're in the same city. Crucially this means, a police officer can arrest a criminal in any city as long as the warrant was issued from their home city.
MD,
                    'sort_order'   => 10,
                    'published_at' => now(),
                ]
            );

            // ── Careers / Customs ──────────────────────────────────────
            // Intro prose + 2 rank sections with image rails (GuideLayout).
            WikiPage::updateOrCreate(
                ['category_id' => $cats['careers']->id, 'slug' => 'customs'],
                [
                    'title'        => 'Customs',
                    'lede'         => null,
                    'body_markdown' => <<<'MD'
Customs is one of the starting careers in the Director. As a customs agent, you have the ability to search incoming travelers for illegal cash and items, seize contraband, approve corporation relocation requests, and view the travel logs of your city.

## Customs Agent

![Agent](https://images.thedirector.app/Careers/CustomsAgent.png)

As a customs agent, you have the ability to search incoming travelers and view your city's travel logs. Nothing too complicated, but it's an easygoing position all the same.

## Customs Supervisor

![Supervisor](https://images.thedirector.app/Careers/CustomsSupervisor.png)

Customs Supervisors have all the same powers as agent, except with improved works and the chance for their searches to now turn up contraband they can seize. They can also approve corporation relocation requests.
MD,
                    'sort_order'   => 80,
                    'published_at' => now(),
                ]
            );

            // ── Careers / Banking ──────────────────────────────────────
            // Intro prose + 4 rank sections + 2 trailing prose sections
            // (LAUNDERING, Derivatives trading) with image rails on the
            // ranks only. GuideLayout — rank sections have images, the
            // trailing two are imageless full-width prose per the new
            // per-section layout in show.blade.php.
            WikiPage::updateOrCreate(
                ['category_id' => $cats['careers']->id, 'slug' => 'banking'],
                [
                    'title'        => 'Banking',
                    'lede'         => 'See Bank & Certificates. See Laundering',
                    'body_markdown' => <<<'MD'
Banking is one of the most potentially lucrative careers in the game, Although the bank you're a part of is a central bank acting as the fiscal agent of the state, you can still become quite wealthy off it.

## Associate

![AS](https://images.thedirector.app/Careers/Associate.png)

As an associate of the bank, you don't have access to much just your works and your title, keep grinding and someday you'll get there.

## Loan Officer

![LO](https://images.thedirector.app/Careers/loanofficer.png)

At loan officer, you're finally able to start doing some under the table laundering for your client and getting paid for it. You're also able to engage in derivatives trading, it's important to understand how they work and study each chart's idiosyncrasies carefully otherwise you'll blow up your own bank!

## Vice President

![VP](https://images.thedirector.app/Careers/vicepresident.png)

Vice presidents have all the same power that loan officers do, except with better works that reflect the more stately nature of the work.

## State Bank manager

![SBM](https://images.thedirector.app/Careers/statebankmanager.png)

At state bank manager, you preside over the entire bank, can fire other bankers and can even view the transaction logs and bank accounts of every resident in your city in the ledger option from the city bank page. You can also set how much your employees have to play with at a time in the market and the stake each banker can receive.

## LAUNDERING

Laundering as a banker is easy - Simply add a client, wait for them to transfer the money then launder the cash - you have a much higher chance of success - especially with a high strength bar and can even set the cut you want.

## Derivatives trading

Trading is hard. It helps to understand how puts/calls work and how each ticker works before you start trading in order to give yourself the best chance of success, be careful though - if you lose too much of the bank's money, you might find yourself out on the street when the owner forces the manager to remove you!
MD,
                    'sort_order'   => 30,
                    'published_at' => now(),
                ]
            );

            // ── Careers / Healthcare ───────────────────────────────────
            // Intro prose + 4 rank sections with image rails (GuideLayout),
            // then 2 trailing prose sections (NGRI, REVIVAL). Content
            // verbatim from the user.
            WikiPage::updateOrCreate(
                ['category_id' => $cats['careers']->id, 'slug' => 'healthcare'],
                [
                    'title'        => 'Healthcare',
                    'lede'         => 'see All actions, talents & Medicine',
                    'body_markdown' => <<<'MD'
Healthcare is one of the most important careers in the game, when you're in one you'll have the ability to perform surgeries, heal players, close cases and even revive someone!

## Resident

![Doctor](https://images.thedirector.app/Careers/resident.png)

As a fresh faced healthcare worker doing your residency, you won't be able to do much since you're still supervised, but just keep doing your works and you'll get there eventually.

## Attending Physician

![Doctor](https://images.thedirector.app/Careers/physician.png)

Attending physicians can now heal players who apply for emergency medical care, and are now eligible for attempting NGRI actions.

## Surgeon Specialist

![Surgeon](https://images.thedirector.app/Careers/surgeonspecialist.png)

Surgeon Specialists can do all the things physicians can, but are capable of performing gender surgeries and any other future surgeries.

## Surgeon General

![Hospital Director](https://images.thedirector.app/Careers/surgeongeneral.png)

The surgeon general has all the same privileges, but can now oversee the hospital and let employees go as well.

## NOT GUILTY BY REASON OF INSANITY

NGRI is available as an action after becoming an attending physician, this allows you to attempt to get any sentenced person in the city off a crime as long as the crime involves jail time, keep in mind the difficulty of convincing the court with your rhetoric scales with how serious the offense was.

## REVIVAL

Hospital players who have the Lazarus connection talent will be able to attempt a revive on any player who has died within the last 12 hours and is online in the same city, as long that player has not yet received a revival attempt, keep in mind however, that the cooldown for revival on the talent timer is 12 hours.
MD,
                    'sort_order'   => 40,
                    'published_at' => now(),
                ]
            );

            // ── Careers / Law ──────────────────────────────────────────
            // Intro prose + 4 rank sections with image rails + 4 trailing
            // prose sections (PROSECUTION, DEFENSE, SENTENCING, APPEALS).
            // Content verbatim from the user, preserving capitalisation,
            // trailing spaces, and original punctuation.
            WikiPage::updateOrCreate(
                ['category_id' => $cats['careers']->id, 'slug' => 'law'],
                [
                    'title'        => 'Law',
                    'lede'         => 'See Jail & Police & All Actions',
                    'body_markdown' => <<<'MD'
Law is one of the most fulfilling career paths in the game, as an attorney you can choose to defend or prosecute cases, as a district judge you can sentence those who you're convinced are guilty, and as one of three chief justices you can maintain the integrity of the court by overturning the decisions of the lower court. The consequences matter, bad judgements may cost you more than just lost revenue.

## Notary Public

![NOTARY](https://images.thedirector.app/Careers/notary.png)

At rank 1, a notary public can't do much like most rank 1s, just keep working and you'll get there soon enough!

## Attorney

![ATT](https://images.thedirector.app/Careers/attorney.png)

As an attorney, you have to right to proceed with prosecution on a case or defend a case a prosecutor has attached themselves to. No attorney can be both prosecutor and defendant on a case. When you prosecute a case, the defendant(s) are notified and if there is no defense lawyer it proceeds straight to the judge after a small wait.

## District Judge

![DJ](https://images.thedirector.app/Careers/district.png)

As a district judge, your position allows you to sentence cases and set punishments as you see fit, but in reality you'll probably have to do it at the discretion of the mayor since the fines will determine the city's funds. There are also restrictions on the minimum and maximum amount of jailtime you can add to a case, and with the exception of capital cases, the suspect usually needs around 10 convictions before you can start adding jailtime.

## Chief Justice

![CJ](https://images.thedirector.app/Careers/chiefjustice.png)

As one of three chief justices per city, you have the same abilities district judges do - with the added bonus of being able to revert the sentencing decisions of the lower courts when a convict appeals their case.

## PROSECUTION

Cases can be viewed from the Court option in the power menu, when a case has been referred for a while without anyone picking it up, it is automatically charged and sent to a district judge the next time a resident attorney logs in.

## DEFENSE

Unlike most careers, a Defense lawyer can choose to take a case in any city. When a prosecutor has been attached to a case, it is possible for a defense attorney to represent a client on it as well. A defense attorney can offer their legal services on that case from the same menu, and if accepted the trial and the results are ran and broadcasted to the relevant participants. If acquitted, the case is closed, otherwise it's sent to a district judge.

## SENTENCING

There is a small wait between a prosecutor filing a case versus when a judge can sentence, this wait allows a defendant to get a lawyer, but after the wait has passed a judge can sentence the case REGARDLESS of whether a defense attorney has chosen to represent them on that case or not.

## APPEALS

A Chief justice can choose to uphold or reverse a decision that a district judge made on a case. When the chief justice does this, they're correct and the district judge made a mistake they're doubly rewarded.
MD,
                    'sort_order'   => 20,
                    'published_at' => now(),
                ]
            );

            // ── Careers / Technician ───────────────────────────────────
            // GuideLayout — intro prose + 2 rank sections with image
            // rails (Technician, Engineer), then 1 trailing prose
            // section (THE COSMOPOLITAN). Content verbatim from user.
            WikiPage::updateOrCreate(
                ['category_id' => $cats['careers']->id, 'slug' => 'technician'],
                [
                    'title'        => 'Technician',
                    'lede'         => null,
                    'body_markdown' => <<<'MD'
Technician is one of the starting careers in The Director, as a technician you'll have the ability to repair and construct a wide variety of things like homes, vehicles and headquarters.

## Technician

![TE](https://images.thedirector.app/Careers/Technician.png)

As a technician, you will have the ability to repair vehicles, inspect new homes and repair destroyed ones as well. It's an important necessary position so take pride in it!

## Engineer

![Engineer](https://images.thedirector.app/Careers/engineer.png)

As an engineer, you'll have all the same abilities a technician does, but now you can also construct corporate headquarters and properties as well.

## THE COSMOPOLITAN

Technicians are able to do their work in any city in the game. A New York engineer can construct the property of a Corporation headquartered in Seoul as long as the engineer is in Seoul.
MD,
                    'sort_order'   => 70,
                    'published_at' => now(),
                ]
            );

            // ── Careers / Politics & Mayor ─────────────────────────────
            // GuideLayout-leaning ProseLayout. Intro paragraphs +
            // RUNNING FOR MAYOR + EXECUTIVE POWERS (bullet list) + a
            // bare `## Mayor` section with only the rank image (no
            // body — intentional, to display the rank avatar between
            // the powers list and the per-power deep-dive sections) +
            // MUNICIPAL BONDS + EXECUTIVE PARDON + UPCOMING - MARTIAL
            // LAW. Content verbatim from user, all quirks preserved.
            WikiPage::updateOrCreate(
                ['category_id' => $cats['careers']->id, 'slug' => 'politics-mayor'],
                [
                    'title'        => 'Politics & Mayor',
                    'lede'         => null,
                    'body_markdown' => <<<'MD'
Mayor is one of the most powerful positions in the entire game. You must learn to manage it properly, keeping crime rates low, your law enforcement active and funds rolling in. Since the mayoral page itself has its own built-in guide, this will be a minimal overview.

A Mayoral term lasts 7 days, through that term the mayor must prevent city crime from spiking out of control, keep a healthy fund so he can exercise executive powers, and keep the assembly happy otherwise they risk being kicked out.

## RUNNING FOR MAYOR

You can only run for mayor in your home city, during the campaign process it's possible to add funds to your campaign if you feel that's a viable route. There can only be 3 candidates in an election, and corporation members are allowed to run. The registration process lasts for 2 days, and voting for one.

## EXECUTIVE POWERS

- Suppress cases
- Enable death sentence
- Executive pardon
- Corporate Audit
- Corp regulation
- Municipal bond
- Dismiss Personnel
- Capital Punishment

## Mayor

![Mayor](https://images.thedirector.app/Careers/mayor.png)

## MUNICIPAL BONDS

Municipal bonds are one of the ways to generate funds as a mayor, once active the city crime rate must stay below a certain amount by a set date, if it hits that amount at any time, the bond is cancelled and the mayor's assembly standing takes a hit, otherwise the bond is successful and they receive the payout from the bonds.

## EXECUTIVE PARDON

An executive pardon allows a mayor to wipe a person's entire conviction history clean. It also releases them from prison if they're incarcerated.

## UPCOMING - MARTIAL LAW

Martial law allows a mayor to lockdown a city, preventing exiting and entering at any time. This is usually done in high crime cities as a last resort way of lowering the crime rate. During this period habeas corpus is suspended and a police officer can arrest anyone with an open case on them.
MD,
                    'sort_order'   => 60,
                    'published_at' => now(),
                ]
            );
        }

        // ── Combat / Protection Windows ────────────────────────────────
        if (isset($cats['combat'])) {
            WikiPage::updateOrCreate(
                ['category_id' => $cats['combat']->id, 'slug' => 'protection-windows'],
                [
                    'title'        => 'Protection Windows',
                    'lede'         => null,
                    'body_markdown' => <<<'MD'
There are 3 different types of protection in The Director: combat protection, action protection and business protection.

## Combat Protection

When a player first joins The Director, they have 5 days of protection from being attacked and attacking another player.

After that they can attack and be attacked at any time as long as the target is in the same city, alive, not hospitalized, not in jail and not protected by combat protection. Keep in mind that taking an offensive action while protected halves your remaining protection time. That's your remaining, not your original.

## Action Protection

Action protection determines how often a player can commit an action against you like crypto rug pull or kidnapping.

## Business Protection

Business protection determines how often your business can be extorted or otherwise acted against.
MD,
                    'sort_order'   => 40,
                    'published_at' => now(),
                ]
            );
        }

        // ── Combat / Combat Guide ──────────────────────────────────────
        // Damage outcomes are illustrated via an SVG flowchart served from
        // public/damage-outcomes.svg (lives at the public root, NOT under
        // /wiki/, because /wiki/{path} would shadow the wiki controller's
        // route in php artisan serve — the built-in dev server serves
        // matching files before falling through to public/index.php).
        // Referenced as a section image so the existing parseSections()
        // image-slot picks it up. Lives in the repo so edits ship via deploy.
        if (isset($cats['combat'])) {
            WikiPage::updateOrCreate(
                ['category_id' => $cats['combat']->id, 'slug' => 'combat-guide'],
                [
                    'title'        => 'Combat Guide',
                    'lede'         => 'See Protection Windows',
                    'body_markdown' => <<<'MD'
There are three different types of attacks in The Director — Assassinations, GBHs and Organized Hits. While Assassinations and Organized Hits attempt to kill the player, GBHs are non-lethal and cannot kill. The goal of a GBH is to take an opponent effectively out of play for an hour and hospitalize them.

## Attacking an Opponent

Before you attempt to attack an opponent, it's important to have some awareness. If you're a newbie attacking a powerful character, it's highly likely you'll just miss, embarrassing yourself and refreshing your page to a death screen. While there are ways to defeat a powerful opponent (good use of talents, stacking, bombings) it's important you think carefully and plan accordingly.

## GBH 

A GBH is an attack whose goal is to incapacitate an opponent, and take them out of play for an hour. GBHs are considered non lethal and can never kill an opponent.

## Assassination

An Assassination has 3 possible outcomes: a miss, damage, or instant kill. A kill results in permanent character death after 12 hours unless they receive a one-time revive from a hospital worker. Apart from character build, combat outcomes can also be affected by a wide range of factors — influence, talents, and equipped items.

## Organized Hit

An Organized Hit is a special type of Assassination carried out with 3 members. It has all of the requirements of an assassination, but with higher stakes and the chance of notifying your target in the planning phase. Unlike GBH and Assassination, an Organized Hit does not miss and will always do some damage, potentially up to 100% of the target's health. It's important to pick your target well — attacking a player not much stronger than you can result in doing less damage than you might've hoped.

## Max Health and Healing

Your player's max health determines how high your health can go regardless of how much you heal. There is **no** way to recover lost max health. This ensures that fights are not merely about building a character that can out-heal damage, but rather about picking the right battles. It is possible to experience a seriously pyrrhic victory.

## Damage Outcomes

![Damage outcomes](/damage-outcomes.svg)

Every assassination runs through a series of possibilities. The tree above shows every branch and their counterfactuals.

A successful damage outcome splits into:

- damage
- damage with critical hit
- damage with critical hit and max health loss
- damage with max health loss

It's also possible for damage to trigger a kill if the cumulative damage exceeds the target's health.

## 3 POSSIBLE STRATEGIES

Here are three possible combat strategies, each with its own strengths and risks.

1. Max Health Grinding :
Your goal isn't to kill in one hit, but to trigger permanent Max HP reduction. By repeatedly attacking a target with the best bonuses and weapons you can, you can potentially lower the target's health ceiling until their character is no longer viable, against much stronger player it will be tough to do that, but patience and persistence can go a long way.

2. Instant Kills :
Even the most powerful character cannot guarantee an instant kill, but by stacking the RIGHT bonuses alongside an extremely strong character, extremely high influence, and the right weapons you can have the best chances of landing an instant kill. This strategy is designed to end a fight before the opponent can start a counter‑grind.

3. Running :
Attack an opponent who you're sure has just arrived at your city. Travel to a different city and log off. If you're lucky, They didn't travel in with a vehicle fast enough that they can attack you before the offline attack timer expires, and even if they did they would have to pick the right city you hid in. Hopefully they don't have any friends in other cities!
MD,
                    'sort_order'   => 10,
                    'published_at' => now(),
                ]
            );

            // ── Combat / Bombing Guide (RCIED) ─────────────────────────
            // GuideLayout-leaning ProseLayout. Two intro paragraphs on
            // planting + backfire (sourced from PlantBomb::execute() and
            // ::handleFailure()), then a `## DETONATION OUTCOMES` H2 that
            // owns the SVG flowchart (/rcied-detonation.svg) summarising
            // every detonation branch from SettingsController::detonate.
            // DISCLAIMER + A POSSIBLE STRATEGY follow as plain prose.
            //
            // The OUTCOMES section from the user's source was replaced
            // by the SVG since the diagram conveys the same label/value
            // matrix more legibly than a flat indented list in markdown.
            WikiPage::updateOrCreate(
                ['category_id' => $cats['combat']->id, 'slug' => 'rcied-bombing-guide'],
                [
                    'title'        => 'Bombing Guide (RCIED)',
                    'lede'         => null,
                    'body_markdown' => <<<'MD'
To plant an RCIED you must: be at least 5 days old, own an RCIED from the black market, have your action timer available, be physically in the target's home city, and the target must own a standing property. While you can have multiple active bombs planted across the city, keep in mind that each plant consumes your action time

Planting is a delicate operation. If you fail the success check, the device may prematurely detonate in your hands, dealing some HP and max HP loss and destroying the RCIED. While usually non-lethal, these accidents have a high chance of being fatal if your character is sufficiently weakened.

## DETONATION OUTCOMES

![Detonation outcomes](/rcied-detonation.svg)

What happens on detonation depends entirely on where the target is when the bomb fires — not when it was planted. You can plant at any time and wait for optimal conditions before detonating, although do not wait too long, as the bomb will expire after 2 hours.

## DISCLAIMER

Jailed or Hospitalized characters are treated as if they are away from their home when the bomb detonates. This means that they will not take any HP damage from the explosion, but they will lose all of their stored items and vehicles and will not be granted any protection window

## A POSSIBLE STRATEGY

The most lethal sequence in the game is detonating an RCIED while the target is online, followed immediately by an assassination. Because detonation on an online or away target grants zero protection, you can stack explosive damage and a full combat hit in a single window before they can respond or flee.

A target can reduce the effectiveness of this either by being away from their home city at the cost of losing all of their stored items, being hospitalized or in jail or by being offline at the cost of a stronger hit, in exchange for a longer protection window.

Conversely, detonating while the target is away from their home city is the only way to bypass the 50% salvage rule and destroy 100% of their stored items—though you sacrifice the health damage and max HP loss to do so
MD,
                    'sort_order'   => 20,
                    'published_at' => now(),
                ]
            );

            // ── Combat / Revive ────────────────────────────────────────
            // Plain ProseLayout — two short paragraphs covering what
            // revive is and the strict preconditions (Lazarus connection
            // talent, target online, inside protection window, no prior
            // attempt). Content verbatim from the user.
            WikiPage::updateOrCreate(
                ['category_id' => $cats['combat']->id, 'slug' => 'revive'],
                [
                    'title'        => 'Revive',
                    'lede'         => null,
                    'body_markdown' => <<<'MD'
Revives are a way of bringing a player back to life after they've died. What factors affect revival chances are not publicly disclosed.

Revive requires a hospital worker with an active talent called The Lazarus connection, once the talent is activated and the dead player is online and within the 12 hour  window and no previous revive attempt has been made, The Hospital Worker can go to their profile and attempt a  revive. .
MD,
                    'sort_order'   => 30,
                    'published_at' => now(),
                ]
            );
        }

        // ── Reference / Achievements ───────────────────────────────────
        // Uses GroupLayout (tiles) — one named group ("Some Achievement
        // Examples") containing five image-illustrated milestone sections.
        // Each section image follows the existing /Achievements/ folder
        // convention on images.thedirector.app.
        if (isset($cats['reference'])) {
            WikiPage::updateOrCreate(
                ['category_id' => $cats['reference']->id, 'slug' => 'achievements'],
                [
                    'title'        => 'Achievements',
                    'lede'         => null,
                    'body_markdown' => <<<'MD'
Achievements are a reward for reaching various milestones. They function using a peak system, for example - if you unlock an achievement for 10,000 works on one character and that character dies, your next character must work 20,000 times to unlock the next tier. They are tied to your user account and survive character death. They also display on your profile as badges and can also be toggled on/off from your settings.

**SOME ACHIEVEMENT EXAMPLES**

## 10,000 WORKS

![](https://images.thedirector.app/Achievements/10kworks.png)

## 100 Influence

![](https://images.thedirector.app/Achievements/100influence.png)

## Becoming a CEO

![](https://images.thedirector.app/Achievements/theexecutive.png)

## Become a Mayor

![](https://images.thedirector.app/Achievements/themayor.png)

## Earning all degrees

![](https://images.thedirector.app/Achievements/3degrees.png)
MD,
                    'sort_order'   => 40,
                    'published_at' => now(),
                ]
            );
        }

        // ── Getting Started / Your First 24 Hours ──────────────────────
        // Plain ProseLayout — no section images, no groups. Five H2s
        // covering the immediate onboarding loop: work, journal, career
        // activities, actions, studying.
        if (isset($cats['getting-started'])) {
            WikiPage::updateOrCreate(
                ['category_id' => $cats['getting-started']->id, 'slug' => 'first-24-hours'],
                [
                    'title'        => 'Your First 24 Hours',
                    'lede'         => null,
                    'body_markdown' => <<<'MD'
Here's what to do within your first 24 Hours, if you want to succeed.

## START WORKING

Your work timer starts the moment your character is created. Go to the Work page, pick your first job, and execute it. Every minute your timer sits unused is income you're not earning and ranking you're not doing.

When working in a career, a good strategy is to start off with easy works, then mix and match works, deciding whether to do lower works with higher success but less experience vs higher works with higher experience but less success till you can consistently succeed the higher works

## READ YOUR JOURNAL

Your journal records everything that happens to your character while you're offline — attacks, money received, cases filed against you, and more. Check it as soon as possible when you get a notification so you're never caught off guard.

## DO CAREER ACTIVITIES

Some starting careers have additional activities beyond basic work. Technicians, for example, can inspect properties and repair jobs under the Workshop, while customs officials can approve moves and scan incoming travels under the airport. Check your career page regularly — these activities often pay more than standard work.

## ACTIONS

You can start doing actions like community service or more on your first day. Keep in mind more complicated actions like extortion or kidnapping are likely to fail given how weak your character is, but actions can help build up your character quite nicely so it's worth doing them.

## START STUDYING

Go to your university and enroll, even if you start as a unnamed corporation member and don't plan on switching careers, the university degrees give you options and can make it much quicker to switch careers if you ever feel the need to later on.

You can also go into training for customs or police from either the police HQ or the transit hub.
MD,
                    'sort_order'   => 20,
                    'published_at' => now(),
                ]
            );

            // ── Getting Started / New Player FAQ ───────────────────────
            // Plain ProseLayout — every H2 is one FAQ question, body
            // below is the answer. No section images, no groups. Source
            // content is verbatim from the user; two syntax-level cleanups
            // applied with explicit permission:
            //   1. Duplicate "What is the difference between Clean and
            //      dirty cash?" Q at end of source was dropped (kept the
            //      first occurrence, which appears earlier in the FAQ).
            //   2. "Can i shoot people who are offline ?" was missing its
            //      `## ` heading prefix in source; added so it renders
            //      as its own section instead of leaking into the prior Q.
            WikiPage::updateOrCreate(
                ['category_id' => $cats['getting-started']->id, 'slug' => 'new-player-faq'],
                [
                    'title'        => 'New Player FAQ',
                    'lede'         => null,
                    'body_markdown' => <<<'MD'
## How do i get started after creating an account ?

After your account is created you'll land on your Dashboard. The dashboard contains information like your educational qualifications, and cash, as well as game wide stats like career and city breakdowns. You can also browse around your settings from the dropdown of your name in the top right and switch between the online list at the bottom. For a more gameplay oriented starter guide, view the First 24 hours.

## What is the difference between clean and dirty cash?

Clean Cash is legitimate money — works, bank transfers, business income. Dirty Cash is earned through illegal activities and other ventures. Certain actions may require or produce one type or the other. Both can be held simultaneously but behave differently in the economy

## What is the Journal?

Your journal is a personal log that records important events — attacks against you, money received, rank changes, case updates. It's the primary way the game communicates what has happened and is happening to your character.

## How do i change careers?

Most careers require completing a University degree (Finance → Banking, Law → Law, Medicine → Healthcare) or enrolling at the Police Academy or customs. You can change career either by quitting your current one through settings -> life, not usually recommended since it incurs a penalty or switching through the university or place where you trained. Some careers require you to step down first in order to avoid a penalty, but that's only for more important positions.

## What career specific activities exist beyond basic work ?

Some careers unlock unique activities under your power menu while others careers like customs or technicians have an additional option like workshop or airport under the work submenu. Police officers, for example can investigate cases and arrest criminals. Lawyers can prosecute and defend court cases. Bankers can engage in trading and laundering. Technicians can repair vehicles, homes and destroyed buildings

## Can i study multiple degrees at a time ?

You can study for two degrees or a degree and training at a time — a major and a minor

## What happens if my character dies?

On death, a character loses absolutely everything and if not revived within 12 hours is killed permanently and must start over again. Death in the director is for the most part, permanent - although you can keep your achievements.

## How do i change my character settings/manage my inventory?

Go to settings, and browse around the options. In Settings → Life for example, you can Equip and unequip clothing, weapons, and armor. Your equipped outfits are also visible on your public profile.

## What happens when my items degrade?

Weapons and vehicles can degrade; clothing cannot. When a weapon or armor reaches zero durability it is destroyed. When a vehicle hits zero it goes to the Workshop of your current city automatically to be repaired and you can no longer use it to travel.

## What is influence and how do i increase it ?

Influence is a numerical score reflecting your overall standing and notoriety in the world. The exact methods for gaining it are not disclosed, but can be discovered through play.

## Can i still do the same things when i travel to a new city?

Yes, for the most part. Although, some careers still require you to be in your home city, others like Technician allow you to perform some career activities in any city

## What do i do when a case has been filed on me by a prosecutor?

You can message a lawyer asking him to offer representation on your case, if the trial goes badly for you, and a judge sentences you - you can still appeal it from the police HQ if it's a felony or higher.

## What stops detectives from framing people ?

The chain of custody (detective → prosecutor → judge) is tracked. Wrongful convictions face penalties when a Chief Justice reviews cases on appeal. Consistently framing also lowers your standing and makes you an easy target.

## What is jail time ?

Once a person has enough convictions, a judge can start adding jail time to their cases, in almost all circumstances, apart from a capital case, this requires 10 convictions.

## What is the strength meter?

Strength is a resource that depletes when you perform actions and action adjacent activities. It recovers at a set rate over time. Think of your strength meter as a motivation or stamina bar that affects how well you perform a particular action

## How does the bank work ?

Every city has a State Bank. You can deposit cash from hand into your bank balance, withdraw it back, or transfer it directly to another player's bank. cross city transfers can incur a fee and there is a quick bank withdraw option in your nav bar.

## Can i shoot people who are offline ?

In normal circumstances, a player must be offline for less than 35 minutes before they can be attacked, but if they're offline for longer than 2 weeks, they can be attacked at any time.

## I've done a few actions and now have some dirty money i want to launder

For non corporation members, there are 2 ways to launder, the first is to own a business and launder through it from your launder option on the actions page, this has a lower success chance and a limited amount that can be laundered at a time. You can also launder through a banker, a banker can simply add you as a client and you can send the money through your banker launder option. You can also cancel at any time and end your relationship.

## How do i unlock a talent?

The method for unlocking talents are secret and are not allowed to be officially disclosed either here or anywhere else on the game/discord, although the talent's story can give obvious hints.

## How do i run for mayor ?

Running for mayor is as easy as being rank 2, in your home city and having the application fee on hand. After filling out your campaign promises, you can begin your campaign run.

## How do i change cities?

Changing cities can be done at the bar by submitting a relocation request. If there is no mayor, after an hour you can go back to city hall and your request will have been approved automatically.

## How many people can be in the highest rank in a career ?

For most careers, it's one per city, one Commissioner-general, One State Bank manager, some careers like technician or customs can have as many people as possible, and Chief justices can have 3 per city.

## Should i buy a property?

Yes, a property is a primary residence, it provides storage for items and vehicles beyond your on-hand limits.
MD,
                    'sort_order'   => 10,
                    'published_at' => now(),
                ]
            );
        }

        // ── Economy / Businesses & Ownership ───────────────────────────
        // Plain ProseLayout — intro prose, then two H2 sections
        // (BUYING A BUSINESS, MANAGING A BUSINESS). No images, no groups.
        // Content is verbatim from the user.
        if (isset($cats['economy'])) {
            WikiPage::updateOrCreate(
                ['category_id' => $cats['economy']->id, 'slug' => 'businesses-and-ownership'],
                [
                    'title'        => 'Businesses & Ownership',
                    'lede'         => 'see Bank & Certificates',
                    'body_markdown' => <<<'MD'
Each city in The Director has a set of business that can be purchased by anyone, the only exception being the city hall and police HQ which can only be owned by the mayor and commissioner of that city respectively. Most services like hospital or bank in the game operate on a separation of management and ownership - the only exception again being the aforementioned two services, the state bank manager is not necessarily the owner of the bank and the surgeon general not necessarily the owner of the hospital.

Certain businesses like bank, hospital, university, police, city hall and transit hub also generate an hourly profit based on meeting certain criteria, i.e. The bank generates an hourly profit based on the amount of resident bankers there are in the city, university based on the amount of residents who hold a degree.

## BUYING A BUSINESS

By default, all business are owned and managed by the city government, after a while the government automatically puts a business on sale to a private individual at a set price. Watch the businesses page for a for-sale sign. The business can only go on sale when a private owner decides to put it up for sale.

## MANAGING A BUSINESS

As a business owner, you can collect profit from the business mostly at any time, Owners control key settings: banks set wire fee rate and CD interest rate, universities set tuition, police precincts set enrollment fees, shops restock inventory. A shop owner can restock inventory every 12 hours.
MD,
                    'sort_order'   => 20,
                    'published_at' => now(),
                ]
            );

            // ── Economy / Bank & Certificates ──────────────────────────
            // Plain ProseLayout — three H2 sections, no images, no groups.
            WikiPage::updateOrCreate(
                ['category_id' => $cats['economy']->id, 'slug' => 'bank-and-certificates'],
                [
                    'title'        => 'Bank & Certificates',
                    'lede'         => 'see businesses and ownership',
                    'body_markdown' => <<<'MD'
## CHECKING BANK ACTIVITY

Go to the bank page and click on the activity tab. You can filter by type of transaction

## CERTIFICATES OF DEPOSIT

Certificates of deposit are a way to make money from your local bank. Deposit a certain amount and earn principal plus interest after 24 hours. The interest rate is set by the bank owner.

## ACCESSING LARGE CAPITAL POOL

Making money as a bank owner is easy. You can generate profit from wire fee funds, from the bank's cut from laundering and from your smart bankers reading and timing the market just right. Increasing interest rates on certificates of deposit is also a great way to bring in large capital into the bank, with larger capital your bankers can not only launder more at a time - they also have access to larger capital to make stock market bets with, which on a win generate you even more profits. Just make sure your bank is liquid enough to cover its liabilities when the time arrives.
MD,
                    'sort_order'   => 10,
                    'published_at' => now(),
                ]
            );

            // ── Economy / Medicine ─────────────────────────────────────
            // GuideLayout — H2 sections, some with images (drug tiles).
            // Blank line inserted between each `## Heading` and the
            // following ![image] / list bullet because parseSections()
            // requires the image to be on its own line directly after
            // the heading. Body wording verbatim from user, including
            // original capitalisation and trailing spaces.
            WikiPage::updateOrCreate(
                ['category_id' => $cats['economy']->id, 'slug' => 'medicine'],
                [
                    'title'        => 'Medicine',
                    'lede'         => 'See Corporation and All actions',
                    'body_markdown' => <<<'MD'
## MEDICINE

The NY drug store contains consumables that can be purchased and taken from your settings page using the consume option in your items menu.
These drugs will set your timer to 2 hours.

## Morphine Sulfate

![Nurse](https://images.thedirector.app/items/Consumable/morphinesulfate.png)

- Recovers some of your health

## Zepbound

![Zepbound](https://images.thedirector.app/items/Consumable/zepbound1.png)

- Increases one of your stats by 100

## Modafinil

![Modafinil](https://images.thedirector.app/items/Consumable/modafinil1.png)

- Increases one of your stats by 100

## CORPORATE MEDICINE

## SETTING UP MEDICAL PRODUCTION

A Company CEO can purchase a medical business allowing anyone in the city to work the Corporate Medical business and produce a certain amount of the drugs, They can also set the PPP (Price per pack) which sets how much they're willing to pay per pack produced by a worker in that city. Working the corporate medical business requires a medical degree, but it does NOT require the worker to be in the Healthcare Career. Once produced the worker is paid and the money deducted from Corporate slush funds. The CEO can then choose either to sell the drugs all on the Black Market, or have his or her subordinates sell the drugs to individuals from the Medical sale action. The maximum is two packs at a time, and each pack contains 3 capsules.

## CONSUMING THE DRUGS

A buyer can then consume these drugs from the same settings menu, one capsule at a time. Unlike regular medicine, these corporate medicines do not have a cooldown and can be used as much as possible. The only limit is the price.

## TAK-925

![TAK](https://images.thedirector.app/items/Consumable/tak-925.png)

- Resets travel timer

## CX-717

![Zepbound](https://images.thedirector.app/items/Consumable/cx717.png)

- Resets Study timer
MD,
                    'sort_order'   => 30,
                    'published_at' => now(),
                ]
            );

            // ── Economy / Laundering ───────────────────────────────────
            // Plain ProseLayout — intro + 3 imageless H2 sections covering
            // each laundering route. Content verbatim from user.
            WikiPage::updateOrCreate(
                ['category_id' => $cats['economy']->id, 'slug' => 'laundering'],
                [
                    'title'        => 'Laundering',
                    'lede'         => 'See Corporation & Properties',
                    'body_markdown' => <<<'MD'
There are three ways to launder in The Director, each with their own strengths and weaknesses.

## MONEY LAUNDERING

This is the option labeled money laundering in the action menu, this allows you to launder any amount of money you want with no overhead, at the cost of requiring you to have a business and the max amount at a time being capped based on your business's balance sheet. You cannot hope to launder much larger amounts at a time through such a straightforwardly crude method. The authorities would easily catch on. Unlike the other methods, this method also creates a legal case and has a lower chance of succeeding.

## BANKER LAUNDERING

Banker laundering solves much of the problems with money laundering, it has a higher success chance and is nearly guaranteed to succeed ( as long as the banker is experienced enough) you can also, depending on the bank's balance sheet, launder up to a million a time.

## OFFSHORE TRUST LAUNDERING - MIRROR TRANSACTION SCHEME

This method is only available to corporations who own an offshore trust property and can only be operated by the CFO of that company. A CFO can transfer corporation slush funds to the offshore trust, then send a request to the banker, once the request is sent, the CFO can execute the launder scheme from the same page and the corporation's slush funds are converted into cash reserves. While this method also contains the banker's cut and daily upkeep fees, it can launder up to 20 mil at a time and the offshore trust also generates a little income from the process.
MD,
                    'sort_order'   => 40,
                    'published_at' => now(),
                ]
            );
        }
    }

    /** @return array<string, WikiCategory> keyed by slug */
    private function seedCategories(): array
    {
       
        $defs = [
            ['getting-started', 'Getting Started', 'New here? Start with these.',                        10],
            ['combat',          'Combat',          'How fights work, what happens when you die.',        20],
            ['careers',         'Careers',         'Work, ranks, and every playable career path.',       30],
            ['economy',         'Economy',         'Cash, banking, businesses, medicine, laundering.',    40],
            ['cities',          'Cities',          'Travel, properties, and city-level services.',       50],
            ['reference',       'Reference',       'Actions, talents, achievements, items.',             60],
        ];

        $out = [];
        foreach ($defs as [$slug, $name, $desc, $order]) {
            $out[$slug] = WikiCategory::create([
                'slug'        => $slug,
                'name'        => $name,
                'description' => $desc,
                'sort_order'  => $order,
            ]);
        }
        return $out;
    }

    /**
     * The landing hero lives in a real wiki page: _meta/landing.
     * Hidden from sidebar nav (slug starts with _, filtered in buildNav).
     *
     * updateOrCreate so seeder-driven content stays authoritative on re-seed.
     * Admins can still edit via the pencil UI, but running the seeder will
     * reset to whatever's defined here — that's the trade-off for keeping
     * content in git.
     */
    private function seedLanding(): void
    {
        $cat = WikiCategory::firstOrCreate(
            ['slug' => '_meta'],
            [
                'name'        => 'Meta',
                'description' => 'Hidden — landing page and other meta content.',
                'sort_order'  => 0,
            ]
        );

        WikiPage::updateOrCreate(
            ['category_id' => $cat->id, 'slug' => 'landing'],
            [
                'title'        => 'Welcome to The Director!',
                'lede'         => 'Greed is Good!',
                'body_markdown' => <<<'MD'
The Director is a strategic game that takes place in a verisimilitudinous world like ours. In it you can build a character, choose a career path, accumulate wealth and power, join or start a corporation, rise through the ranks, engage in combat, own businesses and compete with other real players across multiple cities. Everything is persistent — your character exists in the living world even when you're offline.

This is the wiki for the game, I've tried to keep things as clear and easy to navigate as possible and have the information as updated as often as I can, if you have any further questions you can join the discord or contact me at the email address below.
MD,
                'sort_order'   => 0,
                'published_at' => now(),
            ]
        );
    }


    private function seedPageStubs(array $cats): void
    {
        $stubs = [
            // Getting Started — what-is-the-director removed (landing covers it)
            ['getting-started',  10,  'new-player-faq',         'New Player FAQ'],
            ['getting-started',  20,  'first-24-hours',         'Your First 24 Hours'],

            // Combat
            ['combat',           10,  'combat-guide',           'Combat Guide'],
            ['combat',           20,  'rcied-bombing-guide',    'Bombing Guide (RCIED)'],
            ['combat',           30,  'revive',                 'Revive'],
            ['combat',           40,  'protection-windows',     'Protection Windows'],

            // Careers (added Technician & Customs)
            ['careers',          10,  'police',                 'Police'],
            ['careers',          20,  'law',                    'Law'],
            ['careers',          30,  'banking',                'Banking'],
            ['careers',          40,  'healthcare',             'Healthcare'],
            ['careers',          50,  'corporation',            'Corporation'],
            ['careers',          60,  'politics-mayor',         'Politics & Mayor'],
            ['careers',          70,  'technician',             'Technician'],
            ['careers',          80,  'customs',                'Customs'],

            // Economy
            ['economy',          10,  'bank-and-certificates',  'Bank & Certificates'],
            ['economy',          20,  'businesses-and-ownership', 'Businesses & Ownership'],
            ['economy',          30,  'medicine',               'Medicine'],
            ['economy',          40,  'laundering',             'Laundering'],

            // Cities (jail added)
            ['cities',           10,  'services',               'Services'],
            ['cities',           20,  'travel',                 'Travel'],
            ['cities',           30,  'properties',             'Properties'],
            ['cities',           40,  'jail',                   'Jail'],

            // Reference
            ['reference',        10,  'all-actions',            'All Actions'],
            ['reference',        30,  'talents',                'Talents'],
            ['reference',        40,  'achievements',           'Achievements'],
        ];

        foreach ($stubs as [$catSlug, $sort, $slug, $title]) {
            if (! isset($cats[$catSlug])) continue;
            WikiPage::create([
                'category_id'  => $cats[$catSlug]->id,
                'slug'         => $slug,
                'title'        => $title,
                'lede'         => null,
                'body_markdown' => "_This page is a stub. An admin will fill it in._",
                'sort_order'   => $sort,
                'published_at' => now(), // published — visible to everyone, just empty
            ]);
        }
    }
}
