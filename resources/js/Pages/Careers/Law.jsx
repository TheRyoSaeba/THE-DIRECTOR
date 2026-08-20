import { Head, usePage, router } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { useState, useId } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import {
    Scales, Gavel, X, Eye, Clock, SealCheck,
    ArrowBendUpRight, ArrowCounterClockwise, CheckCircle,
    MagnifyingGlass, Detective, SignOut, MapPin,
    CaretLeft, CaretRight, CaretDown, NotePencil, Warning,
} from '@phosphor-icons/react';
import GameLayout from '@/Layouts/GameLayout';
import { getCityImage } from '@/utils/cityImages';

// ─── Constants ────────────────────────────────────────────────────────────────

const PAGE_SIZE = 15;

const SEV = {
    misdemeanor: { label: 'Misdemeanor', dot: 'bg-amber-400', pill: 'bg-amber-500/10 text-amber-400 border-amber-500/20' },
    felony: { label: 'Felony', dot: 'bg-orange-400', pill: 'bg-orange-500/10 text-orange-400 border-orange-500/20' },
    capital: { label: 'Capital', dot: 'bg-red-400', pill: 'bg-red-500/10 text-red-400 border-red-500/20' },
};

const STAT = {
    referred: { label: 'Referred', pill: 'bg-sky-500/10 text-sky-400 border-sky-500/20' },
    charged: { label: 'Charged', pill: 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20' },
    convicted: { label: 'Convicted', pill: 'bg-orange-500/10 text-orange-400 border-orange-500/20' },
    acquitted: { label: 'Acquitted', pill: 'bg-slate-700/50 text-slate-400 border-slate-600/30' },
    sentenced: { label: 'Sentenced', pill: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' },
    appealed: { label: 'Appealed', pill: 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20' },
    closed: { label: 'Closed', pill: 'bg-slate-900/80 text-slate-600 border-slate-800/30' },
};

function getCaseActions(record, rank) {
    const canProsecute = rank === 2 && record.status === 'referred' && !record.is_my_prosecutor;
    const hasPendingDefenseOffer = record.defense_offer?.status === 'pending' || record.defense_pending;
    const canDefend = rank === 2 && record.status === 'charged' && !record.has_defense && !hasPendingDefenseOffer && !record.is_my_prosecutor;
    const canExecuteDefense = rank === 2
        && record.status === 'charged'
        && record.is_my_defense
        && record.defense_offer?.is_mine
        && record.defense_offer?.status === 'accepted';
    const canJudge = rank >= 3 && record.status === 'charged' && !record.is_my_prosecutor && !record.is_my_defense;
    const canSentence = rank >= 3 && record.status === 'convicted' && !record.is_my_prosecutor && !record.is_my_defense;
    const hasAction = canProsecute || canDefend || canExecuteDefense || canJudge || canSentence;
    return { canProsecute, canDefend, canExecuteDefense, canJudge, canSentence, hasAction };
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
    return (
        <div className="flex items-center justify-center gap-1 pt-3">
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
    );
}

// ─── Tab Bar ───────────────────────────────────────────

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
                        <motion.div layoutId="law-tab"
                            className="absolute bottom-0 left-0 right-0 h-px bg-cyan-400 shadow-[0_0_6px_rgba(34,211,238,0.5)]" />
                    )}
                </button>
            ))}
        </div>
    );
}

// ─── Chain of Custody ─────────────────────────────────────────────────────────

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

// ─── Inline Sentence Form ─────────────────────────────────────────────────────

function InlineSentenceForm({ onSubmit, pending }) {
    const fineId = useId();
    const jailId = useId();
    const [verdict, setVerdict] = useState('sentence');
    const [fine, setFine] = useState('');
    const [jailHours, setJailHours] = useState('');

    const submit = () => onSubmit({
        verdict,
        fine: parseInt(fine, 10) || 0,
        jail_seconds: (parseFloat(jailHours) || 0) * 3600,
    });

    return (
        <div className="rounded-xl border border-slate-700/50 bg-slate-950/50 overflow-hidden">
            <div className="flex border-b border-slate-800/60">
                {[
                    { v: 'sentence', label: 'Sentence', active: 'bg-cyan-600 text-white', idle: 'text-slate-400' },
                    { v: 'acquit', label: 'Acquit', active: 'bg-emerald-600 text-white', idle: 'text-slate-400' },
                ].map(({ v, label, active, idle }) => (
                    <button key={v} onClick={() => setVerdict(v)}
                        className={`flex-1 py-2.5 text-xs font-black uppercase tracking-widest transition-colors
                                    ${verdict === v ? active : `${idle} hover:text-white hover:bg-slate-800/60`}`}>
                        {label}
                    </button>
                ))}
            </div>
            {verdict === 'sentence' && (
                <>
                    <div className="p-3 grid grid-cols-2 gap-3">
                        <div>
                            <label htmlFor={fineId} className="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Fine ($)</label>
                            <input id={fineId} type="number" min="0" value={fine} onChange={e => setFine(e.target.value)}
                                placeholder="0" className="w-full px-2.5 py-2 bg-slate-950 border border-slate-700/60 rounded-lg text-sm
                                                           text-white placeholder:text-slate-700 focus:border-cyan-500/60 focus:outline-none" />
                        </div>
                        <div>
                            <label htmlFor={jailId} className="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Jail (hrs)</label>
                            <input id={jailId} type="number" min="0" step="0.5" value={jailHours} onChange={e => setJailHours(e.target.value)}
                                placeholder="0" className="w-full px-2.5 py-2 bg-slate-950 border border-slate-700/60 rounded-lg text-sm
                                                           text-white placeholder:text-slate-700 focus:border-cyan-500/60 focus:outline-none" />
                        </div>
                    </div>
                    <div className="px-3 pb-3">
                        <button onClick={submit} disabled={pending}
                            className="w-full py-2.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 disabled:opacity-40 disabled:cursor-not-allowed
                                       text-white text-xs font-black uppercase tracking-widest transition-colors flex items-center justify-center gap-2">
                            {pending ? <Spinner /> : <><Gavel size={12} weight="bold" /> Confirm Sentence</>}
                        </button>
                    </div>
                </>
            )}
            {verdict === 'acquit' && (
                <div className="m-3 rounded-lg border border-emerald-500/20 bg-emerald-500/5 p-3 space-y-3">
                    <p className="text-xs text-slate-500 leading-relaxed">
                        All charges will be dropped and no sentence will be recorded.
                    </p>
                    <button onClick={submit} disabled={pending}
                        className="w-full py-2.5 rounded-lg border border-emerald-500/30 bg-emerald-500/8
                                   hover:bg-emerald-500/15 disabled:opacity-40 disabled:cursor-not-allowed
                                   text-emerald-400 text-xs font-black uppercase tracking-widest
                                   transition-colors flex items-center justify-center gap-2">
                        {pending
                            ? <span className="w-3 h-3 border-2 border-emerald-400/40 border-t-emerald-400 rounded-full animate-spin" />
                            : <><CheckCircle size={12} weight="bold" /> Acquit Defendant</>}
                    </button>
                </div>
            )}
        </div>
    );
}

// ─── Compact Case Row ─────────────────────────────────────────────────────────

function CaseRow({ record, rank, isSelected, onToggle }) {
    const sev = SEV[record.severity] ?? SEV.misdemeanor;
    const stat = STAT[record.status] ?? STAT.closed;
    const ts = record.resolved_at_human ?? record.sentenced_at_human
        ?? record.referred_at_human ?? record.committed_at_utc;

    const { hasAction } = getCaseActions(record, rank);

    return (
        <button className={`w-full text-left px-4 py-3 flex items-center gap-3 rounded-xl border transition-all ${isSelected
            ? 'border-cyan-500/50 bg-cyan-950/30 shadow-[inset_0_0_15px_rgba(34,211,238,0.05)]'
            : 'border-slate-800/40 bg-slate-900/20 hover:border-slate-700/40 hover:bg-slate-800/20'}`}
            onClick={onToggle}>

            <span className={`w-2 h-2 rounded-full shrink-0 ${sev.dot} opacity-80`} />
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap mb-0.5">
                    <span className={`text-sm font-black leading-tight ${isSelected ? 'text-cyan-400' : 'text-white'}`}>
                        {record.type_label}
                    </span>
                    <Pill className={stat.pill}>{stat.label}</Pill>
                    <Pill className={sev.pill}>{sev.label}</Pill>
                    {record.auto_charged && <Pill className="bg-amber-500/10 text-amber-400 border-amber-500/20">Auto</Pill>}
                </div>
                <div className="flex items-center gap-2 text-xs text-slate-600 font-mono flex-wrap">
                    <span className={isSelected ? 'text-cyan-600/70' : ''}>#{String(record.id).padStart(5, '0')}</span>
                    {record.city_name && <><span>·</span><span>{record.city_name}</span></>}
                    {ts && <><span>·</span><span>{ts}</span></>}
                </div>
            </div>
            {hasAction && !isSelected && (
                <span className="shrink-0 w-1.5 h-1.5 rounded-full bg-cyan-400 shadow-[0_0_6px_rgba(34,211,238,0.8)]" />
            )}
        </button>
    );
}

// ─── Case Detail Panel ────────────────────────────────────────────────────────

function CaseDetail({ record, rank, onAction, pending }) {
    const sev = SEV[record.severity] ?? SEV.misdemeanor;
    const { canProsecute, canDefend, canExecuteDefense, canJudge, canSentence, hasAction } = getCaseActions(record, rank);
    const [fee, setFee] = useState('1000');
    const defenseOffer = record.defense_offer;
    const parsedFee = parseInt(String(fee).replace(/[^\d]/g, ''), 10) || 0;

    return (
        <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}
            className="rounded-xl border border-slate-700/60 bg-slate-800/20 p-5 space-y-5 relative">

            <div className="flex items-start justify-between gap-4 border-b border-slate-800/50 pb-4">
                <div className="min-w-0">
                    <div className="flex items-center gap-2 flex-wrap mb-1.5">
                        <span className={`w-2 h-2 rounded-full ${sev.dot} opacity-80`} />
                        <span className="text-lg font-black text-white leading-tight truncate">{record.type_label}</span>
                    </div>
                    <div className="flex items-center gap-2 text-xs text-slate-500 font-mono">
                        <span>Case #{String(record.id).padStart(5, '0')}</span>
                        {record.auto_charged && <Pill className="bg-amber-500/10 text-amber-400 border-amber-500/20">Auto</Pill>}
                    </div>
                </div>

                <div className="shrink-0 flex flex-col items-end gap-2">
                    {canProsecute && (
                        <button onClick={() => onAction('prosecute', record)} disabled={pending}
                            className="px-4 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 disabled:opacity-40 text-white
                                       text-[10px] font-black uppercase tracking-widest transition-colors flex items-center gap-2">
                            {pending ? <Spinner /> : <><Gavel size={12} weight="bold" /> File Charges</>}
                        </button>
                    )}
                    {canDefend && (
                        <div className="flex items-center gap-2">
                            <input
                                type="text"
                                inputMode="numeric"
                                value={fee}
                                onChange={e => setFee(e.target.value)}
                                className="h-9 w-28 rounded-lg border border-slate-700/60 bg-slate-950 px-3 text-xs font-bold text-white placeholder:text-slate-600 focus:border-cyan-500/50 focus:outline-none"
                                placeholder="Fee"
                            />
                            <button onClick={() => onAction('defend', record, { fee: parsedFee })} disabled={pending || parsedFee < 1000}
                                className="h-9 px-4 rounded-lg bg-slate-700 hover:bg-slate-600 disabled:opacity-40 text-white
                                           text-[10px] font-black uppercase tracking-widest transition-colors flex items-center gap-2">
                                {pending ? <Spinner /> : <><Scales size={12} weight="bold" /> Send Offer</>}
                            </button>
                        </div>
                    )}
                    {!canDefend && defenseOffer?.status === 'pending' && (
                        <Pill className="bg-amber-500/10 text-amber-300 border-amber-500/25">
                            Defence offer pending
                        </Pill>
                    )}
                    {canExecuteDefense && (
                        <button onClick={() => onAction('execute-defense', record)} disabled={pending}
                            className="px-4 py-2 rounded-lg bg-cyan-700 hover:bg-cyan-600 disabled:opacity-40 text-white
                                       text-[10px] font-black uppercase tracking-widest transition-colors flex items-center gap-2">
                            {pending ? <Spinner /> : <><Scales size={12} weight="bold" /> Execute Defence</>}
                        </button>
                    )}
                </div>
            </div>

            {/* Sentencing form moved up if available */}
            {(canJudge || canSentence) && (
                <div className="p-4 rounded-xl bg-slate-950/40 border border-cyan-500/10 shadow-inner">
                    <div className="flex items-center gap-2 mb-3 border-b border-white/5 pb-2">
                        <Gavel size={14} className="text-cyan-400" weight="fill" />
                        <span className="text-[11px] font-black text-white uppercase tracking-widest">Judicial Docket</span>
                    </div>
                    <InlineSentenceForm pending={pending}
                        onSubmit={(data) => onAction('sentence', record, data)} />
                </div>
            )}



            {/* Timestamps */}
            {[
                { label: 'Committed', v: record.committed_at_utc },
                { label: 'Referred', v: record.referred_at_utc },
                { label: 'Charged', v: record.charged_at_utc },
                { label: 'Sentenced', v: record.sentenced_at_utc },
            ].filter(r => r.v).length > 0 && (
                    <div className="grid grid-cols-2 gap-2">
                        {[
                            { label: 'Committed', v: record.committed_at_utc },
                            { label: 'Referred', v: record.referred_at_utc },
                            { label: 'Charged', v: record.charged_at_utc },
                            { label: 'Sentenced', v: record.sentenced_at_utc },
                        ].filter(r => r.v).map(({ label, v }) => (
                            <div key={label} className="bg-slate-950/50 rounded-xl border border-slate-800/40 p-3">
                                <div className="text-[10px] font-black text-slate-600 uppercase tracking-widest mb-1">{label}</div>
                                <div className="text-xs font-mono text-slate-400">{v}</div>
                            </div>
                        ))}
                    </div>
                )}

            {/* Suspects */}
            {record.suspect_names?.length > 0 && (
                <div>
                    <SLabel icon={Detective}>Suspects</SLabel>
                    <div className="flex flex-wrap gap-1.5">
                        {record.suspect_names.map((n, i) => (
                            <div key={i} className="flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-900/80 border border-slate-700/50">
                                <span className={`w-1.5 h-1.5 rounded-full ${sev.dot}`} />
                                <span className="text-xs font-bold text-white">{n}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Investigation notes */}
            {record.investigation_notes?.length > 0 && (
                <div>
                    <SLabel icon={NotePencil}>Case Summary</SLabel>
                    <div className="space-y-2">
                        {record.investigation_notes.map((note, i) => (
                            <div key={i} className="px-4 py-3 bg-slate-950/60 border border-slate-800/50 rounded-xl">
                                <p className="text-sm text-slate-300 leading-relaxed">
                                    {typeof note === 'string' ? note : (note.note ?? '')}
                                </p>
                                {note?.at && <p className="text-xs text-slate-700 mt-1.5 font-mono">{note.at}</p>}
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Field intel (read-only) */}
            {record.field_intel?.length > 0 && (
                <div>
                    <SLabel icon={Eye}>Field Intelligence</SLabel>
                    <div className="rounded-xl border border-cyan-500/15 bg-cyan-950/20 overflow-hidden">
                        {record.field_intel.map((entry, i) => (
                            <div key={i} className="p-4 space-y-3">
                                <div className="flex items-baseline justify-between gap-2 flex-wrap">
                                    <span className="text-sm font-black text-white">{entry?.target_name ?? '—'}</span>
                                    {entry?.logged_at && <span className="text-xs font-mono text-slate-600">{entry.logged_at}</span>}
                                </div>
                                {entry?.items?.length > 0 ? (
                                    <div className="flex flex-wrap gap-2">
                                        {entry.items.map((item, j) => (
                                            <div key={j} className="flex items-center gap-2 px-3 py-2 rounded-xl bg-slate-900/80 border border-slate-700/60">
                                                {item?.image_url
                                                    ? <img src={item.image_url} alt={item?.name ?? ''} className="w-8 h-8 object-contain rounded" />
                                                    : <Eye size={16} className="text-slate-600" />}
                                                <div>
                                                    <div className="text-[11px] font-black text-white leading-none mb-0.5">{item?.name ?? '—'}</div>
                                                    <div className="text-[9px] text-slate-500 uppercase tracking-wider">{item?.type ?? ''}</div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-xs text-slate-600 italic">No weapons or armour at time of sighting.</p>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Meta */}
            {(record.victim_name || record.amount != null || record.corporation_name) && (
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
                    {record.corporation_name && (
                        <div className="bg-slate-950/50 rounded-xl border border-slate-800/40 p-3 col-span-2">
                            <div className="text-[9px] font-black text-slate-600 uppercase mb-1">Corporation</div>
                            <div className="text-xs font-bold text-white">{record.corporation_name}</div>
                        </div>
                    )}
                </div>
            )}

            <ChainOfCustody detective={record.detective_name} prosecutor={record.prosecutor_name}
                defense={record.defense_name} judge={record.judge_name} />

            {/* Prior sentence */}
            {record.sentence && (record.sentence.fine > 0 || record.sentence.jail_seconds > 0) && (
                <div className="rounded-xl bg-red-500/5 border border-red-500/15 p-4">
                    <div className="flex items-center gap-1.5 mb-3">
                        <Warning size={12} weight="bold" className="text-red-400" />
                        <span className="text-[10px] font-black text-red-400/70 uppercase tracking-widest">Sentence on Record</span>
                    </div>
                    <div className="flex gap-2 flex-wrap">
                        {record.sentence.fine > 0 && (
                            <div className="bg-slate-900/80 rounded-lg border border-slate-800 px-4 py-3">
                                <div className="text-[9px] font-black text-slate-600 uppercase mb-1">Fine</div>
                                <div className="text-sm font-mono font-black text-white">${Number(record.sentence.fine).toLocaleString()}</div>
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

            {/* Role indicators */}
            {(record.is_my_prosecutor || record.is_my_defense || record.is_my_judge) && (
                <div className="pt-3 border-t border-slate-800/50 space-y-2">
                    {record.is_my_prosecutor && <div className="flex items-center gap-2 text-xs text-cyan-400"><Gavel size={14} weight="bold" /> You are the Prosecutor</div>}
                    {record.is_my_defense && <div className="flex items-center gap-2 text-xs text-cyan-400"><Scales size={14} weight="bold" /> You are the Defence{defenseOffer?.fee ? ` · Fee $${Number(defenseOffer.fee).toLocaleString()}` : ''}</div>}
                    {record.is_my_judge && <div className="flex items-center gap-2 text-xs text-cyan-400"><SealCheck size={14} weight="bold" /> You are the Presiding Judge</div>}
                </div>
            )}
        </motion.div>
    );
}

// ─── Compact Appeal Row ───────────────────────────────────────────────────────

function AppealRow({ record, isSelected, onToggle }) {
    const sev = SEV[record.severity] ?? SEV.felony;

    return (
        <button className={`w-full text-left px-4 py-3 flex items-center gap-3 rounded-xl border transition-all ${isSelected
            ? 'border-cyan-500/50 bg-cyan-950/30 shadow-[inset_0_0_15px_rgba(34,211,238,0.05)]'
            : 'border-slate-800/40 bg-slate-900/20 hover:border-slate-700/40 hover:bg-slate-800/20'}`}
            onClick={onToggle}>

            <span className={`w-2 h-2 rounded-full shrink-0 ${sev.dot} opacity-80`} />
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap mb-0.5">
                    <span className={`text-sm font-black leading-tight ${isSelected ? 'text-cyan-400' : 'text-white'}`}>
                        {record.type_label}
                    </span>
                    <Pill className={sev.pill}>{sev.label}</Pill>
                    <Pill className="bg-yellow-500/10 text-yellow-400 border-yellow-500/20">Appealed</Pill>
                </div>
                <div className="text-xs text-slate-600 font-mono">
                    <span className={isSelected ? 'text-cyan-600/70' : ''}>#{String(record.id).padStart(5, '0')}</span>
                    {record.city_name && ` · ${record.city_name}`}
                </div>
            </div>
            {!isSelected && <span className="shrink-0 w-1.5 h-1.5 rounded-full bg-yellow-400 shadow-[0_0_6px_rgba(250,204,21,0.7)]" />}
        </button>
    );
}

// ─── Appeal Detail Panel ──────────────────────────────────────────────────────

function AppealDetail({ record }) {
    const [resolving, setResolving] = useState(null);
    const pending = resolving !== null;
    const sev = SEV[record.severity] ?? SEV.felony;

    const resolve = (upheld) => {
        if (pending) return;
        setResolving(upheld ? 'uphold' : 'overturn');
        router.post(route('career.law.resolve-appeal', record.id), { upheld }, {
            preserveScroll: true,
            preserveState: false,
            onFinish: () => setResolving(null),
        });
    };

    return (
        <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}
            className="rounded-xl border border-slate-700/60 bg-slate-800/20 p-5 space-y-5">

            <div className="flex items-start justify-between gap-4 border-b border-slate-800/50 pb-4">
                <div className="min-w-0">
                    <div className="flex items-center gap-2 flex-wrap mb-1.5">
                        <span className={`w-2 h-2 rounded-full ${sev.dot} opacity-80`} />
                        <span className="text-lg font-black text-white leading-tight truncate">{record.type_label}</span>
                    </div>
                    <div className="text-xs text-slate-500 font-mono">
                        Case #{String(record.id).padStart(5, '0')} · {record.appealed_at_utc}
                    </div>
                </div>

                <div className="shrink-0 flex gap-2">
                    <button onClick={() => resolve(true)} disabled={pending}
                        className="px-3 py-1.5 rounded-lg border border-cyan-500/30 bg-cyan-500/10 text-cyan-400
                                   text-[10px] font-black uppercase tracking-widest hover:bg-cyan-500/20 disabled:opacity-40 transition-colors">
                        {resolving === 'uphold' ? <Spinner /> : 'Uphold'}
                    </button>
                    <button onClick={() => resolve(false)} disabled={pending}
                        className="px-3 py-1.5 rounded-lg border border-emerald-500/30 bg-emerald-500/10 text-emerald-400
                                   text-[10px] font-black uppercase tracking-widest hover:bg-emerald-500/20 disabled:opacity-40 transition-colors">
                        {resolving === 'overturn' ? <Spinner /> : 'Overturn'}
                    </button>
                </div>
            </div>


            <div className="rounded-xl bg-yellow-500/5 border border-yellow-500/15 p-4">
                <p className="text-sm text-slate-400 leading-relaxed">
                    Defendant filed an appeal on this {record.severity} conviction. Rule on whether the original verdict was sound.
                </p>
            </div>

            {record.suspect_names?.length > 0 && (
                <div>
                    <SLabel icon={Detective}>Suspects</SLabel>
                    <div className="flex flex-wrap gap-1.5">
                        {record.suspect_names.map((n, i) => (
                            <div key={i} className="flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-900/80 border border-slate-700/50">
                                <span className={`w-1.5 h-1.5 rounded-full ${sev.dot}`} />
                                <span className="text-xs font-bold text-white">{n}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            <ChainOfCustody detective={record.detective_name} prosecutor={record.prosecutor_name}
                defense={record.defense_name} judge={record.judge_name} />

            {record.sentence && (record.sentence.fine > 0 || record.sentence.jail_seconds > 0) && (
                <div className="rounded-xl bg-red-500/5 border border-red-500/15 p-4">
                    <SLabel>Sentence Under Review</SLabel>
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

function DismissPanel({ members, onDismiss, dismissing }) {
    return (
        <div className="space-y-2 max-w-2xl mx-auto">
            {members.length === 0
                ? <p className="text-center py-10 text-slate-600 text-sm">No members available to dismiss.</p>
                : members.map(m => (
                    <div key={m.id} className="flex items-center gap-3 p-3 rounded-xl border border-slate-800/50 bg-slate-900/30">
                        <div className="w-9 h-9 rounded-xl bg-slate-800 border border-slate-700/50 overflow-hidden flex items-center justify-center shrink-0">
                            {m.avatarUrl
                                ? <img src={m.avatarUrl} className="w-full h-full object-cover" alt="" />
                                : <Scales size={15} className="text-slate-600" />}
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className="text-sm font-black text-white truncate">{m.displayName}</p>
                            <p className="text-[10px] text-slate-500 uppercase tracking-widest">{m.rankName}</p>
                        </div>
                        <button onClick={() => onDismiss(m.id)} disabled={!!dismissing}
                            className="h-8 px-3 rounded-lg text-[11px] font-black uppercase tracking-widest border
                                       border-red-500/25 bg-red-950/15 text-red-400/80
                                       hover:bg-red-500/15 hover:text-red-400 hover:border-red-400/40
                                       transition-all disabled:opacity-40">
                            {dismissing === m.id ? <Spinner /> : 'Dismiss'}
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
            <Scales size={40} weight="duotone" />
            <p className="text-sm font-black uppercase tracking-widest text-center">{message}</p>
            {sub && <p className="text-xs text-slate-600 text-center max-w-[260px]">{sub}</p>}
        </div>
    );
}

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function Law({
    rank = 1,
    rank_label = 'Law Clerk',
    referred_cases = [],
    charged_cases = [],
    convicted_cases = [],
    appealed_cases = [],
    my_cases = [],
    is_chief_justice = false,
    dismissable_members = [],
    can_step_down = false,
    step_down_blocker = null,
}) {
    const { auth } = usePage().props;
    const character = auth?.character ?? {};

    const defaultTab = () => {
        if (rank >= 4 && appealed_cases.length > 0) return 'appeals';
        if (rank >= 3) return 'judge';
        if (rank === 2) return 'prosecution';
        return 'mine';
    };

    const [tab, setTab] = useState(defaultTab);
    const [expandedId, setExpanded] = useState(null);
    const [pending, setPending] = useState(false);
    const [dismissing, setDismissing] = useState(null);
    const [steppingDown, setSteppingDown] = useState(false);

    // Pagination state per tab
    const [pPros, setPPros] = useState(1);
    const [pDef, setPDef] = useState(1);
    const [pJudge, setPJudge] = useState(1);
    const [pAppeals, setPAppeals] = useState(1);
    const [pMine, setPMine] = useState(1);

    const doStepDown = () => {
        if (steppingDown || !can_step_down) return;
        setSteppingDown(true);
        router.post(route('career.law.step-down'), {}, {
            onFinish: () => { setSteppingDown(false); },
        });
    };

    const tabs = [
        ...(rank === 2 ? [{ id: 'prosecution', label: 'Prosecution', count: referred_cases.length }] : []),
        ...(rank === 2 ? [{ id: 'defence', label: 'Defence', count: charged_cases.length }] : []),
        ...(rank >= 3 ? [{ id: 'judge', label: 'Sentencing', count: convicted_cases.length }] : []),
        ...(rank >= 4 ? [{ id: 'appeals', label: 'Appeals', count: appealed_cases.length }] : []),
        { id: 'mine', label: 'My Cases', count: my_cases.length },
        ...(is_chief_justice ? [{ id: 'dismiss', label: 'Dismiss', count: dismissable_members.length }] : []),
    ];

    const switchTab = id => { setTab(id); setExpanded(null); };

    const post = (url, data, onDone) => {
        if (pending) return;
        setPending(true);
        router.post(url, data, {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => { onDone?.(); setExpanded(null); },
            onFinish: () => setPending(false),
        });
    };

    const handleAction = (action, record, extraData = null) => {
        switch (action) {
            case 'prosecute': post(route('career.law.prosecute', record.id), {}); break;
            case 'defend': post(route('career.law.defend', record.id), extraData ?? {}); break;
            case 'execute-defense':
                if (record.defense_offer?.id) {
                    post(route('career.law.defense.execute', record.defense_offer.id), {});
                }
                break;
            case 'sentence':
                if (record.status === 'charged') {
                    post(route('career.law.judge-verdict', record.id), {
                        ...extraData,
                        verdict: extraData.verdict === 'sentence' ? 'convict' : extraData.verdict,
                    });
                } else {
                    post(route('career.law.judge-sentence', record.id), extraData);
                }
                break;
        }
    };

    const dismissMember = id => {
        setDismissing(id);
        router.post(route('career.law.dismiss'), { character_id: id }, {
            preserveScroll: true, preserveState: false,
            onFinish: () => setDismissing(null),
        });
    };

    // Paginated slices
    const slice = (arr, page) => arr.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

    const renderCaseList = (cases, page, setPage) => {
        const items = slice(cases, page);
        if (items.length === 0) return <Empty message="No cases in this queue" />;
        const selectedItem = items.find(i => i.id === expandedId) || null;

        return (
            <div className="flex flex-col lg:flex-row gap-4 lg:gap-6 items-start">
                {/* Left Column: List */}
                <div className="w-full lg:w-2/5 flex flex-col gap-2 shrink-0">
                    {items.map(r => (
                        <CaseRow key={r.id} record={r} rank={rank}
                            isSelected={expandedId === r.id}
                            onToggle={() => setExpanded(p => p === r.id ? null : r.id)} />
                    ))}
                    <Pagination total={cases.length} page={page} pageSize={PAGE_SIZE} onChange={p => { setPage(p); setExpanded(null); }} />
                </div>

                {/* Right Column: Detail Panel with fixed height and scroll */}
                <div className="w-full lg:w-3/5 lg:sticky lg:top-6 lg:max-h-[750px] overflow-hidden flex flex-col group/detail">
                    {selectedItem ? (
                        <div className="flex-1 overflow-y-auto pr-1 custom-scrollbar scroll-smooth">
                            <CaseDetail record={selectedItem} rank={rank} onAction={handleAction} pending={pending} />

                            {/* Scroll Indicator Icon */}
                            <div className="absolute bottom-4 left-1/2 -translate-x-1/2 pointer-events-none animate-bounce opacity-0 group-hover/detail:opacity-100 transition-opacity duration-500 lg:block hidden">
                                <div className="bg-slate-950/80 border border-white/10 rounded-full p-1.5 shadow-2xl backdrop-blur-md">
                                    <CaretDown size={14} className="text-cyan-400" weight="bold" />
                                </div>
                            </div>
                        </div>
                    ) : (

                        <div className="hidden lg:flex flex-1 items-center justify-center border border-dashed border-slate-800/50 rounded-xl bg-slate-900/10 min-h-[400px]">
                            <Empty message="Select a Case" sub="Choose a case from the list to view its details and take action." />
                        </div>
                    )}
                </div>
            </div>
        );
    };

    const renderAppealList = (cases, page, setPage) => {
        const items = slice(cases, page);
        if (items.length === 0) return <Empty message="No appeals pending" />;
        const selectedItem = items.find(i => i.id === expandedId) || null;

        return (
            <div className="flex flex-col lg:flex-row gap-4 lg:gap-6 items-start">
                <div className="w-full lg:w-2/5 flex flex-col gap-2 shrink-0">
                    {items.map(r => (
                        <AppealRow key={r.id} record={r}
                            isSelected={expandedId === r.id}
                            onToggle={() => setExpanded(p => p === r.id ? null : r.id)} />
                    ))}
                    <Pagination total={cases.length} page={page} pageSize={PAGE_SIZE} onChange={p => { setPage(p); setExpanded(null); }} />
                </div>

                <div className="w-full lg:w-3/5 lg:sticky lg:top-6 lg:max-h-[750px] overflow-hidden flex flex-col group/detail">
                    {selectedItem ? (
                        <div className="flex-1 overflow-y-auto pr-1 custom-scrollbar scroll-smooth">
                            <AppealDetail record={selectedItem} />

                            {/* Scroll Indicator Icon */}
                            <div className="absolute bottom-4 left-1/2 -translate-x-1/2 pointer-events-none animate-bounce opacity-0 group-hover/detail:opacity-100 transition-opacity duration-500 lg:block hidden">
                                <div className="bg-slate-950/80 border border-white/10 rounded-full p-1.5 shadow-2xl backdrop-blur-md">
                                    <CaretDown size={14} className="text-yellow-400" weight="bold" />
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="hidden lg:flex flex-1 items-center justify-center border border-dashed border-slate-800/50 rounded-xl bg-slate-900/10 min-h-[400px]">
                            <Empty message="Select an Appeal" sub="Choose a case to review its original conviction and sentence." />
                        </div>
                    )}
                </div>
            </div>
        );
    };

    return (
        <>
            <Head title="The Judiciary" />

            <div className="w-full max-w-[1200px] mx-auto space-y-4">

                {/* ── Hero banner ── */}
                <div className="relative rounded-2xl overflow-hidden border border-white/5 shadow-2xl">
                    <div className="absolute inset-0 bg-slate-950">
                        <img
                            src={getCityImage(character.homeCity ?? character.cityName ?? '')}
                            alt=""
                            className="w-full h-full object-cover"
                            style={{ filter: 'brightness(0.42) contrast(1.12) saturate(1.1)' }}
                        />
                        <div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/55 to-transparent" />
                    </div>
                    <div className="relative px-7 pt-9 pb-7">
                        <div className="flex items-center gap-2 mb-3">
                            <MapPin size={12} weight="fill" className="text-cyan-400" />
                            <span className="text-[11px] font-semibold uppercase tracking-[0.2em] text-cyan-400/80">
                                {character.homeCity ?? character.cityName ?? 'Judiciary'}
                            </span>
                        </div>
                        <h1 className="text-3xl font-light text-white tracking-tight mb-1">The Judiciary</h1>

                        <div className="mt-6 flex flex-wrap gap-7 items-end">
                            <div>
                                <div className="text-[10px] uppercase tracking-widest text-white-500 mb-1">Queue</div>
                                <div className="text-white font-medium text-lg tabular-nums">
                                    {referred_cases.length + charged_cases.length + convicted_cases.length}
                                </div>
                            </div>
                            <div>
                                <div className="text-[10px] uppercase tracking-widest text-white-500 mb-1">My Cases</div>
                                <div className="text-white font-medium tabular-nums">{my_cases.length}</div>
                            </div>
                            {appealed_cases.length > 0 && (
                                <div>
                                    <div className="text-[10px] uppercase tracking-widest text-slate-500 mb-1">Appeals</div>
                                    <div className="text-yellow-400 font-medium tabular-nums">{appealed_cases.length}</div>
                                </div>
                            )}

                            {(
                                <div className="ml-auto">
                                    <button onClick={doStepDown} disabled={!can_step_down || steppingDown}
                                        title={step_down_blocker ?? undefined}
                                        className={`flex items-center gap-1.5 h-8 px-4 rounded-lg text-[11px] font-black
                                                    uppercase tracking-widest border transition-all ${can_step_down && !steppingDown
                                                ? 'border-amber-500/30 bg-amber-500/8 text-amber-400 hover:bg-amber-500/15'
                                                : 'border-slate-700/30 bg-slate-800/20 text-slate-600 cursor-not-allowed'}`}>
                                        {steppingDown ? <Spinner /> : <><SignOut size={12} weight="bold" /> Step Down</>}
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* ── Main panel ── */}
                <div className="bg-slate-900/50 border border-white/5 rounded-2xl overflow-hidden min-h-[600px]">
                    <TabBar tabs={tabs} active={tab} onChange={switchTab} />
                    <div className="p-4 lg:p-6">
                        <AnimatePresence mode="wait">

                            {tab === 'prosecution' && (
                                <motion.div key="prosecution" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                                    {renderCaseList(referred_cases, pPros, setPPros)}
                                </motion.div>
                            )}

                            {tab === 'defence' && (
                                <motion.div key="defence" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                                    {renderCaseList(charged_cases, pDef, setPDef)}
                                </motion.div>
                            )}

                            {tab === 'judge' && (
                                <motion.div key="judge" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                                    {renderCaseList(convicted_cases, pJudge, setPJudge)}
                                </motion.div>
                            )}

                            {tab === 'appeals' && (
                                <motion.div key="appeals" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                                    {renderAppealList(appealed_cases, pAppeals, setPAppeals)}
                                </motion.div>
                            )}

                            {tab === 'mine' && (
                                <motion.div key="mine" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                                    {my_cases.length === 0
                                        ? <Empty message="No cases on your docket"
                                            sub="Cases you prosecute, defend, or sentence appear here." />
                                        : renderCaseList(my_cases, pMine, setPMine)
                                    }
                                </motion.div>
                            )}

                            {tab === 'dismiss' && (
                                <motion.div key="dismiss" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                                    <DismissPanel members={dismissable_members}
                                        onDismiss={dismissMember} dismissing={dismissing} />
                                </motion.div>
                            )}

                        </AnimatePresence>
                    </div>
                </div>
            </div>
        </>
    );
}

Law.layout = page => <GameLayout children={page} />;
