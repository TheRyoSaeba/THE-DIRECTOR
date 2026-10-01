import { useState, useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
// @ts-ignore
import { route } from 'ziggy-js';
import {
    Heartbeat, Users, Stethoscope, GenderIntersex,
    User, Crown, Bandaids, FirstAidKit,
} from '@phosphor-icons/react';
import { motion, AnimatePresence } from 'framer-motion';
import GameLayout from '@/Layouts/GameLayout';
import StyledModal, { ActionButton } from '@/Layouts/styledmodal';
import { getCityImage } from '@/utils/cityImages';



interface HospitalInfo {
    name: string;
    city: string;
    image: string | null;
    description: string | null;
    chief_name: string | null;
    chief_avatar: string | null;
    owner: { name: string; avatar_url: string | null } | null;
}

interface Patient {
    id: string;
    name: string;
    avatar_url: string | null;
}

interface QueuePatient {
    id: string;
    name: string;
    avatar_url: string | null;
    queue_type: 'surgery' | 'gender';
}

interface StaffMember {
    id: number;
    name: string;
    avatar_url: string | null;
    rank_name: string;
}

interface HospitalProps {
    hospital: HospitalInfo | null;
    is_owner: boolean;
    patients: Patient[];
    queue_patients: QueuePatient[];
    in_surgery_queue: boolean;
    in_gender_queue: boolean;
    staff: StaffMember[];
    gender_fee: number;     // live owner-set price; shown on the apply-form
    surgery_fee: number;    // live owner-set price; shown on the apply-form
    // Fees the current viewer locked in at apply time. Will be 0 when the
    // viewer is not in that queue. The pending-status panel uses these so
    // a queued patient sees what they actually paid, not whatever price
    // the owner has since changed the setting to.
    applied_surgery_fee: number;
    applied_gender_fee: number;
    city_slug: string;
}



const fmt$ = (n: number) => `$${Math.round(n).toLocaleString()}`;

// Partial-reload props for queue actions: each POST redirects back here and
// Inertia re-requests only these. 'auth' (cash in the layout) and 'flash'
// (result toast) are shared props needed after every action.
const SURGERY_QUEUE_PROPS = ['in_surgery_queue', 'applied_surgery_fee', 'queue_patients', 'auth', 'flash'];
const GENDER_QUEUE_PROPS = ['in_gender_queue', 'applied_gender_fee', 'queue_patients', 'auth', 'flash'];

type Section = 'ward' | 'surgery' | 'gender' | 'roster';

const SECTIONS: { key: Section; label: string; icon: any }[] = [
    { key: 'ward', label: 'Ward', icon: Heartbeat },
    { key: 'surgery', label: 'Emergency Care', icon: Bandaids },
    { key: 'gender', label: 'Procedures', icon: GenderIntersex },
    { key: 'roster', label: 'Staff', icon: Users },
];


const QUEUE_BADGES: Record<string, { label: string; color: string; border: string; bg: string }> = {
    surgery: { label: 'Awaiting doctor', color: 'text-cyan-400', border: 'border-cyan-500/20', bg: 'bg-cyan-500/10' },
    gender: { label: 'Queued — Gender', color: 'text-violet-400', border: 'border-violet-500/20', bg: 'bg-violet-500/10' },
};

function WardPanel({ patients, queuePatients }: { patients: Patient[]; queuePatients: QueuePatient[] }) {
    if (patients.length === 0 && queuePatients.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-20 text-slate-600 gap-3">
                <Heartbeat size={28} weight="light" />
                <p className="text-[10px] font-black uppercase tracking-[0.3em]">No patients admitted</p>
            </div>
        );
    }

    return (
        <div className="p-5 space-y-2">
            {patients.map(p => (
                <div
                    key={`admitted-${p.id}`}
                    className="flex items-center gap-3 px-4 py-3 rounded-2xl bg-slate-950/50 border border-white/[0.04] hover:border-rose-500/20 transition-colors"
                >
                    <div className="w-8 h-8 rounded-xl bg-slate-800 border border-white/[0.06] overflow-hidden shrink-0 flex items-center justify-center">
                        {p.avatar_url
                            ? <img src={p.avatar_url} className="w-full h-full object-cover" alt="" />
                            : <User size={13} className="text-slate-600" />}
                    </div>
                    <p className="text-sm font-black text-white uppercase tracking-tight truncate">{p.name}</p>
                    <div className="ml-auto px-2 py-0.5 rounded-full bg-rose-500/10 border border-rose-500/20">
                        <span className="text-[9px] font-black text-rose-400 uppercase tracking-widest">Admitted</span>
                    </div>
                </div>
            ))}
            {queuePatients.map(p => {
                const badge = QUEUE_BADGES[p.queue_type];
                return (
                    <div
                        key={`queue-${p.queue_type}-${p.id}`}
                        className="flex items-center gap-3 px-4 py-3 rounded-2xl bg-slate-950/50 border border-white/[0.04] hover:border-white/10 transition-colors"
                    >
                        <div className="w-8 h-8 rounded-xl bg-slate-800 border border-white/[0.06] overflow-hidden shrink-0 flex items-center justify-center">
                            {p.avatar_url
                                ? <img src={p.avatar_url} className="w-full h-full object-cover" alt="" />
                                : <User size={13} className="text-slate-600" />}
                        </div>
                        <p className="text-sm font-black text-white uppercase tracking-tight truncate">{p.name}</p>
                        <div className={`ml-auto px-2 py-0.5 rounded-full ${badge.bg} ${badge.border} border flex items-center gap-1.5`}>
                            <span className={`w-1.5 h-1.5 rounded-full ${badge.color.replace('text-', 'bg-')} animate-pulse`} />
                            <span className={`text-[9px] font-black ${badge.color} uppercase tracking-widest`}>{badge.label}</span>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}



// `appliedFee` is the price the viewer locked in when they applied (0 if
// they're not currently queued). The pending-status row uses it so a
// queued patient sees what they actually paid, even if the owner has
// since changed the live `surgeryFee` setting.
function SurgeryPanel({ surgeryFee, appliedFee, citySlug, inQueue }: { surgeryFee: number; appliedFee: number; citySlug: string; inQueue: boolean }) {
    const [busy, setBusy] = useState(false);

    const submit = () => {
        if (busy) return;
        setBusy(true);
        router.post(
            route('city.hospital.apply-surgery', { city: citySlug }),
            {},
            // Partial reload: queue state plus shared 'auth' (cash) and 'flash'.
            { only: SURGERY_QUEUE_PROPS, preserveScroll: true, onFinish: () => setBusy(false) }
        );
    };

    const cancel = () => {
        if (busy) return;
        setBusy(true);
        router.post(
            route('city.hospital.cancel-surgery', { city: citySlug }),
            {},
            { only: SURGERY_QUEUE_PROPS, preserveScroll: true, onFinish: () => setBusy(false) }
        );
    };

    return (
        <div className="p-6 space-y-6">
            <div className="space-y-2">

                <h3 className="text-2xl font-black text-white uppercase tracking-tighter">Emergency Care</h3>
            </div>

            {inQueue ? (

                <div className="border border-cyan-800/30 rounded-2xl overflow-hidden bg-cyan-950/10">
                    <div className="flex items-center justify-between px-5 py-3.5 border-b border-white/[0.04]">
                        <div className="flex items-center gap-2">
                            <Stethoscope size={14} weight="fill" className="text-cyan-400" />
                            <span className="text-xs font-black text-cyan-400 uppercase tracking-widest">Application Pending</span>
                        </div>
                        <span className="flex items-center gap-1.5 text-[10px] font-bold text-cyan-400 bg-cyan-500/10 border border-cyan-500/20 px-2.5 py-1 rounded-full">
                            <span className="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse" />
                            In Queue
                        </span>
                    </div>
                    <div className="divide-y divide-white/[0.04]">
                        {[
                            { label: 'Procedure', value: 'Emergency Care', accent: 'text-white' },
                            { label: 'Fee', value: fmt$(appliedFee || surgeryFee), accent: 'text-white' },
                            { label: 'Status', value: 'Awaiting doctor', accent: 'text-cyan-400' },
                        ].map(r => (
                            <div key={r.label} className="px-5 py-3.5 flex justify-between items-center">
                                <span className="text-[10px] font-black text-slate-500 uppercase tracking-widest">{r.label}</span>
                                <span className={`text-sm font-black tabular-nums ${r.accent}`}>{r.value}</span>
                            </div>
                        ))}
                    </div>
                </div>
            ) : (

                <>
                    <p className="text-xs text-slate-400 leading-relaxed max-w-lg">
                        Emergency Care can help recover some of your health after an injury.
                    </p>




                </>
            )}

            <div className="bg-slate-950/30 border border-slate-800 rounded-2xl p-5 space-y-4">
                {inQueue ? (

                    <>
                        <p className="text-[10px] font-black text-slate-500 uppercase tracking-[0.3em]">Changed your mind?</p>
                        <p className="text-xs text-slate-500 leading-relaxed">
                            You can cancel your Emergency Care application at any time before a doctor attends to you.
                        </p>
                        <button
                            onClick={cancel}
                            disabled={busy}
                            className={`w-fit px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest flex items-center justify-center gap-2 transition-all ${busy
                                ? 'bg-slate-800/60 text-slate-600 cursor-not-allowed'
                                : 'bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700'
                                }`}
                        >
                            {busy
                                ? <><div className="w-3 h-3 border border-white/30 border-t-white rounded-full animate-spin" />Cancelling…</>
                                : 'Cancel Application'
                            }
                        </button>
                    </>
                ) : (

                    <>
                        <p className="text-[10px] font-black text-slate-500 uppercase tracking-[0.3em]">Apply for Emergency Care</p>
                        <p className="text-xs text-slate-500 leading-relaxed">
                            Your application will be queued. A doctor will attend to you when available.
                        </p>
                        <button
                            onClick={submit}
                            disabled={busy}
                            className={`w-fit px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest flex items-center justify-center gap-2 transition-all ${!busy
                                ? 'bg-gradient-to-r from-rose-600 to-rose-700 hover:from-rose-500 hover:to-rose-600 text-white shadow-lg shadow-rose-900/30'
                                : 'bg-slate-800/60 text-slate-600 cursor-not-allowed'
                                }`}
                        >
                            {busy
                                ? <><div className="w-3 h-3 border border-white/30 border-t-white rounded-full animate-spin" />Submitting…</>
                                : <><Stethoscope size={13} weight="bold" />Apply for Emergency Care</>
                            }
                        </button>
                    </>
                )}
            </div>
        </div>
    );
}



// `appliedFee` is the price the viewer locked in when they applied (0 if
// they're not currently queued). See SurgeryPanel for the rationale.
function GenderPanel({ genderFee, appliedFee, citySlug, inQueue }: { genderFee: number; appliedFee: number; citySlug: string; inQueue: boolean }) {
    const [busy, setBusy] = useState(false);

    const submit = () => {
        if (busy) return;
        setBusy(true);
        router.post(
            route('city.hospital.apply-gender', { city: citySlug }),
            {},
            { only: GENDER_QUEUE_PROPS, preserveScroll: true, onFinish: () => setBusy(false) }
        );
    };

    const cancel = () => {
        if (busy) return;
        setBusy(true);
        router.post(
            route('city.hospital.cancel-gender', { city: citySlug }),
            {},
            { only: GENDER_QUEUE_PROPS, preserveScroll: true, onFinish: () => setBusy(false) }
        );
    };

    return (
        <div className="p-6 space-y-6">
            <div className="space-y-2">
                <div className="flex items-center gap-2">

                </div>
                <h3 className="text-2xl font-black text-white uppercase tracking-tighter">Gender Reassignment</h3>
            </div>

            {inQueue ? (
                /* ── Pending state ──────────────────────────────── */
                <div className="border border-violet-800/30 rounded-2xl overflow-hidden bg-violet-950/10">
                    <div className="flex items-center justify-between px-5 py-3.5 border-b border-white/[0.04]">
                        <div className="flex items-center gap-2">
                            <GenderIntersex size={14} weight="fill" className="text-violet-400" />
                            <span className="text-xs font-black text-violet-400 uppercase tracking-widest">Application Pending</span>
                        </div>
                        <span className="flex items-center gap-1.5 text-[10px] font-bold text-violet-400 bg-violet-500/10 border border-violet-500/20 px-2.5 py-1 rounded-full">
                            <span className="w-1.5 h-1.5 rounded-full bg-violet-400 animate-pulse" />
                            In Queue
                        </span>
                    </div>
                    <div className="divide-y divide-white/[0.04]">
                        {[
                            { label: 'Procedure', value: 'Gender Reassignment', accent: 'text-white' },
                            { label: 'Fee', value: fmt$(appliedFee || genderFee), accent: 'text-white' },
                            { label: 'Status', value: 'Awaiting staff', accent: 'text-violet-400' },
                        ].map(r => (
                            <div key={r.label} className="px-5 py-3.5 flex justify-between items-center">
                                <span className="text-[10px] font-black text-slate-500 uppercase tracking-widest">{r.label}</span>
                                <span className={`text-sm font-black ${r.accent}`}>{r.value}</span>
                            </div>
                        ))}
                    </div>
                </div>
            ) : (
                /* ── Apply state ────────────────────────────────── */
                <>
                    <p className="text-xs text-slate-400 leading-relaxed max-w-lg">
                        Gender reassignment is a complicated  semi permanent procedure that cannot be reversed without a second detransitioning surgery
                    </p>



                </>
            )}

            <div className="bg-slate-950/30 border border-slate-800 rounded-2xl p-5 space-y-4">
                {inQueue ? (
                    /* ── Cancel ─────────────────────────────────── */
                    <>
                        <p className="text-[10px] font-black text-slate-500 uppercase tracking-[0.3em]">Changed your mind?</p>
                        <p className="text-xs text-slate-500 leading-relaxed">
                            You can cancel your application at any time before a staff member performs the procedure.
                        </p>
                        <button
                            onClick={cancel}
                            disabled={busy}
                            className={`w-fit px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest flex items-center justify-center gap-2 transition-all ${busy
                                ? 'bg-slate-800/60 text-slate-600 cursor-not-allowed'
                                : 'bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700'
                                }`}
                        >
                            {busy
                                ? <><div className="w-3 h-3 border border-white/30 border-t-white rounded-full animate-spin" />Cancelling…</>
                                : 'Cancel Application'
                            }
                        </button>
                    </>
                ) : (
                    /* ── Submit ─────────────────────────────────── */
                    <>
                        <p className="text-[10px] font-black text-slate-500 uppercase tracking-[0.3em]">Apply for Procedure</p>

                        <button
                            onClick={submit}
                            disabled={busy}
                            className={`w-fit px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest flex items-center justify-center gap-2 transition-all ${!busy
                                ? 'bg-gradient-to-r from-violet-600 to-violet-700 hover:from-violet-500 hover:to-violet-600 text-white shadow-lg shadow-violet-900/30'
                                : 'bg-slate-800/60 text-slate-600 cursor-not-allowed'
                                }`}
                        >
                            {busy
                                ? <><div className="w-3 h-3 border border-white/30 border-t-white rounded-full animate-spin" />Submitting…</>
                                : <><GenderIntersex size={13} weight="bold" />Apply for Gender Change</>
                            }
                        </button>
                    </>
                )}
            </div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────
// Staff roster panel
// ─────────────────────────────────────────────────────────────

function RosterPanel({ staff }: { staff: StaffMember[] }) {
    if (staff.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-20 text-slate-600 gap-3">
                <Users size={28} weight="light" />
                <p className="text-[10px] font-black uppercase tracking-[0.3em]">No staff assigned</p>
            </div>
        );
    }

    return (
        <div className="p-5 space-y-2">
            {staff.map(m => (
                <div
                    key={m.id}
                    className="flex items-center gap-3 px-4 py-3 rounded-2xl bg-slate-950/50 border border-white/[0.04] hover:border-white/10 transition-colors"
                >
                    <div className="w-9 h-9 rounded-xl bg-slate-800 border border-white/[0.06] overflow-hidden shrink-0 flex items-center justify-center">
                        {m.avatar_url
                            ? <img src={m.avatar_url} className="w-full h-full object-cover" alt="" />
                            : <User size={14} className="text-slate-600" />}
                    </div>
                    <div className="flex-1 min-w-0">
                        <p className="text-sm font-black text-white uppercase tracking-tight truncate">{m.name}</p>
                        <p className="text-[9px] text-slate-500 uppercase tracking-widest font-black">{m.rank_name}</p>
                    </div>
                    {m.rank_name === 'Surgeon General' && (
                        <Crown size={13} className="text-amber-400 shrink-0" weight="fill" />
                    )}
                </div>
            ))}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────
// Owner settings modal (StyledModal pattern)
// ─────────────────────────────────────────────────────────────

function OwnerSettingsModal({
    citySlug, genderFee, surgeryFee, onClose, isOpen,
}: {
    citySlug: string; genderFee: number; surgeryFee: number; onClose: () => void; isOpen: boolean;
}) {
    const [gender, setGender] = useState(genderFee);
    const [surgery, setSurgery] = useState(surgeryFee);

    // Sync sliders when parent props change (e.g. after a successful save)
    useEffect(() => { setGender(genderFee); }, [genderFee]);
    useEffect(() => { setSurgery(surgeryFee); }, [surgeryFee]);
    const [saving, setSaving] = useState(false);

    const save = () => {
        setSaving(true);
        router.post(
            route('city.hospital.settings', { city: citySlug }),
            { gender_reassignment_fee: gender, surgery_fee: surgery },
            { only: ['hospital', 'gender_fee', 'surgery_fee', 'auth', 'flash'], preserveScroll: true, onFinish: () => { setSaving(false); onClose(); } }
        );
    };

    // Fee bounds — kept in sync with HospitalController::updateSettings
    // validation rules. Step intentionally small (1k / 500) so owners can
    // tune precisely within the narrower bands.
    const RATES = [
        { label: 'Gender Reassignment Fee', min: 5_000, max: 50_000, step: 500, value: gender, set: setGender },
        { label: 'Emergency Care Fee', min: 10_000, max: 100_000, step: 1_000, value: surgery, set: setSurgery },
    ];

    return (
        <StyledModal
            isOpen={isOpen}
            onClose={onClose}
            title="Hospital Administration"
            subtitle="Manage procedure fees & revenue"
            headerIcon={
                <div className="flex items-center justify-center w-full h-full">
                    <div className="w-20 h-20 rounded-2xl bg-gradient-to-br from-rose-600 to-rose-800 flex items-center justify-center shadow-lg shadow-rose-900/40">
                        <Crown weight="fill" className="w-10 h-10 text-white" />
                    </div>
                </div>
            }
            maxWidth="max-w-sm"
        >
            <div className="px-6 pb-8 pt-2 space-y-5">
                {RATES.map(r => (
                    <div key={r.label} className="bg-slate-950/50 rounded-xl border border-slate-800 p-4 space-y-3">
                        <div className="flex items-center justify-between">
                            <span className="text-[10px] font-black text-slate-500 uppercase tracking-widest">{r.label}</span>
                            <span className="text-sm font-black text-white tabular-nums font-mono">{fmt$(r.value)}</span>
                        </div>
                        <input
                            type="range" min={r.min} max={r.max} step={r.step} value={r.value}
                            onChange={e => r.set(parseInt(e.target.value))}
                            className="w-full h-1.5 bg-slate-800 rounded-full appearance-none cursor-pointer accent-rose-500"
                        />
                        <div className="flex justify-between text-[9px] text-slate-600 font-bold uppercase tracking-wider">
                            <span>{fmt$(r.min)}</span>
                            <span>{fmt$(r.max)}</span>
                        </div>
                    </div>
                ))}

                <ActionButton
                    onClick={save}
                    disabled={saving}
                    icon={Crown}
                    variant="primary"
                    className="w-full !bg-rose-600 hover:!bg-rose-500 !text-white"
                >
                    {saving ? 'Saving…' : 'Update Rates'}
                </ActionButton>
            </div>
        </StyledModal>
    );
}

// ─────────────────────────────────────────────────────────────
// Root
// ─────────────────────────────────────────────────────────────

export default function Hospital({
    hospital, is_owner,
    patients, queue_patients, in_surgery_queue, in_gender_queue,
    staff, gender_fee, surgery_fee,
    applied_surgery_fee, applied_gender_fee,
    city_slug,
}: HospitalProps) {
    const cityName = hospital?.city ?? 'City';
    const heroImage = hospital?.image || getCityImage(cityName);
    const [activeSection, setActiveSection] = useState<Section>('ward');
    const [showSettings, setShowSettings] = useState(false);

    return (
        <>
            <Head title={`Hospital — ${cityName}`} />

            <OwnerSettingsModal
                isOpen={showSettings}
                citySlug={city_slug}
                genderFee={gender_fee}
                surgeryFee={surgery_fee}
                onClose={() => setShowSettings(false)}
            />

            {/* Owner FAB */}
            {is_owner && (
                <button
                    onClick={() => setShowSettings(true)}
                    className="fixed bottom-6 right-6 z-40 w-14 h-14 bg-gradient-to-br from-rose-600 to-rose-800 rounded-full flex items-center justify-center transition-all hover:scale-110"
                    title="Hospital Settings"
                >
                    <Crown weight="fill" className="w-6 h-6 text-white" />
                </button>
            )}

            {/* Extra bottom padding when the floating owner-settings button
                is visible — at mid breakpoints the right-rail's Fee
                Schedule card sits behind the fixed `bottom-6 right-6 z-40`
                crown button. Padding pushes the rail's last card up far
                enough that the button stops obscuring it. */}
            <div className={`max-w-6xl mx-auto px-4 sm:px-6 py-6 space-y-5 ${is_owner ? 'pb-28' : ''}`}>

                {/* ── Hero banner ────────────────────────────────── */}
                <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4 }}
                    className="relative rounded-t-2xl overflow-hidden border border-white/5 border-b-0 shadow-2xl shrink-0 min-h-[16rem] sm:min-h-[20rem] flex flex-col justify-end"
                >
                    <div
                        className="absolute inset-0 bg-cover bg-center transition-transform duration-1000"
                        style={{ backgroundImage: `url('${heroImage}')` }}
                    />
                    <div className="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-950/20 to-transparent" />

                    <div className="relative h-full p-6 sm:p-8 flex flex-col justify-end">
                        <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                            <div className="space-y-2">



                                <h1 className="text-3xl sm:text-5xl font-black text-white tracking-tighter uppercase filter drop-shadow-lg leading-none">
                                    {hospital?.name ?? 'General Hospital'}
                                </h1>

                            </div>

                            <div className="flex items-center gap-4">
                                {patients.length > 0 && (
                                    <div className="flex flex-col items-end gap-1 bg-rose-950/40 border border-rose-500/20 rounded-2xl px-5 py-2 backdrop-blur-xl">
                                        <div className="text-[8px] font-black uppercase text-rose-400/70 tracking-widest">Patients</div>
                                        <div className="text-rose-300 font-mono font-black text-lg leading-none">{patients.length}</div>
                                    </div>
                                )}
                                {/* Emergency Care fee chip removed — the Fee
                                    Schedule card in the right rail already
                                    surfaces this number, no need to
                                    duplicate it in the hero. */}
                            </div>
                        </div>
                    </div>
                </motion.div>

                {/* ── Pill tabs ─────────────────────────────────── */}
                <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 0.15, duration: 0.4 }}
                    className="flex gap-2 p-2 bg-slate-900/60 border border-white/5 rounded-2xl shadow-xl backdrop-blur-md"
                >
                    {SECTIONS.map(sec => {
                        const Icon = sec.icon;
                        const isActive = activeSection === sec.key;
                        return (
                            <button
                                key={sec.key}
                                onClick={() => setActiveSection(sec.key)}
                                className={`relative flex-1 flex items-center justify-center gap-2 py-2.5 rounded-lg text-[10px] font-black uppercase tracking-[0.15em] transition-all duration-200 ${isActive
                                    ? 'bg-rose-500/10 text-rose-400 border border-rose-500/30'
                                    : 'text-slate-500 hover:text-slate-300 border border-transparent'
                                    }`}
                            >
                                <Icon size={14} weight={isActive ? 'fill' : 'regular'} />
                                <span className="hidden sm:inline">{sec.label}</span>
                            </button>
                        );
                    })}
                </motion.div>

                {/* ── Content grid (3 + 1 sidebar) ─────────────── */}
                <div className="grid grid-cols-1 lg:grid-cols-4 gap-4 sm:gap-5">

                    {/* Main panel */}
                    <motion.div
                        key={activeSection}
                        initial={{ opacity: 0, x: -12 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ duration: 0.35 }}
                        className="col-span-1 lg:col-span-3 bg-slate-900/50 border border-slate-800/50 rounded-2xl overflow-hidden"
                    >
                        <AnimatePresence mode="wait">
                            {activeSection === 'ward' && <WardPanel patients={patients} queuePatients={queue_patients} />}
                            {activeSection === 'surgery' && <SurgeryPanel surgeryFee={surgery_fee} appliedFee={applied_surgery_fee} citySlug={city_slug} inQueue={in_surgery_queue} />}
                            {activeSection === 'gender' && <GenderPanel genderFee={gender_fee} appliedFee={applied_gender_fee} citySlug={city_slug} inQueue={in_gender_queue} />}
                            {activeSection === 'roster' && <RosterPanel staff={staff} />}
                        </AnimatePresence>
                    </motion.div>

                    {/* Sidebar */}
                    <motion.div
                        initial={{ opacity: 0, x: 16 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.25, duration: 0.4 }}
                        className="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-1 gap-4"
                    >
                        {/* Surgeon General card */}
                        <div className="bg-slate-900/60 border border-white/5 rounded-2xl p-5 relative overflow-hidden shadow-xl backdrop-blur-md">
                            <div className="relative">
                                <div className="flex items-center gap-2 mb-4">
                                    <div className="w-1 h-3 bg-rose-500 rounded-full" />
                                    <h3 className="text-[10px] font-black text-rose-400 uppercase tracking-[0.3em]">Surgeon General</h3>
                                </div>
                                {hospital?.chief_name ? (
                                    <div className="flex items-center gap-3 min-w-0">
                                        <div className="w-12 h-12 rounded-2xl bg-slate-800 border border-white/5 overflow-hidden flex items-center justify-center shrink-0 shadow-lg">
                                            {hospital.chief_avatar
                                                ? <img src={hospital.chief_avatar} className="w-full h-full object-cover" alt="" />
                                                : <FirstAidKit size={22} className="text-rose-400" weight="fill" />}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-black text-white truncate uppercase tracking-tight">{hospital.chief_name}</p>

                                        </div>
                                    </div>
                                ) : (
                                    <p className="text-xs text-slate-600 italic">Position vacant</p>
                                )}
                            </div>
                        </div>

                        {/* Owner card */}
                        <div className="bg-slate-900/60 border border-white/5 rounded-2xl p-5 relative overflow-hidden shadow-xl backdrop-blur-md">
                            <div className="relative">
                                <div className="flex items-center gap-2 mb-4">
                                    <div className="w-1 h-3 bg-amber-500 rounded-full" />
                                    <h3 className="text-[10px] font-black text-amber-400 uppercase tracking-[0.3em]">Owner</h3>
                                </div>
                                {hospital?.owner ? (
                                    <div className="flex items-center gap-3 min-w-0">
                                        <div className="w-12 h-12 rounded-2xl bg-slate-800 border border-white/5 overflow-hidden flex items-center justify-center shrink-0 shadow-lg">
                                            {hospital.owner.avatar_url
                                                ? <img src={hospital.owner.avatar_url} className="w-full h-full object-cover" alt="" />
                                                : <Crown size={22} className="text-amber-400" weight="fill" />}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-black text-white truncate uppercase tracking-tight">{hospital.owner.name}</p>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="flex items-center gap-3">
                                        <div className="w-12 h-12 rounded-2xl bg-slate-800 border border-white/5 flex items-center justify-center shrink-0">
                                            <User size={22} className="text-slate-600" />
                                        </div>
                                        <div>
                                            <p className="text-sm font-black text-slate-500 uppercase tracking-tight">Govt. Operated</p>
                                            <p className="text-[10px] text-slate-600 font-bold uppercase tracking-widest">Public institution</p>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Fee summary card */}
                        <div className="bg-slate-900/60 border border-white/5 rounded-2xl p-5 relative overflow-hidden shadow-xl backdrop-blur-md">
                            <div className="relative space-y-3">
                                <div className="flex items-center gap-2 mb-1">
                                    <div className="w-1 h-3 bg-slate-500 rounded-full" />
                                    <h3 className="text-[10px] font-black text-slate-400 uppercase tracking-[0.3em]">Fee Schedule</h3>
                                </div>
                                <div className="flex justify-between items-center">
                                    <span className="text-[10px] font-black text-slate-500 uppercase tracking-widest">Emergency Care</span>
                                    <span className="text-sm font-mono font-black text-white">{fmt$(surgery_fee)}</span>
                                </div>
                                <div className="h-px bg-slate-800" />
                                <div className="flex justify-between items-center">
                                    <span className="text-[10px] font-black text-slate-500 uppercase tracking-widest">Gender</span>
                                    <span className="text-sm font-mono font-black text-white">{fmt$(gender_fee)}</span>
                                </div>
                            </div>
                        </div>
                    </motion.div>
                </div>
            </div>
        </>
    );
}

Hospital.layout = (page: React.ReactNode) => <GameLayout wide children={page} />;
