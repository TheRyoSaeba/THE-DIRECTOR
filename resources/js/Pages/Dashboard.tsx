import { Head, usePage, Link, router } from '@inertiajs/react';
import GameLayout from '@/Layouts/GameLayout';
import {
    Clock, Newspaper, GraduationCap, CheckCircle,
    UserCircle, CaretRight, Envelope, Sword,
} from '@phosphor-icons/react';
import { getCityImage } from '@/utils/cityImages';
import { motion } from 'framer-motion';
import { route } from 'ziggy-js';
import { useEffect, useState } from 'react';

interface Character {
    displayName: string;
    avatarUrl: string | null;
    rank: string;
    career: string;
    cityName: string;
    citySlug: string;
    homeCity: string;
    health: number;
    maxHealth: number;
    strength: number;
    rankProgress: number;
    glowColor: string;
    cleanCash: number;
    dirtyCash: number;
    timers: Record<string, number>;
    unreadJournalCount: number;
    canPromote: boolean;
    nextRank?: { name: string };
}

interface Degree {
    code: string;
    name: string;
    color: string;
    completed: boolean;
    progress: number;
}

interface DashboardData {
    journal?: {
        id: number;
        title: string;
        description: string;
        created_at: string;
        color_class?: string;
    }[];
    active_degrees?: Degree[];
    completed_degrees?: Degree[];
    bank_interest_rate?: number;
    messages?: {
        id: number;
        sender_name: string;
        body: string;
        created_at: string;
    }[];
    unreadMessageCount?: number;
}

interface CensusBucket {
    label: string;
    count: number;
}

interface CensusData {
    total_players: number;
    total_corps: number;
    by_career: CensusBucket[];
    by_city: CensusBucket[];
    by_gender: CensusBucket[];
    computed_at: string;
}

interface PageProps {
    auth: {
        character: Character;
        user: { username: string };
        navigation: any[];
    };
    dashboard: DashboardData;
    census?: CensusData;
    serverTime: string;
    flash?: any;
    [key: string]: any;
}

function StatBar({
    label,
    value,
    max,
    color = 'cyan',
    suffix = '',
    showValues = true,
}: {
    label: string;
    value: number;
    max: number;
    color?: 'emerald' | 'cyan' | 'purple' | 'amber' | 'rose';
    suffix?: string;
    showValues?: boolean;
}) {
    const pct = Math.min((value / max) * 100, 100);
    const barColors = {
        emerald: 'bg-emerald-500',
        cyan: 'bg-cyan-500',
        purple: 'bg-purple-500',
        amber: 'bg-amber-500',
        rose: 'bg-rose-500',
    };

    return (
        <div>
            <div className="flex items-center justify-between mb-2">
                <span className="text-xs text-slate-500 font-black uppercase tracking-widest">{label}</span>
                {showValues && (
                    <span className="text-xs text-slate-400 font-black tabular-nums">
                        {value}{suffix} / {max}{suffix}
                    </span>
                )}
            </div>
            <div className="h-1.5 bg-slate-800 rounded-full overflow-hidden">
                <motion.div
                    className={`h-full rounded-full ${barColors[color]}`}
                    initial={{ width: 0 }}
                    animate={{ width: `${pct}%` }}
                    transition={{ duration: 0.8, ease: 'easeOut', delay: 0.3 }}
                />
            </div>
        </div>
    );
}

export default function Dashboard({ dashboard }: { dashboard: DashboardData }) {
    const { auth, serverTime, census } = usePage<PageProps>().props;
    const character = auth?.character;
    const [censusRequested, setCensusRequested] = useState(false);

    useEffect(() => {
        if (census || censusRequested) {
            return;
        }

        const timeout = setTimeout(() => {
            setCensusRequested(true);
            router.reload({
                only: ['census'],
                showProgress: false,
                onCancel: () => setCensusRequested(false),
            });
        }, 750);

        return () => clearTimeout(timeout);
    }, [census, censusRequested]);

    if (!character) return null;

    const unreadMessageCount = dashboard?.unreadMessageCount ?? 0;
    const recentMessages = dashboard?.messages ?? [];

    return (
        <>
            <Head title="Profile Dashboard" />
            <div className="max-w-5xl mx-auto px-6 py-10 space-y-6">

                <motion.div
                    initial={{ opacity: 0, y: 24 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.5, ease: 'easeOut' }}
                    className="bg-slate-900/80 border border-slate-700/40 rounded-2xl overflow-hidden shadow-2xl"
                >
                    <div className="relative h-44 bg-slate-950 overflow-hidden">
                        <div
                            className="absolute inset-0 bg-cover bg-center opacity-30"
                            style={{ backgroundImage: `url('${getCityImage(character.cityName)}')` }}
                        />
                        <div className="absolute inset-0 bg-gradient-to-t from-slate-900 via-slate-900/20 to-transparent" />

                        <div
                            className="absolute inset-0 flex items-center justify-center"
                            style={{ perspective: 1000 }}
                        >
                            <motion.div
                                className="w-20 h-20"
                                animate={{ rotateY: 360 }}
                                transition={{ duration: 6, repeat: Infinity, ease: 'linear' }}
                                style={{ transformStyle: 'preserve-3d' }}
                            >
                                <img
                                    src="https://images.thedirector.app/Careers/promo.png"
                                    className="w-full h-full object-contain"
                                    style={{ backfaceVisibility: 'visible' }}
                                    alt="logo"
                                />
                            </motion.div>
                        </div>



                        <div className="absolute top-4 left-4 flex items-center gap-3">
                            <div className="w-12 h-12 rounded-xl bg-slate-800 border border-slate-700 overflow-hidden flex items-center justify-center shadow-lg">
                                {character.avatarUrl ? (
                                    <img src={character.avatarUrl} alt={character.displayName} className="w-full h-full object-cover" />
                                ) : (
                                    <UserCircle size={24} className="text-slate-500" />
                                )}
                            </div>
                            <div>
                                <span className="text-[10px] text-cyan-400 font-black uppercase tracking-widest block">Character</span>
                                <span className="text-sm font-black text-white leading-tight">{character.displayName}</span>
                            </div>
                        </div>
                    </div>

                    <div className="text-center px-6 pt-6 pb-4">

                        <p className="text-xs text-cyan-400 font-bold uppercase tracking-widest mt-1.5">
                            {character.rank} · {character.career}
                        </p>
                    </div>

                    <div className="px-6 pb-5 space-y-4">
                        <StatBar label="Health" value={character.health} max={character.maxHealth} color="emerald" />
                        <StatBar label="Rank Progress" value={character.rankProgress} max={100} suffix="%" color="cyan" showValues={false} />

                        {character.canPromote && character.nextRank && (
                            <motion.div
                                initial={{ opacity: 0, scale: 0.97 }}
                                animate={{ opacity: 1, scale: 1 }}
                            >
                                <Link
                                    href="/career/promote"
                                    className="bg-emerald-500/10 border border-emerald-500/25 rounded-xl px-4 py-2.5 text-xs text-emerald-400 font-black uppercase tracking-widest flex items-center gap-2 hover:bg-emerald-500/20 transition-colors"
                                >
                                    <Sword size={14} weight="fill" />
                                    Eligible for promotion to {character.nextRank.name}
                                </Link>
                            </motion.div>
                        )}

                        <div className="flex justify-between items-end border-t border-slate-800/60 pt-4">
                            <div>
                                <span className="text-[10px] text-cyan-400 uppercase font-black tracking-widest block mb-1">Clean Cash</span>
                                <span className="text-2xl font-black text-emerald-400">${character.cleanCash?.toLocaleString()}</span>
                            </div>
                            <div className="text-right">
                                <span className="text-[10px] text-cyan-400 uppercase font-black tracking-widest block mb-1">Dirty Cash</span>
                                <span className="text-2xl font-black text-rose-400">${character.dirtyCash?.toLocaleString()}</span>
                            </div>
                        </div>
                    </div>

                    <div className="px-6 pb-6">
                        <p className="text-[10px] text-cyan-400 font-black uppercase tracking-widest mb-3">Education</p>
                        <div className="flex flex-wrap gap-2">
                            {(dashboard?.active_degrees ?? []).map(deg => (
                                <span
                                    key={deg.code}
                                    title={`${deg.progress}% complete`}
                                    className="px-3.5 py-1.5 rounded-full text-xs font-black uppercase tracking-widest flex items-center gap-1.5"
                                    style={{ backgroundColor: deg.color + '1a', border: `1px solid ${deg.color}40`, color: deg.color }}
                                >
                                    <GraduationCap size={13} /> {deg.name} <span style={{ opacity: 0.6 }}>· {deg.progress}%</span>
                                </span>
                            ))}
                            {(dashboard?.completed_degrees ?? []).map(deg => (
                                <span
                                    key={deg.code}
                                    title="Degree mastered"
                                    className="px-3.5 py-1.5 rounded-full text-xs font-black uppercase tracking-widest flex items-center gap-1.5"
                                    style={{ backgroundColor: deg.color + '1a', border: `1px solid ${deg.color}40`, color: deg.color }}
                                >
                                    <CheckCircle size={13} weight="fill" className="text-emerald-400" /> {deg.name}
                                </span>
                            ))}
                        </div>
                    </div>

                    <div className="px-6 pb-6 border-t border-slate-800/40 pt-5">
                        <p className="text-[10px] text-cyan-400 font-black uppercase tracking-widest mb-3">Census</p>
                        {!census ? (
                            <div className="space-y-2">
                                <div className="h-9 bg-slate-800/30 animate-pulse rounded-lg" />
                                <div className="h-9 bg-slate-800/30 animate-pulse rounded-lg" />
                            </div>
                        ) : (
                            <CensusPanel census={census} />
                        )}
                    </div>
                </motion.div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <motion.div
                        initial={{ opacity: 0, x: -16 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.25, duration: 0.4 }}
                        className="bg-slate-900/60 border border-slate-700/40 rounded-xl p-5"
                    >
                        <div className="flex items-center justify-between mb-5">
                            <h3 className="text-xs font-black text-white uppercase tracking-widest flex items-center gap-2">
                                <div className="w-1 h-4 bg-purple-500 rounded-full" />
                                Latest Intel
                            </h3>
                            <Link href={route('journal')} className="text-[10px] text-slate-600 hover:text-cyan-400 transition-colors uppercase font-black flex items-center gap-1">
                                View all <CaretRight size={10} />
                            </Link>
                        </div>
                        {dashboard?.journal?.[0] ? (
                            <div className="flex gap-3">
                                <Newspaper size={18} className="text-purple-400 shrink-0 mt-0.5" />
                                <div className="flex-1 min-w-0">
                                    <div className="flex justify-between items-center mb-1 gap-2">
                                        <span className="text-sm font-black text-white truncate">{dashboard.journal[0].title}</span>
                                        <span className="text-[10px] text-slate-600 uppercase font-bold shrink-0">{dashboard.journal[0].created_at}</span>
                                    </div>
                                    <p className="text-xs text-slate-500 line-clamp-2 leading-relaxed">{dashboard.journal[0].description}</p>
                                </div>
                            </div>
                        ) : (
                            <p className="text-xs text-slate-600">No recent intel.</p>
                        )}
                    </motion.div>

                    <motion.div
                        initial={{ opacity: 0, x: 16 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.35, duration: 0.4 }}
                        className="bg-slate-900/60 border border-slate-700/40 rounded-xl p-5"
                    >
                        <div className="flex items-center justify-between mb-5">
                            <h3 className="text-xs font-black text-white uppercase tracking-widest flex items-center gap-2">
                                <div className="w-1 h-4 bg-blue-500 rounded-full" />
                                Messages
                                {unreadMessageCount > 0 && (
                                    <span className="bg-blue-500/15 border border-blue-500/25 px-2 py-0.5 rounded-full text-[10px] font-black text-blue-400">
                                        {unreadMessageCount} new
                                    </span>
                                )}
                            </h3>
                            <Link href={route('messages')} className="text-[10px] text-slate-600 hover:text-cyan-400 transition-colors uppercase font-black flex items-center gap-1">
                                View all <CaretRight size={10} />
                            </Link>
                        </div>
                        {recentMessages.length > 0 ? (
                            <div className="space-y-3">
                                {recentMessages.slice(0, 2).map(msg => (
                                    <div key={msg.id} className="flex gap-3">
                                        <Envelope size={16} className="text-blue-400 shrink-0 mt-0.5" />
                                        <div className="flex-1 min-w-0">
                                            <div className="flex justify-between items-center gap-2">
                                                <span className="text-sm font-bold text-white truncate">{msg.sender_name}</span>
                                                <span className="text-[10px] text-slate-600 uppercase shrink-0">{msg.created_at}</span>
                                            </div>
                                            <p className="text-xs text-slate-500 truncate">{msg.body}</p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-xs text-slate-600">No messages.</p>
                        )}
                    </motion.div>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = (page: React.ReactNode) => <GameLayout wide children={page} />;

function CensusPanel({ census }: { census: CensusData }) {
    return (
        <div className="space-y-5">
            {/* Headline totals — side by side, not stacked rows */}
            <div className="flex items-baseline gap-8">
                <div>
                    <p className="text-2xl font-black text-white tabular-nums leading-none">{census.total_players.toLocaleString()}</p>
                    <p className="text-[10px] text-cyan-400 font-black uppercase tracking-widest mt-1">Players</p>
                </div>
                <div>
                    <p className="text-2xl font-black text-white tabular-nums leading-none">{census.total_corps.toLocaleString()}</p>
                    <p className="text-[10px] text-cyan-400 font-black uppercase tracking-widest mt-1">Corporations</p>
                </div>
            </div>

            {/* Breakdowns — three columns side by side */}
            <div className="grid grid-cols-3 gap-x-6 gap-y-3">
                <CensusColumn title="Career" rows={census.by_career} />
                <CensusColumn title="City" rows={census.by_city} />
                <CensusColumn title="Gender" rows={census.by_gender} />
            </div>
        </div>
    );
}

function CensusColumn({ title, rows }: { title: string; rows: CensusBucket[] }) {
    if (!rows.length) return null;
    return (
        <div>
            <p className="text-[10px] text-cyan-400/70 font-black uppercase tracking-widest mb-2">{title}</p>
            <dl className="text-xs space-y-1">
                {rows.map(r => (
                    <div key={r.label} className="flex justify-between gap-2">
                        <dt className="text-slate-200 truncate">{r.label}</dt>
                        <dd className="text-white tabular-nums font-bold shrink-0">{r.count.toLocaleString()}</dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}
