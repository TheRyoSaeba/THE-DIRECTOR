import { useState, useEffect, useRef } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { route } from 'ziggy-js';
import {
    Trophy, Warning, Coins, CircleNotch, Crown,
    Diamond, Star, User, Gear, Spade, Club, Heart,
    Sparkle,
} from '@phosphor-icons/react';
import { motion, AnimatePresence } from 'framer-motion';
import GameLayout from '@/Layouts/GameLayout';

interface PageProps {
    auth: any;
    flash: {
        success?: string;
        error?: string;
    };
    [key: string]: any;
}

interface BlackjackHand {
    phase: 'player_turn' | 'dealer_turn' | 'settled';
    bet: number;
    doubled: boolean;
    player: number[];
    player_total: number;
    dealer: number[];
    dealer_total: number | null;
    dealer_hidden: boolean;
    result: 'win' | 'lose' | 'push' | 'blackjack' | null;
    payout: number;
    net: number | null;
    can_double: boolean;
}

interface Props {
    cityData: {
        name: string;
        slug: string;
    };
    business: {
        name: string;
        image_url: string | null;
        owner_name: string;
        is_owner: boolean;
        settings: {
            cost_per_ball: number;
            bj_bet_min: number;
            bj_bet_max: number;
            bj_blackjack_payout: number;
        };
        owner_settings: null | {
            cost_per_ball: number;
            owner_greed: number;
            number_of_decks: number;
        };
        balance: number | null;
    };
    blackjack_hand: BlackjackHand | null;
}

type ActiveTab = "pachinko" | "slots" | "blackjack" | "owner";

const TABS = [
    { id: "pachinko" as const, label: "Pachinko Parlor", icon: Sparkle },
    { id: "blackjack" as const, label: "Blackjack", icon: Spade },
] as const;

// A card value (2..11) gets a deterministic rank label + a suit for display.
// Suit is purely cosmetic — the server only tracks values — so we derive one
// from the card's position in the hand to keep the felt looking varied.
const SUITS = [
    { Icon: Spade, red: false },
    { Icon: Diamond, red: true },
    { Icon: Club, red: false },
    { Icon: Heart, red: true },
] as const;

function rankLabel(value: number, indexInHand: number): string {
    if (value === 11) return 'A';
    if (value === 10) {
        // 10 could be a 10/J/Q/K — vary it by position so a hand isn't all 10s.
        return ['10', 'J', 'Q', 'K'][indexInHand % 4];
    }
    return String(value);
}

function PlayingCard({ value, index, hidden = false }: { value?: number; index: number; hidden?: boolean }) {
    const suit = SUITS[index % SUITS.length];
    const SuitIcon = suit.Icon;

    return (
        <motion.div
            initial={{ opacity: 0, y: -30, rotateY: 90 }}
            animate={{ opacity: 1, y: 0, rotateY: 0 }}
            exit={{ opacity: 0, scale: 0.8 }}
            transition={{ duration: 0.35, ease: 'easeOut' }}
            className="relative"
            style={{ perspective: 600 }}
        >
            {hidden ? (
                <div className="w-14 h-20 sm:w-16 sm:h-24 rounded-lg bg-gradient-to-br from-emerald-900 to-slate-950 border border-amber-500/40 flex items-center justify-center shadow-lg">
                    <div className="w-8 h-12 rounded border border-amber-500/20 bg-[repeating-linear-gradient(45deg,rgba(245,158,11,0.12)_0_4px,transparent_4px_8px)]" />
                </div>
            ) : (
                <div className={`w-14 h-20 sm:w-16 sm:h-24 rounded-lg bg-white p-2 flex flex-col justify-between shadow-lg select-none ${suit.red ? 'text-red-500' : 'text-slate-900'}`}>
                    <span className="text-base font-black leading-none">{rankLabel(value!, index)}</span>
                    <SuitIcon size={20} weight="fill" className="self-center" />
                    <span className="text-base font-black leading-none text-right rotate-180">{rankLabel(value!, index)}</span>
                </div>
            )}
        </motion.div>
    );
}

// Idle-state visual: a small fan of face-down cards so an empty felt reads as
// "a table ready to deal" rather than blank space.
function DeckStack() {
    return (
        <div className="relative w-14 h-20 sm:w-16 sm:h-24">
            {[0, 1, 2].map(i => (
                <div
                    key={i}
                    className="absolute inset-0 rounded-lg bg-gradient-to-br from-emerald-900 to-slate-950 border border-amber-500/30 shadow-lg flex items-center justify-center"
                    style={{ transform: `translate(${i * 3}px, ${i * -3}px)` }}
                >
                    <div className="w-8 h-12 rounded border border-amber-500/15 bg-[repeating-linear-gradient(45deg,rgba(245,158,11,0.10)_0_4px,transparent_4px_8px)]" />
                </div>
            ))}
        </div>
    );
}

export default function Pachinko({ cityData, business, blackjack_hand }: Props) {
    const { auth, flash } = usePage<PageProps>().props;
    const characterMoney = auth?.character?.cleanCash ?? 0;
    const [activeTab, setActiveTab] = useState<ActiveTab>("pachinko");

    // Core game state
    const [betAmount, setBetAmount] = useState(100);
    const [processing, setProcessing] = useState(false);
    const [resultAnimation, setResultAnimation] = useState<'idle' | 'spinning' | 'win' | 'loss'>('idle');
    const [showJackpotBanner, setShowJackpotBanner] = useState(false);
    const [lastResult, setLastResult] = useState<{ net: number; jackpot?: boolean } | null>(null);
    const [fallingBalls, setFallingBalls] = useState<any[]>([]);
    const [confetti, setConfetti] = useState<any[]>([]);

    const ballCounter = useRef(0);
    const confettiCounter = useRef(0);

    // Owner setting states
    const [settingCost, setSettingCost] = useState(business.owner_settings?.cost_per_ball ?? 100);
    const [settingGreed, setSettingGreed] = useState(business.owner_settings?.owner_greed ?? 0.05);
    const [settingDecks, setSettingDecks] = useState(business.owner_settings?.number_of_decks ?? 6);

    // Blackjack state. Seeded from the resume prop so a dropped connection
    // returns straight to the in-progress hand.
    const [hand, setHand] = useState<BlackjackHand | null>(blackjack_hand);
    const [bjBet, setBjBet] = useState(business.settings.bj_bet_min);
    const [bjProcessing, setBjProcessing] = useState(false);

    const costPerBall = business.settings.cost_per_ball;
    const totalCost = betAmount * costPerBall;
    const canAfford = characterMoney >= totalCost;

    // Dynamically append administrative controls
    const allTabs = [
        ...TABS,
        ...(business.is_owner ? [{ id: "owner" as const, label: "Manage Parlor", icon: Crown }] : []),
    ];

    useEffect(() => {
        const message = flash.success || flash.error;
        if (!message) return;

        const netMatch = message.match(/profit of \$([\d,]+)|lost \$([\d,]+)/);
        const netAmount = netMatch?.[1] || netMatch?.[2];
        const net = netAmount ? parseInt(netAmount.replace(/,/g, '')) : 0;
        const isWin = message.includes('profit') || message.includes('break even');
        const hasJackpot = message.includes('JACKPOT');

        setLastResult({
            net: isWin ? net : -net,
            jackpot: hasJackpot
        });

        setResultAnimation(isWin ? 'win' : 'loss');
        if (hasJackpot) {
            setShowJackpotBanner(true);

            const particles = Array.from({ length: 60 }, (_, i) => ({
                id: confettiCounter.current++,
                left: `${Math.random() * 100}%`,
                delay: `${Math.random() * 0.5}s`,
                color: ['#FFD700', '#FFA500', '#FF69B4', '#00FFFF', '#FF4500', '#FFD700', '#FFA500'][Math.floor(Math.random() * 7)]
            }));
            setConfetti(particles);

            const timer = setTimeout(() => {
                setShowJackpotBanner(false);
                setConfetti([]);
            }, 6500);

            return () => clearTimeout(timer);
        }

        const timer = setTimeout(() => {
            setResultAnimation('idle');
        }, 5500);

        return () => clearTimeout(timer);
    }, [flash.success, flash.error]);

    useEffect(() => {
        if (resultAnimation === 'spinning') {
            const interval = setInterval(() => {
                setFallingBalls(prev => [
                    ...prev.slice(-10),
                    {
                        id: ballCounter.current++,
                        left: `${Math.random() * 100}%`,
                        delay: `${Math.random() * 0.3}s`,
                        size: `${Math.floor(Math.random() * 10 + 6)}px`,
                    },
                ]);
            }, 150);
            return () => clearInterval(interval);
        } else {
            setFallingBalls([]);
        }
    }, [resultAnimation]);

    const handleSpin = () => {
        if (!canAfford || processing) return;
        setProcessing(true);
        setResultAnimation('spinning');
        setLastResult(null);
        setShowJackpotBanner(false);

        router.post(route('city.pachinko.spin', { city: cityData.slug }), {
            balls: betAmount,
        }, {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                setProcessing(false);
                setResultAnimation('idle');
            },
            onFinish: () => {
                setProcessing(false);
            },
        });
    };

    const handleSaveSettings = () => {
        if (!business.is_owner) return;
        setProcessing(true);
        router.post(route('city.pachinko.settings', { city: cityData.slug }), {
            cost_per_ball: settingCost,
            owner_greed: settingGreed,
            number_of_decks: settingDecks,
        }, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    // ── Blackjack actions ──────────────────────────────────────────────
    // Every action reads the fresh hand from the `blackjack` flash the server
    // returns, so the client never computes game state itself.
    const bjAction = (name: 'deal' | 'hit' | 'stand' | 'double', extra: Record<string, any> = {}) => {
        if (bjProcessing) return;
        setBjProcessing(true);
        router.post(route(`city.pachinko.blackjack.${name}`, { city: cityData.slug }), extra, {
            preserveScroll: true,
            preserveState: true,
            // Only refresh what an action can change: the hand flash and the
            // player's live cash (auth). Skipping the rest keeps moves snappy.
            only: ['flash', 'auth'],
            onSuccess: (page) => {
                const next = (page.props.flash as any)?.blackjack ?? null;
                if (next) setHand(next as BlackjackHand);
            },
            onFinish: () => setBjProcessing(false),
        });
    };

    const handleDeal = () => {
        if (characterMoney < bjBet || bjProcessing) return;
        bjAction('deal', { bet: bjBet });
    };
    const clearHand = () => setHand(null);

    const formatMoney = (amount: number | null | undefined): string => {
        if (amount == null) return '$0';
        return '$' + amount.toLocaleString();
    };

    const pins = Array.from({ length: 80 }, (_, i) => ({
        id: i,
        top: `${Math.floor(i / 8) * 12 + 8}%`,
        left: `${(i % 8) * 12 + 6}%`,
        delay: `${i * 0.03}s`,
    }));

    return (
        <div className="max-w-7xl mx-auto p-4 sm:p-6 space-y-3 relative">
            <Head title={`${business.name} - ${cityData.name}`} />

            {/* ── Hero Card ────────────────────────────────────────── */}
            <motion.div
                initial={{ opacity: 0, y: 12 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.4 }}
                className="relative rounded-2xl overflow-hidden border border-white/5 shadow-2xl shrink-0 min-h-[14rem] md:min-h-[16rem] flex flex-col justify-end"
            >
                <div className="absolute inset-0 bg-slate-900 overflow-hidden">
                    <div
                        className="absolute inset-0 bg-cover bg-center transition-transform duration-1000 group-hover:scale-105"
                        style={{ backgroundImage: `url(${business.image_url || 'https://images.thedirector.app/city-placeholders/tokyo.jpg'})` }}
                    />
                    <div className="absolute inset-0 bg-gradient-to-t from-slate-950/50 via-slate-950/50 to-transparent" />
                </div>

                <div className="relative p-6 md:p-8 flex flex-col justify-end">
                    <div className="flex flex-col md:flex-row md:items-end justify-between gap-6">
                        <div className="space-y-1">
                            <div className="flex items-center gap-2">
                                <span className="text-[9px] font-black uppercase tracking-[0.3em] text-fuchsia-400">
                                    Kabukichō District</span>
                            </div>
                            <h1 className="text-3xl sm:text-5xl font-black text-white tracking-tighter uppercase filter drop-shadow-lg leading-none">
                                {business.name}
                            </h1>
                        </div>

                        <div className="flex items-center gap-4">
                            <div className="flex items-center gap-3 bg-slate-950/85 backdrop-blur-xl border border-white/5 rounded-xl pl-2.5 pr-6 py-2 shadow-2xl shrink-0">
                                <div className="w-9 h-9 rounded-lg overflow-hidden ring-2 ring-fuchsia-500/20 bg-slate-900 flex items-center justify-center shrink-0">
                                    <User size={18} className="text-fuchsia-400/50" />
                                </div>
                                <div className="text-left">
                                    <div className="text-[8px] uppercase tracking-[0.2em] text-fuchsia-400/80 font-black mb-0.5">Manager</div>
                                    <div className="text-xs text-white font-semibold leading-none">{business.owner_name}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </motion.div>

            {/* ── Sub Navigation Tab Bar (Replicating Bank.tsx styling) ── */}
            <div className="flex overflow-x-auto no-scrollbar border-b border-slate-800/40 gap-1 shrink-0 px-2 lg:px-0">
                {allTabs.map(tab => {
                    const Icon = tab.icon;
                    const active = activeTab === tab.id;
                    const isOwnerTab = tab.id === "owner";
                    return (
                        <button
                            key={tab.id}
                            onClick={() => setActiveTab(tab.id as ActiveTab)}
                            className={`flex items-center gap-2 px-5 py-3 text-[10px] font-black uppercase tracking-widest border-b-2 transition whitespace-nowrap ${active
                                ? isOwnerTab
                                    ? "border-amber-400 text-amber-400"
                                    : "border-fuchsia-500 text-white"
                                : "border-transparent text-slate-500 hover:text-slate-300"
                                }`}
                        >
                            <Icon size={14} weight={active ? "fill" : "bold"} className={active && isOwnerTab ? "text-amber-400" : ""} />
                            {tab.label}
                        </button>
                    );
                })}
            </div>

            {/* ── Dynamic Tab Content Container with framer-motion transitions ── */}
            <div className="flex-1 min-h-0">
                <AnimatePresence mode="wait">
                    <motion.div
                        key={activeTab}
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        transition={{ duration: 0.12 }}
                        className="h-full flex flex-col"
                    >
                        {/* 1. PACHINKO PANEL */}
                        {activeTab === "pachinko" && (
                            <div className="flex flex-col lg:flex-row flex-1 divide-y lg:divide-y-0 lg:divide-x divide-white/[0.04] bg-slate-950/20 border-2 border-fuchsia-500/50 rounded-2xl overflow-hidden shadow-[0_0_50px_rgba(217,70,239,0.3)]">
                                {/* Left — Machine board */}
                                <div className="flex-1 relative bg-gradient-to-b from-[#0a0612] via-[#1a0a2e] to-[#0d0015] overflow-hidden">
                                    <div className="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-pink-500 via-fuchsia-500 to-cyan-400 shadow-[0_0_20px_rgba(217,70,239,0.8)]" />
                                    <div className="absolute top-2 left-2 w-3 h-3 rounded-full bg-pink-500 shadow-[0_0_15px_rgba(236,72,153,0.8)] animate-pulse" />
                                    <div className="absolute top-2 right-2 w-3 h-3 rounded-full bg-cyan-400 shadow-[0_0_15px_rgba(34,211,238,0.8)] animate-pulse" />
                                    <div className="absolute bottom-2 left-2 w-3 h-3 rounded-full bg-amber-400 shadow-[0_0_15px_rgba(251,191,36,0.8)] animate-pulse" />
                                    <div className="absolute bottom-2 right-2 w-3 h-3 rounded-full bg-emerald-400 shadow-[0_0_15px_rgba(16,185,129,0.8)] animate-pulse" />

                                    <div className="relative h-[480px] overflow-hidden">
                                        <div className="absolute inset-0">
                                            {pins.map(pin => (
                                                <div
                                                    key={pin.id}
                                                    className="absolute w-2 h-2 bg-gradient-to-br from-yellow-300 via-amber-400 to-orange-500 rounded-full shadow-[0_0_12px_rgba(251,191,36,0.8)] animate-pinGlow"
                                                    style={{ top: pin.top, left: pin.left, animationDelay: pin.delay }}
                                                />
                                            ))}
                                        </div>
                                        {fallingBalls.map(ball => (
                                            <div
                                                key={ball.id}
                                                className="absolute bg-gradient-to-br from-red-400 via-rose-500 to-red-600 rounded-full shadow-[0_0_15px_rgba(239,68,68,0.9)] animate-ballFall"
                                                style={{ left: ball.left, width: ball.size, height: ball.size, animationDelay: ball.delay, top: '-15px' }}
                                            />
                                        ))}
                                        <div className="absolute inset-0 flex items-center justify-center">
                                            <div className="relative w-80 h-80">
                                                <div className={`absolute inset-0 border-4 border-fuchsia-500/70 rounded-full shadow-[0_0_30px_rgba(217,70,239,0.5)] ${resultAnimation === 'spinning' ? 'animate-spin-slow' : ''}`} />
                                                <div className={`absolute inset-4 border-2 border-cyan-400/60 rounded-full shadow-[0_0_20px_rgba(34,211,238,0.4)] ${resultAnimation === 'spinning' ? 'animate-spin-slower' : ''}`} />
                                                <div className={`absolute inset-8 border border-pink-400/40 rounded-full ${resultAnimation === 'spinning' ? 'animate-spin-slow' : ''}`} />
                                                <div className="absolute inset-0 flex items-center justify-center">
                                                    <div className="bg-gradient-to-br from-slate-900 to-slate-950 p-6 rounded-full border-2 border-fuchsia-500/30 shadow-[0_0_30px_rgba(217,70,239,0.3)]">
                                                        {resultAnimation === 'idle' && <Coins weight="fill" className="w-14 h-14 text-fuchsia-400 drop-shadow-[0_0_20px_rgba(232,121,249,0.7)]" />}
                                                        {resultAnimation === 'spinning' && <CircleNotch weight="bold" className="w-14 h-14 text-fuchsia-400 drop-shadow-[0_0_20px_rgba(232,121,249,0.7)] animate-spin" />}
                                                        {resultAnimation === 'win' && <Trophy weight="fill" className="w-14 h-14 text-yellow-400 drop-shadow-[0_0_25px_rgba(250,204,21,0.8)]" />}
                                                        {resultAnimation === 'loss' && <Warning weight="fill" className="w-14 h-14 text-red-400 drop-shadow-[0_0_15px_rgba(239,68,68,0.5)]" />}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {/* Right — Controls */}
                                <div className="w-full lg:w-72 shrink-0 flex flex-col p-6 gap-6 bg-slate-950/60">
                                    {/* Last Result — cleaned-up readout where the
                                        jackpot banner used to sit. Hidden while the
                                        full jackpot banner is showing up top. */}
                                    {lastResult && !showJackpotBanner && (
                                        <div className="flex items-center justify-between rounded-xl bg-[#0a0612] border border-fuchsia-500/20 px-4 py-2.5">
                                            <span className="text-[10px] font-black text-fuchsia-400 uppercase tracking-widest">Last Result</span>
                                            <span className={`text-base font-black font-mono ${lastResult.net > 0 ? 'text-emerald-400' : 'text-red-400'}`}>
                                                {lastResult.net > 0 ? '+' : ''}{formatMoney(lastResult.net)}
                                            </span>
                                        </div>
                                    )}

                                    <div>
                                        <p className="text-[10px] font-black text-fuchsia-400 uppercase tracking-widest mb-3">Balls</p>
                                        <div className="flex bg-[#0a0612] rounded-xl p-1 border border-fuchsia-500/30">
                                            {[10, 100, 500].map(amt => (
                                                <button
                                                    key={amt}
                                                    onClick={() => setBetAmount(amt)}
                                                    className={`flex-1 px-3 py-2 rounded-lg text-sm font-bold transition-all ${betAmount === amt
                                                        ? 'bg-gradient-to-r from-fuchsia-600 to-pink-600 text-white shadow-[0_0_15px_rgba(232,121,249,0.6)]'
                                                        : 'text-white/70 hover:text-white hover:bg-white/5'
                                                        }`}
                                                >
                                                    {amt}
                                                </button>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="space-y-2.5">
                                        <div className="flex justify-between items-center text-xs">
                                            <span className="text-white/80 uppercase font-bold tracking-wide">Cost per Ball</span>
                                            <span className="font-mono font-black text-white">{formatMoney(costPerBall)}</span>
                                        </div>
                                        <div className="flex justify-between items-center text-xs">
                                            <span className="text-white/80 uppercase font-bold tracking-wide">Total Cost</span>
                                            <span className={`font-mono font-black ${!canAfford ? 'text-red-400' : 'text-white'}`}>{formatMoney(totalCost)}</span>
                                        </div>
                                    </div>

                                    <button
                                        onClick={handleSpin}
                                        disabled={!canAfford || processing}
                                        className={`mt-auto py-4 px-6 rounded-xl font-black text-sm uppercase tracking-widest transition-all ${!canAfford
                                            ? 'bg-slate-800 text-slate-500 cursor-not-allowed'
                                            : processing
                                                ? 'bg-gradient-to-r from-fuchsia-900 to-pink-900 text-fuchsia-200 animate-pulse'
                                                : 'bg-gradient-to-r from-fuchsia-600 via-pink-500 to-fuchsia-600 text-white shadow-[0_0_30px_rgba(217,70,239,0.5)] hover:shadow-[0_0_50px_rgba(217,70,239,0.7)] hover:scale-[1.02]'
                                            }`}
                                    >
                                        {processing ? 'SPINNING...' : 'SPIN'}
                                    </button>
                                </div>
                            </div>
                        )}


                        {/* 3. BLACKJACK */}
                        {activeTab === "blackjack" && (
                            <div className="flex flex-col lg:flex-row flex-1 divide-y lg:divide-y-0 lg:divide-x divide-white/[0.04] bg-slate-950/20 border border-emerald-500/30 rounded-2xl overflow-hidden shadow-2xl">
                                {/* Left — felt. Cards float directly on it. */}
                                <div className="flex-1 flex flex-col justify-between p-8 gap-6 bg-gradient-to-b from-[#021810] to-[#042418] relative overflow-hidden min-h-[460px]">
                                    <div className="absolute right-0 top-0 w-80 h-80 bg-emerald-500/5 blur-[120px] rounded-full mix-blend-screen pointer-events-none" />

                                    {/* Dealer */}
                                    <div className="flex flex-col items-center gap-3 relative z-10">
                                        <div className="flex items-center gap-3">
                                            <span className="text-[9px] font-black uppercase text-emerald-300/60 tracking-[0.25em]">Dealer</span>
                                            {hand?.dealer_total != null && (
                                                <span className="text-xs font-mono font-bold text-white">{hand.dealer_total}</span>
                                            )}
                                        </div>
                                        <div className="flex gap-2.5 min-h-[5rem] sm:min-h-[6rem] items-center">
                                            <AnimatePresence>
                                                {hand ? (
                                                    <>
                                                        {hand.dealer.map((card, i) => (
                                                            <PlayingCard key={`d-${i}`} value={card} index={i} />
                                                        ))}
                                                        {hand.dealer_hidden && <PlayingCard key="d-hole" index={1} hidden />}
                                                    </>
                                                ) : (
                                                    <DeckStack />
                                                )}
                                            </AnimatePresence>
                                        </div>
                                    </div>

                                    {/* Center result banner */}
                                    <div className="flex items-center justify-center relative z-10 h-8">
                                        <AnimatePresence>
                                            {hand?.phase === 'settled' && (
                                                <motion.div
                                                    initial={{ opacity: 0, scale: 0.8 }}
                                                    animate={{ opacity: 1, scale: 1 }}
                                                    exit={{ opacity: 0 }}
                                                    className={`px-5 py-1.5 rounded-full font-black text-xs uppercase tracking-[0.25em] ${hand.result === 'lose'
                                                        ? 'bg-red-500/15 text-red-300 border border-red-500/30'
                                                        : 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30'
                                                        }`}
                                                >
                                                    {hand.result === 'blackjack' && 'Blackjack!'}
                                                    {hand.result === 'win' && `You win +${formatMoney((hand.net ?? 0))}`}
                                                    {hand.result === 'push' && 'Push — bet returned'}
                                                    {hand.result === 'lose' && `You lose ${formatMoney(hand.bet)}`}
                                                </motion.div>
                                            )}
                                        </AnimatePresence>
                                    </div>

                                    {/* Player */}
                                    <div className="flex flex-col items-center gap-3 relative z-10">
                                        <div className="flex gap-2.5 min-h-[5rem] sm:min-h-[6rem] items-center">
                                            <AnimatePresence>
                                                {hand ? (
                                                    hand.player.map((card, i) => (
                                                        <PlayingCard key={`p-${i}`} value={card} index={i} />
                                                    ))
                                                ) : (
                                                    <DeckStack />
                                                )}
                                            </AnimatePresence>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <span className="text-[9px] font-black uppercase text-emerald-300/60 tracking-[0.25em]">You</span>
                                            {hand && (
                                                <span className="text-sm font-mono font-bold text-emerald-400">
                                                    {hand.player_total}
                                                    {hand.doubled && <span className="ml-2 text-[9px] text-amber-400">DOUBLED</span>}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {/* Right — action panel */}
                                <div className="w-full lg:w-72 shrink-0 flex flex-col p-6 gap-5 bg-slate-950/50">
                                    <div className="flex justify-between items-baseline">
                                        <p className="text-[10px] font-black text-emerald-400 uppercase tracking-widest">Blackjack</p>
                                        <span className="text-[10px] font-mono text-white/70">pays 3:2</span>
                                    </div>

                                    {/* No hand in progress → bet + deal */}
                                    {(!hand || hand.phase === 'settled') && (
                                        <>
                                            <div>
                                                <p className="text-[10px] font-bold text-white/70 uppercase tracking-wide mb-2">Bet</p>
                                                <div className="grid grid-cols-2 gap-1.5">
                                                    {[1000, 2500, 5000, 10000].map(amt => (
                                                        <button
                                                            key={amt}
                                                            onClick={() => setBjBet(amt)}
                                                            disabled={amt > characterMoney}
                                                            className={`px-3 py-2 rounded-lg text-sm font-bold transition-all ${bjBet === amt
                                                                ? 'bg-gradient-to-r from-emerald-600 to-green-600 text-white shadow-[0_0_15px_rgba(16,185,129,0.5)]'
                                                                : amt > characterMoney
                                                                    ? 'bg-slate-900 text-slate-600 cursor-not-allowed'
                                                                    : 'text-white/70 hover:text-white hover:bg-white/5 bg-[#0a1410]'
                                                                }`}
                                                        >
                                                            {formatMoney(amt)}
                                                        </button>
                                                    ))}
                                                </div>
                                            </div>

                                            <button
                                                onClick={handleDeal}
                                                disabled={characterMoney < bjBet || bjProcessing}
                                                className={`mt-auto py-4 px-6 rounded-xl font-black text-sm uppercase tracking-widest transition-all ${characterMoney < bjBet
                                                    ? 'bg-slate-800 text-slate-500 cursor-not-allowed'
                                                    : bjProcessing
                                                        ? 'bg-emerald-900 text-emerald-200 animate-pulse'
                                                        : 'bg-gradient-to-r from-emerald-600 to-green-500 text-white shadow-[0_0_25px_rgba(16,185,129,0.4)] hover:scale-[1.02]'
                                                    }`}
                                            >
                                                {bjProcessing ? 'Dealing…' : (hand?.phase === 'settled' ? 'Deal Again' : 'Deal')}
                                            </button>
                                        </>
                                    )}

                                    {/* Hand in progress → hit / stand / double */}
                                    {hand && hand.phase === 'player_turn' && (
                                        <div className="flex flex-col gap-2.5 mt-auto">
                                            <div className="flex justify-between items-center text-xs mb-1">
                                                <span className="text-white/70 uppercase font-bold tracking-wide">Bet</span>
                                                <span className="font-mono font-black text-white">{formatMoney(hand.bet)}</span>
                                            </div>
                                            <div className="grid grid-cols-2 gap-2.5">
                                                <button
                                                    onClick={() => bjAction('hit')}
                                                    disabled={bjProcessing}
                                                    className="py-3.5 rounded-xl bg-gradient-to-r from-emerald-600 to-green-500 text-white font-black text-xs uppercase tracking-widest shadow-[0_0_15px_rgba(16,185,129,0.35)] hover:scale-[1.02] transition-all disabled:opacity-50"
                                                >
                                                    Hit
                                                </button>
                                                <button
                                                    onClick={() => bjAction('stand')}
                                                    disabled={bjProcessing}
                                                    className="py-3.5 rounded-xl bg-slate-800 text-white font-black text-xs uppercase tracking-widest hover:bg-slate-700 transition-all disabled:opacity-50"
                                                >
                                                    Stand
                                                </button>
                                            </div>
                                            <button
                                                onClick={() => bjAction('double')}
                                                disabled={bjProcessing || !hand.can_double || characterMoney < hand.bet}
                                                className="py-3 rounded-xl border border-amber-500/40 text-amber-300 font-black text-xs uppercase tracking-widest hover:bg-amber-500/10 transition-all disabled:opacity-30 disabled:cursor-not-allowed"
                                            >
                                                Double Down
                                            </button>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* 4. BUSINESS OWNER PANEL (Directly on-page!) */}
                        {activeTab === "owner" && business.is_owner && business.owner_settings && (
                            <div className="flex flex-col lg:flex-row flex-1 divide-y lg:divide-y-0 lg:divide-x divide-white/[0.04] bg-slate-950/20 border border-amber-500/30 rounded-2xl overflow-hidden">
                                {/* Left — Description */}
                                <div className="flex-1 flex flex-col items-center justify-center p-8 gap-3 text-center">

                                    <h3 className="text-xl font-light text-white tracking-tight">Owner's Dashboard</h3>
                                    <p className="text-sm text-white/80 leading-relaxed max-w-sm">
                                        Manage your Pachinko parlor house edges to keep the money rolling in. Keep in mind that bets are automatically rejected if the possible losses cannot be covered by your balance sheet.
                                    </p>
                                </div>

                                {/* Right — Settings sliders Form */}
                                <div className="w-full lg:w-80 shrink-0 flex flex-col p-6 gap-6 bg-slate-950/40">
                                    <p className="text-xs font-black text-amber-300 uppercase tracking-[0.2em]">Owner Adjustments</p>

                                    <div className="space-y-6">
                                        {/* Cost per Ball slider */}
                                        <div>
                                            <div className="flex justify-between items-baseline mb-2">
                                                <span className="text-xs font-bold text-white uppercase">Cost per Ball</span>
                                                <span className="text-sm font-mono font-bold text-yellow-400">${settingCost}</span>
                                            </div>
                                            <input
                                                type="range"
                                                min="100"
                                                max="1000"
                                                step="10"
                                                value={settingCost}
                                                onChange={(e) => setSettingCost(parseInt(e.target.value))}
                                                className="w-full h-1.5 bg-slate-800 rounded-full appearance-none cursor-pointer accent-amber-400"
                                            />
                                            <div className="flex justify-between text-[10px]  font-bold text-white/60 mt-1.5">
                                                <span>$100</span>

                                                <span>$1,000</span>
                                            </div>
                                        </div>

                                        {/* House Greed slider */}
                                        <div>
                                            <div className="flex justify-between items-baseline mb-2">
                                                <span className="text-xs font-bold text-white uppercase">House Edge (Greed)</span>
                                                <span className="text-sm font-mono font-bold text-yellow-400">{(settingGreed * 100).toFixed(0)}%</span>
                                            </div>
                                            <input
                                                type="range"
                                                min="-0.05"
                                                max="0.05"
                                                step="0.01"
                                                value={settingGreed}
                                                onChange={(e) => setSettingGreed(parseFloat(e.target.value))}
                                                className="w-full h-1.5 bg-slate-800 rounded-full appearance-none cursor-pointer accent-amber-400"
                                            />
                                            <div className="flex justify-between text-[10px] font-bold text-white/60 mt-1.5">
                                                <span>-5% (Player's favor)</span>
                                                <span>+5% (House's favor)</span>
                                            </div>
                                        </div>

                                        {/* Blackjack decks slider — more decks = higher house edge */}
                                        <div>
                                            <div className="flex justify-between items-baseline mb-2">
                                                <span className="text-xs font-bold text-white uppercase">Blackjack Decks</span>
                                                <span className="text-sm font-mono font-bold text-yellow-400">{settingDecks}</span>
                                            </div>
                                            <input
                                                type="range"
                                                min="1"
                                                max="8"
                                                step="1"
                                                value={settingDecks}
                                                onChange={(e) => setSettingDecks(parseInt(e.target.value))}
                                                className="w-full h-1.5 bg-slate-800 rounded-full appearance-none cursor-pointer accent-amber-400"
                                            />
                                            <div className="flex justify-between text-[10px] font-bold text-white/60 mt-1.5">
                                                <span>1 (Player's favor)</span>
                                                <span>8 (House's favor)</span>
                                            </div>
                                        </div>

                                        {/* Save settings Button */}
                                        <motion.button
                                            whileTap={{ scale: 0.97 }}
                                            onClick={handleSaveSettings}
                                            disabled={processing}
                                            className="w-full py-3.5 bg-gradient-to-r from-amber-500 to-yellow-400 text-slate-900 font-black text-xs uppercase tracking-widest rounded-xl disabled:opacity-50 hover:shadow-[0_0_20px_rgba(245,158,11,0.4)] transition-all flex items-center justify-center gap-2 mt-4"
                                        >
                                            {processing ? (
                                                <>
                                                    <div className="w-3.5 h-3.5 border border-slate-900/30 border-t-slate-900 rounded-full animate-spin" />
                                                    SAVING...
                                                </>
                                            ) : (
                                                <>
                                                    <Gear size={13} weight="bold" />
                                                    UPDATE SETTINGS
                                                </>
                                            )}
                                        </motion.button>
                                    </div>
                                </div>
                            </div>
                        )}
                    </motion.div>
                </AnimatePresence>
            </div>

            {/* Neon board keyframe rules */}
            <style>{`
                @keyframes gradient-x {
                    0%, 100% { background-position: 0% 50%; }
                    50% { background-position: 100% 50%; }
                }
                .animate-gradient-x {
                    animation: gradient-x 2s ease infinite;
                    background-size: 200% 200%;
                }
                @keyframes spin-slow {
                    from { transform: rotate(0deg); }
                    to { transform: rotate(360deg); }
                }
                .animate-spin-slow {
                    animation: spin-slow 6s linear infinite;
                }
                .animate-spin-slower {
                    animation: spin-slow 9s linear infinite;
                }
                @keyframes ballFall {
                    0% { top: -15px; opacity: 1; }
                    80% { opacity: 1; }
                    100% { top: 100%; opacity: 0; }
                }
                .animate-ballFall {
                    animation: ballFall 1.5s ease-in forwards;
                }
                @keyframes pinGlow {
                    0%, 100% { opacity: 0.5; transform: scale(0.8); }
                    50% { opacity: 1; transform: scale(1.2); box-shadow: 0 0 20px gold; }
                }
                .animate-pinGlow {
                    animation: pinGlow 2s infinite;
                }
                @keyframes confetti {
                    0% { top: -20px; transform: rotate(0deg); opacity: 1; }
                    100% { top: 100%; transform: rotate(720deg); opacity: 0; }
                }
                .animate-confetti {
                    animation: confetti 2.5s ease-out forwards;
                }
                .animate-pulse-fast {
                    animation: pulse 0.6s cubic-bezier(0.4, 0, 0.6, 1) infinite;
                }
                .animate-bounce {
                    animation: bounce 0.8s ease infinite;
                }
            `}</style>
        </div>
    );
}

Pachinko.layout = (page: React.ReactNode) => <GameLayout wide children={page} />;
