import { useState } from 'react';
import { Head, router } from '@inertiajs/react';

interface DevUser { id: number; email: string; username: string; }
interface Props { users: DevUser[]; }

export default function DevLogin({ users }: Props) {
    const [email, setEmail]       = useState(users[0]?.email ?? '');
    const [password, setPassword] = useState('password');
    const [error, setError]       = useState<string | null>(null);
    const [loading, setLoading]   = useState(false);

    const submit = () => {
        setError(null);
        setLoading(true);
        router.post('/dev-login', { email, password }, {
            onError:  (e) => { setError(e.email ?? 'Login failed.'); setLoading(false); },
            onFinish: ()  => setLoading(false),
        });
    };

    return (
        <>
            <Head title="Dev Login" />
            <div className="min-h-screen bg-slate-950 flex items-center justify-center p-4">
                <div className="w-full max-w-sm bg-slate-900 border border-amber-600/40 rounded-2xl p-8 shadow-2xl space-y-6">

                    <div className="text-center space-y-1">
                        <span className="inline-block text-[9px] font-black uppercase tracking-widest text-amber-400 bg-amber-900/20 border border-amber-700/40 px-2 py-0.5 rounded">
                            Local Dev Only
                        </span>
                        <h1 className="text-xl font-black text-white uppercase tracking-wide mt-2">Dev Login</h1>
                        <p className="text-xs text-slate-500">Bypass OAuth — never available in production.</p>
                    </div>

                    {/* Quick-pick */}
                    {users.length > 0 && (
                        <div className="space-y-1.5">
                            <p className="text-[10px] font-black uppercase tracking-widest text-slate-500">Quick Select</p>
                            <div className="grid grid-cols-1 gap-1.5">
                                {users.map(u => (
                                    <button
                                        key={u.id}
                                        type="button"
                                        onClick={() => setEmail(u.email)}
                                        className={`w-full text-left px-3 py-2 rounded-lg border text-xs font-bold transition-all ${
                                            email === u.email
                                                ? 'bg-cyan-900/30 border-cyan-500/60 text-cyan-300'
                                                : 'bg-slate-800/50 border-slate-700/50 text-slate-400 hover:border-slate-500/70 hover:text-white'
                                        }`}
                                    >
                                        {u.email}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Manual fields */}
                    <div className="space-y-3">
                        <div className="space-y-1">
                            <label className="text-[10px] font-black uppercase tracking-widest text-slate-500">Email</label>
                            <input
                                type="email"
                                value={email}
                                onChange={e => setEmail(e.target.value)}
                                className="w-full bg-slate-800/50 border border-slate-700/60 rounded-lg py-2.5 px-3 text-white text-sm font-medium focus:outline-none focus:border-cyan-500/50 transition-all"
                            />
                        </div>
                        <div className="space-y-1">
                            <label className="text-[10px] font-black uppercase tracking-widest text-slate-500">Password</label>
                            <input
                                type="password"
                                value={password}
                                onChange={e => setPassword(e.target.value)}
                                onKeyDown={e => e.key === 'Enter' && submit()}
                                className="w-full bg-slate-800/50 border border-slate-700/60 rounded-lg py-2.5 px-3 text-white text-sm font-medium focus:outline-none focus:border-cyan-500/50 transition-all"
                            />
                        </div>
                    </div>

                    {error && (
                        <p className="text-xs text-red-400 font-medium bg-red-950/30 border border-red-800/40 rounded-lg px-3 py-2">
                            {error}
                        </p>
                    )}

                    <button
                        onClick={submit}
                        disabled={loading || !email}
                        className="w-full h-11 bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 disabled:cursor-not-allowed text-white font-black text-xs uppercase tracking-widest rounded-xl transition-all"
                    >
                        {loading ? 'Signing in…' : 'Sign In'}
                    </button>
                </div>
            </div>
        </>
    );
}
