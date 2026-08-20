import { useState, useEffect } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Heart, Clock, SignOut, ShieldSlash, Pulse } from '@phosphor-icons/react';

interface HospitalProps {
    release_time: number;
    reason?: string;
}

interface PageProps extends Record<string, any> {
    flash?: {
        release_time?: number;
        reason?: string;
    };
    serverTime: string;
}

function fmt(seconds: number): string {
    if (seconds <= 0) return '0:00';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    if (h > 0) return `${h}h ${m}m ${String(s).padStart(2, '0')}s`;
    return `${m}:${String(s).padStart(2, '0')}`;
}

export default function Hospital({ release_time, reason: propReason }: HospitalProps) {
    const { flash, serverTime } = usePage<PageProps>().props;

    const releaseTimestamp = release_time || flash?.release_time || 0;
    const reason = propReason || flash?.reason || 'Severe blunt-force trauma sustained during a street altercation.';

    const [serverClock, setServerClock] = useState(() => {
        const ts = Math.floor(new Date(serverTime).getTime() / 1000);
        return Number.isFinite(ts) ? ts : Math.floor(Date.now() / 1000);
    });

    useEffect(() => {
        if (serverTime) {
            const ts = Math.floor(new Date(serverTime).getTime() / 1000);
            if (Number.isFinite(ts)) setServerClock(ts);
        }
    }, [serverTime]);

    useEffect(() => {
        const interval = setInterval(() => {
            setServerClock(prev => prev + 1);
        }, 1000);
        return () => clearInterval(interval);
    }, []);

    const remaining = Math.max(0, releaseTimestamp - serverClock);
    const totalDuration = releaseTimestamp - (releaseTimestamp - remaining);
    const pct = totalDuration > 0 ? ((totalDuration - remaining) / totalDuration) * 100 : 0;
    const isReleased = remaining <= 0;

    useEffect(() => {
        window.history.pushState(null, '', window.location.href);
        const trapBack = () => {
            window.history.pushState(null, '', window.location.href);
        };
        const handlePageShow = (e: PageTransitionEvent) => {
            if (e.persisted) router.get(window.location.href, {}, { preserveState: false });
        };
        window.addEventListener('popstate', trapBack);
        window.addEventListener('pageshow', handlePageShow);
        return () => {
            window.removeEventListener('popstate', trapBack);
            window.removeEventListener('pageshow', handlePageShow);
        };
    }, []);

    useEffect(() => {
        if (isReleased) {
            router.get('/dashboard');
            return;
        }

        const pollInterval = setInterval(() => {
            router.reload({ only: ['flash'] });
        }, 30000);

        return () => clearInterval(pollInterval);
    }, [isReleased]);

    const handleLogout = () => {
        router.post('/logout');
    };

    return (
        <div className="min-h-screen bg-slate-950 text-white flex flex-col relative overflow-hidden">
            <Head title="Hospitalized - TheDirector" />


            <div className="absolute inset-0 pointer-events-none opacity-[0.15]">
                <svg className="absolute w-full h-full" preserveAspectRatio="none" viewBox="0 0 1200 400">
                    <defs>
                        <linearGradient id="ekgGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                            <stop offset="0%" stopColor="rgb(6, 182, 212)" stopOpacity="0" />
                            <stop offset="20%" stopColor="rgb(6, 182, 212)" stopOpacity="1" />
                            <stop offset="80%" stopColor="rgb(6, 182, 212)" stopOpacity="1" />
                            <stop offset="100%" stopColor="rgb(6, 182, 212)" stopOpacity="0" />
                        </linearGradient>
                    </defs>

                    <motion.path
                        d="M-300,200 L0,200 L40,200 
                           Q45,195 50,193 Q55,195 60,200
                           L70,200 L72,205 L74,195 L76,220 L78,160 L80,200 L82,205 L84,200
                           L94,200 Q99,195 104,190 Q109,195 114,200
                           L200,200 L1600,200"
                        stroke="url(#ekgGradient)"
                        strokeWidth="2.5"
                        fill="none"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        initial={{ x: 0 }}
                        animate={{ x: [-300, 1400] }}
                        transition={{
                            duration: 2.5,
                            repeat: Infinity,
                            ease: "linear",
                            repeatDelay: 0
                        }}
                    />

                    <motion.path
                        d="M-300,250 L0,250 L35,250 
                           Q40,246 45,244 Q50,246 55,250
                           L65,250 L67,254 L69,246 L71,268 L73,218 L75,250 L77,254 L79,250
                           L89,250 Q94,246 99,242 Q104,246 109,250
                           L190,250 L1600,250"
                        stroke="url(#ekgGradient)"
                        strokeWidth="2"
                        fill="none"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        opacity="0.6"
                        initial={{ x: 0 }}
                        animate={{ x: [-300, 1400] }}
                        transition={{
                            duration: 2.5,
                            repeat: Infinity,
                            ease: "linear",
                            delay: 0.4,
                            repeatDelay: 0
                        }}
                    />

                    <motion.path
                        d="M-300,150 L0,150 L38,150 
                           Q43,147 48,145 Q53,147 58,150
                           L68,150 L70,154 L72,146 L74,168 L76,128 L78,150 L80,154 L82,150
                           L92,150 Q97,147 102,143 Q107,147 112,150
                           L195,150 L1600,150"
                        stroke="url(#ekgGradient)"
                        strokeWidth="1.8"
                        fill="none"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        opacity="0.4"
                        initial={{ x: 0 }}
                        animate={{ x: [-300, 1400] }}
                        transition={{
                            duration: 2.5,
                            repeat: Infinity,
                            ease: "linear",
                            delay: 0.8,
                            repeatDelay: 0
                        }}
                    />
                </svg>


                <motion.div
                    className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-40 h-40 rounded-full bg-cyan-500/5"
                    animate={{
                        scale: [1, 1.2, 1, 1.1, 1],
                        opacity: [0.2, 0.4, 0.2, 0.3, 0.2]
                    }}
                    transition={{
                        duration: 2.5,
                        repeat: Infinity,
                        ease: "easeInOut"
                    }}
                />
            </div>

            <header className="h-12 border-b border-slate-800/50 flex items-center justify-between px-3 sm:px-5 bg-slate-950/80 sticky top-0 z-50 backdrop-blur-sm">
                <div className="font-bold text-sm whitespace-nowrap">
                    THE <span className="text-cyan-400">DIRECTOR</span>
                </div>
            </header>

            <div className="flex-1 flex items-center justify-center p-4 sm:p-8 relative z-10">
                <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.6, ease: [0.22, 1, 0.36, 1] }}
                    className="w-full max-w-lg"
                >
                    <div className="relative rounded-2xl overflow-hidden border border-white/5 shadow-2xl">
                        <div className="absolute inset-0 bg-slate-950" />
                        <div className="absolute inset-0 bg-gradient-to-b from-slate-900/40 via-slate-950 to-slate-950" />

                        <div className="relative px-8 py-10 sm:px-10 sm:py-12 space-y-8">
                            <div className="flex items-center gap-2">
                                <span className="relative flex h-2 w-2">
                                    <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-500/40" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-red-500/80" />
                                </span>
                                <span className="text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500">
                                    Hospital
                                </span>
                            </div>

                            <div>
                                <h1 className="text-3xl sm:text-4xl font-light text-white tracking-tight">
                                    ICU
                                </h1>
                                <p className="text-sm text-slate-500 mt-2 leading-relaxed max-w-sm">
                                    Your attacker beat the sense out of you so  badly you had to be rushed to the  emergency room!
                                </p>
                            </div>

                            <div className="rounded-xl bg-slate-900/60 border border-white/5 p-5 space-y-3">
                                <div className="flex items-center gap-2">
                                    <Pulse size={13} className="text-slate-500" />
                                    <span className="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">
                                        Their Message:
                                    </span>
                                </div>
                                <p className="text-xs text-slate-400 leading-relaxed">{reason}</p>
                            </div>

                            <div className="space-y-3">
                                <div className="flex items-center gap-2">
                                    <Heart size={13} className="text-slate-500" />
                                    <span className="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">
                                        Time Remaining
                                    </span>
                                </div>
                                <div>
                                    <span className="text-4xl font-light text-white tabular-nums font-mono tracking-tight">
                                        {isReleased ? 'Released' : fmt(remaining)}
                                    </span>
                                </div>
                            </div>

                            <div className="flex items-center gap-2 text-slate-600">
                                <ShieldSlash size={12} />
                                <span className="text-[10px] uppercase tracking-widest font-medium">
                                    All actions restricted
                                </span>
                            </div>

                            <div className="border-t border-white/5" />

                            <button
                                onClick={handleLogout}
                                className="flex items-center gap-2 text-slate-600 hover:text-slate-400 transition-colors"
                            >
                                <SignOut size={12} />
                                <span className="text-[10px] font-medium uppercase tracking-widest">
                                    Log Out &amp; Rest
                                </span>
                            </button>
                        </div>
                    </div>
                </motion.div>
            </div>
        </div>
    );
}
