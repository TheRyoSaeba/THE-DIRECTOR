import { useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { formatUTC } from '@/Layouts/GameLayoutComponents';
import { SignOut } from '@phosphor-icons/react';

interface DeathPageProps {
    character_name: string;
    died_at: string;
    death_cause?: string | null;
    death_reason?: string | null;
    last_words?: string | null;
    can_reincarnate: boolean;
}

export default function DeathPage({
    character_name,
    died_at,
    death_cause,
    death_reason,
    last_words,
    can_reincarnate,
}: DeathPageProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const formattedTime = formatUTC(died_at);

    // Last words form state
    const [wordsDraft, setWordsDraft] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const wordsAlreadySet = !!last_words;

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
        if (!containerRef.current) return;
        const container = containerRef.current;
        for (let i = 0; i < 20; i++) {
            const drip = document.createElement('div');
            drip.className = 'blood-drip';
            drip.style.left = Math.random() * 100 + '%';
            drip.style.animationDelay = Math.random() * 5 + 's';
            drip.style.animationDuration = 3 + Math.random() * 6 + 's';
            container.appendChild(drip);
        }
        return () => {
            while (container.firstChild) container.removeChild(container.firstChild);
        };
    }, []);

    const handleSubmitLastWords = () => {
        if (!wordsDraft.trim() || submitting) return;
        setSubmitting(true);
        router.post(
            '/death/last-words',
            { last_words: wordsDraft.trim() },
            {
                preserveScroll: true,
                onFinish: () => setSubmitting(false),
            }
        );
    };

    return (
        <div
            ref={containerRef}
            className="relative min-h-screen w-full overflow-hidden"
            style={{
                backgroundColor: '#0a0a0a',
                backgroundImage: `
          radial-gradient(circle at 20% 30%, #2a1a1a 0%, #0f0f0f 80%),
          repeating-linear-gradient(45deg, rgba(139,0,0,0.02) 0px, rgba(139,0,0,0.02) 2px, transparent 2px, transparent 4px)
        `,
            }}
        >
            {/* Ambient effects */}
            <div className="absolute inset-0 pointer-events-none">
                <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_center,_rgba(200,200,200,0.1)_0%,_transparent_80%)] animate-pulse" />
                <div className="absolute top-0 left-0 w-full h-full bg-[url('data:image/svg+xml,%3Csvg viewBox='0 0 400 400' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.01' numOctaves='1' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.08'/%3E%3C/svg%3E')] opacity-30" />
            </div>
            <div className="absolute inset-0 opacity-20 pointer-events-none">
                <div className="absolute top-1/4 left-1/3 w-96 h-96 rounded-full bg-red-900/30 blur-3xl" />
                <div className="absolute bottom-1/3 right-1/4 w-80 h-80 rounded-full bg-red-800/30 blur-3xl" />
            </div>

            {/* Logout */}
            <div className="absolute top-6 right-6 z-20">
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    className="group flex items-center gap-2 px-4 py-2 bg-black/40 border border-red-900/30 rounded-lg text-red-400/70 hover:text-red-300 hover:border-red-700/50 transition-all backdrop-blur-sm"
                >
                    <SignOut className="w-4 h-4 group-hover:rotate-12 transition-transform" weight="bold" />
                    <span className="text-xs uppercase tracking-wider">Logout</span>
                </Link>
            </div>

            {/* Main content */}
            <div className="relative z-10 flex items-center justify-center min-h-screen p-6">
                <motion.div
                    initial={{ opacity: 0, y: 40 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 2, ease: 'easeOut' }}
                    className="max-w-2xl w-full text-center space-y-8"
                >
                    {/* Title */}
                    <motion.div
                        animate={{
                            textShadow: [
                                '0 0 10px #8b0000, 0 0 20px #4a0000',
                                '0 0 15px #b30000, 0 0 30px #8b0000',
                                '0 0 10px #8b0000, 0 0 20px #4a0000',
                            ],
                        }}
                        transition={{ duration: 3, repeat: Infinity }}
                        className="text-5xl md:text-7xl font-black text-red-700 uppercase tracking-widest"
                        style={{
                            background: 'linear-gradient(135deg, #9f1a1a, #cf3a3a, #9f1a1a)',
                            WebkitBackgroundClip: 'text',
                            WebkitTextFillColor: 'transparent',
                            filter: 'drop-shadow(0 0 30px rgba(139,0,0,0.5))',
                        }}
                    >
                        {character_name}, you have died!
                    </motion.div>

                    {/* Death info grid */}
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        transition={{ delay: 0.8 }}
                        className="grid grid-cols-2 gap-4 mt-12 text-left"
                    >
                        <div className="bg-black/50 border border-red-900/30 rounded-lg p-5 backdrop-blur-sm">
                            <div className="text-xs uppercase text-red-400/60 tracking-wider mb-1">Time of death</div>
                            <div className="text-white/90 font-mono text-sm">{formattedTime}</div>
                        </div>
                        <div className="bg-black/50 border border-red-900/30 rounded-lg p-5 backdrop-blur-sm">
                            <div className="text-xs uppercase text-red-400/60 tracking-wider mb-1">Cause</div>
                            <div className="text-white/90 text-sm">{death_cause || 'Unknown'}</div>
                        </div>
                    </motion.div>

                    {/* Attacker's whisper */}
                    {death_reason && (
                        <motion.div
                            initial={{ opacity: 0, y: 20 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ delay: 1.2 }}
                            className="bg-black/60 border border-red-900/40 rounded-lg p-6 backdrop-blur-sm"
                        >
                            <div className="text-sm uppercase text-red-400/70 tracking-wider mb-3 flex items-center gap-2">
                                <span className="w-1 h-6 bg-red-600 rounded-full animate-pulse" />
                                Your attacker whispered...
                            </div>
                            <p className="text-white/90 text-lg italic leading-relaxed">
                                "{death_reason}"
                            </p>
                        </motion.div>
                    )}
                    <motion.div
                        initial={{ opacity: 0, rotate: -3, y: -8 }}
                        animate={{ opacity: 1, rotate: wordsAlreadySet ? [0, 1.5, -1.5, 0.5, -0.5, 0] : 0 }}
                        transition={{
                            opacity: { delay: 1.8, duration: 0.6 },
                            rotate: { delay: 2.2, duration: 4.5, ease: 'easeInOut' },
                        }}
                        style={{ transformOrigin: 'top center' }}
                        className="bg-black/70 border border-red-700/50 rounded-lg p-5 backdrop-blur-sm shadow-xl text-left"
                    >
                        <div className="text-xs uppercase text-red-300/80 tracking-wider mb-3 flex items-center gap-2">
                            <span className="w-1 h-4 bg-red-500 rounded-full" />
                            Last words
                        </div>

                        {wordsAlreadySet ? (
                            <p className="text-white/80 text-sm italic leading-relaxed">
                                "{last_words}"
                            </p>
                        ) : (
                            <div className="space-y-3">
                                <p className="text-white/40 text-xs leading-relaxed">
                                    You may leave a final message.
                                </p>
                                <textarea
                                    value={wordsDraft}
                                    onChange={e => setWordsDraft(e.target.value)}
                                    placeholder="Say something before the darkness takes you..."
                                    maxLength={300}
                                    rows={3}
                                    disabled={submitting}
                                    className="w-full bg-black/60 border border-red-900/40 rounded-lg px-4 py-3 text-sm text-white/80 placeholder:text-white/20 outline-none focus:border-red-700/60 transition-colors resize-none disabled:opacity-40 leading-relaxed"
                                />
                                <div className="flex items-center justify-between">
                                    <span className="text-[10px] text-white/20 font-mono">{wordsDraft.length} / 300</span>
                                    <button
                                        onClick={handleSubmitLastWords}
                                        disabled={!wordsDraft.trim() || submitting}
                                        className="px-5 py-2 bg-red-900/60 hover:bg-red-800/80 border border-red-700/40 text-red-200 text-xs font-bold uppercase tracking-widest rounded-lg transition-all disabled:opacity-30 disabled:cursor-not-allowed flex items-center gap-2"
                                    >
                                        {submitting && (
                                            <div className="w-3 h-3 border border-red-300/30 border-t-red-300 rounded-full animate-spin" />
                                        )}
                                        {submitting ? 'Recording…' : 'Speak your last words'}
                                    </button>
                                </div>
                            </div>
                        )}
                    </motion.div>

                    {/* Reincarnation */}
                    <motion.p
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        transition={{ delay: 2.4 }}
                        className="text-xs text-white uppercase tracking-widest pt-8"
                    >
                        {can_reincarnate
                            ? 'Begin the cycle anew.'
                            : 'You may create a new character in 12 hours.'}
                    </motion.p>

                    {can_reincarnate && (
                        <motion.div
                            initial={{ opacity: 0, scale: 0.9 }}
                            animate={{ opacity: 1, scale: 1 }}
                            transition={{ delay: 2.8 }}
                            className="pt-4"
                        >
                            <Link
                                href="/death/reincarnate"
                                method="post"
                                as="button"
                                className="px-8 py-3 bg-white text-black text-xs font-black uppercase tracking-[0.3em] rounded-full hover:bg-red-600 hover:text-white transition-all duration-500 shadow-[0_0_20px_rgba(255,255,255,0.2)] hover:shadow-[0_0_30px_rgba(220,38,38,0.4)]"
                            >
                                Reincarnate
                            </Link>
                        </motion.div>
                    )}
                </motion.div>
            </div>

            <style>{`
        .blood-drip {
          position: absolute;
          top: -20px;
          width: 3px;
          background: linear-gradient(to bottom, #a52a2a, #4a0000, transparent);
          border-radius: 1px;
          filter: blur(1px);
          animation: drip linear infinite;
          z-index: 5;
          pointer-events: none;
        }
        @keyframes drip {
          0% { opacity: 0; height: 0; }
          15% { opacity: 0.8; }
          100% { opacity: 0.2; height: 180px; top: 110%; }
        }
      `}</style>
        </div>
    );
}
