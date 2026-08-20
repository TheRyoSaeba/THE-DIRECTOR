import { useState, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { route } from 'ziggy-js';
import {
    GraduationCap, BookOpen, Briefcase, Clock,
    CheckCircle, Lock, Crown, X, User,
    Student, Scroll, Medal, Sparkle, Wrench, Buildings
} from '@phosphor-icons/react';
import GameLayout from '@/Layouts/GameLayout';

interface PageProps {
    auth: any;
    flash: {
        success?: string;
        error?: string;
    };
    [key: string]: any;
}

interface Degree {
    code: string;
    name: string;
    career: string | null;
    required_cycles: number;
    cycles: number;
    enrolled: boolean;
    completed: boolean;
    city_id: number | null;
    is_local: boolean;
}

interface Props {
    citySlug: string;
    university: {
        name: string;
        image_url: string | null;
        owner: {
            name: string;
            avatar_url: string | null;
        } | null;
    } | null;
    degrees: Degree[];
    canEnroll: boolean;
    activeCityId: number | null;
    tuition: number;
    isOwner: boolean;
}

const DEGREE_CONFIG: Record<string, { color: string; bgImage: string; icon: any }> = {
    finance: {
        color: '#B87333', // Copper
        bgImage: 'https://images.thedirector.app/businesses/finance.png',
        icon: Scroll
    },
    law: {
        color: '#800080', // Purple
        bgImage: 'https://images.thedirector.app/businesses/law.png',
        icon: Medal
    },
    medicine: {
        color: '#4CBB17', // Kelly Green
        bgImage: 'https://images.thedirector.app/businesses/healthcare.png',
        icon: Student
    },
    engineering: {
        color: '#e86231', // Engineering regalia orange
        bgImage: 'https://images.thedirector.app/businesses/engineering.png',
        icon: Wrench
    },
    business: {
        color: '#af795f', // Commerce regalia drab
        bgImage: 'https://images.thedirector.app/businesses/MBA.png',
        icon: Buildings
    },
};

const CAREER_LABELS: Record<string, string> = {
    banking: 'Banking',
    law: 'Law',
    healthcare: 'Healthcare',
};

const formatMoney = (amount: number) => `$${amount.toLocaleString()}`;

export default function University({ citySlug, university, degrees, canEnroll, activeCityId, tuition, isOwner }: Props) {
    const { auth } = usePage<PageProps>().props;
    const [processing, setProcessing] = useState(false);
    const [showOwnerPanel, setShowOwnerPanel] = useState(false);
    const [settingTuition, setSettingTuition] = useState(tuition);

    const studyTimer = auth?.character?.timers?.next_study_at ?? 0;
    const serverTime = auth?.serverTime;
    const characterMoney = auth?.character?.cleanCash ?? 0;

    const [countdown, setCountdown] = useState(0);

    const universityName = university?.name || 'University';

    const universityImage = university?.image_url || 'https://images.thedirector.app/city-placeholders/university.jpg';
    const ownerName = university?.owner?.name;

    useEffect(() => {
        if (!studyTimer || !serverTime) { setCountdown(0); return; }
        const serverClock = Math.floor(new Date(serverTime).getTime() / 1000);
        const remaining = Math.max(0, studyTimer - serverClock);
        setCountdown(remaining);

        if (remaining <= 0) return;

        const interval = setInterval(() => {
            setCountdown(prev => {
                if (prev <= 1) { clearInterval(interval); return 0; }
                return prev - 1;
            });
        }, 1000);
        return () => clearInterval(interval);
    }, [studyTimer, serverTime]);



    const formatTime = (seconds: number) => {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return `${m}:${s.toString().padStart(2, '0')}`;
    };

    const handleEnroll = (code: string) => {
        if (processing) return;
        setProcessing(true);
        router.post(route('city.university.enroll', { city: citySlug }), { degree_code: code }, {
            only: ['auth', 'flash', 'degrees', 'canEnroll', 'activeCityId'],
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    const handleStudy = (code: string) => {
        if (processing || countdown > 0) return;
        setProcessing(true);
        router.post(route('city.university.study', { city: citySlug }), { degree_code: code }, {
            only: ['auth', 'flash', 'degrees'],
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    const handleStartCareer = (code: string) => {
        if (processing) return;
        setProcessing(true);
        router.post(route('city.university.start-career', { city: citySlug }), { degree_code: code }, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    const handleSaveSettings = () => {
        if (processing) return;
        setProcessing(true);
        router.post(route('city.university.settings', { city: citySlug }), { tuition: settingTuition }, {
            only: ['auth', 'flash', 'tuition', 'university'],
            preserveScroll: true,
            onFinish: () => { setProcessing(false); setShowOwnerPanel(false); },
        });
    };

    const activeDegrees = degrees.filter(d => d.enrolled && !d.completed);
    const canStudyHere = activeDegrees.length === 0 || activeDegrees.some(d => d.is_local);
    const characterCareer = auth?.character?.career;

    return (
        <div className="max-w-5xl mx-auto p-3 sm:p-4 space-y-4 relative">
            <Head title="University" />


            <div className="relative rounded-[2rem] overflow-hidden border border-slate-800 shadow-2xl bg-slate-900 group h-64 sm:h-80">
                <div
                    className="absolute inset-0 bg-cover bg-center transition-transform duration-1000 group-hover:scale-105"
                    style={{ backgroundImage: `url('${universityImage}')` }}
                />
                <div className="absolute inset-0 bg-gradient-to-t from-slate-950/40 via-slate-950/10 to-transparent" />

                <div className="relative h-full p-6 sm:p-8 flex flex-col justify-end">
                    <div className="flex flex-col md:flex-row md:items-end justify-between gap-6">
                        <div className="space-y-2">
                            <div className="flex items-center gap-3">


                            </div>
                            <h1 className="text-3xl sm:text-5xl font-black text-white tracking-tighter uppercase filter drop-shadow-lg leading-none">
                                {universityName}
                            </h1>
                            <div className="flex items-center gap-2 text-xs text-slate-400 font-bold uppercase tracking-widest opacity-80">
                                {/* TODO: Make this bold white */}
                                <span className="text-white">All excellent things are as difficult as they are rare.</span>
                            </div>
                        </div>

                        <div className="flex items-center gap-4">

                            {ownerName && (
                                <div className="flex items-center gap-3 bg-slate-950/80 backdrop-blur-xl border border-white/5 rounded-2xl pl-2 pr-6 py-1.5 shadow-2xl">
                                    <div className="w-10 h-10 rounded-xl overflow-hidden ring-2 ring-indigo-500/20 bg-slate-900 flex items-center justify-center shrink-0">
                                        {university?.owner?.avatar_url ? (
                                            <img src={university.owner.avatar_url} alt={ownerName} className="w-full h-full object-cover" />
                                        ) : (
                                            <User size={20} className="text-indigo-400/40" />
                                        )}
                                    </div>
                                    <div className="text-left">
                                        <div className="text-[8px] uppercase tracking-[0.2em] text-indigo-400/70 font-black mb-0.5">Chancellor</div>
                                        <div className="text-white font-black text-xs leading-none">{ownerName}</div>
                                    </div>
                                </div>
                            )}

                            <div className="flex flex-col items-end gap-1 bg-indigo-950/40 border border-indigo-500/20 rounded-2xl px-5 py-2 backdrop-blur-xl">
                                <div className="text-[8px] font-black uppercase text-indigo-400/70 tracking-widest">Tuition</div>
                                <div className="text-indigo-300 font-mono font-black text-lg leading-none">
                                    {formatMoney(tuition)}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>






            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-[1.6rem] justify-items-center">
                {degrees.map(degree => {
                    const config = DEGREE_CONFIG[degree.code] || DEGREE_CONFIG.finance;
                    const progress = degree.required_cycles > 0 ? Math.round((degree.cycles / degree.required_cycles) * 100) : 0;
                    const Icon = config.icon;

                    return (
                        <div
                            key={degree.code}
                            className="group relative w-full max-w-[16rem] min-h-[22.4rem] h-full rounded-3xl overflow-hidden border border-white/10 bg-slate-900 shadow-2xl transition-all duration-500 hover:scale-[1.02] hover:shadow-[0_16px_40px_rgba(0,0,0,0.5)] flex flex-col"
                        >

                            <div
                                className="absolute inset-0 bg-cover bg-center transition-transform duration-700 group-hover:scale-110 opacity-60"
                                style={{ backgroundImage: `url('${config.bgImage}')` }}
                            />


                            <div className="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-950/20 to-transparent" />


                            <div className="relative h-full p-[1.6rem] flex flex-col justify-end">




                                <div className="mb-auto">
                                    {degree.completed ? (
                                        <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/20 border border-emerald-500/30 backdrop-blur-md">
                                            <CheckCircle weight="fill" className="w-3.5 h-3.5 text-emerald-400" />
                                            <span className="text-[10px] font-black uppercase tracking-widest text-emerald-400">Mastered</span>
                                        </div>
                                    ) : degree.enrolled ? (
                                        <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 border border-white/20 backdrop-blur-md">
                                            <span className="relative flex h-2 w-2">
                                                <span className={`animate-ping absolute inline-flex h-full w-full rounded-full opacity-75`} style={{ backgroundColor: config.color }}></span>
                                                <span className={`relative inline-flex rounded-full h-2 w-2`} style={{ backgroundColor: config.color }}></span>
                                            </span>
                                            <span className="text-[10px] font-black uppercase tracking-widest text-white">Active</span>
                                        </div>
                                    ) : null}
                                </div>


                                <div className="mb-5 space-y-2">
                                    <h3
                                        className="text-2xl font-black uppercase tracking-tighter leading-none drop-shadow-lg"
                                        style={{ color: config.color }}
                                    >
                                        {degree.name}
                                    </h3>
                                </div>


                                {degree.enrolled && !degree.completed && (
                                    <div className="mb-5 space-y-2">
                                        <div className="flex justify-between text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                            <span>Progress</span>
                                            <span className="text-white">{progress}%</span>
                                        </div>
                                        <div className="h-1.5 bg-white/10 rounded-full overflow-hidden">
                                            <div
                                                className="h-full rounded-full transition-all duration-1000"
                                                style={{ width: `${progress}%`, backgroundColor: config.color, boxShadow: `0 0 10px ${config.color}66` }}
                                            />
                                        </div>
                                    </div>
                                )}


                                <div className="mt-2">
                                    {degree.completed ? (
                                        degree.career && characterCareer === degree.career ? (
                                            <div className="w-full py-3 rounded-xl bg-emerald-500/20 border border-emerald-500/30 flex items-center justify-center gap-2">
                                                <CheckCircle weight="fill" className="w-4 h-4 text-emerald-400" />
                                                <span className="font-black text-xs uppercase tracking-widest text-emerald-400">Career Active</span>
                                            </div>
                                        ) : (
                                            <button
                                                onClick={() => handleStartCareer(degree.code)}
                                                disabled={processing}
                                                className="w-full py-3 rounded-xl font-black text-[11px] uppercase tracking-widest text-white flex items-center justify-center gap-2 transition-all hover:scale-[1.02]"
                                                style={{ background: `linear-gradient(135deg, ${config.color}dd, ${config.color})`, boxShadow: `0 10px 30px -10px ${config.color}66` }}
                                            >
                                                <Briefcase className="w-4 h-4" /> Start Career
                                            </button>
                                        )
                                    ) : degree.enrolled ? (
                                        <button
                                            onClick={() => handleStudy(degree.code)}
                                            disabled={processing || countdown > 0}
                                            className={`w-full py-3 rounded-xl font-black text-[11px] uppercase tracking-widest text-white flex items-center justify-center gap-2 transition-all hover:scale-[1.02] ${countdown > 0 ? 'opacity-50 cursor-not-allowed bg-slate-800' : ''}`}
                                            style={!(countdown > 0) ? { background: `linear-gradient(135deg, ${config.color}dd, ${config.color})`, boxShadow: `0 10px 30px -10px ${config.color}66` } : {}}
                                        >
                                            {countdown > 0 ? (
                                                <><Clock className="w-4 h-4 animate-pulse" />{formatTime(countdown)}</>
                                            ) : (
                                                <><BookOpen className="w-4 h-4" /> Study Session</>
                                            )}
                                        </button>
                                    ) : (
                                        <button
                                            onClick={() => handleEnroll(degree.code)}
                                            disabled={processing}
                                            className="w-full py-3 rounded-xl font-black text-[11px] uppercase tracking-widest text-white flex items-center justify-center gap-2 transition-all hover:bg-white/10 border border-white/20 group-hover:border-white/40"
                                        >
                                            <GraduationCap className="w-4 h-4" /> Enroll Now
                                        </button>
                                    )}


                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>


            {isOwner && (
                <>
                    <button
                        onClick={() => setShowOwnerPanel(true)}
                        className="fixed bottom-6 right-6 z-50 w-16 h-16 bg-gradient-to-br from-amber-500 to-yellow-500 rounded-full flex items-center justify-center shadow-[0_0_30px_rgba(245,158,11,0.4)] hover:shadow-[0_0_50px_rgba(245,158,11,0.6)] transition-all hover:scale-110 group"
                    >
                        <Crown weight="fill" className="w-8 h-8 text-slate-900 group-hover:rotate-12 transition-transform" />
                    </button>

                    {showOwnerPanel && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" onClick={() => setShowOwnerPanel(false)}>
                            <div className="absolute inset-0 bg-slate-950/80 backdrop-blur-md" />
                            <div
                                className="relative bg-gradient-to-br from-slate-900 to-slate-950 border border-amber-500/30 rounded-3xl p-8 w-full max-w-sm shadow-[0_0_60px_rgba(245,158,11,0.2)]"
                                onClick={e => e.stopPropagation()}
                            >
                                <button
                                    onClick={() => setShowOwnerPanel(false)}
                                    className="absolute top-4 right-4 text-slate-500 hover:text-white transition-colors"
                                >
                                    <X className="w-6 h-6" />
                                </button>

                                <div className="text-center mb-8">
                                    <div className="w-16 h-16 bg-gradient-to-br from-amber-500 to-yellow-500 rounded-2xl mx-auto flex items-center justify-center shadow-lg shadow-amber-500/20 mb-4">
                                        <Crown weight="fill" className="w-8 h-8 text-slate-900" />
                                    </div>
                                    <h3 className="text-xl font-black text-white uppercase tracking-tight">Chancellor Controls</h3>
                                    <p className="text-sm text-slate-400 font-medium">Manage university tuition rates</p>
                                </div>

                                <div className="space-y-6">
                                    <div className="bg-slate-950/50 rounded-2xl p-4 border border-white/5">
                                        <div className="flex justify-between text-xs font-bold text-amber-500 uppercase mb-3">
                                            <span>Tuition per Session</span>
                                            <span className="bg-amber-500/10 px-2 py-0.5 rounded text-amber-400 border border-amber-500/20">{formatMoney(settingTuition)}</span>
                                        </div>
                                        <input
                                            type="range"
                                            min="0"
                                            max="75000"
                                            step="500"
                                            value={settingTuition}
                                            onChange={(e) => setSettingTuition(parseInt(e.target.value))}
                                            className="w-full h-2 bg-slate-800 rounded-lg appearance-none cursor-pointer accent-amber-500"
                                        />
                                        <div className="flex justify-between text-[10px] text-slate-600 font-bold uppercase mt-2">
                                            <span>Free</span>
                                            <span>Max $75k</span>
                                        </div>
                                    </div>

                                    <button
                                        onClick={handleSaveSettings}
                                        disabled={processing}
                                        className="w-full py-4 bg-gradient-to-r from-amber-500 to-yellow-500 text-slate-900 font-black text-sm uppercase tracking-widest rounded-xl disabled:opacity-50 hover:shadow-[0_0_30px_rgba(245,158,11,0.4)] transition-all hover:scale-[1.02]"
                                    >
                                        {processing ? 'SAVING...' : 'UPDATE SETTINGS'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

University.layout = (page: React.ReactNode) => <GameLayout children={page} />;
