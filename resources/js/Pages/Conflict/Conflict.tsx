import { useState, FormEvent } from "react";
import { router } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import {
    Skull, Users,
    MagnifyingGlass, Warning, Shield,
    Crosshair, BoxingGlove, CheckCircle, X, CaretDown, CaretUp, Prohibit, ArrowRight
} from "@phosphor-icons/react";
import GameLayout from '@/Layouts/GameLayout';
import { motion, AnimatePresence } from 'framer-motion';

interface Weapon {
    id: number;
    code: string;
    name: string;
    type: string;
    image_url: string;
    max_uses: number;
    price: number;
}

interface Armor {
    id: number;
    code: string;
    name: string;
    type: string;
    max_uses: number;
    image_url: string;
    price: number;
}

interface EquippedGear {
    weapon: Weapon | null;
    armor: Armor | null;
}

interface OrganizedHitState {
    target_name: string;
    member_ids: number[];
    member_names: Record<number, string>;
    accepted_by: number[];
    is_ready: boolean;
    expires_at: number;
    is_initiator: boolean;
    initiator_id: number;
}

interface PendingInvite {
    initiator_id: number;
    initiator_name: string;
    target_name: string;
    member_ids: number[];
    member_names: Record<number, string>;
    accepted_by: number[];
}

interface Accomplice {
    id: number;
    name: string;
}

interface ConflictProps {
    equipped: EquippedGear;
    organizedHit: OrganizedHitState | null;
    pendingInvite: PendingInvite | null;
    accomplices: Accomplice[];
    myId: number;
}

type Tab = "assassinate" | "gbh" | "crew";

// ── Organized hit panel ───────────────────────────────────────────────────

function OrganizedHitPanel({
    accomplices, organizedHit, pendingInvite, myId,
}: {
    accomplices: Accomplice[];
    organizedHit: OrganizedHitState | null;
    pendingInvite: PendingInvite | null;
    myId: number;
}) {
    const [target, setTarget] = useState('');
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [pickerOpen, setPickerOpen] = useState(false);
    const [message, setMessage] = useState('');
    const [processing, setProcessing] = useState(false);
    const [inviteProcessing, setInviteProcessing] = useState<'accept' | 'decline' | null>(null);
    const [executeProcessing, setExecuteProcessing] = useState(false);
    const [cancelProcessing, setCancelProcessing] = useState(false);

    const targetLower = target.trim().toLowerCase();
    const filteredCrew = accomplices.filter(a => a.name.toLowerCase() !== targetLower);

    const toggle = (id: number) =>
        setSelectedIds(prev =>
            prev.includes(id) ? prev.filter(x => x !== id) : prev.length < 2 ? [...prev, id] : prev
        );

    const canSubmit = !!(target.trim() && selectedIds.length === 2 && !processing);

    const handleSubmit = () => {
        if (!canSubmit) return;
        setProcessing(true);
        router.post('/conflict/organized/initiate',
            {
                target: target.trim(),
                accomplice_ids: selectedIds,
                message: message.trim() || undefined,
            },
            { preserveScroll: true, onFinish: () => setProcessing(false) }
        );
    };

    const handleAccept = () => {
        if (!pendingInvite || inviteProcessing) return;
        setInviteProcessing('accept');
        router.post(`/conflict/organized/accept/${pendingInvite.initiator_id}`, {},
            { preserveScroll: true, onFinish: () => setInviteProcessing(null) }
        );
    };

    const handleDecline = () => {
        if (!pendingInvite || inviteProcessing) return;
        setInviteProcessing('decline');
        router.post(`/conflict/organized/decline/${pendingInvite.initiator_id}`, {},
            { preserveScroll: true, onFinish: () => setInviteProcessing(null) }
        );
    };

    const handleExecute = () => {
        if (executeProcessing) return;
        setExecuteProcessing(true);
        router.post('/conflict/organized/execute', {},
            { preserveScroll: true, onFinish: () => setExecuteProcessing(false) }
        );
    };

    const handleCancel = () => {
        if (cancelProcessing) return;
        setCancelProcessing(true);
        router.post('/conflict/organized/cancel', {},
            { preserveScroll: true, onFinish: () => setCancelProcessing(false) }
        );
    };

    // State 3: Active op (initiator OR accepted accomplice)
    if (organizedHit) {
        const acceptedCount = organizedHit.accepted_by.length;
        const totalCount = organizedHit.member_ids.length;
        const isInitiator = organizedHit.is_initiator ?? false;

        return (
            <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }}
                className="bg-slate-900/40 border border-slate-800/80 rounded-xl overflow-hidden"
            >
                <div className={`px-5 py-3 border-b border-white/[0.05] flex items-center gap-3 ${organizedHit.is_ready ? 'bg-emerald-500/10' : 'bg-slate-800/60'
                    }`}>
                    <Users size={13} weight="fill" className={organizedHit.is_ready ? 'text-emerald-400' : 'text-slate-400'} />
                    <div className="flex-1">
                        <p className={`text-[9px] font-black uppercase tracking-widest ${organizedHit.is_ready ? 'text-emerald-400' : 'text-cyan-400/85'
                            }`}>
                            {organizedHit.is_ready
                                ? `Crew ready — ${acceptedCount}/${totalCount} confirmed`
                                : `Waiting for crew — ${acceptedCount}/${totalCount} confirmed`}
                        </p>
                        <p className="mt-0.5 text-xs font-bold text-white/75">
                            Target: <span className="text-red-400 font-bold">{organizedHit.target_name}</span>
                        </p>
                    </div>
                </div>

                <div className="px-5 py-4">
                    <p className="mb-3 text-[9px] font-black uppercase tracking-widest text-cyan-400/85">Crew Status</p>
                    <div className="space-y-2">
                        {organizedHit.member_ids.map(id => {
                            const accepted = organizedHit.accepted_by.includes(id);
                            const name = organizedHit.member_names[id] ?? `#${id}`;
                            return (
                                <div key={id} className="flex items-center gap-3">
                                    <div className={`w-2 h-2 rounded-full shrink-0 ${accepted ? 'bg-emerald-400' : 'bg-slate-700'}`} />
                                    <span className="text-sm font-bold text-white/85">
                                        {name}{id === myId ? ' (you)' : ''}
                                    </span>
                                    <span className={`ml-auto text-[9px] font-black uppercase tracking-wider ${accepted ? 'text-emerald-500' : 'text-white/50'}`}>
                                        {accepted ? 'Confirmed' : 'Pending'}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </div>

                <div className="px-5 pb-5 space-y-2">
                    {isInitiator && organizedHit.is_ready && (
                        <button
                            onClick={handleExecute}
                            disabled={executeProcessing}
                            className={`w-full h-11 flex items-center justify-center gap-2 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all ${executeProcessing
                                    ? 'bg-slate-800/60 text-slate-600 cursor-not-allowed border border-white/[0.06]'
                                    : 'bg-red-600 hover:bg-red-500 text-white shadow-lg shadow-red-500/20'
                                }`}
                        >
                            {executeProcessing
                                ? <><div className="w-3 h-3 border-2 border-slate-600 border-t-slate-400 rounded-full animate-spin" /><span>Executing...</span></>
                                : <><Crosshair size={13} weight="bold" /><span>Execute Hit</span><ArrowRight size={12} weight="bold" /></>}
                        </button>
                    )}
                    {isInitiator && (
                        <button
                            onClick={handleCancel}
                            disabled={cancelProcessing}
                            className={`w-full h-10 flex items-center justify-center gap-2 rounded-xl text-[10px] font-black uppercase tracking-widest border transition-all ${cancelProcessing
                                    ? 'bg-slate-800/40 text-slate-700 border-white/[0.04] cursor-not-allowed'
                                    : 'bg-slate-800/60 text-slate-400 border-white/[0.06] hover:border-red-500/30 hover:text-red-400 hover:bg-red-500/5'
                                }`}
                        >
                            {cancelProcessing
                                ? <><div className="w-3 h-3 border-2 border-slate-700 border-t-slate-500 rounded-full animate-spin" /><span>Cancelling...</span></>
                                : <><Prohibit size={12} weight="bold" /><span>Cancel Operation</span></>}
                        </button>
                    )}
                    {!isInitiator && !organizedHit.is_ready && (
                        <div className="h-10 flex items-center justify-center gap-2 text-[10px] font-black uppercase tracking-widest text-white/55">
                            <div className="w-3 h-3 border-2 border-slate-700 border-t-slate-500 rounded-full animate-spin" />
                            Waiting for initiator...
                        </div>
                    )}
                </div>
            </motion.div>
        );
    }

    // State 2: Pending invite
    if (pendingInvite) {
        const acceptedCount = pendingInvite.accepted_by.length;
        const totalCount = pendingInvite.member_ids.length;

        return (
            <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }}
                className="bg-slate-900/40 border border-red-900/40 rounded-xl overflow-hidden"
            >
                <div className="px-5 py-3 border-b border-red-900/30 bg-red-950/30 flex items-center gap-3">
                    <Skull size={13} weight="fill" className="text-red-400" />
                    <div className="flex-1">
                        <p className="text-[9px] font-black uppercase tracking-widest text-red-400">
                            Organized Attack— {acceptedCount}/{totalCount} confirmed
                        </p>
                        <p className="mt-0.5 text-xs font-bold text-white/75">
                            Target: <span className="text-red-400 font-bold">{pendingInvite.target_name}</span>
                        </p>
                    </div>
                </div>

                <div className="px-5 py-4">
                    <p className="mb-3 text-[9px] font-black uppercase tracking-widest text-cyan-400/85">Crew Status</p>
                    <div className="space-y-2">
                        {pendingInvite.member_ids.map(id => {
                            const accepted = pendingInvite.accepted_by.includes(id);
                            const name = pendingInvite.member_names[id] ?? `#${id}`;
                            return (
                                <div key={id} className="flex items-center gap-3">
                                    <div className={`w-2 h-2 rounded-full shrink-0 ${accepted ? 'bg-emerald-400' : 'bg-slate-700'}`} />
                                    <span className="text-sm font-bold text-white/85">
                                        {name}{id === myId ? ' (you)' : ''}
                                    </span>
                                    <span className={`ml-auto text-[9px] font-black uppercase tracking-wider ${accepted ? 'text-emerald-500' : 'text-white/50'}`}>
                                        {accepted ? 'Confirmed' : 'Pending'}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </div>

                <div className="px-5 pb-5 flex gap-3">
                    <button
                        onClick={handleAccept}
                        disabled={!!inviteProcessing}
                        className={`flex-1 h-11 flex items-center justify-center gap-2 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all ${inviteProcessing === 'accept'
                                ? 'bg-emerald-800/60 text-emerald-600 cursor-not-allowed'
                                : inviteProcessing
                                    ? 'bg-slate-800/40 text-slate-600 cursor-not-allowed'
                                    : 'bg-emerald-600 hover:bg-emerald-500 text-white'
                            }`}
                    >
                        {inviteProcessing === 'accept'
                            ? <><div className="w-3 h-3 border-2 border-emerald-600 border-t-emerald-300 rounded-full animate-spin" /><span>Accepting...</span></>
                            : <><CheckCircle size={13} weight="fill" /><span>Accept</span></>}
                    </button>
                    <button
                        onClick={handleDecline}
                        disabled={!!inviteProcessing}
                        className={`flex-1 h-11 flex items-center justify-center gap-2 rounded-xl border text-[11px] font-black uppercase tracking-widest transition-all ${inviteProcessing
                                ? 'bg-slate-800/40 text-slate-600 border-white/[0.04] cursor-not-allowed'
                                : 'bg-slate-800 hover:bg-slate-700/80 border-white/[0.06] text-slate-400 hover:text-white'
                            }`}
                    >
                        {inviteProcessing === 'decline'
                            ? <><div className="w-3 h-3 border-2 border-slate-600 border-t-slate-400 rounded-full animate-spin" /><span>Declining...</span></>
                            : <><X size={13} weight="bold" /><span>Decline</span></>}
                    </button>
                </div>
            </motion.div>
        );
    }

    // State 1: Initiate form
    return (
        <div className="space-y-5">
            <span className="text-[10px] px-2 py-0.5 bg-slate-700/40 rounded text-slate-300 font-mono border border-slate-600/30">3-PERSON OP</span>

            <div className="bg-slate-900/40 border border-slate-800/80 rounded-xl p-5 space-y-4">
                <div>
                    <label className="mb-2 block text-sm font-semibold text-white/80">Target</label>
                    <div className="relative">
                        <MagnifyingGlass className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-500" weight="bold" />
                        <input
                            type="text"
                            value={target}
                            onChange={e => { setTarget(e.target.value); setSelectedIds([]); }}
                            placeholder="Enter target display name..."
                            disabled={processing}
                            className="w-full pl-10 pr-4 py-2.5 bg-slate-800/50 border border-slate-700/30 rounded-lg text-sm text-white placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-500/40 transition disabled:opacity-50"
                        />
                    </div>
                </div>

                <div>
                    <label className="mb-2 block text-sm font-semibold text-white/80">Final Message <span className="font-semibold text-white/50">(optional)</span></label>
                    <textarea
                        value={message}
                        onChange={e => setMessage(e.target.value)}
                        placeholder="Leave a message for your target..."
                        maxLength={200}
                        rows={3}
                        disabled={processing}
                        className="w-full px-3 py-2.5 bg-slate-800/50 border border-slate-700/30 rounded-lg text-sm text-white placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-red-500/40 resize-none transition disabled:opacity-50"
                    />
                    <div className="mt-1 text-right text-xs font-semibold text-white/50">{message.length}/200</div>
                </div>

                <div>

                    <div className="relative">
                        <button
                            type="button"
                            onClick={() => setPickerOpen(p => !p)}
                            className={`w-full flex items-center justify-between gap-3 px-4 py-2.5 rounded-lg border text-sm font-bold transition-all ${selectedIds.length > 0
                                    ? 'bg-slate-800/80 border-slate-600/50 text-white'
                                    : 'bg-slate-800/50 border-slate-700/30 text-white/70'
                                }`}
                        >
                            <span className="flex items-center gap-2">
                                <Users size={14} className="text-white/60" />
                                {selectedIds.length > 0 ? `${selectedIds.length}/2 selected` : 'Choose your crew...'}
                            </span>
                            {pickerOpen ? <CaretUp size={12} weight="bold" className="text-white/60" /> : <CaretDown size={12} weight="bold" className="text-white/60" />}
                        </button>

                        <AnimatePresence>
                            {pickerOpen && (
                                <motion.div
                                    initial={{ opacity: 0, y: -6 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    exit={{ opacity: 0, y: -6 }}
                                    transition={{ duration: 0.12 }}
                                    className="absolute z-20 w-full mt-1.5 bg-slate-950 border border-slate-700 rounded-xl overflow-hidden shadow-2xl"
                                >
                                    <div className="max-h-60 overflow-y-auto">
                                        {filteredCrew.length === 0 && (
                                            <div className="px-4 py-3 text-xs text-white/55 italic">
                                                {target.trim() ? 'No crew available (target excluded)' : 'No one available in your city'}
                                            </div>
                                        )}
                                        {filteredCrew.map(a => {
                                            const picked = selectedIds.includes(a.id);
                                            const disabled = !picked && selectedIds.length >= 2;
                                            return (
                                                <button
                                                    key={a.id}
                                                    type="button"
                                                    disabled={disabled}
                                                    onClick={() => toggle(a.id)}
                                                    className={`w-full flex items-center gap-3 px-4 py-2.5 text-sm text-left transition-colors ${picked ? 'bg-slate-700/40 text-white'
                                                            : disabled ? 'text-slate-700 cursor-not-allowed'
                                                                : 'hover:bg-slate-800/80 text-white/80'
                                                        }`}
                                                >
                                                    <span className="flex-1">{a.name}</span>
                                                    {picked && <CheckCircle size={12} weight="fill" className="text-white/80 shrink-0" />}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </motion.div>
                            )}
                        </AnimatePresence>
                    </div>

                    {selectedIds.length > 0 && (
                        <div className="flex flex-wrap gap-1.5 mt-2">
                            {selectedIds.map(id => {
                                const a = filteredCrew.find(x => x.id === id);
                                return a ? (
                                    <span key={id} className="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded-md bg-slate-700/40 text-white/75 border border-slate-600/40">
                                        {a.name}
                                        <button type="button" onClick={() => toggle(id)} className="hover:text-white ml-0.5">
                                            <X size={9} weight="bold" />
                                        </button>
                                    </span>
                                ) : null;
                            })}
                        </div>
                    )}
                </div>
            </div>

            <button
                onClick={handleSubmit}
                disabled={!canSubmit}
                className={`w-full h-12 rounded-xl font-bold text-xs uppercase tracking-[0.2em] transition-all duration-300 ${!canSubmit
                        ? 'bg-slate-800/50 text-slate-500 border border-slate-800/50 cursor-not-allowed'
                        : 'bg-white text-slate-950 hover:bg-slate-300 hover:-translate-y-0.5'
                    }`}
            >
                <div className="flex items-center justify-center gap-2">
                    {processing
                        ? <><div className="w-3 h-3 border-2 border-slate-950/30 border-t-slate-950 rounded-full animate-spin" /><span>Initiating...</span></>
                        : <><Users size={14} /><span>Launch Operation</span></>}
                </div>
            </button>
        </div>
    );
}


export default function Conflict({ equipped, organizedHit, pendingInvite, accomplices, myId }: ConflictProps) {
    const [activeTab, setActiveTab] = useState<Tab>("assassinate");
    const [targetUsername, setTargetUsername] = useState("");
    const [message, setMessage] = useState("");
    const [processing, setProcessing] = useState(false);

    const handleGBH = (e: FormEvent) => {
        e.preventDefault();
        if (!targetUsername.trim() || !message.trim() || processing) return;
        setProcessing(true);
        router.post('/conflict/gbh', {
            target: targetUsername.trim(),
            message: message.trim(),
        }, {
            preserveScroll: true,
            onSuccess: () => { setTargetUsername(""); setMessage(""); },
            onFinish: () => setProcessing(false),
        });
    };

    const handleAssassinate = (e: FormEvent) => {
        e.preventDefault();
        if (!targetUsername.trim() || processing) return;
        setProcessing(true);
        router.post('/conflict/attack', {
            target: targetUsername.trim(),
            message: message.trim() || undefined,
        }, {
            preserveScroll: true,
            onSuccess: () => { setTargetUsername(""); setMessage(""); },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div className="w-full">
            <Head title="Conflict - TheDirector" />

            <div className="w-full max-w-4xl mx-auto space-y-8 py-4 sm:py-8 px-4">
                { }
                <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 border-b border-slate-800/50 pb-6">
                    <div>
                        <h1 className="text-3xl sm:text-4xl font-black text-white tracking-tight">
                            CONFLICT <span className="text-red-400 italic"></span>
                        </h1>
                         
                        <p className="mt-3 border-l-2 border-cyan-400 pl-3 text-xs font-bold text-white/75">
                            Players offline for 2 weeks or longer can be attacked.
                        </p>
                    </div>
                </div>

                { }
                <div className="grid lg:grid-cols-[1fr_300px] gap-8 items-start">

                    { }
                    <div className="space-y-6">
                        { }
                        <div className="flex gap-1 border-b border-slate-800/50">
                            <button
                                onClick={() => setActiveTab("assassinate")}
                                className={`px-4 py-2.5 text-sm font-medium transition border-b-2 flex items-center gap-2 ${activeTab === "assassinate"
                                    ? "text-red-400 border-red-400"
                                    : "text-slate-300 border-transparent hover:text-red-300"
                                    }`}
                            >
                                <Skull className="h-4 w-4" />
                                Assassinate
                            </button>
                            <button
                                onClick={() => setActiveTab("gbh")}
                                className={`px-4 py-2.5 text-sm font-medium transition border-b-2 flex items-center gap-2 ${activeTab === "gbh"
                                    ? "text-cyan-400 border-cyan-400"
                                    : "text-slate-300 border-transparent hover:text-cyan-300"
                                    }`}
                            >
                                <BoxingGlove className="h-4 w-4" weight="bold" />
                                GBH
                            </button>
                            <button
                                onClick={() => setActiveTab("crew")}
                                className={`px-4 py-2.5 text-sm font-medium transition border-b-2 flex items-center gap-2 ${activeTab === "crew"
                                    ? "text-violet-400 border-violet-400"
                                    : "text-slate-300 border-transparent hover:text-violet-300"
                                    }`}
                            >
                                <Users className="h-4 w-4" />
                                Organized Hit
                            </button>
                        </div>

                        { }
                        {activeTab === "assassinate" && (
                            <form onSubmit={handleAssassinate} className="space-y-5">
                                <div className="flex items-center justify-between px-1">

                                    <span className="text-[10px] px-2 py-0.5 bg-red-500/10 rounded text-red-400 font-mono border border-red-500/20">LETHAL</span>
                                </div>

                                <div className="bg-slate-900/40 border border-slate-800/80 rounded-xl p-5 space-y-4">
                                    <div>
                                        <label className="mb-2 block text-sm font-semibold text-white/80">Target</label>
                                        <div className="relative">
                                            <MagnifyingGlass className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-500" weight="bold" />
                                            <input
                                                type="text"
                                                value={targetUsername}
                                                onChange={(e) => setTargetUsername(e.target.value)}
                                                placeholder="Enter target display name..."
                                                disabled={processing}
                                                className="w-full pl-10 pr-4 py-2.5 bg-slate-800/50 border border-slate-700/30 rounded-lg text-sm text-white placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-red-500/40 focus:border-red-500/40 transition disabled:opacity-50"
                                            />
                                        </div>
                                    </div>

                                    <div>
                                        <label className="mb-2 block text-sm font-semibold text-white/80">Final Message <span className="font-semibold text-white/50">(optional)</span></label>
                                        <textarea
                                            value={message}
                                            onChange={(e) => setMessage(e.target.value)}
                                            placeholder="Leave a message for your target..."
                                            maxLength={200}
                                            rows={3}
                                            disabled={processing}
                                            className="w-full px-3 py-2.5 bg-slate-800/50 border border-slate-700/30 rounded-lg text-sm text-white placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-red-500/40 resize-none transition disabled:opacity-50"
                                        />
                                        <div className="mt-1 text-right text-xs font-semibold text-white/50">{message.length}/200</div>
                                    </div>
                                </div>

                                <button
                                    type="submit"
                                    disabled={!targetUsername.trim() || processing}
                                    className={`w-full group relative overflow-hidden h-12 rounded-xl font-bold text-xs uppercase tracking-[0.2em] transition-all duration-300 ${!targetUsername.trim() || processing
                                        ? 'bg-slate-800/50 text-slate-500 border border-slate-800/50 cursor-not-allowed'
                                        : 'bg-white text-slate-950 hover:bg-red-400 hover:shadow-[0_0_20px_rgba(248,113,113,0.4)] hover:-translate-y-0.5'
                                        }`}
                                >
                                    <div className="relative z-10 flex items-center justify-center gap-2">
                                        {processing ? (
                                            <>
                                                <div className="w-3 h-3 border-2 border-slate-950/30 border-t-slate-950 rounded-full animate-spin" />
                                                <span>Attacking...</span>
                                            </>
                                        ) : (
                                            <>
                                                <Crosshair size={14} />
                                                <span>Kill</span>
                                            </>
                                        )}
                                    </div>
                                </button>
                            </form>
                        )}

                        { }
                        {activeTab === "gbh" && (
                            <form onSubmit={handleGBH} className="space-y-5">
                                <div className="flex items-center justify-between px-1">

                                    <span className="text-[10px] px-2 py-0.5 bg-cyan-500/10 rounded text-cyan-400 font-mono border border-cyan-500/20">NON-LETHAL</span>
                                </div>

                                <div className="bg-slate-900/40 border border-slate-800/80 rounded-xl p-5 space-y-4">
                                    <div>
                                        <label className="mb-2 block text-sm font-semibold text-white/80">Target</label>
                                        <div className="relative">
                                            <MagnifyingGlass className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-500" weight="bold" />
                                            <input
                                                type="text"
                                                value={targetUsername}
                                                onChange={(e) => setTargetUsername(e.target.value)}
                                                placeholder="Enter target display name..."
                                                disabled={processing}
                                                className="w-full pl-10 pr-4 py-2.5 bg-slate-800/50 border border-slate-700/30 rounded-lg text-sm text-white placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/40 focus:border-cyan-500/40 transition disabled:opacity-50"
                                            />
                                        </div>
                                    </div>

                                    <div>
                                        <label className="mb-2 block text-sm font-semibold text-white/80">Message <span className="text-red-400">*</span></label>
                                        <textarea
                                            value={message}
                                            onChange={(e) => setMessage(e.target.value)}
                                            placeholder="Deliver your message with force..."
                                            maxLength={200}
                                            rows={3}
                                            disabled={processing}
                                            className="w-full px-3 py-2.5 bg-slate-800/50 border border-slate-700/30 rounded-lg text-sm text-white placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/40 resize-none transition disabled:opacity-50"
                                        />
                                        <div className="mt-1 text-right text-xs font-semibold text-white/50">{message.length}/200</div>
                                    </div>
                                </div>

                                <button
                                    type="submit"
                                    disabled={!targetUsername.trim() || !message.trim() || processing}
                                    className={`w-full group relative overflow-hidden h-12 rounded-xl font-bold text-xs uppercase tracking-[0.2em] transition-all duration-300 ${!targetUsername.trim() || !message.trim() || processing
                                        ? 'bg-slate-800/50 text-slate-500 border border-slate-800/50 cursor-not-allowed'
                                        : 'bg-white text-slate-950 hover:bg-cyan-400 hover:shadow-[0_0_20px_rgba(6,182,212,0.4)] hover:-translate-y-0.5'
                                        }`}
                                >
                                    <div className="relative z-10 flex items-center justify-center gap-2">
                                        {processing ? (
                                            <>
                                                <div className="w-3 h-3 border-2 border-slate-950/30 border-t-slate-950 rounded-full animate-spin" />
                                                <span>Assaulting...</span>
                                            </>
                                        ) : (
                                            <>
                                                <BoxingGlove size={14} weight="bold" />
                                                <span>Execute GBH</span>
                                            </>
                                        )}
                                    </div>
                                </button>
                            </form>
                        )}

                        {activeTab === "crew" && (
                            <OrganizedHitPanel
                                accomplices={accomplices}
                                organizedHit={organizedHit}
                                pendingInvite={pendingInvite}
                                myId={myId}
                            />
                        )}
                    </div>

                    { }
                    <div className="sticky top-24">
                        <div className="bg-gradient-to-br from-slate-900/80 to-slate-950/80 backdrop-blur-xl border border-slate-800/80 rounded-2xl overflow-hidden shadow-2xl">
                            <div className="p-5">
                                <h3 className="text-xs font-black text-white uppercase tracking-[0.2em] mb-4">Your Loadout</h3>

                                { }
                                <div className="rounded-xl border border-slate-700/30 overflow-hidden mb-3 group hover:border-red-500/30 transition-colors">
                                    {equipped.weapon?.image_url ? (
                                        <div className="aspect-[4/3] bg-slate-800/50 overflow-hidden flex items-center justify-center">
                                            <img
                                                src={equipped.weapon.image_url}
                                                alt={equipped.weapon.name}
                                                className="w-full h-full object-contain p-2 group-hover:scale-105 transition-transform duration-500"
                                            />
                                        </div>
                                    ) : (
                                        <div className="aspect-[4/3] bg-slate-800/30 flex items-center justify-center">
                                            <Crosshair className="h-8 w-8 text-slate-700" />
                                        </div>
                                    )}
                                    <div className="p-3 bg-slate-900/60">
                                        <div className="text-[10px] text-red-400 uppercase tracking-wider font-bold mb-0.5">Weapon</div>
                                        <div className="text-sm font-bold text-white">{equipped.weapon?.name || 'Bare Hands'}</div>
                                    </div>
                                </div>

                                { }
                                <div className="rounded-xl border border-slate-700/30 overflow-hidden group hover:border-emerald-500/30 transition-colors">
                                    {equipped.armor?.image_url ? (
                                        <div className="aspect-[4/3] bg-slate-800/50 overflow-hidden flex items-center justify-center">
                                            <img
                                                src={equipped.armor.image_url}
                                                alt={equipped.armor.name}
                                                className="w-full h-full object-contain p-2 group-hover:scale-105 transition-transform duration-500"
                                            />
                                        </div>
                                    ) : (
                                        <div className="aspect-[4/3] bg-slate-800/30 flex items-center justify-center">
                                            <Shield className="h-8 w-8 text-slate-700" />
                                        </div>
                                    )}
                                    <div className="p-3 bg-slate-900/60">
                                        <div className="text-[10px] text-emerald-400 uppercase tracking-wider font-bold mb-0.5">Armor</div>
                                        <div className="text-sm font-bold text-white">{equipped.armor?.name || 'Unprotected'}</div>

                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

Conflict.layout = (page: React.ReactNode) => <GameLayout children={page} />;
