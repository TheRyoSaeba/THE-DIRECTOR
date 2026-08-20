/**
 * guides.jsx
 * ----------
 * All guide content for the Help page.
 * Each guide has: id, title, category, categoryColor, description,
 * author, isAdmin, readTime, and an `article` array.
 *
 * Article blocks:
 *   { type: 'heading', text }
 *   { type: 'subheading', text }
 *   { type: 'body', text }
 *   { type: 'callout', variant: 'info'|'warning'|'danger', text }
 *   { type: 'image', src, alt, caption }
 *   { type: 'component', render }   ← inline React component for diagrams/charts
 *
 * The `render` field on a `component` block is a zero-argument function
 * returning JSX — it is called by GuidesPanel when rendering the article.
 */

import React from 'react';


// ── Assassination Outcome Flow ────────────────────────────────────────────────

function TreeRow({ depth = 0, connector = '├', label, detail, isLast = false }) {
    const prefix = depth === 0 ? '' : '│  '.repeat(depth - 1) + (isLast ? '└─ ' : '├─ ');
    return (
        <div className="flex items-baseline gap-0 font-mono text-[12px] leading-relaxed">
            <span className="text-slate-700 whitespace-pre select-none">{prefix}</span>
            <span className="text-slate-300 font-semibold">{label}</span>
            {detail && <span className="text-slate-600 ml-2">— {detail}</span>}
        </div>
    );
}

function DamageOutcomeTree() {
    return (
        <div className="my-6 rounded-2xl border border-slate-800/60 bg-slate-900/40 overflow-hidden">
            <div className="px-5 py-3 border-b border-slate-800/50 bg-slate-900/60">
                <p className="text-xs font-black uppercase tracking-widest text-slate-500">
                    Assassination — Outcome Flow
                </p>
            </div>
            <div className="px-5 py-5 overflow-x-auto">
                <div className="space-y-0.5">
                    <TreeRow depth={0} label="Attack" />
                    <TreeRow depth={1} label="Hit Roll" />
                    <TreeRow depth={2} label="Miss" detail="no damage, different protection window" />
                    <TreeRow depth={2} label="Hit" isLast />
                    <TreeRow depth={3} label="Kill Roll" />
                    <TreeRow depth={4} label="Instant Kill" detail="target killed, cash and dirty cash looted, 12-hour revive window" />
                    <TreeRow depth={4} label="Damage" detail="HP reduced" isLast />
                    <TreeRow depth={5} label="Critical hit possible" detail="extra damage" />
                    <TreeRow depth={5} label="Max HP" detail="can be permanently reduced" />
                    <TreeRow depth={5} label="HP reaches zero" detail="target killed" />
                    <TreeRow depth={5} label="Target survives" detail="receives protection window" isLast />
                </div>
            </div>
        </div>
    );
}

// ── RCIED Outcome Tree ────────────────────────────────────────────────────────

function RCIEDOutcomeTree() {
    return (
        <div className="my-8 rounded-2xl border border-slate-800/60 bg-slate-900/40 overflow-hidden shadow-2xl scale-[1.02]">
            <div className="px-6 py-4 border-b border-slate-800/50 bg-slate-900/60">
                <p className="text-sm font-black uppercase tracking-widest text-slate-500">
                    Outcome Reference — RCIED Detonation
                </p>
            </div>
            <div className="px-6 py-8 overflow-x-auto">
                <div className="space-y-1">
                    <TreeRow depth={0} label="Detonation" />
                    <TreeRow depth={1} label="Condition: Target Away from Home City" />
                    <TreeRow depth={2} label="HP Damage" detail="None" />
                    <TreeRow depth={2} label="Stored Items" detail="ALL items in safe/garage destroyed" />
                    <TreeRow depth={2} label="Protection" detail="None" isLast />

                    <TreeRow depth={1} label="Condition: Target in Home City" isLast />
                    <TreeRow depth={2} label="HP Damage" detail=" Lower Damage (Online) or Higher Damage (Offline)" />
                    <TreeRow depth={2} label="Stored Items" detail="50% of items in safe/garage destroyed" />
                    <TreeRow depth={2} label="Protection" detail=" Double the standard window if target was offline; None if online" isLast />
                </div>
            </div>
            <div className="px-6 py-4 border-t border-slate-800/50 bg-slate-900/20">
                <p className="text-[11px] text-slate-500 leading-relaxed italic">
                    Note: Items on the target's person (on hand) are never affected by property destruction.
                    Protection is halved for the attacker since a bomb explosion counts as an attack.
                </p>
            </div>
        </div>
    );
}

// ── Outcome Row — Redundant after tree refactor — DELETED ──────────────────────




// ── RCIED Success Factors ─────────────────────────────────────────────────────
function PlantingFactors() {
    const factors = [
        { label: 'Target State', ideal: 'Offline' },
        { label: 'City Activity', ideal: 'Quiet City' },
        { label: 'Property Type', ideal: 'Low-Tier Residence' },
    ];

    return (
        <div className="my-6 rounded-2xl border border-slate-800/60 bg-slate-900/40 overflow-hidden text-sm">
            <div className="px-5 py-3 border-b border-slate-800/50 bg-slate-900/60">
                <p className="text-xs font-black uppercase tracking-widest text-slate-500">
                    Success Factors
                </p>
            </div>
            <div className="p-0 overflow-hidden">
                <table className="w-full text-left">
                    <thead>
                        <tr className="border-b border-slate-800/50 bg-slate-900/20">
                            <th className="px-5 py-3 text-[10px] font-bold uppercase text-slate-500">Factor</th>
                            <th className="px-5 py-3 text-[10px] font-bold uppercase text-slate-500 text-right">Improves Chances</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-800/30">
                        {factors.map((f) => (
                            <tr key={f.label} className="hover:bg-slate-400/5 transition-colors">
                                <td className="px-5 py-3 text-slate-400 font-medium">{f.label}</td>
                                <td className="px-5 py-3 text-slate-300 text-right font-mono">{f.ideal}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}



export const GUIDES_DATA = [
    {
        id: 'combat-guide',
        title: 'Combat Guide',
        category: 'Combat',
        categoryColor: 'text-red-400 bg-red-500/10 border-red-500/20',
        description: 'How combat works, what outcomes are possible, and how revives factor in.',
        author: 'System',
        isAdmin: false,
        readTime: '5 min',
        article: [
            {
                type: 'heading',
                text: 'Combat Overview',
            },
            {
                type: 'body',
                text: 'There are two types of combat in The Director: GBH and Assassination. Each produces different outcomes and serves a different purpose.',
            },

            {
                type: 'heading',
                text: 'Protection',

            },
            {
                type: 'body',
                text: 'All players start off The Director with a 5 day  Protection period as stated in the knowledge base. Once the 5 days are over a player can attack or be attacked, once attacked a player receives a  window of protection depending on the type and outcome of the attack. A player that attacks while their protection window is active lowers their own protection window by half.',
            },






            {
                type: 'heading',
                text: 'GBH',
            },
            {
                type: 'body',
                text: 'Grievous Bodily Harm is a non-lethal attack. A successful hit hospitalizes the target for one hour, locking them out of work, combat, and career activities — essentially taking them out of play. It also deals a small amount of HP damage. It has two outcomes: a miss or a successful hospitalization.',
            },
            {
                type: 'heading',
                text: 'Assassination',
            },
            {
                type: 'body',
                text: 'Assassination is a lethal attack intended to permanently kill the target. It has three possible outcomes: miss, damage, or kill. A kill results in permanent character death after 12 hours unless a hospital worker successfully revives them before the window closes. Revives are a one time  action.',
            },
            {
                type: 'callout',
                variant: 'info',
                text: 'Apart from character build, combat outcomes are affected by  a wide range of factors  — influence, active talents, equipped weapons and armor,  bonuses, and more.',
            },

            {
                type: 'heading',
                text: 'Organized Hit',
            },
            {
                type: 'body',
                text: 'An Organized Hit is a special type of Assasination carried out with 3 members. It has all of the requirements of an assasination, but with higher stakes, timers and the chance of notifying your target in the planning phase.',
            },

            {
                type: 'callout',
                variant: 'info',
                text: 'Unlike GBH and Assassination, an organized hit does not have an element of chance and will always do some damage, potentially up to 100% of the target\'s health.',
            },


            {
                type: 'heading',
                text: 'Damage Outcomes',
            },
            {
                type: 'body',
                text: 'Every assassination runs through a sequence of rolls. The tree below shows every branch and what triggers it.',

            },

            {
                type: 'callout',
                variant: 'info',
                text: ' A successful damage roll splits into damage, damage with critical hit, damage with critical hit and max health loss or damage with max health loss, it\'s also possible for damage to trigger a kill if the cumulative damage exceeds the target\'s  health.',
            },






            {
                type: 'component',
                render: () => <DamageOutcomeTree />,
            },



            {
                type: 'heading',
                text: 'Revive',
            },


            {
                type: 'body',
                text: 'A Murdered character has exactly 12 hours during which a Healthcare worker with the Lazarus Connection talent can attempt a revive. The attempt is one chance only — a failure permanently closes the window. It is imperative for the healthcare worker to be as experienced as possible.',
            },

            {
                type: 'heading',
                text: 'Max Health and Healing',
            },
            {
                type: 'body',
                text: 'Your player\'s max health determines how high your health can go regardless of how much you heal. There is NO way to recover lost max health. This ensures that fights are not merely about building a character that can outheal damage, but rather about picking the right battles. It is possible to experience a  seriously pyrrhic victory.',
            },
            {
                type: 'body',
                text: 'There are 3 ways to heal in the Director. One is to simply wait - The Game naturally recovers up to 2 HP every half hour. The second is to apply for surgery at a hospital which can restore some of your health - Keep in mind you can do only do 1 surgery per city every 12 hours. The third is buy a drug like Morphine and consume it from your inventory, but it\'s expensive and uses your talent timer so keep that in mind.',
            },
            {
                type: 'heading',
                text: 'Three Strategies',
            },
            {
                type: 'body',
                text: 'Here are three possible combat strategies, each with its own strengths and risks.',
            },

            {
                type: 'subheading',
                text: '1. Max Health Grinding',
            },
            {
                type: 'body',
                text: 'Your goal isn\'t to kill in one hit, but to trigger permanent Max HP reduction. By repeatedly attacking a  target with the best bonuses and weapons you can, you can potentially lower the target\'s health ceiling until their character is no longer viable, against much stronger player it will be tough to do that, but patience and persistence can go a long way.',
            },
            {
                type: 'subheading',
                text: '2. Instant Kills',
            },
            {
                type: 'body',
                text: ' Even the most powerful character cannot guarantee an instant kill, but by stacking the RIGHT bonuses alongside an extremely strong character, extremely high influence, and the right weapons you can have the best chances of landing an instant kill. This strategy is designed to end a fight before the opponent can start a counter‑grind.',
            },
            {
                type: 'subheading',
                text: '3. Running',
            },
            {
                type: 'body',
                text: 'Attack an opponent who you\'re sure has just arrived at your city. Travel to a different city and log off. If you\'re lucky, They didn\'t travel in with a vehicle fast enough that they can attack you before the offline attack timer expires, and even if they did they would have to pick the right city you hid in. Hopefully they don\'t have any friends in other cities!'
            },
        ],
    },
    {
        id: 'bombing-guide',
        title: 'RCIED Bombing Guide',
        category: 'Combat',
        categoryColor: 'text-red-400 bg-red-500/10 border-red-500/20',
        description: 'How to plant and detonate an RCIED, what affects success, and how outcomes differ based on target location.',
        author: 'System',
        isAdmin: false,
        readTime: '3 min',
        article: [
            {
                type: 'heading',
                text: 'Requirements',
            },
            {
                type: 'body',
                text: 'To plant an RCIED you must: be at least 5 days old, own an RCIED from the black market, have your action timer available, be physically in the target\'s home city, and the target must own a standing property. While you can have multiple active bombs planted across the city, keep in mind that each plant consumes your action timer.',
            },


            {
                type: 'heading',
                text: 'Planting Mechanics',
            },
            {
                type: 'body',
                text: 'Planting is a delicate operation. If you fail the success check, the device may prematurely detonate in your hands, dealing some HP and max HP loss and destroying the RCIED. While usually non-lethal, these accidents have a high chance of being fatal if your character is sufficiently weakened.',
            },

            {
                type: 'component',
                render: () => <PlantingFactors />,
            },

            {
                type: 'heading',
                text: 'Detonation Outcomes',
            },
            {
                type: 'body',
                text: 'What happens on detonation depends entirely on where the target is when the bomb fires — not when it was planted. You can plant at any time and wait for optimal conditions before detonating, although do not wait too long, as the bomb will expire after 2 hours.',
            },
            {
                type: 'component',
                render: () => <RCIEDOutcomeTree />,
            },
            {
                type: 'callout',
                variant: 'warning',
                text: 'Safe Havens: Jailed or Hospitalized characters are treated as if they are away from their home when the bomb detonates. This means that they will not take any HP damage from the explosion, but they will lose all of their stored items and vehicles and will not be granted any protection window.',
            },
            {
                type: 'heading',
                text: 'The Double Attack',
            },
            {
                type: 'body',
                text: 'The most lethal sequence in the game is detonating an RCIED while the target is online, followed immediately by an assassination. Because detonation on an online or away target grants zero protection, you can stack explosive damage and a full combat hit in a single window before they can respond or flee.',


            },


            {
                type: 'body',
                text: 'A target can reduce the effectiveness of this either by being away from their home city at the cost of losing all of their stored items, being hospitalized or in jail or by being offline at the cost of a stronger hit, in exchange for a longer protection window',
            },
            {
                type: 'callout',
                variant: 'warning',
                text: 'Conversely, detonating while the target is away from their home city is the only way to bypass the 50% salvage rule and destroy 100% of their stored items—though you sacrifice the health damage and max HP loss to do so.',
            },
        ],
    },
    {
        id: 'new-player',
        title: 'New Player Checklist',
        category: 'General',
        categoryColor: 'text-slate-400 bg-slate-500/10 border-slate-500/20',
        description: 'Six things every new player should do in their first five days.',
        author: 'System',
        isAdmin: false,
        readTime: '4 min',
        article: [
            {
                type: 'heading',
                text: '1. Start Working',
            },
            {
                type: 'body',
                text: 'Your work timer starts the moment your character is created. Go to the Work page, pick your first job, and execute it. Every minute your timer sits unused is income you\'re not earning.',
            },

            {
                type: 'callout',
                variant: 'info',
                text: 'When working in a career, a good strategy is to start off with easy works, then mix and match works, deciding whether to do lower works with higher success but less experience vs higher works with higher experience but less success till you can consistently succeed the higher works.',
            },
            {
                type: 'heading',
                text: '2. Do Career Activities',
            },
            {
                type: 'body',
                text: 'Some starting careers have additional activities beyond basic work. Technicians, for example, can find inspection and repair jobs under the Workshop. Check your career page regularly — these activities often pay more than standard work.',
            },
            {
                type: 'callout',
                variant: 'info',
                text: 'Once you complete training at a university or police academy and start a new career, you unlock a different set of  works and career activities with increased earnings.',
            },
            {
                type: 'heading',
                text: '3. Avoid Actions Early',
            },
            {
                type: 'body',
                text: 'The Actions page contains extra activities that offer higher rewards, some illegal some not. Although new players may be tempted to immediately start doing them given they have a much higher ceiling for rewards, new characters attempting actions are likely to fail, which wastes your timer and earns nothing. Build your character through work and career activities first, then after a few days you can try activities',
            },
            {
                type: 'callout',
                variant: 'info',
                text: 'Keeping most of your cash in the bank can protect it against some actions other players can run against you.',
            },
            {
                type: 'heading',
                text: '4. Stay Out of Fights',
            },
            {
                type: 'body',
                text: 'You have a five-day new player protection period. Once it expires, you can be attacked by other players. Fighting before you understand the mechanics is a fast way to end up hospitalized, dead or missing all your shots.',
            },
            {
                type: 'heading',
                text: '5. Enroll at a University or Training',
            },
            {
                type: 'body',
                text: 'Starting careers are a foundation, not a destination. As soon as you can afford enrollment, choose a long-term career path and start studying. The sooner you enroll, the sooner you unlock access to higher-paying work and career activities.',
            },
            {
                type: 'heading',
                text: '6. Read Your Journal',
            },
            {
                type: 'body',
                text: 'Your journal records everything that happens to your character while you\'re offline — attacks, money received, cases filed against you, and more. Check it as soon as possible when you get a notification so you\'re never caught off guard.',
            },
        ],
    },
];
