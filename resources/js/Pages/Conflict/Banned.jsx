import { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Warning, Lock, Clock } from '@phosphor-icons/react';

import { formatUTC } from '@/Layouts/GameLayoutComponents';

function useCountdown(target) {
    const [remaining, setRemaining] = useState(() =>
        target ? Math.max(0, new Date(target).getTime() - Date.now()) : 0
    );

    useEffect(() => {
        if (!target) return;
        const tick = () => setRemaining(Math.max(0, new Date(target).getTime() - Date.now()));
        tick();
        const id = setInterval(tick, 1000);
        return () => clearInterval(id);
    }, [target]);

    if (!target || remaining <= 0) return null;

    const days = Math.floor(remaining / 86400000);
    const hours = Math.floor((remaining % 86400000) / 3600000);
    const minutes = Math.floor((remaining % 3600000) / 60000);

    const parts = [];
    if (days) parts.push(`${days}d`);
    if (hours || days) parts.push(`${hours}h`);
    parts.push(`${minutes}m`);
    return parts.join(' ');
}

export default function Banned({ ban_reason, banned_at, banned_until }) {
    const bannedDate = formatUTC(banned_at);
    const isTemporary = Boolean(banned_until);
    const countdown = useCountdown(banned_until);

    useEffect(() => {
        window.history.pushState(null, '', window.location.href);
        const trapBack = () => {
            window.history.pushState(null, '', window.location.href);
        };
        const handlePageShow = (e) => {
            if (e.persisted) router.get(window.location.href, {}, { preserveState: false });
        };
        window.addEventListener('popstate', trapBack);
        window.addEventListener('pageshow', handlePageShow);
        return () => {
            window.removeEventListener('popstate', trapBack);
            window.removeEventListener('pageshow', handlePageShow);
        };
    }, []);

    return (
        <div className="min-h-screen bg-slate-950 text-white flex flex-col items-center justify-center p-4">
            <Head title="Banned - TheDirector" />

            <div className="w-full max-w-md bg-slate-900 border border-red-900/50 rounded-lg shadow-2xl p-8 text-center relative overflow-hidden">
                { }
                <div className="absolute inset-0 pointer-events-none opacity-10 bg-[radial-gradient(circle_at_center,rgba(220,38,38,0.2),transparent_70%)]" />
                <div className="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-red-900 via-red-600 to-red-900" />

                <div className="relative z-10 flex flex-col items-center gap-6">
                    <div className="w-20 h-20 bg-red-900/20 rounded-full flex items-center justify-center border border-red-500/30 shadow-[0_0_20px_rgba(220,38,38,0.3)]">
                        <Lock size={40} className="text-red-500" />
                    </div>

                    <div>
                        <h1 className="text-3xl font-black uppercase tracking-wider text-red-500 mb-2">
                            {isTemporary ? 'You\'ve been Banned' : "You've been Banned"}
                        </h1>
                        <p className="text-slate-400 text-sm font-mono uppercase tracking-widest">
                            If you think this is a mistake, message @admin@thedirector.app
                        </p>
                    </div>

                    <div className="w-full bg-slate-950/50 border border-slate-800 p-4 rounded-md text-left">
                        <p className="text-xs text-slate-500 uppercase tracking-wider mb-2 font-bold">Ban Reason</p>
                        <p className="text-slate-300 font-medium leading-relaxed">
                            {ban_reason}
                        </p>
                        <div className="mt-4 pt-4 border-t border-slate-800 flex justify-between text-xs text-slate-500">
                            <span>Date:</span>
                            <span className="font-mono text-slate-400">{bannedDate}</span>
                        </div>
                        {isTemporary && (
                            <div className="mt-2 pt-2 border-t border-slate-800 flex justify-between text-xs text-slate-500">
                                <span>Lifts:</span>
                                <span className="font-mono text-slate-400">{formatUTC(banned_until)}</span>
                            </div>
                        )}
                    </div>

                    <div className="pt-4 w-full">
                        <Link
                            href={route('logout')}
                            method="post"
                            as="button"
                            className="w-full py-3 bg-slate-800 hover:bg-slate-700 text-slate-200 uppercase tracking-widest text-xs font-bold rounded transition-colors border border-slate-700 hover:border-slate-500"
                        >
                            Log Out
                        </Link>
                    </div>
                </div>
            </div>

            <div className="mt-8 text-center text-slate-600 text-xs">
                <p>&copy; {new Date().getFullYear()} TheDirector.</p>
                <p className="mt-1"> BETA Gameplay.</p>
            </div>
        </div>
    );
}
