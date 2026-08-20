import React, { useState, useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
import { motion, AnimatePresence } from 'framer-motion';
// @ts-ignore
import { route } from 'ziggy-js';
import {
    Bank, HandCoins, ChartLineUp, ChartBar,
    Lock, ArrowUp, ArrowDown, Warning, TrendUp, TrendDown,
    ArrowFatUp, ArrowFatDown, UserMinus, User, Shuffle,
} from '@phosphor-icons/react';
import GameLayout from '@/Layouts/GameLayout';
import { formatUTC, parseSymbolicAmount } from '@/Layouts/GameLayoutComponents';
import type { TickerState } from '@/utils/tickerEngine';
import { useMarketFeed, TickerStrip, MiniChart, RegimeBadge } from '@/Components/Tickers';


interface BankData {
    name: string;
    image: string | null;
    description: string | null;
    balance: number;
    position_cap_percent: number;
}

interface Personnel {
    name: string;
    avatar_url: string | null;
}

interface TradeRow {
    id: number;
    banker: string;
    type: 'trade_win' | 'trade_loss';
    ticker: string;
    direction: string;
    amount: number;
    created_at: string;
}

interface DismissableBanker {
    id: number;
    name: string;
    avatar_url: string | null;
    rank_name: string;
    rank: number;
}

interface LaunderClient {
    id: number;
    client_id: number;
    client_name: string;
    client_avatar: string | null;
    amount: number;
    cut_pct: number;
    amount_sent: number;
    status: 'pending' | 'client_sent';
}

interface LaunderRelationship {
    id: number;
    banker_id: number;
    banker_name: string;
    banker_avatar: string | null;
    amount: number;
    cut_pct: number;
    amount_sent: number;
    status: 'pending' | 'client_sent';
    bank_overhead: number;
}

interface LaunderConstants {
    overhead: number;
    cut_min: number;
    cut_max: number;
    amount_max: number;
    amount_min: number;
}

interface Props {
    bank: BankData | null;
    owner: Personnel | null;
    manager: Personnel | null;
    rank: number;
    rank_label: string;
    is_manager: boolean;
    is_owner: boolean;
    financial_reports: TradeRow[] | null;
    dismissable_bankers: DismissableBanker[];
    launder_clients?: LaunderClient[];
    launder_relationships?: LaunderRelationship[];
    launder_constants?: LaunderConstants;
    market_server_time: number;
    ticker_snapshots: TickerState[];
}

const fmt$ = (n: number) => `$${Math.round(n).toLocaleString()}`;



interface EquitiesTerminalProps {
    bankBalance: number;
    hasBank: boolean;
    positionCapPercent: number;
    initialSnapshots: TickerState[];
    initialMarketTime: number;
}

export function EquitiesTerminal({ bankBalance, hasBank, positionCapPercent, initialSnapshots, initialMarketTime }: EquitiesTerminalProps) {
    const { states, utcNow, marketTime } = useMarketFeed(initialSnapshots, initialMarketTime, route('career.banking.tickers'));

    const [selectedSymbol, setSelectedSymbol] = useState<string>(initialSnapshots[0]?.profile.symbol ?? 'JPM');
    const [direction, setDirection] = useState<'call' | 'put'>('call');
    const [amount, setAmount] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const selectedState = states.find(s => s.profile.symbol === selectedSymbol) ?? states[0];
    const profile = selectedState?.profile;
    const regime = selectedState?.regime ?? 'sideways';

    const MIN_POSITION = 1_000;
    const capPercent = Math.min(25, Math.max(5, Math.round(Number(positionCapPercent || 10))));
    const maxPosition = Math.floor(bankBalance * (capPercent / 100));
    const parsedAmount = parseSymbolicAmount(amount);
    const belowMin = parsedAmount > 0 && parsedAmount < MIN_POSITION;
    const exceedsCap = parsedAmount > maxPosition;
    const exceedsBank = parsedAmount > bankBalance;
    const canSubmit = parsedAmount >= MIN_POSITION && !exceedsCap && !exceedsBank && !submitting && hasBank;
    //! MAKE SURE THIS IS MIRRORED EXACTLY.
    // Win preview — mirrors executeTrade() on the server exactly:
    //   grossReturn = floor(stake × mult)
    //   netGain     = grossReturn − stake   (stake was already in the bank)
    //   rawCut      = floor(netGain × position cap percent)
    //   yourCut     = min(rawCut, 150_000)  ← $150k personal cap enforced server-side
    //   bankGain    = netGain − yourCut
    // Loss depth is server-only and intentionally not shown here.
    const mult = profile?.payoutMultiplier ?? 1.5;
    const grossReturn = parsedAmount > 0 ? Math.floor(parsedAmount * mult) : 0;
    const netGain = grossReturn - parsedAmount;
    const rawBankerCut = parsedAmount > 0 ? Math.floor(netGain * (capPercent / 100)) : 0;
    const estimatedWin = Math.min(rawBankerCut, 150_000);
    const estimatedBank = netGain - estimatedWin;
    const cutIsCapped = rawBankerCut > 150_000;

    const handleTrade = () => {
        if (!canSubmit) return;
        setSubmitting(true);
        router.post(
            route('career.banking.trade'),
            { ticker: selectedSymbol, direction, amount: parsedAmount, market_time: marketTime || undefined },
            {
                preserveScroll: true,
                onFinish: () => { setSubmitting(false); setAmount(''); },
            },
        );
    };

    if (!hasBank || !selectedState || !profile) {
        return (
            <div className="flex flex-col items-center justify-center h-64 text-white/70 gap-3">
                <Warning size={28} />
                <p className="text-[10px] font-black uppercase tracking-widest">{hasBank ? 'Market feed unavailable' : 'No bank found in your home city'}</p>
            </div>
        );
    }

    return (
        <div className="flex flex-col h-full">

            {/* ── Top bar ─────────────────────────────────────────────── */}
            <div className="flex flex-wrap items-center px-5 py-3 sm:py-2.5 border-b border-white/[0.04] bg-slate-950/60 shrink-0 gap-x-6 gap-y-2">
                <div className="flex items-center gap-2">
                    <span className="w-2 h-2 rounded-full bg-emerald-400 shadow-[0_0_6px_rgba(52,211,153,0.7)] animate-pulse" />
                    <span className="text-xs font-black uppercase tracking-[0.2em] text-emerald-400/70">
                        LIVE MARKET FEED
                    </span>
                    <span className="text-xs text-white/80 font-mono ml-2 flex items-center gap-2">
                        {formatUTC(utcNow)}
                        <span className="text-white/65 font-bold ml-1">Account #88888</span>
                    </span>
                </div>
                <div className="flex flex-wrap items-center gap-3 sm:gap-5 text-xs text-white/80 uppercase tracking-widest">
                    <span>Capital: <span className="text-emerald-400">{fmt$(bankBalance)}</span></span>
                    <span>Max: <span className="text-amber-400">{fmt$(maxPosition)}</span> <span className="text-white/50">({capPercent}%)</span></span>

                </div>
            </div>

            {/* ── Ticker strip ────────────────────────────────────────── */}
            <TickerStrip
                states={states}
                selectedSymbol={selectedSymbol}
                onSelect={setSelectedSymbol}
            />

            {/* ── Main content ────────────────────────────────────────── */}
            <div className="flex flex-col lg:flex-row flex-1 min-h-0 divide-y lg:divide-y-0 lg:divide-x divide-white/[0.04] overflow-hidden">

                {/* Left — instrument detail */}
                <div className="flex-1 flex flex-col p-5 gap-4 overflow-y-auto">
                    <AnimatePresence mode="wait">
                        {selectedState && (
                            <motion.div
                                key={selectedSymbol}
                                initial={{ opacity: 0, y: 6 }}
                                animate={{ opacity: 1, y: 0 }}
                                exit={{ opacity: 0, y: -6 }}
                                transition={{ duration: 0.18 }}
                                className="flex flex-col gap-4"
                            >
                                {/* Instrument header */}
                                <div className="flex items-start justify-between gap-4">
                                    <div>
                                        <p className="text-[9px] text-cyan-200/70 uppercase tracking-widest mb-0.5">
                                            {profile.exchange} · {profile.sector}
                                        </p>
                                        <h3 className="text-xl font-light text-white tracking-tight">
                                            {profile.name}
                                        </h3>
                                        <div className="flex items-baseline gap-3 mt-1">
                                            <motion.span
                                                key={Math.round(selectedState.price * 100)}
                                                initial={{ scale: 1.04 }}
                                                animate={{ scale: 1 }}
                                                className={`text-3xl font-light tabular-nums ${selectedState.priceDelta >= 0 ? 'text-emerald-400' : 'text-red-400'
                                                    }`}
                                            >
                                                ${selectedState.price.toFixed(2)}
                                            </motion.span>
                                            <span className={`flex items-center gap-0.5 text-sm ${selectedState.priceDelta >= 0 ? 'text-emerald-500' : 'text-red-500'
                                                }`}>
                                                {selectedState.priceDelta >= 0
                                                    ? <TrendUp size={14} weight="bold" />
                                                    : <TrendDown size={14} weight="bold" />
                                                }
                                                {selectedState.priceDelta >= 0 ? '+' : ''}
                                                {selectedState.priceDelta.toFixed(2)}
                                            </span>
                                        </div>
                                    </div>

                                    <div className="text-right shrink-0 flex flex-col items-end gap-1.5">
                                        <RegimeBadge regime={regime} />
                                        <p className="text-[9px] text-white/60 uppercase tracking-widest">
                                            {profile.payoutMultiplier.toFixed(2)}× on win
                                        </p>
                                    </div>
                                </div>

                                {/* Chart */}
                                <MiniChart state={selectedState} />

                                {/* Win preview — only shown for valid, in-range positions */}
                                {parsedAmount >= MIN_POSITION && !exceedsCap && !exceedsBank && (
                                    <motion.div
                                        initial={{ opacity: 0, height: 0 }}
                                        animate={{ opacity: 1, height: 'auto' }}
                                        exit={{ opacity: 0, height: 0 }}
                                        className="border border-white/[0.05] rounded-xl overflow-hidden"
                                    >
                                        <div className="px-4 py-3 bg-slate-950/40">
                                            <p className="text-xs text-white/75 uppercase tracking-widest mb-2">
                                                Position Preview · {fmt$(parsedAmount)} stake
                                            </p>
                                            <div className="bg-emerald-500/5 border border-emerald-500/10 rounded-lg px-3 py-2 flex flex-col gap-1.5">
                                                <p className="text-[9px] text-white/65 uppercase tracking-widest">If Closed Green · Net gain</p>
                                                <div className="flex items-baseline justify-between">
                                                    <span className="text-[10px] text-white/75 uppercase tracking-widest">Your cut</span>
                                                    <span className="text-sm text-emerald-400 tabular-nums font-semibold">
                                                        +{fmt$(estimatedWin)}
                                                        {cutIsCapped && (
                                                            <span className="ml-1.5 text-[9px] text-amber-400/70 font-normal normal-case tracking-normal">capped</span>
                                                        )}
                                                    </span>
                                                </div>
                                                <div className="flex items-baseline justify-between">
                                                    <span className="text-[10px] text-white/65 uppercase tracking-widest">Bank gains</span>
                                                    <span className="text-[11px] text-emerald-500/60 tabular-nums">+{fmt$(estimatedBank)}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </motion.div>
                                )}
                            </motion.div>
                        )}
                    </AnimatePresence>
                </div>

                {/* Right — order entry */}
                <div className="w-full lg:w-72 shrink-0 flex flex-col p-5 gap-4 bg-slate-950/20">
                    <p className="text-xs font-black text-cyan-300 uppercase tracking-[0.2em]">
                        Order Entry
                    </p>

                    {/* Direction */}
                    <div className="grid grid-cols-2 gap-2">
                        {(['call', 'put'] as const).map(d => (
                            <motion.button
                                key={d}
                                whileTap={{ scale: 0.97 }}
                                onClick={() => setDirection(d)}
                                className={`flex items-center justify-center gap-1.5 py-3 rounded-xl text-xs font-black uppercase tracking-widest border transition-all ${direction === d
                                    ? d === 'call'
                                        ? 'bg-emerald-500/15 border-emerald-500/40 text-emerald-400'
                                        : 'bg-red-500/15 border-red-500/40 text-red-400'
                                    : 'border-white/[0.06] text-white/65 hover:border-white/15 hover:text-white'
                                    }`}
                            >
                                {d === 'call'
                                    ? <ArrowUp size={12} weight="bold" />
                                    : <ArrowDown size={12} weight="bold" />
                                }
                                {d.toUpperCase()}
                            </motion.button>
                        ))}
                    </div>

                    {/* Amount */}
                    <div>
                        <div className="flex justify-between items-baseline mb-2">
                            <label className="text-xs text-white/75 uppercase tracking-widest">
                                Position Size
                            </label>
                            <button
                                type="button"
                                onClick={() => setAmount(String(maxPosition))}
                                className="text-xs text-white/60 hover:text-amber-400 uppercase tracking-widest transition-colors"
                            >
                                ADD MAX
                            </button>
                        </div>
                        <input
                            type="text"
                            value={amount}
                            onChange={e => setAmount(e.target.value.replace(/[^0-9kmKMbB.]/g, ''))}
                            placeholder="0"
                            disabled={submitting}
                            className={`w-full px-4 py-3 bg-slate-950/70 border rounded-xl text-white font-mono text-base placeholder:text-white/35 focus:outline-none transition-colors disabled:opacity-50 ${belowMin || exceedsCap || exceedsBank
                                ? 'border-red-500/40 focus:border-red-500/60'
                                : 'border-white/[0.07] focus:border-emerald-500/30'
                                }`}
                        />
                        {(belowMin || exceedsCap || exceedsBank) && (
                            <motion.p
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                className="text-xs text-red-400 mt-1.5 flex items-center gap-1"
                            >
                                <Warning size={10} weight="fill" />
                                {belowMin
                                    ? 'Minimum position is $1,000'
                                    : exceedsBank
                                        ? 'Exceeds bank capital'
                                        : `Exceeds ${capPercent}% position cap`}
                            </motion.p>
                        )}
                    </div>

                    {/* Execute */}
                    <motion.button
                        whileTap={canSubmit ? { scale: 0.97 } : {}}
                        onClick={handleTrade}
                        disabled={!canSubmit}
                        className={`w-full py-3 rounded-xl text-xs font-black uppercase tracking-widest flex items-center justify-center gap-2 transition-all ${canSubmit
                            ? direction === 'call'
                                ? 'bg-emerald-700 hover:bg-emerald-600 text-white shadow-[0_0_20px_rgba(52,211,153,0.15)]'
                                : 'bg-red-800 hover:bg-red-700 text-white shadow-[0_0_20px_rgba(239,68,68,0.15)]'
                            : 'bg-slate-800/60 text-white/35 cursor-not-allowed'
                            }`}
                    >
                        {submitting ? (
                            <>
                                <div className="w-3 h-3 border border-white/30 border-t-white rounded-full animate-spin" />
                                Executing…
                            </>
                        ) : (
                            `${direction === 'call' ? 'CALL' : 'PUT'} · ${selectedState?.profile.displaySymbol}`
                        )}
                    </motion.button>
                </div>
            </div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────
// StaffPanel — manager dismisses bankers ranked below them
// ─────────────────────────────────────────────────────────────

function PositionCapControl({ initialCap }: { initialCap: number }) {
    const clamp = (value: number) => Math.min(25, Math.max(5, Math.round(value)));
    const [cap, setCap] = useState(String(clamp(initialCap || 10)));
    const [savingCap, setSavingCap] = useState(false);
    const parsedCap = Number(cap);
    const capValid = Number.isInteger(parsedCap) && parsedCap >= 5 && parsedCap <= 25;

    const saveCap = () => {
        if (savingCap || !capValid) return;

        setSavingCap(true);
        router.post(
            route('career.banking.position-cap'),
            { position_cap_percent: parsedCap },
            {
                preserveScroll: true,
                onFinish: () => setSavingCap(false),
            },
        );
    };

    return (
        <div className="flex flex-col gap-3 border-b border-white/[0.06] px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p className="text-[10px] font-black uppercase tracking-[0.25em] text-cyan-300">Trading Risk</p>

                <p className="text-xs font-medium text-white/70">Limits each position and sets the Banker win cut. 5-25%. Default 10%.</p>
            </div>
            <div className="flex items-center gap-2">
                <input
                    type="number"
                    min={5}
                    max={25}
                    step={1}
                    value={cap}
                    onChange={e => setCap(e.target.value)}
                    disabled={savingCap}
                    className={`h-9 w-20 rounded-lg border bg-slate-950/70 px-3 text-center text-sm font-black text-white outline-none transition-colors ${capValid ? 'border-white/10 focus:border-cyan-400/50' : 'border-red-500/50 focus:border-red-400'}`}
                />
                <span className="text-sm font-black text-white">%</span>
                <button
                    type="button"
                    onClick={saveCap}
                    disabled={savingCap || !capValid}
                    className="ml-2 h-9 rounded-lg bg-cyan-700 px-4 text-[10px] font-black uppercase tracking-widest text-white transition-colors hover:bg-cyan-600 disabled:bg-slate-800 disabled:text-white/35"
                >
                    {savingCap ? 'Saving...' : 'Save'}
                </button>
            </div>
        </div>
    );
}

function StaffPanel({ bankers, positionCapPercent }: { bankers: DismissableBanker[]; positionCapPercent: number }) {
    const [dismissing, setDismissing] = useState<number | null>(null);
    const [confirm, setConfirm] = useState<number | null>(null);

    const handleDismiss = (id: number) => {
        setDismissing(id);
        router.post(
            route('career.banking.dismiss'),
            { character_id: id },
            {
                preserveScroll: true,
                onFinish: () => { setDismissing(null); setConfirm(null); },
            },
        );
    };

    return (
        <div className="h-full">
            <PositionCapControl initialCap={positionCapPercent} />
            <div className="px-6 py-5">
                <p className="text-[10px] font-black text-cyan-300 uppercase tracking-[0.25em] mb-4">
                    Banking Staff
                </p>
                {bankers.length === 0 ? (
                    <div className="mt-8 flex flex-col items-center justify-center py-12 text-white/55 gap-2 border-y border-white/[0.04]">
                        <UserMinus size={24} />
                        <p className="text-xs font-black uppercase tracking-widest text-slate-300">No staff to manage</p>
                    </div>
                ) : (
                    <div className="mt-4 divide-y divide-white/[0.05] border-y border-white/[0.06]">
                        {bankers.map(b => (
                            <div
                                key={b.id}
                                className="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:gap-4"
                            >
                                <div className="w-9 h-9 rounded-lg bg-slate-800 border border-white/5 overflow-hidden shrink-0 flex items-center justify-center">
                                    {b.avatar_url
                                        ? <img src={b.avatar_url} className="w-full h-full object-cover" alt="" />
                                        : <User size={13} className="text-white/45" />
                                    }
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm text-white font-semibold truncate">{b.name}</p>
                                    <p className="text-[10px] text-white/65 uppercase tracking-wider">{b.rank_name}</p>
                                </div>
                                {confirm === b.id ? (
                                    <div className="flex flex-wrap items-center gap-2 shrink-0">
                                        <span className="text-[10px] text-amber-300 font-bold uppercase tracking-widest">Confirm dismissal</span>
                                        <button
                                            onClick={() => handleDismiss(b.id)}
                                            disabled={dismissing === b.id}
                                            className="px-3 py-1.5 text-[10px] font-black uppercase tracking-widest bg-red-800 hover:bg-red-700 text-white rounded-lg disabled:opacity-40 transition-colors"
                                        >
                                            {dismissing === b.id ? 'Dismissing…' : 'Yes, Dismiss'}
                                        </button>
                                        <button
                                            onClick={() => setConfirm(null)}
                                            className="px-3 py-1.5 text-[10px] font-black uppercase tracking-widest border border-white/10 text-slate-300 hover:text-white rounded-lg transition-colors"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                ) : (
                                    <button
                                        onClick={() => setConfirm(b.id)}
                                        className="flex items-center justify-center gap-1.5 px-3 py-1.5 text-[10px] font-black uppercase tracking-widest border border-red-500/20 text-red-300 hover:border-red-500/40 hover:text-red-200 rounded-lg transition-colors shrink-0"
                                    >
                                        <UserMinus size={12} weight="bold" /> Dismiss
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

// FinancialReports — rank-4 manager only
// ─────────────────────────────────────────────────────────────

const PAGE_SIZE = 5;

function FinancialReports({ rows }: { rows: TradeRow[] }) {
    const [page, setPage] = useState(0);

    const wins = rows.filter(r => r.type === 'trade_win');
    const losses = rows.filter(r => r.type === 'trade_loss');
    const totalWon = wins.reduce((s, r) => s + r.amount, 0);
    const totalLost = losses.reduce((s, r) => s + r.amount, 0);
    const netPnl = totalWon - totalLost;
    const totalPages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
    const pageRows = rows.slice(page * PAGE_SIZE, page * PAGE_SIZE + PAGE_SIZE);

    return (
        <div className="flex flex-col h-full">

            {/* ── Summary strip ── */}
            <div className="flex flex-col md:flex-row items-start md:items-center gap-4 md:gap-8 px-6 py-4 border-b border-white/[0.06] bg-slate-950/60 shrink-0">
                <div className="flex items-center gap-2.5">
                    <ArrowFatUp size={16} weight="fill" className="text-emerald-400" />
                    <span className="text-xs text-white/70 uppercase tracking-widest">Win payouts</span>
                    <span className="text-base text-emerald-400 tabular-nums font-semibold ml-1">{fmt$(totalWon)}</span>
                    <span className="text-xs text-white/45 ml-1">({wins.length})</span>
                </div>
                <div className="flex items-center gap-2.5">
                    <ArrowFatDown size={16} weight="fill" className="text-red-400" />
                    <span className="text-xs text-white/70 uppercase tracking-widest">Bank losses</span>
                    <span className="text-base text-red-400 tabular-nums font-semibold ml-1">{fmt$(totalLost)}</span>
                    <span className="text-xs text-white/45 ml-1">({losses.length})</span>
                </div>
                <div className="flex items-center gap-2.5 md:ml-auto">
                    <span className="text-xs text-white/70 uppercase tracking-widest">Net P&amp;L</span>
                    <span className={`text-base tabular-nums font-bold ${netPnl >= 0 ? 'text-emerald-400' : 'text-red-400'}`}>
                        {netPnl >= 0 ? '+' : ''}{fmt$(netPnl)}
                    </span>
                </div>
            </div>

            {/* ── Trade log ── */}
            {rows.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-16 text-white/45 gap-2">
                    <ChartBar size={28} />
                    <p className="text-sm uppercase tracking-widest">No trades on record</p>
                </div>
            ) : (
                <>
                    <div className="overflow-auto flex-1">
                        <table className="w-full border-collapse">
                            <thead className="sticky top-0 bg-slate-950/95">
                                <tr className="text-left border-b border-white/[0.06]">
                                    <th className="px-6 py-3 text-xs font-semibold text-cyan-200 uppercase tracking-widest">Banker</th>
                                    <th className="px-6 py-3 text-xs font-semibold text-cyan-200 uppercase tracking-widest">Ticker</th>
                                    <th className="px-6 py-3 text-xs font-semibold text-cyan-200 uppercase tracking-widest">Direction</th>
                                    <th className="px-6 py-3 text-xs font-semibold text-cyan-200 uppercase tracking-widest text-right">Amount</th>
                                    <th className="px-6 py-3 text-xs font-semibold text-cyan-200 uppercase tracking-widest">Result</th>
                                    <th className="px-6 py-3 text-xs font-semibold text-cyan-200 uppercase tracking-widest">Time (UTC)</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/[0.03]">
                                {pageRows.map(row => (
                                    <tr key={row.id} className="hover:bg-white/[0.025] transition-colors">
                                        <td className="px-6 py-3.5 text-sm text-slate-200 font-medium">{row.banker}</td>
                                        <td className="px-6 py-3.5 text-sm text-slate-300 font-bold tracking-wide">{row.ticker || '—'}</td>
                                        <td className="px-6 py-3.5">
                                            <span className={`text-sm font-bold uppercase tracking-widest ${row.direction === 'CALL' ? 'text-emerald-400' : 'text-red-400'
                                                }`}>
                                                {row.direction || '—'}
                                            </span>
                                        </td>
                                        <td className={`px-6 py-3.5 text-sm tabular-nums font-semibold text-right ${row.type === 'trade_win' ? 'text-emerald-400' : 'text-red-400'
                                            }`}>
                                            {row.type === 'trade_win' ? '+' : '−'}{fmt$(row.amount)}
                                        </td>
                                        <td className="px-6 py-3.5">
                                            <span className={`inline-flex items-center text-xs font-bold uppercase tracking-widest px-2 py-1 rounded ${row.type === 'trade_win'
                                                ? 'bg-emerald-500/10 text-emerald-400'
                                                : 'bg-red-500/10 text-red-400'
                                                }`}>
                                                {row.type === 'trade_win' ? 'WIN' : 'LOSS'}
                                            </span>
                                        </td>
                                        <td className="px-6 py-3.5 text-sm text-white/65 font-mono">
                                            {formatUTC(row.created_at)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* ── Pagination ── */}
                    <div className="flex items-center justify-between px-6 py-3 border-t border-white/[0.06] bg-slate-950/60 shrink-0">
                        <span className="text-xs text-white/60">
                            Showing {page * PAGE_SIZE + 1}–{Math.min((page + 1) * PAGE_SIZE, rows.length)} of {rows.length} trades
                        </span>
                        <div className="flex items-center gap-1">
                            <button
                                onClick={() => setPage(0)}
                                disabled={page === 0}
                                className="px-2 py-1 text-xs text-white/60 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                            >
                                «
                            </button>
                            <button
                                onClick={() => setPage(p => Math.max(0, p - 1))}
                                disabled={page === 0}
                                className="px-3 py-1 text-xs text-white/60 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                            >
                                Prev
                            </button>
                            {Array.from({ length: totalPages }, (_, i) => (
                                <button
                                    key={i}
                                    onClick={() => setPage(i)}
                                    className={`w-7 h-7 text-xs rounded transition-colors ${i === page
                                        ? 'bg-cyan-500/20 text-cyan-400 font-bold'
                                        : 'text-white/60 hover:text-white'
                                        }`}
                                >
                                    {i + 1}
                                </button>
                            ))}
                            <button
                                onClick={() => setPage(p => Math.min(totalPages - 1, p + 1))}
                                disabled={page >= totalPages - 1}
                                className="px-3 py-1 text-xs text-white/60 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                            >
                                Next
                            </button>
                            <button
                                onClick={() => setPage(totalPages - 1)}
                                disabled={page >= totalPages - 1}
                                className="px-2 py-1 text-xs text-white/60 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                            >
                                »
                            </button>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}

function LaunderPanel({
    rank,
    bankBalance,
    clients = [],
    constants,
}: {
    rank: number;
    bankBalance: number;
    clients?: LaunderClient[];
    constants?: LaunderConstants;
}) {
    const [subPage, setSubPage] = useState<'add' | 'view'>('add');
    const [submitting, setSubmitting] = useState(false);
    const [executing, setExecuting] = useState<number | null>(null);
    const [savingCut, setSavingCut] = useState<number | null>(null);
    const [canceling, setCanceling] = useState<number | null>(null);
    // Master-detail: which client is selected in the view tab.
    const [selectedClientId, setSelectedClientId] = useState<number | null>(null);

    const C = constants ?? { overhead: 0.06, cut_min: 0.05, cut_max: 0.30, amount_max: 0, amount_min: 1000 };
    const overheadPct = Math.round(C.overhead * 100);
    const cutMinPct = Math.round(C.cut_min * 100);
    const cutMaxPct = Math.round(C.cut_max * 100);

    // Sort: clients with queued funds first (they need action), then pending.
    const sortedClients = [...clients].sort((a, b) =>
        (b.amount_sent > 0 ? 1 : 0) - (a.amount_sent > 0 ? 1 : 0)
    );

    // Auto-select first client with funds if none selected, or after list changes.
    const selectedClient = sortedClients.find(c => c.id === selectedClientId)
        ?? sortedClients.find(c => c.amount_sent > 0)
        ?? sortedClients[0]
        ?? null;

    const anyBusy = submitting || executing !== null || savingCut !== null || canceling !== null;

    const post = (routeName: string, routeParams: Record<string, unknown>, data: Record<string, unknown> = {}, onDone?: () => void) => {
        setSubmitting(true);
        router.post(route(routeName, routeParams), data as Parameters<typeof router.post>[1], {
            preserveScroll: true,
            onFinish: () => { setSubmitting(false); onDone?.(); },
        });
    };

    const postExecute = (offerId: number, cutPct: number) => {
        setExecuting(offerId);
        router.post(route('career.banking.launder.execute', { offer: offerId }), { cut_pct: cutPct } as Parameters<typeof router.post>[1], {
            preserveScroll: true,
            onFinish: () => setExecuting(null),
        });
    };

    const postSaveCut = (offerId: number, cutPct: number) => {
        setSavingCut(offerId);
        router.post(route('career.banking.launder.update-cut', { offer: offerId }), { cut_pct: cutPct } as Parameters<typeof router.post>[1], {
            preserveScroll: true,
            onFinish: () => setSavingCut(null),
        });
    };

    const postCancel = (offerId: number) => {
        setCanceling(offerId);
        router.post(route('career.banking.launder.cancel', { offer: offerId }), {} as Parameters<typeof router.post>[1], {
            preserveScroll: true,
            onFinish: () => {
                setCanceling(null);
                // If we just cancelled the selected client, deselect it.
                setSelectedClientId(prev => prev === offerId ? null : prev);
            },
        });
    };

    return (
        <div className="flex flex-col h-full">
            {/* ── Header bar ── */}
            <div className="flex items-center justify-between px-5 py-2.5 border-b border-white/[0.04] bg-slate-950/60 shrink-0">

                {rank < 2 && (
                    <span className="flex items-center gap-1.5 text-xs text-white/60 uppercase tracking-widest">
                        <Lock size={11} weight="bold" /> Rank 2 required
                    </span>
                )}
            </div>

            {rank < 2 ? (
                <div className="flex flex-col items-center justify-center flex-1 text-white/40 py-16 gap-3" />
            ) : (
                <>
                    {/* ── Sub-page toggle ── */}
                    <div className="flex gap-px px-5 pt-4 pb-0 shrink-0">
                        {(['add', 'view'] as const).map(p => (
                            <button
                                key={p}
                                onClick={() => setSubPage(p)}
                                className={`flex-1 py-2 text-[10px] font-black uppercase tracking-widest rounded-t-lg transition-all border-b-2 ${subPage === p
                                    ? 'border-amber-400/70 text-white bg-white/[0.03]'
                                    : 'border-transparent text-white/55 hover:text-white'
                                    }`}
                            >
                                {p === 'add' ? 'Add a Client' : `View Clients${clients.length ? ` (${clients.length})` : ''}`}
                            </button>
                        ))}
                    </div>

                    <div className="flex-1 min-h-0 overflow-hidden border-t border-white/[0.04]">
                        <AnimatePresence mode="wait">

                            {subPage === 'add' && (
                                <motion.div
                                    key="add"
                                    initial={{ opacity: 0 }}
                                    animate={{ opacity: 1 }}
                                    exit={{ opacity: 0 }}
                                    className="h-full flex flex-col lg:flex-row divide-y lg:divide-y-0 lg:divide-x divide-white/[0.04] overflow-hidden"
                                >
                                    {/* Left — rate card */}
                                    <div className="flex-1 flex flex-col p-5 gap-5 overflow-y-auto">
                                        <div>

                                            <h3 className="text-xl font-light text-white tracking-tight">Money Laundering</h3>
                                            <p className="text-sm text-white mt-2 leading-relaxed">
                                                Add a client to establish a permanent laundering arrangement.
                                            </p>
                                        </div>

                                        <div className="border border-white/[0.05] rounded-xl divide-y divide-white/[0.04] overflow-hidden">
                                            {[
                                                ['Min amount', fmt$(C.amount_min), 'text-white'],
                                                ['Max amount', fmt$(C.amount_max || Math.max(100_000, Math.min(1_000_000, Math.floor(bankBalance * 0.25)))), 'text-amber-400'],
                                                ['Bank fee', `${overheadPct}%`, 'text-white'],
                                                ['Your cut', `${cutMinPct}% – ${cutMaxPct}%`, 'text-emerald-400'],
                                            ].map(([label, val, color]) => (
                                                <div key={label as string} className="px-4 py-3 flex justify-between items-center">
                                                    <span className="text-[10px] text-cyan-200 uppercase tracking-widest">{label}</span>
                                                    <span className={`text-sm tabular-nums font-medium ${color}`}>{val}</span>
                                                </div>
                                            ))}
                                        </div>

                                        <div className="text-[10px] text-white leading-relaxed border border-white/[0.04] rounded-xl px-4 py-3">
                                            When the client sends dirty cash, it is held until you execute. On success, funds are cleaned and distributed. On failure, the dirty cash is lost. Either party can cancel at any time.
                                        </div>
                                    </div>

                                    {/* Right — add client form */}
                                    <AddClientForm
                                        bankBalance={bankBalance}
                                        constants={C}
                                        submitting={submitting}
                                        onSubmit={(data, reset) => post('career.banking.launder.add-client', {}, data, reset)}
                                    />
                                </motion.div>
                            )}

                            {subPage === 'view' && (
                                <motion.div
                                    key="view"
                                    initial={{ opacity: 0 }}
                                    animate={{ opacity: 1 }}
                                    exit={{ opacity: 0 }}
                                    className="h-full flex flex-col lg:flex-row divide-y lg:divide-y-0 lg:divide-x divide-white/[0.04] overflow-hidden"
                                >
                                    {/* ── Left: client list ── */}
                                    <div className="w-full lg:w-56 shrink-0 flex flex-col border-white/[0.04] overflow-hidden">
                                        {sortedClients.length === 0 ? (
                                            <div className="flex flex-col items-center justify-center py-16 text-white/45 gap-3 flex-1">
                                                <HandCoins size={24} />
                                                <p className="text-[10px] font-black uppercase tracking-widest text-center px-4">No active clients</p>
                                            </div>
                                        ) : (
                                            <div className="overflow-y-auto flex-1">
                                                {sortedClients.map(client => {
                                                    const isSelected = selectedClient?.id === client.id;
                                                    const hasQueued = client.amount_sent > 0;
                                                    return (
                                                        <button
                                                            key={client.id}
                                                            onClick={() => setSelectedClientId(client.id)}
                                                            className={`w-full flex items-center gap-3 px-4 py-3 text-left transition-colors border-b border-white/[0.04] last:border-0 ${isSelected
                                                                ? 'bg-amber-500/[0.08] border-l-2 border-l-amber-500/50'
                                                                : 'hover:bg-white/[0.025]'
                                                                }`}
                                                        >
                                                            <div className="w-8 h-8 rounded-lg bg-slate-800 border border-white/[0.05] overflow-hidden shrink-0 flex items-center justify-center">
                                                                {client.client_avatar
                                                                    ? <img src={client.client_avatar} className="w-full h-full object-cover" alt="" />
                                                                    : <User size={12} className="text-white/45" />
                                                                }
                                                            </div>
                                                            <div className="flex-1 min-w-0">
                                                                <p className="text-xs font-bold text-white truncate">{client.client_name}</p>
                                                                <p className={`text-[9px] font-black uppercase tracking-wider mt-0.5 ${hasQueued ? 'text-amber-400' : 'text-white/45'
                                                                    }`}>
                                                                    {hasQueued ? `${fmt$(client.amount_sent)} queued` : 'pending'}
                                                                </p>
                                                            </div>
                                                            {hasQueued && (
                                                                <div className="w-1.5 h-1.5 rounded-full bg-amber-400 shadow-[0_0_6px_rgba(251,191,36,0.6)] animate-pulse shrink-0" />
                                                            )}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </div>

                                    {/* ── Right: selected client detail ── */}
                                    <div className="flex-1 min-w-0 overflow-y-auto">
                                        {!selectedClient ? (
                                            <div className="flex flex-col items-center justify-center h-full py-16 text-white/45 gap-2">
                                                <User size={24} />
                                                <p className="text-[10px] font-black uppercase tracking-widest">Select a client</p>
                                            </div>
                                        ) : (
                                            <ClientDetail
                                                client={selectedClient}
                                                constants={C}
                                                isExecuting={executing === selectedClient.id}
                                                isSavingCut={savingCut === selectedClient.id}
                                                isCanceling={canceling === selectedClient.id}
                                                anyBusy={anyBusy}
                                                onExecute={(cutPct) => postExecute(selectedClient.id, cutPct)}
                                                onSaveCut={(cutPct) => postSaveCut(selectedClient.id, cutPct)}
                                                onCancel={() => postCancel(selectedClient.id)}
                                            />
                                        )}
                                    </div>
                                </motion.div>
                            )}

                        </AnimatePresence>
                    </div>
                </>
            )}
        </div>
    );
}

// ── Add Client sub-form ───────────────────────────────────────

function AddClientForm({
    bankBalance,
    constants: C,
    submitting,
    onSubmit,
}: {
    bankBalance: number;
    constants: LaunderConstants;
    submitting: boolean;
    onSubmit: (data: Record<string, unknown>, reset: () => void) => void;
}) {
    const [clientName, setClientName] = useState('');
    const [cut, setCut] = useState(15);

    const maxAmount = C.amount_max || Math.max(100_000, Math.min(1_000_000, Math.floor(bankBalance * 0.25)));
    const canSubmit = clientName.trim().length > 0 && !submitting;

    const handleSubmit = () => {
        if (!canSubmit) return;
        onSubmit(
            { client_name: clientName.trim(), cut_pct: cut / 100 },
            () => { setClientName(''); setCut(15); },
        );
    };

    return (
        <div className="w-full lg:w-72 shrink-0 flex flex-col p-5 gap-4 bg-slate-950/20 overflow-y-auto">
            <p className="text-xs font-black text-white uppercase tracking-[0.2em]">Add a Client</p>

            <div>
                <label className="text-[10px] text-cyan-200 uppercase tracking-widest block mb-1.5">Client Name</label>
                <input
                    type="text"
                    value={clientName}
                    onChange={e => setClientName(e.target.value)}
                    placeholder="Display name…"
                    disabled={submitting}
                    className="w-full px-4 py-3 bg-slate-950/70 border border-white/[0.07] rounded-xl text-white text-sm placeholder:text-white/35 focus:outline-none focus:border-amber-500/30 transition-colors disabled:opacity-50"
                />
            </div>

            <div>
                <div className="flex justify-between items-baseline mb-1.5">
                    <label className="text-[10px] text-cyan-200 uppercase tracking-widest">Your Cut</label>
                    <span className="text-sm tabular-nums text-amber-400 font-black">{cut}%</span>
                </div>
                <input
                    type="range"
                    min={Math.round(C.cut_min * 100)}
                    max={Math.round(C.cut_max * 100)}
                    value={cut}
                    onChange={e => setCut(Number(e.target.value))}
                    disabled={submitting}
                    className="w-full accent-amber-400 disabled:opacity-50"
                />
                <div className="flex justify-between text-[9px] text-white/40 mt-0.5">
                    <span>{Math.round(C.cut_min * 100)}%</span>
                    <span>{Math.round(C.cut_max * 100)}%</span>
                </div>
            </div>

            <motion.button
                whileTap={canSubmit ? { scale: 0.97 } : {}}
                onClick={handleSubmit}
                disabled={!canSubmit}
                className={`w-full py-3 rounded-xl text-xs font-black uppercase tracking-widest flex items-center justify-center gap-2 transition-all mt-auto ${canSubmit
                    ? 'bg-amber-600/80 hover:bg-amber-500/80 text-white shadow-[0_0_20px_rgba(245,158,11,0.15)]'
                    : 'bg-slate-800/60 text-white/35 cursor-not-allowed'
                    }`}
            >
                {submitting
                    ? <><div className="w-3 h-3 border border-white/30 border-t-white rounded-full animate-spin" /> Adding…</>
                    : <><Shuffle size={12} weight="bold" /> Add Client</>
                }
            </motion.button>
        </div>
    );
}

// ── Client Detail ───────────────────────────────────────────
// Full detail panel — shown on the right side of the master-detail split.

function ClientDetail({
    client, constants: C,
    isExecuting, isSavingCut, isCanceling, anyBusy,
    onExecute, onSaveCut, onCancel,
}: {
    client: LaunderClient;
    constants: LaunderConstants;
    isExecuting: boolean;
    isSavingCut: boolean;
    isCanceling: boolean;
    anyBusy: boolean;
    onExecute: (cutPct: number) => void;
    onSaveCut: (cutPct: number) => void;
    onCancel: () => void;
}) {
    const [cut, setCut] = useState(Math.round(client.cut_pct * 100));
    React.useEffect(() => { setCut(Math.round(client.cut_pct * 100)); }, [client.id, client.cut_pct]);

    const hasFunds = client.amount_sent > 0;
    const liveMax = C.amount_max > 0 ? C.amount_max : client.amount;
    const cutDecimal = cut / 100;
    const bankFee = hasFunds ? Math.floor(client.amount_sent * C.overhead) : 0;
    const bankerCut = hasFunds ? Math.floor(client.amount_sent * cutDecimal) : 0;
    const clientNet = hasFunds ? Math.max(0, client.amount_sent - bankFee - bankerCut) : 0;
    const cutChanged = cut !== Math.round(client.cut_pct * 100);
    const canSaveCut = cutChanged && !hasFunds && !anyBusy;
    const canExecute = hasFunds && !anyBusy;

    return (
        <div className="flex flex-col h-full p-5 gap-5">

            {/* Client header */}
            <div className="flex items-center gap-3">
                <div className="w-12 h-12 rounded-xl bg-slate-800 border border-white/[0.06] overflow-hidden shrink-0 flex items-center justify-center">
                    {client.client_avatar
                        ? <img src={client.client_avatar} className="w-full h-full object-cover" alt="" />
                        : <User size={18} className="text-white/45" />
                    }
                </div>
                <div className="flex-1 min-w-0">
                    <p className="text-base font-bold text-white truncate">{client.client_name}</p>
                    <p className="text-[10px] text-white/60 uppercase tracking-wider mt-0.5">
                        Cut {Math.round(client.cut_pct * 100)}%
                        <span className="text-white/35 mx-1">·</span>
                        Bank fee {Math.round(C.overhead * 100)}%
                        <span className="text-white/35 mx-1">·</span>
                        Max {fmt$(liveMax)}
                    </p>
                </div>
                {hasFunds ? (
                    <div className="shrink-0 text-right">
                        <p className="text-sm font-black text-amber-400">{fmt$(client.amount_sent)}</p>
                        <p className="text-[9px] text-amber-400/60 uppercase tracking-wider">queued</p>
                    </div>
                ) : (
                    <span className="text-[9px] text-white/60 uppercase tracking-wider shrink-0 border border-white/10 px-2 py-1 rounded-lg">pending</span>
                )}
            </div>

            {/* Deal breakdown — only when funds queued */}
            {hasFunds && (
                <div className="rounded-xl border border-amber-500/20 bg-amber-500/[0.04] grid grid-cols-3 divide-x divide-amber-500/10 overflow-hidden">
                    {([
                        ['Client gets', fmt$(clientNet), 'text-emerald-400'],
                        ['You earn', fmt$(bankerCut), 'text-amber-400'],
                        ['Bank fee', fmt$(bankFee), 'text-white/60'],
                    ] as const).map(([lbl, val, color]) => (
                        <div key={lbl} className="px-4 py-3 flex flex-col gap-0.5">
                            <span className="text-[9px] text-white/60 uppercase tracking-widest">{lbl}</span>
                            <span className={`text-sm tabular-nums font-semibold ${color}`}>{val}</span>
                        </div>
                    ))}
                </div>
            )}

            {/* Cut slider */}
            <div>
                <div className="flex justify-between items-baseline mb-2">
                    <label className="text-[10px] text-white/70 uppercase tracking-widest">
                        {hasFunds ? 'Cut — locked while funds queued' : 'Banker cut'}
                    </label>
                    <span className="text-sm tabular-nums text-amber-400 font-black">{cut}%</span>
                </div>
                <div className="flex items-center gap-3">
                    <input
                        type="range"
                        min={Math.round(C.cut_min * 100)}
                        max={Math.round(C.cut_max * 100)}
                        value={cut}
                        onChange={e => setCut(Number(e.target.value))}
                        disabled={hasFunds || anyBusy}
                        className="flex-1 accent-amber-400 disabled:opacity-30"
                    />
                    <motion.button
                        whileTap={canSaveCut ? { scale: 0.97 } : {}}
                        onClick={() => onSaveCut(cutDecimal)}
                        disabled={!canSaveCut}
                        className={`shrink-0 px-4 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest flex items-center justify-center min-w-[56px] transition-all ${canSaveCut
                            ? 'bg-slate-700 hover:bg-slate-600 text-white'
                            : 'bg-transparent text-white/30 cursor-not-allowed'
                            }`}
                    >
                        {isSavingCut
                            ? <div className="w-3 h-3 border border-white/30 border-t-white rounded-full animate-spin" />
                            : 'Save'
                        }
                    </motion.button>
                </div>
            </div>

            {/* Execute + Cancel */}
            <div className="flex gap-3 mt-auto">
                <motion.button
                    whileTap={canExecute ? { scale: 0.97 } : {}}
                    onClick={() => canExecute && onExecute(cutDecimal)}
                    disabled={!canExecute}
                    className={`flex-1 py-3 rounded-xl text-xs font-black uppercase tracking-widest flex items-center justify-center gap-1.5 transition-all ${canExecute
                        ? 'bg-emerald-700 hover:bg-emerald-600 text-white shadow-[0_0_20px_rgba(52,211,153,0.12)]'
                        : 'bg-slate-800/50 text-white/35 cursor-not-allowed'
                        }`}
                >
                    {isExecuting
                        ? <><div className="w-3 h-3 border border-white/30 border-t-white rounded-full animate-spin" /> Executing…</>
                        : <><Shuffle size={12} weight="bold" /> {hasFunds ? 'Execute Launder' : 'Awaiting funds'}</>
                    }
                </motion.button>
                <button
                    onClick={() => !anyBusy && onCancel()}
                    disabled={anyBusy}
                    className="px-5 py-3 rounded-xl text-xs font-black uppercase tracking-widest border border-red-500/20 text-red-400/50 hover:border-red-500/40 hover:text-red-400 transition-colors disabled:opacity-30 flex items-center gap-1.5"
                >
                    {isCanceling
                        ? <div className="w-3 h-3 border border-red-400/30 border-t-red-400 rounded-full animate-spin" />
                        : <UserMinus size={12} weight="bold" />
                    }
                    Remove
                </button>
            </div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────
// Tab config
// ─────────────────────────────────────────────────────────────

const TABS = [
    { id: 'loans', label: 'Laundering', icon: HandCoins, minRank: 2, managerOnly: false },
    { id: 'trading', label: 'Derivatives Trading', icon: ChartLineUp, minRank: 2, managerOnly: false },
    { id: 'reports', label: 'Financial Reports', icon: ChartBar, minRank: 2, managerOnly: true },
    { id: 'staff', label: 'Staff Management', icon: UserMinus, minRank: 4, managerOnly: true },
] as const;

// ─────────────────────────────────────────────────────────────
// Root page
// ─────────────────────────────────────────────────────────────

export default function Banking({ bank, owner, manager, rank, rank_label, is_manager, is_owner, financial_reports, dismissable_bankers = [], launder_clients = [], launder_relationships = [], launder_constants, market_server_time, ticker_snapshots = [] }: Props) {
    const [activeTab, setActiveTab] = useState<string>(() => {
        if (typeof window !== 'undefined') {
            return sessionStorage.getItem('banking_active_tab') ?? 'loans';
        }
        return 'loans';
    });

    useEffect(() => {
        sessionStorage.setItem('banking_active_tab', activeTab);
    }, [activeTab]);

    return (
        <>
            <Head title="Banking Terminal — TheDirector" />
            <div className="w-full max-w-6xl mx-auto px-1 md:px-4 py-3 md:py-6 flex flex-col gap-4 md:gap-6 h-full">

                {/* ── Hero ─────────────────────────────────────────── */}
                <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4 }}
                    className="relative rounded-t-2xl overflow-hidden border border-white/5 border-b-0 shadow-2xl shrink-0 min-h-[16rem] md:min-h-[20rem] flex flex-col justify-end"
                >
                    <div className="absolute inset-0 bg-slate-900 overflow-hidden">
                        {bank?.image ? (
                            <img src={bank.image} className="absolute inset-0 w-full h-full object-cover" alt="" />
                        ) : (
                            <div className="absolute inset-0 bg-gradient-to-br from-slate-800 via-slate-900 to-slate-950 opacity-80" />
                        )}
                        <div className="absolute right-0 top-0 w-96 h-96 bg-emerald-500/10 blur-[100px] rounded-full mix-blend-screen pointer-events-none" />
                        <div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/80 to-transparent" />
                        <div className="absolute inset-0 bg-gradient-to-r from-slate-950/80 to-transparent" />
                    </div>

                    <div className="relative px-8 pt-10 pb-6 md:px-10 md:pb-8">
                        <div className="flex flex-col md:flex-row md:items-end justify-between gap-6">
                            <div className="max-w-2xl">
                                <div className="flex items-center gap-2 mb-3">

                                </div>
                                <h1 className="text-4xl md:text-5xl lg:text-6xl font-light text-white tracking-tight leading-none mb-4">
                                    {bank ? bank.name : 'Financial Terminal'}
                                </h1>

                            </div>

                            <div className="flex items-center gap-6 md:gap-8 shrink-0">
                                {owner && (
                                    <div className="flex items-center gap-3">
                                        <div className="w-10 h-10 md:w-12 md:h-12 rounded-full overflow-hidden border border-emerald-500/30 bg-slate-800 shrink-0">
                                            {owner.avatar_url && (
                                                <img src={owner.avatar_url} className="w-full h-full object-cover" alt="" />
                                            )}
                                        </div>
                                        <div>
                                            <p className="text-[9px] font-black uppercase tracking-widest text-emerald-400/80">Owner</p>
                                            <p className="text-sm md:text-base font-semibold text-white">{owner.name}</p>
                                        </div>
                                    </div>
                                )}
                                {manager && (
                                    <div className="flex items-center gap-3">
                                        <div className="w-10 h-10 md:w-12 md:h-12 rounded-full overflow-hidden border border-cyan-500/30 bg-slate-800 shrink-0">
                                            {manager.avatar_url && (
                                                <img src={manager.avatar_url} className="w-full h-full object-cover" alt="" />
                                            )}
                                        </div>
                                        <div>
                                            <p className="text-[9px] font-black uppercase tracking-widest text-cyan-400/80">Manager</p>
                                            <p className="text-sm md:text-base font-semibold text-white">{manager.name}</p>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </motion.div>

                {/* ── Tab bar ──────────────────────────────────────── */}
                <div className="flex overflow-x-auto no-scrollbar border-b border-slate-800/40 gap-1 shrink-0 px-2 lg:px-0">
                    {TABS.filter(tab => tab.id !== 'staff' || is_manager).map(tab => {
                        const Icon = tab.icon;
                        const active = activeTab === tab.id;
                        // Rank gate OR manager-only gate
                        const locked = rank < tab.minRank;
                        return (
                            <button
                                key={tab.id}
                                onClick={() => !locked && setActiveTab(tab.id)}
                                disabled={locked}
                                className={`flex items-center gap-2 px-5 py-3 text-[10px] font-black uppercase tracking-widest border-b-2 transition ${locked
                                    ? 'border-transparent text-white/25 cursor-not-allowed'
                                    : active
                                        ? 'border-cyan-400 text-white'
                                        : 'border-transparent text-white/65 hover:text-white'
                                    }`}
                            >
                                {locked
                                    ? <Lock size={12} weight="bold" className="text-white/25" />
                                    : <Icon size={14} weight={active ? 'fill' : 'bold'} />
                                }
                                {tab.label}
                                {locked && (
                                    <span className="text-[8px] text-white/35 normal-case font-normal tracking-normal">
                                        {rank < tab.minRank ? `` : 'Manager only'}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>

                {/* ── Tab content ──────────────────────────────────── */}
                <div className="flex-1 min-h-0 bg-slate-900/60 border border-slate-700/40 rounded-xl shadow-lg overflow-hidden">
                    <AnimatePresence mode="wait">

                        {activeTab === 'loans' && (
                            <motion.div
                                key="loans"
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                exit={{ opacity: 0 }}
                                className="h-full flex flex-col"
                            >
                                <LaunderPanel
                                    rank={rank}
                                    bankBalance={bank?.balance ?? 0}
                                    clients={launder_clients}
                                    constants={launder_constants}
                                />
                            </motion.div>
                        )}

                        {activeTab === 'trading' && (
                            <motion.div
                                key="trading"
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                exit={{ opacity: 0 }}
                                className="h-full flex flex-col"
                            >
                                <EquitiesTerminal
                                    bankBalance={bank?.balance ?? 0}
                                    hasBank={!!bank}
                                    positionCapPercent={bank?.position_cap_percent ?? 10}
                                    initialSnapshots={ticker_snapshots}
                                    initialMarketTime={market_server_time}
                                />
                            </motion.div>
                        )}

                        {activeTab === 'staff' && (
                            <motion.div
                                key="staff"
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                exit={{ opacity: 0 }}
                                className="h-full overflow-auto"
                            >
                                <StaffPanel bankers={dismissable_bankers} positionCapPercent={bank?.position_cap_percent ?? 10} />
                            </motion.div>
                        )}

                        {activeTab === 'reports' && (
                            <motion.div
                                key="reports"
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                exit={{ opacity: 0 }}
                                className="h-full flex flex-col"
                            >
                                <FinancialReports rows={financial_reports ?? []} />
                            </motion.div>
                        )}

                    </AnimatePresence>
                </div>

            </div>
        </>
    );
}

Banking.layout = (page: React.ReactNode) => <GameLayout wide children={page} />;
