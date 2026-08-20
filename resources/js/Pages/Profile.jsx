import { useState, useMemo } from 'react';
import { useForm, Head, router } from '@inertiajs/react';
import { motion, AnimatePresence } from 'framer-motion';
import GameLayout from '@/Layouts/GameLayout';
import {
    User, CurrencyDollar, House, Buildings, Trophy,
    TShirt, Shield, Crown, Sneaker, Target, ArrowRight,
    PencilSimple, FloppyDisk, X, Watch, ChatTeardrop, EnvelopeSimple, Money, Barcode,
    PaperPlaneTilt, Heartbeat, PawPrint, Briefcase, CurrencyBtcIcon, DesktopIcon
} from '@phosphor-icons/react';
import { useServerClock } from '@/contexts/ClockContext';
import StyledModal, { ActionButton, StyledInput } from '@/Layouts/styledmodal';
import { formatUTC } from '@/Layouts/GameLayoutComponents';
import MarkdownRenderer from '@/Components/MarkdownRenderer';

const getInfluenceGradient = (influence) => {
    const val = influence || 0;
    if (val < 15) return {
        gradient: 'linear-gradient(90deg,#B45309 0%,#D97706 50%,#F59E0B 100%)',
        shadow: '0 0 10px rgba(217,119,6,0.4)', text: 'text-amber-500',
    };
    if (val < 40) {
        const t = (val - 15) / 25;
        const r = Math.round(5 + (52 - 5) * t), g = Math.round(150 + (211 - 150) * t), b = Math.round(50 + (153 - 50) * t);
        return { gradient: `linear-gradient(90deg,#D97706 0%,rgb(${r},${g},${b}) 60%,#34D399 100%)`, shadow: `0 0 14px rgba(${r},${g},${b},0.45)`, text: 'text-emerald-400' };
    }
    if (val < 65) {
        const t = (val - 40) / 25;
        const r = Math.round(52 + (218 - 52) * t), g = Math.round(211 + (165 - 211) * t), b = Math.round(153 - (153 - 32) * t);
        return { gradient: `linear-gradient(90deg,#34D399 0%,rgb(${r},${g},${b}) 55%,#DAA520 100%)`, shadow: `0 0 18px rgba(${r},${g},${b},0.5)`, text: 'text-yellow-400' };
    }
    if (val < 90) {
        const t = (val - 65) / 25, br = Math.round(210 + 45 * t);
        return { gradient: `linear-gradient(90deg,#DAA520 0%,rgb(${br},${Math.round(br * 0.84)},0) 50%,#FFD700 100%)`, shadow: `0 0 22px rgba(255,215,0,${0.55 + 0.2 * t})`, text: 'text-yellow-300' };
    }
    return { gradient: 'linear-gradient(90deg,#FFD700 0%,#FFE44D 25%,#FFFFFF 50%,#FFE44D 75%,#FFD700 100%)', shadow: '0 0 35px rgba(255,215,0,0.95),0 0 65px rgba(255,215,0,0.45)', text: 'text-yellow-100' };
};

const glowThemeMap = {
    cyan: { text: 'text-cyan-400', border: 'border-cyan-500/30', bg: 'bg-cyan-500/10', glow: 'shadow-[0_0_20px_rgba(34,211,238,0.15)]' },
    red: { text: 'text-red-400', border: 'border-red-500/30', bg: 'bg-red-500/10', glow: 'shadow-[0_0_20px_rgba(239,68,68,0.15)]' },
    green: { text: 'text-emerald-400', border: 'border-emerald-500/30', bg: 'bg-emerald-500/10', glow: 'shadow-[0_0_20px_rgba(16,185,129,0.15)]' },
    blue: { text: 'text-blue-400', border: 'border-blue-500/30', bg: 'bg-blue-500/10', glow: 'shadow-[0_0_20px_rgba(59,130,246,0.15)]' },
    purple: { text: 'text-purple-400', border: 'border-purple-500/30', bg: 'bg-purple-500/10', glow: 'shadow-[0_0_20px_rgba(168,85,247,0.15)]' },
    yellow: { text: 'text-yellow-400', border: 'border-yellow-500/30', bg: 'bg-yellow-500/10', glow: 'shadow-[0_0_20px_rgba(234,179,8,0.15)]' },
    pink: { text: 'text-pink-400', border: 'border-pink-500/30', bg: 'bg-pink-500/10', glow: 'shadow-[0_0_20px_rgba(236,72,153,0.15)]' },
};

const wealthTiers = {
    poor: { label: 'Poor as Dirt', color: 'text-slate-500', bg: 'bg-slate-800/20' },
    comfortable: { label: 'Comfortable', color: 'text-emerald-400', bg: 'bg-emerald-900/10' },
    bourgeoise: { label: 'Bourgeoisie', color: 'text-blue-400', bg: 'bg-blue-900/10' },
    ultra_high: { label: 'Ultra High Net Worth', color: 'text-purple-400', bg: 'bg-purple-900/10' },
    wealth_beyond: { label: 'Wealth Beyond Measure', color: 'text-yellow-400', bg: 'bg-yellow-900/10' },
};

const formatLastSeen = (timestamp, serverClock) => {
    if (!timestamp) return 'Unknown';
    const diff = serverClock - timestamp;
    if (diff < 60 && diff >= 0) return 'Just now';
    if (diff < 3600 && diff >= 60) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return formatUTC(timestamp, false);
};

const formatCorpPosition = (position, isCeo, isFounder) => {
    const posLabel = {
        cfo: 'CFO',
        cto: 'CTO',
        vp: 'VICE PRESIDENT',
        group_president: 'GROUP PRESIDENT',
        chairman: 'Chairman',
        director_of_board: 'DIRECTOR OF THE BOARD',
    }[position];
    if (isCeo && isFounder) return 'FOUNDER & CEO';
    if (isCeo) return 'CEO';
    if (isFounder && posLabel) return `FOUNDER & ${posLabel}`;
    if (isFounder) return 'FOUNDER';
    return posLabel ?? 'MEMBER';
};

const OUTFIT_SLOTS = [
    { id: 'head', label: 'Head', icon: Crown },
    { id: 'body', label: 'Body', icon: TShirt },
    { id: 'bottom', label: 'Bottom', icon: Shield },
    { id: 'shoes', label: 'Shoes', icon: Sneaker },
    { id: 'accessory', label: 'Accessory', icon: Watch },
];

// ── Corporation section helpers ───────────────────────────────────────────────

const nameHue = name => { let h = 0; for (const c of name) h = (h << 5) - h + c.charCodeAt(0); return Math.abs(h) % 360; };

const corpBadge = m => {
    if (m.position === 'director_of_board') return 'DOTB';
    if (m.position === 'chairman') return 'BOD';
    if (m.position === 'group_president') return 'GP';
    if (m.isCeo && m.isFounder) return 'F/CEO';
    if (m.isCeo) return 'CEO';
    if (m.isFounder) return 'F';
    return { cfo: 'CFO', cto: 'CTO', vp: 'VP' }[m.position] ?? '';
};

const corpAccent = m => {
    if (m.position === 'director_of_board')
        return { text: '#F8FAFC', border: 'rgba(226,232,240,0.38)', bg: 'rgba(226,232,240,0.08)', badge: 'rgba(226,232,240,0.12)' };
    if (['chairman', 'group_president'].includes(m.position))
        return { text: '#FDBA74', border: 'rgba(251,146,60,0.28)', bg: 'rgba(251,146,60,0.06)', badge: 'rgba(251,146,60,0.12)' };
    if (m.isCeo)
        return { text: '#FCD34D', border: 'rgba(245,158,11,0.3)', bg: 'rgba(245,158,11,0.08)', badge: 'rgba(245,158,11,0.12)' };
    if (m.position === 'cfo')
        return { text: '#6EE7B7', border: 'rgba(52,211,153,0.28)', bg: 'rgba(16,185,129,0.06)', badge: 'rgba(16,185,129,0.12)' };
    if (m.position === 'cto')
        return { text: '#7DD3FC', border: 'rgba(56,189,248,0.28)', bg: 'rgba(14,165,233,0.06)', badge: 'rgba(14,165,233,0.12)' };
    if (m.position === 'vp')
        return { text: '#67E8F9', border: 'rgba(34,211,238,0.25)', bg: 'rgba(6,182,212,0.05)', badge: 'rgba(6,182,212,0.1)' };
    return { text: '#64748B', border: 'rgba(71,85,105,0.2)', bg: 'transparent', badge: 'rgba(71,85,105,0.1)' };
};

const corpChainArray = value => Array.isArray(value) ? value : [];

const corpReportsToMembers = corporation => {
    if (Array.isArray(corporation?.chain)) return [];
    return corpChainArray(corporation?.chain?.reportsTo);
};

const corpManagingMembers = corporation => {
    if (Array.isArray(corporation?.chain)) return corporation.chain;
    return corpChainArray(corporation?.chain?.manages ?? corporation?.chain?.members);
};

const corpReportsToCorporation = corporation => {
    if (Array.isArray(corporation?.chain)) return [];
    return corporation?.chain?.reportsToCorporation ? [corporation.chain.reportsToCorporation] : [];
};

const corpManagingCorporations = corporation => {
    if (Array.isArray(corporation?.chain)) return [];
    return corpChainArray(corporation?.chain?.managesCorporations);
};

const CorpChainNode = ({ member, index }) => {
    const ac = corpAccent(member);
    const badge = corpBadge(member);
    const hue = nameHue(member.displayName);

    return (
        <motion.div
            initial={{ opacity: 0, y: 6 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.08 + index * 0.05, duration: 0.28, ease: 'easeOut' }}
            className="flex min-w-0 items-center gap-2"
        >
            <button
                type="button"
                onClick={() => router.get(`/profile/${member.displayName}`)}
                className="group flex min-w-0 items-center gap-2 rounded-xl px-1 py-1 text-left transition hover:bg-white/[0.04]"
            >
                {member.avatarUrl ? (
                    <img
                        src={member.avatarUrl}
                        className="h-9 w-9 flex-shrink-0 rounded-xl object-cover transition-transform group-hover:scale-105"
                        style={{ border: `1.5px solid ${ac.border}` }}
                        alt=""
                    />
                ) : (
                    <div
                        className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl text-[10px] font-black transition-transform group-hover:scale-105"
                        style={{ background: `hsl(${hue},18%,11%)`, border: `1.5px solid ${ac.border}`, color: `hsl(${hue},40%,52%)` }}
                    >
                        {member.displayName.slice(0, 2).toUpperCase()}
                    </div>
                )}
                <div className="min-w-0">
                    <span className="block truncate text-xs font-black uppercase leading-tight tracking-[0.04em] text-white drop-shadow">
                        {member.displayName}
                    </span>
                    {badge && (
                        <span
                            className="mt-1 inline-flex rounded px-1.5 py-0.5 text-[8px] font-black uppercase leading-none tracking-[0.12em]"
                            style={{ color: ac.text, background: ac.badge, border: `1px solid ${ac.border}` }}
                        >
                            {badge}
                        </span>
                    )}
                </div>
            </button>
        </motion.div>
    );
};

const CorpCompanyNode = ({ company, index }) => {
    const hue = nameHue(company.name ?? '');

    return (
        <motion.div
            initial={{ opacity: 0, y: 6 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.08 + index * 0.05, duration: 0.28, ease: 'easeOut' }}
            className="flex min-w-0 items-center gap-2 rounded-xl px-1 py-1"
        >
            {company.imageUrl ? (
                <img
                    src={company.imageUrl}
                    className="h-9 w-12 flex-shrink-0 rounded-xl border border-amber-300/25 object-cover shadow-[0_8px_20px_rgba(2,6,23,0.32)]"
                    alt={company.name}
                />
            ) : (
                <div
                    className="flex h-9 w-12 flex-shrink-0 items-center justify-center rounded-xl border border-amber-300/20 text-[10px] font-black"
                    style={{ background: `hsl(${hue},18%,11%)`, color: `hsl(${hue},58%,62%)` }}
                >
                    {(company.name ?? 'CO').slice(0, 2).toUpperCase()}
                </div>
            )}
            <div className="min-w-0">
                <span className="block truncate text-xs font-black uppercase leading-tight tracking-[0.04em] text-white drop-shadow">
                    {company.name}
                </span>
                {company.homeCity && (
                    <span className="mt-1 block truncate text-[8px] font-black uppercase leading-none tracking-[0.16em] text-amber-200/75">
                        {company.homeCity}
                    </span>
                )}
            </div>
        </motion.div>
    );
};

const CorpChainSection = ({ label, members, type = 'member' }) => {
    if (!members.length) return null;
    const Node = type === 'company' ? CorpCompanyNode : CorpChainNode;

    return (
        <div className="min-w-0">
            <div className="flex items-center gap-2 md:justify-end">
                <div className="h-px flex-1 bg-white/[0.06] md:max-w-14" />
                <span className="text-[9px] font-black uppercase tracking-[0.22em] text-slate-400">
                    {label}
                </span>
                {members.length > 1 && (
                    <span className="rounded-full border border-amber-300/20 bg-amber-300/10 px-1.5 py-0.5 text-[8px] font-black leading-none text-amber-200">
                        {members.length}
                    </span>
                )}
            </div>
            <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-2 md:justify-end">
                {members.map((member, index) => (
                    <Node key={member.id} member={member} company={member} index={index} />
                ))}
            </div>
        </div>
    );
};

const CorpBanner = ({ corporation }) => {
    const reportsTo = corpReportsToMembers(corporation);
    const manages = corpManagingMembers(corporation);
    const reportsToCompanies = corpReportsToCorporation(corporation);
    const managesCompanies = corpManagingCorporations(corporation);
    const hasChain = reportsTo.length > 0 || manages.length > 0 || reportsToCompanies.length > 0 || managesCompanies.length > 0;

    return (
        <motion.div
            className="md:col-span-12"
            initial={{ opacity: 0, y: -10 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4, ease: 'easeOut' }}
        >
            <div className="relative overflow-hidden rounded-3xl border border-amber-500/15 bg-slate-900/70 shadow-2xl" style={{ minHeight: '184px' }}>
                <div className="absolute inset-0 p-2">
                    {corporation.imageUrl ? (
                        <img
                            src={corporation.imageUrl}
                            alt={corporation.name}
                            className="h-full w-full rounded-2xl object-cover object-center"
                            style={{ filter: 'brightness(1.04) contrast(1.04) saturate(1.08)' }}
                        />
                    ) : (
                        <div className="h-full w-full rounded-2xl bg-gradient-to-br from-slate-900 via-slate-950 to-slate-900" />
                    )}
                    <div className="absolute inset-2 rounded-2xl bg-gradient-to-t from-slate-950/82 via-slate-950/10 to-transparent" />
                    <div className="absolute inset-2 rounded-2xl bg-gradient-to-r from-slate-950/72 via-slate-950/20 to-slate-950/35 md:to-slate-950/55" />
                    <div className="absolute inset-x-2 bottom-2 h-px bg-gradient-to-r from-amber-400/35 via-transparent to-amber-400/20" />
                </div>

                <div className="relative flex min-h-[184px] flex-col justify-end gap-5 px-4 py-4 md:flex-row md:items-end md:px-5 md:py-5">
                    <div className="min-w-0 flex-1">
                        <p className="text-[10px] font-black uppercase tracking-[0.35em] text-white/55">
                            {corporation.homeCity || 'Unknown'}
                        </p>
                        <h4
                            className="mt-2 truncate text-base font-black uppercase leading-none tracking-[0.04em] text-amber-300 md:text-lg"
                            style={{ textShadow: '0 0 10px rgba(251,191,36,0.45), 0 0 22px rgba(245,158,11,0.18)' }}
                        >
                            {corporation.name}
                        </h4>
                        <p className="mt-2 truncate text-[12px] font-black uppercase leading-none tracking-[0.18em] text-slate-300">
                            {formatCorpPosition(corporation.position, corporation.isCeo, corporation.isFounder)}
                        </p>
                    </div>

                    <div className="w-full md:ml-auto md:w-auto md:min-w-[18rem] md:max-w-[36rem]">
                        {hasChain ? (
                            <div className="space-y-3">
                                <CorpChainSection label="Reports To" members={reportsTo} />
                                <CorpChainSection label="Reports To" members={reportsToCompanies} type="company" />
                                <CorpChainSection label="Managing" members={manages} />
                                <CorpChainSection label="Managing" members={managesCompanies} type="company" />
                            </div>
                        ) : (
                            <div className="mt-3 text-right text-[9px] font-black uppercase tracking-[0.2em] text-slate-600">
                                No live chain
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </motion.div>
    );
};

// ── Main component ────────────────────────────────────────────────────────────

export default function Profile({ profile, isDead, isOwnProfile, characterItems, targetCharacterId, viewerCitySlug, can_revive }) {
    const [isEditing, setIsEditing] = useState(false);
    const [showActionModal, setShowActionModal] = useState(false);
    const [interactView, setInteractView] = useState('menu');
    const [sendAmount, setSendAmount] = useState('');
    const [sendNote, setSendNote] = useState('');
    const [sendingMoney, setSendingMoney] = useState(false);
    const [scamming, setScamming] = useState(false);
    const [reviving, setReviving] = useState(false);

    const theme = useMemo(() => glowThemeMap[profile?.glowColor] || glowThemeMap.cyan, [profile?.glowColor]);

    const { data, setData, put, processing } = useForm({
        biography: profile?.biography || '',
        additional_info: profile?.additionalInfo || '',
    });

    const serverClock = useServerClock();

    const lastSeenDisplay = useMemo(() => {
        if (profile?.isOnline) return null;
        return formatLastSeen(profile?.lastActivity, serverClock);
    }, [profile?.isOnline, profile?.lastActivity, serverClock]);

    const handleCryptoScam = () => {
        if (scamming) return;
        setScamming(true);

        router.post(route('actions.crypto-rug-pull'), {
            target_id: targetCharacterId
        }, {
            onSuccess: () => {
                setShowActionModal(false);
            },
            onFinish: () => setScamming(false),
            preserveScroll: true
        });
    };

    const handleSendMoney = () => {
        if (!sendAmount || sendingMoney) return;
        setSendingMoney(true);
        router.post(`/${viewerCitySlug}/bank/transfer`, {
            amount: parseInt(sendAmount), recipient: profile.displayName, note: sendNote || '',
        }, {
            preserveScroll: true,
            onSuccess: () => { setShowActionModal(false); setInteractView('menu'); setSendAmount(''); setSendNote(''); },
            onFinish: () => setSendingMoney(false),
        });
    };

    const openInteract = () => { setInteractView('menu'); setSendAmount(''); setSendNote(''); setShowActionModal(true); };

    const handleSubmit = (e) => {
        e.preventDefault();
        put('/profile', { preserveScroll: true, onSuccess: () => setIsEditing(false) });
    };

    // ── Dead profile ──────────────────────────────────────────────────────────

    if (isDead) {
        const isBanned = profile.user?.is_banned;
        const hasFinalWords = !!profile.biography;

        return (
            <>
                <Head title={`${profile.displayName} - Deceased`} />
                <div className="flex-1 flex items-center justify-center p-4" style={{ zoom: 1.0 }}>
                    <div className="max-w-xl w-full">
                        <div className="rounded-2xl border border-red-900/60 bg-gradient-to-br from-gray-900/90 to-gray-900/60 backdrop-blur-xl overflow-hidden shadow-2xl relative">
                            <div className="absolute inset-0 flex items-center justify-center opacity-10 pointer-events-none">
                                <div className="text-[12rem] font-black text-red-900/30 tracking-widest">RIP</div>
                            </div>
                            <div className="h-8 bg-gradient-to-r from-transparent via-gray-800/50 to-transparent flex items-center justify-center">
                                <span className="text-xs text-slate-500 italic">A free man thinks of nothing less than of death, for his wisdom is a meditation on life.</span>
                            </div>
                            <div className="p-6">
                                <div className="flex items-center gap-4 mb-6">
                                    <div className="relative shrink-0">
                                        <div className="absolute -inset-3 rounded-full bg-gradient-to-r from-red-800/30 to-red-900/20 blur-xl" />
                                        {(() => {
                                            const isCustom = !!profile.avatarUrl && !profile.avatarUrl.includes('images.thedirector.app');
                                            return (
                                                <div className={`relative w-20 h-20 rounded-full overflow-hidden bg-slate-950 ${isCustom ? 'border-4 border-white/60' : 'border-2 border-red-800/50'}`}>
                                                    <div className="absolute inset-0 shadow-[inset_0_0_12px_rgba(0,0,0,0.8)] z-10 pointer-events-none rounded-full" />
                                                    <img
                                                        src={profile.avatarUrl || 'https://images.thedirector.app/Conflict/dying.png'}
                                                        className="w-full h-full object-cover grayscale opacity-75 relative z-0"
                                                        alt="Deceased"
                                                    />
                                                </div>
                                            );
                                        })()}
                                    </div>
                                    <div>
                                        <h1 className="text-2xl font-bold text-red-500">{profile.displayName}</h1>
                                        <div className="text-sm text-gray-400">
                                            {profile.career && <span className="mr-2">{profile.career}</span>}
                                            {profile.rank && <span>{profile.rank}</span>}
                                        </div>
                                    </div>
                                </div>
                                <div className="space-y-4">
                                    {!isBanned && profile.deathDate && (
                                        <div className="text-center">
                                            <div className="text-xs text-gray-500 mb-1">DIED ON</div>
                                            <div className="text-lg font-medium text-gray-300">{formatUTC(profile.deathDate)}</div>
                                        </div>
                                    )}
                                    {!isBanned && profile.deathCause && (
                                        <div>
                                            <div className="text-xs text-gray-500 mb-1">CAUSE OF DEATH</div>
                                            <div className="text-sm text-gray-300 bg-red-900/20 border border-red-800/30 rounded-lg p-3">{profile.deathCause}</div>
                                        </div>
                                    )}
                                    {isBanned && profile.user?.ban_reason && (
                                        <div className="mb-4">
                                            <div className="relative rounded-lg bg-red-950/40 border border-red-700/50 p-4">
                                                <div className="absolute -top-2 left-1/2 -translate-x-1/2 bg-red-800 px-3 py-1 rounded-full text-xs font-bold text-red-300 border border-red-600/50">BAN REASON</div>
                                                <div className="pt-2">
                                                    <p className="text-center text-red-300">{profile.user.ban_reason}</p>
                                                    {profile.user.banned_at && <p className="text-center text-xs text-red-400 mt-2">Banned on {formatUTC(profile.user.banned_at, false)} UTC</p>}
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                    {!isBanned && hasFinalWords && (
                                        <div>
                                            <div className="text-xs text-gray-500 mb-1">FINAL WORDS</div>
                                            <div className="text-sm text-gray-300 bg-gray-900/40 border border-gray-700/40 rounded-lg p-3">
                                                <span className="italic">"{profile.biography}"</span>
                                            </div>
                                        </div>
                                    )}
                                    {can_revive && (
                                        <div className="pt-2">
                                            <button
                                                disabled={reviving}
                                                onClick={() => {
                                                    setReviving(true);
                                                    router.post(
                                                        route('profile.revive', { displayName: profile.displayName }),
                                                        {},
                                                        { onFinish: () => setReviving(false) }
                                                    );
                                                }}
                                                className="w-full flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-emerald-950/60 border border-emerald-700/40 text-emerald-400 hover:bg-emerald-900/60 hover:border-emerald-600/60 transition-all text-xs font-black uppercase tracking-widest disabled:opacity-50 disabled:cursor-not-allowed"
                                            >
                                                <Heartbeat size={15} weight="fill" className={reviving ? '' : 'animate-pulse'} />
                                                {reviving ? 'Attempting...' : 'Attempt Revival'}
                                            </button>
                                            <p className="text-[10px] text-center text-gray-600 mt-2">Nothing is guaranteed, but it's the only hope they have left.</p>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </>
        );
    }

    // ── Live profile ──────────────────────────────────────────────────────────

    const wealthInfo = wealthTiers[profile?.wealthStatus] || wealthTiers.poor;
    const avatarHasBorder = !!profile?.avatarUrl && !profile.avatarUrl.includes('images.thedirector.app');
    const influenceStyle = getInfluenceGradient(profile?.influence);

    return (
        <div className="w-full max-w-6xl mx-auto py-4 px-3 animate-fadeIn">
            <Head title={`${profile?.displayName || 'Character'}'s Profile`} />

            {/* Corporation Banner – full width, same as original */}
            {profile?.corporation && <CorpBanner corporation={profile.corporation} />}

            <div className="mt-4 space-y-5">

                {/* ========== HERO CARD – Large, asymmetric, story-first ========== */}

                <div className={`relative rounded-3xl border ${theme.border} bg-slate-900/50 backdrop-blur-2xl shadow-2xl overflow-hidden`}>
                    <div className="relative p-4 flex flex-col md:flex-row gap-4 items-center md:items-start">

                        {/* LEFT: Avatar + Actions */}
                        <div className="flex flex-col items-center gap-4 shrink-0 w-full md:w-auto">
                            <div className="relative w-32 h-32 md:w-40 md:h-40">
                                <div
                                    className={`w-full h-full rounded-full overflow-hidden ${avatarHasBorder
                                        ? 'border-4 border-white bg-slate-800 shadow-2xl'
                                        : 'bg-[#10152d]'
                                        }`}
                                >
                                    {profile?.avatarUrl ? (
                                        <img src={profile.avatarUrl} className="w-full h-full object-cover" alt="" />
                                    ) : (
                                        <div className="w-full h-full flex items-center justify-center">
                                            <User size={48} className="text-slate-600" />
                                        </div>
                                    )}
                                </div>
                                <div className="absolute -bottom-1.5 left-1/2 -translate-x-1/2 w-6 h-6 rounded-full bg-slate-950 flex items-center justify-center border border-white/10 shadow-2xl z-20">
                                    <div className={`w-3.5 h-3.5 rounded-full ${profile?.isOnline ? 'bg-emerald-500 animate-pulse shadow-[0_0_12px_rgba(16,185,129,0.95)]' : 'bg-red-500 shadow-[0_0_12px_rgba(239,68,68,0.95)]'}`} />
                                </div>
                            </div>

                            {/* Action buttons */}
                            <div className="flex flex-row justify-center gap-3 w-full max-w-[160px]">
                                {isOwnProfile && !isEditing && (
                                    <button onClick={() => setIsEditing(true)} className="px-4 py-2 rounded-full bg-white/10 text-white text-xs font-black uppercase tracking-wide hover:bg-white/20 transition flex items-center justify-center gap-2 w-full">
                                        <PencilSimple size={14} /> Edit
                                    </button>
                                )}
                                {!isOwnProfile && (
                                    <button onClick={openInteract} className="px-4 py-2 rounded-full bg-cyan-500/20 text-cyan-400 text-xs font-black uppercase tracking-wide hover:bg-cyan-500/30 transition flex items-center justify-center gap-2 w-full">
                                        <ChatTeardrop size={14} /> Interact
                                    </button>
                                )}
                            </div>
                        </div>

                        {/* RIGHT: Identity */}
                        <div className="flex-1 w-full">

                            {/* Name + Influence top-right */}
                            <div className="flex items-start justify-between gap-4 mb-3">
                                <div className="min-w-0">
                                    <div className="flex min-w-0 max-w-full flex-wrap items-center gap-x-2 gap-y-1">
                                        <h1
                                            className="min-w-0 max-w-full break-words text-3xl font-black leading-tight tracking-tight text-white"
                                            style={{ overflowWrap: 'anywhere' }}
                                        >
                                            {profile?.displayName}
                                        </h1>
                                        {profile?.displayName === 'Makimura' && (
                                            <span className="text-[10px] font-black px-2 py-0.5 rounded-full bg-amber-500/30 text-amber-300 border border-amber-400/50 shrink-0">CREATOR & ADMINISTRATOR</span>
                                        )}
                                    </div>
                                    {profile?.biography && (
                                        <p className="text-sm text-slate-300 mt-1.5 truncate">"{profile.biography}"</p>
                                    )}
                                </div>

                                {/* Influence — stays top-right */}
                                <div className="shrink-0 text-right">
                                    <p className="text-[9px] font-black uppercase tracking-widest text-cyan-400 mb-1">Influence</p>
                                    <span className={`text-2xl font-mono font-black leading-none ${influenceStyle.text}`}>
                                        {Math.floor(profile?.influence || 0)}
                                    </span>
                                    <div className="w-24 h-1 bg-slate-800/60 rounded-full overflow-hidden mt-1.5 ml-auto">
                                        <div className="h-full rounded-full" style={{ width: `${Math.min(100, (profile?.influence || 0) / 150 * 100)}%`, background: influenceStyle.gradient, boxShadow: influenceStyle.shadow }} />
                                    </div>
                                </div>
                            </div>

                            {/* Stat grid — replaces all pills */}
                            <div className="grid grid-cols-3 gap-x-4 gap-y-3">
                                {[
                                    { label: 'Career', value: profile?.career || 'Unassigned' },
                                    { label: 'Rank', value: profile?.rank || 'Entry Level' },
                                    { label: 'Home City', value: profile?.homeCity || '—' },
                                    { label: 'Gender', value: profile?.gender ? (profile.gender.charAt(0).toUpperCase() + profile.gender.slice(1)) : '—' },
                                    {
                                        label: 'Last online',
                                        value: profile?.isOnline ? 'Online' : (lastSeenDisplay || 'Offline'),
                                        color: profile?.isOnline ? 'text-emerald-400' : 'text-slate-500',
                                    },
                                    {
                                        label: 'Net Worth',
                                        value: wealthInfo.label,
                                        color: wealthInfo.color,
                                    },
                                ].map(({ label, value, color }) => (
                                    <div key={label} className="flex flex-col gap-0.5 min-w-0">
                                        <span className="text-[9px] font-black uppercase tracking-widest text-cyan-400">{label}</span>
                                        <span className={`text-xs font-bold truncate ${color || 'text-white'}`}>{value}</span>
                                    </div>
                                ))}
                            </div>

                        </div>
                    </div>
                </div>

                {/* ========== EDIT FORM (exactly as original, fully functional) ========== */}
                <AnimatePresence>
                    {isEditing && (
                        <motion.div
                            initial={{ opacity: 0, y: -8 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: -8 }}
                            className="rounded-2xl border border-white/[0.08] bg-slate-900/70 backdrop-blur-xl shadow-xl overflow-hidden"
                        >
                            <div className="flex items-center justify-between px-5 py-3 border-b border-white/[0.06]">
                                <span className="text-xs font-black text-slate-300 uppercase tracking-[0.3em]">Edit Profile</span>
                                <button type="button" onClick={() => setIsEditing(false)} className="text-slate-500 hover:text-white transition-colors p-1 rounded-lg hover:bg-white/5">
                                    <X size={16} />
                                </button>
                            </div>
                            <form onSubmit={handleSubmit} className="p-5 space-y-4">
                                <div>
                                    <div className="flex justify-between items-center mb-1.5">
                                        <label className="text-[10px] font-black text-slate-500 uppercase tracking-widest">Tagline</label>
                                        <span className="text-[10px] text-slate-600 tabular-nums">{data.biography.length}/30</span>
                                    </div>
                                    <input
                                        type="text"
                                        value={data.biography}
                                        onChange={e => setData('biography', e.target.value)}
                                        maxLength={30}
                                        className="w-full bg-slate-950/60 border border-white/[0.08] rounded-xl px-4 py-2.5 text-sm text-white placeholder:text-slate-600 focus:border-white/20 outline-none"
                                        placeholder="Short tagline..."
                                    />
                                </div>
                                <div>
                                    <div className="flex justify-between items-center mb-1.5">
                                        <label className="text-[10px] font-black text-slate-500 uppercase tracking-widest">About</label>
                                        <span className="text-[10px] text-slate-600 tabular-nums">{data.additional_info.length}/500</span>
                                    </div>
                                    <textarea
                                        value={data.additional_info}
                                        onChange={e => setData('additional_info', e.target.value)}
                                        maxLength={500}
                                        rows={12}
                                        className="w-full bg-slate-950/60 border border-white/[0.08] rounded-xl px-4 py-2.5 text-sm text-white placeholder:text-slate-600 focus:border-white/20 outline-none resize-none min-h-[160px]"
                                        placeholder="Taunts, what other characters should know, sales etc..."
                                    />
                                    <div className="mt-2 p-3 rounded-lg bg-white/5 border border-white/5">
                                        <p className="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Formatting Guide</p>
                                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                            {[['*italic*', '_italic_'], ['**bold**', null], ['# Heading', null], ['> Quote', null], ['[c]center[/c][default]', null], ['[r]right[/r]', null]].map(([a, b], i) => (
                                                <div key={i} className="text-[10px] text-slate-400">
                                                    <span className="text-white font-mono">{a}</span>
                                                    {b && <> or <span className="text-white font-mono">{b}</span></>}
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                                <div className="flex justify-end gap-3 pt-1">
                                    <button type="button" onClick={() => setIsEditing(false)} className="px-5 py-2 rounded-xl bg-white/5 hover:bg-white/10 text-xs font-black uppercase text-slate-400 tracking-widest transition-all border border-white/5">
                                        Cancel
                                    </button>
                                    <button type="submit" disabled={processing} className="px-6 py-2 rounded-xl bg-white/10 hover:bg-white/15 text-xs font-black uppercase text-white tracking-widest transition-all border border-white/10 flex items-center gap-2 disabled:opacity-50">
                                        <FloppyDisk size={13} weight="bold" /> Save
                                    </button>
                                </div>
                            </form>
                        </motion.div>
                    )}
                </AnimatePresence>

                <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
                    {/* LEFT SIDEBAR – Outfit & Residence */}
                    <div className="lg:col-span-4 flex flex-col gap-5">
                        {/* Outfit card */}
                        <div className="rounded-2xl border border-white/10 bg-slate-900/50 backdrop-blur-xl p-4">
                            <h3 className="text-xs font-black text-slate-400 uppercase tracking-wider mb-3">Outfit</h3>
                            <div className="space-y-2">
                                {OUTFIT_SLOTS.map((slot) => {
                                    const item = characterItems?.find(i => i.is_equipped && i.equipped_slot === slot.id);
                                    const Icon = slot.icon;
                                    return (
                                        <div key={slot.id} className={`flex items-center gap-3 p-2 rounded-xl transition-all ${item ? 'bg-white/5' : ''}`}>
                                            <div className="w-10 h-10 rounded-lg bg-slate-800/80 flex items-center justify-center">
                                                {item ? <img src={item.image_url} className="w-full h-full object-contain" alt="" /> : <Icon size={20} className="text-slate-600" />}
                                            </div>
                                            <div className="flex-1">
                                                <div className="text-[9px] font-bold uppercase text-slate-500">{slot.label}</div>
                                                <div className="text-sm font-semibold text-white">{item ? item.name : 'Empty'}</div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        <div className="rounded-3xl border border-white/5 bg-slate-900/40 backdrop-blur-2xl overflow-hidden flex-1 flex flex-col">
                            <div className="p-3 border-b border-white/5">
                                <h3 className="text-xs font-black text-slate-500 uppercase tracking-[0.4em]">Residence</h3>
                            </div>
                            {profile?.property?.image ? (
                                <div className="relative flex-1 bg-cover bg-center min-h-[128px]" style={{ backgroundImage: `url(${profile.property.image})` }}>
                                    <div className="absolute inset-0 bg-gradient-to-t from-black/85 via-black/30 to-transparent" />
                                    <div className="absolute bottom-3 left-4 right-4">
                                        <h4 className="text-sm font-black tracking-tight leading-tight text-white drop-shadow-lg">
                                            {profile.property.name}
                                        </h4>
                                    </div>
                                </div>
                            ) : (
                                <div className="flex-1 flex items-center justify-center px-3 py-4 text-center text-[10px] font-black uppercase tracking-widest text-slate-600">No Residence</div>
                            )}
                        </div>
                    </div>
                    {/* RIGHT MAIN – About, Businesses, Badges */}
                    <div className="lg:col-span-8 flex flex-col gap-5">
                        {/* About – Markdown */}
                        <div className="rounded-2xl border border-white/10 bg-slate-900/50 backdrop-blur-xl p-5">
                            <h3 className="text-xs font-black text-slate-400 uppercase tracking-wider mb-3">About</h3>
                            <div className="px-4 py-3.5 min-h-[160px] flex items-start">
                                <MarkdownRenderer className="text-slate-300 text-sm leading-relaxed text-center w-full">
                                    {profile?.additionalInfo || ``}
                                </MarkdownRenderer>
                            </div>
                        </div>

                        {/* Businesses – horizontal carousel (CSS scroll snap) */}
                        {/*increase business card size*/}
                        <div className="rounded-2xl border border-white/10 bg-slate-900/50 backdrop-blur-xl p-4">
                            <h3 className="text-xs font-black text-slate-400 uppercase tracking-wider mb-3">Businesses</h3>
                            {profile?.businesses?.length > 0 ? (
                                <div className="flex overflow-x-auto gap-3 pb-2 snap-x snap-mandatory scrollbar-thin scrollbar-thumb-white/10">
                                    {profile.businesses.map((biz, i) => (
                                        <div key={i} className="snap-start shrink-0 w-48 h-28 rounded-xl relative overflow-hidden border border-white/10">
                                            {biz.image && <img src={biz.image} className="absolute inset-0 w-full h-full object-cover" alt="" />}
                                            <div className="absolute inset-0 bg-gradient-to-t from-black/80 to-transparent" />
                                            <div className="absolute bottom-2 left-2 right-2">
                                                <p className="text-white text-xs font-bold truncate">{biz.name}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="text-center py-6 text-slate-600 text-xs uppercase">No businesses owned</div>
                            )}
                        </div>

                        {/* BADGES – compact wrap */}
                        <div className="rounded-2xl border border-white/10 bg-slate-900/50 backdrop-blur-xl p-4 flex-1 flex flex-col">
                            <h3 className="text-xs font-black text-slate-400 uppercase tracking-wider mb-3">Achievements</h3>
                            {profile?.achievements?.length > 0 ? (
                                <div className="flex flex-wrap gap-3 mt-auto">
                                    {profile.achievements.map((a, i) => (
                                        <div key={i} className="group relative">
                                            <div className="w-14 h-14 rounded-full bg-slate-800/80 flex items-center justify-center transition-transform group-hover:scale-105">
                                                {a.icon_url ? <img src={a.icon_url} className="w-8 h-8" alt="" /> : <span className="text-3xl">{a.icon || '🏅'}</span>}
                                            </div>
                                            <span className="absolute -top-1 -right-1 w-2 h-2 rounded-full bg-amber-400 animate-pulse" />
                                            <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 px-2 py-0.5 bg-black/80 rounded text-[9px] text-white whitespace-nowrap opacity-0 group-hover:opacity-100 transition pointer-events-none">{a.name}</div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="flex-1 flex items-center justify-center py-6 text-slate-600 text-xs uppercase">No achievements yet</div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* ========== INTERACT MODAL – exactly as original ========== */}
            <StyledModal
                isOpen={showActionModal}
                onClose={() => { setShowActionModal(false); setInteractView('menu'); }}
                subtitle=""
                headerIcon={
                    <div className="flex flex-col items-center justify-center pt-8 pb-2">
                        <div className="w-24 h-24 rounded-3xl overflow-hidden border-4 border-white shadow-2xl mb-4">
                            {profile?.avatarUrl
                                ? <img src={profile.avatarUrl} className="w-full h-full object-cover" alt={profile.displayName} />
                                : <div className="w-full h-full bg-slate-800 flex items-center justify-center"><User size={40} className="text-slate-500" weight="bold" /></div>
                            }
                        </div>
                        <h2
                            className="max-w-full break-words text-center text-xl font-black uppercase leading-tight tracking-tight text-white"
                            style={{ overflowWrap: 'anywhere' }}
                        >
                            {profile?.displayName}
                        </h2>
                    </div>
                }
                maxWidth="max-w-sm"
            >
                {interactView === 'menu' && (
                    <div className="p-6 space-y-3">
                        <ActionButton onClick={() => router.get(`/messages/name/${profile.displayName}`)} icon={ChatTeardrop} variant="cyan" className="w-full">
                            Send Message
                        </ActionButton>
                        <ActionButton onClick={() => setInteractView('money')} icon={CurrencyDollar} variant="success" className="w-full">
                            Send Money
                        </ActionButton>
                        <ActionButton
                            onClick={handleCryptoScam}
                            disabled={scamming}
                            icon={CurrencyBtcIcon}
                            variant="warning"
                            className="w-full"
                        >
                            {scamming ? 'Running Scam...' : 'Run a Crypto Scam'}
                        </ActionButton>
                    </div>
                )}
                {interactView === 'money' && (
                    <div className="p-6 space-y-4">
                        <StyledInput label="Amount" type="number" value={sendAmount} onChange={e => setSendAmount(e.target.value)} placeholder="0" prefix="$" min={1} required hint="Transferred from your bank balance" />
                        <StyledInput label="Note (optional)" value={sendNote} onChange={e => setSendNote(e.target.value)} placeholder="Reason for transfer..." maxLength={100} />
                        <div className="flex gap-3 pt-2">
                            <ActionButton onClick={() => setInteractView('menu')} variant="secondary" className="flex-1">Back</ActionButton>
                            <ActionButton onClick={handleSendMoney} disabled={!sendAmount || sendingMoney} icon={PaperPlaneTilt} variant="success" className="flex-1">
                                {sendingMoney ? 'Sending...' : 'Transfer'}
                            </ActionButton>
                        </div>
                    </div>
                )}
            </StyledModal>
        </div>
    );
}

Profile.layout = page => <GameLayout children={page} />;
