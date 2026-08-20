import { usePage } from '@inertiajs/react';
import React, { useMemo } from 'react';
import {
    User, MapPin, Briefcase, Heart, SignOut, Gear,
    Clock, House, Target, Trophy, CurrencyDollar, Buildings,
    Users, Globe, CaretDown, CaretUp, Sword, Scales, ChartBar,
    Article, ChatTeardrop, Question, Car, Barbell,
    List, X, TrendUp, Shield, FirstAidKit, Gavel, Bank, Lightbulb, Lightning, Wrench,
    Desk, ChartLineDown,
    BuildingOffice, OfficeChair, Megaphone
} from '@phosphor-icons/react';
import { useServerClock } from '@/contexts/ClockContext';

export const glowColors = {
    cyan: { gradient: 'from-cyan-500 to-blue-500', label: 'Cyan' },
    red: { gradient: 'from-red-500 to-orange-500', label: 'Red' },
    green: { gradient: 'from-emerald-500 to-green-500', label: 'Green' },
    blue: { gradient: 'from-blue-500 to-indigo-500', label: 'Blue' },
    purple: { gradient: 'from-purple-500 to-pink-500', label: 'Purple' },
    yellow: { gradient: 'from-yellow-500 to-amber-500', label: 'Yellow' },
    pink: { gradient: 'from-pink-500 to-rose-500', label: 'Pink' },
};

export const formatRemaining = (seconds) => {
    if (seconds === 0) return 'Ready';
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;
    if (minutes < 60) {
        return remainingSeconds > 0 ? `${minutes}m ${remainingSeconds}s` : `${minutes}m`;
    }
    const hours = Math.floor(minutes / 60);
    const remainingMinutes = minutes % 60;
    return remainingMinutes > 0 ? `${hours}h ${remainingMinutes}m` : `${hours}h`;
};

export const TIMER_KEYS = [
    { key: 'next_work_at', label: 'Work', icon: Briefcase, weight: 'bold' },
    { key: 'next_action_at', label: 'Action', icon: Clock, weight: 'bold' },
    { key: 'next_study_at', label: 'Study', icon: ChartBar, weight: 'bold' },
    { key: 'next_travel_at', label: 'Travel', icon: Car, weight: 'bold' },
    { key: 'next_talents_at', label: 'Talents', icon: Trophy, weight: 'fill' },
    { key: 'next_conflict_at', label: 'Combat', icon: Sword, weight: 'fill' },
];

export const formatUTC = (timestamp, includeTime = true) => {
    if (!timestamp) return 'Recently';
    let date;
    if (typeof timestamp === 'number') {
        date = new Date(timestamp * 1000);
    } else {
        const s = String(timestamp);
        const normalised = /Z$|[+-]\d{2}:\d{2}$/.test(s)
            ? s
            : s.replace(' ', 'T').replace(/\.\d+$/, '') + 'Z';
        date = new Date(normalised);
    }
    const pad = (n) => n.toString().padStart(2, '0');
    const day = pad(date.getUTCDate());
    const month = pad(date.getUTCMonth() + 1);
    const year = date.getUTCFullYear().toString().slice(-2);
    const datePart = `${day}/${month}/${year}`;
    if (!includeTime) return datePart;
    const hours = pad(date.getUTCHours());
    const minutes = pad(date.getUTCMinutes());
    const seconds = pad(date.getUTCSeconds());
    return `${datePart} ${hours}:${minutes}:${seconds} UTC`;
};

export const IconMap = {
    Newspaper: Article,
    MessageSquare: ChatTeardrop,
    Briefcase,
    Car,
    Building: Buildings,
    Sword,
    Scale: Scales,
    BarChart3: ChartBar,
    ChartBar,
    Users,
    MapPin,
    Clock,
    User,
    Settings: Gear,
    HelpCircle: Question,
    TrendingUp: TrendUp,
    Shield,
    HeartPulse: FirstAidKit,
    Gavel,
    Landmark: Bank,
    Trading: ChartLineDown,
    BuildingOffice: BuildingOffice,
    Building2: Buildings,
    bulb: Lightbulb,
    Zap: Lightning,
    Swords: Sword,
    Wrench,
    OfficeChair,
    Megaphone,
};

export const resolveIcon = (iconName) => IconMap[iconName] || Question;

export const getFooterItems = () => [
    { label: 'Profile', icon: User, route: '/profile', weight: 'bold' },
    { label: 'Settings', icon: Gear, route: '/settings', weight: 'bold' },
];

export const formatCash = (value) => {
    if (value === null || value === undefined) return '$0';
    if (value >= 1_000_000_000) return `$${(value / 1_000_000_000).toFixed(1)}B`;
    if (value >= 1_000_000) return `$${(value / 1_000_000).toFixed(1)}M`;
    if (value >= 100_000) return `$${(value / 1_000).toFixed(0)}K`;
    return `$${value.toLocaleString()}`;
};

export const parseSymbolicAmount = (value) => {
    if (value === null || value === undefined) return 0;
    if (typeof value === 'number') return value;

    const cleanStr = String(value).replace(/[\$,]/g, '').trim().toLowerCase();
    if (!cleanStr) return 0;

    const kRegex = /^([\d\.]+)\s*k$/;
    const mRegex = /^([\d\.]+)\s*m$/;
    const bRegex = /^([\d\.]+)\s*b$/;

    let match;
    if ((match = cleanStr.match(kRegex))) {
        return Math.round(parseFloat(match[1]) * 1_000);
    } else if ((match = cleanStr.match(mRegex))) {
        return Math.round(parseFloat(match[1]) * 1_000_000);
    } else if ((match = cleanStr.match(bRegex))) {
        return Math.round(parseFloat(match[1]) * 1_000_000_000);
    }

    const parsed = parseInt(cleanStr, 10);
    return isNaN(parsed) ? 0 : parsed;
};

export const getBottomTabs = (character) => [
    { label: 'Work', icon: Briefcase, href: '/work', weight: 'bold' },
    { label: 'City', icon: Buildings, href: `/${character?.citySlug || 'city'}`, weight: 'bold' },
    { label: 'Conflict', icon: Sword, href: '/conflict', weight: 'fill' },
    { label: 'Messages', icon: ChatTeardrop, href: '/messages', weight: 'bold' },
];

// City background images - add new cities here, death overrides all of these
const CITY_BACKGROUNDS = {
    'New York': 'https://images.thedirector.app/newyorktooltip.png',
    'Tokyo': 'https://images.thedirector.app/tokyotooltip.png',
    'Seoul': 'https://images.thedirector.app/seoultooltip.jpg',
};

const EXECUTIVE_POSITIONS = new Set(['group_president', 'chairman', 'director_of_board']);

const CORPORATION_POSITION_LABELS = {
    cfo: 'CFO',
    cto: 'CTO',
    vp: 'VP',
    group_president: 'GROUP PRESIDENT',
    chairman: 'CHAIRMAN',
    director_of_board: 'DIRECTOR OF THE BOARD',
};

export const isExecutivePlayerName = (player) => {
    const position = player?.corporationPosition?.toLowerCase() || '';
    const rank = player?.rank?.toLowerCase() || '';

    return Boolean(
        player?.isCeo
        || EXECUTIVE_POSITIONS.has(position)
        || rank.includes('managing director')
        || rank.includes('group president')
        || rank.includes('chairman')
        || rank.includes('director of the board')
    );
};

export const formatOnlinePlayerName = (player) => {
    const name = player?.displayName ?? '';

    return isExecutivePlayerName(player) ? name.toUpperCase() : name.toLowerCase();
};

const fmtCorpPosV2 = (player) => {
    const pos = player?.corporationPosition?.toLowerCase();
    const positionLabel = CORPORATION_POSITION_LABELS[pos] ?? 'MEMBER';

    if (player?.corporationIsHoldingCompany && EXECUTIVE_POSITIONS.has(pos)) {
        return player?.isFounder ? `FOUNDER / ${positionLabel}` : positionLabel;
    }

    if (player?.isCeo && player?.isFounder) return 'FOUNDER / CEO';
    if (player?.isCeo && player?.corporationParentTrustName) return 'SUBSIDIARY CEO';
    if (player?.isCeo) return 'CEO';
    if (player?.isFounder && positionLabel !== 'MEMBER') return `FOUNDER / ${positionLabel}`;
    if (player?.isFounder) return 'FOUNDER';

    return positionLabel;
};

export const PlayerTooltip = ({ player, arrowOffsetX = 0 }) => {
    const serverClock = useServerClock();

    const formatLastActivity = (timestamp) => {
        const diff = serverClock - timestamp;
        if (diff < 60) return 'Just now';
        if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
        if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
        return `${Math.floor(diff / 86400)}d ago`;
    };

    const currentGlow = player.is_dead ? glowColors.red : (glowColors[player.glowColor] || glowColors.cyan);
    const rankLabel = player.rank?.toLowerCase() || '';
    const hasPriorityGlow = player.is_dead || rankLabel.includes('mayor') || rankLabel.includes('chairman');
    const glowOpacity = hasPriorityGlow ? 0.9 : 0.1;
    const hasCorp = !!player.corporationName;
    const isCustomAvatar = !!player.avatarUrl && !player.avatarUrl.includes('images.thedirector.app');
    const corporationContext = player.corporationIsHoldingCompany
        ? 'Holding company'
        : player.corporationParentTrustName
            ? `Reports to ${player.corporationParentTrustName}`
            : null;

    // City background — only when alive; death always wins
    const cityBg = !player.is_dead ? (CITY_BACKGROUNDS[player.homeCity] ?? null) : null;

    return (
        <div className="relative pointer-events-none">
            {/* Glow ring */}
            <div
                className={`absolute -inset-1 bg-gradient-to-r ${currentGlow.gradient} blur-xl rounded-2xl`}
                style={{ opacity: glowOpacity }}
            />

            {/* Tooltip card */}
            <div className={`relative backdrop-blur-xl border rounded-2xl shadow-2xl w-[286px] ring-1 ring-white/5 overflow-hidden ${player.is_dead
                ? 'bg-gradient-to-br from-red-950/95 to-slate-950/95 border-red-900/80'
                : 'bg-gradient-to-br from-slate-900/90 to-slate-950/90 border-slate-800'
                }`}>

                {/* City background image + scrim — absolutely positioned behind all content */}
                {cityBg && (
                    <>
                        <img
                            src={cityBg}
                            alt=""
                            aria-hidden="true"
                            draggable={false}
                            className="absolute inset-0 w-full h-full object-cover select-none pointer-events-none"
                            style={{ zIndex: 0 }}
                        />
                        {/* Dark scrim keeps all text legible over any photo */}
                        <div
                            className="absolute inset-0 pointer-events-none"
                            style={{
                                background: 'linear-gradient(135deg, rgba(15,23,42,0.58) 0%, rgba(2,6,23,0.72) 100%)',
                                zIndex: 1,
                            }}
                        />
                    </>
                )}

                {/* All foreground content sits above the image and scrim */}
                <div className="relative" style={{ zIndex: 2 }}>

                    {/* Downward caret */}
                    <div
                        className="absolute top-full -translate-x-1/2 -mt-[3px] z-10"
                        style={{ left: `calc(50% + ${arrowOffsetX}px)` }}
                    >
                        <div className={`border-[10px] border-transparent ${player.is_dead ? 'border-t-red-900/80' : 'border-t-slate-800'
                            }`} />
                    </div>
                    <div
                        className="absolute top-full -translate-x-1/2 z-10"
                        style={{ left: `calc(50% + ${arrowOffsetX}px)` }}
                    >
                        <div className={`border-[10px] border-transparent ${player.is_dead ? 'border-t-red-950/95' : 'border-t-slate-950/90'
                            }`} />
                    </div>

                    {/* Corporation banner */}
                    {hasCorp && (
                        <div className="relative h-[4.5rem] overflow-hidden border-b border-white/[0.06]">
                            {player.corporationImageUrl && (
                                <img
                                    src={player.corporationImageUrl}
                                    className="absolute inset-0 w-full h-full object-cover"
                                    style={{ filter: 'brightness(0.5) saturate(1.2)' }}
                                    alt=""
                                />
                            )}
                            <div className="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-transparent to-transparent" />
                            <div className="absolute bottom-0 left-0 right-0 flex items-end justify-between gap-2 px-3 pb-2">
                                <span className="min-w-0">
                                    <span className="block truncate text-[11px] font-black text-white">
                                        {player.corporationName}
                                    </span>
                                    {corporationContext && (
                                        <span className="mt-0.5 block truncate text-[8px] font-black uppercase tracking-[0.18em] text-cyan-200/85">
                                            {corporationContext}
                                        </span>
                                    )}
                                </span>
                                <span className="shrink-0 rounded-full border border-white/20 bg-white/10 px-1.5 py-0.5 text-[8px] font-black uppercase tracking-widest text-white">
                                    {fmtCorpPosV2(player)}
                                </span>
                            </div>
                        </div>
                    )}

                    {/* Main content */}
                    <div className="flex gap-3 p-3">
                        {/* Avatar */}
                        <div className="relative shrink-0 flex items-center">
                            <div className={`w-24 h-24 rounded-full overflow-hidden bg-gradient-to-br from-slate-800 to-slate-950 ${isCustomAvatar ? 'border-[3px] border-white' : ''
                                }`}>
                                {player.avatarUrl ? (
                                    <img
                                        src={player.avatarUrl}
                                        className={`w-full h-full object-cover ${player.is_dead ? 'grayscale opacity-80' : ''}`}
                                        alt={player.displayName}
                                    />
                                ) : (
                                    <div className={`w-full h-full flex items-center justify-center ${player.is_dead ? 'bg-gradient-to-br from-red-950 to-slate-950' : ''
                                        }`}>
                                        <User
                                            className={`h-10 w-10 ${player.is_dead ? 'text-red-900' : 'text-slate-700'}`}
                                            weight="bold"
                                        />
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Info rows */}
                        <div className="flex-1 min-w-0 space-y-1.5">
                            <div className="text-white font-black text-base tracking-tight break-words uppercase">
                                {player.displayName}
                            </div>
                            <div className="space-y-1">
                                <div className="flex items-baseline gap-2">
                                    <span className="text-[8px] text-slate-300 uppercase tracking-widest font-semibold w-14 shrink-0">Career</span>
                                    <span className="text-[11px] font-bold text-white break-words min-w-0">{player.career || 'Unknown'}</span>
                                </div>
                                <div className="flex items-baseline gap-2">
                                    <span className="text-[8px] text-slate-300 uppercase tracking-widest font-semibold w-14 shrink-0">Rank</span>
                                    <span className="text-[11px] font-bold text-white break-words min-w-0">{player.rank || 'Entry Level'}</span>
                                </div>
                                {(player.is_dead || player.is_jailed || player.is_hospitalized) && (
                                    <div className="flex items-baseline gap-2">
                                        <span className="text-[8px] text-slate-300 uppercase tracking-widest font-semibold w-14 shrink-0">Status</span>
                                        <span className={`text-[11px] font-bold break-words min-w-0 ${player.is_dead ? 'text-red-400' : player.is_jailed ? 'text-green-400' : 'text-yellow-400'
                                            }`}>
                                            {player.is_dead ? 'DEAD' : player.is_jailed ? 'In Jail' : 'In Hospital'}
                                        </span>
                                    </div>
                                )}
                            </div>
                            {player.homeCity && (
                                <div className="flex items-baseline gap-2 pt-1.5 mt-0.5 border-t border-white/10">
                                    <span className="text-[8px] text-slate-300 uppercase tracking-widest font-semibold w-14 shrink-0">HomeCity</span>
                                    <span className="text-[11px] font-bold text-white break-words min-w-0">{player.homeCity}</span>
                                </div>
                            )}
                            {player.lastActivity && (
                                <div className="text-[9px] text-slate-300 font-medium text-right mt-1.5">
                                    {formatLastActivity(player.lastActivity)}
                                </div>
                            )}
                        </div>
                    </div>

                </div>
            </div>
        </div>
    );
};

// ── Player name styling ──────────────────────────────────────────────────────
// Keyframes for .td-name-board and .td-name-president live in app.css.

export const getPlayerNameClass = (player) => {
    // ── Status overrides (highest priority) ───────────────────────────────
    if (player.is_dead) return 'text-red-400 group-hover:text-red-300';
    if (player.is_jailed) return 'text-emerald-400 group-hover:text-emerald-300';
    if (player.is_hospitalized) return 'text-gray-400 group-hover:text-gray-300';

    const rank = (player.rank ?? '').toLowerCase();
    const career = (player.career ?? '').toLowerCase();
    const position = (player.corporationPosition ?? '').toLowerCase();


    if (rank === 'director of the board') {
        return 'td-name-board';
    }
    if (position === 'group_president' || rank.includes('group president') || position === 'chairman' || rank.includes('chairman')) {
        return 'td-name-president';
    }

    if (rank.includes('mayor')) {
        return 'italic text-slate-300 group-hover:text-slate-100';
    }

    if (career.includes('police officer') || career.includes('police')) {
        if (rank.includes('commissioner')) {
            return 'inline-block bg-gradient-to-r from-blue-500 to-indigo-500 bg-clip-text text-transparent font-black tracking-wide';
        }
        return 'text-blue-400 font-bold tracking-wide';
    }

    if (career.includes('lawyer') || career.includes('law')) {
        if (rank.includes('chief justice')) {
            return 'inline-block bg-gradient-to-r from-purple-500 to-fuchsia-600 bg-clip-text text-transparent font-black tracking-wide';
        }
        // Standard law purple
        return 'text-purple-400 font-semibold tracking-wide';
    }

    if (player.isCeo || rank.includes('managing director')) {
        return 'td-name-md';
    }
    return 'text-white/75 group-hover:text-white/90';
};

/**
 * @deprecated Use getPlayerNameClass instead.
 * Kept so existing callers don't break during migration.
 */
export const getPlayerBorderClass = (player) => getPlayerNameClass(player);

export const ClockDisplay = React.memo(function ClockDisplay() {
    const serverClock = useServerClock();
    const formatted = formatUTC(serverClock);
    return (
        <span className="font-mono text-xs tabular-nums">
            {formatted}
        </span>
    );
});

export const CooldownDisplay = React.memo(function CooldownDisplay({ timerKey, label, icon: Icon, weight }) {
    const { auth } = usePage().props;
    const serverClock = useServerClock();
    const timers = auth?.character?.timers;

    const remaining = useMemo(() => {
        if (!timers || timers[timerKey] == null) return 0;
        const timerValue = Number(timers[timerKey]);
        if (isNaN(timerValue)) return 0;
        return Math.max(0, timerValue - serverClock);
    }, [timers, timerKey, serverClock]);

    const formatted = formatRemaining(remaining);
    const ready = remaining === 0;

    return (
        <div className={`flex items-center gap-2 shrink-0 text-xs font-medium transition ${ready ? 'text-emerald-400' : 'text-slate-400'}`}>
            <span className="font-semibold">{label}:</span>
            <span className="font-semibold tabular-nums">{formatted}</span>
        </div>
    );
});

export const CooldownsList = React.memo(function CooldownsList() {
    return (
        <>
            {TIMER_KEYS.map((t) => (
                <CooldownDisplay key={t.key} timerKey={t.key} label={t.label} icon={t.icon} weight={t.weight} />
            ))}
        </>
    );
});

export const MobileCooldownsList = React.memo(function MobileCooldownsList() {
    return (
        <div className="flex flex-wrap gap-4">
            {TIMER_KEYS.map((t) => (
                <CooldownDisplay key={t.key} timerKey={t.key} label={t.label} icon={t.icon} weight={t.weight} />
            ))}
        </div>
    );
});
