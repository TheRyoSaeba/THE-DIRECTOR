import { useState, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { route } from 'ziggy-js';
import {
    ShieldStar, UserCircle,
    HandPalm, UsersThree, Siren,
    Star, CheckCircle, Crown,
    User, ArrowRight,
} from '@phosphor-icons/react';
import { motion, AnimatePresence } from 'framer-motion';
import GameLayout from '@/Layouts/GameLayout';
import StyledModal, { ActionButton } from '@/Layouts/styledmodal';
import { getCityImage } from '@/utils/cityImages';


interface PageProps {
    auth: { character: { timers: Record<string, number>; cleanCash: number; career: string;[key: string]: any }; serverTime: string;[key: string]: any };
    flash: { success?: string; error?: string };
    [key: string]: any;
}

interface Training { enrolled: boolean; cycles: number; requiredCycles: number; completed: boolean; nextStudyAt: number | null; }
interface Academy { isPolice: boolean; training: Training | null; }
interface Officer { displayName: string; avatarUrl: string | null; rankName: string; rankNumber: number; isOnline: boolean; }
interface CaseRecord { id: number; type: string; typeLabel: string; severity: string; status: string; committedAtUtc: string; victimName: string | null; }
interface Convict { displayName: string; avatarUrl: string | null; }
interface Leader { name: string; rank: string; since: string; avatar_url: string | null; }

interface Props {
    cityName: string;
    citySlug: string;
    commissioner: Leader | null;
    owner: { name: string; avatar_url: string | null } | null;
    leaderTitle: string;
    academy: Academy;
    roster: Officer[];
    myOpenCases: CaseRecord[];
    convicts: Convict[];
    enrollFee: number;
    isOwner: boolean;
    openCaseCount: number;
}


function canAppeal(c: CaseRecord): boolean {
    return c.status === 'sentenced' && c.severity !== 'misdemeanor';
}


const SECTIONS = [
    { key: 'academy', label: 'Academy', icon: ShieldStar },
    { key: 'roster', label: 'Roster', icon: UsersThree },
    { key: 'turnin', label: 'Turn In', icon: HandPalm },
    { key: 'convicts', label: 'Convicts', icon: Siren },
] as const;

type SectionKey = (typeof SECTIONS)[number]['key'];

const ACCENT = {
    blue: { ring: 'border-blue-500/30', text: 'text-blue-400', bg: 'bg-blue-500', bgSoft: 'bg-blue-500/10', glow: 'shadow-[0_0_40px_rgba(59,130,246,0.08)]' },
} as const;

const SEV_COLORS: Record<string, string> = {
    misdemeanor: 'text-blue-400/80 border-blue-500/20 bg-blue-500/5',
    felony: 'text-blue-400 border-blue-500/30 bg-blue-500/10',
    capital: 'text-blue-500 border-blue-500/40 bg-blue-500/20',
};

const THEME = {
    bar: 'h-[2px] w-8 bg-blue-500/50',
    title: 'text-[10px] font-black text-blue-400 uppercase tracking-[0.4em]',
    badge: 'inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-500/10 border border-blue-500/20 text-[10px] font-black text-blue-400 tabular-nums',
    card: 'group relative h-[26rem] rounded-3xl overflow-hidden border border-white/10 bg-gradient-to-br from-slate-950 to-slate-900 shadow-2xl transition-all duration-500 hover:scale-[1.02] hover:shadow-[0_20px_50px_rgba(0,0,0,0.5)] flex flex-col',
    btnPrimary: 'w-full py-4 rounded-xl font-black text-xs uppercase tracking-widest text-white flex items-center justify-center gap-2 transition-all hover:scale-[1.02] shadow-lg',
    btnSecondary: 'w-full py-4 rounded-xl font-black text-xs uppercase tracking-widest text-white flex items-center justify-center gap-2 transition-all hover:bg-white/10 border border-white/20 group-hover:border-white/40 shadow-xl',
};

const fmt$ = (n: number) => '$' + n.toLocaleString();

function formatTime(s: number) {
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    if (h > 0) return `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
    return `${m}:${String(sec).padStart(2, '0')}`;
}


function useCountdown(target: number | null | undefined, serverTime: string | undefined) {
    const [remaining, setRemaining] = useState(0);
    useEffect(() => {
        if (!target || !serverTime) { setRemaining(0); return; }
        const clock = Math.floor(new Date(serverTime).getTime() / 1000);
        const left = Math.max(0, target - clock);
        setRemaining(left);
        if (left <= 0) return;
        const iv = setInterval(() => setRemaining(p => { if (p <= 1) { clearInterval(iv); return 0; } return p - 1; }), 1000);
        return () => clearInterval(iv);
    }, [target, serverTime]);
    return remaining;
}


function OwnerSettingsModal({ citySlug, enrollFee, onClose, isOpen }: { citySlug: string; enrollFee: number; onClose: () => void; isOpen: boolean }) {
    const [fee, setFee] = useState(enrollFee);
    const [processing, setProcessing] = useState(false);

    const handleSave = () => {
        if (processing) return;
        setProcessing(true);
        router.post(route('city.police.settings', { city: citySlug }), { enroll_fee: fee }, {
            preserveScroll: true,
            onFinish: () => { setProcessing(false); onClose(); },
        });
    };

    return (
        <StyledModal
            isOpen={isOpen}
            onClose={onClose}
            title="Precinct Command"
            subtitle="Enrollment fee & revenue operations"
            headerIcon={
                <div className="flex items-center justify-center w-full h-full">
                    <div className="w-20 h-20 rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 flex items-center justify-center shadow-lg shadow-blue-900/40">
                        <Crown weight="fill" className="w-10 h-10 text-white" />
                    </div>
                </div>
            }
            maxWidth="max-w-sm"
        >
            <div className="px-6 pb-8 pt-2 space-y-5">
                <div className="bg-slate-950/50 rounded-xl border border-slate-800 p-4 space-y-3">
                    <div className="flex items-center justify-between">
                        <span className="text-[10px] font-black text-slate-500 uppercase tracking-widest">Enrollment Fee</span>
                        <span className="text-sm font-black text-white tabular-nums font-mono">${fee.toLocaleString()}</span>
                    </div>
                    <input
                        type="range" min="1000" max="10000" step="500"
                        value={fee}
                        onChange={e => setFee(Number(e.target.value))}
                        className="w-full h-1.5 bg-slate-800 rounded-full appearance-none cursor-pointer accent-blue-500"
                    />
                    <div className="flex justify-between text-[9px] text-slate-600 font-bold uppercase tracking-wider">
                        <span>Min $1,000</span>
                        <span>Max $10,000</span>
                    </div>
                </div>

                <ActionButton
                    onClick={handleSave}
                    disabled={processing}
                    icon={Crown}
                    variant="primary"
                    className="w-full !bg-blue-600 hover:!bg-blue-500 !text-white"
                >
                    {processing ? 'Saving…' : 'Update Operations'}
                </ActionButton>
            </div>
        </StyledModal>
    );
}


export default function PoliceHq({
    cityName, citySlug, commissioner, owner, leaderTitle,
    academy, roster, myOpenCases, convicts, enrollFee, isOwner,
    openCaseCount,
}: Props) {
    const { auth } = usePage<PageProps>().props;
    const precinctImage = getCityImage(cityName);
    const serverTime = auth?.serverTime;

    const [activeSection, setActiveSection] = useState<SectionKey>('academy');
    const [processing, setProcessing] = useState(false);
    const [showSettings, setShowSettings] = useState(false);

    const trainCountdown = useCountdown(academy.training?.nextStudyAt, serverTime);
    const activeAccent = ACCENT.blue;

    const handleEnroll = () => { if (processing) return; setProcessing(true); router.post(route('city.police.enroll', { city: citySlug }), {}, { only: ['auth', 'flash', 'academy'], preserveScroll: true, onFinish: () => setProcessing(false) }); };
    const handleTrain = () => { if (processing || trainCountdown > 0) return; setProcessing(true); router.post(route('city.police.train', { city: citySlug }), {}, { only: ['auth', 'flash', 'academy'], preserveScroll: true, onFinish: () => setProcessing(false) }); };
    const handleGrad = () => { if (processing) return; setProcessing(true); router.post(route('city.police.graduate', { city: citySlug }), {}, { preserveScroll: true, onFinish: () => setProcessing(false) }); };
    const handleTurnIn = (id: number) => { if (processing) return; setProcessing(true); router.post(route('city.police.turn-in', { city: citySlug }), { case_id: id }, { preserveScroll: true, onFinish: () => setProcessing(false) }); };

    const handleAppeal = (id: number) => { if (processing) return; setProcessing(true); router.post(route('law.appeal', id), {}, { preserveScroll: true, onFinish: () => setProcessing(false) }); };

    return (
        <>
            <Head title={`Police HQ — ${cityName}`} />

            <OwnerSettingsModal
                isOpen={showSettings}
                citySlug={citySlug}
                enrollFee={enrollFee}
                onClose={() => setShowSettings(false)}
            />

            { }
            {isOwner && (
                <button
                    onClick={() => setShowSettings(true)}
                    className="fixed bottom-6 right-6 z-40 w-14 h-14 bg-gradient-to-br from-blue-600 to-cyan-600 rounded-full flex items-center justify-center shadow-[0_0_30px_rgba(59,130,246,0.4)] hover:shadow-[0_0_50px_rgba(59,130,246,0.6)] transition-all hover:scale-110"
                    title="Precinct Settings"
                >
                    <Crown weight="fill" className="w-6 h-6 text-white" />
                </button>
            )}

            <div className="max-w-6xl mx-auto px-4 sm:px-6 py-6 space-y-5">

                { }
                <motion.div
                    initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.5 }}
                    className="relative rounded-[2rem] overflow-hidden border border-slate-800 shadow-2xl bg-slate-900 group h-64 sm:h-80"
                >
                    <div
                        className="absolute inset-0 bg-cover bg-center transition-transform duration-1000 group-hover:scale-105"
                        style={{ backgroundImage: `url('${precinctImage}')` }}
                    />
                    {/* Light gradient matched with University.tsx */}
                    <div className="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-950/20 to-transparent" />


                    <div className="relative h-full p-6 sm:p-8 flex flex-col justify-end">
                        <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                            <div className="space-y-2">
                                <div className="flex items-center gap-3">
                                    <div className="h-[2px] w-8 bg-blue-500/50 shadow-sm" />

                                </div>
                                <h1 className="text-3xl sm:text-5xl font-black text-white tracking-tighter uppercase filter drop-shadow-lg leading-none">
                                    Police Headquarters
                                </h1>

                            </div>

                            <div className="flex items-center gap-4">
                                <div className="flex flex-col items-end gap-1 bg-blue-950/40 border border-blue-500/20 rounded-2xl px-5 py-2 backdrop-blur-xl">
                                    <div className="text-[8px] font-black uppercase text-blue-400/70 tracking-widest">Enrollment Fee</div>
                                    <div className="text-blue-300 font-mono font-black text-lg leading-none">
                                        {fmt$(enrollFee)}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </motion.div>

                { }
                <motion.div
                    initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.15, duration: 0.4 }}
                    className="flex gap-2 p-2 bg-slate-900/60 border border-white/5 rounded-2xl shadow-xl backdrop-blur-md"
                >
                    {SECTIONS.map(sec => {
                        const Icon = sec.icon;
                        const isActive = activeSection === sec.key;
                        const a = ACCENT.blue;
                        return (
                            <button key={sec.key} onClick={() => setActiveSection(sec.key)}
                                className={`relative flex-1 flex items-center justify-center gap-2 py-2.5 rounded-lg text-[10px] font-black uppercase tracking-[0.15em] transition-all duration-200 ${isActive ? `${a.bgSoft} ${a.text} border ${a.ring}` : 'text-slate-500 hover:text-slate-300 border border-transparent'
                                    }`}
                            >
                                <Icon size={14} weight={isActive ? 'fill' : 'regular'} />
                                <span className="hidden sm:inline">{sec.label}</span>
                            </button>
                        );
                    })}
                </motion.div>

                { }
                <div className="grid lg:grid-cols-4 gap-5">

                    { /* Academy stacks bare horizontal cards (no panel chrome) so future
                         tracks like SWAT or Cybersecurity can sit alongside Police Academy
                         without nesting boxes inside boxes. Other tabs keep the panel
                         chrome since their content is a list/grid that needs a container. */ }
                    <motion.div
                        key={activeSection}
                        initial={{ opacity: 0, x: -12 }} animate={{ opacity: 1, x: 0 }} transition={{ duration: 0.35 }}
                        className={`lg:col-span-3 ${activeSection === 'academy' ? '' : `bg-slate-900/50 border border-slate-800/50 rounded-2xl overflow-hidden ${ACCENT.blue.glow}`}`}
                    >
                        <AnimatePresence mode="wait">
                            {activeSection === 'academy' && <AcademyPanel academy={academy} processing={processing} trainCountdown={trainCountdown} onEnroll={handleEnroll} onTrain={handleTrain} onGraduate={handleGrad} />}
                            {activeSection === 'roster' && <RosterPanel roster={roster} />}
                            {activeSection === 'turnin' && <TurnInPanel cases={myOpenCases} processing={processing} onTurnIn={handleTurnIn} onAppeal={handleAppeal} />}
                            {activeSection === 'convicts' && <ConvictsPanel convicts={convicts} />}
                        </AnimatePresence>
                    </motion.div>

                    { }
                    <motion.div
                        initial={{ opacity: 0, x: 16 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.25, duration: 0.4 }}
                        className="space-y-4"
                    >
                        { }
                        <div className="bg-slate-900/60 border border-white/5 rounded-[2rem] p-6 relative overflow-hidden shadow-xl backdrop-blur-md">
                            <div className="absolute top-0 right-0 w-32 h-32 bg-blue-500/5 rounded-full -mr-16 -mt-16 blur-2xl" />
                            <div className="relative">
                                <div className="flex items-center gap-2 mb-4">
                                    <div className="w-1 h-3 bg-blue-500 rounded-full" />
                                    <h3 className="text-[10px] font-black text-blue-400 uppercase tracking-[0.3em]">{leaderTitle}</h3>
                                </div>
                                {commissioner ? (
                                    <div className="flex items-center gap-3 min-w-0">
                                        <div className="w-12 h-12 rounded-2xl bg-slate-800 border border-white/5 overflow-hidden flex items-center justify-center shrink-0 shadow-lg">
                                            {commissioner.avatar_url
                                                ? <img src={commissioner.avatar_url} className="w-full h-full object-cover" alt="" />
                                                : <ShieldStar size={22} className="text-blue-400" weight="fill" />}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-black text-white truncate uppercase tracking-tight">{commissioner.name}</p>

                                            <p className="text-[9px] text-slate-500 mt-1 font-bold uppercase">Since {commissioner.since}</p>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="flex items-center gap-4 opacity-50">
                                        <div className="w-14 h-14 rounded-2xl bg-slate-800/50 border border-white/5 flex items-center justify-center shrink-0">
                                            <User size={24} className="text-slate-600" />
                                        </div>
                                        <div>
                                            <p className="text-sm font-black text-slate-500 uppercase tracking-tight">Vacant</p>
                                            <p className="text-[10px] text-slate-600 uppercase font-bold tracking-widest">Empty</p>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>

                        { }
                        <div className="bg-slate-900/60 border border-white/5 rounded-[2rem] p-6 shadow-xl backdrop-blur-md">
                            <div className="flex items-center gap-2 mb-4">
                                <div className="w-1 h-3 bg-slate-600 rounded-full" />
                                <h3 className="text-[10px] font-black text-slate-400 uppercase tracking-[0.3em]">Owner</h3>
                            </div>
                            <div className="flex items-center gap-3 min-w-0">
                                <div className="w-12 h-12 rounded-2xl bg-slate-800/50 border border-white/5 overflow-hidden flex items-center justify-center shrink-0 shadow-lg">
                                    {owner?.avatar_url
                                        ? <img src={owner.avatar_url} className="w-full h-full object-cover" alt="" />
                                        : <User size={22} className="text-slate-600" />}
                                </div>
                                {owner ? (
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-black text-white uppercase tracking-tight truncate">{owner.name}</p>

                                    </div>
                                ) : (
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-black text-slate-500 uppercase tracking-tight">Govt. Operated</p>
                                        <p className="text-[10px] text-slate-600 font-bold uppercase tracking-widest">Public institution</p>
                                    </div>
                                )}
                            </div>
                        </div>

                        { }
                        <div className="bg-slate-900/60 border border-white/5 rounded-[2rem] p-6 shadow-xl backdrop-blur-md">
                            <div className="flex items-center gap-2 mb-4">
                                <div className="w-1 h-3 bg-blue-500 rounded-full" />
                                <h3 className="text-[10px] font-black text-slate-400 uppercase tracking-[0.3em]">Precinct Stats</h3>
                            </div>
                            <div className="space-y-4">
                                {[
                                    { label: 'Officers', value: roster.length, color: 'text-white' },
                                    { label: 'Inmates', value: convicts.length, color: 'text-white' },
                                    { label: 'Open Cases', value: openCaseCount, color: 'text-white' },
                                ].map((row, i) => (
                                    <div key={i}>
                                        {i > 0 && <div className="h-px bg-white/5 mb-4" />}
                                        <div className="flex justify-between items-center">
                                            <span className="text-[10px] text-slate-500 font-black uppercase tracking-[0.2em]">{row.label}</span>
                                            <span className={`text-base font-black tabular-nums ${row.color}`}>{row.value}</span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </motion.div>
                </div>
            </div>
        </>
    );
}


function AcademyPanel({ academy, processing, trainCountdown, onEnroll, onTrain, onGraduate }: {
    academy: Academy; processing: boolean; trainCountdown: number;
    onEnroll: () => void; onTrain: () => void; onGraduate: () => void;
}) {
    const { training, isPolice } = academy;
    const progress = training
        ? Math.min(Math.round((training.cycles / training.requiredCycles) * 100), 100)
        : 0;

    // Resolve the current state into a single set of label/sublabel/handler
    // so the markup below stays linear and reads top-to-bottom.
    let title: string, sub: string, click: (() => void) | undefined, isLocked = false;
    if (isPolice) {
        title = 'Active Duty';
        sub = 'You are sworn in and serving on the force.';
        isLocked = true;
    } else if (training?.completed) {
        title = 'Graduate Academy';
        sub = 'Training complete — report for duty.';
        click = onGraduate;
    } else if (training?.enrolled) {
        title = trainCountdown > 0 ? formatTime(trainCountdown) : 'Train Session';
        sub = `${training.cycles}/${training.requiredCycles} sessions complete`;
        click = trainCountdown > 0 ? undefined : onTrain;
        isLocked = trainCountdown > 0;
    } else {
        title = 'Enroll';
        sub = 'Begin Police Academy training';
        click = onEnroll;
    }

    // Mirrors City.tsx Service link markup token-for-token. Each track is a
    // fixed-height horizontal bar; future SWAT / Cybersecurity tracks become
    // siblings in `space-y-3` and stack underneath without changing the shape.
    return (
        <motion.div
            key="academy"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            className="space-y-3"
        >
            <button
                onClick={click}
                disabled={isLocked || processing || !click}
                className="group relative block w-full h-28 md:h-32 rounded-xl overflow-hidden border border-white/5 hover:border-blue-500/30 transition-all duration-300 disabled:cursor-not-allowed disabled:hover:border-white/5"
            >
                <img
                    src="https://images.thedirector.app/businesses/policehat.png"
                    alt=""
                    className="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover:scale-105 group-disabled:group-hover:scale-100"
                    style={{ filter: 'brightness(0.8) contrast(1.1) saturate(1.2)' }}
                />
                <div className="absolute inset-0 bg-gradient-to-r from-transparent via-slate-950/30 to-slate-950/80" />
                <div className="absolute inset-0 bg-gradient-to-t from-slate-950/20 to-transparent" />
                {!isLocked && (
                    <div className="absolute inset-0 bg-blue-500/0 group-hover:bg-blue-500/5 transition-colors duration-300" />
                )}

                <div className="relative h-full flex items-center justify-end px-6 md:px-10">
                    <div className="text-right max-w-sm">
                        <h3 className="text-xl md:text-2xl font-light text-white tracking-wide group-hover:text-blue-50 transition-colors">
                            {title}
                        </h3>
                        <p className="text-xs text-slate-400 mt-1 leading-relaxed group-hover:text-slate-300 transition-colors line-clamp-2">
                            {sub}
                        </p>
                    </div>
                    {!isLocked && click && (
                        <ArrowRight className="w-5 h-5 text-slate-600 group-hover:text-blue-400 ml-4 shrink-0 transition-all duration-300 group-hover:translate-x-1" />
                    )}
                </div>
            </button>

            {training?.enrolled && !training.completed && (
                <div className="px-1">

                </div>
            )}
        </motion.div>
    );
}


function RosterPanel({ roster }: { roster: Officer[] }) {
    return (
        <motion.div key="roster" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="p-6">
            <div className="flex items-center gap-3 mb-6">

                <div className="ml-auto">
                    <span className={THEME.badge}>{roster.length} Force Total</span>
                </div>
            </div>
            {roster.length === 0 ? (
                <div className="text-center py-12">
                    <UsersThree size={36} className="text-slate-700 mx-auto mb-3" />
                    <p className="text-xs text-slate-500 font-bold uppercase tracking-wider">No officers stationed in this precinct.</p>
                </div>
            ) : (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    {roster.map((officer, i) => (
                        <motion.div key={officer.displayName} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.03, duration: 0.25 }}
                            className="flex items-center gap-3 px-4 py-3 bg-slate-950/40 border border-white/5 rounded-2xl hover:border-blue-500/30 transition-all duration-300 group"
                        >
                            <div className="relative shrink-0">
                                <div className="w-12 h-12 rounded-xl bg-slate-900 border border-slate-800 overflow-hidden flex items-center justify-center group-hover:border-blue-500/30 transition-colors">
                                    {officer.avatarUrl ? <img src={officer.avatarUrl} className="w-full h-full object-cover" alt="" /> : <UserCircle size={20} className="text-slate-600" />}
                                </div>
                                {officer.isOnline && <span className="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-emerald-500 border-2 border-slate-950 rounded-full shadow-[0_0_10px_rgba(16,185,129,0.4)]" />}
                            </div>
                            <div className="flex-1 min-w-0">
                                <p className="text-xs font-black text-white truncate group-hover:text-blue-50 transition-colors uppercase tracking-tight">{officer.displayName}</p>
                                <div className="flex items-center gap-1.5 mt-1">
                                    <div className="flex items-center gap-0.5">
                                        {Array.from({ length: officer.rankNumber }).map((_, s) => <Star key={s} size={7} weight="fill" className="text-blue-400/50" />)}
                                    </div>
                                    <span className="text-[9px] text-slate-500 font-black uppercase tracking-widest ml-1">{officer.rankName}</span>
                                </div>
                            </div>
                        </motion.div>
                    ))}
                </div>
            )}
        </motion.div>
    );
}


function TurnInPanel({ cases, processing, onTurnIn, onAppeal }: {
    cases: CaseRecord[];
    processing: boolean;
    onTurnIn: (id: number) => void;
    onAppeal: (id: number) => void;
}) {
    const [page, setPage] = useState(1);
    const perPage = 4;
    const totalPages = Math.ceil(cases.length / perPage);
    const start = (page - 1) * perPage;
    const paginatedCases = cases.slice(start, start + perPage);

    return (
        <motion.div key="turnin" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="p-6">
            <div className="flex items-center gap-3 mb-2">

            </div>

            {cases.length === 0 ? (
                <div className="text-center py-12">
                    <CheckCircle size={36} className="text-emerald-500/40 mx-auto mb-3" />
                    <p className="text-xs font-black text-emerald-400 uppercase tracking-widest">Clean Record</p>
                    <p className="text-[10px] text-slate-500 mt-1 uppercase font-bold">You have no outstanding cases.</p>
                </div>
            ) : (
                <div className="space-y-4">
                    <div className="space-y-3">
                        {paginatedCases.map((c, i) => (
                            <motion.div
                                key={c.id}
                                initial={{ opacity: 0, x: -8 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: i * 0.05, duration: 0.3 }}
                                className="flex items-center gap-4 p-4 bg-slate-950/40 border border-white/5 rounded-2xl hover:border-blue-500/30 transition-all duration-300"
                            >
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2 flex-wrap mb-1">
                                        <span className="text-xs font-black text-white tabular-nums">#{c.id}</span>
                                        <span className="text-[10px] font-black text-blue-400 uppercase tracking-wider">{c.typeLabel}</span>
                                        <span className={`text-[9px] font-black uppercase px-2 py-0.5 rounded-full border ${SEV_COLORS[c.severity] || 'text-slate-400 border-slate-700 bg-slate-800'}`}>
                                            {c.severity}
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span className="text-[9px] text-slate-500 font-black uppercase tracking-widest">{c.committedAtUtc}</span>
                                        {c.victimName && (
                                            <span className="text-[9px] text-slate-600 font-bold uppercase tracking-tighter shrink-0">vs. {c.victimName}</span>
                                        )}
                                    </div>
                                </div>

                                { }
                                {canAppeal(c) && (
                                    <button
                                        onClick={() => onAppeal(c.id)}
                                        disabled={processing}
                                        title="File an appeal — a Chief Justice will review the conviction"
                                        className="shrink-0 h-10 px-4 rounded-xl text-xs font-black uppercase tracking-widest
                                                   bg-yellow-500/10 border border-yellow-500/30 text-yellow-400
                                                   hover:bg-yellow-500/20 transition-all active:scale-95 disabled:opacity-50"
                                    >
                                        Appeal
                                    </button>
                                )}

                                { }
                                {c.status === 'sentenced' && (
                                    <button
                                        onClick={() => onTurnIn(c.id)}
                                        disabled={processing}
                                        className="shrink-0 h-10 px-5 rounded-xl text-xs font-black uppercase tracking-widest
                                                   bg-blue-500/10 border border-blue-500/30 text-blue-400
                                                   hover:bg-blue-500/20 transition-all active:scale-95 disabled:opacity-50"
                                    >
                                        Surrender
                                    </button>
                                )}

                                { }
                                {c.status !== 'sentenced' && (
                                    <span className="shrink-0 text-[10px] font-black uppercase tracking-widest text-slate-600 px-3">
                                        {c.status}
                                    </span>
                                )}
                            </motion.div>
                        ))}
                    </div>

                    {totalPages > 1 && (
                        <div className="flex items-center justify-between pt-4 border-t border-white/5">
                            <span className="text-[10px] font-black text-slate-500 uppercase tracking-widest">Page {page} of {totalPages}</span>
                            <div className="flex gap-2">
                                <button
                                    onClick={() => setPage(p => Math.max(1, p - 1))}
                                    disabled={page === 1}
                                    className="px-4 py-2 rounded-lg bg-slate-950/50 border border-white/5 text-[10px] font-black text-slate-400 uppercase tracking-widest hover:text-white disabled:opacity-30 transition-colors"
                                >Prev</button>
                                <button
                                    onClick={() => setPage(p => Math.min(totalPages, p + 1))}
                                    disabled={page === totalPages}
                                    className="px-4 py-2 rounded-lg bg-slate-950/50 border border-white/5 text-[10px] font-black text-slate-400 uppercase tracking-widest hover:text-white disabled:opacity-30 transition-colors"
                                >Next</button>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </motion.div>
    );
}


function ConvictsPanel({ convicts }: { convicts: Convict[] }) {
    return (
        <motion.div key="convicts" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="p-6">
            <div className="flex items-center gap-3 mb-5">

                <div className="ml-auto">
                    <span className={THEME.badge}>{convicts.length} inmates</span>
                </div>
            </div>
            {convicts.length === 0 ? (
                <div className="text-center py-12">
                    <Siren size={36} className="text-slate-700 mx-auto mb-3" />
                    <p className="text-xs text-slate-500 font-bold">No inmates currently held.</p>
                </div>
            ) : (
                <div className="space-y-2">
                    {convicts.map((c, i) => <ConvictRow key={`${c.displayName}-${i}`} convict={c} index={i} />)}
                </div>
            )}
        </motion.div>
    );
}

function ConvictRow({ convict, index }: { convict: Convict; index: number }) {
    return (
        <motion.div initial={{ opacity: 0, x: -8 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: index * 0.03, duration: 0.25 }}
            className="flex items-center gap-3 px-4 py-3 bg-slate-950/40 border border-white/5 rounded-2xl hover:border-blue-500/30 transition-all duration-300"
        >
            <div className="w-12 h-12 rounded-xl bg-slate-900 border border-slate-800 overflow-hidden flex items-center justify-center shrink-0">
                {convict.avatarUrl ? <img src={convict.avatarUrl} className="w-full h-full object-cover opacity-60" alt="" /> : <UserCircle size={20} className="text-slate-600" />}
            </div>
            <div className="flex-1 min-w-0">
                <p className="text-xs font-black text-white truncate uppercase tracking-tight">{convict.displayName}</p>
            </div>
        </motion.div>
    );
}

PoliceHq.layout = (page: React.ReactNode) => <GameLayout children={page} />;
