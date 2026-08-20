import { useState, useMemo } from 'react';
import { Head, router } from '@inertiajs/react';
import { motion, AnimatePresence } from 'framer-motion';
// @ts-ignore
import { route } from 'ziggy-js';
import {
    AirplaneTilt, ArrowRight, Backpack, Briefcase,
    MagnifyingGlass, ShieldCheck, Warning, User,
    ArrowDown, ArrowUp, ClockCountdown, Funnel,
    CurrencyDollar, Scroll, Buildings, CheckCircle, XCircle,
} from '@phosphor-icons/react';
import GameLayout from '@/Layouts/GameLayout';
import { getCityImage } from '@/utils/cityImages';

// ── Types ─────────────────────────────────────────────────────────────────────

interface ItemSummary {
    name: string | null;
    image_url: string | null;
    type: string | null;
}

interface SearchResults {
    seized_dirty_cash: number;
    fine_amount: number;
    conviction_count: number;
    searched_at: string;
    officer_name: string;
    seized_gadget_name: string | null;
}

interface TravelLog {
    id: number;
    character_name: string;
    gender: string;
    conviction_count: number;
    direction: 'IN' | 'OUT';
    other_city_name: string;
    from_city: string;
    to_city: string;
    was_searched: boolean;
    searchable: boolean;
    search_results: SearchResults | null;
    cash_on_hand: number;
    avatar_url: string | null;
    items: ItemSummary[];
    item_count: number;
    time_diff: string;
}

interface HistoryRow {
    id: number;
    character_name: string;
    direction: 'IN' | 'OUT';
    from_city: string;
    to_city: string;
    was_searched: boolean;
    conviction_count: number;
    time_diff: string;
    avatar_url: string | null;
}

interface MoveRequest {
    corporation_id: number;
    corporation_name: string;
    corporation_image_url: string | null;
    requested_by_id: number;
    requested_by_name: string;
    from_city_id: number;
    from_city_name: string;
    to_city_id: number;
    to_city_name: string;
    fee_escrowed: number;
    requested_at: string;
    expires_at: string;
}

interface Props {
    rank: number;
    rank_label: string;
    city_name: string;
    city_slug: string;
    city_image: string | null;
    logs: TravelLog[];
    history: HistoryRow[];
    stats: {
        incoming: number;
        outgoing: number;
        seized: number;
        fined: number;
        searched: number;
    };
    pendingMoveRequests: MoveRequest[];
    moveAgentPayout: number;
}

const fmt$ = (n: number) => `$${Math.round(n).toLocaleString()}`;

// ── Log Row ───────────────────────────────────────────────────────────────────

function LogRow({ log, isSelected, onClick }: {
    log: TravelLog;
    isSelected: boolean;
    onClick: () => void;
}) {
    const hasConvictions = log.conviction_count > 0;

    return (
        <button
            onClick={onClick}
            className={`w-full group relative flex items-start gap-3 px-4 py-3.5 border-b border-white/[0.04] last:border-0 text-left transition-colors ${
                isSelected ? 'bg-cyan-500/[0.07]' : 'hover:bg-white/[0.025]'
            }`}
        >
            {isSelected && <div className="absolute left-0 top-0 bottom-0 w-0.5 bg-cyan-400" />}

            {/* Avatar */}
            <div className={`w-9 h-9 rounded-lg overflow-hidden shrink-0 flex items-center justify-center border mt-0.5 ${
                hasConvictions ? 'border-amber-500/40 bg-amber-500/10' : 'border-white/[0.06] bg-slate-800'
            }`}>
                {log.avatar_url
                    ? <img src={log.avatar_url} className="w-full h-full object-cover" alt="" />
                    : <User size={13} className="text-slate-500" />
                }
            </div>

            {/* Info block */}
            <div className="flex-1 min-w-0">
                {/* Name row */}
                <div className="flex items-center gap-1.5 mb-1">
                    <span className="text-sm font-bold text-white truncate">{log.character_name}</span>
                    {hasConvictions && (
                        <Warning size={11} weight="fill" className="text-amber-400 shrink-0" />
                    )}
                </div>

                {/* Route row */}
                <div className="flex items-center gap-1.5 text-[10px] text-slate-500 mb-1">
                    <span className="truncate max-w-[72px]">{log.from_city}</span>
                    <ArrowRight size={9} className="shrink-0 text-slate-700" />
                    <span className="truncate max-w-[72px] text-slate-400">{log.to_city}</span>
                </div>

                {/* Time + status row */}
               
            </div>
        </button>
    );
}

// ── Detail Panel ──────────────────────────────────────────────────────────────

function DetailPanel({ log, rank, onSearch, searching }: {
    log: TravelLog;
    rank: number;
    onSearch: () => void;
    searching: boolean;
}) {
    const hasConvictions = log.conviction_count > 0;

    return (
        <div className="flex flex-col h-full p-6 gap-5">

            {/* Header */}
            <div className="flex items-center gap-4">
                <div className={`w-14 h-14 rounded-xl overflow-hidden shrink-0 flex items-center justify-center border-2 ${
                    hasConvictions ? 'border-amber-500/50' : 'border-white/10'
                } bg-slate-800`}>
                    {log.avatar_url
                        ? <img src={log.avatar_url} className="w-full h-full object-cover" alt="" />
                        : <User size={20} className="text-slate-600" />
                    }
                </div>
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap mb-1">
                        <h3 className="text-lg font-bold text-white truncate">{log.character_name}</h3>
                        {hasConvictions && (
                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-amber-500/15 border border-amber-500/30 text-amber-400">
                                <Warning size={9} weight="fill" /> {log.conviction_count} Conviction{log.conviction_count !== 1 ? 's' : ''}
                            </span>
                        )}
                    </div>
                    <div className="flex items-center gap-1.5 text-[10px] text-slate-500 uppercase tracking-wider">
                        <span className="capitalize">{log.gender}</span>
                        <span className="text-slate-700">·</span>
                        <span className="flex items-center gap-1 text-emerald-500">
                            <ArrowDown size={10} weight="bold" /> from {log.from_city}
                        </span>
                        <span className="text-slate-700">·</span>
                        <span>{log.time_diff}</span>
                    </div>
                </div>
            </div>

            {/* Cash — only declared cash shown pre-search */}
            <div className="grid grid-cols-2 gap-3">
                <div className="rounded-xl border border-white/[0.06] bg-slate-950/40 p-3 flex flex-col gap-0.5">
                    <span className="text-[9px] text-slate-600 uppercase tracking-widest">Declared cash</span>
                    <span className="text-sm font-bold text-emerald-400 tabular-nums">{fmt$(log.cash_on_hand)}</span>
                </div>
                <div className="rounded-xl border border-white/[0.06] bg-slate-950/40 p-3 flex flex-col gap-0.5">
                    <span className="text-[9px] text-slate-600 uppercase tracking-widest">Illicit funds</span>
                    <span className="text-sm font-bold text-slate-600 tabular-nums italic">
                        {log.was_searched
                            ? (log.search_results && log.search_results.seized_dirty_cash > 0
                                ? fmt$(log.search_results.seized_dirty_cash) + ' seized'
                                : 'None found')
                            : 'Unknown until searched'
                        }
                    </span>
                </div>
            </div>

            {/* Manifest — Digital Luggage Scan */}
            <div className="space-y-3">
                <div className="flex items-center justify-between border-b border-white/[0.05] pb-2">
                    <p className="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] flex items-center gap-2">

                        Declared Items
                    </p>

                </div>

                {log.items.length > 0 ? (
                    <div className="grid grid-cols-3 sm:grid-cols-4 gap-y-4 gap-x-4 py-2">
                        {log.items.map((item, idx) => {
                            const isSeized = log.was_searched
                                && log.search_results?.seized_gadget_name
                                && item.name === log.search_results.seized_gadget_name;
                            return (
                            <motion.div
                                key={idx}
                                initial={{ opacity: 0, scale: 0.9 }}
                                animate={{ opacity: 1, scale: 1 }}
                                transition={{ delay: idx * 0.04 }}
                                className="flex flex-col items-center group"
                            >
                                {/* Item Image + seized overlay */}
                                <div className="relative w-20 h-20 mb-1 flex items-center justify-center transition-transform duration-300 group-hover:scale-105">
                                    {item.image_url ? (
                                        <img
                                            src={item.image_url}
                                            alt=""
                                            className={`w-full h-full object-contain drop-shadow-[0_4px_12px_rgba(0,0,0,0.5)] ${
                                                isSeized ? 'opacity-40 grayscale' : ''
                                            }`}
                                        />
                                    ) : (
                                        <div className="w-16 h-16 rounded-full bg-slate-800/20 flex items-center justify-center">
                                            <Backpack size={24} className="text-slate-800" />
                                        </div>
                                    )}
                                    {isSeized && (
                                        <div className="absolute top-1 right-1 bg-amber-500/80 text-[8px] font-black text-white px-1.5 py-0.5 rounded shadow-lg uppercase tracking-widest">
                                            Seized
                                        </div>
                                    )}
                                </div>

                                {/* Item Name */}
                                <p className={`text-[9px] font-black uppercase tracking-wider text-center line-clamp-1 px-1 transition-colors ${
                                    isSeized ? 'text-amber-500' : 'text-slate-500 group-hover:text-white'
                                }`}>
                                    {item.name}
                                </p>
                            </motion.div>
                            );
                        })}
                        
                        {/* More items indicator */}
                        {log.item_count > log.items.length && (
                            <div className="flex flex-col items-center justify-center">
                                <div className="w-16 h-16 rounded-full border border-dashed border-slate-800 flex items-center justify-center mb-1">
                                    <span className="text-[10px] font-black text-slate-800">+{log.item_count - log.items.length}</span>
                                </div>
                                <p className="text-[8px] font-black text-slate-800 uppercase tracking-widest">More</p>
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="py-6 flex flex-col items-center justify-center opacity-30">
                        <p className="text-[10px] font-black text-slate-700 uppercase tracking-widest italic">Manifest Empty</p>
                    </div>
                )}
            </div>

            {/* Search record — shown after search */}
            {log.was_searched && log.search_results && (
                <div className="rounded-xl border border-slate-700/50 bg-slate-950/50 p-4">
                    <p className="text-[10px] text-slate-500 uppercase tracking-widest mb-3 flex items-center gap-1.5">
                        <ShieldCheck size={11} weight="bold" /> Search Record
                    </p>
                    <div className="grid grid-cols-3 gap-3">
                        <div>
                            <p className="text-[9px] text-slate-600 uppercase tracking-widest mb-0.5">Officer</p>
                            <p className="text-xs text-slate-300 font-medium">{log.search_results.officer_name}</p>
                        </div>
                        <div>
                            <p className="text-[9px] text-slate-600 uppercase tracking-widest mb-0.5">Seized</p>
                            <p className={`text-xs font-bold tabular-nums ${
                                log.search_results.seized_dirty_cash > 0 ? 'text-emerald-400' : 'text-slate-500'
                            }`}>
                                {log.search_results.seized_dirty_cash > 0 ? fmt$(log.search_results.seized_dirty_cash) : 'None'}
                            </p>
                        </div>
                        <div>
                            <p className="text-[9px] text-slate-600 uppercase tracking-widest mb-0.5">Fine</p>
                            <p className={`text-xs font-bold tabular-nums ${
                                (log.search_results.fine_amount ?? 0) > 0 ? 'text-amber-400' : 'text-slate-500'
                            }`}>
                                {(log.search_results.fine_amount ?? 0) > 0 ? fmt$(log.search_results.fine_amount) : 'None'}
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {/* Action */}
            <div className="mt-auto">
                <motion.button
                    whileTap={log.searchable && !searching ? { scale: 0.98 } : {}}
                    onClick={() => log.searchable && !searching && onSearch()}
                    disabled={!log.searchable || searching}
                    className={`w-full py-3 rounded-xl text-xs font-black uppercase tracking-widest flex items-center justify-center gap-2 transition-all ${
                        log.was_searched
                            ? 'bg-slate-800/50 text-slate-600 cursor-not-allowed'
                            : !log.searchable
                                ? 'bg-slate-800/50 text-slate-600 cursor-not-allowed'
                                : searching
                                    ? 'bg-cyan-700/50 text-cyan-300'
                                    : 'bg-cyan-700 hover:bg-cyan-600 text-white shadow-[0_0_20px_rgba(34,211,238,0.12)]'
                    }`}
                >
                    {searching ? (
                        <>
                            <div className="w-3 h-3 border border-cyan-300/30 border-t-cyan-300 rounded-full animate-spin" />
                            Executing Search…
                        </>
                    ) : log.was_searched ? (
                        <><ShieldCheck size={13} weight="bold" /> Already Searched</>
                    ) : !log.searchable ? (
                        <><ClockCountdown size={13} weight="bold" /> Search Window Closed</>
                    ) : (
                        <><MagnifyingGlass size={13} weight="bold" /> Execute Physical Search</>
                    )}
                </motion.button>
            </div>
        </div>
    );
}

// ── History Tab ───────────────────────────────────────────────────────────────

function HistoryPanel({ history, cityName }: { history: HistoryRow[]; cityName: string }) {
    const [query, setQuery] = useState('');

    const filtered = useMemo(() => {
        if (!query.trim()) return history;
        const q = query.toLowerCase();
        return history.filter(r => r.character_name.toLowerCase().includes(q));
    }, [history, query]);

    return (
        <div className="flex flex-col h-full">
            {/* Search bar */}
            <div className="px-5 py-3 border-b border-white/[0.04] bg-slate-950/60 shrink-0">
                <div className="relative">
                    <MagnifyingGlass size={13} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-600" weight="bold" />
                    <input
                        type="text"
                        placeholder="Search by character name…"
                        value={query}
                        onChange={e => setQuery(e.target.value)}
                        className="w-full pl-8 pr-4 py-2 bg-slate-900/60 border border-white/[0.07] rounded-lg text-sm text-white placeholder:text-slate-600 focus:outline-none focus:border-cyan-500/30 transition-colors"
                    />
                </div>
            </div>

            {/* Description */}
            <div className="px-5 py-2.5 border-b border-white/[0.04] bg-slate-950/30 shrink-0">
                <p className="text-[9px] text-slate-600 uppercase tracking-widest">
                    All arrivals &amp; departures through {cityName} — past 12 hours
                </p>
            </div>

            {/* Results */}
            <div className="flex-1 overflow-y-auto">
                {filtered.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-16 text-slate-700 gap-3">
                        <Scroll size={24} />
                        <p className="text-[10px] font-black uppercase tracking-widest">
                            {query ? 'No records found' : 'No travel history'}
                        </p>
                    </div>
                ) : (
                    <table className="w-full border-collapse">
                        <thead className="sticky top-0 bg-slate-950/95">
                            <tr className="text-left border-b border-white/[0.06]">
                                <th className="px-5 py-2.5 text-[9px] font-black text-slate-600 uppercase tracking-widest">Traveler</th>
                                <th className="px-4 py-2.5 text-[9px] font-black text-slate-600 uppercase tracking-widest">Direction</th>
                                <th className="px-4 py-2.5 text-[9px] font-black text-slate-600 uppercase tracking-widest hidden sm:table-cell">Route</th>
                                <th className="px-4 py-2.5 text-[9px] font-black text-slate-600 uppercase tracking-widest">When</th>
                                <th className="px-4 py-2.5 text-[9px] font-black text-slate-600 uppercase tracking-widest">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-white/[0.03]">
                            {filtered.map(row => (
                                <tr key={row.id} className="hover:bg-white/[0.02] transition-colors">
                                    <td className="px-5 py-3">
                                        <div className="flex items-center gap-2.5">
                                            <div className="w-7 h-7 rounded-lg overflow-hidden shrink-0 bg-slate-800 border border-white/[0.05] flex items-center justify-center">
                                                {row.avatar_url
                                                    ? <img src={row.avatar_url} className="w-full h-full object-cover" alt="" />
                                                    : <User size={11} className="text-slate-600" />
                                                }
                                            </div>
                                            <div>
                                                <p className="text-sm font-bold text-white">{row.character_name}</p>
                                                {row.conviction_count > 0 && (
                                                    <p className="text-[9px] text-amber-400 font-black uppercase tracking-wider flex items-center gap-1">
                                                        <Warning size={8} weight="fill" /> {row.conviction_count} conviction{row.conviction_count !== 1 ? 's' : ''}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {row.direction === 'IN' ? (
                                            <span className="flex items-center gap-1 text-[10px] font-black text-emerald-500 uppercase tracking-wider">
                                                <ArrowDown size={10} weight="bold" /> Arrival
                                            </span>
                                        ) : (
                                            <span className="flex items-center gap-1 text-[10px] font-black text-amber-500 uppercase tracking-wider">
                                                <ArrowUp size={10} weight="bold" /> Departure
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 hidden sm:table-cell">
                                        <div className="flex items-center gap-1.5 text-[10px] text-slate-500">
                                            <span className="truncate max-w-[80px]">{row.from_city}</span>
                                            <ArrowRight size={9} className="text-slate-700 shrink-0" />
                                            <span className="truncate max-w-[80px] text-slate-400">{row.to_city}</span>
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className="text-[10px] text-slate-500">{row.time_diff}</span>
                                    </td>
                                    <td className="px-4 py-3">
                                        {row.was_searched ? (
                                            <span className="text-[9px] font-black text-slate-600 uppercase tracking-wider flex items-center gap-1">
                                                <ShieldCheck size={10} weight="bold" /> Searched
                                            </span>
                                        ) : (
                                            <span className="text-[9px] font-black text-slate-700 uppercase tracking-wider">—</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </div>
    );
}

// ── Move Requests Panel ───────────────────────────────────────────────────────

function MoveRequestsPanel({
    requests, rank, cityName, agentPayout, decidingCorpId, onDecide,
}: {
    requests: MoveRequest[];
    rank: number;
    cityName: string;
    agentPayout: number;
    decidingCorpId: number | null;
    onDecide: (corpId: number, approve: boolean) => void;
}) {
    const lockedByRank = rank < 2;

    if (requests.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center h-full py-20 text-slate-700 gap-3">
                <Buildings size={28} />
                <p className="text-[10px] font-black uppercase tracking-widest">
                    No corporate relocation requests for {cityName}
                </p>
               
            </div>
        );
    }

    return (
        <div className="overflow-y-auto h-full divide-y divide-white/[0.04]">
            {requests.map((req) => {
                const isDeciding = decidingCorpId === req.corporation_id;
                const disabled = isDeciding || decidingCorpId !== null || lockedByRank;
                return (
                    <div key={req.corporation_id} className="flex flex-col md:flex-row gap-4 px-5 py-4 hover:bg-white/[0.025] transition-colors">
                        <div className="w-12 h-12 rounded-lg overflow-hidden shrink-0 border border-white/[0.06] bg-slate-800 flex items-center justify-center">
                            {req.corporation_image_url
                                ? <img src={req.corporation_image_url} className="w-full h-full object-cover" alt="" />
                                : <Buildings size={20} className="text-slate-500" />
                            }
                        </div>

                        <div className="flex-1 min-w-0 space-y-1">
                            <div className="flex items-center gap-2">
                                <span className="text-sm font-bold text-white truncate">{req.corporation_name}</span>
                               
                            </div>
                            <p className="text-[10px] uppercase tracking-widest font-black text-slate-500">
                                CEO {req.requested_by_name}
                            </p>
                            <p className="text-[11px] text-slate-400">
                                Relocating from <span className="text-amber-300">{req.from_city_name}</span>
                                {' '}<ArrowRight size={10} className="inline-block -mt-px" />{' '}
                                <span className="text-cyan-300">{req.to_city_name}</span>
                            </p>
                          
                        </div>

                        <div className="flex flex-row gap-2 md:flex-col md:w-32 shrink-0">
                            <button
                                onClick={() => onDecide(req.corporation_id, true)}
                                disabled={disabled}
                                className={`flex-1 md:flex-none flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest transition ${
                                    disabled
                                        ? 'bg-slate-800/50 text-slate-600 cursor-not-allowed'
                                        : 'bg-emerald-500/15 text-emerald-300 hover:bg-emerald-500/25'
                                }`}
                            >
                                <CheckCircle size={12} weight="fill" />
                                {isDeciding ? '...' : 'Approve'}
                            </button>
                            <button
                                onClick={() => onDecide(req.corporation_id, false)}
                                disabled={disabled}
                                className={`flex-1 md:flex-none flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest transition ${
                                    disabled
                                        ? 'bg-slate-800/50 text-slate-600 cursor-not-allowed'
                                        : 'bg-rose-500/15 text-rose-300 hover:bg-rose-500/25'
                                }`}
                            >
                                <XCircle size={12} weight="fill" />
                                {isDeciding ? '...' : 'Deny'}
                            </button>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

// ── Root page ─────────────────────────────────────────────────────────────────

export default function Customs({ rank, rank_label, city_name, city_slug, city_image, logs, history, stats, pendingMoveRequests, moveAgentPayout }: Props) {
    const [tab, setTab] = useState<'incoming' | 'history' | 'move-requests'>('incoming');
    const [decidingCorpId, setDecidingCorpId] = useState<number | null>(null);
    const moveRequests = pendingMoveRequests ?? [];

    const decideMove = (corpId: number, approve: boolean) => {
        if (decidingCorpId !== null) return;
        setDecidingCorpId(corpId);
        router.post(
            route(approve ? 'career.customs.move.approve' : 'career.customs.move.deny'),
            { corporation_id: corpId },
            {
                preserveScroll: true,
                only: ['pendingMoveRequests', 'flash', 'auth'],
                onFinish: () => setDecidingCorpId(null),
            }
        );
    };

    const [selectedLogId, setSelectedLogId] = useState<number | null>(
        logs.find(l => !l.was_searched && l.conviction_count > 0)?.id ?? logs[0]?.id ?? null
    );
    const [searching, setSearching] = useState(false);

    const bgImage = getCityImage(city_name, city_image);
    const selectedLog = logs.find(l => l.id === selectedLogId) ?? null;

    const handleSearch = () => {
        if (!selectedLog || searching) return;
        setSearching(true);
        router.post(
            route('career.customs.search'),
            { log_id: selectedLog.id },
            {
                preserveScroll: true,
                only: ['logs', 'history', 'stats', 'flash', 'auth'],
                onFinish: () => setSearching(false),
            }
        );
    };

    return (
        <>
            <Head title="Airport Customs — TheDirector" />
            <div className="w-full max-w-6xl mx-auto px-1 md:px-4 py-3 md:py-6 flex flex-col gap-4 md:gap-6 h-full">

                {/* ── Hero ──────────────────────────────────────────────────── */}
                <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4 }}
                    className="relative rounded-t-2xl overflow-hidden border border-white/5 border-b-0 shadow-2xl shrink-0 min-h-[16rem] md:min-h-[20rem] flex flex-col justify-end"
                >
                    <div className="absolute inset-0 bg-slate-900 overflow-hidden">
                        <img
                            src={bgImage}
                            alt={city_name}
                            className="absolute inset-0 w-full h-full object-cover"
                        />
                        <div className="absolute right-0 top-0 w-96 h-96 bg-cyan-500/10 blur-[100px] rounded-full mix-blend-screen pointer-events-none opacity-50" />
                        <div className="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-950/20 to-transparent" />
                    </div>

                    <div className="relative px-8 pt-10 pb-6 md:px-10 md:pb-8 drop-shadow-md">
                        <div className="flex flex-col md:flex-row md:items-end justify-between gap-6">
                            <div className="space-y-1">
                                
                                <h1 className="text-4xl md:text-5xl lg:text-6xl font-bold text-white tracking-tight leading-none">
                                    {city_name} <span className="text-white">Customs</span>
                                </h1>
                                <p className="text-slate-300 text-sm leading-relaxed max-w-md">
                                    Another day stamping passports and searching bags.
                                </p>
                            </div>

                            {/* Stats strip */}
                           
                        </div>
                    </div>
                </motion.div>

                {/* ── Tab bar ────────────────────────────────────────────────── */}
                <div className="flex gap-1 shrink-0 border-b border-slate-800/40 px-1 lg:px-0">
                    {([
                        { id: 'incoming', label: `Incoming (${logs.length})`, icon: ArrowDown },
                        { id: 'history',  label: 'Travel History',            icon: Scroll },
                        { id: 'move-requests', label: `Approve Move (${moveRequests.length})`, icon: Buildings },
                    ] as const).map(t => {
                        const Icon = t.icon;
                        const active = tab === t.id;
                        return (
                            <button
                                key={t.id}
                                onClick={() => setTab(t.id)}
                                className={`flex items-center gap-2 px-5 py-3 text-[10px] font-black uppercase tracking-widest border-b-2 transition ${
                                    active
                                        ? 'border-cyan-400 text-white'
                                        : 'border-transparent text-slate-500 hover:text-slate-300'
                                }`}
                            >
                                <Icon size={12} weight={active ? 'fill' : 'bold'} />
                                {t.label}
                            </button>
                        );
                    })}
                </div>

                {/* ── Content ────────────────────────────────────────────────── */}
                <div className="flex-1 min-h-0 bg-slate-900/60 border border-slate-700/40 rounded-xl shadow-lg overflow-hidden">
                    <AnimatePresence mode="wait">

                        {/* ── Incoming tab — master-detail ── */}
                        {tab === 'incoming' && (
                            <motion.div
                                key="incoming"
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                exit={{ opacity: 0 }}
                                className="flex h-full flex-col lg:flex-row divide-y lg:divide-y-0 lg:divide-x divide-white/[0.04]"
                            >
                                {/* Left — log list */}
                                <div className="w-full lg:w-72 xl:w-80 shrink-0 flex flex-col overflow-hidden">
                                    <div className="flex items-center justify-between px-5 py-3 border-b border-white/[0.04] bg-slate-950/60 shrink-0">
                                        <span className="text-[10px] font-black uppercase tracking-widest text-slate-500 flex items-center gap-1.5">
                                            <Briefcase size={11} /> Line Up
                                        </span>
                                        <span className="text-[9px] text-slate-700 font-mono flex items-center gap-1">
                                            <ClockCountdown size={10} /> 15 min window
                                        </span>
                                    </div>

                                    <div className="overflow-y-auto flex-1">
                                        {logs.length === 0 ? (
                                            <div className="flex flex-col items-center justify-center py-16 text-slate-700 gap-3">
                                                <AirplaneTilt size={26} />
                                                <p className="text-[10px] font-black uppercase tracking-widest">No arrivals in window</p>
                                                
                                            </div>
                                        ) : (
                                            logs.map(log => (
                                                <LogRow
                                                    key={log.id}
                                                    log={log}
                                                    isSelected={selectedLogId === log.id}
                                                    onClick={() => setSelectedLogId(log.id)}
                                                />
                                            ))
                                        )}
                                    </div>
                                </div>

                                {/* Right — detail */}
                                <div className="flex-1 min-w-0 overflow-y-auto">
                                    <AnimatePresence mode="wait">
                                        {selectedLog ? (
                                            <motion.div
                                                key={selectedLog.id}
                                                initial={{ opacity: 0, x: 8 }}
                                                animate={{ opacity: 1, x: 0 }}
                                                exit={{ opacity: 0 }}
                                                transition={{ duration: 0.15 }}
                                                className="h-full"
                                            >
                                                <DetailPanel
                                                    log={selectedLog}
                                                    rank={rank}
                                                    onSearch={handleSearch}
                                                    searching={searching}
                                                />
                                            </motion.div>
                                        ) : (
                                            <motion.div
                                                key="empty"
                                                initial={{ opacity: 0 }}
                                                animate={{ opacity: 1 }}
                                                className="flex flex-col items-center justify-center h-full py-20 text-slate-700 gap-3"
                                            >
                                                <MagnifyingGlass size={28} />
                                                <p className="text-[10px] font-black uppercase tracking-widest">
                                                    Select a traveler to inspect
                                                </p>
                                            </motion.div>
                                        )}
                                    </AnimatePresence>
                                </div>
                            </motion.div>
                        )}

                        {/* ── History tab ── */}
                        {tab === 'history' && (
                            <motion.div
                                key="history"
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                exit={{ opacity: 0 }}
                                className="h-full flex flex-col"
                            >
                                <HistoryPanel history={history} cityName={city_name} />
                            </motion.div>
                        )}

                        {/* ── Move Requests tab ── */}
                        {tab === 'move-requests' && (
                            <motion.div
                                key="move-requests"
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                exit={{ opacity: 0 }}
                                className="h-full flex flex-col"
                            >
                                <MoveRequestsPanel
                                    requests={moveRequests}
                                    rank={rank}
                                    cityName={city_name}
                                    agentPayout={moveAgentPayout ?? 0}
                                    decidingCorpId={decidingCorpId}
                                    onDecide={decideMove}
                                />
                            </motion.div>
                        )}

                    </AnimatePresence>
                </div>

            </div>
        </>
    );
}

Customs.layout = (page: React.ReactNode) => <GameLayout wide children={page} />;
