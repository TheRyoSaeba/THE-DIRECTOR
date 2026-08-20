import { useState, useEffect, useMemo } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import {
    Lock, Clock, BookOpen,
    Coins, Sword, Timer, SignOut
} from '@phosphor-icons/react';
import { route } from 'ziggy-js';
import { useServerClock } from '@/contexts/ClockContext';
import { formatUTC } from '@/Layouts/GameLayoutComponents';

interface Inmate {
    display_name: string;
    avatar_url: string | null;
    city: string | null;
    jail_until: number | null;
    cell_block: string | null;
}

interface JailWork {
    id: string;
    label: string;
    locked: boolean;
    min_exp: number;
}

interface JailAction {
    id: string;
    label: string;
    description: string;
    icon: React.ElementType;
    cooldown: number;
}

const ACTIONS: JailAction[] = [
    { id: 'study', label: 'Law Library', description: 'Spend your days reading through Kant', icon: BookOpen, cooldown: 0 },
    { id: 'bribe', label: 'Bribe Guard', description: 'Talk to guard, see if he can get you a pack', icon: Coins, cooldown: 0 },
    { id: 'fight', label: 'Pick a Fight', description: 'Meet another inmate who has been eyeing you', icon: Sword, cooldown: 0 },
];

function fmt(seconds: number): string {
    if (seconds <= 0) return '0:00';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    if (h > 0) return `${h}h ${m}m`;
    return `${m}:${String(s).padStart(2, '0')}`;
}

function fmtCooldown(seconds: number): string {
    if (seconds <= 0) return 'RDY';
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return m > 0 ? `${m}m` : `${s}s`;
}

const cellBlockLabel = (block: string | null): string => block ? `Block ${block}` : 'Block D';

function JailHeader({ serverClock, displayName, timers, onLogout }: {
    serverClock: number;
    displayName: string;
    timers: Record<string, number>;
    onLogout: () => void;
}) {
    const actionCd = Math.max(0, (timers.next_action_at ?? 0) - serverClock);
    const studyCd = Math.max(0, (timers.next_study_at ?? 0) - serverClock);
    const workCd = Math.max(0, (timers.next_work_at ?? 0) - serverClock);

    return (
        <header className="h-12 border-b border-slate-800/50 flex items-center justify-between px-3 sm:px-5 bg-slate-950/80 sticky top-0 z-50">
            <span className="font-bold text-sm whitespace-nowrap select-none">
                THE <span className="text-cyan-400">DIRECTOR</span>
            </span>

            <div className="flex-1 min-w-0 flex items-center justify-center mx-2 overflow-hidden">
                <div className="flex items-center gap-1 shrink-0">
                    <Clock size={12} className="text-cyan-400" />
                    <span className="font-mono text-xs tabular-nums text-slate-300">
                        {formatUTC(serverClock)}
                    </span>
                </div>

                <div className="hidden nav:flex items-center gap-5 ml-6">
                    <div className={`flex items-center gap-2 shrink-0 text-xs font-medium transition ${workCd === 0 ? 'text-emerald-400' : 'text-slate-400'}`}>
                        <span className="font-semibold">Work:</span>
                        <span className="font-semibold tabular-nums">{workCd === 0 ? 'Ready' : fmtCooldown(workCd)}</span>
                    </div>
                    <div className={`flex items-center gap-2 shrink-0 text-xs font-medium transition ${actionCd === 0 ? 'text-emerald-400' : 'text-slate-400'}`}>
                        <span className="font-semibold">Action:</span>
                        <span className="font-semibold tabular-nums">{actionCd === 0 ? 'Ready' : fmtCooldown(actionCd)}</span>
                    </div>

                    <div className={`flex items-center gap-2 shrink-0 text-xs font-medium transition ${studyCd === 0 ? 'text-emerald-400' : 'text-slate-400'}`}>
                        <span className="font-semibold">Study:</span>
                        <span className="font-semibold tabular-nums">{studyCd === 0 ? 'Ready' : fmtCooldown(studyCd)}</span>
                    </div>
                </div>
            </div>

            <div className="flex items-center gap-2 shrink-0">
                <span className="text-slate-300 text-xs font-medium max-w-[100px] truncate hidden sm:inline">{displayName}</span>
                <button
                    onClick={onLogout}
                    className="flex items-center gap-1.5 text-white hover:text-slate-300 transition-colors px-2 py-1 rounded hover:bg-slate-800/50"
                    title="Log Out"
                >
                    <SignOut size={13} weight="bold" />
                    <span className="text-[10px] font-black uppercase tracking-widest hidden sm:inline">Logout</span>
                </button>
            </div>
        </header>
    );
}

function SentenceHero({ remaining, cellBlock }: {
    remaining: number;
    cellBlock: string;
}) {
    return (
        <div className="relative rounded-2xl overflow-hidden border border-white/5 shadow-2xl">
            <div className="absolute inset-0 bg-slate-950" />
            <div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/80 to-slate-900/60" />
            <div
                className="absolute inset-0 opacity-[0.03]"
                style={{
                    backgroundImage:
                        'linear-gradient(rgba(255,255,255,0.15) 1px, transparent 1px),' +
                        'linear-gradient(90deg, rgba(255,255,255,0.15) 1px, transparent 1px)',
                    backgroundSize: '80px 80px',
                }}
            />

            <div className="relative px-8 pt-14 pb-8 sm:px-10 sm:pt-16 sm:pb-10">
                <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-6">
                    <div>
                        <div className="flex items-center gap-2 mb-3">
                            <Lock size={14} className="text-slate-500" />
                            <span className="text-[11px] font-semibold uppercase tracking-[0.2em] text-white">
                                Bentham's private Maximum.
                            </span>
                        </div>
                        <h1 className="text-4xl sm:text-5xl font-light text-white tracking-tight">
                            {cellBlock}
                        </h1>
                    </div>

                    <div className="text-left sm:text-right shrink-0">
                        <div className="text-[10px] uppercase tracking-widest text-slate-500 mb-1">
                            Time Remaining
                        </div>
                        <div className="text-3xl font-light text-emerald-400 tabular-nums font-mono tracking-tight drop-shadow-[0_0_15px_rgba(52,211,153,0.25)]">
                            {fmt(remaining)}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

function WorkList({ works, workCd, serverClock }: {
    works: JailWork[];
    workCd: number;
    serverClock: number;
}) {
    const [selected, setSelected] = useState<string | null>(null);
    const [working, setWorking] = useState(false);
    const onCooldown = workCd > 0;

    const doWork = () => {
        setWorking(true);
        router.post(route('jail.work'), { work_id: selected }, {
            only: ['auth', 'flash'],
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setWorking(false),
        });
    };

    return (
        <div className="rounded-xl border border-white/5 bg-slate-900/40 p-5 sm:p-6">
            <div className="flex items-center justify-between mb-3">
                <span className="text-[10px] font-black uppercase tracking-[0.2em] text-cyan-400">Prison Jobs</span>
                <span className={`text-[10px] font-semibold tabular-nums ${onCooldown ? 'text-slate-500' : 'text-emerald-400'}`}>

                </span>
            </div>

            <div>
                {works.map(work => {

                    const isSelected = selected === work.id;
                    return (
                        <button
                            key={work.id}
                            onClick={() => setSelected(work.id)}

                            className={`group grid min-h-11 w-full grid-cols-[20px_minmax(0,1fr)_auto] items-center gap-3 border-b border-slate-800/50 py-2.5 text-left transition-colors 'hover:border-cyan-500/50'}`}
                        >
                            <span className={`block h-5 w-5 rounded-full transition-transform ${isSelected ? 'border-2 border-cyan-300 bg-cyan-400 shadow-[inset_0_0_0_3px_rgb(15,23,42)] scale-110' : 'border border-slate-600'}`} />
                            <span className={`min-w-0 truncate text-sm font-bold uppercase tracking-wider ${isSelected ? 'text-cyan-300' : 'text-white/80 group-hover:text-white'}`}>
                                {work.label}
                            </span>

                        </button>
                    );
                })}
            </div>

            <div className="mt-4 flex justify-center">
                <button
                    onClick={doWork}
                    className={`h-10 px-8 rounded-lg font-bold text-xs uppercase tracking-wider transition-colors ${!selected || working
                        ? 'bg-slate-800/50 text-slate-500 border border-slate-800/50 cursor-not-allowed'
                        : 'bg-white text-slate-950 hover:bg-cyan-400 hover:shadow-[0_0_15px_rgba(6,182,212,0.3)]'
                        }`}
                >
                    {working ? 'Working…' : 'Work'}
                </button>
            </div>
        </div>
    );
}



interface InmateRow {
    displayName: string;
    timeLeft: number;
    isPlayer: boolean;
}

// ── Inmate List (Flat bar at the bottom matching the exact structure and behavior of GameLayout online list, with timer removed) ──
function InmateList({ rows }: { rows: InmateRow[] }) {
    const sorted = useMemo(
        () => [...rows].sort((a, b) => (a.isPlayer ? -1 : b.isPlayer ? 1 : a.timeLeft - b.timeLeft)),
        [rows],
    );

    return (
        <div className="border-t border-slate-900/20 bg-slate-900/20 backdrop-blur-md pb-16 nav:pb-0">
            <div className="max-w-5xl mx-auto px-4 py-3 flex flex-col gap-2.5">
                <div className="flex justify-between items-center select-none border-b border-white/[0.03] pb-1.5">
                    <span className="text-[9px] font-black uppercase tracking-[0.2em] text-slate-500">
                        Cell Block Inmates
                    </span>

                </div>

                <div className="flex min-h-[34px] flex-wrap items-center gap-x-5 gap-y-2.5 pb-1">
                    {sorted.length > 0 ? (
                        sorted.map((row, i) => (
                            <span
                                key={i}
                                className="block leading-none flex items-center gap-1.5"
                            >
                                <span className={`block max-w-[130px] truncate text-[12px] ${row.isPlayer
                                    ? 'text-emerald-400 font-black uppercase'
                                    : 'text-slate-400 font-semibold lowercase'
                                    }`}>
                                    {row.isPlayer ? row.displayName.toUpperCase() : row.displayName.toLowerCase()}
                                </span>
                            </span>
                        ))
                    ) : (
                        <div className="py-2 text-xs font-semibold text-cyan-100/70">
                            No other inmates
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

export default function Jail({ release_time, cell_block, inmates, works }: {
    release_time: number | null;
    cell_block: string | null;
    inmates: Inmate[];
    works: JailWork[];
}) {
    const { auth } = usePage().props as any;
    const character = auth?.character;

    // Subscribed directly to reactive Global Server Clock!
    const serverClock = useServerClock();
    const cellBlock = useMemo(() => cellBlockLabel(cell_block), [cell_block]);

    const remaining = release_time ? Math.max(0, release_time - serverClock) : 0;
    const isReleased = release_time != null && serverClock >= release_time;

    useEffect(() => {
        window.history.pushState(null, '', window.location.href);
        const onPopState = () => window.history.pushState(null, '', window.location.href);
        const onPageShow = (e: PageTransitionEvent) => {
            if (e.persisted) router.get(window.location.href, {}, { preserveState: false });
        };
        window.addEventListener('popstate', onPopState);
        window.addEventListener('pageshow', onPageShow);
        return () => {
            window.removeEventListener('popstate', onPopState);
            window.removeEventListener('pageshow', onPageShow);
        };
    }, []);

    useEffect(() => {
        if (isReleased) {
            router.get(route('dashboard'));
            return;
        }

        const poll = setInterval(() => router.reload({ only: ['release_time', 'inmates'] }), 30_000);
        return () => clearInterval(poll);
    }, [isReleased]);

    const handleLogout = () => router.post('/logout');

    const inmateRows = useMemo<InmateRow[]>(() => {
        const others: InmateRow[] = (inmates ?? []).map(inmate => ({
            displayName: inmate.display_name,
            timeLeft: inmate.jail_until ? Math.max(0, inmate.jail_until - serverClock) : 0,
            isPlayer: false,
        }));

        const me: InmateRow = {
            displayName: character?.displayName ?? 'You',
            timeLeft: remaining,
            isPlayer: true,
        };

        return [me, ...others];
    }, [inmates, serverClock, remaining, character]);

    const timers: Record<string, number> = character?.timers ?? {};

    return (
        <>
            <Head title={cellBlock} />

            <div className="min-h-screen bg-slate-950 text-white flex flex-col">
                <JailHeader
                    serverClock={serverClock}
                    displayName={character?.displayName ?? 'You'}
                    timers={timers}
                    onLogout={handleLogout}
                />

                <main className="flex-1 overflow-y-auto">
                    <div className="w-full max-w-5xl mx-auto p-4 sm:p-6 space-y-4 flex flex-col">
                        <SentenceHero
                            remaining={remaining}
                            cellBlock={cellBlock}
                        />

                        <div className="flex-1 flex flex-col gap-4">
                            <WorkList
                                works={works ?? []}
                                workCd={Math.max(0, (timers.next_work_at ?? 0) - serverClock)}
                                serverClock={serverClock}
                            />

                        </div>
                    </div>
                </main>

                <InmateList rows={inmateRows} />
            </div>
        </>
    );
}

Jail.layout = (page: React.ReactNode) => page;
