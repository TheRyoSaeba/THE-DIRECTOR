import { Head } from '@inertiajs/react';
import { Lock } from '@phosphor-icons/react';

export default function Admined({ temporary = false }) {
    return (
        <div className="min-h-screen bg-slate-950 text-white flex flex-col items-center justify-center p-4">
            <Head title="Banned - TheDirector" />

            <div className="w-full max-w-md bg-slate-900 border border-red-900/50 rounded-lg shadow-2xl p-8 text-center relative overflow-hidden">
                <div className="absolute inset-0 pointer-events-none opacity-10 bg-[radial-gradient(circle_at_center,rgba(220,38,38,0.2),transparent_70%)]" />
                <div className="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-red-900 via-red-600 to-red-900" />

                <div className="relative z-10 flex flex-col items-center gap-6">
                    <div className="w-20 h-20 bg-red-900/20 rounded-full flex items-center justify-center border border-red-500/30 shadow-[0_0_20px_rgba(220,38,38,0.3)]">
                        <Lock size={40} className="text-red-500" />
                    </div>

                    <div>
                        <h1 className="text-3xl font-black uppercase tracking-wider text-red-500 mb-2">
                            Access Denied
                        </h1>
                        <p className="text-slate-400 text-sm font-mono uppercase tracking-widest">
                            You have been banned
                        </p>
                    </div>

                    <div className="w-full bg-slate-950/50 border border-slate-800 p-4 rounded-md text-left">

                        <p className="text-slate-400 text-sm mt-3">
                            {temporary
                                ? 'This is a temporary ban. If you believe it is an error, contact support at admin@thedirector.app'
                                : 'If you believe this is an error, please contact support at admin@thedirector.app'}
                        </p>
                    </div>
                </div>
            </div>

            <div className="mt-8 text-center text-slate-600 text-xs">
                <p>&copy; {new Date().getFullYear()} TheDirector.</p>
                <p className="mt-1">BETA Gameplay.</p>
            </div>
        </div>
    );
}
