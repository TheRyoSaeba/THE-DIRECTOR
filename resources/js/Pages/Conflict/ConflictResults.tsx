import { useEffect } from 'react';
import { Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    Crosshair,
    Skull,
    Shield,
    Sword,
    Heart,
    ArrowCounterClockwise,
    House,
    ArrowRight,
    Lightning,
    CrossIcon,
    Coins,
    Bag,
} from '@phosphor-icons/react';
import GameLayout from '@/Layouts/GameLayout';

/* ══════════════════════════════════════════════════════════════════════
   CONFLICT RESULTS
   Cinematic full-page outcome display — served after attack / GBH.
   Direct URL access is bounced server-side to /conflict.
   ══════════════════════════════════════════════════════════════════════ */

interface Loot {
    cash: number;
    dirty_cash: number;
}

interface ConflictResultsProps {
    outcome: 'kill' | 'damage' | 'hospitalized' | 'miss' | 'gbh_miss';
    message: string;
    image_url: string | null;
    is_instant_kill: boolean;
    is_critical: boolean;
    damage: number;
    loot: Loot | null;
}

const OUTCOME_CONFIG = {
    kill: {
        label: 'Target Eliminated',
        accent: 'text-emerald-400',
        border: 'border-emerald-500/20',
        glow: 'rgba(52,211,153,0.07)',
        ping: 'bg-emerald-500',
        badge: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
        Icon: Skull,
    },
    damage: {
        label: 'Damage Dealt',
        accent: 'text-amber-400',
        border: 'border-amber-500/20',
        glow: 'rgba(245,158,11,0.07)',
        ping: 'bg-amber-500',
        badge: 'bg-amber-500/10 text-amber-400 border-amber-500/20',
        Icon: Sword,
    },
    hospitalized: {
        label: 'Target Hospitalized',
        accent: 'text-orange-400',
        border: 'border-orange-500/20',
        glow: 'rgba(249,115,22,0.07)',
        ping: 'bg-orange-500',
        badge: 'bg-orange-500/10 text-orange-400 border-orange-500/20',
        Icon: Heart,
    },
    miss: {
        label: 'Attack Missed',
        accent: 'text-slate-400',
        border: 'border-slate-500/20',
        glow: 'rgba(148,163,184,0.05)',
        ping: 'bg-slate-500',
        badge: 'bg-slate-500/10 text-slate-400 border-slate-500/20',
        Icon: Shield,
    },
    gbh_miss: {
        label: 'Assault Failed',
        accent: 'text-slate-400',
        border: 'border-slate-500/20',
        glow: 'rgba(148,163,184,0.05)',
        ping: 'bg-slate-500',
        badge: 'bg-slate-500/10 text-slate-400 border-slate-500/20',
        Icon: Shield,
    },
} as const;

// ── Floating particles ────────────────────────────────────────────────
function Particles({ color }: { color: string }) {
    return (
        <div className="fixed inset-0 overflow-hidden pointer-events-none">
            {Array.from({ length: 14 }).map((_, i) => {
                const size = 1 + Math.random() * 2.5;
                const left = Math.random() * 100;
                const delay = Math.random() * 4;
                const dur = 5 + Math.random() * 6;
                return (
                    <motion.div
                        key={i}
                        className={`absolute rounded-full ${color} opacity-0`}
                        style={{ width: size, height: size, left: `${left}%`, bottom: -8 }}
                        animate={{ y: [0, -(500 + Math.random() * 300)], opacity: [0, 0.4, 0] }}
                        transition={{ duration: dur, delay, repeat: Infinity, ease: 'easeOut' }}
                    />
                );
            })}
        </div>
    );
}

// ── Scanline sweep ────────────────────────────────────────────────────
function ScanLine() {
    return (
        <motion.div
            className="fixed inset-x-0 h-px bg-gradient-to-r from-transparent via-white/8 to-transparent pointer-events-none z-10"
            initial={{ top: 0 }}
            animate={{ top: '100%' }}
            transition={{ duration: 4, repeat: Infinity, ease: 'linear' }}
        />
    );
}

export default function ConflictResults({
    outcome,
    message,
    image_url,
    is_instant_kill,
    is_critical,
    damage,
    loot,
}: ConflictResultsProps) {
    const cfg = OUTCOME_CONFIG[outcome] ?? OUTCOME_CONFIG.damage;
    const { Icon } = cfg;

    const hasLoot = loot && (loot.cash > 0 || loot.dirty_cash > 0);

    // Prevent back-nav landing on a stale result after the session flash is gone.
    useEffect(() => {
        window.history.replaceState(null, '', window.location.href);
    }, []);

    return (
        <div className="relative w-full text-white">

            {/* ── Ambient radial glow ──────────────────────────────── */}
            <div
                className="fixed inset-0 pointer-events-none"
                style={{ background: `radial-gradient(ellipse at 50% 0%, ${cfg.glow} 0%, transparent 55%)` }}
            />
            <Particles color={cfg.ping} />
            <ScanLine />

            <div className="relative z-10 max-w-5xl mx-auto px-4 sm:px-6 py-8 sm:py-12">

                {/* ── Status pill ──────────────────────────────────── */}
                <motion.div
                    initial={{ opacity: 0, y: -8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4 }}
                    className="flex items-center gap-2.5 mb-6"
                >
                    <span className="relative flex h-2 w-2">
                        <span className={`animate-ping absolute inline-flex h-full w-full rounded-full ${cfg.ping} opacity-40`} />
                        <span className={`relative inline-flex rounded-full h-2 w-2 ${cfg.ping}`} />
                    </span>

                    {is_instant_kill && (
                        <span className="flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-500/10 border border-red-500/20 text-red-400 text-[9px] font-black uppercase tracking-widest">

                            INSTANT KILL
                        </span>
                    )}
                    {is_critical && (
                        <span className="flex items-center gap-1 px-2 py-0.5 rounded-full bg-yellow-500/10 border border-yellow-500/20 text-yellow-400 text-[9px] font-black uppercase tracking-widest">

                            CRITICAL HIT
                        </span>
                    )}
                </motion.div>

                {/* ── Hero: icon + outcome title + MESSAGE ─────────── */}
                <motion.div
                    initial={{ opacity: 0, y: 14 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.55, delay: 0.08 }}
                    className="mb-10"
                >
                    {/* Outcome label row */}
                    <div className="flex items-center gap-4 mb-4">
                        <div className={`flex-shrink-0 w-14 h-14 rounded-xl border ${cfg.border} bg-slate-900/80 flex items-center justify-center relative`}>
                            <Icon className={cfg.accent} size={26} weight="bold" />
                            <motion.div
                                className={`absolute inset-0 rounded-xl border ${cfg.border}`}
                                animate={{ scale: [1, 1.4], opacity: [0.5, 0] }}
                                transition={{ duration: 2, repeat: Infinity, ease: 'easeOut' }}
                            />
                        </div>
                        <div>
                            <h1 className={`text-3xl sm:text-5xl font-black tracking-tight uppercase ${cfg.accent}`}>
                                {cfg.label}
                            </h1>
                        </div>
                    </div>


                </motion.div>

                {/* ── Two-column layout ────────────────────────────── */}
                <div className="grid lg:grid-cols-[1fr_320px] gap-8 items-start">

                    {/* ── Left: scene image + optional loot strip ──── */}
                    <div className="space-y-4">

                        {/* Scene image */}
                        <motion.div
                            initial={{ opacity: 0, scale: 0.97 }}
                            animate={{ opacity: 1, scale: 1 }}
                            transition={{ duration: 0.6, delay: 0.15 }}
                            className={`relative rounded-2xl overflow-hidden border ${cfg.border} bg-slate-900/40`}
                        >
                            {image_url ? (
                                <img
                                    src={image_url}
                                    alt="Conflict scene"
                                    className="w-full aspect-video object-cover"
                                    style={{ filter: 'brightness(0.65) contrast(1.1) saturate(0.8)' }}
                                />
                            ) : (
                                <div className="w-full aspect-video flex items-center justify-center bg-slate-900/60">
                                    <Crosshair size={36} className="text-slate-700" />
                                </div>
                            )}
                            <div className="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-transparent to-transparent pointer-events-none" />

                            {/* Damage chip — hidden on hospitalized since damage is implicit */}
                            <div className="absolute bottom-4 left-4 flex items-center gap-2">
                                {damage > 0 && outcome !== 'hospitalized' && (
                                    <span className="text-[9px] font-black uppercase tracking-[0.15em] px-2.5 py-1 rounded-lg border border-white/10 backdrop-blur-sm bg-black/40 text-slate-300">
                                        {damage} DMG
                                    </span>
                                )}
                            </div>
                        </motion.div>




                        <motion.div
                            initial={{ opacity: 0, y: 10 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.5, delay: 0.32 }}
                            className="bg-slate-900/60 border border-white/5 rounded-xl p-5 space-y-3"
                        >
                            <div className="flex items-center gap-2 text-slate-500">
                                <Crosshair size={11} weight="bold" />
                                <span className="text-[12px] font-bold  text-white uppercase tracking-[0.2em]">
                                    WHAT HAPPENED?
                                </span>
                            </div>
                            <p className="text-sm sm:text-base text-slate-300 leading-relaxed">
                                {message}
                            </p>
                        </motion.div>


                    </div>

                    {/* ── Right: actions panel ─────────────────────── */}
                    <motion.div
                        initial={{ opacity: 0, x: 18 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ duration: 0.55, delay: 0.35 }}
                        className="space-y-3 lg:sticky lg:top-20"
                    >
                        <p className="text-[10px] font-semibold uppercase text-white tracking-[0.2em] text-slate-600 px-1 mb-4">
                            What's Next
                        </p>

                        <Link
                            href="/conflict"
                            className="group flex items-center gap-4 rounded-xl bg-slate-900/50 border border-white/5 hover:border-white/10 p-5 transition-all duration-300 hover:bg-slate-900/80"
                        >
                            <div className="flex-shrink-0 w-12 h-12 rounded-xl bg-slate-800/80 border border-white/5 flex items-center justify-center group-hover:border-white/10 transition-all duration-300">
                                <ArrowCounterClockwise size={20} className="text-slate-400 group-hover:text-white transition-colors" />
                            </div>
                            <div className="flex-1 min-w-0">
                                <span className="text-sm font-semibold text-slate-200 group-hover:text-white transition-colors block">
                                    Strike Again
                                </span>
                                <p className="text-xs text-slate-500 mt-0.5">Choose your next target.</p>
                            </div>
                            <ArrowRight size={15} className="text-slate-600 group-hover:text-slate-400 transition-colors flex-shrink-0" />
                        </Link>

                        <Link
                            href="/dashboard"
                            className="group flex items-center gap-4 rounded-xl bg-slate-900/50 border border-white/5 hover:border-slate-600/30 p-5 transition-all duration-300 hover:bg-slate-900/80"
                        >
                            <div className="flex-shrink-0 w-12 h-12 rounded-xl bg-slate-800/80 border border-white/5 flex items-center justify-center group-hover:border-slate-600/40 transition-all duration-300">
                                <House size={20} className="text-slate-400 group-hover:text-slate-300 transition-colors" />
                            </div>
                            <div className="flex-1 min-w-0">
                                <span className="text-sm font-semibold text-slate-200 group-hover:text-white transition-colors block">
                                    Return to Main Page
                                </span>
                                <p className="text-xs text-slate-500 mt-0.5">Head back to the dashboard.</p>
                            </div>
                            <ArrowRight size={15} className="text-slate-600 group-hover:text-slate-400 transition-colors flex-shrink-0" />
                        </Link>

                        {/* todo: comment this out for now, we'll use this later */}
                        {/* Attack Outcome card */}
                        {/* <div className="mt-6 pt-5 border-t border-slate-800/50">
                            <div className={`text-center py-5 rounded-xl border ${cfg.border} bg-slate-900/40`}>
                                <Icon className={`${cfg.accent} mx-auto mb-2`} size={28} weight="bold" />
                                <div className={`text-sm font-black uppercase tracking-[0.15em] ${cfg.accent}`}>
                                    {cfg.label}
                                </div>
                                <div className="text-[9px] text-slate-600 font-bold uppercase tracking-[0.25em] mt-1">
                                    Attack Outcome
                                </div>
                            </div>
                        </div> */}
                    </motion.div>
                </div>
            </div>
        </div>
    );
}

ConflictResults.layout = (page: React.ReactNode) => <GameLayout children={page} />;
