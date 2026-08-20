import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
// @ts-ignore
import { route } from 'ziggy-js';
import {
    ChartBar, Scales, CurrencyDollar, HandFist,
    TrendUp, Shield, Buildings, Gavel, Handshake,
    UserMinus, SealWarning, Warning, ArrowRight,
    Lock, Plus, Minus,
    Receipt, Info, IdentificationCard,
    Lightbulb, SignOut
} from '@phosphor-icons/react';
import { motion, AnimatePresence } from 'framer-motion';
import GameLayout from '@/Layouts/GameLayout';
import { getCityImage } from '@/utils/cityImages';
import Picker from '@/Components/picker.jsx';

// ─────────────────────────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────────────────────────

interface PeriodIncome {
    tax: number;
    fine: number;
    audit: number;
    bond: number;
}

interface MayorTerm {
    id: number;
    period: number;
    city_funds: number;
    assembly_score: number;
    budget_law: number;
    budget_corp_reg: number;
    budget_services: number;
    budget_bonds: number;
    income_tax_rate: number;
    corporate_tax_rate: number;
    corp_regulation_active: boolean;
    bonds_active: boolean;
    death_sentence_active: boolean;
    suppressions_used: number;
    has_active_bond: boolean;
    bond_yield: number | null;
    bond_matures_at: number | null;
    pardon_cooldown_passed: boolean;
    audit_in_progress: boolean;
    period_income: PeriodIncome;
    ledger: LedgerEntry[];
}

interface LedgerEntry {
    period: number;
    tax_in: number;
    fine_in: number;
    audit_in: number;
    bond_return: number;
    crime_drain: number;
    net: number;
    assembly_delta: number;
    crime_rate: number;
    at: number;
}

interface Props {
    city: { name: string; slug: string; image_url: string | null };
    term: MayorTerm;
    mayor_name: string;
    crime_rate: number;
    pickerData: {
        active_cases: { value: string; label: string }[];
        pardon_targets: { value: string; label: string }[];
        police_officers: { value: string; label: string }[];
        commissioner: { value: string; label: string }[];
    };
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

const fmt$ = (n: number) => `$${Math.round(n).toLocaleString()}`;

// Assembly: removal is triggered the instant the score hits 50 (see
// MayorService — every removal check is `assembly_score <= 50`). The color
// bands MUST reflect that 50 is death, not "low". A mayor sitting at 55 is
// in genuine danger, not "unstable but fine".
//   > 75  emerald  — comfortable mandate
//   60-75 amber    — slipping, needs attention
//   <= 59 rose     — danger zone, one bad period from removal
function asmColor(score: number) {
    if (score > 75) return 'text-emerald-400';
    if (score >= 60) return 'text-amber-400';
    return 'text-rose-400';
}

// Crime bands mirror App\Support\CrimeThresholds (SAFE<=15, LOW<=35,
// MODERATE<=55, HIGH<=75, CRITICAL>75). The bond-default threshold is 65,
// so anything in the HIGH band is already dangerous for a bond-holding
// mayor — hence amber starts at the MODERATE/HIGH boundary (55).
function crimeColor(rate: number) {
    if (rate > 55) return 'text-rose-400';
    if (rate > 35) return 'text-amber-400';
    return 'text-emerald-400';
}

// Label mirrors CrimeThresholds::label() exactly so the UI never disagrees
// with the canonical band names used server-side.
function crimeLabel(rate: number): string {
    if (rate <= 15) return 'Safe';
    if (rate <= 35) return 'Low';
    if (rate <= 55) return 'Moderate';
    if (rate <= 75) return 'High';
    return 'Critical';
}

// Assembly status label, aligned with the asmColor bands above.
function asmLabel(score: number): string {
    if (score > 75) return 'Secure';
    if (score >= 60) return 'Slipping';
    if (score > 50) return 'Critical';
    return 'Collapsing';
}

/** Tier label for budget allocation */
function budgetTier(val: number): { label: string; color: string } {
    if (val >= 50) return { label: 'Dominant', color: 'text-cyan-400' };
    if (val >= 25) return { label: 'Supporting', color: 'text-cyan-300/80' };
    return { label: 'Neglected', color: 'text-rose-400' };
}

// ─────────────────────────────────────────────────────────────────────────────
// Inline hint component
// ─────────────────────────────────────────────────────────────────────────────

function Hint({ text }: { text: string }) {
    const [show, setShow] = useState(false);
    return (
        <span className="relative inline-flex items-center">
            <button
                type="button"
                onMouseEnter={() => setShow(true)}
                onMouseLeave={() => setShow(false)}
                className="text-slate-600 hover:text-slate-400 transition-colors"
            >
                <Info size={12} weight="fill" />
            </button>
            <AnimatePresence>
                {show && (
                    <motion.div
                        initial={{ opacity: 0, y: 4 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0 }}
                        transition={{ duration: 0.1 }}
                        className="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-52 bg-slate-800 border border-white/10 rounded-lg px-3 py-2 text-[10px] text-slate-300 font-medium leading-relaxed z-50 pointer-events-none shadow-xl"
                    >
                        {text}
                    </motion.div>
                )}
            </AnimatePresence>
        </span>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Mayor's Guide
// Triggered: period 1, no settled ledger. No localStorage.
// Each page is visually distinct — not a repeated card template.
// ─────────────────────────────────────────────────────────────────────────────

const GUIDE_PAGES: Array<{ tag: string; heading: string; body: React.ReactNode }> = [
    {
        tag: '01 — Your New Office',
        heading: 'One week.\nFour cycles.\nOne city.',
        body: (
            <div className="space-y-0">
                {([
                    ["You control this city's government for the next seven days, split into four cycles of roughly two days each.", false],
                    ["Criminal activity drains your treasury in real time — the damage accumulates between every visit you make to this office, not just at cycle's end.", true],
                    ["Your Assembly score is your mandate from heaven. Let it fall too low  and you are removed immediately, forfeiting everything you've earned.", false],
                    ["Complete every cycle and you collect a substantial personal reward at each checkpoint. Leave early or get removed and you lose it all.", false],
                ] as [string, boolean][]).map(([text, highlight], i) => (
                    <div key={i} className={`py-3.5 border-b border-white/[0.04] last:border-0 ${highlight ? 'pl-3 border-l-2 border-l-amber-500/50' : ''}`}>
                        <p className={`text-[11px] font-medium leading-relaxed ${highlight ? 'text-amber-100/80' : 'text-white/70'}`}>{text}</p>
                    </div>
                ))}
            </div>
        ),
    },
    {
        tag: '02 — The Clock Never Stops',
        heading: "Crime is corrosive.",
        body: (
            <div className="space-y-4">
                <p className="text-[11px] text-white/70 font-medium leading-relaxed">
                    Every time you return to this office, time has passed. Two things have been happening silently:
                </p>
                <div className="space-y-3">
                    {[
                        {
                            color: 'bg-rose-500/40',
                            label: 'Continuous — every visit',
                            labelColor: 'text-rose-400',
                            text: "Crime has been draining your treasury proportional to how dangerous the city is and how long you were gone. A violent city can bleed dry in days. A peaceful one barely feels it.",
                        },
                        {
                            color: 'bg-emerald-500/40',
                            label: 'Continuous — every visit',
                            labelColor: 'text-emerald-400',
                            text: "If you invested in law enforcement, your police force has been quietly reducing city crime between visits — easing that drain over time.",
                        },
                        {
                            color: 'bg-amber-500/40',
                            label: 'Instant — on next visit',
                            labelColor: 'text-amber-400',
                            text: "Some events resolve the moment you next arrive: a bond that matured, a bond that defaulted, a suppression you ordered. These hit your standing immediately.",
                        },
                        {
                            color: 'bg-slate-500/40',
                            label: 'Tallied — every 42 hours',
                            labelColor: 'text-cyan-400/75',
                            text: "Assembly score changes, your personal retainer, and the ledger snapshot are calculated at cycle checkpoints — roughly every two days.",
                        },
                    ].map(({ color, label, labelColor, text }) => (
                        <div key={label} className="flex gap-3">
                            <div className={`w-px ${color} shrink-0 mt-1`} />
                            <div>
                                <p className={`text-[10px] font-black uppercase tracking-widest mb-1 ${labelColor}`}>{label}</p>
                                <p className="text-[11px] text-slate-300 font-medium leading-relaxed">{text}</p>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        ),
    },
    {
        tag: '03 — Two Paths',
        heading: 'Choose a strategy.\nCommit to it.',
        body: (
            <div className="space-y-3">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-px bg-white/[0.04] rounded-xl overflow-hidden">
                    <div className="bg-slate-900 p-5 space-y-3">
                        <div>
                            <p className="text-[9px] font-black text-cyan-400 uppercase tracking-[0.2em] mb-1">Law & Order</p>
                            <p className="text-xs font-black text-white leading-snug">Invest in enforcement. Slow the bleeding.</p>
                        </div>
                        <div className="w-8 h-px bg-cyan-500/30" />
                        <div className="space-y-2 text-[10px] text-white/70 font-medium leading-relaxed">
                            <p>A dominant law enforcement budget gradually reduces city crime between every visit — lowering the drain on your treasury over time.</p>
                            <p>Also unlocks your ability to order corporate audits through the police, recovering seized funds and boosting Assembly confidence.</p>
                            <p className="text-white/50 italic">Works best when police and law careers are active, generating fines and case closures.</p>
                        </div>
                    </div>
                    <div className="bg-slate-950 p-5 space-y-3">
                        <div>
                            <p className="text-[9px] font-black text-amber-400 uppercase tracking-[0.2em] mb-1">Prosperity</p>
                            <p className="text-xs font-black text-white leading-snug">Amplify what residents earn. Issue bonds.</p>
                        </div>
                        <div className="w-8 h-px bg-amber-500/30" />
                        <div className="space-y-2 text-[10px] text-white/70 font-medium leading-relaxed">
                            <p>A dominant public services budget multiplies the tax collected from every career earn in the city. More residents working means more revenue.</p>
                            <p>Municipal bonds let you issue capital now and collect principal plus yield after roughly half a cycle — reliable income if crime stays low.</p>
                            <p className="text-white/50 italic">Fails fast if crime climbs too high. Bonds become dangerous above a certain threshold.</p>
                        </div>
                    </div>
                </div>
                <p className="text-[10px] text-white/60 font-medium px-1">
                    <span className="text-white font-black">Both paths require activity.</span> An empty city with no career players has no tax base regardless of policy.
                </p>
            </div>
        ),
    },
    {
        tag: '04 — What Ends Terms',
        heading: 'Four mistakes\nthat end mayors.',
        body: (
            <div className="space-y-0">
                {[
                    {
                        n: '01',
                        title: 'Spending more than you earn',
                        text: "If your income falls short of crime drain at cycle end, the Assembly punishes you severely. Repeat this across multiple cycles and your standing collapses.",
                        color: 'text-rose-400',
                    },
                    {
                        n: '02',
                        title: 'Suppressing cases',
                        text: "Each suppression hits your Assembly immediately when you authorize it, then hits you again at every subsequent cycle checkpoint. It compounds for the rest of your term.",
                        color: 'text-orange-400',
                    },
                    {
                        n: '03',
                        title: 'Letting crime run unchecked',
                        text: "A dangerous city drains your treasury faster than any income policy can compensate. Without enforcement, player criminal activity pushes the crime rate higher on every kill, assault, and scam committed in your city.",
                        color: 'text-amber-400',
                    },
                    {
                        n: '04',
                        title: 'Bond mismanagement',
                        text: "If crime climbs too high or you move your budget away from bonds while one is active, it defaults the next time you visit this office — not at cycle end. The principal is lost immediately and Assembly drops right away.",
                        color: 'text-white/70',
                    },
                ].map(({ n, title, text, color }) => (
                    <div key={n} className="flex gap-4 py-4 border-b border-white/[0.04] last:border-0">
                        <span className={`text-[11px] font-black tabular-nums shrink-0 pt-0.5 ${color}`}>{n}</span>
                        <div>
                            <p className="text-[11px] font-black text-white mb-1">{title}</p>
                            <p className="text-[10px] text-white/70 font-medium leading-relaxed">{text}</p>
                        </div>
                    </div>
                ))}
            </div>
        ),
    },
    {
        tag: '05 — First 24 Hours',
        heading: 'Four actions.\nDo them now.',
        body: (
            <div className="space-y-0">
                {[
                    { step: 'Budget', action: "Go to the Budget tab and commit to a sector.The budget totals 100 and a sector needs 50+ to do anything substantial. For a first term, you can try putting 50%+ into Law Enforcement. It is the only lever that shrinks the crime drain that would otherwise end your term." },
                    { step: 'Tax', action: "Raise income tax in Tax Policy — toward the 25% cap, not just 'above default'. The 5% floor does not cover crime drain in any city with real activity." },
                    { step: 'Pick a lever', action: "With your spare budget points, you can choose to either build toward Corp Regulation (enables audits, which recover funds and lift Assembly), commit to higher public services for increased tax revenue, or commit 50% to Bonds for the Prosperity path." },
                ].map(({ step, action }, i) => (
                    <div key={i} className="flex gap-4 py-3.5 border-b border-white/[0.04] last:border-0 items-start">
                        <span className="text-[9px] font-black text-amber-400/60 uppercase tracking-widest shrink-0 w-20 pt-0.5 leading-tight">{step}</span>
                        <p className="text-[11px] text-slate-300 font-medium leading-relaxed">{action}</p>
                    </div>
                ))}
            </div>
        ),
    },
];
function MayorBriefing() {
    const [page, setPage] = useState(0);
    const current = GUIDE_PAGES[page];

    return (
        <div className="grid gap-7 lg:grid-cols-[240px_minmax(0,1fr)]">
            <aside className="px-1">
                <p className="text-[10px] font-black uppercase tracking-[0.25em] text-cyan-400">Mayor's Briefing</p>
                <p className="mt-3 text-[11px] font-medium leading-relaxed text-white/70">
                    Crime is, as Daniel Dennett puts it, a universal acid. Don't let it eat away at your city.
                </p>

                <nav className="mt-5 space-y-1 border-l border-white/10">
                    {GUIDE_PAGES.map((entry, i) => (
                        <button
                            key={entry.tag}
                            type="button"
                            onClick={() => setPage(i)}
                            className={`-ml-px block w-full border-l px-4 py-3 text-left transition-colors ${i === page
                                ? 'border-amber-400 bg-amber-500/[0.06]'
                                : 'border-transparent hover:border-cyan-400/50 hover:bg-white/[0.02]'
                                }`}
                        >
                            <span className={`block text-[9px] font-black uppercase tracking-[0.22em] ${i === page ? 'text-amber-300' : 'text-cyan-400/75'}`}>
                                {entry.tag}
                            </span>
                            <span className={`mt-1 block text-[11px] font-black leading-snug ${i === page ? 'text-white' : 'text-white/55'}`}>
                                {entry.heading.replace('\n', ' ')}
                            </span>
                        </button>
                    ))}
                </nav>
            </aside>

            <article className="min-w-0 border-l border-white/[0.06] pl-5 md:pl-8">
                <AnimatePresence mode="wait" initial={false}>
                    <motion.div
                        key={page}
                        initial={{ opacity: 0, y: 6 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: -4 }}
                        transition={{ duration: 0.14 }}
                    >
                        <p className="mb-3 text-[9px] font-black uppercase tracking-[0.25em] text-amber-400/80">{current.tag}</p>
                        <h2 className="max-w-xl text-2xl md:text-3xl font-black leading-tight tracking-tight text-white whitespace-pre-line">
                            {current.heading}
                        </h2>
                        <div className="mt-6 h-px bg-white/[0.06]" />
                        <div className="mt-6">
                            {current.body}
                        </div>
                    </motion.div>
                </AnimatePresence>
            </article>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Nav
// ─────────────────────────────────────────────────────────────────────────────

const NAV = [
    { key: 'overview', label: 'Overview', icon: ChartBar },
    { key: 'briefing', label: 'Briefing', icon: Lightbulb },
    { key: 'budget', label: 'Budget', icon: Scales },
    { key: 'taxes', label: 'Tax Policy', icon: CurrencyDollar },
    { key: 'powers', label: 'Exec. Powers', icon: HandFist },
    { key: 'ledger', label: 'Ledger', icon: TrendUp },
] as const;

type NavKey = (typeof NAV)[number]['key'];

// ─────────────────────────────────────────────────────────────────────────────
// Budget sector definitions
// ─────────────────────────────────────────────────────────────────────────────

const BUDGET_SECTORS = [
    {
        key: 'budget_law' as const,
        label: 'Law Enforcement',
        icon: Shield,
        img: 'https://images.thedirector.app/Careers/enforcement.jpg',
        desc: ' ≥50%: crime drifts. ≥75%: crime drifts faster. 100%: Crime drifts fastest. 25–49%: no drift. <25%: crime rises.Corporate Audit also requires ≥ 50%',
    },
    {
        key: 'budget_corp_reg' as const,
        label: 'Corp Regulation',
        icon: Buildings,
        img: 'https://images.thedirector.app/Careers/corporateregulation.png',
        desc: '≥50%: corporate tax rate applies once corp regulation is active. 25–49%: neutral, no effect either way. <25%: corp members pay less income tax',
    },
    {
        key: 'budget_services' as const,
        label: 'Public Services',
        icon: Handshake,
        img: 'https://images.thedirector.app/Careers/publicservices.jpg',
        desc: '≥50%: tax revenue increased. 25–49%: no change. <25%: tax revenue decreased.',
    },
    {
        key: 'budget_bonds' as const,
        label: 'Municipal Bonds',
        icon: TrendUp,
        img: 'https://images.thedirector.app/Careers/municipalbonds.jpg',
        desc: '≥50%: bond issuance unlocked. Dropping below 50% while a bond is active causes immediate default on next page visit — loses principal and assembly score',
    },
] as const;

// ─────────────────────────────────────────────────────────────────────────────
// Overview
// ─────────────────────────────────────────────────────────────────────────────

function Overview({ term, crimeRate }: { term: MayorTerm; crimeRate: number }) {
    const totalThisPeriod = term.period_income.tax + term.period_income.fine
        + term.period_income.audit + term.period_income.bond;

    const stats = [
        {
            label: 'Treasury',
            value: fmt$(term.city_funds),
            accent: 'text-emerald-400',
            bar: 'bg-emerald-400/70',
            sub: 'Operational capital',
        },
        {
            label: 'Assembly',
            value: `${term.assembly_score}`,
            accent: asmColor(term.assembly_score),
            bar: term.assembly_score > 75 ? 'bg-emerald-400/70' : term.assembly_score >= 60 ? 'bg-amber-400/70' : 'bg-rose-400/70',
            sub: asmLabel(term.assembly_score),
        },
        {
            label: 'Crime',
            value: `${crimeRate}%`,
            accent: crimeColor(crimeRate),
            bar: crimeRate > 55 ? 'bg-rose-400/70' : crimeRate > 35 ? 'bg-amber-400/70' : 'bg-emerald-400/70',
            sub: crimeLabel(crimeRate),
        },
        {
            label: 'Period',
            value: `${term.period} / 4`,
            accent: 'text-white',
            bar: 'bg-white/60',
            sub: term.period === 4 ? 'Final period' : `${4 - term.period} remaining`,
        },
    ];

    return (
        <div className="space-y-5">
            {/* Assembly danger warning. Removal fires at <= 50, so the
                warning band is 51-65: close enough that one bad period
                ends the term. Below ~55 it is genuinely critical. */}
            {term.assembly_score <= 65 && (
                <div className="flex items-center gap-4 px-5 py-4 rounded-2xl bg-rose-500/5 border border-rose-500/20">
                    <SealWarning size={22} weight="fill" className="text-rose-400 animate-pulse shrink-0" />
                    <div>
                        <p className="text-xs font-black text-rose-400 uppercase tracking-widest leading-none mb-1">
                            {term.assembly_score <= 55 ? 'Critical — Removal Imminent' : 'Warning — Assembly Slipping'}
                        </p>
                        <p className="text-[11px] text-rose-300/60 font-medium">
                            You are removed from office the moment Assembly hits 50. You are at {term.assembly_score}.
                            {term.assembly_score <= 55
                                ? ' A single negative period ends your term — restore confidence now.'
                                : ' One mismanaged period could end your term. Stabilize your finances and crime rate.'}
                        </p>
                    </div>
                </div>
            )}



            {/* KPI grid */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                {stats.map((s, i) => {
                    return (
                        <motion.div
                            key={s.label}
                            initial={{ opacity: 0, scale: 0.95 }}
                            animate={{ opacity: 1, scale: 1 }}
                            transition={{ delay: i * 0.04 }}
                            className="relative bg-slate-900/40 border border-white/[0.03] rounded-2xl p-4 hover:border-white/[0.08] transition-all hover:bg-slate-900/60 overflow-hidden"
                        >
                            <div className={`absolute inset-y-4 left-0 w-px ${s.bar}`} />
                            <p className="text-[10px] font-black text-white uppercase tracking-[0.2em] mb-2 leading-none">{s.label}</p>
                            <p className={`text-2xl md:text-3xl font-black tabular-nums leading-none tracking-tight ${s.accent}`}>{s.value}</p>
                            <p className="text-[10px] text-white/60 mt-1.5 font-bold uppercase tracking-wider">{s.sub}</p>
                        </motion.div>
                    );
                })}
            </div>

            {/* Revenue + Policy summary */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">

                {/* Revenue */}
                <div className="bg-slate-900/40 border border-white/[0.03] rounded-2xl p-5">
                    <div className="flex items-center gap-3 mb-4">
                        <div className="w-9 h-9 rounded-xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center shrink-0">
                            <Receipt size={16} className="text-cyan-400" />
                        </div>
                        <div>
                            <h3 className="text-xs font-black text-white uppercase tracking-wider leading-none mb-0.5">Revenue</h3>
                            <p className="text-[10px] text-cyan-400/80 font-bold uppercase tracking-widest">This period</p>
                        </div>
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                        {[
                            { label: 'Income', value: term.period_income.tax, color: 'text-white' },
                            { label: 'Fine Revenue', value: term.period_income.fine, color: 'text-white' },
                            { label: 'Period Total', value: totalThisPeriod, color: 'text-cyan-400' },
                        ].map(item => (
                            <div key={item.label} className="min-h-[88px] rounded-xl border border-white/[0.04] bg-slate-950/45 px-3 py-3">
                                <span className="block text-[10px] font-black text-white uppercase tracking-wider">{item.label}</span>
                                <span className={`mt-3 block text-lg font-black tabular-nums ${item.color}`}>{fmt$(item.value)}</span>
                                <span className="mt-1 block text-[9px] font-medium uppercase tracking-wider text-white/45">This period</span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Active policies */}
                <div className="bg-slate-900/40 border border-white/[0.03] rounded-2xl p-5">
                    <div className="flex items-center gap-3 mb-4">
                        <div className="w-9 h-9 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center shrink-0">
                            <IdentificationCard size={16} className="text-amber-400" />
                        </div>
                        <div>
                            <h3 className="text-xs font-black text-white uppercase tracking-wider leading-none mb-0.5">Policies</h3>
                            <p className="text-[10px] text-amber-400/75 font-bold uppercase tracking-widest">Current term</p>
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        {[
                            {
                                label: 'Corp Regulation',
                                active: term.corp_regulation_active,
                                activeLabel: 'ENABLED',
                                inactiveLabel: 'INACTIVE — costs $25k',
                            },
                            {
                                label: 'Municipal Bonds',
                                active: term.has_active_bond,
                                activeLabel: term.bond_yield ? `LIVE — ${term.bond_yield.toFixed(1)}% yield` : 'ACTIVE',
                                inactiveLabel: 'NO ACTIVE BOND',
                            },
                            {
                                label: 'Capital Punishment',
                                active: term.death_sentence_active,
                                activeLabel: 'ENACTED',
                                inactiveLabel: 'INACTIVE — costs $100k',
                                danger: true,
                            },
                        ].map(p => (
                            <div key={p.label} className="flex items-center justify-between px-3 py-2 bg-slate-950/40 rounded-xl border border-white/[0.02]">
                                <span className="text-[10px] text-white font-bold uppercase tracking-wider">{p.label}</span>
                                <span className={`text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full ${p.active
                                    ? p.danger
                                        ? 'bg-rose-500/15 text-rose-400'
                                        : 'bg-emerald-500/15 text-emerald-400'
                                    : 'bg-white/5 text-white/50'
                                    }`}>
                                    {p.active ? p.activeLabel : p.inactiveLabel}
                                </span>
                            </div>
                        ))}
                        <div className="flex items-center justify-between px-3 py-2 bg-slate-950/40 rounded-xl border border-white/[0.02]">
                            <span className="text-[10px] text-white font-bold uppercase tracking-wider">Case Suppressions</span>
                            <span className={`text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full ${term.suppressions_used > 0
                                ? 'bg-orange-500/15 text-orange-400'
                                : 'bg-white/5 text-white/50'
                                }`}>
                                {term.suppressions_used > 0 ? `${term.suppressions_used} USED` : 'UNUSED'}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            {/* Budget snapshot */}
            <div className="bg-slate-900/40 border border-white/[0.03] rounded-2xl p-5">
                <div className="flex items-center justify-between mb-3">
                    <div className="flex items-center gap-2">
                        <h3 className="text-[10px] font-black text-white uppercase tracking-widest">Budget Snapshot</h3>
                        <Hint text="Budget allocations affect crime drift, tax multiplier, and which executive actions are available. Go to the Budget tab to adjust." />
                    </div>
                    <span className="text-[9px] text-white/45 font-bold uppercase tracking-widest">Must sum to 100</span>
                </div>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                    {BUDGET_SECTORS.map(s => {
                        const val = term[s.key];
                        const tier = budgetTier(val);
                        return (
                            <div key={s.key} className="text-center p-3 bg-slate-950/40 rounded-xl border border-white/[0.02]">
                                <p className="text-[9px] text-white font-black uppercase tracking-widest mb-1">{s.label}</p>
                                <p className="text-2xl font-black text-white tabular-nums">{val}%</p>
                                <p className={`text-[9px] font-black uppercase tracking-widest mt-1 ${tier.color}`}>{tier.label}</p>
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Budget
// ─────────────────────────────────────────────────────────────────────────────

function Budget({ term }: { term: MayorTerm }) {
    const [alloc, setAlloc] = useState({
        budget_law: term.budget_law,
        budget_corp_reg: term.budget_corp_reg,
        budget_services: term.budget_services,
        budget_bonds: term.budget_bonds,
    });
    const [busy, setBusy] = useState(false);

    const total = Object.values(alloc).reduce((s, v) => s + v, 0);
    const remaining = 100 - total;
    const valid = remaining === 0;

    const adjust = (key: keyof typeof alloc, delta: number) => {
        setAlloc(prev => {
            const next = prev[key] + delta;
            if (next < 0 || next > 100) return prev;
            if (delta > 0 && remaining <= 0) return prev;
            return { ...prev, [key]: next };
        });
    };

    const save = () => {
        if (!valid || busy) return;
        setBusy(true);
        router.post(route('career.politics.budget'), alloc, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="space-y-5">
            <div className="flex items-center justify-between px-1">
                <div>
                    <h2 className="text-sm font-black text-white uppercase tracking-widest leading-none mb-1">Sector Allocation</h2>

                </div>
                <div className={`px-4 py-2 rounded-2xl border-2 text-sm font-black tabular-nums transition-all ${valid
                    ? 'border-emerald-500/20 bg-emerald-500/5 text-emerald-400'
                    : remaining > 0
                        ? 'border-cyan-500/20 bg-cyan-500/5 text-cyan-400'
                        : 'border-rose-500/20 bg-rose-500/5 text-rose-400'
                    }`}>
                    {valid ? 'STABLE' : `${remaining > 0 ? '+' : ''}${remaining}`}
                </div>
            </div>

            <div className="grid grid-cols-1 gap-2">
                {BUDGET_SECTORS.map(s => {
                    const val = alloc[s.key];
                    const tier = budgetTier(val);
                    return (
                        <div
                            key={s.key}
                            className="group relative h-24 rounded-2xl border border-white/5 hover:border-white/10 transition-all duration-300"
                        >

                            <div className="absolute inset-0 rounded-2xl overflow-hidden pointer-events-none">
                                <img
                                    src={s.img}
                                    alt=""
                                    className="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover:scale-105"
                                    style={{ filter: 'brightness(0.8) contrast(1.1) saturate(1.2)' }}
                                />
                                <div className="absolute inset-0 bg-gradient-to-r from-slate-950 via-slate-950/40 to-transparent" />
                            </div>

                            <div className="relative h-full flex items-center justify-between px-6">
                                <div className="space-y-0.5">
                                    <div className="flex items-center gap-2">
                                        <h3 className="text-base font-light text-white tracking-wide">{s.label}</h3>
                                        <Hint text={s.desc} />
                                    </div>
                                    <span className={`text-[10px] font-black uppercase tracking-widest ${tier.color}`}>{tier.label}</span>
                                </div>

                                <div className="flex items-center gap-3 bg-black/40 backdrop-blur-md p-1.5 rounded-2xl border border-white/5">
                                    <button
                                        onClick={() => adjust(s.key, -5)}
                                        disabled={val <= 0}
                                        className="w-9 h-9 rounded-xl bg-slate-900 border border-white/5 flex items-center justify-center hover:bg-slate-800 disabled:opacity-20 transition-all text-white"
                                    >
                                        <Minus size={13} weight="bold" />
                                    </button>
                                    <div className="w-14 text-center">
                                        <p className="text-xl font-black text-white tabular-nums leading-none">{val}%</p>
                                    </div>
                                    <button
                                        onClick={() => adjust(s.key, 5)}
                                        disabled={val >= 100 || remaining <= 0}
                                        className="w-9 h-9 rounded-xl bg-slate-900 border border-white/5 flex items-center justify-center hover:bg-slate-800 disabled:opacity-20 transition-all text-white"
                                    >
                                        <Plus size={13} weight="bold" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>

            <button
                onClick={save}
                disabled={!valid || busy}
                className={`w-full py-3.5 rounded-2xl text-[11px] font-black uppercase tracking-[0.25em] flex items-center justify-center gap-3 transition-all border-2 ${valid && !busy
                    ? 'border-cyan-500/20 bg-cyan-500/10 text-cyan-400 hover:border-cyan-500/40 hover:bg-cyan-500/20'
                    : 'border-slate-800 bg-slate-900 text-slate-600 cursor-not-allowed'
                    }`}
            >
                {busy ? (
                    <div className="w-4 h-4 border-2 border-cyan-400/20 border-t-cyan-400 rounded-full animate-spin" />
                ) : !valid ? (
                    <><Warning size={13} weight="bold" className="text-rose-500" /> {remaining > 0 ? `${remaining} points unallocated` : `${Math.abs(remaining)} points over budget`}</>
                ) : (
                    <><Scales size={13} weight="bold" /> Ratify Budget</>
                )}
            </button>
        </div>
    );
}



function Taxes({ term }: { term: MayorTerm }) {
    const [income, setIncome] = useState(term.income_tax_rate);
    const [corp, setCorp] = useState(term.corporate_tax_rate);
    const [busy, setBusy] = useState(false);

    const save = () => {
        if (busy) return;
        setBusy(true);
        router.post(
            route('career.politics.taxes'),
            { income_tax_rate: income, corporate_tax_rate: corp },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const corpUnlocked = term.corp_regulation_active && term.budget_corp_reg >= 50;

    return (
        <div className="space-y-8">
            <TaxRow label="Income Tax" value={income} onChange={setIncome} min={5} max={25} />
            <TaxRow label="Corporate Tax" value={corp} onChange={setCorp} min={0} max={25} disabled={!corpUnlocked} />

            <div className="flex justify-end">
                <button
                    onClick={save}
                    disabled={busy}
                    className="inline-flex items-center justify-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/5 px-5 py-2.5 text-[10px] font-black uppercase tracking-[0.22em] text-emerald-400 transition-all hover:bg-emerald-500/10 disabled:opacity-40"
                >
                    {busy ? (
                        <div className="h-3 w-3 rounded-full border-2 border-current/30 border-t-current animate-spin" />
                    ) : (
                        <><Scales size={13} weight="bold" /> Commit Tax Rates</>
                    )}
                </button>
            </div>
        </div>
    );
}

function TaxRow({
    label, value, onChange, min, max, disabled = false,
}: {
    label: string;
    value: number;
    onChange: (v: number) => void;
    min: number;
    max: number;
    disabled?: boolean;
}) {
    const pct = max > min ? ((value - min) / (max - min)) * 100 : 0;
    return (
        <div className={`flex items-center gap-6 ${disabled ? 'opacity-30 pointer-events-none' : ''}`}>
            <h3 className="w-40 shrink-0 text-sm font-black text-white uppercase tracking-widest">{label}</h3>
            <input
                type="range"
                min={min}
                max={max}
                value={value}
                disabled={disabled}
                onChange={e => onChange(parseInt(e.target.value))}
                className="tax-slider flex-1"
                style={{ ['--pct' as any]: `${pct}%`, ['--accent' as any]: '#34d399' }}
            />
            <span className="w-16 text-right text-2xl font-black tabular-nums text-white">{value}%</span>
        </div>
    );
}


function buildDismissOptions(pickerData?: Props['pickerData']): { value: string; label: string; isComm: boolean }[] {
    const officers = (pickerData?.police_officers || []).map(o => ({ ...o, isComm: false }));
    const comm = (pickerData?.commissioner || []).map(c => ({ ...c, isComm: true }));
    return [...comm, ...officers];
}

function Powers({ term, pickerData }: { term: MayorTerm; pickerData?: Props['pickerData'] }) {
    const [busyAction, setBusyAction] = useState<string | null>(null);
    const [bondPrincipal, setBondPrincipal] = useState(50_000);
    const [selectedDismiss, setSelectedDismiss] = useState<string>('');
    const [selectedPardon, setSelectedPardon] = useState<string>('');
    const [selectedSuppress, setSelectedSuppress] = useState<string>('');
    const [selectedCommand, setSelectedCommand] = useState<string>('corp-regulation');

    const dismissOptions = buildDismissOptions(pickerData);
    const selectedDismissEntry = dismissOptions.find(o => o.value === selectedDismiss);
    const isCommissioner = selectedDismissEntry?.isComm ?? false;

    const act = (routeName: string, data: Record<string, any> = {}, actionId: string) => {
        if (busyAction) return;
        setBusyAction(actionId);
        router.post(route(routeName), data, {
            preserveScroll: true,
            onFinish: () => setBusyAction(null),
        });
    };

    const handleDismiss = () => {
        if (!selectedDismiss) return;
        const routeName = isCommissioner
            ? 'career.politics.dismiss-commissioner'
            : 'career.politics.dismiss-officer';
        act(routeName, { character_id: parseInt(selectedDismiss) }, 'Dismiss Personnel');
    };

    const maxBond = Math.floor(term.city_funds * 0.4);

    const commands = [
        {
            key: 'corp-regulation',
            icon: Buildings,
            title: 'Corp Regulation',
            cost: '$25,000',
            body: 'Activate municipal oversight. Required for corporate taxation and auditing.',
            locked: term.corp_regulation_active || term.budget_corp_reg < 25,
            lockNote: term.corp_regulation_active ? 'Already Active' : 'Corp Reg budget < 25%',
            action: 'INITIALIZE',
            onAct: () => act('career.politics.enable-corp-reg', {}, 'Corp Regulation'),
        },
        {
            key: 'corporate-audit',
            icon: Shield,
            title: 'Corporate Audit',
            cost: '$75,000',
            body: 'Deep-state audit on the largest corp in the city. Recovers 5–15% of their slush fund.',
            locked: !term.corp_regulation_active || term.audit_in_progress || term.budget_corp_reg < 50,
            lockNote: term.audit_in_progress ? 'Audit In Progress' : !term.corp_regulation_active ? 'Corp Regulation inactive' : 'Corp Reg budget < 50%',
            action: 'AUTHORIZE',
            onAct: () => act('career.politics.audit', {}, 'Corporate Audit'),
        },
        {
            key: 'municipal-bond',
            icon: TrendUp,
            title: 'Municipal Bond',
            cost: 'Variable · 21h',
            body: 'Issue a bond for immediate capital. Principal + yield returned after 21h (half a period).',
            locked: term.budget_bonds < 50 || term.has_active_bond || maxBond < 50_000,
            lockNote: term.has_active_bond ? 'Bond Outstanding' : term.budget_bonds < 50 ? 'Bonds budget < 50%' : 'Treasury too low',
            action: 'ISSUE',
            input: (
                <div className="space-y-1.5 mb-3">
                    <div className="flex items-center justify-between">
                        <p className="text-[9px] text-white/70 font-bold uppercase tracking-widest">Principal</p>
                        <p className="text-[9px] text-cyan-400/75 font-bold uppercase tracking-widest">Max {fmt$(maxBond)}</p>
                    </div>
                    <div className="flex items-center gap-2 bg-black/40 px-2 py-1.5 rounded-xl border border-white/5">
                        <button onClick={() => setBondPrincipal(p => Math.max(50_000, p - 10_000))} className="w-7 h-7 rounded-lg bg-slate-900 border border-white/5 flex items-center justify-center text-white hover:bg-slate-800">
                            <Minus size={10} />
                        </button>
                        <span className="flex-1 text-center text-xs font-black text-white tabular-nums">{fmt$(bondPrincipal)}</span>
                        <button onClick={() => setBondPrincipal(p => Math.min(maxBond, p + 10_000))} className="w-7 h-7 rounded-lg bg-slate-900 border border-white/5 flex items-center justify-center text-white hover:bg-slate-800">
                            <Plus size={10} />
                        </button>
                    </div>
                </div>
            ),
            onAct: () => act('career.politics.bond', { principal: bondPrincipal }, 'Municipal Bond'),
        },
        {
            key: 'suppress-case',
            icon: Gavel,
            title: 'Suppress Case',
            cost: '$50,000',
            body: 'Redact a case from the police queue.',
            action: 'REDACT',
            input: (
                <div className="mb-3">
                    <Picker
                        options={pickerData?.active_cases || []}
                        value={selectedSuppress}
                        onChange={setSelectedSuppress}
                        placeholder="Select Case"
                    />
                </div>
            ),
            onAct: () => act('career.politics.suppress', { case_id: parseInt(selectedSuppress) }, 'Suppress Case'),
        },
        {
            key: 'executive-pardon',
            icon: Handshake,
            title: 'Executive Pardon',
            cost: '$75,000 · 10h CD',
            body: 'Clear a criminal record and release the target if jailed.',
            locked: !term.pardon_cooldown_passed,
            lockNote: 'Cooldown Active (10h)',
            action: 'PARDON',
            input: (
                <div className="mb-3">
                    <Picker
                        options={pickerData?.pardon_targets || []}
                        value={selectedPardon}
                        onChange={setSelectedPardon}
                        placeholder="Select Character"
                    />
                </div>
            ),
            onAct: () => act('career.politics.pardon', { character_id: parseInt(selectedPardon) }, 'Executive Pardon'),
        },
        {

            key: 'dismiss-personnel',
            icon: UserMinus,
            title: 'Dismiss Personnel',
            cost: isCommissioner ? '$150,000 · Rank 3 req.' : selectedDismiss ? '$50,000' : '$50k–$150k',
            body: isCommissioner
                ? 'Oust the Commissioner. Requires a qualified rank-3 successor in the city.'
                : 'Forcibly terminate a rank 1–3 officer from the police force ($50k). Select the Commissioner to oust them instead ($150k, requires successor).',
            action: isCommissioner ? 'OUST' : 'TERMINATE',
            input: (
                <div className="mb-3">
                    <Picker
                        options={dismissOptions}
                        value={selectedDismiss}
                        onChange={setSelectedDismiss}
                        placeholder="Select Officer or Commissioner"
                    />
                </div>
            ),
            onAct: handleDismiss,
        },
        {
            key: 'capital-punishment',
            icon: SealWarning,
            title: 'Capital Punishment',
            cost: '$100,000 · Irreversible',
            body: 'Enact lethal sentencing for top-tier felonies. Permanent for the term.',
            locked: term.death_sentence_active,
            lockNote: 'Sanction Active',
            action: 'ENACT',
            onAct: () => act('career.politics.death-sentence', {}, 'Capital Punishment'),
        },
    ];

    const activeCommand = commands.find(command => command.key === selectedCommand) ?? commands[0];
    const activeLocked = 'locked' in activeCommand && activeCommand.locked;

    return (
        <div className="space-y-5">
            <div className="px-1">
                <h2 className="text-sm font-black text-white uppercase tracking-widest leading-none mb-1">Executive Commands</h2>

            </div>

            <div className="grid gap-4 lg:grid-cols-[260px_minmax(0,1fr)]">
                <div className="rounded-2xl border border-white/[0.04] bg-slate-950/35 p-2">
                    {commands.map(command => {
                        const Icon = command.icon;
                        const isActive = command.key === activeCommand.key;
                        const isLocked = 'locked' in command && command.locked;

                        return (
                            <button
                                key={command.key}
                                type="button"
                                onClick={() => setSelectedCommand(command.key)}
                                className={`flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left transition-all ${isActive
                                    ? 'bg-white/[0.06] text-white'
                                    : 'text-white/55 hover:bg-white/[0.03] hover:text-white'
                                    }`}
                            >
                                <Icon size={15} weight={isActive ? 'fill' : 'duotone'} className={isActive ? 'text-cyan-400' : 'text-white/40'} />
                                <span className="min-w-0 flex-1 text-[10px] font-black uppercase tracking-[0.18em]">
                                    {command.title}
                                </span>
                                {isLocked && <Lock size={11} weight="fill" className="text-white/30" />}
                            </button>
                        );
                    })}
                </div>

                <section className="rounded-2xl border border-white/[0.04] bg-slate-900/35 p-5 md:p-6">
                    <div className="flex flex-col gap-5 md:flex-row md:items-start md:justify-between">
                        <div className="max-w-2xl">
                            <p className="text-[11px] font-medium leading-relaxed text-white/75">{activeCommand.body}</p>
                        </div>

                        <div className="rounded-xl border border-white/[0.04] bg-slate-950/45 px-4 py-3 md:min-w-[170px]">
                            <p className="text-[9px] font-black uppercase tracking-[0.2em] text-white/45">Cost</p>
                            <p className="mt-1 text-sm font-black text-white">{activeCommand.cost}</p>
                        </div>
                    </div>

                    <div className="mt-6 border-t border-white/[0.06] pt-5">
                        {!activeLocked && 'input' in activeCommand && activeCommand.input}

                        {activeLocked ? (
                            <div className="flex items-center justify-center gap-2 rounded-xl border border-white/[0.04] bg-slate-950/45 px-4 py-3">
                                <Lock size={12} className="text-white/35" weight="fill" />
                                <span className="text-[10px] font-black uppercase tracking-widest text-white/45">{(activeCommand as any).lockNote}</span>
                            </div>
                        ) : (
                            <div className="flex justify-end">
                                <button
                                    onClick={activeCommand.onAct}
                                    disabled={!!busyAction}
                                    className="inline-flex min-w-[140px] items-center justify-center gap-2 rounded-xl border border-cyan-500/25 bg-cyan-500/10 px-5 py-2.5 text-[10px] font-black uppercase tracking-[0.22em] text-cyan-300 transition-all hover:border-cyan-400/45 hover:bg-cyan-500/15 disabled:opacity-45"
                                >
                                    {busyAction === activeCommand.title ? (
                                        <div className="h-3 w-3 rounded-full border-2 border-current/30 border-t-current animate-spin" />
                                    ) : (
                                        <><ArrowRight size={11} weight="bold" /> {activeCommand.action}</>
                                    )}
                                </button>
                            </div>
                        )}
                    </div>
                </section>
            </div>
        </div>
    );
}



function Ledger({ ledger, currentPeriod }: { ledger: LedgerEntry[]; currentPeriod: number }) {
    if (ledger.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-16 gap-4">

                <div className="text-center space-y-1">

                    <p className="text-[10px] text-slate-700 font-bold uppercase tracking-widest">No periods settled yet · period {currentPeriod} is in progress</p>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-5">
            <div className="px-1">
                <h2 className="text-sm font-black text-white uppercase tracking-widest leading-none mb-1">Period Ledger</h2>

            </div>

            <div className="space-y-3">
                {[...ledger].reverse().map((e, i) => (
                    <motion.div
                        key={e.period}
                        initial={{ opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ delay: i * 0.05 }}
                        className="bg-slate-900/40 border border-white/[0.03] rounded-2xl overflow-hidden hover:border-white/10 transition-all"
                    >
                        <div className="flex items-center justify-between px-5 py-3 bg-slate-950/60 border-b border-white/[0.02]">
                            <div className="flex items-center gap-3">
                                <div className="w-7 h-7 rounded-lg bg-white/[0.03] border border-white/5 flex items-center justify-center">
                                    <span className="text-[10px] font-black text-white">{e.period}</span>
                                </div>
                                <span className="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Period {e.period} Settlement</span>
                            </div>
                            <span className={`text-lg font-black tabular-nums tracking-tighter ${e.net >= 0 ? 'text-emerald-400' : 'text-rose-400'}`}>
                                {e.net >= 0 ? '+' : ''}{fmt$(e.net)}
                            </span>
                        </div>
                        <div className="p-5 grid grid-cols-2 sm:grid-cols-4 gap-4">
                            {[
                                { l: 'Tax Revenue', v: e.tax_in, c: 'text-emerald-400', raw: false },
                                { l: 'Audit Yield', v: e.audit_in, c: 'text-amber-400', raw: false },
                                { l: 'Crime Drain', v: -e.crime_drain, c: 'text-rose-400', raw: false },
                                { l: 'Assembly Δ', v: e.assembly_delta, c: e.assembly_delta >= 0 ? 'text-emerald-400' : 'text-rose-400', raw: true },
                            ].map(r => (
                                <div key={r.l} className="space-y-0.5">
                                    <p className="text-[9px] font-black text-slate-500 uppercase tracking-widest">{r.l}</p>
                                    <p className={`text-sm font-black tabular-nums ${r.c}`}>
                                        {r.raw
                                            ? (r.v >= 0 ? `+${r.v}` : `${r.v}`)
                                            : (r.v >= 0 ? `+${fmt$(r.v)}` : fmt$(r.v))
                                        }
                                    </p>
                                </div>
                            ))}
                        </div>
                    </motion.div>
                ))}
            </div>
        </div>
    );
}


export default function MayoralChambers({ city, term, mayor_name, crime_rate, pickerData }: Props) {
    const heroImage = getCityImage(city.name, city.image_url);
    const [active, setActive] = useState<NavKey>(
        term.period === 1 && term.ledger.length === 0 ? 'briefing' : 'overview'
    );
    const [resign, setResign] = useState(false);
    const [resigning, setResigning] = useState(false);
    const doResign = () => {
        if (resigning) return;
        setResigning(true);
        router.post(route('career.politics.resign'), {}, {
            onFinish: () => setResigning(false),
        });
    };

    const panels: Record<NavKey, React.ReactNode> = {
        overview: <Overview term={term} crimeRate={crime_rate} />,
        briefing: <MayorBriefing />,
        budget: <Budget term={term} />,
        taxes: <Taxes term={term} />,
        powers: <Powers term={term} pickerData={pickerData} />,
        ledger: <Ledger ledger={term.ledger} currentPeriod={term.period} />,
    };

    return (
        <>
            <Head title={`Mayor — ${city.name}`} />

            <div className="w-full max-w-6xl mx-auto flex flex-col px-4 py-6 md:py-8">


                <div className="relative h-[140px] md:h-[180px] rounded-2xl overflow-hidden border border-white/5 shadow-xl mb-5 group">
                    <img
                        src={heroImage}
                        alt={city.name}
                        className="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform ease-out"
                        style={{ filter: 'brightness(0.55) contrast(1.1)', transitionDuration: '3000ms' }}
                    />
                    <div className="absolute inset-0 bg-gradient-to-t from-[#020617] via-[#020617]/30 to-transparent" />
                    <div className="absolute inset-0 bg-gradient-to-r from-[#020617]/80 via-transparent to-transparent" />

                    <div className="relative flex h-full items-end px-6 pb-4 md:px-8">
                        <div>
                            <h1 className="text-2xl md:text-3xl font-black text-white tracking-tighter uppercase leading-none italic [text-shadow:0_4px_16px_rgba(0,0,0,0.6)]">
                                {city.name} <span className="text-cyan-300">Chambers</span>
                            </h1>
                            <p className="mt-1.5 text-[11px] text-white/75 font-bold uppercase tracking-widest border-l-2 border-cyan-500/50 pl-3">
                                Mayor: <span className="text-white">{mayor_name}</span>
                            </p>
                        </div>
                    </div>
                </div>


                <div className="flex flex-wrap justify-center gap-1.5 mb-5">
                    {NAV.map(n => {
                        const Icon = n.icon;
                        const isActive = active === n.key;
                        return (
                            <button
                                key={n.key}
                                onClick={() => setActive(n.key)}
                                className={`group flex items-center gap-2 px-5 py-3 rounded-xl text-[11px] font-black uppercase tracking-[0.18em] transition-all ${isActive
                                    ? 'bg-white/5 text-white border border-white/10'
                                    : 'text-white/55 hover:text-white border border-transparent'
                                    }`}
                            >
                                <Icon size={13} weight={isActive ? 'fill' : 'duotone'} className={isActive ? 'text-cyan-400' : 'group-hover:text-slate-200'} />
                                {n.label}
                            </button>
                        );
                    })}
                </div>

                <div className="relative overflow-hidden">
                    <AnimatePresence mode="popLayout" initial={false}>
                        <motion.div
                            key={active}
                            initial={{ opacity: 0, y: 6 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: -4 }}
                            transition={{ duration: 0.12, ease: 'easeOut' }}
                        >
                            <div className="bg-slate-900/20 backdrop-blur-md rounded-2xl border border-white/[0.03] p-5 md:p-8 shadow-2xl">
                                {panels[active]}
                            </div>
                        </motion.div>
                    </AnimatePresence>
                </div>


                <div className="mt-5 flex justify-end">
                    {!resign ? (
                        <button
                            onClick={() => setResign(true)}
                            className="flex items-center gap-2 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest text-slate-600 hover:text-rose-400 border border-transparent hover:border-rose-500/20 hover:bg-rose-500/5 transition-all"
                        >
                            <SignOut size={12} weight="bold" />
                            Resign from Office
                        </button>
                    ) : (
                        <div className="flex items-center gap-3 px-4 py-2.5 rounded-xl bg-rose-500/5 border border-rose-500/20">
                            <SealWarning size={15} className="text-rose-400 shrink-0" />
                            <span className="text-[11px] text-rose-300 font-bold">Resign and trigger new election?</span>
                            <button
                                onClick={doResign}
                                disabled={resigning}
                                className="px-3 py-1.5 rounded-lg bg-rose-500/20 border border-rose-500/30 text-rose-400 text-[10px] font-black uppercase tracking-widest hover:bg-rose-500/30 disabled:opacity-40 transition-all"
                            >
                                {resigning ? 'Resigning…' : 'Confirm'}
                            </button>
                            <button
                                onClick={() => setResign(false)}
                                className="px-3 py-1.5 rounded-lg bg-white/5 border border-white/5 text-slate-400 text-[10px] font-black uppercase tracking-widest hover:bg-white/10 transition-all"
                            >
                                Cancel
                            </button>
                        </div>
                    )}
                </div>

            </div>

        </>
    );
}

MayoralChambers.layout = (page: React.ReactNode) => <GameLayout wide children={page} />;
