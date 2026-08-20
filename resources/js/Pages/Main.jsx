import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { X, CheckCircle, Warning } from '@phosphor-icons/react';
import { motion, AnimatePresence } from 'framer-motion';
import GamePreviewOverlay, { gamePreviewItems } from '@/Components/GamePreviewOverlay';
import { useServerClock } from '@/contexts/ClockContext';
import { formatUTC } from '@/Layouts/GameLayoutComponents';

export default function Main({ flash = {}, errors = {} }) {
    const [modal, setModal] = useState(null);
    const [isLoading, setIsLoading] = useState(false);
    const [previewOpen, setPreviewOpen] = useState(false);
    const [previewIndex, setPreviewIndex] = useState(0);

    const error = Object.values(errors)[0] || flash?.error;
    const success = flash?.success;


    const serverClock = useServerClock();
    const serverTimeDisplay = formatUTC(serverClock);

    const handleGoogleLogin = (e) => {
        setIsLoading(true);
    };

    const nextPreview = () => {
        setPreviewIndex((index) => (index + 1) % gamePreviewItems.length);
    };

    const previousPreview = () => {
        setPreviewIndex((index) => (index - 1 + gamePreviewItems.length) % gamePreviewItems.length);
    };

    return (
        <>
            <Head title="TheDirector">
                <meta name="description" content="A strategic game where corporate rules, political governance, and player choice determine whether you survive or thrive." />
                <meta property="og:type" content="website" />
                <meta property="og:url" content="https://thedirector.app/" />
                <meta property="og:title" content="TheDirector | Greed is Good." />
                <meta property="og:description" content="A strategic game where corporate rules, political governance, and player choice determine whether you survive or thrive." />
                <script type="application/ld+json">{`
        {
            "@context": "https://schema.org",
            "@type": "WebSite",
            "name": "TheDirector",
            "url": "https://thedirector.app",
            "description": "A strategic game where corporate rules, political governance, and player choice determine whether you survive or thrive."
        }
    `}</script>
            </Head>
            <div className="min-h-screen relative flex flex-col items-center justify-center p-4 sm:p-6 lg:p-8 overflow-hidden bg-slate-950 selection:bg-cyan-500/30 selection:text-white" style={{ fontFamily: "'Plus Jakarta Sans', sans-serif" }}>


                <picture className="absolute inset-0 z-0 pointer-events-none">
                    <source srcSet="/biggerbanner.webp" type="image/webp" />
                    <img
                        src="/biggerbanner.png"
                        alt=""
                        loading="eager"
                        decoding="async"
                        fetchPriority="high"
                        className="absolute inset-0 w-full h-full object-cover object-center"
                        draggable={false}
                    />
                </picture>
                <div className="absolute inset-0 z-0 bg-gradient-to-t from-slate-950 via-slate-950/60 to-slate-950/10 pointer-events-none" />

                <button
                    type="button"
                    onClick={() => setPreviewOpen(true)}
                    className={`fixed left-4 top-4 z-30 text-[11px] font-black uppercase tracking-[0.26em] text-white/80 drop-shadow-[0_2px_8px_rgba(0,0,0,0.85)] transition-colors hover:text-cyan-200 sm:left-6 sm:top-6 lg:left-8 lg:top-8 ${previewOpen ? 'pointer-events-none opacity-0' : ''}`}
                >
                    PREVIEW
                </button>

                <nav className={`fixed left-32 right-4 top-4 z-30 flex flex-wrap items-center justify-end gap-x-4 gap-y-2 sm:left-auto sm:right-6 sm:top-6 sm:gap-x-8 lg:right-8 lg:top-8 ${previewOpen ? 'pointer-events-none opacity-0' : ''}`}>
                    <button
                        onClick={() => setModal('about')}
                        className="text-[10px] font-black uppercase tracking-[0.18em] text-white/80 drop-shadow-[0_2px_8px_rgba(0,0,0,0.85)] transition-colors hover:text-cyan-200 sm:text-[11px] sm:tracking-[0.2em]"
                    >
                        About
                    </button>
                    <button
                        onClick={() => setModal('rules')}
                        className="text-[10px] font-black uppercase tracking-[0.18em] text-white/80 drop-shadow-[0_2px_8px_rgba(0,0,0,0.85)] transition-colors hover:text-cyan-200 sm:text-[11px] sm:tracking-[0.2em]"
                    >
                        Rules
                    </button>
                    <button
                        onClick={() => setModal('contact')}
                        className="text-[10px] font-black uppercase tracking-[0.18em] text-white/80 drop-shadow-[0_2px_8px_rgba(0,0,0,0.85)] transition-colors hover:text-cyan-200 sm:text-[11px] sm:tracking-[0.2em]"
                    >
                        Contact
                    </button>
                    <button
                        onClick={() => setModal('privacy')}
                        className="text-[10px] font-black uppercase tracking-[0.18em] text-white/80 drop-shadow-[0_2px_8px_rgba(0,0,0,0.85)] transition-colors hover:text-cyan-200 sm:text-[11px] sm:tracking-[0.2em]"
                    >
                        Privacy
                    </button>
                </nav>


                <div className="relative z-10 flex flex-col items-center w-full max-w-sm">

                    <div className="w-full mb-8">
                        <AnimatePresence mode="wait">
                            {error && (
                                <motion.div
                                    initial={{ opacity: 0, y: -10 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    exit={{ opacity: 0, y: -10 }}
                                    className="p-4 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-sm flex items-center gap-3"
                                >
                                    <Warning className="w-5 h-5 shrink-0" weight="fill" />
                                    <p>{error}</p>
                                </motion.div>
                            )}
                            {success && (
                                <motion.div
                                    initial={{ opacity: 0, y: -10 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    exit={{ opacity: 0, y: -10 }}
                                    className="p-4 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm flex items-center gap-3"
                                >
                                    <CheckCircle className="w-5 h-5 shrink-0" weight="fill" />
                                    <p>{success}</p>
                                </motion.div>
                            )}
                        </AnimatePresence>
                    </div>
                </div>


                <motion.div
                    initial={{ y: -30, opacity: 0 }}
                    animate={{ y: 0, opacity: 1 }}
                    transition={{ duration: 0.8, ease: "easeOut" }}
                    className="relative z-10 text-center mb-2 sm:mb-4"
                >


                    <div className="mb-4 sm:mb-5 inline-flex items-center gap-2.5 drop-shadow-[0_3px_14px_rgba(0,0,0,0.95)]">
                        <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
                        <span className="text-base sm:text-sm lg:text-white font-black tracking-[0.10em] font-mono tabular-nums text-cyan-300">
                            {serverTimeDisplay}
                        </span>
                    </div>

                    <h1 className="text-6xl sm:text-7xl lg:text-8xl font-extrabold text-white tracking-tight drop-shadow-[0_4px_12px_rgba(0,0,0,0.8)]">
                        THE<span className="text-cyan-500">DIRECTOR</span>
                    </h1>
                    <p className="text-cyan-400 text-sm sm:text-base font-black tracking-[0.4em] mt-1 uppercase drop-shadow-[0_3px_14px_rgba(0,0,0,0.95)]">Greed is Good.</p>
                </motion.div>


                <div className="relative z-10 flex flex-col items-center w-full max-w-sm">
                    <motion.a
                        href="/auth/google"
                        onClick={handleGoogleLogin}
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        transition={{ delay: 0.4 }}
                        whileHover={{ scale: 1.02, backgroundColor: "rgba(6, 182, 212, 0.1)" }}
                        whileTap={{ scale: 0.98 }}
                        className={`w-auto mt-5 bg-transparent hover:bg-cyan-500/5 text-white font-bold py-3.5 px-9 rounded-full border border-white/20 hover:border-cyan-500/50 shadow-lg shadow-cyan-900/10 transition-all flex items-center justify-center gap-3 group backdrop-blur-[2px] ${isLoading ? 'opacity-50 cursor-not-allowed pointer-events-none' : ''}`}
                    >
                        {isLoading ? (
                            <div className="flex items-center gap-2.5">
                                <div className="w-4 h-4 border-2 border-cyan-500 border-t-transparent rounded-full animate-spin" />
                                <span className="tracking-[0.15em] uppercase text-sm">Connecting...</span>
                            </div>
                        ) : (
                            <>
                                <svg className="w-6 h-6 filter drop-shadow-[0_0_8px_rgba(34,211,238,0.4)]" viewBox="0 0 24 24">
                                    <path fill="#22d3ee" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                                    <path fill="#22d3ee" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                                    <path fill="#22d3ee" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
                                    <path fill="#22d3ee" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
                                </svg>
                                <span className="tracking-[0.15em] uppercase text-sm">Sign in with Google</span>
                            </>
                        )}
                    </motion.a>

                </div>


                <div className="mt-20 text-center relative z-20">
                    <p className="text-white/60 text-[10px] uppercase tracking-[0.2em] font-bold leading-[2] drop-shadow-[0_2px_8px_rgba(0,0,0,0.85)]">

                        <br />
                        &copy; 2026 THE DIRECTOR. BETA GAMEPLAY.
                    </p>
                </div>




                <AnimatePresence>
                    {modal && (
                        <motion.div
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            exit={{ opacity: 0 }}
                            className="fixed inset-0 z-50 flex items-center justify-center p-4 text-left"
                        >
                            <div className="absolute inset-0 bg-transparent" onClick={() => setModal(null)} />
                            <motion.div
                                initial={{ scale: 0.95, opacity: 0 }}
                                animate={{ scale: 1, opacity: 1 }}
                                exit={{ scale: 0.95, opacity: 0 }}
                                className="relative bg-slate-900 border border-slate-700 max-w-2xl w-full rounded shadow-2xl overflow-hidden"
                            >
                                <div className="flex items-center justify-between p-6 border-b border-slate-800">
                                    <h2 className="text-white font-bold text-lg sm:text-xl uppercase tracking-wider">
                                        {modal === 'about' ? 'About TheDirector' : modal === 'rules' ? 'Game Rules' : modal === 'contact' ? 'Contact Support' : 'Privacy Policy'}
                                    </h2>
                                    <button onClick={() => setModal(null)} className="text-slate-400 hover:text-white transition">
                                        <X className="w-6 h-6" />
                                    </button>
                                </div>
                                <div className="p-8 max-h-[70vh] overflow-auto">
                                    {modal === 'about' && (
                                        <div className="text-slate-300 space-y-3">
                                            <div className="flex items-center gap-3 mb-5">

                                                <div>
                                                    <h3 className="text-xl font-bold text-white">Welcome to TheDirector</h3>
                                                </div>
                                            </div>

                                            <p className="text-sm text-slate-400 leading-relaxed pb-1">
                                                A spiritual successor to PBBG games like Injustice, The Director is
                                                an in-Beta strategic game where Corporate rules, political governance, and player choice determine whether you survive or Thrive.
                                            </p>

                                            {[
                                                {
                                                    title: 'Player-Driven System',
                                                    body: 'Make a grab for power and wealth, control swaths of businesses, or stop the oligarchs in their tracks.',
                                                },
                                                {
                                                    title: 'Rule or be Ruled',
                                                    body: 'Run for office, form alliances, and game the system.',
                                                },
                                            ].map(({ title, body }) => (
                                                <div key={title} className="flex gap-4 p-4 rounded bg-slate-800/40 border border-slate-700/40">
                                                    <span className="mt-1 shrink-0 w-1.5 h-1.5 rounded-full bg-cyan-500/70" />
                                                    <div className="min-w-0">
                                                        <p className="font-semibold text-white text-sm mb-1.5">{title}</p>
                                                        <p className="text-sm text-slate-400">{body}</p>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}


                                    {modal === 'rules' && (
                                        <div className="text-slate-300 space-y-3">
                                            <div className="flex items-center gap-3 mb-5">

                                                <div>
                                                    <h3 className="text-xl font-bold text-white">Game Rules & Guidelines</h3>
                                                    <p className="text-xs text-slate-500 mt-0.5 uppercase tracking-widest">BETA Gameplay</p>
                                                </div>
                                            </div>

                                            {[
                                                {
                                                    n: '01',
                                                    title: 'Game Integrity',
                                                    points: [
                                                        'Only one account is allowed per player.',
                                                        'Attempting to use multiple accounts, sharing accounts or bypassing restrictions on this in any way will result in a permanent ban.',
                                                        'Scripting, botting, or any form of automation is prohibited.',
                                                        'Exploiting bugs or intentionally abusing  mechanics will result in swift action.',
                                                    ],
                                                },
                                                {
                                                    n: '02',
                                                    title: 'Respectful Community',
                                                    points: [
                                                        'Keep roleplay civil and respectful. Harassment, discrimination, or hate speech will not be tolerated.',
                                                        'TheDirector allows users to communicate in game  and carry out a variety of less than legal actions  in a verisimilitudinous environment, but please be mindful of yourself and your words.',
                                                        'There are situations where player actions pose a serious and unmistakable threat to the flourishing and safety of the game and its community, while not being explicitly against any of the rules here, for those cases admins retain emergency discretionary power to hand out punishment without explicit rule breaking.'
                                                    ],
                                                },

                                                {
                                                    n: '03',
                                                    title: 'Legal & Content Boundaries',
                                                    points: [
                                                        'No pornographic, illegal, or objectionable material may be shared in any form.',
                                                        'Absolutely no real world illegal activity is endorsed by the game or its creator.',
                                                        'I have tried to avoid copyright infringement wherever possible',
                                                    ],
                                                },
                                            ].map(({ n, title, points }) => (
                                                <div key={n} className="flex gap-4 p-4 rounded bg-slate-800/40 border border-slate-700/40">
                                                    <span className="shrink-0 text-2xl font-black text-slate-600 leading-none pt-0.5 select-none">{n}</span>
                                                    <div className="min-w-0">
                                                        <p className="font-semibold text-white text-sm mb-2">{title}</p>
                                                        <ul className="space-y-1">
                                                            {points.map((pt, i) => (
                                                                <li key={i} className="flex items-start gap-2 text-sm text-slate-400">
                                                                    <span className="mt-1.5 shrink-0 w-1 h-1 rounded-full bg-amber-500/70" />
                                                                    {pt}
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                </div>
                                            ))}

                                            <p className="text-xs text-slate-400 pt-2 text-center">
                                                FAQ & more will be available  in game.
                                            </p>
                                        </div>
                                    )}

                                    {modal === 'contact' && (
                                        <div className="text-slate-300 space-y-3">
                                            <div className="flex items-center gap-3 mb-5">
                                                <div className="p-3 rounded bg-blue-500/20">
                                                    <CheckCircle className="h-7 w-7 text-blue-400" weight="bold" />
                                                </div>
                                                <div>
                                                    <h3 className="text-xl font-bold text-white">Contact & Support</h3>
                                                </div>
                                            </div>

                                            <p className="text-sm text-slate-400 leading-relaxed pb-1">
                                                Need help or want to report a bug? Join our Discord or contact us directly.
                                            </p>

                                            {[
                                                {
                                                    title: 'Discord ',
                                                    body: 'https://discord.gg/esGpNynbER',
                                                },
                                                {
                                                    title: 'Email Support',
                                                    body: 'For account issues and business inquiries: admin@thedirector.app',
                                                },
                                            ].map(({ title, body }) => (
                                                <div key={title} className="flex gap-4 p-4 rounded bg-slate-800/40 border border-slate-700/40">
                                                    <span className="mt-1 shrink-0 w-1.5 h-1.5 rounded-full bg-blue-500/70" />
                                                    <div className="min-w-0">
                                                        <p className="font-semibold text-white text-sm mb-1.5">{title}</p>
                                                        <p className="text-sm text-slate-400">{body}</p>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}

                                    {modal === 'privacy' && (
                                        <div className="text-slate-300 space-y-3">
                                            <div className="flex items-center gap-3 mb-5">
                                                <div>
                                                    <h3 className="text-xl font-bold text-white">Privacy Policy</h3>
                                                    <p className="text-xs text-slate-500 mt-0.5 uppercase tracking-widest">Last updated June 2026</p>
                                                </div>
                                            </div>

                                            <p className="text-sm text-slate-400 leading-relaxed pb-1">
                                                TheDirector is a free browser game that collects data necessary to provide and enhance our services. The privacy policy  below describes which data and for what reasons as well as your rights.
                                            </p>

                                            {[
                                                {
                                                    n: '01',
                                                    title: 'What We Collect & Why',
                                                    points: [
                                                        'When you sign up/sign in with Google Oauth we collect your email address and access tokens necessary to identify you as well as cookies, IP addresses and date.',
                                                        'Gameplay data you create — your character, actions, and messages — is stored to run the game. In-game messages can be read by admins in order to ensure player safety.',
                                                        'We may use your email to send account or game notifications. We never sell your data and any advertising is limited to google adsense. ',
                                                    ],
                                                },


                                                {
                                                    n: '03',
                                                    title: 'Retention & Your Choices',
                                                    points: [
                                                        'Account and gameplay data are kept while your user  exists. You can disable email notifications in the settings page or from the unsubscribe option on any email newsletter.',
                                                        'You can request account deletion by emailing admin@thedirector.app, though records needed to enforce an active ban may be retained.',
                                                    ],
                                                },
                                            ].map(({ n, title, points }) => (
                                                <div key={n} className="flex gap-4 p-4 rounded bg-slate-800/40 border border-slate-700/40">
                                                    <span className="shrink-0 text-2xl font-black text-slate-600 leading-none pt-0.5 select-none">{n}</span>
                                                    <div className="min-w-0">
                                                        <p className="font-semibold text-white text-sm mb-2">{title}</p>
                                                        <ul className="space-y-1">
                                                            {points.map((pt, i) => (
                                                                <li key={i} className="flex items-start gap-2 text-sm text-slate-400">
                                                                    <span className="mt-1.5 shrink-0 w-1 h-1 rounded-full bg-cyan-500/70" />
                                                                    {pt}
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                </div>
                                            ))}

                                            <p className="text-xs text-slate-400 pt-2 text-center">
                                                Questions? Email admin@thedirector.app
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </motion.div>
                        </motion.div>
                    )}
                </AnimatePresence>

                <GamePreviewOverlay
                    open={previewOpen}
                    activeIndex={previewIndex}
                    onBack={() => setPreviewOpen(false)}
                    onNext={nextPreview}
                    onPrevious={previousPreview}
                />
            </div>



        </>
    );
}
