import { Head, usePage, router } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { useState, useTransition, useCallback, useRef, useEffect, useMemo } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import {
    Shield, MagnifyingGlass, Fingerprint, UsersThree, Package,
    PaperPlaneTilt, X, Gavel, Plus, Trash,
    NotePencil, Warning, Detective, SignOut, Target,
    CaretLeft, CaretRight, CaretDown, Eye, MapPin, Crosshair,
    CheckCircle, Clock,
} from '@phosphor-icons/react';
import GameLayout from '@/Layouts/GameLayout';
import { getCityImage } from '@/utils/cityImages';

// ─── Constants ────────────────────────────────────────────────────────────────

const PAGE_SIZE = 8;

const SEV = {
    misdemeanor: { label: 'Misdemeanor', dot: 'bg-amber-400', pill: 'bg-amber-500/10 text-amber-400 border-amber-500/20' },
    felony: { label: 'Felony', dot: 'bg-orange-400', pill: 'bg-orange-500/10 text-orange-400 border-orange-500/20' },
    capital: { label: 'Capital', dot: 'bg-red-400', pill: 'bg-red-500/10 text-red-400 border-red-500/20' },
};

const STAT = {
    open: { label: 'Open', pill: 'bg-slate-700/50 text-white border-slate-500/40' },
    investigating: { label: 'Investigating', pill: 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20' },
    referred: { label: 'Referred', pill: 'bg-violet-500/10 text-violet-400 border-violet-500/20' },
    charged: { label: 'Charged', pill: 'bg-amber-500/10 text-amber-400 border-amber-500/20' },
    convicted: { label: 'Convicted', pill: 'bg-orange-500/10 text-orange-400 border-orange-500/20' },
    acquitted: { label: 'Acquitted', pill: 'bg-slate-700/50 text-slate-400 border-slate-600/30' },
    sentenced: { label: 'Sentenced', pill: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' },
    appealed: { label: 'Appealed', pill: 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20' },
    closed: { label: 'Closed', pill: 'bg-slate-900/80 text-slate-600 border-slate-800/30' },
};

function fmtISO(iso) {
    if (!iso) return null;
    const d = new Date(iso);
    if (isNaN(d.getTime())) return String(iso);
    const p = n => String(n).padStart(2, '0');
    return `${p(d.getUTCDate())}/${p(d.getUTCMonth() + 1)}/${String(d.getUTCFullYear()).slice(2)} ${p(d.getUTCHours())}:${p(d.getUTCMinutes())}:${p(d.getUTCSeconds())} UTC`;
}

// ─── Primitives ───────────────────────────────────────────────────────────────

function Spinner() {
    return <span className="w-3 h-3 border-2 border-white/20 border-t-white rounded-full animate-spin inline-block shrink-0" />;
}

function Pill({ children, className = '' }) {
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-widest border ${className}`}>
            {children}
        </span>
    );
}

function SLabel({ icon: Icon, children }) {
    return (
        <div className="flex items-center gap-1.5 mb-2">
            {Icon && <Icon size={11} className="text-slate-600" />}
            <span className="text-[10px] font-black text-slate-500 uppercase tracking-widest">{children}</span>
        </div>
    );
}

// ─── Pagination ───────────────────────────────────────────────────────────────

function Pagination({ total, page, pageSize, onChange }) {
    const pages = Math.ceil(total / pageSize);
    if (pages <= 1) return null;
    const dots = [];
    for (let i = 1; i <= pages; i++) {
        if (Math.abs(i - page) <= 1 || i === 1 || i === pages) dots.push(i);
        else if (dots[dots.length - 1] !== '…') dots.push('…');
    }
    const start = (page - 1) * pageSize + 1;
    const end = Math.min(page * pageSize, total);

    return (
        <div className="flex flex-col items-center gap-3 pt-4 border-t border-white/5">
            <div className="flex items-center justify-center gap-1">
                <button onClick={() => onChange(page - 1)} disabled={page === 1}
                    className="w-7 h-7 flex items-center justify-center rounded-lg border border-slate-700/60
                               text-slate-500 hover:text-white hover:border-slate-600 disabled:opacity-30 disabled:cursor-not-allowed transition-all">
                    <CaretLeft size={11} weight="bold" />
                </button>
                {dots.map((p, i) =>
                    p === '…'
                        ? <span key={`g${i}`} className="w-6 text-center text-[11px] text-slate-700">…</span>
                        : <button key={p} onClick={() => onChange(p)}
                            className={`w-7 h-7 rounded-lg text-[11px] font-black transition-all border ${p === page
                                ? 'bg-cyan-500/20 border-cyan-500/40 text-cyan-300'
                                : 'border-slate-700/60 bg-slate-900/40 text-slate-500 hover:text-white hover:border-slate-600'}`}>{p}</button>
                )}
                <button onClick={() => onChange(page + 1)} disabled={page === pages}
                    className="w-7 h-7 flex items-center justify-center rounded-lg border border-slate-700/60
                               text-slate-500 hover:text-white hover:border-slate-600 disabled:opacity-30 disabled:cursor-not-allowed transition-all">
                    <CaretRight size={11} weight="bold" />
                </button>
            </div>
            <div className="text-[9px] font-black uppercase tracking-widest text-slate-600">
                Showing <span className="text-slate-400">{start}</span> to <span className="text-slate-400">{end}</span> of <span className="text-slate-400">{total}</span>
            </div>
        </div>
    );
}

// ─── Components ───────────────────────────────────────────────────────────────

function TabBar({ tabs, active, onChange }) {
    return (
        <div className="flex border-b border-white/5 overflow-x-auto">
            {tabs.map(t => (
                <button key={t.id} onClick={() => onChange(t.id)}
                    className={`relative px-4 py-3.5 text-[11px] font-black uppercase tracking-widest whitespace-nowrap transition-all
                                flex items-center gap-2 shrink-0 ${active === t.id ? 'text-cyan-400' : 'text-slate-500 hover:text-slate-300'}`}>
                    {t.label}
                    {t.count > 0 && (
                        <span className={`px-1.5 py-0.5 text-[9px] rounded-full font-black border leading-none ${active === t.id
                            ? 'bg-cyan-500/20 text-cyan-300 border-cyan-500/30'
                            : 'bg-slate-800 text-slate-500 border-slate-700/50'}`}>{t.count}</span>
                    )}
                    {active === t.id && (
                        <motion.div layoutId="police-tab"
                            className="absolute bottom-0 left-0 right-0 h-px bg-cyan-400 shadow-[0_0_6px_rgba(34,211,238,0.5)]" />
                    )}
                </button>
            ))}
        </div>
    );
}

function ChainOfCustody({ detective, prosecutor, defense, judge }) {
    const steps = [
        { label: 'Detective', name: detective, cls: 'bg-blue-500/8 border-blue-500/20 text-blue-400' },
        { label: 'Prosecutor', name: prosecutor, cls: 'bg-cyan-500/8 border-cyan-500/20 text-cyan-400' },
        { label: 'Defence', name: defense, cls: 'bg-slate-700/30 border-slate-600/40 text-slate-200' },
        { label: 'Judge', name: judge, cls: 'bg-amber-500/8 border-amber-500/20 text-amber-400' },
    ];
    if (!steps.some(s => s.name)) return null;
    return (
        <div className="grid grid-cols-2 gap-1.5">
            {steps.map(({ label, name, cls }) => (
                <div key={label} className={`rounded-xl border p-2.5 ${name ? cls : 'bg-slate-900/20 border-slate-800/20 text-slate-700'}`}>
                    <div className="text-[9px] font-black uppercase tracking-widest mb-0.5 opacity-50">{label}</div>
                    <div className="text-xs font-bold truncate">{name ?? '—'}</div>
                </div>
            ))}
        </div>
    );
}

function InvestigationLog({ notes }) {
    if (!notes?.length) return (
        <div className="flex items-center gap-2 px-3 py-3 rounded-xl border border-dashed border-slate-800/60 text-slate-700">
            <NotePencil size={12} />
            <span className="text-[11px] font-bold uppercase tracking-widest">No notes yet</span>
        </div>
    );
    return (
        <div className="space-y-2">
            {[...notes].reverse().map((n, i) => {
                const note = typeof n === 'string' ? n : (n?.note ?? '');
                const at = typeof n === 'object' ? n?.at : null;
                return (
                    <div key={i} className="px-4 py-3 bg-slate-950/60 border border-slate-800/50 rounded-xl">
                        <p className="text-sm text-slate-300 leading-relaxed">{note}</p>
                        {at && <p className="text-xs text-slate-700 mt-1.5 font-mono">{fmtISO(at)}</p>}
                    </div>
                );
            })}
        </div>
    );
}

function FieldIntelPanel({ record, onSubmit, pending, myDisplayName }) {
    const [name, setName] = useState('');
    const [error, setError] = useState('');
    const inputRef = useRef(null);
    const intel = record?.field_intel;

    const validate = useCallback((raw) => {
        const v = raw.trim();
        if (v.length < 2) return 'Enter at least 2 characters.';
        if (v.toLowerCase() === (myDisplayName ?? '').toLowerCase().trim())
            return 'You cannot run surveillance on yourself.';
        return '';
    }, [myDisplayName]);

    const handleSubmit = () => {
        const err = validate(name);
        if (err) { setError(err); inputRef.current?.focus(); return; }
        setError('');
        onSubmit(name.trim());
    };

    if (intel?.length > 0) {
        return (
            <div className="rounded-xl border border-cyan-500/15 bg-cyan-950/20 overflow-hidden">
                <div className="px-3 py-2 border-b border-cyan-500/10 flex items-center gap-2">
                    <Eye size={11} weight="fill" className="text-cyan-500" />
                    <span className="text-[10px] font-black text-cyan-500/70 uppercase tracking-widest">Logged Sightings</span>
                </div>
                {intel.map((entry, i) => (
                    <div key={i} className="p-4 space-y-3">
                        <div className="flex items-baseline justify-between gap-2 flex-wrap">
                            <span className="text-sm font-black text-white">{entry?.target_name ?? '—'}</span>
                            {entry?.logged_at && <span className="text-xs font-mono text-slate-600">{fmtISO(entry.logged_at)}</span>}
                        </div>
                        {entry?.items?.length > 0 ? (
                            <div className="flex flex-wrap gap-2">
                                {entry.items.map((item, j) => (
                                    <div key={j} className="flex items-center gap-2 px-3 py-2 rounded-xl bg-slate-900/80 border border-slate-700/60">
                                        {item?.image_url
                                            ? <img src={item.image_url} alt={item?.name ?? ''} className="w-8 h-8 object-contain rounded" />
                                            : <Package size={15} className="text-slate-600" />}
                                        <div>
                                            <div className="text-[11px] font-black text-white leading-none mb-0.5">{item?.name ?? '—'}</div>
                                            <div className="text-[9px] text-slate-500 uppercase tracking-wider">{item?.type ?? ''}</div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-xs text-slate-600 italic">No weapons or armour on hand.</p>
                        )}
                    </div>
                ))}
            </div>
        );
    }

    return (
        <div className="rounded-xl border border-slate-700/40 bg-slate-950/60 p-4 space-y-3">
            <div className="relative">
                <input ref={inputRef} type="text" value={name} onChange={e => { setName(e.target.value); setError(''); }}
                    onKeyDown={e => e.key === 'Enter' && handleSubmit()}
                    placeholder="Suspect Name…"
                    className="w-full pl-3 pr-[5.5rem] py-2.5 bg-slate-900/70 rounded-lg text-sm text-white border border-slate-700/50 focus:border-cyan-500/50 outline-none" />
                <button onClick={handleSubmit} disabled={pending || name.trim().length < 2}
                    className="absolute right-1.5 top-1.5 h-8 px-3 rounded-lg text-[10px] font-black uppercase tracking-widest bg-cyan-600 hover:bg-cyan-500 text-white disabled:opacity-40">
                    {pending ? <Spinner /> : 'Scan'}
                </button>
            </div>
            {error && <p className="text-[10px] text-red-400 font-bold">{error}</p>}
        </div>
    );
}

function ReferPanel({ record, onSubmit, onCancel, pending, myDisplayName }) {
    const [suspects, setSuspects] = useState(['']);
    const victimName = (record?.victim_name ?? '').toLowerCase().trim();
    const selfName = (myDisplayName ?? '').toLowerCase().trim();

    const validate = useCallback((list) => {
        const errs = {};
        list.forEach((s, i) => {
            const v = s.toLowerCase().trim();
            if (!v || v.length < 2) return;
            if (selfName && v === selfName) errs[i] = 'Invalid name.';
            if (victimName && v === victimName) errs[i] = 'Invalid name.';
        });
        return errs;
    }, [selfName, victimName]);

    const submit = () => {
        const errs = validate(suspects);
        if (Object.keys(errs).length) return;
        onSubmit(suspects.filter(s => s.trim().length >= 2));
    };

    return (
        <div className="rounded-xl border border-blue-500/20 bg-blue-950/20 p-4 space-y-3">
            <div className="space-y-1.5">
                {suspects.map((s, i) => (
                    <div key={i} className="flex gap-2">
                        <input type="text" value={s} onChange={e => {
                            const n = [...suspects]; n[i] = e.target.value; setSuspects(n);
                        }} placeholder={`Suspect ${i + 1}…`} className="flex-1 px-3 py-2 bg-slate-950 border border-slate-700/60 rounded-lg text-xs text-white outline-none focus:border-blue-500/50" />
                        {suspects.length > 1 && (
                            <button onClick={() => setSuspects(suspects.filter((_, idx) => idx !== i))} className="text-slate-600 hover:text-red-400"><X size={14} /></button>
                        )}
                    </div>
                ))}
            </div>
            <div className="flex gap-2">
                <button onClick={() => setSuspects([...suspects, ''])} disabled={suspects.length >= 5} className="text-[10px] font-black uppercase text-slate-500 hover:text-slate-300">Add Suspect</button>
                <button onClick={submit} disabled={pending || suspects.every(s => s.trim().length < 2)} className="flex-1 h-9 bg-blue-600 hover:bg-blue-500 rounded-lg text-[10px] font-black uppercase tracking-widest text-white disabled:opacity-40">
                    {pending ? <Spinner /> : 'Refer to Law'}
                </button>
                <button onClick={onCancel} className="text-slate-600 hover:text-white px-2"><X size={14} /></button>
            </div>
        </div>
    );
}

// ─── Row Components ───────────────────────────────────────────────────────────

function PoliceRow({ record, isSelected, onToggle, canTake, taking, onTake }) {
    const sev = SEV[record.severity] ?? SEV.misdemeanor;
    const stat = STAT[record.status] ?? STAT.open;
    const ts = record.committed_at_utc ?? record.referred_at_utc ?? record.sentenced_at_utc;
    const isMyActive = record.is_current_case;

    return (
        <button className={`w-full text-left px-4 py-3 flex items-center gap-3 rounded-xl border transition-all ${isSelected
            ? 'border-cyan-500/50 bg-cyan-950/30 shadow-[inset_0_0_15px_rgba(34,211,238,0.05)]'
            : 'border-slate-800/40 bg-slate-900/20 hover:border-slate-700/40 hover:bg-slate-800/20'}`}
            onClick={onToggle}>

            <span className={`w-2 h-2 rounded-full shrink-0 ${isMyActive ? 'bg-cyan-400 animate-pulse shadow-[0_0_8px_rgba(34,211,238,0.8)]' : sev.dot} opacity-80`} />
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap mb-0.5">
                    <span className={`text-sm font-black leading-tight ${isSelected ? 'text-cyan-400' : 'text-white'}`}>
                        {record.type_label}
                    </span>
                    {isMyActive && <Pill className="bg-cyan-500/20 text-cyan-300 border-cyan-500/30">Active</Pill>}
                    {!isMyActive && record.status && <Pill className={stat.pill}>{stat.label}</Pill>}
                    <Pill className={sev.pill}>{sev.label}</Pill>
                </div>
                <div className="flex items-center gap-2 text-xs text-slate-400 font-mono">
                    <span className={isSelected ? 'text-cyan-400/80' : 'text-slate-300'}>#{String(record.id).padStart(5, '0')}</span>
                    {ts && <><span className="text-slate-600">·</span><span className="text-slate-300">{ts}</span></>}
                </div>
            </div>
            {canTake && !isSelected && !isMyActive && (
                <div className="shrink-0 h-6 px-2 rounded-lg bg-white text-slate-950 text-[9px] font-black uppercase flex items-center gap-1 shadow-xl">
                    <Shield size={10} weight="fill" /> Take
                </div>
            )}
        </button>
    );
}

// ─── Detail Panel ─────────────────────────────────────────────────────────────

function PoliceDetail({ record, myDisplayName, onAction, processingKey }) {
    const [showRefer, setShowRefer] = useState(false);
    const sev = SEV[record.severity] ?? SEV.felony;
    const isInvestigating = record.status === 'investigating';
    const isQueue = record.status === 'open';

    const CLUES = [
        { type: 'witness', label: 'Interview Witnesses' },
        { type: 'stat', label: 'Profile Suspect' },
        { type: 'item', label: 'Tracing Center' },
    ];

    return (
        <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}
            className="rounded-xl border border-slate-700/60 bg-slate-800/20 p-5 space-y-5 relative">

            <div className="flex items-start justify-between gap-4 border-b border-slate-800/50 pb-4">
                <div className="min-w-0">
                    <div className="flex items-center gap-2 flex-wrap mb-1.5">
                        <span className={`w-2 h-2 rounded-full ${sev.dot} opacity-80`} />
                        <span className="text-lg font-black text-white leading-tight truncate">{record.type_label}</span>
                    </div>
                    <div className="flex items-center gap-2 text-xs font-mono">
                        <span className="text-slate-300">Case #{String(record.id).padStart(5, '0')}</span>
                        {record.status && <Pill className={STAT[record.status]?.pill}>{record.status}</Pill>}
                    </div>
                </div>

                <div className="shrink-0 flex flex-col items-end gap-2">
                    {isQueue && (
                        <button onClick={() => onAction('take', record.id)} disabled={!!processingKey}
                            className="px-4 py-2 rounded-lg bg-white hover:bg-cyan-400 text-slate-950
                                       text-[10px] font-black uppercase tracking-widest transition-colors flex items-center gap-2 shadow-lg">
                            {processingKey === 'take' ? <Spinner /> : <><Shield size={12} weight="bold" /> Take Case</>}
                        </button>
                    )}
                    {isInvestigating && (
                        <div className="flex gap-2">
                            <button onClick={() => onAction('abandon')} disabled={!!processingKey}
                                className="px-3 py-1.5 rounded-lg border border-red-500/30 bg-red-950/10 text-red-400
                                           text-[10px] font-black uppercase tracking-widest hover:bg-red-500/20 transition-colors">
                                Abandon
                            </button>
                            {!showRefer && (
                                <button onClick={() => setShowRefer(true)} disabled={!!processingKey}
                                    className="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white
                                               text-[10px] font-black uppercase tracking-widest transition-colors flex items-center gap-2 shadow-lg">
                                    <PaperPlaneTilt size={12} weight="bold" /> Refer
                                </button>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {isInvestigating && showRefer && (
                <ReferPanel record={record} pending={processingKey === 'refer'}
                    onSubmit={names => onAction('refer', names)} onCancel={() => setShowRefer(false)}
                    myDisplayName={myDisplayName} />
            )}

            {isInvestigating && (
                <div className="space-y-4">
                    <div className="grid grid-cols-3 gap-3">
                        {CLUES.map(({ type, label }) => (
                            <button key={type} onClick={() => onAction('investigate', type)} disabled={!!processingKey}
                                className="w-full py-3.5 rounded-xl font-black text-[10px] uppercase tracking-widest text-white flex items-center justify-center gap-2 transition-all hover:bg-white/10 border border-white/20">
                                {processingKey === type ? <Spinner /> : label}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            <div className="grid grid-cols-2 gap-2">
                {record.victim_name && (
                    <div className="bg-slate-950/50 rounded-xl border border-slate-800/40 p-3">
                        <div className="text-[9px] font-black text-slate-600 uppercase mb-1">Victim</div>
                        <div className="text-xs font-bold text-white truncate">{record.victim_name}</div>
                    </div>
                )}
                {record.amount != null && (
                    <div className="bg-slate-950/50 rounded-xl border border-slate-800/40 p-3">
                        <div className="text-[9px] font-black text-slate-600 uppercase mb-1">Amount</div>
                        <div className="text-xs font-bold text-emerald-400 font-mono">${Number(record.amount).toLocaleString()}</div>
                    </div>
                )}
            </div>

            <div>
                <SLabel icon={NotePencil}>Investigation Log</SLabel>
                <InvestigationLog notes={record.investigation_notes} />
            </div>

            {isInvestigating && (
                <div>
                    <SLabel icon={Target}>Field Intelligence</SLabel>
                    <FieldIntelPanel record={record} onSubmit={name => onAction('field-intel', name)}
                        pending={processingKey === 'field-intel'} myDisplayName={myDisplayName} />
                </div>
            )}

            <ChainOfCustody detective={record.detective_name} prosecutor={record.prosecutor_name}
                defense={record.defense_name} judge={record.judge_name} />

            {record.sentence && (record.sentence.fine > 0 || record.sentence.jail_seconds > 0) && (
                <div className="rounded-xl bg-red-500/5 border border-red-500/15 p-4">
                    <SLabel icon={Warning}>Sentence on Record</SLabel>
                    <div className="flex gap-2 flex-wrap mt-2">
                        {record.sentence.fine > 0 && (
                            <div className="bg-slate-900/80 rounded-lg border border-slate-800 px-4 py-3">
                                <div className="text-[9px] font-black text-slate-600 uppercase mb-1">Fine</div>
                                <div className="text-sm font-mono font-black text-white">${record.sentence.fine.toLocaleString()}</div>
                            </div>
                        )}
                        {record.sentence.jail_seconds > 0 && (
                            <div className="bg-slate-900/80 rounded-lg border border-slate-800 px-4 py-3">
                                <div className="text-[9px] font-black text-slate-600 uppercase mb-1">Jail</div>
                                <div className="text-sm font-black text-white">{Math.ceil(record.sentence.jail_seconds / 3600)}h</div>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </motion.div>
    );
}

// ─── Dismiss Panel ────────────────────────────────────────────────────────────

function DismissPanel({ officers, onDismiss, dismissing }) {
    return (
        <div className="space-y-2 max-w-2xl mx-auto">
            {officers.length === 0
                ? <p className="text-center py-10 text-slate-600 text-sm">No officers available to dismiss.</p>
                : officers.map(o => (
                    <div key={o.id} className="flex items-center gap-3 p-3 rounded-xl border border-slate-800/50 bg-slate-900/30">
                        <div className="w-9 h-9 rounded-xl bg-slate-800 border border-slate-700/50 overflow-hidden flex items-center justify-center shrink-0">
                            {o.avatarUrl
                                ? <img src={o.avatarUrl} className="w-full h-full object-cover" alt="" />
                                : <Shield size={15} className="text-slate-600" />}
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className="text-sm font-black text-white truncate">{o.displayName}</p>
                            <p className="text-[10px] text-slate-500 uppercase tracking-widest">{o.rankName}</p>
                        </div>
                        <button onClick={() => onDismiss(o.id)} disabled={!!dismissing}
                            className="h-8 px-3 rounded-lg text-[11px] font-black uppercase tracking-widest border
                                       border-red-500/25 bg-red-950/15 text-red-400/80
                                       hover:bg-red-500/15 hover:text-red-400 hover:border-red-400/40
                                       transition-all disabled:opacity-40">
                            {dismissing === o.id ? <Spinner /> : 'Dismiss'}
                        </button>
                    </div>
                ))
            }
        </div>
    );
}

// ─── Empty State ──────────────────────────────────────────────────────────────

function Empty({ message, sub }) {
    return (
        <div className="flex flex-col items-center justify-center py-16 gap-3 text-slate-700">
            <Shield size={40} weight="duotone" />
            <p className="text-sm font-black uppercase tracking-widest text-center">{message}</p>
            {sub && <p className="text-xs text-slate-600 text-center max-w-[260px]">{sub}</p>}
        </div>
    );
}

// ─── Master Detail Layout Component ───────────────────────────────────────────

function InvestigationsList({
    cases,
    expandedId,
    setExpanded,
    isHistory,
    handleAction,
    processingKey,
    character,
    page,
    setPage
}) {
    const scrollRef = useRef(null);
    const [showArrow, setShowArrow] = useState(false);

    const checkScroll = useCallback(() => {
        if (!scrollRef.current) return;
        const { scrollTop, scrollHeight, clientHeight } = scrollRef.current;
        setShowArrow(scrollHeight > clientHeight + 5 && (scrollTop + clientHeight < scrollHeight - 20));
    }, []);

    const items = useMemo(() => cases.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE), [cases, page]);
    const selectedItem = useMemo(() => cases.find(i => i.id === expandedId) || null, [cases, expandedId]);

    useEffect(() => {
        const el = scrollRef.current;
        if (!el) return;
        checkScroll();
        const obs = new ResizeObserver(checkScroll);
        obs.observe(el);
        el.addEventListener('scroll', checkScroll);
        return () => {
            obs.disconnect();
            el.removeEventListener('scroll', checkScroll);
        };
    }, [selectedItem, checkScroll]);

    if (items.length === 0) return <Empty message="No cases found" />;

    return (
        <div className="flex flex-col lg:flex-row gap-4 lg:gap-6 items-start">
            <div className="w-full lg:w-2/5 flex flex-col gap-2 shrink-0">
                {items.map(r => (
                    <PoliceRow key={r.id} record={r}
                        isSelected={expandedId === r.id}
                        onToggle={() => setExpanded(p => p === r.id ? null : r.id)}
                        canTake={!isHistory && r.is_open}
                        onTake={id => handleAction('take', id)}
                        taking={processingKey === 'take'} />
                ))}
                <Pagination total={cases.length} page={page} pageSize={PAGE_SIZE} onChange={setPage} />
            </div>

            <div className="w-full lg:w-3/5 lg:sticky lg:top-6 lg:max-h-[750px] overflow-hidden flex flex-col group/detail border border-slate-800/40 rounded-xl bg-slate-900/10 min-h-[400px]">
                {selectedItem ? (
                    <div className="flex-1 overflow-hidden relative flex flex-col">
                        <div ref={scrollRef} className="flex-1 overflow-y-auto p-4 lg:p-5 pr-2 lg:pr-3 custom-scrollbar scroll-smooth">
                            <PoliceDetail record={selectedItem} myDisplayName={character.displayName}
                                onAction={handleAction} processingKey={processingKey} />
                        </div>

                        <AnimatePresence>
                            {showArrow && (
                                <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: 10 }}
                                    className="absolute bottom-6 left-1/2 -translate-x-1/2 pointer-events-none">
                                    <div className="bg-slate-950/90 border border-white/10 rounded-full p-2 shadow-2xl backdrop-blur-md animate-bounce">
                                        <CaretDown size={14} className="text-cyan-400" weight="bold" />
                                    </div>
                                </motion.div>
                            )}
                        </AnimatePresence>
                    </div>
                ) : (
                    <div className="hidden lg:flex flex-1 items-center justify-center">
                        <Empty message="Select a Case" sub="Review case details and investigation progress." />
                    </div>
                )}
            </div>
        </div>
    );
}

// ─── Crime Dispatch ────────────────────────────────────────────────────────

// 911-style chatter feed. Cached entries from CrimeService, 15-min expiry,
// current city only. Names leak only ~50% of the time (server-side decision).
// No detail page — just the raw stream.
function CrimeDispatch({ entries, cityName }) {
    if (!entries?.length) {
        return (
            <div className="flex flex-col items-center justify-center py-16 gap-3 text-slate-700">
                <Shield size={40} weight="duotone" />
                <p className="text-sm font-black uppercase tracking-widest text-center">Quiet on the wire</p>
                <p className="text-xs text-slate-600 text-center max-w-[300px]">
                    No active dispatch. Reports show up here as crimes are committed and fade after 15 minutes.
                </p>
            </div>
        );
    }

    return (
        <div className="w-full">

            <div className="space-y-1.5">
                {entries.map((e, i) => {
                    const sev = SEV[e.severity] ?? SEV.felony;
                    const initials = e.name ? e.name.substring(0, 2).toUpperCase() : null;
                    return (
                        <div key={i} className="grid items-center gap-3 px-4 py-2.5 rounded-xl border border-slate-800/40 bg-slate-900/20 w-full" style={{ gridTemplateColumns: '8px 1fr auto 24px' }}>
                            <span className={`w-2 h-2 rounded-full ${sev.dot} opacity-80`} />
                            <span className="text-sm font-black text-white leading-tight truncate">{e.type_label}</span>
                            <span className="text-[11px] font-mono text-slate-300">{e.at_utc}</span>
                            <span className="text-[11px] font-black text-cyan-300/80 text-right">{initials ?? ''}</span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function Police({
    rank_label = 'Officer',
    open_cases = [],
    current_case = null,
    my_cases = [],
    crime_log = [],
    is_commissioner = false,
    dismissable_officers = [],
    can_step_down = false,
}) {
    const { auth } = usePage().props;
    const character = auth?.character ?? {};

    const [tab, setTab] = useState('files');
    const [expandedId, setExpanded] = useState(current_case?.id || (open_cases[0]?.id || null));
    const [processingKey, setKey] = useState(null);
    const [dismissing, setDismissing] = useState(null);
    const [steppingDown, setSteppingDown] = useState(false);

    const [pFiles, setPFiles] = useState(1);
    const [pHistory, setPHistory] = useState(1);

    const doStepDown = () => {
        if (steppingDown || !can_step_down) return;
        setSteppingDown(true);
        router.post(route('career.police.step-down'), {}, {
            onFinish: () => setSteppingDown(false),
        });
    };

    const caseFiles = useMemo(() => {
        const list = [];
        const activeStatuses = ['open', 'investigating'];
        if (current_case) list.push({ ...current_case, is_current_case: true });
        my_cases.forEach(c => {
            if (c.id !== current_case?.id && activeStatuses.includes(c.status)) {
                list.push({ ...c, is_assigned: true });
            }
        });
        open_cases.forEach(c => {
            if (c.id !== current_case?.id && !my_cases.some(m => m.id === c.id) && activeStatuses.includes(c.status)) {
                list.push({ ...c, is_open: true });
            }
        });
        return list;
    }, [current_case, my_cases, open_cases]);

    const caseHistory = useMemo(() => {
        const terminalStatuses = ['referred', 'charged', 'convicted', 'acquitted', 'sentenced', 'appealed', 'closed'];
        const history = my_cases.filter(c => terminalStatuses.includes(c.status));
        return history.sort((a, b) => b.id - a.id);
    }, [my_cases]);

    const tabs = [
        { id: 'files', label: 'Case Files', count: caseFiles.length },
        { id: 'history', label: 'Case History', count: caseHistory.length },
        { id: 'log', label: 'Live Dispatch', count: crime_log.length },
        ...(is_commissioner ? [{ id: 'dismiss', label: 'Dismiss', count: dismissable_officers.length }] : []),
    ];

    const switchTab = id => {
        setTab(id);
        if (id === 'files') setExpanded(current_case?.id || caseFiles[0]?.id || null);
        else if (id === 'history') setExpanded(caseHistory[0]?.id || null);
        else setExpanded(null);
    };

    const handleAction = (action, data = null) => {
        if (processingKey) return;
        setKey(action);
        const id = current_case?.id;
        const post = (url, body, key) => router.post(url, body, {
            preserveScroll: true, preserveState: false,
            onFinish: () => setKey(null),
            onSuccess: () => { if (['abandon', 'refer'].includes(key)) setExpanded(null); }
        });

        switch (action) {
            case 'take': post(route('career.police.take', data), {}, 'take'); break;
            case 'investigate': post(route('career.police.investigate', id), { clue_type: data }, data); break;
            case 'abandon': post(route('career.police.abandon'), {}, 'abandon'); break;
            case 'field-intel': post(route('career.police.field-intel', id), { target_name: data }, 'field-intel'); break;
            case 'refer': post(route('career.police.refer', id), { suspect_names: data }, 'refer'); break;
        }
    };

    const dismissOfficer = id => {
        setDismissing(id);
        router.post(route('career.police.dismiss'), { character_id: id }, {
            preserveScroll: true, preserveState: false,
            onFinish: () => setDismissing(null),
        });
    };

    return (
        <>
            <Head title="Police Department" />

            <div className="w-full max-w-[1200px] mx-auto space-y-4">
                <div className="relative rounded-2xl overflow-hidden border border-white/5 shadow-2xl">
                    <div className="absolute inset-0 bg-slate-950">
                        <img src={getCityImage(character.homeCity ?? character.cityName ?? '')} alt="" className="w-full h-full object-cover opacity-40" />
                        <div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/55 to-transparent" />
                    </div>
                    <div className="relative px-7 pt-9 pb-7">
                        <div className="flex items-center gap-2 mb-3">
                            <MapPin size={12} weight="fill" className="text-cyan-400" />
                            <span className="text-[11px] font-semibold uppercase tracking-[0.2em] text-cyan-400/80">{character.homeCity ?? character.cityName ?? 'PD HQ'}</span>
                        </div>
                        <h1 className="text-3xl font-light text-white tracking-tight mb-1">Police Department</h1>
                        <div className="mt-6 flex flex-wrap gap-7 items-end">
                            <div className="ml-auto">
                                <button onClick={doStepDown} disabled={!can_step_down || steppingDown}
                                    className={`flex items-center gap-1.5 h-8 px-4 rounded-lg text-[11px] font-black uppercase tracking-widest border transition-all ${can_step_down && !steppingDown
                                        ? 'border-amber-500/30 bg-amber-500/8 text-amber-400 hover:bg-amber-500/15'
                                        : 'border-slate-700/30 bg-slate-800/20 text-slate-600 cursor-not-allowed'}`}>
                                    {steppingDown ? <Spinner /> : <><SignOut size={12} weight="bold" /> Step Down</>}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="bg-slate-900/50 border border-white/5 rounded-2xl overflow-hidden min-h-[600px]">
                    <TabBar tabs={tabs} active={tab} onChange={switchTab} />
                    <div className="p-4 lg:p-6">
                        <AnimatePresence mode="wait">
                            {tab === 'files' && (
                                <motion.div key="files" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                                    <InvestigationsList cases={caseFiles} expandedId={expandedId} setExpanded={setExpanded} isHistory={false}
                                        handleAction={handleAction} processingKey={processingKey} character={character} page={pFiles} setPage={setPFiles} />
                                </motion.div>
                            )}

                            {tab === 'history' && (
                                <motion.div key="history" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                                    <InvestigationsList cases={caseHistory} expandedId={expandedId} setExpanded={setExpanded} isHistory={true}
                                        handleAction={handleAction} processingKey={processingKey} character={character} page={pHistory} setPage={setPHistory} />
                                </motion.div>
                            )}

                            {tab === 'log' && (
                                <motion.div key="log" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                                    <CrimeDispatch entries={crime_log} cityName={character.homeCity ?? character.cityName ?? ''} />
                                </motion.div>
                            )}

                            {tab === 'dismiss' && (
                                <motion.div key="dismiss" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                                    <DismissPanel officers={dismissable_officers} onDismiss={dismissOfficer} dismissing={dismissing} />
                                </motion.div>
                            )}
                        </AnimatePresence>
                    </div>
                </div>
            </div>
        </>
    );
}

Police.layout = page => <GameLayout children={page} />;
