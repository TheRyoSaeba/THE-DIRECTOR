import { useState, useEffect, useRef } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import { motion, AnimatePresence } from "framer-motion";
// @ts-ignore
import { route } from "ziggy-js";
import {
    Crown, Users, Trophy, CurrencyDollar, CheckCircle, XCircle,
    Warning, CircleNotch, Article, Plus, HandPointing, Timer,
    MapPin, Scales, ArrowRight, Star, Diamond,
} from "@phosphor-icons/react";
import GameLayout from "@/Layouts/GameLayout";
import { getCityImage } from "@/utils/cityImages";
import { formatCash, formatUTC } from "@/Layouts/GameLayoutComponents";

// ─── Types ───────────────────────────────────────────────────────────────────

interface Candidate {
    campaign_id: number;
    display_name: string;
    avatar_url: string | null;
    manifesto: string;
    // campaign_fund is intentionally absent — never sent from server
}

interface ElectionData {
    id: number;
    cycle: number;
    status: "registration" | "voting" | "completed";
    registration_ends: string;
    voting_starts: string | null;
    voting_ends: string | null;
    has_voted: boolean;
    max_candidates: number;
    is_home_city: boolean;
    // Only present for the authenticated player's own campaign
    my_campaign_fund: number | null;
    candidates: Candidate[];
}

interface RecentResult {
    cycle: number;
    ended_at: string;
    winner: string;
    term_end: string;
    total_votes: number;
    candidates: { display_name: string; votes: number; vote_percentage: number; won: boolean }[];
}

interface CanApply {
    can_apply: boolean;
    errors: string[];
}

interface ElectionConstants {
    application_fee: number;
    min_rank_required: number;
    registration_days: number;
    voting_days: number;
    term_duration_days: number;
}

interface Props {
    city: { id: number; name: string; slug: string; current_mayor: string | null };
    election: ElectionData | null;
    recent_result: RecentResult | null;
    can_apply: CanApply | null;
    needs_election: boolean;
    election_constants: ElectionConstants;
}

// ─── Countdown hook ───────────────────────────────────────────────────────────

function useCountdown(end: string | null | undefined, serverTime: string): number {
    const [remaining, setRemaining] = useState(0);
    useEffect(() => {
        if (!end || !serverTime) { setRemaining(0); return; }
        const serverTs = Math.floor(new Date(serverTime).getTime() / 1000);
        const endTs = Math.floor(new Date(end).getTime() / 1000);
        const initial = Math.max(0, endTs - serverTs);
        setRemaining(initial);
        if (initial <= 0) return;
        const id = setInterval(() => setRemaining(p => {
            if (p <= 1) { clearInterval(id); return 0; }
            return p - 1;
        }), 1000);
        return () => clearInterval(id);
    }, [end, serverTime]);
    return remaining;
}

function fmtRemaining(s: number): string {
    if (s <= 0) return "Ending soon";
    const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600),
        m = Math.floor((s % 3600) / 60), sec = s % 60;
    if (d > 0) return `${d}d ${h}h`;
    if (h > 0) return `${h}h ${m}m`;
    if (m > 0) return `${m}m ${sec}s`;
    return `${sec}s`;
}

// ─── Phase badge ─────────────────────────────────────────────────────────────

function PhaseBadge({ status }: { status: string }) {
    const styles: Record<string, string> = {
        registration: "bg-sky-500/15 text-sky-300 border-sky-500/30",
        voting: "bg-emerald-500/15 text-emerald-300 border-emerald-500/30",
        completed: "bg-slate-700/40 text-slate-400 border-slate-600/30",
    };
    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full border text-[10px] font-semibold uppercase tracking-wider ${styles[status] ?? styles.completed}`}>
            {status}
        </span>
    );
}

// ─── Winner banner — Pachinko-style confetti celebration ─────────────────────

function WinnerBanner({ result, onDismiss }: { result: RecentResult; onDismiss: () => void }) {
    const confettiCounter = useRef(0);
    const colors = ['#FFD700', '#FFA500', '#FF69B4', '#00FFFF', '#FF4500', '#A855F7', '#34D399'];

    const confetti = Array.from({ length: 60 }, () => ({
        id: confettiCounter.current++,
        left: `${Math.random() * 100}%`,
        delay: `${(Math.random() * 0.6).toFixed(2)}s`,
        color: colors[Math.floor(Math.random() * colors.length)],
    }));

    return (
        <motion.div
            initial={{ opacity: 0, scale: 0.95, y: -16 }}
            animate={{ opacity: 1, scale: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.95, y: -16 }}
            transition={{ type: 'spring', stiffness: 300, damping: 24 }}
            className="mb-4 relative overflow-hidden rounded-xl"
        >
            {/* Animated gradient background */}
            <div className="absolute inset-0 bg-gradient-to-r from-amber-500 via-yellow-400 to-amber-500 animate-gradient-x" />
            <div className="absolute inset-0 bg-[radial-gradient(circle,_white_20%,_transparent_70%)] opacity-30 animate-pulse" />

            {/* Confetti particles */}
            {confetti.map(c => (
                <div
                    key={c.id}
                    className="absolute w-2.5 h-2.5 rounded-full animate-confetti"
                    style={{
                        left: c.left,
                        top: '-12px',
                        backgroundColor: c.color,
                        boxShadow: `0 0 10px ${c.color}`,
                        animationDelay: c.delay,
                    }}
                />
            ))}

            {/* Content */}
            <div className="relative p-5">
                {/* Header row */}
                <div className="flex items-center justify-between mb-4">
                    <motion.div
                        className="flex items-center gap-3"
                        animate={{ scale: [1, 1.05, 1] }}
                        transition={{ duration: 1.5, repeat: Infinity }}
                    >
                        <Diamond className="w-7 h-7 text-slate-900 drop-shadow-[0_0_12px_rgba(255,255,255,0.8)]" weight="fill" />
                        <div>
                            <p className="text-black font-black text-xl uppercase tracking-tighter leading-none">
                                {result.winner}
                            </p>
                            <p className="text-slate-800 font-black text-xs uppercase tracking-[0.3em]">
                                Elected Mayor — Cycle #{result.cycle}
                            </p>
                        </div>
                        <Star className="w-7 h-7 text-slate-900 drop-shadow-[0_0_12px_rgba(255,255,255,0.8)] animate-spin-slow" weight="fill" />
                    </motion.div>

                    <button
                        onClick={onDismiss}
                        className="p-1.5 bg-black/20 hover:bg-black/40 rounded-full text-slate-900 transition shrink-0"
                    >
                        <XCircle className="w-4 h-4" weight="bold" />
                    </button>
                </div>

                {/* Candidate results */}
                <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
                    {result.candidates.map((c, idx) => (
                        <motion.div
                            key={c.display_name}
                            initial={{ opacity: 0, y: 10 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ delay: 0.1 + idx * 0.06 }}
                            className={`px-3 py-2 rounded-lg border font-black text-xs ${c.won
                                    ? 'border-slate-900/40 bg-black/20 text-slate-900'
                                    : 'border-black/20 bg-black/10 text-slate-800'
                                }`}
                        >
                            <div className="flex items-center gap-1.5 mb-0.5">
                                {c.won && <Crown className="w-3 h-3 shrink-0" weight="fill" />}
                                <span className="truncate uppercase tracking-tight">{c.display_name}</span>
                            </div>
                            <div className="font-mono text-[10px] opacity-70">
                                {c.vote_percentage.toFixed(0)}%
                            </div>
                        </motion.div>
                    ))}
                </div>

                <p className="mt-3 text-[10px] text-slate-800 font-bold uppercase tracking-wider">
                    Term ends {formatUTC(result.term_end, false)}
                </p>
            </div>

            <style>{`
                @keyframes gradient-x {
                    0%, 100% { background-position: 0% 50%; }
                    50% { background-position: 100% 50%; }
                }
                .animate-gradient-x {
                    animation: gradient-x 2s ease infinite;
                    background-size: 200% 200%;
                }
                @keyframes confetti {
                    0%   { top: -12px; transform: rotate(0deg);   opacity: 1; }
                    100% { top: 100%;  transform: rotate(720deg); opacity: 0; }
                }
                .animate-confetti {
                    animation: confetti 2.5s ease-out forwards;
                }
                @keyframes spin-slow {
                    from { transform: rotate(0deg); }
                    to   { transform: rotate(360deg); }
                }
                .animate-spin-slow { animation: spin-slow 5s linear infinite; }
            `}</style>
        </motion.div>
    );
}

// ─── Hero header ─────────────────────────────────────────────────────────────

function ElectionHero({
    city, election, constants, timeRemaining,
}: {
    city: Props["city"];
    election: ElectionData | null;
    constants: ElectionConstants;
    timeRemaining: number;
}) {
    const bg = getCityImage(city.name);
    return (
        <div className="relative rounded-2xl overflow-hidden border border-white/5 shadow-2xl mb-4">
            <div className="absolute inset-0">
                <img src={bg} alt={city.name} className="w-full h-full object-cover"
                    style={{ filter: 'brightness(0.45) contrast(1.15) saturate(1.1)' }} />
                <div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/60 to-transparent" />
            </div>

            <div className="relative px-8 pt-16 pb-8 md:px-10 md:pt-20 md:pb-10">
                <div className="max-w-3xl">
                    <div className="flex items-center gap-2 mb-3">
                        <MapPin className="w-4 h-4 text-cyan-400" weight="fill" />
                        <span className="text-[11px] font-semibold uppercase tracking-[0.2em] text-cyan-400/80">
                            Mayoral Election
                        </span>
                    </div>
                    <h1 className="text-4xl md:text-5xl font-light text-white tracking-tight mb-2">
                        {city.name}
                    </h1>
                    <div className="flex items-center gap-3 flex-wrap">
                        {election && <PhaseBadge status={election.status} />}
                        {election && (
                            <span className="text-[11px] text-white font-mono">Cycle #{election.cycle}</span>
                        )}
                        {!election && city.current_mayor && (
                            <span className="text-sm text-slate-400">
                                Mayor: <span className="text-amber-400 font-medium">{city.current_mayor}</span>
                            </span>
                        )}
                    </div>
                </div>

                <div className="mt-8 flex flex-wrap gap-8 text-sm">

                    <div>
                        <div className="text-[10px] uppercase tracking-widest text-white mb-1">Candidates</div>
                        <div className="text-white font-medium flex items-center gap-1.5">
                            <Users className="w-3.5 h-3.5 text-slate-400" />
                            {election?.candidates.length ?? 0} / {election?.max_candidates ?? 3}
                        </div>
                    </div>
                    {timeRemaining > 0 && (
                        <div>
                            <div className="text-[10px] uppercase tracking-widest text-white mb-1">Time Left</div>
                            <div className="text-cyan-400 font-medium flex items-center gap-1.5">
                                <Timer className="w-3.5 h-3.5" />
                                {fmtRemaining(timeRemaining)}
                            </div>
                        </div>
                    )}
                    <div>
                        <div className="text-[10px] uppercase tracking-widest text-white mb-1">Entry Cost</div>
                        <div className="text-emerald-400 font-medium">
                            {formatCash(constants.application_fee)}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

// ─── Candidate avatar ─────────────────────────────────────────────────────────

function CandidateAvatar({ candidate, size = 'md' }: { candidate: Candidate; size?: 'sm' | 'md' }) {
    const dim = size === 'sm' ? 'w-9 h-9 text-sm' : 'w-11 h-11 text-lg';
    return candidate.avatar_url ? (
        <img
            src={candidate.avatar_url}
            alt={candidate.display_name}
            className={`${dim} rounded-lg object-cover shrink-0 border border-slate-700/40`}
        />
    ) : (
        <div className={`${dim} rounded-lg flex items-center justify-center shrink-0 font-bold border bg-slate-800 border-slate-700/40 text-slate-500`}>
            {candidate.display_name.charAt(0).toUpperCase()}
        </div>
    );
}

// ─── Candidate card ───────────────────────────────────────────────────────────

function CandidateCard({
    candidate, rank, canVote, isMe, processing, onVote,
}: {
    candidate: Candidate;
    rank: number;
    // canVote is already false for non-home-city residents — button simply won't render
    canVote: boolean;
    isMe: boolean;
    processing: boolean;
    onVote: (id: number) => void;
}) {
    return (
        <motion.div
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: rank * 0.04 }}
            className={`group relative rounded-xl overflow-hidden border transition-all duration-300 ${isMe
                    ? "border-cyan-500/30 bg-cyan-500/5 hover:border-cyan-500/50"
                    : "border-slate-700/40 bg-slate-900/40 hover:border-slate-600/50 hover:bg-slate-900/60"
                }`}
        >
            <div className="flex items-start gap-4 p-4">
                <CandidateAvatar candidate={candidate} />

                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap mb-1">
                        <span className="text-sm font-semibold text-white">{candidate.display_name}</span>
                        {isMe && (
                            <span className="text-[9px] font-semibold uppercase tracking-wider text-cyan-400 bg-cyan-500/10 border border-cyan-500/20 px-1.5 py-0.5 rounded-full">
                                You
                            </span>
                        )}

                    </div>

                    <p className="text-xs text-slate-500 line-clamp-2 mb-3">{candidate.manifesto}</p>

                    <div className="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-wider text-slate-500">
                        <Scales className="w-3.5 h-3.5" weight="bold" />
                        Ballot listed
                    </div>
                </div>


                {canVote && !isMe && (
                    <button
                        onClick={() => onVote(candidate.campaign_id)}
                        disabled={processing}
                        className="shrink-0 flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-emerald-500/30 bg-emerald-500/10 text-emerald-400 text-xs font-medium hover:bg-emerald-500/20 hover:border-emerald-500/50 transition disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        {processing
                            ? <CircleNotch className="w-3.5 h-3.5 animate-spin" weight="bold" />
                            : <HandPointing className="w-3.5 h-3.5" weight="bold" />
                        }
                        Vote
                    </button>
                )}
            </div>
        </motion.div>
    );
}

// ─── Application form ─────────────────────────────────────────────────────────

const MANIFESTO_MIN = 50;
const MANIFESTO_MAX = 500;

function ApplicationForm({
    citySlug, applicationFee, processing, onProcessing,
}: {
    citySlug: string;
    applicationFee: number;
    processing: boolean;
    onProcessing: (v: boolean) => void;
}) {
    const [manifesto, setManifesto] = useState("");
    const len = manifesto.trim().length;
    const ready = len >= MANIFESTO_MIN;

    const handleSubmit = () => {
        if (!ready || processing) return;
        onProcessing(true);
        router.post(
            route("city.election.apply", { city: citySlug }),
            { manifesto: manifesto.trim() },
            { preserveScroll: true, onFinish: () => onProcessing(false) },
        );
    };

    return (
        <div className="space-y-4">
            <div>
                <label className="flex items-center gap-1.5 text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-2">
                    <Article className="w-3.5 h-3.5" weight="bold" />
                    Your Manifesto
                </label>
                <textarea
                    value={manifesto}
                    onChange={(e) => setManifesto(e.target.value.slice(0, MANIFESTO_MAX))}
                    placeholder="State your vision for the city..."
                    rows={4}
                    className="w-full px-3 py-2.5 bg-slate-900/60 border border-slate-700/50 rounded-xl text-sm text-white placeholder:text-slate-600 focus:outline-none focus:ring-1 focus:ring-cyan-500/50 resize-none"
                />
                <div className="flex justify-between mt-1.5 text-[10px]">
                    <span className={ready ? "text-emerald-400" : "text-slate-500"}>
                        {ready ? "✓ Ready" : `${MANIFESTO_MIN - len} more chars needed`}
                    </span>
                    <span className="text-slate-600 font-mono">{len} / {MANIFESTO_MAX}</span>
                </div>
            </div>

            <div className="rounded-xl border border-slate-700/40 bg-slate-900/40 p-4 space-y-2 text-xs">
                <div className="flex justify-between text-slate-400">
                    <span>Application fee</span>
                    <span className="text-amber-400 font-medium">{formatCash(applicationFee)}</span>
                </div>
            </div>

            <button
                onClick={handleSubmit}
                disabled={!ready || processing}
                className="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl border border-cyan-500/40 bg-cyan-500/10 hover:bg-cyan-500/20 text-white font-medium text-sm transition disabled:opacity-40 disabled:cursor-not-allowed"
            >
                {processing
                    ? <><CircleNotch className="w-4 h-4 animate-spin" weight="bold" /> Filing…</>
                    : <><Crown className="w-4 h-4" weight="fill" /> Enter the Race</>
                }
            </button>
        </div>
    );
}

// ─── My campaign status ───────────────────────────────────────────────────────

function MyCampaignStatus({
    campaign, campaignFund, citySlug, isRegistration, processing, onProcessing,
}: {
    campaign: Candidate;
    // campaignFund comes from election.my_campaign_fund — the server only sends
    // this value for the authenticated player's own campaign, never in the
    // candidates array, so rival fund totals are never exposed.
    campaignFund: number;
    citySlug: string;
    isRegistration: boolean;
    processing: boolean;
    onProcessing: (v: boolean) => void;
}) {
    const [fundAmount, setFundAmount] = useState("");

    const handleAddFunds = () => {
        const amount = parseInt(fundAmount, 10);
        if (!amount || amount <= 0 || processing) return;
        onProcessing(true);
        router.post(
            route("city.election.funds", { city: citySlug }),
            { campaign_id: campaign.campaign_id, amount },
            { preserveScroll: true, onFinish: () => { onProcessing(false); setFundAmount(""); } },
        );
    };

    return (
        <div className="space-y-4">
            <div className="flex items-center gap-2 text-sm">
                <CheckCircle className="w-4 h-4 text-cyan-400" weight="bold" />
                <span className="font-medium text-white">Campaign Active</span>
            </div>

            <div className="grid grid-cols-2 gap-2">
                {[
                    { label: "My Fund", value: formatCash(campaignFund) },
                    { label: "Status", value: isRegistration ? "Registered" : "On ballot" },
                ].map(({ label, value }) => (
                    <div key={label} className="rounded-lg border border-slate-700/40 bg-slate-900/40 p-3">
                        <p className="text-[10px] uppercase tracking-wider text-slate-500 mb-1">{label}</p>
                        <p className="text-sm font-semibold text-white font-mono">{value}</p>
                    </div>
                ))}
            </div>

            {isRegistration && (
                <div className="space-y-2">
                    <p className="text-[11px] text-slate-500 uppercase tracking-wider">Boost Campaign Fund</p>
                    <div className="flex gap-2">
                        <div className="relative flex-1">
                            <CurrencyDollar className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" weight="bold" />
                            <input
                                type="number"
                                min={1}
                                value={fundAmount}
                                onChange={(e) => setFundAmount(e.target.value)}
                                placeholder="Amount"
                                className="w-full pl-9 pr-3 py-2 bg-slate-900/60 border border-slate-700/50 rounded-xl text-sm text-white placeholder:text-slate-600 focus:outline-none focus:ring-1 focus:ring-cyan-500/50 font-mono"
                            />
                        </div>
                        <button
                            onClick={handleAddFunds}
                            disabled={!fundAmount || processing}
                            className="px-3 py-2 rounded-xl border border-amber-500/30 bg-amber-500/10 text-amber-400 hover:bg-amber-500/20 transition disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            {processing
                                ? <CircleNotch className="w-4 h-4 animate-spin" weight="bold" />
                                : <Plus className="w-4 h-4" weight="bold" />
                            }
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── Sidebar panel wrapper ────────────────────────────────────────────────────

function SidePanel({ title, icon: Icon, children }: {
    title: string;
    icon: React.ElementType;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-xl border border-slate-700/40 bg-slate-900/40 overflow-hidden">
            <div className="px-4 py-3 border-b border-slate-700/40 flex items-center gap-2">
                <Icon className="w-4 h-4 text-slate-400" weight="bold" />
                <h3 className="text-sm font-medium text-white">{title}</h3>
            </div>
            <div className="p-4">{children}</div>
        </div>
    );
}

// ─── No election state ────────────────────────────────────────────────────────

function NoElectionState({
    city, needsElection, canApply, constants, processing, onProcessing,
}: {
    city: Props["city"];
    needsElection: boolean;
    canApply: CanApply | null;
    constants: ElectionConstants;
    processing: boolean;
    onProcessing: (v: boolean) => void;
}) {
    return (
        <div className="rounded-xl border border-slate-700/40 bg-slate-900/40 overflow-hidden">
            <div className="px-6 py-8 text-center border-b border-slate-700/40">
                <div className="w-12 h-12 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center mx-auto mb-4">
                    <Crown className="w-6 h-6 text-amber-400" weight="fill" />
                </div>
                <p className="text-base font-semibold text-white mb-1">
                    {needsElection
                        ? "No Mayor — City Needs a Leader"
                        : city.current_mayor
                            ? `${city.current_mayor} Holds Office`
                            : "No Active Election"}
                </p>
                <p className="text-sm text-slate-500">
                    {needsElection
                        ? "Be the first to declare your candidacy."
                        : city.current_mayor
                            ? "Next election begins when the current term expires."
                            : "Check back later for upcoming elections."}
                </p>
            </div>

            {needsElection && (
                <div className="p-5">
                    {canApply === null ? (
                        <div className="flex items-center justify-center gap-2 py-4 text-slate-500 text-sm">
                            <CircleNotch className="w-4 h-4 animate-spin" weight="bold" />
                            Checking eligibility…
                        </div>
                    ) : canApply.can_apply ? (
                        <ApplicationForm
                            citySlug={city.slug}
                            applicationFee={constants.application_fee}
                            processing={processing}
                            onProcessing={onProcessing}
                        />
                    ) : (
                        <div className="rounded-xl border border-red-500/20 bg-red-500/5 p-4">
                            <div className="flex items-center gap-2 mb-3">
                                <XCircle className="w-4 h-4 text-red-400" weight="bold" />
                                <p className="text-sm font-medium text-red-400">Not eligible to run</p>
                            </div>
                            <ul className="space-y-1.5">
                                {canApply.errors.map((err, i) => (
                                    <li key={i} className="flex items-start gap-2 text-xs text-red-400/80">
                                        <Warning className="w-3.5 h-3.5 shrink-0 mt-0.5" weight="bold" />
                                        {err}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}


function Election({ city, election, recent_result, can_apply, needs_election, election_constants }: Props) {
    const { auth, serverTime } = usePage<{ auth: any; serverTime: string;[key: string]: any }>().props;
    const myName: string = auth?.character?.displayName ?? "";

    const [processing, setProcessing] = useState(false);
    const [showResult, setShowResult] = useState(true);

    const activeEnd = election?.status === "voting" ? election.voting_ends : (election?.registration_ends ?? null);
    const timeRemaining = useCountdown(activeEnd, serverTime ?? "");

    const myCampaign = election?.candidates.find(c => c.display_name === myName) ?? null;
    const isRegistration = election?.status === "registration";
    const isFull = (election?.candidates.length ?? 0) >= (election?.max_candidates ?? 3);
    const canVote = election?.status === "voting"
        && !election.has_voted
        && (election.is_home_city ?? false);

    const candidates = election?.candidates ?? [];

    const handleVote = (campaignId: number) => {
        if (processing) return;
        setProcessing(true);
        router.post(
            route("city.election.vote", { city: city.slug }),
            { campaign_id: campaignId },
            { preserveScroll: true, onFinish: () => setProcessing(false) },
        );
    };

    return (
        <>
            <Head title={`${city.name} — Election`} />

            <div className="w-full max-w-5xl mx-auto flex flex-col">

                {/* Animated winner banner */}
                <AnimatePresence>
                    {showResult && recent_result && (
                        <WinnerBanner result={recent_result} onDismiss={() => setShowResult(false)} />
                    )}
                </AnimatePresence>

                {/* Hero */}
                <ElectionHero
                    city={city}
                    election={election}
                    constants={election_constants}
                    timeRemaining={timeRemaining}
                />

                {/* No election */}
                {!election && (
                    <NoElectionState
                        city={city}
                        needsElection={needs_election}
                        canApply={can_apply}
                        constants={election_constants}
                        processing={processing}
                        onProcessing={setProcessing}
                    />
                )}

                {/* Active election */}
                {election && (
                    <div className="grid lg:grid-cols-3 gap-4">

                        {/* Candidate list */}
                        <div className="lg:col-span-2 space-y-2">
                            <div className="flex items-center justify-between px-1 mb-1">

                                {isFull && (
                                    <span className="text-[10px] font-semibold uppercase tracking-wider text-red-400 bg-red-500/10 border border-red-500/20 px-2 py-0.5 rounded-full">Full</span>
                                )}
                            </div>

                            {candidates.length > 0 ? candidates.map((c, idx) => (
                                <CandidateCard
                                    key={c.campaign_id}
                                    candidate={c}
                                    rank={idx}
                                    canVote={canVote}
                                    isMe={c.display_name === myName}
                                    processing={processing}
                                    onVote={handleVote}
                                />
                            )) : (
                                <div className="rounded-xl border border-dashed border-slate-700/40 py-16 text-center">
                                    <Users className="w-8 h-8 text-slate-700 mx-auto mb-3" />
                                    <p className="text-sm text-slate-500">No candidates yet</p>
                                    <p className="text-xs text-slate-600 mt-1">Be the first to declare your candidacy</p>
                                </div>
                            )}
                        </div>

                        {/* Sidebar */}
                        <div className="space-y-3">

                            {/* Action panel */}
                            <SidePanel title={myCampaign ? "Your Campaign" : "Run for Mayor"} icon={Crown}>
                                {myCampaign ? (
                                    <MyCampaignStatus
                                        campaign={myCampaign}
                                        campaignFund={election.my_campaign_fund ?? 0}
                                        citySlug={city.slug}
                                        isRegistration={isRegistration}
                                        processing={processing}
                                        onProcessing={setProcessing}
                                    />
                                ) : isRegistration && !isFull && can_apply?.can_apply ? (
                                    <ApplicationForm
                                        citySlug={city.slug}
                                        applicationFee={election_constants.application_fee}
                                        processing={processing}
                                        onProcessing={setProcessing}
                                    />
                                ) : isRegistration && (isFull || (can_apply && !can_apply.can_apply)) ? (
                                    <div className="rounded-xl border border-red-500/20 bg-red-500/5 p-4">
                                        <div className="flex items-center gap-2 mb-2">
                                            <XCircle className="w-4 h-4 text-red-400" weight="bold" />
                                            <p className="text-sm font-medium text-red-400">
                                                {isFull ? "All slots filled" : "Not eligible"}
                                            </p>
                                        </div>
                                        {!isFull && can_apply?.errors.map((err, i) => (
                                            <p key={i} className="flex items-start gap-1.5 text-xs text-red-400/70 mt-1">
                                                <Warning className="w-3.5 h-3.5 shrink-0 mt-0.5" weight="bold" /> {err}
                                            </p>
                                        ))}
                                    </div>
                                ) : election.status === "voting" ? (
                                    <div className="py-3 text-center">
                                        {election.has_voted ? (
                                            <div className="flex flex-col items-center gap-2">
                                                <CheckCircle className="w-8 h-8 text-emerald-400" weight="bold" />
                                                <p className="text-sm font-medium text-emerald-400">Vote cast</p>
                                                <p className="text-xs text-slate-500">Results when voting closes</p>
                                            </div>
                                        ) : !election.is_home_city ? (
                                            // Non-residents see a clear, honest message — no disabled button
                                            <div className="flex flex-col items-center gap-2">
                                                <MapPin className="w-8 h-8 text-slate-600" weight="fill" />
                                                <p className="text-sm font-medium text-slate-400">Voting not available</p>
                                                <p className="text-xs text-slate-600">Only residents of {city.name} may vote</p>
                                            </div>
                                        ) : (
                                            // Home-city resident who hasn't voted — prompt them via the candidate list
                                            <div className="flex flex-col items-center gap-2">
                                                <HandPointing className="w-8 h-8 text-emerald-400" weight="bold" />
                                                <p className="text-sm font-medium text-white">Voting is open</p>
                                                <p className="text-xs text-slate-500">Select a candidate from the list</p>
                                            </div>
                                        )}
                                    </div>
                                ) : null}
                            </SidePanel>

                            {/* Election info */}
                            <SidePanel title="Election Info" icon={Scales}>
                                <div className="space-y-0 text-xs">
                                    {[
                                        { label: "Phase", value: election.status },
                                        { label: "Cycle", value: `#${election.cycle}` },
                                        {
                                            label: "Your vote",
                                            value: election.has_voted ? "✓ Cast" : (election.is_home_city ? "Pending" : "N/A"),
                                            highlight: election.has_voted ? "text-emerald-400" : "text-slate-400",
                                        },
                                    ].map(({ label, value, highlight }) => (
                                        <div key={label} className="flex justify-between items-center py-2 border-b border-slate-800/60 last:border-0">
                                            <span className="text-slate-500 uppercase tracking-wider text-[10px]">{label}</span>
                                            <span className={`font-medium ${highlight ?? "text-white"}`}>{value}</span>
                                        </div>
                                    ))}
                                    {election.voting_ends && election.status === "voting" && (
                                        <p className="pt-2 text-slate-500 text-[10px]">
                                            Voting ends {formatUTC(election.voting_ends, false)}
                                        </p>
                                    )}
                                    {election.registration_ends && election.status === "registration" && (
                                        <p className="pt-2 text-slate-500 text-[10px]">
                                            Registration ends {formatUTC(election.registration_ends, false)}
                                        </p>
                                    )}
                                </div>
                            </SidePanel>

                            {/* Requirements */}
                            <SidePanel title="Requirements" icon={ArrowRight}>
                                <div className="space-y-0 text-xs">
                                    {[
                                        { label: "Application fee", value: formatCash(election_constants.application_fee), color: "text-amber-400" },
                                        { label: "Min rank", value: `Rank ${election_constants.min_rank_required}`, color: "text-cyan-400" },
                                        { label: "Term length", value: `${election_constants.term_duration_days} days`, color: "text-slate-300" },
                                    ].map(({ label, value, color }) => (
                                        <div key={label} className="flex justify-between items-center py-2 border-b border-slate-800/60 last:border-0">
                                            <span className="text-slate-500 uppercase tracking-wider text-[10px]">{label}</span>
                                            <span className={`font-medium ${color}`}>{value}</span>
                                        </div>
                                    ))}
                                </div>
                            </SidePanel>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

Election.layout = (page: React.ReactNode) => <GameLayout wide children={page} />;

export default Election;
