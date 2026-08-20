import { Head, Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { CheckCircle } from '@phosphor-icons/react';

export default function Unsubscribed({ email }: { email: string }) {
    return (
        <>
            <Head title="Unsubscribed" />

            <div className="min-h-screen bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950 text-white flex items-center justify-center px-5 py-12">
                <motion.div
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.3, ease: 'easeOut' }}
                    className="max-w-md w-full text-center"
                >
                    <CheckCircle size={40} weight="duotone" className="text-emerald-400 mx-auto mb-6" />

                    <p className="text-[10px] font-black uppercase tracking-[0.32em] text-emerald-400/80 mb-3">
                        Unsubscribed
                    </p>

                    <h1 className="text-2xl font-light text-white tracking-tight mb-4">
                        You're off the announcement list.
                    </h1>

                    <p className="text-sm text-slate-300 leading-relaxed mb-2">
                        <span className="font-mono text-cyan-400">{email}</span> won't receive any more
                        system announcement emails from The Director.
                    </p>

                    <p className="text-xs text-slate-500 leading-relaxed mb-8">
                        You can still log in and check announcements inside the game any time.
                    </p>

                    <Link
                        href="/dashboard"
                        className="inline-block px-5 py-2 rounded-lg border border-cyan-500/30 bg-cyan-500/10 text-cyan-300 text-[11px] font-black uppercase tracking-[0.22em] hover:bg-cyan-500/15 transition"
                    >
                        Back to the Game
                    </Link>
                </motion.div>
            </div>
        </>
    );
}
