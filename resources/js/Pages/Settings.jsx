import { useState, useEffect } from 'react';
import { useForm, usePage, router } from '@inertiajs/react';
import GameLayout from '@/Layouts/GameLayout';
import { Head } from '@inertiajs/react';
import {
    Lock, User, FloppyDisk, Warning, Briefcase,
    GraduationCap, Skull, Bell, Image as ImageIcon,
    BookOpen, CurrencyDollar, Sword,
    Star, Scales, Detective, CaretLeft, CaretRight,
} from '@phosphor-icons/react';
import Wardrobe from './Profile/Partials/Wardrobe';
import { AnimatePresence, motion } from 'framer-motion';
import Destroyed from '@/Components/Destroyed';
const DestroyedModal = Destroyed;

const fmt$ = (n) => `${Math.round(n ?? 0).toLocaleString()}`;
const fmtNum = (n) => Math.round(n ?? 0).toLocaleString();

function StatRow({ label, value, accent = false }) {
    return (
        <div className={`flex items-center justify-between px-3 py-2 border-b border-white/[0.04] last:border-0 ${accent ? 'bg-cyan-500/5' : 'hover:bg-white/[0.02]'} transition-colors`}>
            <span className="text-xs text-slate-400">{label}</span>
            <span className={`text-xs font-semibold tabular-nums ${accent ? 'text-cyan-300' : 'text-slate-200'}`}>{value}</span>
        </div>
    );
}

function StatSection({ title, icon: Icon, iconColor = 'text-slate-400', children }) {
    return (
        <div className="rounded-xl border border-white/[0.06] flex flex-col">
            <div className="flex items-center gap-2 px-3 py-2.5 bg-slate-800/60 border-b border-white/[0.06] shrink-0">
                {Icon && <Icon size={13} weight="bold" className={iconColor} />}
                <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">{title}</span>
            </div>
            <div className="bg-slate-900/40">{children}</div>
        </div>
    );
}

function GroupedEntry({ label, meta = [] }) {
    return (
        <div className="border-b border-white/[0.04] last:border-0">
            <div className="flex items-center justify-between px-3 py-2 bg-white/[0.02]">
                <span className="text-xs font-semibold text-slate-200">{label}</span>
            </div>
            {meta.map(({ key, value }, i) => (
                <div key={i} className="flex items-center justify-between pl-6 pr-3 py-1.5 border-t border-white/[0.03]">
                    <span className="text-[10px] text-slate-500">{key}</span>
                    <span className="text-[10px] text-slate-400 tabular-nums">{value}</span>
                </div>
            ))}
        </div>
    );
}

function ComingSoonSection({ title, icon: Icon, iconColor }) {
    return (
        <div className="rounded-xl border border-white/[0.06] overflow-hidden opacity-50">
            <div className="flex items-center gap-2 px-3 py-2.5 bg-slate-800/60 border-b border-white/[0.06]">
                {Icon && <Icon size={13} weight="bold" className={iconColor} />}
                <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">{title}</span>
                <span className="ml-auto text-[9px] text-slate-600 uppercase tracking-widest">Coming soon</span>
            </div>
            <div className="bg-slate-900/40 px-3 py-3">
                <p className="text-[10px] text-slate-600 italic">Statistics will appear here once history tracking is active.</p>
            </div>
        </div>
    );
}

const dangerTones = {
    career: {
        ring: 'border-amber-500/20',
        hover: 'hover:bg-amber-500/[0.04]',
        active: 'bg-amber-500/[0.045] border-amber-500/35',
        text: 'text-amber-300',
        rail: 'bg-amber-400',
        button: 'bg-amber-600 text-white hover:bg-amber-500',
    },
    study: {
        ring: 'border-purple-500/20',
        hover: 'hover:bg-purple-500/[0.04]',
        active: 'bg-purple-500/[0.045] border-purple-500/35',
        text: 'text-purple-300',
        rail: 'bg-purple-400',
        button: 'bg-purple-600 text-white hover:bg-purple-500',
    },
    life: {
        ring: 'border-red-500/25',
        hover: 'hover:bg-red-500/[0.05]',
        active: 'bg-red-500/[0.055] border-red-500/40',
        text: 'text-red-300',
        rail: 'bg-red-500',
        button: 'bg-red-700 text-white hover:bg-red-600',
    },
};

function DangerActionRow({ active, tone = 'career', title, subtitle, onClick, children }) {
    const theme = dangerTones[tone];

    return (
        <div className={`relative border-t border-slate-800/70 first:border-t-0 ${active ? theme.active : 'border-transparent'} transition-colors`}>
            {active && <div className={`absolute left-0 top-0 h-full w-0.5 ${theme.rail}`} />}
            <button
                type="button"
                onClick={onClick}
                aria-expanded={active}
                className={`w-full px-4 py-3.5 text-left transition ${theme.hover}`}
            >
                <div className="flex items-center gap-3">
                    <span className="min-w-0 flex-1">
                        <span className="block text-[11px] font-black uppercase tracking-[0.18em] text-slate-100">{title}</span>
                        <span className="mt-0.5 block truncate text-[11px] text-slate-500">{subtitle}</span>
                    </span>
                    <CaretRight
                        size={15}
                        weight="bold"
                        className={`shrink-0 text-slate-600 transition-transform ${active ? 'rotate-90 ' + theme.text : ''}`}
                    />
                </div>
            </button>
            <AnimatePresence initial={false}>
                {active && (
                    <motion.div
                        initial={{ opacity: 0, y: -4 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: -4 }}
                        transition={{ duration: 0.16, ease: 'easeOut' }}
                        className="px-4 pb-4 sm:pl-16"
                    >
                        <div className={`rounded-xl border ${theme.ring} bg-slate-950/65 p-4`}>
                            {children}
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}

function DangerButton({ tone = 'career', variant = 'primary', className = '', children, ...props }) {
    const theme = dangerTones[tone];
    const classes = variant === 'primary'
        ? theme.button
        : 'border border-slate-700/70 bg-slate-900/70 text-slate-300 hover:border-slate-500 hover:bg-slate-800';

    return (
        <button
            type="button"
            className={`rounded-lg px-4 py-2 text-[10px] font-black uppercase tracking-[0.18em] transition disabled:cursor-not-allowed disabled:opacity-50 ${classes} ${className}`}
            {...props}
        >
            {children}
        </button>
    );
}

function DangerInput({ label, error, ...props }) {
    return (
        <label className="block">
            <span className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-500">{label}</span>
            <input
                className="mt-2 w-full rounded-lg border border-slate-700/70 bg-slate-950 px-3 py-2.5 text-sm font-semibold text-white outline-none transition placeholder:text-slate-700 focus:border-slate-400"
                {...props}
            />
            {error && <span className="mt-2 block text-xs text-red-400">{error}</span>}
        </label>
    );
}

export default function Settings() {
    const { settings, flash, catalogItems, characterItems, citySlug, character_stats, property_condition } = usePage().props;
    const [activeTab, setActiveTab] = useState('account');
    const [recordPage, setRecordPage] = useState(0);
    const [cardFlipSaving, setCardFlipSaving] = useState(false);
    const [emailOptOutSaving, setEmailOptOutSaving] = useState(false);

    // Tracks whether the destroyed-property notification has been dismissed this
    // session. It auto-shows once when the user first enters the Life tab with a
    // destroyed property so they know stashing is unavailable — but the rest of
    // the tab (equip, unequip, danger zone) remains fully interactive.
    const [destroyedNoticeDismissed, setDestroyedNoticeDismissed] = useState(false);

    const isPropertyDestroyed = property_condition === 'DESTROYED';
    const showDestroyedNotice = isPropertyDestroyed && activeTab === 'life' && !destroyedNoticeDismissed;

    const ucfirst = (str) => str ? str.charAt(0).toUpperCase() + str.slice(1).toLowerCase() : '';

    const { data: appearanceData, setData: setAppearanceData, put: updateAppearance, processing: updatingAppearance, errors: appearanceErrors } = useForm({
        avatar_url: settings?.avatarUrl || '',
        glow_color: settings?.glowColor || 'cyan',
        hide_achievements: settings?.hideAchievements || false,
        disable_card_flip: settings?.disableCardFlip || false,
        email_opt_out: settings?.emailOptOut || false,
    });

    const { data: quitData, setData: setQuitData, post: postQuit, processing: quitting, errors: quitErrors, reset: resetQuit } = useForm({
        confirmation_name: '',
        degree_code: '',
    });

    const characterDegrees = usePage().props.degrees || {};
    const activeStudies = Object.entries(characterDegrees)
        .map(([code, data]) => [String(code ?? '').trim(), data])
        .filter(([code, data]) => code !== '' && !data?.completed_at);

    const [activeDangerAction, setActiveDangerAction] = useState(null);
    const [selectedDegree, setSelectedDegree] = useState(null);
    const [confirmStep, setConfirmStep] = useState(0);

    const [avatarPreview, setAvatarPreview] = useState(settings?.avatarUrl || '');
    const [isDragging, setIsDragging] = useState(false);


    // Cleanup blob URLs to prevent memory leaks
    useEffect(() => {
        return () => {
            if (avatarPreview && avatarPreview.startsWith('blob:')) {
                URL.revokeObjectURL(avatarPreview);
            }
        };
    }, [avatarPreview]);

    // Update submit handler
    const handleAppearanceSubmit = (e) => {
        e.preventDefault();
        updateAppearance('/settings/appearance', {
            preserveScroll: true,
            only: ['settings', 'auth', 'flash'],
        });
    };

    const toggleCardFlipPreference = () => {
        if (cardFlipSaving) {
            return;
        }

        const nextValue = !appearanceData.disable_card_flip;
        setAppearanceData('disable_card_flip', nextValue);
        setCardFlipSaving(true);

        router.put('/settings/appearance', {
            ...appearanceData,
            disable_card_flip: nextValue,
        }, {
            preserveScroll: true,
            only: ['settings', 'auth', 'flash'],
            onError: () => setAppearanceData('disable_card_flip', !nextValue),
            onFinish: () => setCardFlipSaving(false),
        });
    };

    const toggleEmailOptOutPreference = () => {
        if (emailOptOutSaving) {
            return;
        }

        const nextValue = !appearanceData.email_opt_out;
        setAppearanceData('email_opt_out', nextValue);
        setEmailOptOutSaving(true);

        router.put('/settings/appearance', {
            ...appearanceData,
            email_opt_out: nextValue,
        }, {
            preserveScroll: true,
            only: ['settings', 'auth', 'flash'],
            onError: () => setAppearanceData('email_opt_out', !nextValue),
            onFinish: () => setEmailOptOutSaving(false),
        });
    };

    const resetDangerFlow = () => {
        setConfirmStep(0);
        setSelectedDegree(null);
        resetQuit();
    };

    const closeDangerAction = () => {
        setActiveDangerAction(null);
        resetDangerFlow();
    };

    const openDangerAction = (action) => {
        if (activeDangerAction === action) {
            closeDangerAction();
            return;
        }

        setActiveDangerAction(action);
        resetDangerFlow();
    };

    const handleQuitCareer = (e) => {
        if (e) e.preventDefault();
        postQuit(route('settings.quit-career'), {
            onSuccess: closeDangerAction,
        });
    };

    const handleQuitLife = (e) => {
        if (e) e.preventDefault();
        postQuit(route('settings.quit-life'), {
            onSuccess: closeDangerAction,
        });
    };

    const handleQuitStudy = (e) => {
        if (e) e.preventDefault();
        postQuit(route('settings.quit-study'), {
            onSuccess: closeDangerAction,
        });
    };

    const glowColors = [
        { value: 'cyan', label: 'Cyan', gradient: 'from-cyan-500 to-blue-500' },
        { value: 'red', label: 'Red', gradient: 'from-red-500 to-orange-500' },
        { value: 'green', label: 'Green', gradient: 'from-emerald-500 to-green-500' },
        { value: 'blue', label: 'Blue', gradient: 'from-blue-500 to-indigo-500' },
        { value: 'purple', label: 'Purple', gradient: 'from-purple-500 to-pink-500' },
        { value: 'yellow', label: 'Yellow', gradient: 'from-yellow-500 to-amber-500' },
        { value: 'pink', label: 'Pink', gradient: 'from-pink-500 to-rose-500' },
    ];

    return (
        <>
            <Head title="Settings" />

            {/*
                Destroyed property notification — informational only.
                Fires once when the user enters the Life tab with a destroyed property.
                Dismissing it does NOT redirect away; the tab stays open and fully
                interactive. Only stashing is server-blocked when the property is
                destroyed — equip, unequip, and danger zone are unaffected.
            */}
            <DestroyedModal
                isOpen={showDestroyedNotice}
                onExit={() => setDestroyedNoticeDismissed(true)}
            />

            <div className="space-y-5 text-[0.93rem]" style={{ zoom: 0.9 }}>
                <div className="rounded-2xl border border-slate-800/50 bg-gradient-to-br from-slate-900/80 to-slate-900/50 backdrop-blur-sm overflow-hidden">
                    <div className="h-1 bg-gradient-to-r from-cyan-500 to-blue-500" />
                    <div className="p-5">
                        <h1 className="text-2xl font-bold text-white mb-1.5">Settings</h1>
                        <p className="text-sm text-slate-400">Manage your account and appearance</p>
                    </div>
                </div>

                <div className="rounded-2xl border border-slate-800/50 bg-gradient-to-br from-slate-900/80 to-slate-900/50 backdrop-blur-sm overflow-hidden">
                    <div className="border-b border-slate-800 flex overflow-x-auto">
                        {[
                            { id: 'account', label: 'Account', icon: User },
                            { id: 'appearance', label: 'Appearance', icon: ImageIcon },
                            { id: 'life', label: 'Life', icon: Skull },
                            { id: 'notifications', label: 'User Preferences', icon: Bell },
                        ].map(tab => (
                            <button
                                key={tab.id}
                                onClick={() => {

                                    if (tab.id === 'life') {
                                        setDestroyedNoticeDismissed(false);
                                    }
                                    setActiveTab(tab.id);
                                }}
                                className={`flex-1 min-w-[132px] py-3 px-3 text-[13px] font-medium transition ${activeTab === tab.id ? 'text-cyan-400 border-b-2 border-cyan-400' : 'text-slate-500 hover:text-slate-300'
                                    }`}
                            >
                                <tab.icon size={16} className="inline mr-2" />
                                {tab.label}
                            </button>
                        ))}
                    </div>

                    <div className="p-5">

                        {/* ── Account tab ───────────────────────────────── */}
                        {activeTab === 'account' && (() => {
                            const cs = character_stats || {};
                            const ageDays = cs.age_days ?? null;

                            const pages = [
                                // Page 0 — Identity + Finances
                                <div key="p0" className="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
                                    <StatSection title="Character Information" icon={User} iconColor="text-cyan-400">
                                        <StatRow label="Name" value={cs.display_name ?? '—'} accent />
                                        <StatRow label="Gender" value={cs.gender ? cs.gender.charAt(0).toUpperCase() + cs.gender.slice(1) : '—'} />
                                        <StatRow label="Career" value={cs.career ?? '—'} />
                                        <StatRow label="Rank" value={cs.rank ?? '—'} />
                                        <StatRow label="Total Works" value={fmtNum(cs.total_earns)} />
                                        <StatRow label="Convictions" value={fmtNum(cs.convictions)} />
                                        {ageDays !== null && <StatRow label="Character Age" value={`${ageDays} day${ageDays !== 1 ? 's' : ''}`} />}
                                    </StatSection>
                                    <StatSection title="Finances" icon={CurrencyDollar} iconColor="text-emerald-400">
                                        <StatRow label="Cash on Hand" value={`${fmt$(cs.cash_on_hand)}`} accent />
                                        <StatRow label="Bank Balance" value={`${fmt$(cs.cash_in_bank)}`} />
                                        <StatRow label="Dirty Cash" value={`${fmt$(cs.dirty_cash)}`} />
                                        <StatRow label="Earned from Actions" value={`${fmt$(cs.finance?.earned_actions)}`} />
                                        <StatRow label="Earned from Career" value={`${fmt$(cs.finance?.earned_career)}`} />
                                        <StatRow label="Earned from Businesses" value={`${fmt$(cs.finance?.earned_business)}`} />
                                    </StatSection>
                                </div>,

                                // Page 1 — Career History
                                <div key="p1">
                                    <StatSection title="Career History" icon={Star} iconColor="text-amber-400">
                                        {(cs.career_history || []).length === 0 ? (
                                            <div className="px-3 py-3">
                                                <p className="text-[10px] text-slate-600 italic">No career history recorded yet.</p>
                                            </div>
                                        ) : (
                                            (cs.career_history || []).map((span, i) => (
                                                <GroupedEntry
                                                    key={i}
                                                    label={span.career_name}
                                                    meta={(span.ranks || []).map(r => ({ key: r.rank, value: r.at ?? '—' }))}
                                                />
                                            ))
                                        )}
                                    </StatSection>
                                </div>,

                                // Page 2 — Career Activity
                                <div key="p2">
                                    <StatSection title="Career Activity" icon={Briefcase} iconColor="text-cyan-400">
                                        {(() => {
                                            const act = cs.career_activity || {};
                                            const careers = [
                                                {
                                                    code: 'police', label: 'Police', rows: [
                                                        { key: 'Cases Investigated', col: 'cases_investigated' },
                                                        { key: 'Cases Closed', col: 'cases_closed' },
                                                        { key: 'Arrests Made', col: 'arrests_made' },
                                                    ]
                                                },
                                                {
                                                    code: 'law', label: 'Law', rows: [
                                                        { key: 'Cases Prosecuted', col: 'cases_prosecuted' },
                                                        { key: 'Cases Defended (W)', col: 'cases_defended' },
                                                        { key: 'Cases Acquitted', col: 'cases_acquitted' },
                                                        { key: 'Cases Sentenced', col: 'cases_sentenced' },
                                                        { key: 'Appeals Resolved', col: 'cases_appealed' },
                                                    ]
                                                },
                                                {
                                                    code: 'healthcare', label: 'Healthcare', rows: [
                                                        { key: 'Surgeries Performed', col: 'surgeries_performed' },
                                                        { key: 'Gender Reassignments', col: 'gender_reassignments_performed' },
                                                        { key: 'NGRI Pleas Won', col: 'ngri_successes' },
                                                    ]
                                                },
                                                {
                                                    code: 'banking', label: 'Banking', rows: [
                                                        { key: 'Trades Won', col: 'trades_won' },
                                                        { key: 'Trades Lost', col: 'trades_lost' },
                                                        { key: 'Launders Success', col: 'launders_succeeded' },
                                                        { key: 'Launders Failed', col: 'launders_failed' },
                                                    ]
                                                },
                                                {
                                                    code: 'politics', label: 'Politics', rows: [
                                                        { key: 'Terms Served', col: 'terms_served' },
                                                        { key: 'Pardons Issued', col: 'pardons_issued' },
                                                        { key: 'Dismissals', col: 'officers_dismissed' },
                                                        { key: 'Policies Enacted', col: 'policies_enacted' },
                                                    ]
                                                },
                                                {
                                                    code: 'technician', label: 'Technician', rows: [
                                                        { key: 'Vehicles Repaired', col: 'vehicles_repaired' },
                                                        { key: 'Homes Inspected', col: 'homes_inspected' },
                                                    ]
                                                },
                                                {
                                                    code: 'customs', label: 'Customs', rows: [
                                                        { key: 'Travelers Inspected', col: 'travelers_inspected' },
                                                    ]
                                                }
                                            ];
                                            const active = careers.filter(c => act[c.code]);
                                            if (!active.length) return (
                                                <div className="px-3 py-3">
                                                    <p className="text-[10px] text-slate-600 italic">No career activity recorded yet.</p>
                                                </div>
                                            );
                                            return active.map(career => (
                                                <GroupedEntry
                                                    key={career.code}
                                                    label={career.label}
                                                    meta={career.rows
                                                        .filter(r => act[career.code]?.[r.col])
                                                        .map(r => ({ key: r.key, value: fmtNum(act[career.code][r.col]) }))
                                                    }
                                                />
                                            ));
                                        })()}
                                    </StatSection>
                                </div>,

                                // Page 3 — Combat
                                <div key="p3">
                                    <StatSection title="Combat Record" icon={Sword} iconColor="text-red-400">
                                        <StatRow label="Damage Attacks" value={fmtNum(cs.combat?.attacks_landed)} accent />
                                        <StatRow label="Missed Attacks" value={fmtNum(cs.combat?.attacks_missed)} />
                                        <StatRow label="Kills" value={fmtNum(cs.combat?.kills)} />
                                        <StatRow label="Instant Kills" value={fmtNum(cs.combat?.instant_kills)} />
                                        <StatRow label="Critical Hits" value={fmtNum(cs.combat?.critical_hits)} />
                                        <StatRow label="GBH Landed" value={fmtNum(cs.combat?.gbh_landed)} />
                                        <StatRow label="GBH Missed" value={fmtNum(cs.combat?.gbh_missed)} />
                                        <StatRow label="Attacks Survived" value={fmtNum(cs.combat?.times_hit)} />
                                    </StatSection>
                                </div>,

                                // Page 4 — Talents + Criminal Record
                                <div key="p4" className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                    <StatSection title="Talents" icon={GraduationCap} iconColor="text-violet-400">
                                        {(cs.talents || []).map(talent => (
                                            <GroupedEntry
                                                key={talent.id}
                                                label={talent.name}
                                                meta={[
                                                    { key: 'Unlocked', value: talent.unlocked ? '✓ Yes' : '✗ No' },
                                                    { key: 'Times Activated', value: fmtNum(talent.times_used) },
                                                ]}
                                            />
                                        ))}
                                    </StatSection>
                                    <ComingSoonSection title="Criminal Log" icon={Detective} iconColor="text-rose-400" />
                                </div>,
                            ];

                            const total = pages.length;
                            const isFirst = recordPage === 0;
                            const isLast = recordPage === total - 1;

                            return (
                                <div className="space-y-6">


                                    <div className="flex items-center gap-3">
                                        <span className="text-[10px] font-black uppercase tracking-widest text-slate-500">Character Record</span>
                                        <div className="flex-1 h-px bg-white/[0.05]" />
                                    </div>

                                    <div className="flex flex-col gap-4">
                                        {pages[recordPage]}

                                        <div className="flex items-center justify-center gap-4">
                                            <button
                                                onClick={() => setRecordPage(p => Math.max(0, p - 1))}
                                                disabled={isFirst}
                                                className={`flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-medium transition-all ${isFirst ? 'text-slate-600 cursor-not-allowed' : 'text-slate-300 hover:text-white bg-slate-800/40 hover:bg-slate-800/70 border border-slate-700/30'
                                                    }`}
                                            >
                                                <CaretLeft size={13} weight="bold" /> Prev
                                            </button>

                                            <div className="flex items-center gap-1.5">
                                                {pages.map((_, i) => (
                                                    <button
                                                        key={i}
                                                        onClick={() => setRecordPage(i)}
                                                        className={`h-2 rounded-full transition-all ${i === recordPage ? 'w-5 bg-cyan-400' : 'w-2 bg-slate-700 hover:bg-slate-500'
                                                            }`}
                                                    />
                                                ))}
                                            </div>

                                            <button
                                                onClick={() => setRecordPage(p => Math.min(total - 1, p + 1))}
                                                disabled={isLast}
                                                className={`flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-medium transition-all ${isLast ? 'text-slate-600 cursor-not-allowed' : 'text-slate-300 hover:text-white bg-slate-800/40 hover:bg-slate-800/70 border border-slate-700/30'
                                                    }`}
                                            >
                                                Next <CaretRight size={13} weight="bold" />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            );
                        })()}

                        {activeTab === 'appearance' && (
                            <div className="space-y-6">
                                <form onSubmit={handleAppearanceSubmit} className="space-y-6">
                                    {/* Modern Avatar Upload Area */}
                                    <div
                                        className={`relative rounded-xl border-2 border-dashed transition-all p-6 ${isDragging ? 'border-cyan-400/50 bg-cyan-500/5' : 'border-slate-700 bg-slate-900/30'}`}
                                        onDragOver={(e) => { e.preventDefault(); setIsDragging(true); }}
                                        onDragLeave={() => setIsDragging(false)}
                                        onDrop={(e) => {
                                            e.preventDefault();
                                            setIsDragging(false);

                                            // Detect image URL from another tab/window
                                            const html = e.dataTransfer.getData('text/html');
                                            if (html) {
                                                const match = html.match(/src="([^"]+)"/);
                                                if (match && match[1]) {
                                                    const url = match[1];
                                                    setAppearanceData(prev => ({ ...prev, avatar_url: url }));
                                                    setAvatarPreview(url);
                                                    return;
                                                }
                                            }

                                            const plainText = e.dataTransfer.getData('text/plain');
                                            if (plainText && (plainText.startsWith('http') || plainText.startsWith('https'))) {
                                                setAppearanceData(prev => ({ ...prev, avatar_url: plainText }));
                                                setAvatarPreview(plainText);
                                            }
                                        }}
                                    >
                                        <div className="flex flex-col sm:flex-row items-center gap-6">
                                            <div className="relative flex-shrink-0">
                                                <div className="w-28 h-28 rounded-full overflow-hidden border-2 border-slate-700 shadow-lg">
                                                    <img
                                                        src={avatarPreview || 'https://placehold.co/112x112/0f172a/475569?text=Avatar'}
                                                        alt="Character Avatar"
                                                        className="w-full h-full object-cover"
                                                        onError={(e) => e.target.src = 'https://placehold.co/112x112/0f172a/475569?text=Avatar'}
                                                    />
                                                </div>
                                            </div>

                                            <div className="flex-1 space-y-2 text-center sm:text-left">
                                                <div>
                                                    <h3 className="text-sm font-semibold text-white mb-1">Character Avatar</h3>
                                                    <p className="text-xs text-slate-400 leading-relaxed">
                                                        To change your avatar, drag and drop an image.
                                                        <br />

                                                    </p>
                                                </div>
                                                <div className="flex flex-wrap items-center justify-center sm:justify-start gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setAvatarPreview('https://placehold.co/112x112/0f172a/475569?text=Default');
                                                            setAppearanceData(prev => ({ ...prev, avatar_url: '' }));
                                                        }}
                                                        className="px-4 py-2 text-xs font-black uppercase tracking-widest text-red-400 hover:bg-red-500/10 border border-red-500/20 rounded-lg transition"
                                                    >
                                                        Reset to Default
                                                    </button>
                                                </div>
                                                {appearanceErrors.avatar_url && (
                                                    <p className="text-xs text-red-400">{appearanceErrors.avatar_url}</p>
                                                )}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="space-y-4">
                                        <div className="flex items-center justify-between">
                                            <label className="text-sm font-semibold text-white tracking-tight">Glow Color</label>

                                        </div>
                                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
                                            {glowColors.map(color => {
                                                const isActive = appearanceData.glow_color === color.value;

                                                return (
                                                    <button
                                                        key={color.value}
                                                        type="button"
                                                        onClick={() => setAppearanceData('glow_color', color.value)}
                                                        className={`flex min-h-11 items-center gap-2 rounded-lg border px-3 py-2 text-left transition ${isActive ? 'border-cyan-400/70 bg-cyan-500/10 text-white' : 'border-slate-700/60 bg-slate-900/40 text-slate-400 hover:border-slate-500 hover:text-white'}`}
                                                        aria-pressed={isActive}
                                                    >
                                                        <span className={`h-4 w-4 shrink-0 rounded-full bg-gradient-to-br ${color.gradient}`} />
                                                        <span className="text-[10px] font-black uppercase tracking-[0.14em]">
                                                            {color.label}
                                                        </span>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>

                                    {/* Hide Achievements Toggle */}
                                    <div className="flex items-center justify-between p-4 rounded-lg bg-slate-800/30 border border-slate-700/50">
                                        <div>
                                            <h3 className="text-sm font-medium text-white">Hide Achievements</h3>
                                            <p className="text-xs text-slate-400 mt-1">Don't show achievements on your profile</p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => setAppearanceData('hide_achievements', !appearanceData.hide_achievements)}
                                            className={`relative inline-flex h-6 w-11 items-center rounded-full transition ${appearanceData.hide_achievements ? 'bg-cyan-600' : 'bg-slate-700'}`}
                                        >
                                            <span className={`inline-block h-4 w-4 transform rounded-full bg-white transition ${appearanceData.hide_achievements ? 'translate-x-6' : 'translate-x-1'}`} />
                                        </button>
                                    </div>

                                    {/* Save Button */}
                                    <button
                                        type="submit"
                                        disabled={updatingAppearance}
                                        className="px-6 py-3 bg-cyan-600 hover:bg-cyan-700 disabled:bg-slate-700 text-white font-medium rounded-lg transition flex items-center gap-2"
                                    >
                                        <FloppyDisk size={18} weight="bold" />
                                        {updatingAppearance ? 'Saving...' : 'Save Appearance'}
                                    </button>
                                </form>
                            </div>
                        )}

                        {/* ── Life tab ──────────────────────────────────── */}
                        {activeTab === 'life' && (
                            <div className="space-y-8">
                                {/*
                                    Destroyed property — inline banner, shown after the modal is
                                    dismissed so the user has a persistent reminder without being
                                    locked out. Only stashing is blocked; the rest of this tab
                                    is fully functional.
                                */}


                                <div className="space-y-6">
                                    <div className="p-4 rounded-lg bg-blue-500/10 border border-blue-500/30">
                                        <div className="flex items-start gap-3">
                                            <User size={18} className="text-blue-400 mt-0.5" />
                                            <div>
                                                <h3 className="text-sm font-semibold text-blue-400 mb-1">Wardrobe & Equipment</h3>
                                                <p className="text-xs text-slate-300">Manage your character's appearance and active equipment.</p>
                                            </div>
                                        </div>
                                    </div>
                                    {/*
                                        Pass property_condition so Wardrobe can disable its
                                        Stash button visually — the server also blocks stashing
                                        when property is destroyed or uninspected.
                                    */}
                                    <Wardrobe
                                        catalogItems={catalogItems}
                                        characterItems={characterItems}
                                        citySlug={citySlug}
                                        propertyCondition={property_condition}
                                    />
                                </div>

                                <div className="space-y-3 pt-6 mt-6 border-t border-slate-800">
                                    <div className="flex items-center gap-2 opacity-70">

                                        <h3 className="text-xs font-bold text-slate-400 uppercase tracking-widest">Life Choices</h3>
                                    </div>
                                    <div className="overflow-hidden rounded-2xl border border-red-950/45 bg-slate-950/35">
                                        <DangerActionRow
                                            active={activeDangerAction === 'career'}
                                            tone="career"
                                            title="Quit Career"
                                            subtitle="Resign and become unemployed."
                                            onClick={() => openDangerAction('career')}
                                        >
                                            {confirmStep === 0 ? (
                                                <div className="space-y-4">
                                                    <p className="text-sm leading-relaxed text-slate-400">
                                                        Quitting your career will reset your status to <span className="font-bold text-amber-400">Unemployed</span> and cost you dearly.
                                                    </p>
                                                    <div className="flex flex-col gap-2 sm:flex-row">
                                                        <DangerButton tone="career" onClick={() => setConfirmStep(1)} className="sm:w-auto">Next Step</DangerButton>
                                                        <DangerButton tone="career" variant="secondary" onClick={closeDangerAction} className="sm:w-auto">Cancel</DangerButton>
                                                    </div>
                                                </div>
                                            ) : (
                                                <form onSubmit={handleQuitCareer} className="space-y-4">
                                                    <div className="rounded-lg border border-red-500/20 bg-red-500/10 px-3 py-2 text-xs font-medium text-red-400">
                                                        This action is final. Enter your character name to resign.
                                                    </div>
                                                    <DangerInput
                                                        label="Character Name"
                                                        value={quitData.confirmation_name}
                                                        onChange={e => setQuitData('confirmation_name', e.target.value)}
                                                        placeholder="Enter character name"
                                                        required
                                                        error={quitErrors.confirmation_name}
                                                    />
                                                    {quitErrors.error && <p className="rounded-lg border border-red-800/40 bg-red-950/30 px-3 py-2 text-xs text-red-400">{quitErrors.error}</p>}
                                                    <div className="flex flex-col gap-2 sm:flex-row">
                                                        <DangerButton tone="career" type="button" variant="secondary" onClick={() => setConfirmStep(0)} className="sm:w-auto">Go Back</DangerButton>
                                                        <DangerButton tone="life" type="submit" disabled={quitting} className="sm:w-auto">{quitting ? 'RESIGNING...' : 'CONFIRM'}</DangerButton>
                                                    </div>
                                                </form>
                                            )}
                                        </DangerActionRow>

                                        <DangerActionRow
                                            active={activeDangerAction === 'study'}
                                            tone="study"
                                            title="Quit Study"
                                            subtitle="Drop out of your degree or training program."
                                            onClick={() => openDangerAction('study')}
                                        >
                                            {confirmStep === 0 ? (
                                                <div className="space-y-3">
                                                    <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Select program to drop</div>
                                                    {activeStudies.length > 0 ? (
                                                        activeStudies.map(([code, data], index) => (
                                                            <button
                                                                type="button"
                                                                key={`${code}-${index}`}
                                                                onClick={() => { setSelectedDegree(code); setQuitData('degree_code', code); setConfirmStep(1); }}
                                                                className="w-full rounded-xl border border-slate-800 bg-slate-950/50 p-3 text-left transition hover:border-purple-500/50 hover:bg-purple-500/10"
                                                            >
                                                                <div className="flex items-center justify-between gap-3">
                                                                    <div className="min-w-0">
                                                                        <div className="truncate text-sm font-bold text-white">{ucfirst(code)}</div>
                                                                        <div className="mt-1 text-[10px] text-slate-500">{data.cycles} cycles completed</div>
                                                                    </div>
                                                                    <BookOpen className="shrink-0 text-purple-400/70" size={18} />
                                                                </div>
                                                            </button>
                                                        ))
                                                    ) : (
                                                        <div className="rounded-xl bg-slate-950/30 p-4 text-center text-sm text-slate-500">No active studies to drop.</div>
                                                    )}
                                                    <DangerButton tone="study" variant="secondary" onClick={closeDangerAction}>Cancel</DangerButton>
                                                </div>
                                            ) : (
                                                <form onSubmit={handleQuitStudy} className="space-y-4">
                                                    <div className="rounded-lg border border-purple-500/20 bg-purple-500/10 px-3 py-2 text-xs font-medium text-purple-300">
                                                        Dropping <span className="font-bold text-purple-400">{ucfirst(selectedDegree)}</span>. This cannot be undone.
                                                    </div>
                                                    <DangerInput
                                                        label="Character Name"
                                                        value={quitData.confirmation_name}
                                                        onChange={e => setQuitData('confirmation_name', e.target.value)}
                                                        placeholder="Enter your character name"
                                                        required
                                                        error={quitErrors.confirmation_name}
                                                    />
                                                    {quitErrors.error && <p className="text-xs text-red-400">{quitErrors.error}</p>}
                                                    <div className="flex flex-col gap-2 sm:flex-row">
                                                        <DangerButton tone="study" type="button" variant="secondary" onClick={() => setConfirmStep(0)}>Go Back</DangerButton>
                                                        <DangerButton tone="life" type="submit" disabled={quitting}>{quitting ? 'DROPPING...' : 'CONFIRM'}</DangerButton>
                                                    </div>
                                                </form>
                                            )}
                                        </DangerActionRow>

                                        <DangerActionRow
                                            active={activeDangerAction === 'life'}
                                            tone="life"
                                            title="Quit Life"
                                            subtitle="Permanently terminate this character."
                                            onClick={() => openDangerAction('life')}
                                        >
                                            {confirmStep === 0 ? (
                                                <div className="space-y-4">
                                                    <p className="text-sm leading-relaxed text-slate-400">
                                                        This will permanently <span className="font-bold uppercase text-red-500">terminate</span> your character. All progress, items, and status will be lost forever.
                                                    </p>
                                                    <div className="flex flex-col gap-2 sm:flex-row">
                                                        <DangerButton tone="life" onClick={() => setConfirmStep(1)}>Continue</DangerButton>
                                                        <DangerButton tone="life" variant="secondary" onClick={closeDangerAction}>I want to live</DangerButton>
                                                    </div>
                                                </div>
                                            ) : (
                                                <form onSubmit={handleQuitLife} className="space-y-4">
                                                    <div className="rounded-lg border border-red-500/20 bg-red-950/40 px-3 py-2 text-center text-xs font-black uppercase tracking-tighter text-red-400">
                                                        One should die proudly when it is no longer possible to live proudly.
                                                    </div>
                                                    <DangerInput
                                                        label="Character Name"
                                                        value={quitData.confirmation_name}
                                                        onChange={e => setQuitData('confirmation_name', e.target.value)}
                                                        placeholder="Enter character name to confirm"
                                                        required
                                                        error={quitErrors.confirmation_name}
                                                    />
                                                    <div className="flex flex-col gap-2 sm:flex-row">
                                                        <DangerButton tone="life" type="button" variant="secondary" onClick={() => setConfirmStep(0)}>Nevermind</DangerButton>
                                                        <DangerButton tone="life" type="submit" disabled={quitting}>{quitting ? 'TERMINATING...' : 'PULL THE TRIGGER'}</DangerButton>
                                                    </div>
                                                </form>
                                            )}
                                        </DangerActionRow>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* ── Notifications tab ─────────────────────────── */}
                        {activeTab === 'notifications' && (
                            <div className="w-full">
                                <div className="flex items-center justify-between gap-4 border-b border-slate-800/70 pb-4">
                                    <div>
                                        <h3 className="text-sm font-semibold text-white">Disable Card Flip</h3>
                                        <p className="mt-1 text-xs text-slate-400">Use direct page transitions.</p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={toggleCardFlipPreference}
                                        disabled={cardFlipSaving}
                                        className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition disabled:opacity-60 ${appearanceData.disable_card_flip ? 'bg-cyan-600' : 'bg-slate-700'}`}
                                        aria-pressed={appearanceData.disable_card_flip}
                                    >
                                        <span className={`inline-block h-4 w-4 transform rounded-full bg-white transition ${appearanceData.disable_card_flip ? 'translate-x-6' : 'translate-x-1'}`} />
                                    </button>
                                </div>

                                <div className="flex items-center justify-between gap-4 border-b border-slate-800/70 py-4">
                                    <div>
                                        <h3 className="text-sm font-semibold text-white">Unsubscribe from Emails</h3>
                                        <p className="mt-1 text-xs text-slate-400">Stop receiving system announcement emails. You'll still see in-game announcements.</p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={toggleEmailOptOutPreference}
                                        disabled={emailOptOutSaving}
                                        className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition disabled:opacity-60 ${appearanceData.email_opt_out ? 'bg-cyan-600' : 'bg-slate-700'}`}
                                        aria-pressed={appearanceData.email_opt_out}
                                    >
                                        <span className={`inline-block h-4 w-4 transform rounded-full bg-white transition ${appearanceData.email_opt_out ? 'translate-x-6' : 'translate-x-1'}`} />
                                    </button>
                                </div>
                            </div>
                        )}

                    </div>
                </div>
            </div>
        </>
    );
}

Settings.layout = page => <GameLayout wide children={page} />;
