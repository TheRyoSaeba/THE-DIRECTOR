import { useState } from 'react';
import { Head } from '@inertiajs/react';
import GameLayout from '@/Layouts/GameLayout';
import {
    User, X, CaretLeft, CaretRight,
    MapPin, Sword, Briefcase, Buildings,RankingIcon,CrosshairSimpleIcon,SuitcaseSimpleIcon 
} from '@phosphor-icons/react';
import { motion, AnimatePresence } from 'framer-motion';

// ── Reusable avatar — ring is caller's responsibility via extraClass ──────────
function Avatar({ url, name, size = 36, grayscale = false, extraClass = '' }) {
    const [error, setError] = useState(false);
    const iconSize = Math.round(size * 0.44);

    return (
        <div
            className={`relative rounded-full overflow-hidden bg-gradient-to-br from-slate-800 to-slate-950 flex items-center justify-center shrink-0 ${extraClass}`}
            style={{ width: size, height: size }}
        >
            {!error && url ? (
                <img
                    src={url}
                    alt={name}
                    className={`w-full h-full object-cover relative z-0 ${
                        grayscale ? 'grayscale opacity-70' : ''
                    }`}
                    onError={() => setError(true)}
                />
            ) : (
                <User size={iconSize} weight="bold" className="text-slate-600 z-0" />
            )}
        </div>
    );
}

 

const fmtNum = (n) => Math.round(n ?? 0).toLocaleString();

const getOrdinal = (n) => {
    const s = ['th', 'st', 'nd', 'rd'], v = n % 100;
    return n + (s[(v - 20) % 10] || s[v] || s[0]);
};

// Framed club affiliation strip. Corp image_url is stored at banner
// aspect ratio (not square), so it sits as a full-bleed background
// with a darkening gradient over it for text legibility. The corp
// name is overlaid in a quiet "AFFILIATION" label + bold name pair.
// When the corp has no image_url (Corporation.image_url is nullable
// for older corps) a faint Buildings glyph fills the negative space
// so the section never reads as broken or empty.
//
// The single one-shot shine sweep after entrance is the signature
// premium touch — it runs once when the modal opens and then settles,
// echoing the shimmer on Legend/Gold tier rows without committing to
// an infinite distracting loop.
function CorpBanner({ imageUrl, name }) {
    const [imgError, setImgError] = useState(false);
    const hasImage = imageUrl && !imgError;

    return (
        <motion.div
            initial={{ opacity: 0, y: -10, scaleY: 0.78 }}
            animate={{ opacity: 1, y: 0, scaleY: 1 }}
            transition={{ delay: 0.18, type: 'spring', stiffness: 240, damping: 22 }}
            className="relative mx-5 mt-5 mb-1 h-[88px] rounded-xl border-2 border-white/15 overflow-hidden shadow-xl shadow-black/40"
        >
            {/* Banner background — full-bleed cover crop. */}
            {hasImage ? (
                <img
                    src={imageUrl}
                    alt=""
                    onError={() => setImgError(true)}
                    className="absolute inset-0 w-full h-full object-cover"
                />
            ) : (
                <>
                    <div className="absolute inset-0 bg-gradient-to-br from-slate-800 via-slate-900 to-slate-950" />
                    <Buildings
                        size={92}
                        weight="duotone"
                        className="absolute -right-3 -bottom-2 text-white/[0.06]"
                    />
                </>
            )}

            {/* Legibility scrim — slightly heavier on the left so the
                name overlay reads cleanly regardless of banner image. */}
            <div className="absolute inset-0 bg-gradient-to-r from-black/85 via-black/55 to-black/30" />

            {/* One-shot shine sweep — premium polish on entrance, then settles. */}
            <motion.div
                initial={{ x: '-110%' }}
                animate={{ x: '130%' }}
                transition={{ delay: 0.55, duration: 1.3, ease: 'easeInOut' }}
                className="absolute top-0 bottom-0 w-1/3 -skew-x-12 bg-gradient-to-r from-transparent via-white/[0.18] to-transparent pointer-events-none"
            />

            {/* Name overlay — left-aligned, quiet kicker above the bold name. */}
            <div className="absolute inset-0 flex flex-col justify-center px-5">
                <span className="text-[9px] font-black uppercase tracking-[0.32em] text-white/55">
                    Corporation
                </span>
                <span className="text-base font-black uppercase tracking-tight text-white truncate drop-shadow-[0_2px_4px_rgba(0,0,0,0.6)]">
                    {name ?? 'Unaffiliated'}
                </span>
            </div>
        </motion.div>
    );
}

// ── Tier system (FIFA UT inspired) ─────────────────────────────────────────
const getTier = (r) => {
    if (r >= 90) return 'legend';
    if (r >= 80) return 'gold';
    if (r >= 60) return 'cyan';
    return 'bronze';
};

const TIER = {
    legend: {
        label:        'LEGEND',
        // Row: clean white-silver shimmer — the FIFA Icon "black card" feel
        rowBg:        'bg-gradient-to-br from-white/[0.08] via-slate-300/[0.05] to-white/[0.08] shimmer',
        rowAccent:    'border-l-4 border-white/50',
        rankText:     'text-white',
        nameText:     'text-white group-hover:text-white',
        badgeBg:      'bg-gradient-to-br from-white/20 via-slate-200/15 to-white/20 shimmer',
        badgeBorder:  'border-white/40',
        badgeText:    'text-white',
        // Modal
        modalBorder:  'border-white',
        modalGlow:    'rgba(255,255,255,0.4)',
        glowScale:    [1, 1.22, 1],
        glowOpacity:  [0.4, 0.72, 0.4],
        glowDuration: 2,
        modalRadial:  'radial-gradient(ellipse at 50% 30%, rgba(255,255,255,0.14), transparent 60%)',
        ratingText:   'text-white',
        avatarRing:   '',
    },
    gold: {
        label:        'GOLD',
        rowBg:        'bg-gradient-to-br from-yellow-300/20 via-amber-300/15 to-yellow-300/20 shimmer',
        rowAccent:    'border-l-4 border-amber-400/60',
        rankText:     'text-yellow-400',
        nameText:     'text-yellow-100 group-hover:text-white',
        badgeBg:      'bg-gradient-to-br from-yellow-300/30 via-amber-300/25 to-yellow-300/30 shimmer',
        badgeBorder:  'border-amber-400/60',
        badgeText:    'text-yellow-400',
        modalBorder:  'border-amber-400/70',
        modalGlow:    'rgba(251,191,36,0.5)',
        glowScale:    [1, 1.15, 1],
        glowOpacity:  [0.5, 0.85, 0.5],
        glowDuration: 2.5,
        modalRadial:  'radial-gradient(ellipse at 50% 30%, rgba(251,191,36,0.12), transparent 70%)',
        ratingText:   'text-yellow-400',
        avatarRing:   '',
    },
    cyan: {
        label:        'ELITE',
        rowBg:        'bg-cyan-500/[0.07]',
        rowAccent:    'border-l-4 border-cyan-500/30',
        rankText:     'text-cyan-400',
        nameText:     'text-slate-200 group-hover:text-white',
        badgeBg:      'bg-cyan-500/15',
        badgeBorder:  'border-cyan-400/40',
        badgeText:    'text-cyan-400',
        modalBorder:  'border-cyan-500/60',
        modalGlow:    'rgba(34,211,238,0.4)',
        glowScale:    [1, 1.1, 1],
        glowOpacity:  [0.35, 0.6, 0.35],
        glowDuration: 3.5,
        modalRadial:  'radial-gradient(ellipse at 50% 30%, rgba(34,211,238,0.1), transparent 70%)',
        ratingText:   'text-cyan-400',
        avatarRing:   '',
    },
    bronze: {
        label:        'BRONZE',
        rowBg:        'bg-transparent',
        rowAccent:    '',
        rankText:     'text-slate-700',
        nameText:     'text-slate-400 group-hover:text-slate-200',
        badgeBg:      'bg-orange-600/10',
        badgeBorder:  'border-orange-500/30',
        badgeText:    'text-orange-300',
        modalBorder:  'border-orange-500/30',
        modalGlow:    'rgba(194,65,12,0.35)',
        glowScale:    [1, 1.06, 1],
        glowOpacity:  [0.2, 0.38, 0.2],
        glowDuration: 5,
        modalRadial:  'radial-gradient(ellipse at 50% 30%, rgba(194,65,12,0.08), transparent 70%)',
        ratingText:   'text-orange-300',
        avatarRing:   '',
    },
};

// Rank 1–3 get podium colors on the number; 4–10 are slightly lit; 11+ are dim
const rankColor = (rank) => {
    if (rank === 1) return 'text-yellow-400';
    if (rank === 2) return 'text-slate-300';
    if (rank === 3) return 'text-amber-700';
    if (rank <= 10) return 'text-slate-500';
    return 'text-slate-700';
};

 
function PlayerCardModal({ entry, rank, isHistorical, onClose }) {
    if (!entry) return null;

    // Tier is rating-driven, not life-status driven — a Legend-rated
    // dead character is still a Legend. We compute it for everyone so
    // the historical card surfaces the tier label (LEGEND / GOLD /
    // ELITE / BRONZE) in the same slot as living cards. Tier-tinted
    // styling that should only apply to the living (modal glow,
    // radial backdrop, tier-colored rating number) is gated by an
    // explicit `!isHistorical` check at each render site.
    const tier = TIER[getTier(entry.rating)];

    // Custom portrait uploads get the thick white ring (matches
    // Profile.jsx). Applies to both living and historical entries —
    // death doesn't strip the custom-portrait affordance.
    const avatarIsCustom = !!entry.avatar_url && !entry.avatar_url.includes('images.thedirector.app');
    const avatarRingClass = avatarIsCustom ? 'ring-4 ring-white' : '';

    return (
        <div
            className="fixed inset-0 z-[100] flex items-center justify-center p-4 backdrop-blur-sm"
            onClick={onClose}
        >
            <motion.div
                initial={{ opacity: 0, scale: 0.88, y: 24 }}
                animate={{ opacity: 1, scale: 1, y: 0 }}
                exit={{ opacity: 0, scale: 0.88, y: 24 }}
                transition={{ type: 'spring', stiffness: 260, damping: 22 }}
                className="relative w-full max-w-sm"
                onClick={e => e.stopPropagation()}
            >
                {/* Tier ambient glow behind the card */}
                {!isHistorical && tier?.modalGlow && (
                    <motion.div
                        animate={{ scale: tier.glowScale, opacity: tier.glowOpacity }}
                        transition={{ duration: tier.glowDuration, repeat: Infinity, ease: 'easeInOut' }}
                        className="absolute inset-[-60px] rounded-full blur-[80px] pointer-events-none"
                        style={{ background: tier.modalGlow }}
                    />
                )}

                <div className={`relative rounded-3xl border-4 bg-slate-950 overflow-hidden shadow-2xl ${
                    isHistorical ? 'border-slate-700/60' : (tier?.modalBorder ?? 'border-slate-700')
                }`}>

                    {/* Card portrait area. Hosts the rating, ordinal,
                        avatar, name, and (for historical entries only)
                        the deceased-date kicker. Club affiliation now
                        lives in its own framed banner section below
                        this block, so the portrait can return to its
                        original tighter minHeight. */}
                    <div className="relative bg-slate-900 overflow-hidden" style={{ minHeight: '260px' }}>

                        {/* Tier radial glow behind avatar */}
                        {!isHistorical && tier?.modalRadial && (
                            <div className="absolute inset-0 opacity-100 pointer-events-none"
                                style={{ background: tier.modalRadial }} />
                        )}

                        <button
                            onClick={onClose}
                            className="absolute top-4 right-4 z-30 p-1.5 rounded-full bg-black/50 hover:bg-black/80 text-slate-400 hover:text-white transition-colors"
                        >
                            <X size={16} weight="bold" />
                        </button>

                        {/* Rating + tier label top-left. The tier
                            label always shows the rating-derived tier
                            (LEGEND / GOLD / ELITE / BRONZE) — death is
                            conveyed by the deceased-date kicker under
                            the player name and the greyed rating
                            number, not by overwriting the tier slot. */}
                        <div className="absolute top-5 left-5 z-20 flex flex-col items-start gap-0.5">
                            <span className={`text-5xl font-black leading-none tabular-nums ${
                                isHistorical ? 'text-slate-400' : tier.ratingText
                            }`}>
                                {entry.rating}
                            </span>
                            <span className={`text-[9px] font-black uppercase tracking-[0.25em] ${
                                isHistorical ? 'text-slate-600' : tier.ratingText
                            } opacity-60`}>
                                {tier.label}
                            </span>
                        </div>

                        {/* Ordinal top-right. Anchored at top-5 to sit
                            eye-to-eye with the rating block's top edge,
                            pushed left of the close button via right-16
                            to avoid collision. */}
                        <div className="absolute top-5 right-16 z-20 text-right">
                            <span className="text-[10px] font-black uppercase tracking-widest text-white">
                                {getOrdinal(rank)} overall
                            </span>
                        </div>

                        {/* Avatar */}
                        <div className="relative flex justify-center items-center pt-10 pb-6 z-10">
                            <Avatar
                                url={entry.avatar_url}
                                name={entry.display_name}
                                size={144}
                                grayscale={isHistorical}
                                extraClass={`${avatarRingClass} shadow-2xl`}
                            />
                        </div>

                        {/* Name + (deceased kicker for historical). The
                            "Active" pill for current entries is
                            intentionally omitted — being listed on the
                            Current tab already conveys that the player
                            is alive, so the pill is redundant noise. */}
                        <div className="absolute bottom-0 left-0 right-0 px-6 pb-4 text-center">
                            <h3 className="text-xl font-black text-white uppercase tracking-tight truncate">
                                {entry.display_name}
                            </h3>
                            {isHistorical && (
                                <p className="text-[10px] text-red-400/70 uppercase tracking-widest mt-0.5">
                                    {entry.born_at
                                        ? `${entry.born_at} — ${entry.died_at}`
                                        : `Deceased · ${entry.died_at ?? '—'}`}
                                </p>
                            )}
                        </div>
                    </div>

                    {/* Club affiliation banner — only renders when the
                        snapshot captured a corp. Sits between the
                        portrait and the stats list as its own discrete
                        section so the corp's banner image gets room to
                        breathe without competing with the avatar. */}
                    {(entry.corporation_name || entry.corporation_image_url) && (
                        <CorpBanner
                            imageUrl={entry.corporation_image_url}
                            name={entry.corporation_name}
                        />
                    )}

                    {/* Stats */}
                    <div className="bg-slate-950 px-6 py-5 space-y-2.5">
                        {[
                            { icon: RankingIcon, label: 'Rank',  value: entry.rank_name },
                            { icon: MapPin,    label: 'Home City',  value: entry.home_city_name },
                            ...(isHistorical ? [
                                { icon: CrosshairSimpleIcon ,    label: 'Kills',        value: fmtNum(entry.kills) },
                                { icon: SuitcaseSimpleIcon ,label: 'Total Works',  value: fmtNum(entry.total_works) },
                            ] : []),
                        ].map(({ icon: Icon, label, value }) => (
                            <div key={label} className="flex items-center justify-between py-2 border-b border-white/5 last:border-0">
                                <div className="flex items-center gap-2">
                                    <Icon size={11} className="text-slate-600 shrink-0" />
                                    <span className="text-[10px] font-black uppercase tracking-widest text-slate-500">{label}</span>
                                </div>
                                <span className="text-xs font-bold text-slate-200 truncate max-w-[140px] text-right">{value ?? '—'}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </motion.div>
        </div>
    );
}

 

function LeaderboardRow({ entry, rank, onClick, index }) {
    const tier = TIER[getTier(entry.rating)];
    const rc   = rankColor(rank);

    return (
        <motion.button
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: index * 0.018, duration: 0.25, ease: 'easeOut' }}
            whileHover={{ x: 6, backgroundColor: 'rgba(255,255,255,0.02)' }}
            onClick={() => onClick(entry, rank)}
            className={`
                w-full text-left group relative flex items-center gap-4 px-6 py-3
                border-b border-white/5 transition-all last:border-b-0
                ${tier.rowBg} ${tier.rowAccent}
            `}
        >
            {/* Rank number — podium colors for 1/2/3, dim for the rest */}
            <span className={`w-8 shrink-0 text-xs font-black tabular-nums tracking-tighter ${rc}`}>
                {rank.toString().padStart(2, '0')}
            </span>

            {/* Name */}
            <span className={`flex-1 min-w-0 text-sm font-bold uppercase tracking-tight truncate transition-colors ${tier.nameText}`}>
                {entry.display_name}
            </span>

            {/* Rating badge — kept intentionally minimal. Club
                affiliation lives in the modal's banner section so
                the row stays scannable as a pure ranking. */}
            <div className={`shrink-0 w-8 h-8 rounded-lg border flex items-center justify-center ${tier.badgeBg} ${tier.badgeBorder}`}>
                <span className={`text-xs font-black tabular-nums ${tier.badgeText}`}>{entry.rating}</span>
            </div>
        </motion.button>
    );
}

 

function Pagination({ currentPage, totalPages, onPageChange }) {
    if (totalPages <= 1) return null;
    return (
        <div className="flex items-center justify-center gap-3 py-8 border-t border-white/5">
            <button
                disabled={currentPage === 1}
                onClick={() => onPageChange(currentPage - 1)}
                className="p-2.5 rounded-xl bg-white/5 border border-white/10 text-slate-400 hover:text-white disabled:opacity-25 disabled:cursor-not-allowed transition"
            >
                <CaretLeft size={18} weight="bold" />
            </button>
            <div className="flex gap-1.5">
                {[...Array(totalPages)].map((_, i) => (
                    <button
                        key={i + 1}
                        onClick={() => onPageChange(i + 1)}
                        className={`w-10 h-10 rounded-xl text-xs font-black transition ${currentPage === i + 1
                            ? 'bg-cyan-500 text-slate-950 shadow-[0_0_16px_rgba(34,211,238,0.35)]'
                            : 'bg-white/5 border border-white/10 text-slate-500 hover:border-white/25'
                            }`}
                    >
                        {i + 1}
                    </button>
                ))}
            </div>
            <button
                disabled={currentPage === totalPages}
                onClick={() => onPageChange(currentPage + 1)}
                className="p-2.5 rounded-xl bg-white/5 border border-white/10 text-slate-400 hover:text-white disabled:opacity-25 disabled:cursor-not-allowed transition"
            >
                <CaretRight size={18} weight="bold" />
            </button>
        </div>
    );
}

 

export default function Leaderboard({ current, historical }) {
    const [activeTab, setActiveTab] = useState('current');
    const [currentPage, setCurrentPage] = useState(1);
    const [selected, setSelected] = useState(null); // { entry, rank }
    const itemsPerPage = 10;

    const entries = activeTab === 'current' ? (current ?? []) : (historical ?? []);
    const totalPages = Math.ceil(entries.length / itemsPerPage);

    const handleTabChange = (id) => { setActiveTab(id); setCurrentPage(1); };

    const tabs = [
        { id: 'current', label: 'Current', sub: 'Updated every 24h', count: current?.length ?? 0 },
        { id: 'historical', label: 'Historical', sub: 'Hall of the fallen', count: historical?.length ?? 0 },
    ];

    const startIndex = (currentPage - 1) * itemsPerPage;
    const visibleEntries = entries.slice(startIndex, startIndex + itemsPerPage);

    return (
        <>
            <Head title="Leaderboard" />

            <div className="space-y-6 max-w-4xl mx-auto pb-16 px-4">

              
                <motion.div
                    initial={{ opacity: 0, y: -16 }}
                    animate={{ opacity: 1, y: 0 }}
                    className="rounded-3xl border-4 border-slate-700 bg-slate-900/60 backdrop-blur-xl shadow-2xl overflow-hidden"
                >
                    <div className="px-10 py-8 flex items-center gap-6">
                       
                        <motion.div
                            initial={{ scale: 0.7, rotate: -8, opacity: 0 }}
                            animate={{ scale: 1, rotate: 0, opacity: 1 }}
                            transition={{ type: 'spring', stiffness: 320, damping: 18, delay: 0.1 }}
                            className="relative shrink-0 w-16 h-16 rounded-2xl border-2 border-cyan-500/40 bg-cyan-500/5 flex items-center justify-center shadow-[0_0_24px_rgba(34,211,238,0.15)]"
                        >
                            <motion.div
                                className="w-11 h-11"
                                animate={{ rotateY: 360 }}
                                transition={{ duration: 6, repeat: Infinity, ease: 'linear' }}
                                style={{ transformStyle: 'preserve-3d' }}
                            >
                                <img
                                    src="https://images.thedirector.app/Careers/promo.png"
                                    className="w-full h-full object-contain"
                                    style={{ backfaceVisibility: 'visible' }}
                                    alt="logo"
                                />
                            </motion.div>
                        </motion.div>

                        <div>
                            <h1 className="text-5xl font-black text-white uppercase tracking-tighter mb-1">
                                Game Rankings
                            </h1>
                            <p className="text-sm text-slate-500 font-bold italic">
                                The ever sought after list of the best players in the game past and present. Your name only shows up here if you're at  least in the top 50th percentile.
                            </p>
                        </div>
                    </div>
                </motion.div>

                
                <motion.div
                    initial={{ opacity: 0, y: 16 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 0.08 }}
                    className="rounded-3xl border-4 border-slate-700 bg-slate-900/40 backdrop-blur-xl shadow-2xl overflow-hidden"
                >
                   
                    <div className="border-b border-white/10 flex">
                        {tabs.map(tab => (
                            <button
                                key={tab.id}
                                onClick={() => handleTabChange(tab.id)}
                                className={`
                                    flex-1 flex flex-col items-center justify-center gap-1 py-6 px-4 transition-all
                                    ${activeTab === tab.id
                                        ? 'text-cyan-400 border-b-2 border-cyan-400 bg-cyan-500/5'
                                        : 'text-slate-500 hover:text-slate-300 border-b-2 border-transparent hover:bg-white/[0.02]'
                                    }
                                `}
                            >
                                <div className="flex items-center gap-2">
                                    <span className="text-sm font-black uppercase tracking-[0.18em]">{tab.label}</span>
                                    {tab.count > 0 && (
                                        <span className={`text-[10px] px-1.5 py-0.5 rounded-full font-bold ${activeTab === tab.id ? 'bg-cyan-500 text-slate-950' : 'bg-slate-800 text-slate-500'}`}>
                                            {tab.count}
                                        </span>
                                    )}
                                </div>
                                <span className={`text-[9px] uppercase tracking-widest ${activeTab === tab.id ? 'text-cyan-500/60' : 'text-slate-700'}`}>
                                    {tab.sub}
                                </span>
                            </button>
                        ))}
                    </div>

                    <div className="flex items-center gap-4 px-6 py-2.5 border-b border-white/5">
                        <span className="w-8 shrink-0 text-[9px] font-black uppercase tracking-widest text-slate-700">#</span>
                        <span className="flex-1 text-[9px] font-black uppercase tracking-widest text-slate-700">Name</span>
                        <span className="w-8 shrink-0 text-[9px] font-black uppercase tracking-widest text-slate-700 text-center">Rtg</span>
                    </div>

                  
                    <div className="min-h-[420px] flex flex-col">
                        {visibleEntries.length === 0 ? (
                            <div className="flex-1 flex items-center justify-center text-slate-700 text-sm py-20">
                                {activeTab === 'current' ? 'No active Players on the board yet.' : 'No historical records yet.'}
                            </div>
                        ) : (
                            <AnimatePresence mode="wait">
                                <motion.div
                                    key={`${activeTab}-${currentPage}`}
                                    initial={{ opacity: 0, x: 4 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    exit={{ opacity: 0, x: -4 }}
                                    transition={{ duration: 0.15 }}
                                >
                                    {visibleEntries.map((entry, i) => (
                                        <LeaderboardRow
                                            key={`${entry.display_name}-${startIndex + i}`}
                                            entry={entry}
                                            rank={startIndex + i + 1}
                                            onClick={(e, r) => setSelected({ entry: e, rank: r })}
                                            index={i}
                                        />
                                    ))}
                                </motion.div>
                            </AnimatePresence>
                        )}
                    </div>

                    <Pagination
                        currentPage={currentPage}
                        totalPages={totalPages}
                        onPageChange={setCurrentPage}
                    />

                   
                    <div className="border-t border-white/5 px-8 py-5">
                        <p className="text-[10px] text-slate-600 leading-relaxed">
                            <span className="font-black uppercase tracking-widest text-slate-500">Disclaimer · </span>
                            This ranking system is calculated on pure stats for integrity reasons — no bonuses or talents are factored in — so it is only approximately accurate.
                        </p>
                    </div>
                </motion.div>
            </div>

           
            <AnimatePresence>
                {selected && (
                    <PlayerCardModal
                        entry={selected.entry}
                        rank={selected.rank}
                        isHistorical={activeTab === 'historical'}
                        onClose={() => setSelected(null)}
                    />
                )}
            </AnimatePresence>
        </>
    );
}

Leaderboard.layout = page => <GameLayout children={page} />;
