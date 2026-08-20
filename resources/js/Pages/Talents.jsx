import { useState, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import GameLayout from '@/Layouts/GameLayout';
import {
    Lightning, Lock, Brain, Clock, GraduationCap, ShieldChevron, Skull, Heartbeat
} from '@phosphor-icons/react';

const iconMap = { GraduationCap, Lightning, ShieldChevron, Skull, Heartbeat };
const resolveIcon = (name) => iconMap[name] || Lightning;

const formatCooldown = (seconds) => {
    if (seconds <= 0) return '0s';
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    if (m < 1) return `${s}s`;
    if (m < 60) return s > 0 ? `${m}m ${s}s` : `${m}m`;
    const h = Math.floor(m / 60);
    const rm = m % 60;
    return rm > 0 ? `${h}h ${rm}m` : `${h}h`;
};

export default function Talents({ talents, cooldown }) {
    const { flash } = usePage().props;
    const [processing, setProcessing] = useState(false);
    const [remaining, setRemaining] = useState({});

    useEffect(() => {
        const initial = {};
        talents.forEach(t => {
            if (t.remaining > 0) initial[t.id] = t.remaining;
        });
        setRemaining(initial);

        const interval = setInterval(() => {
            setRemaining(prev => {
                const next = {};
                let anyActive = false;
                for (const [id, val] of Object.entries(prev)) {
                    if (val > 1) {
                        next[id] = val - 1;
                        anyActive = true;
                    }
                }
                if (!anyActive) clearInterval(interval);
                return next;
            });
        }, 1000);

        return () => clearInterval(interval);
    }, [talents]);

    const activate = (talentId) => {
        setProcessing(true);
        router.post('/talents/activate', { talent_id: talentId }, {
            only: ['auth', 'flash', 'talents', 'cooldown'],
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <>
            <Head title="Talents - TheDirector" />
            <div className="max-w-4xl mx-auto p-6">
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-2xl font-bold text-white mb-1">Talents</h1>
                        <p className="text-sm text-slate-400">Take advantage of your dedication.</p>
                    </div>

                </div>

                <div className="space-y-3">
                    {talents.length === 0 ? (
                        <div className="p-12 text-center rounded-xl border border-slate-700/30 bg-slate-800/30">
                            <Brain className="h-12 w-12 text-slate-600 mx-auto mb-3" />
                            <p className="text-slate-400">No talents available</p>
                            <p className="text-sm text-slate-500 mt-2">Talents will appear here as you progress</p>
                        </div>
                    ) : (
                        talents.map((talent) => {
                            const Icon = resolveIcon(talent.icon);
                            const timeLeft = remaining[talent.id] || 0;
                            const isActive = talent.active;
                            const onCooldown = talent.on_cooldown;

                            return (
                                <div
                                    key={talent.id}
                                    className={`relative rounded-xl border transition-all ${isActive
                                        ? 'bg-amber-900/20 border-amber-500/40 ring-1 ring-amber-500/20'
                                        : 'bg-slate-800/20 border-slate-700/30'
                                        } ${processing ? 'opacity-50' : ''}`}
                                >
                                    <div className="p-4">
                                        <div className="flex items-start gap-4">
                                            <div className={`w-10 h-10 rounded-xl flex items-center justify-center border shrink-0 ${talent.unlocked
                                                ? isActive
                                                    ? 'border-amber-500/40 bg-amber-500/15 text-amber-400'
                                                    : 'border-amber-500/30 bg-amber-500/10 text-amber-400'
                                                : 'border-slate-700/30 bg-slate-900/50 text-slate-600'
                                                }`}>
                                                {talent.unlocked ? (
                                                    <Icon className="h-5 w-5" weight="fill" />
                                                ) : (
                                                    <Lock className="h-5 w-5" weight="bold" />
                                                )}
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center gap-3 mb-1">
                                                    <span className={`font-semibold ${talent.unlocked ? 'text-white' : 'text-slate-500'}`}>
                                                        {talent.name}
                                                    </span>
                                                    {!talent.unlocked ? (
                                                        <span className="text-[10px] font-black px-2 py-0.5 rounded border bg-slate-800 text-slate-500 border-slate-700 uppercase tracking-widest">
                                                            Locked
                                                        </span>
                                                    ) : isActive ? (
                                                        <>
                                                            <span className="text-[10px] font-black px-2 py-0.5 rounded border bg-amber-500/20 text-amber-400 border-amber-500/30 uppercase tracking-widest">
                                                                Active
                                                            </span>
                                                            {timeLeft > 0 && (
                                                                <span className="ml-auto text-xs text-slate-500 flex items-center gap-1">
                                                                    <Clock className="h-3 w-3" />
                                                                    {formatCooldown(timeLeft)}
                                                                </span>
                                                            )}
                                                        </>
                                                    ) : onCooldown && timeLeft > 0 ? (
                                                        <span className="ml-auto text-xs text-slate-500 flex items-center gap-1">
                                                            <Clock className="h-3 w-3" />
                                                            {formatCooldown(timeLeft)}
                                                        </span>
                                                    ) : null}
                                                </div>
                                                <p className={`text-sm leading-relaxed mb-3 ${talent.unlocked ? 'text-slate-300' : 'text-slate-600'}`}>
                                                    {talent.description}
                                                </p>
                                                {talent.unlocked && (
                                                    <div className="flex items-center gap-3">
                                                        <span className="flex items-center gap-1 text-[10px] text-slate-600 font-bold uppercase tracking-wider">
                                                            <Clock className="h-3 w-3" />
                                                            {formatCooldown(talent.cooldown)} cooldown
                                                        </span>
                                                    </div>
                                                )}
                                            </div>
                                            <div className="shrink-0">
                                                {talent.unlocked && !isActive && !onCooldown ? (
                                                    <button
                                                        onClick={() => activate(talent.id)}
                                                        disabled={processing}
                                                        className="px-4 py-2 rounded-lg text-xs font-medium bg-amber-600 hover:bg-amber-500 text-white transition disabled:opacity-50 flex items-center gap-2"
                                                    >
                                                        <Brain className="h-3.5 w-3.5" weight="fill" />
                                                        Activate
                                                    </button>
                                                ) : isActive ? (
                                                    <button
                                                        disabled
                                                        className="px-4 py-2 rounded-lg text-xs font-medium bg-amber-500/20 text-amber-400 border border-amber-500/30 cursor-not-allowed flex items-center gap-2"
                                                    >
                                                        <Brain className="h-3.5 w-3.5" weight="fill" />
                                                        Activated
                                                    </button>
                                                ) : onCooldown ? (
                                                    <button
                                                        disabled
                                                        className="px-4 py-2 rounded-lg text-xs font-medium bg-slate-800/50 text-slate-500 border border-slate-700/30 cursor-not-allowed flex items-center gap-2"
                                                    >
                                                        <Clock className="h-3.5 w-3.5" />
                                                        Cooldown
                                                    </button>
                                                ) : null}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            );
                        })
                    )}
                </div>
            </div>
        </>
    );
}

Talents.layout = page => <GameLayout children={page} />;
