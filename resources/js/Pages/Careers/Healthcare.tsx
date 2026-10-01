import { useState, useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
// @ts-ignore
import { route } from 'ziggy-js';
import {
    Heartbeat, Stethoscope, GenderIntersex, UserMinus,
    User, Crown, SignOut, Warning,
} from '@phosphor-icons/react';
import { motion, AnimatePresence } from 'framer-motion';
import GameLayout from '@/Layouts/GameLayout';

//TODO ADD PROFILE PICTURE TO WARD FIX TIME LEFT
// ─────────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────────

interface Patient {
    id: string;
    name: string;
    avatar_url: string | null;
    remaining_seconds: number;
    health: number;
    max_health: number;
    time_left: number;
}

interface QueueEntry {
    id: string;
    name: string;
    avatar_url: string | null;
    applied_at: number;
}

interface StaffMember {
    id: number;
    name: string;
    avatar_url: string | null;
    rank_name: string;
    rank_level: number;
    is_self: boolean;
}

interface HospitalInfo {
    name: string;
    image: string | null;
    description: string | null;
    chief_name: string | null;
    chief_avatar: string | null;
    owner: { name: string; avatar_url: string | null } | null;
}

interface Props {
    hospital: HospitalInfo | null;
    rank: number;
    rank_label: string;
    is_chief: boolean;
    is_owner: boolean;
    patients: Patient[];
    surgery_queue: QueueEntry[];
    gender_queue: QueueEntry[];
    staff: StaffMember[];
    gender_fee: number;
    surgery_fee: number;
    surgeon_surgery_cut: number;
    surgeon_gender_cut: number;
}

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────

const fmt$ = (n: number) => `$${Math.round(n).toLocaleString()}`;

function fmtTime(seconds: number): string {
    if (seconds <= 0) return 'Discharged';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    if (h > 0) return `${h}h ${m}m`;
    return `${m}m`;
}

type Tab = 'ward' | 'surgery' | 'gender' | 'staff';

const TABS: { id: Tab; label: string; icon: any; minRank?: number; hideBelow?: number }[] = [
    { id: 'ward', label: 'Ward', icon: Heartbeat },
    { id: 'surgery', label: 'Emergency Care', icon: Stethoscope, minRank: 2 },
    { id: 'gender', label: 'Procedures', icon: GenderIntersex, minRank: 3 },
    { id: 'staff', label: 'Staff Management', icon: UserMinus, minRank: 4, hideBelow: 4 },
];

// ─────────────────────────────────────────────────────────────
// Confirm modal
// ─────────────────────────────────────────────────────────────

function ConfirmModal({
    title, body, confirmLabel, danger = false,
    onConfirm, onClose, loading,
}: {
    title: string; body: string; confirmLabel: string;
    danger?: boolean; onConfirm: () => void; onClose: () => void; loading: boolean;
}) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" onClick={onClose}>
            <div className="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" />
            <motion.div
                initial={{ scale: 0.95, opacity: 0 }}
                animate={{ scale: 1, opacity: 1 }}
                className="relative bg-slate-900 border border-slate-700 rounded-2xl p-6 w-full max-w-sm shadow-2xl"
                onClick={e => e.stopPropagation()}
            >
                <p className="text-sm font-bold text-white mb-2">{title}</p>
                <p className="text-xs text-slate-400 leading-relaxed mb-6">{body}</p>
                <div className="flex gap-3">
                    <button
                        onClick={onClose}
                        className="flex-1 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest bg-slate-800 hover:bg-slate-700 text-slate-300 transition-colors"
                    >
                        Cancel
                    </button>
                    <motion.button
                        whileTap={{ scale: 0.97 }}
                        onClick={onConfirm}
                        disabled={loading}
                        className={`flex-1 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all flex items-center justify-center gap-2 ${danger
                            ? 'bg-rose-700 hover:bg-rose-600 text-white'
                            : 'bg-white text-slate-900 hover:bg-slate-100'
                            } ${loading ? 'opacity-60 cursor-not-allowed' : ''}`}
                    >
                        {loading
                            ? <div className="w-3 h-3 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                            : confirmLabel}
                    </motion.button>
                </div>
            </motion.div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────
// Ward tab
// ─────────────────────────────────────────────────────────────

function WardTab({ patients }: { patients: Patient[] }) {
    if (patients.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-24 text-slate-700 gap-3">
                <Heartbeat size={32} />
                <p className="text-xs uppercase tracking-widest">Ward is empty</p>
            </div>
        );
    }

    return (
        <div className="divide-y divide-white/[0.04]">
            {/* Header row — hidden on xs, shown sm+ */}
            <div className="hidden sm:grid sm:grid-cols-[1fr_auto_auto] items-center gap-3 px-4 sm:px-6 py-2">
                <span className="text-[9px] font-black text-slate-600 uppercase tracking-widest">Patient</span>

                <span className="text-[9px] font-black text-slate-600 uppercase tracking-widest w-16 text-right">Time left</span>
            </div>

            {patients.map(p => (
                <div
                    key={p.id}
                    className="flex flex-col sm:grid sm:grid-cols-[1fr_auto_auto] items-start sm:items-center gap-2 sm:gap-3 px-4 sm:px-6 py-4 hover:bg-white/[0.02] transition-colors"
                >
                    <div className="flex items-center gap-3 min-w-0 w-full">
                        <div className="w-9 h-9 rounded-full bg-slate-800 border border-white/[0.06] overflow-hidden shrink-0 flex items-center justify-center">
                            {p.avatar_url
                                ? <img src={p.avatar_url} className="w-full h-full object-cover" alt="" />
                                : <User size={14} className="text-slate-600" />}
                        </div>
                        <div className="min-w-0">
                            <p className="text-sm font-semibold text-white truncate">{p.name}</p>
                            {/* On mobile show inline stats under name */}

                        </div>
                    </div>

                    <span className="hidden sm:block text-xs font-mono text-rose-400/70 tabular-nums w-16 text-right">
                        {fmtTime(p.time_left)}
                    </span>
                </div>
            ))}
        </div>
    );
}



function SurgeryTab({ surgeryQueue, surgeryFee, surgeonCut, rank }: {
    surgeryQueue: QueueEntry[];
    surgeryFee: number;
    surgeonCut: number;
    rank: number;
}) {
    const [operating, setOperating] = useState<string | null>(null);

    const operate = (id: string) => {
        if (operating) return;
        setOperating(id);
        router.post(
            route('career.healthcare.surgery'),
            { character_id: id },
            // Partial reload: the queue/ward plus shared 'auth' (cash, XP, timers) and 'flash'.
            { only: ['surgery_queue', 'patients', 'auth', 'flash'], preserveScroll: true, onFinish: () => setOperating(null) }
        );
    };

    if (rank < 2) {
        return (
            <div className="flex flex-col items-center justify-center py-24 text-slate-600 gap-3">
                <Warning size={28} />
                <p className="text-xs uppercase tracking-widest">Emergency Care requires rank 2 or above</p>
            </div>
        );
    }

    if (surgeryQueue.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-24 text-slate-700 gap-3">
                <Stethoscope size={32} />
                <p className="text-xs uppercase tracking-widest">No patients awaiting care</p>
            </div>
        );
    }

    return (
        <div className="divide-y divide-white/[0.04]">
            <div className="hidden sm:grid sm:grid-cols-[1fr_auto] items-center gap-3 px-4 sm:px-6 py-2">
                <span className="text-[9px] font-black text-slate-600 uppercase tracking-widest">Applicant</span>

            </div>

            {surgeryQueue.map(p => (
                <div
                    key={p.id}
                    className="flex flex-col sm:grid sm:grid-cols-[1fr_auto_auto] items-start sm:items-center gap-2 sm:gap-3 px-4 sm:px-6 py-4 hover:bg-white/[0.02] transition-colors"
                >
                    <div className="flex items-center gap-3 min-w-0 w-full">
                        <div className="w-9 h-9 rounded-full bg-slate-800 border border-white/[0.06] overflow-hidden shrink-0 flex items-center justify-center">
                            {p.avatar_url
                                ? <img src={p.avatar_url} className="w-full h-full object-cover" alt="" />
                                : <User size={14} className="text-slate-600" />}
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-semibold text-white truncate">{p.name}</p>
                        </div>
                        {/* mobile operate button */}
                        <div className="sm:hidden">
                            <motion.button
                                whileTap={{ scale: 0.95 }}
                                onClick={() => operate(p.id)}
                                disabled={operating === p.id}
                                className={`px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all ${operating === p.id
                                    ? 'bg-slate-800/60 text-slate-600 cursor-not-allowed'
                                    : 'bg-cyan-700/30 hover:bg-cyan-700/60 text-cyan-300 border border-cyan-700/40'
                                    }`}
                            >
                                {operating === p.id
                                    ? <span className="flex items-center gap-1"><div className="w-2.5 h-2.5 border border-slate-500/40 border-t-slate-400 rounded-full animate-spin" /></span>
                                    : 'Op'}
                            </motion.button>
                        </div>
                    </div>

                    <div className="hidden sm:flex w-20 justify-end">
                        <motion.button
                            whileTap={{ scale: 0.95 }}
                            onClick={() => operate(p.id)}
                            disabled={operating === p.id}
                            className={`px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all ${operating === p.id
                                ? 'bg-slate-800/60 text-slate-600 cursor-not-allowed'
                                : 'bg-cyan-700/30 hover:bg-cyan-700/60 text-cyan-300 border border-cyan-700/40'
                                }`}
                        >
                            {operating === p.id
                                ? <span className="flex items-center gap-1.5"><div className="w-2.5 h-2.5 border border-slate-500/40 border-t-slate-400 rounded-full animate-spin" />...</span>
                                : 'Operate'}
                        </motion.button>
                    </div>
                </div>
            ))}
        </div>
    );
}


function GenderTab({ genderQueue, genderFee, surgeonCut, rank }: {
    genderQueue: QueueEntry[];
    genderFee: number;
    surgeonCut: number;
    rank: number;
}) {
    const [operating, setOperating] = useState<string | null>(null);

    const perform = (id: string) => {
        if (operating) return;
        setOperating(id);
        router.post(
            route('career.healthcare.gender'),
            { character_id: id },
            { only: ['gender_queue', 'patients', 'auth', 'flash'], preserveScroll: true, onFinish: () => setOperating(null) }
        );
    };

    if (genderQueue.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-24 text-slate-700 gap-3">
                <GenderIntersex size={32} />
                <p className="text-xs uppercase tracking-widest">No pending applications</p>
            </div>
        );
    }

    return (
        <div className="divide-y divide-white/[0.04]">
            <div className="hidden sm:grid sm:grid-cols-[1fr_auto] items-center gap-3 px-4 sm:px-6 py-2">
                <span className="text-[9px] font-black text-slate-600 uppercase tracking-widest">Applicant</span>
                <span className="text-[9px] font-black text-slate-600 uppercase tracking-widest w-20 text-right">Perform</span>
            </div>

            {genderQueue.map(p => (
                <div
                    key={p.id}
                    className="flex flex-col sm:grid sm:grid-cols-[1fr_auto_auto] items-start sm:items-center gap-2 sm:gap-3 px-4 sm:px-6 py-4 hover:bg-white/[0.02] transition-colors"
                >
                    <div className="flex items-center gap-3 min-w-0 w-full">
                        <div className="w-9 h-9 rounded-full bg-slate-800 border border-white/[0.06] overflow-hidden shrink-0 flex items-center justify-center">
                            {p.avatar_url
                                ? <img src={p.avatar_url} className="w-full h-full object-cover" alt="" />
                                : <User size={14} className="text-slate-600" />}
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-semibold text-white truncate">{p.name}</p>
                        </div>
                        <div className="sm:hidden">
                            <motion.button
                                whileTap={{ scale: 0.95 }}
                                onClick={() => perform(p.id)}
                                disabled={operating === p.id}
                                className={`px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all ${operating === p.id
                                    ? 'bg-slate-800/60 text-slate-600 cursor-not-allowed'
                                    : 'bg-violet-700/30 hover:bg-violet-700/60 text-violet-300 border border-violet-700/40'
                                    }`}
                            >
                                {operating === p.id
                                    ? <span className="flex items-center gap-1"><div className="w-2.5 h-2.5 border border-slate-500/40 border-t-slate-400 rounded-full animate-spin" /></span>
                                    : 'Do'}
                            </motion.button>
                        </div>
                    </div>

                    <div className="hidden sm:flex w-20 justify-end">
                        <motion.button
                            whileTap={{ scale: 0.95 }}
                            onClick={() => perform(p.id)}
                            disabled={operating === p.id}
                            className={`px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all ${operating === p.id
                                ? 'bg-slate-800/60 text-slate-600 cursor-not-allowed'
                                : 'bg-violet-700/30 hover:bg-violet-700/60 text-violet-300 border border-violet-700/40'
                                }`}
                        >
                            {operating === p.id
                                ? <span className="flex items-center gap-1.5"><div className="w-2.5 h-2.5 border border-slate-500/40 border-t-slate-400 rounded-full animate-spin" />...</span>
                                : 'Perform'}
                        </motion.button>
                    </div>
                </div>
            ))}
        </div>
    );
}


function StaffTab({ staff, isChief, isOwner }: {
    staff: StaffMember[]; isChief: boolean; isOwner: boolean;
}) {
    const [dismissTarget, setDismissTarget] = useState<StaffMember | null>(null);
    const [loading, setLoading] = useState(false);

    const canDismiss = isChief || isOwner;

    const dismiss = () => {
        if (!dismissTarget || loading) return;
        setLoading(true);
        router.post(
            route('career.healthcare.dismiss'),
            { character_id: dismissTarget.id },
            {
                only: ['staff', 'auth', 'flash'],
                preserveScroll: true,
                onFinish: () => { setLoading(false); setDismissTarget(null); },
            }
        );
    };

    return (
        <>
            <div className="flex flex-col h-full">
                {staff.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-24 text-slate-700 gap-3">
                        <UserMinus size={32} />
                        <p className="text-xs uppercase tracking-widest">No staff to manage</p>
                    </div>
                ) : (
                    <div className="divide-y divide-white/[0.04]">
                        {staff.map(m => (
                            <div
                                key={m.id}
                                className="flex items-center gap-3 px-4 sm:px-6 py-4 hover:bg-white/[0.02] transition-colors"
                            >
                                <div className="w-9 h-9 rounded-full bg-slate-800 border border-white/[0.06] overflow-hidden shrink-0 flex items-center justify-center">
                                    {m.avatar_url
                                        ? <img src={m.avatar_url} className="w-full h-full object-cover" alt="" />
                                        : <User size={14} className="text-slate-600" />}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm font-semibold text-white truncate">{m.name}</p>
                                    <p className="text-[10px] font-black text-slate-500 uppercase tracking-widest">
                                        {m.rank_name}
                                    </p>
                                </div>

                                {canDismiss && !m.is_self && (isOwner || m.rank_level < 4) && (
                                    <motion.button
                                        whileTap={{ scale: 0.95 }}
                                        onClick={() => setDismissTarget(m)}
                                        className="shrink-0 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest bg-rose-900/20 hover:bg-rose-900/40 text-rose-400 border border-rose-800/30 transition-all"
                                    >
                                        Dismiss
                                    </motion.button>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>

            <AnimatePresence>
                {dismissTarget && (
                    <ConfirmModal
                        title={`Dismiss ${dismissTarget.name}?`}
                        body={`${dismissTarget.name} (${dismissTarget.rank_name}) will be removed from the healthcare career. Their XP is preserved.`}
                        confirmLabel="Dismiss"
                        danger
                        onConfirm={dismiss}
                        onClose={() => setDismissTarget(null)}
                        loading={loading}
                    />
                )}
            </AnimatePresence>
        </>
    );
}

// ─────────────────────────────────────────────────────────────
// Root
// ─────────────────────────────────────────────────────────────

export default function Healthcare({
    hospital, rank, rank_label, is_chief, is_owner,
    patients, surgery_queue, gender_queue, staff,
    gender_fee, surgery_fee, surgeon_surgery_cut, surgeon_gender_cut,
}: Props) {
    const [activeTab, setActiveTab] = useState<Tab>('ward');
    // No confirmation modal — step-down fires directly per user request.
    // The `stepping` flag still gates the button so a double-click can't
    // double-submit, but there's no intermediate modal in between.
    const [stepping, setStepping] = useState(false);

    const doStepDown = () => {
        if (stepping) return;
        setStepping(true);
        router.post(
            route('career.healthcare.resign'),
            {},
            { onFinish: () => setStepping(false) }
        );
    };

    return (
        <>
            <Head title="Healthcare — Staff Portal" />
            <div className="w-full max-w-6xl mx-auto px-2 sm:px-4 py-3 sm:py-6 flex flex-col gap-3 sm:gap-6 h-full">

                {/* ── Hero ─────────────────────────────────────────── */}
                <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4 }}
                    className="relative rounded-t-2xl overflow-hidden border border-white/5 border-b-0 shadow-2xl shrink-0 min-h-[14rem] sm:min-h-[18rem] flex flex-col justify-end"
                >
                    <div className="absolute inset-0 bg-slate-900 overflow-hidden">
                        {hospital?.image ? (
                            <img src={hospital.image} className="absolute inset-0 w-full h-full object-cover" alt="" />
                        ) : (
                            <div className="absolute inset-0 bg-gradient-to-br from-slate-800 via-slate-900 to-slate-950 opacity-80" />
                        )}
                        <div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/80 to-transparent" />
                        <div className="absolute inset-0 bg-gradient-to-r from-slate-950/80 to-transparent" />
                    </div>

                    <div className="relative px-5 pt-8 pb-5 sm:px-8 sm:pb-7">


                        {/* Hospital name */}
                        <h1 className="text-3xl sm:text-4xl md:text-5xl font-light text-white tracking-tight leading-none mb-3">
                            {hospital ? hospital.name : 'General Hospital'}
                        </h1>

                        {hospital?.description && (
                            <p className="text-slate-300 text-xs sm:text-sm leading-relaxed line-clamp-2 mb-4">
                                {hospital.description}
                            </p>
                        )}

                        {/* Owner + Chief cards + Step Down all on same row */}
                        <div className="flex flex-wrap items-center gap-4">
                            {hospital?.owner && (
                                <div className="flex items-center gap-3">
                                    <div className="w-10 h-10 sm:w-12 sm:h-12 rounded-full overflow-hidden border-2 border-emerald-500/40 bg-slate-800 shrink-0">
                                        {hospital.owner.avatar_url && (
                                            <img src={hospital.owner.avatar_url} className="w-full h-full object-cover" alt="" />
                                        )}
                                    </div>
                                    <div>
                                        <p className="text-[9px] font-black uppercase tracking-widest text-emerald-400">Owner</p>
                                        <p className="text-sm font-black text-white">{hospital.owner.name}</p>
                                    </div>
                                </div>
                            )}
                            {hospital?.chief_name && (
                                <div className="flex items-center gap-3">
                                    <div className="w-10 h-10 sm:w-12 sm:h-12 rounded-full overflow-hidden border-2 border-rose-500/40 bg-slate-800 shrink-0">
                                        {hospital.chief_avatar && (
                                            <img src={hospital.chief_avatar} className="w-full h-full object-cover" alt="" />
                                        )}
                                    </div>
                                    <div>
                                        <p className="text-[9px] font-black uppercase tracking-widest text-rose-400">Surgeon General</p>
                                        <p className="text-sm font-black text-white">{hospital.chief_name}</p>
                                    </div>
                                </div>
                            )}
                            <button
                                onClick={doStepDown}
                                disabled={stepping}
                                className={`ml-auto flex items-center gap-1.5 px-3 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest border border-amber-500/40 transition-colors ${
                                    stepping
                                        ? 'bg-amber-500/5 text-amber-400/50 cursor-not-allowed'
                                        : 'bg-amber-500/10 hover:bg-amber-500/20 text-amber-400'
                                }`}
                            >
                                <SignOut size={12} weight="bold" /> {stepping ? 'Stepping Down…' : 'Step Down'}
                            </button>
                        </div>
                    </div>
                </motion.div>


                {/* ── Tab bar ──────────────────────────────────────── */}
                <div className="flex overflow-x-auto no-scrollbar border-b border-slate-800/40 gap-0.5 shrink-0">
                    {TABS.filter(tab => !(tab.hideBelow && rank < tab.hideBelow)).map(tab => {
                        const Icon = tab.icon;
                        const active = activeTab === tab.id;
                        const locked = tab.minRank && rank < tab.minRank;
                        return (
                            <button
                                key={tab.id}
                                onClick={() => !locked && setActiveTab(tab.id)}
                                className={`flex items-center gap-1.5 px-3 sm:px-5 py-3 text-[10px] font-black uppercase tracking-widest border-b-2 transition whitespace-nowrap ${locked
                                    ? 'border-transparent text-slate-700 cursor-not-allowed'
                                    : active
                                        ? 'border-emerald-400 text-white'
                                        : 'border-transparent text-slate-500 hover:text-slate-300'
                                    }`}
                            >
                                <Icon size={13} weight={active ? 'fill' : 'bold'} />
                                <span className="hidden xs:inline sm:inline">{tab.label}</span>
                                {locked && (
                                    <span className="text-[8px] font-black text-slate-700 border border-slate-700 rounded px-1">
                                        R{tab.minRank}+
                                    </span>
                                )}
                                {tab.id === 'ward' && patients.length > 0 && (
                                    <span className="px-1.5 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400 text-[9px] font-black tabular-nums border border-emerald-500/20">
                                        {patients.length}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>

                {/* ── Tab content ──────────────────────────────────── */}
                <div className="flex-1 min-h-0 bg-slate-900/60 border border-slate-700/40 rounded-xl shadow-lg overflow-hidden overflow-y-auto">
                    <AnimatePresence mode="wait">
                        <motion.div
                            key={activeTab}
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            exit={{ opacity: 0 }}
                            transition={{ duration: 0.12 }}
                            className="h-full flex flex-col"
                        >
                            {activeTab === 'ward' && <WardTab patients={patients} />}
                            {activeTab === 'surgery' && (
                                <SurgeryTab
                                    surgeryQueue={surgery_queue}
                                    surgeryFee={surgery_fee}
                                    surgeonCut={surgeon_surgery_cut}
                                    rank={rank}
                                />
                            )}
                            {activeTab === 'gender' && (
                                <GenderTab
                                    genderQueue={gender_queue}
                                    genderFee={gender_fee}
                                    surgeonCut={surgeon_gender_cut}
                                    rank={rank}
                                />
                            )}
                            {activeTab === 'staff' && (
                                <StaffTab
                                    staff={staff}
                                    isChief={is_chief}
                                    isOwner={is_owner}
                                />
                            )}
                        </motion.div>
                    </AnimatePresence>
                </div>
            </div>

            {/* Step-down confirmation modal removed per user request —
                the button now fires doStepDown() directly. Dismiss modal
                (line 440) is still wired because it's a senior action
                affecting another character, not the caller's own choice. */}
        </>
    );
}

Healthcare.layout = (page: React.ReactNode) => <GameLayout wide children={page} />;
