import { useState, useEffect, useMemo, useRef, memo, forwardRef, useImperativeHandle } from "react";
import { usePage, router, Link } from "@inertiajs/react";
import { route } from "ziggy-js";
import { motion, AnimatePresence } from "framer-motion";
import Works24hBar from "@/Components/Works24hBar";

import {
    User,
    MapPin,
    Briefcase,
    Heart,
    SignOut,
    Gear,
    Clock,
    House,
    Target,
    Trophy,
    CurrencyDollar,
    Buildings,
    Globe,
    CaretDown,
    CaretUp,
    Sword,
    Scales,
    ChartBar,
    Article,
    Megaphone,
    ChatTeardrop,
    Car,
    Barbell,
    List,
    X,
    TrendUp,
    Shield,
    FirstAidKit,
    Gavel,
    Bank,
    Lightning,
    Wrench,
    ChartLineDown,
    AirplaneTilt,
    LinkedinLogoIcon,
    OfficeChair,

} from "@phosphor-icons/react";

import {
    glowColors,
    formatRemaining,
    TIMER_KEYS,
    resolveIcon,
    getFooterItems,
    formatCash,
    getBottomTabs,
    PlayerTooltip,
    getPlayerBorderClass,
    ClockDisplay,
    CooldownsList,
    MobileCooldownsList,
    formatOnlinePlayerName,
    isExecutivePlayerName,
    parseSymbolicAmount,
} from "./GameLayoutComponents";
import { Airplane } from "@phosphor-icons/react/dist/ssr";

const getOnlineTooltipPosition = (rect) => {
    const rawX = rect.left + rect.width / 2;
    const halfTooltip = 143;
    const viewportPad = 12;
    const clampedX = Math.min(
        Math.max(rawX, halfTooltip + viewportPad),
        window.innerWidth - halfTooltip - viewportPad,
    );

    const maxArrowOffset = halfTooltip - viewportPad;

    return {
        x: clampedX,
        y: rect.top - 12,
        arrowOffsetX: Math.min(Math.max(rawX - clampedX, -maxArrowOffset), maxArrowOffset),
    };
};

// Hover-prefetch cache window. Game state changes often, so keep it short;
// any non-GET visit also flushes the whole prefetch cache (see below).
const PREFETCH_CACHE_FOR = "5s";

// GET /journal and GET /messages mark entries as read, so prefetching them
// would mark things read that the player never saw.
const NO_PREFETCH_PATHS = ["/journal", "/messages"];

const prefetchPropsFor = (href) => {
    const path = typeof href === "string" ? href.split("?")[0] : "";
    if (
        !path ||
        NO_PREFETCH_PATHS.some((p) => path === p || path.startsWith(`${p}/`))
    ) {
        return {};
    }
    return { prefetch: "hover", cacheFor: PREFETCH_CACHE_FOR };
};

// Owns the hover state so hovering a name only re-renders the tooltip,
// not the whole layout. Rendered at the same spot in the tree as before
// (outside the backdrop-blur footer, which would otherwise become the
// containing block for this position:fixed element).
const OnlinePlayerTooltip = forwardRef(function OnlinePlayerTooltip(_props, ref) {
    const [hoveredPlayer, setHoveredPlayer] = useState(null);
    const [tooltipPos, setTooltipPos] = useState({ x: 0, y: 0 });

    useImperativeHandle(
        ref,
        () => ({
            show(player, rect) {
                setHoveredPlayer(player);
                setTooltipPos(getOnlineTooltipPosition(rect));
            },
            hide() {
                setHoveredPlayer(null);
            },
        }),
        [],
    );

    return (
        <div
            className="fixed z-[200] pointer-events-none"
            style={{
                left: tooltipPos.x,
                top: tooltipPos.y,
                transform: "translate(-50%, -100%)",
            }}
        >
            <AnimatePresence>
                {hoveredPlayer && (
                    <motion.div
                        key={hoveredPlayer.id || "tooltip"}
                        initial={{ opacity: 0, scale: 0.9, y: 10 }}
                        animate={{ opacity: 1, scale: 1, y: 0 }}
                        exit={{ opacity: 0, scale: 0.9, y: 10 }}
                        transition={{
                            type: "spring",
                            damping: 20,
                            stiffness: 300,
                        }}
                        className="mb-4"
                    >
                        <PlayerTooltip
                            player={hoveredPlayer}
                            arrowOffsetX={tooltipPos.arrowOffsetX || 0}
                        />
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
});

// Memoised: the once-prop list keeps the same identity across navigations,
// so the ~100 links are not re-rendered on every layout render.
// Link's hover-prefetch owns onMouseEnter/onMouseLeave, so the tooltip uses
// the pointer equivalents.
const OnlinePlayerList = memo(function OnlinePlayerList({ players, tooltipRef }) {
    return (
        <div className="contents">
            {players.map((player, idx) => (
                <Link
                    key={`${player.displayName}-${idx}`}
                    href={`/profile/${player.displayName}`}
                    {...prefetchPropsFor(`/profile/${player.displayName}`)}
                    onPointerEnter={(e) =>
                        tooltipRef.current?.show(
                            player,
                            e.currentTarget.getBoundingClientRect(),
                        )
                    }
                    onPointerLeave={() => tooltipRef.current?.hide()}
                    className="group relative bg-transparent p-0 text-left leading-none transition-colors duration-150"
                >
                    <span className={`pointer-events-none block max-w-[130px] truncate text-[12px] transition-colors ${getPlayerBorderClass(player)} ${isExecutivePlayerName(player) ? 'font-black uppercase' : 'font-semibold lowercase'}`}>
                        {formatOnlinePlayerName(player)}
                    </span>
                </Link>
            ))}
        </div>
    );
});

export default function GameLayout({ children, wide = false, flush = false, noFlip = false }) {
    const { auth, flash, serverTime, onlinePlayers } = usePage().props;
    const { url } = usePage();
    const character = auth?.character;
    const [showSettings, setShowSettings] = useState(false);
    const [showLeaderboard, setShowLeaderboard] = useState(false);
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [statsExpanded, setStatsExpanded] = useState(false);
    const [onlineFilter, setOnlineFilter] = useState("city");
    const tooltipRef = useRef(null);
    const pagePath = useMemo(() => url.split("?")[0], [url]);

    //TODO: remove the conflict check
    useEffect(() => {
        if (
            auth?.user?.is_banned &&
            !window.location.pathname.includes("/banned")
        ) {
            window.location.href = "/banned";
            return;
        }

        if (
            character?.is_dead &&
            !window.location.pathname.includes("/death")
        ) {
            window.location.href = "/death";
            return;
        }

        if (
            character?.is_hospitalized &&
            !window.location.pathname.includes("/hospital")
        ) {
            window.location.href = "/hospital";
            return;
        }

        if (
            character?.is_jailed &&
            !window.location.pathname.includes("/jail")
        ) {
            window.location.href = "/jail";
            return;
        }
    }, [auth?.user?.is_banned, character?.is_dead, character?.is_hospitalized, character?.is_jailed]);

    const [isFlipped, setIsFlipped] = useState(false);
    const flipDisabled = noFlip || auth?.user?.disableCardFlip;

    const navSections = auth?.navigation || [];

    // Latest committed values for the router listeners below, which are
    // bound once for the lifetime of the (persistent) layout.
    const navStateRef = useRef({ flipDisabled, pagePath });
    useEffect(() => {
        navStateRef.current = { flipDisabled, pagePath };
    }, [flipDisabled, pagePath]);

    useEffect(() => {
        let mounted = true;
        let animationTimeout;
        // A real (non-prefetch) visit is in flight.
        let navigating = false;
        // Hover prefetches fire global start/finish events too; they are
        // background fetches and must not flip/unflip the card.
        const prefetchVisits = new WeakSet();

        const scheduleUnflip = () => {
            clearTimeout(animationTimeout);
            animationTimeout = setTimeout(() => {
                if (mounted) {
                    setIsFlipped(false);
                }
            }, 100);
        };

        // Flip on "before" rather than "start": a visit served from the
        // prefetch cache never fires "start"/"finish".
        const unbindBefore = router.on("before", (event) => {
            if (!mounted || event.defaultPrevented) return;

            const visit = event.detail.visit;

            if (visit.prefetch) {
                // On touch, a tap fires mouseenter right before click, so the
                // Link's hover-prefetch timer would re-request the page the
                // real visit is already loading. Skip prefetches meanwhile.
                return navigating ? false : undefined;
            }

            navigating = true;

            const { flipDisabled: disabled, pagePath: currentPath } = navStateRef.current;
            const nextUrl = visit.url;
            const nextPath =
                typeof nextUrl === "string"
                    ? nextUrl.split("?")[0]
                    : nextUrl.pathname;

            const bothMessages = currentPath.startsWith('/messages') && nextPath.startsWith('/messages');
            if (!disabled && visit.method === "get" && nextPath !== currentPath && !bothMessages) {
                clearTimeout(animationTimeout);
                setIsFlipped(true);
            }
        });

        const unbindStart = router.on("start", (event) => {
            const visit = event.detail.visit;
            if (visit.prefetch) {
                prefetchVisits.add(visit);
            }
        });

        // Cache hits only fire "success"; regular visits fire "success" then
        // "finish", and "finish" re-arms the timer, so the unflip still lands
        // 100ms after "finish" exactly as before.
        const unbindSuccess = router.on("success", () => {
            if (!mounted) return;
            navigating = false;
            scheduleUnflip();
        });

        const unbindFinish = router.on("finish", (event) => {
            if (!mounted) return;

            const visit = event.detail.visit;
            if (prefetchVisits.has(visit)) return;

            navigating = false;

            // Prefetched pages carry pre-mutation shared props (cash, timers,
            // health...). Drop them after any non-GET visit.
            if (visit.method !== "get") {
                router.flushAll();
            }

            scheduleUnflip();
        });

        // A prefetched response that ends in errors/invalid/exception never fires
        // "success", and cache hits never fire "finish"; settle the card here too
        // so it can't stay flipped.
        const unbindSettled = ["error", "invalid", "exception"].map((type) =>
            router.on(type, () => {
                if (!mounted) return;
                navigating = false;
                scheduleUnflip();
            }),
        );

        return () => {
            mounted = false;
            clearTimeout(animationTimeout);
            unbindBefore();
            unbindStart();
            unbindSuccess();
            unbindFinish();
            unbindSettled.forEach((unbind) => unbind());
        };
    }, []);

    // onlinePlayers is a once-prop holding the global list; the city tab is
    // derived here from each row's cityId.
    const globalOnlineList = onlinePlayers?.globalList;
    const currentCityId = character?.cityId;
    const cityOnlineList = useMemo(
        () =>
            globalOnlineList
                ?.filter((p) => p.cityId === currentCityId)
                .slice(0, 50),
        [globalOnlineList, currentCityId],
    );
    const currentOnlineList =
        onlineFilter === "city" ? cityOnlineList : globalOnlineList;

    const handleFilterChange = (newFilter) => {
        setOnlineFilter(newFilter);
    };

    const [quickWorking, setQuickWorking] = useState(false);
    const [workExpanded, setWorkExpanded] = useState(false);
    const [bankExpanded, setBankExpanded] = useState(false);
    const [bankWithdrawAmount, setBankWithdrawAmount] = useState('');
    const [bankWithdrawBusy, setBankWithdrawBusy] = useState(false);
    const handleQuickWork = (e) => {
        e.preventDefault();
        e.stopPropagation();
        const lastEarnId = localStorage.getItem('last_earn_id');
        if (!lastEarnId || quickWorking) return;
        setQuickWorking(true);
        router.post(route('work.attempt'), { earn_id: parseInt(lastEarnId) }, {
            only: ['auth', 'flash'],
            preserveScroll: true,
            onFinish: () => setQuickWorking(false),
        });
    };

    const footerItems = getFooterItems();

    const healthPercentage = useMemo(() => {
        if (
            !character?.health ||
            !character?.maxHealth ||
            character.maxHealth === 0
        )
            return 0;
        return Math.round((character.health / character.maxHealth) * 100);
    }, [character?.health, character?.maxHealth]);

    const getHealthColor = (pct) => {
        if (pct <= 30) return "bg-red-500";
        if (pct <= 70) return "bg-yellow-500";
        return "bg-emerald-500";
    };

    return (
        <div className="min-h-screen bg-slate-920 text-white flex flex-col">
            { }
            {isFlipped && (
                <div className="fixed inset-0 z-[100] pointer-events-none">
                    <div className="absolute inset-0 bg-gradient-to-r from-transparent via-cyan-500/10 to-transparent animate-swipe-right" />
                </div>
            )}

            <header className="h-12 border-b border-slate-800/50 flex items-center justify-between px-3 sm:px-5 bg-slate-950/80 sticky top-0 z-50">
                <Link
                    href="/dashboard"
                    {...prefetchPropsFor("/dashboard")}
                    className="font-bold text-sm hover:text-cyan-400 transition whitespace-nowrap"
                >
                    THE <span className="text-cyan-400">DIRECTOR</span>
                </Link>

                <div className="flex-1 min-w-0 flex items-center justify-center mx-2 overflow-hidden">
                    <div className="flex items-center gap-1 shrink-0">
                        <Clock size={12} className="text-cyan-400" />
                        <ClockDisplay />
                    </div>
                    <div className="hidden nav:flex items-center gap-3 ml-4 overflow-hidden">
                        <CooldownsList />
                    </div>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                    {/* Wiki is now a plain Blade/HTML page, not Inertia —
                        a real anchor triggers a full browser navigation
                        instead of going through Inertia's router and
                        falling back. Cleaner intent. */}
                    <a
                        href={route('wiki.index')}
                        className="text-cyan-400/85 hover:text-cyan-200 transition px-2 py-1 rounded hover:bg-cyan-500/10 text-[10px] font-black uppercase tracking-widest whitespace-nowrap no-underline"
                    >
                        Wiki
                    </a>
                    <Link
                        href={route('help')}
                        className="text-cyan-400/85 hover:text-cyan-200 transition px-2 py-1 rounded hover:bg-cyan-500/10 text-[10px] font-black uppercase tracking-widest whitespace-nowrap"
                    >
                        Forum
                    </Link>
                    <div className="relative ml-1">
                        <button
                            onClick={() => setShowSettings((v) => !v)}
                            className="flex items-center gap-1.5 hover:bg-slate-800/50 px-2 py-1 rounded transition"
                        >
                            <span className="text-slate-300 text-xs font-medium max-w-[100px] truncate">
                                {character.displayName}
                            </span>
                        </button>
                        {showSettings && (
                            <div className="absolute right-0 mt-2 w-40 bg-slate-900/95 backdrop-blur-md border border-slate-800 rounded-lg shadow-2xl py-1 z-50">
                                {footerItems.map((item) => (
                                    <button
                                        key={item.label}
                                        onClick={() => {
                                            router.get(item.route);
                                            setShowSettings(false);
                                        }}
                                        className="w-full px-3 py-2 text-left text-xs text-slate-300 hover:bg-slate-800 flex items-center gap-2.5 transition"
                                    >
                                        <item.icon size={13} />
                                        {item.label}
                                    </button>
                                ))}
                                <div className="border-t border-slate-800 my-1" />
                                <button
                                    onClick={() => router.post("/logout")}
                                    className="w-full px-3 py-2 text-left text-xs text-red-400 hover:bg-slate-800 flex items-center gap-2 transition"
                                >
                                    <SignOut size={13} weight="bold" />
                                    Logout
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            </header>

            <div className="nav:hidden border-b border-slate-800/50 bg-slate-950/60">
                <button
                    onClick={() => setStatsExpanded((v) => !v)}
                    className="w-full flex items-center justify-between px-3 py-2"
                >
                    <div className="flex items-center gap-3 text-[11px] overflow-hidden">
                        <span className="flex items-center gap-1">
                            <Heart
                                size={10}
                                weight="fill"
                                className={
                                    healthPercentage <= 30
                                        ? "text-red-400"
                                        : "text-emerald-400"
                                }
                            />
                            <span className="tabular-nums">
                                {character.health}%
                            </span>
                        </span>
                        <span className="text-emerald-400 tabular-nums font-medium">
                            {formatCash(character.cleanCash)}
                        </span>
                        <span className="text-red-400 tabular-nums font-medium">
                            {formatCash(character.dirtyCash)}
                        </span>
                        <span className="text-cyan-400 text-[10px] truncate">
                            {character.rank}
                        </span>
                    </div>
                    {statsExpanded ? (
                        <CaretUp
                            size={14}
                            className="text-slate-400 shrink-0"
                        />
                    ) : (
                        <CaretDown
                            size={14}
                            className="text-slate-400 shrink-0"
                        />
                    )}
                </button>
                {statsExpanded && (
                    <div className="px-3 pb-3 space-y-2">
                        <MobileCooldownsList />
                        <div className="grid grid-cols-3 gap-2">
                            <div>
                                <div className="text-[10px] text-slate-400 mb-0.5 flex justify-between">
                                    <span>Health</span>
                                    <span className="tabular-nums">
                                        {character.health}/{character.maxHealth}
                                    </span>
                                </div>
                                <div className="h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                    <div
                                        className={`h-full ${getHealthColor(healthPercentage)} transition-all`}
                                        style={{
                                            width: `${healthPercentage}%`,
                                        }}
                                    />
                                </div>
                            </div>

                            <div>
                                <div className="text-[10px] text-slate-400 mb-0.5 flex justify-between">
                                    <span>Rank</span>
                                    <span className="tabular-nums text-emerald-400">
                                        {character.rankProgress || 0}
                                    </span>
                                </div>
                                <div className="h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                    <div
                                        className="h-full bg-emerald-500 transition-all"
                                        style={{
                                            width: `${character.rankProgress}%`,
                                        }}
                                    />
                                </div>
                            </div>

                            <div>
                                <div className="text-[10px] text-slate-400 mb-0.5 flex justify-between">
                                    <span>Strength</span>
                                    <span className="tabular-nums text-emerald-400">
                                        {character.strength || 0}
                                    </span>
                                </div>
                                <div className="h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                    <div
                                        className="h-full bg-emerald-500 transition-all"
                                        style={{
                                            width: `${Math.min(100, character.strength || 0)}%`,
                                        }}
                                    />
                                </div>
                            </div>

                            <div>
                                <Works24hBar count={character.works24h} compact />
                            </div>
                        </div>
                    </div>
                )}
            </div>

            <div className="flex flex-1 overflow-hidden">
                {drawerOpen && (
                    <div
                        className="lg:hidden fixed inset-0 z-40"
                        onClick={() => setDrawerOpen(false)}
                    >
                        <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" />
                        <aside
                            className="absolute left-0 top-0 bottom-0 w-72 max-w-[85vw] bg-slate-950 border-r border-slate-800/50 flex flex-col overflow-hidden"
                            onClick={(e) => e.stopPropagation()}
                        >
                            <div className="p-4 border-b border-slate-800/50 flex items-center justify-between">
                                <div className="min-w-0">
                                    <div
                                        className="font-bold text-white text-sm truncate"
                                        title={character.displayName}
                                    >
                                        {character.displayName}
                                    </div>
                                    <div
                                        className="text-xs text-cyan-400"
                                        title={`${character.rank} • ${character.career}`}
                                    >
                                        <span className="truncate block">
                                            {character.rank}
                                        </span>
                                        <span className="truncate block">
                                            {character.career}
                                        </span>
                                    </div>
                                </div>
                                <button
                                    onClick={() => setDrawerOpen(false)}
                                    className="text-slate-400 hover:text-white p-1"
                                >
                                    <X size={18} />
                                </button>
                            </div>
                            <nav className="flex-1 overflow-y-auto py-3">
                                {navSections.map((section) => (
                                    <div key={section.section} className="mb-4">
                                        <div className="px-4 text-[10px] uppercase tracking-widest text-cyan-400/85 mb-1.5 font-black">
                                            {section.section}
                                        </div>
                                        {section.items.map((item) => {
                                            const hasQuickWork = item.label === 'Work' && localStorage.getItem('last_earn_id');
                                            const isExpandable = hasQuickWork || item.isTechnician || item.isCustoms || item.hasActions || item.hasWithdraw;
                                            return (
                                                <div key={item.label} className="flex flex-col">
                                                    <div className="flex items-center">
                                                        <Link
                                                            href={item.href}
                                                            {...prefetchPropsFor(item.href)}
                                                            onClick={() => setDrawerOpen(false)}
                                                            className="flex-1 px-4 py-2.5 flex items-center justify-between text-slate-300 hover:text-white hover:bg-slate-800/40 transition text-left group"
                                                        >
                                                            <div className="flex items-center gap-3">
                                                                {(() => {
                                                                    const Icon = resolveIcon(item.icon);
                                                                    return item.isAlert ? (
                                                                        <motion.span
                                                                            animate={{ scale: [1, 1.18, 1] }}
                                                                            transition={{ duration: 1.6, repeat: Infinity, ease: 'easeInOut' }}
                                                                            className="text-amber-400 shrink-0 flex"
                                                                        >
                                                                            <Icon size={16} />
                                                                        </motion.span>
                                                                    ) : (
                                                                        <Icon size={16} className="text-slate-400 group-hover:text-cyan-400 transition shrink-0" />
                                                                    );
                                                                })()}
                                                                <span className={`text-sm font-medium ${item.isAlert ? 'text-amber-300' : ''}`}>{item.label}</span>
                                                            </div>
                                                            {item.badge > 0 && !isExpandable && (
                                                                <span className="bg-cyan-500 text-slate-900 text-[10px] font-bold px-1.5 py-0.5 rounded-full">{item.badge}</span>
                                                            )}
                                                            {item.isAlert && !isExpandable && (
                                                                <motion.span
                                                                    animate={{ opacity: [0.4, 1, 0.4] }}
                                                                    transition={{ duration: 1.6, repeat: Infinity, ease: 'easeInOut' }}
                                                                    className="w-1.5 h-1.5 rounded-full bg-amber-400 shrink-0"
                                                                />
                                                            )}
                                                        </Link>
                                                        {isExpandable && (
                                                            <button
                                                                onClick={(e) => {
                                                                    e.preventDefault();
                                                                    item.hasWithdraw ? setBankExpanded(!bankExpanded) : setWorkExpanded(!workExpanded);
                                                                }}
                                                                className="px-4 py-2.5 text-slate-400 hover:text-white transition shrink-0"
                                                            >
                                                                {(item.hasWithdraw ? bankExpanded : workExpanded) ? <CaretUp size={14} /> : <CaretDown size={14} />}
                                                            </button>
                                                        )}
                                                    </div>
                                                    {isExpandable && (item.hasWithdraw ? bankExpanded : workExpanded) && (
                                                        <div className="bg-slate-900/30 py-1 border-t border-slate-800/30">
                                                            {item.hasWithdraw && (
                                                                <div className="px-4 py-2 pl-[44px] space-y-2">
                                                                    <div className="flex gap-2">
                                                                        <input
                                                                            type="text"
                                                                            placeholder="Amount"
                                                                            value={bankWithdrawAmount}
                                                                            onChange={e => setBankWithdrawAmount(e.target.value.replace(/[^0-9kmKMbB.]/g, ''))}
                                                                            className="flex-1 bg-slate-800 border border-slate-700/60 rounded-lg px-2.5 py-1.5 text-xs text-white placeholder:text-slate-600 focus:outline-none focus:border-cyan-500/40 min-w-0"
                                                                        />
                                                                        <button
                                                                            disabled={bankWithdrawBusy || !bankWithdrawAmount}
                                                                            onClick={() => {
                                                                                const parsedVal = parseSymbolicAmount(bankWithdrawAmount);
                                                                                if (!parsedVal || parsedVal <= 0 || bankWithdrawBusy) return;
                                                                                setBankWithdrawBusy(true);
                                                                                router.post(item.withdrawUrl, { amount: parsedVal }, {
                                                                                    preserveScroll: true,
                                                                                    onSuccess: () => { setBankWithdrawAmount(''); setBankExpanded(false); setDrawerOpen(false); },
                                                                                    onFinish: () => setBankWithdrawBusy(false),
                                                                                });
                                                                            }}
                                                                            className="px-3 py-1.5 rounded-lg bg-cyan-500/20 border border-cyan-500/40 text-[10px] font-black text-cyan-300 uppercase tracking-widest hover:bg-cyan-500/30 disabled:opacity-40 transition-all whitespace-nowrap"
                                                                        >
                                                                            {bankWithdrawBusy ? '…' : 'Withdraw'}
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            )}
                                                            {hasQuickWork && (
                                                                <button
                                                                    onClick={(e) => { handleQuickWork(e); setDrawerOpen(false); }}
                                                                    disabled={quickWorking}
                                                                    className={`w-full flex items-center gap-3 px-4 py-2 pl-[44px] text-left text-[11px] font-bold uppercase tracking-wider transition ${quickWorking ? 'text-amber-400 animate-pulse' : 'text-slate-400 hover:text-amber-400 hover:bg-slate-800/40'}`}
                                                                >
                                                                    <span className={`w-1.5 h-1.5 rounded-full shrink-0 ${quickWorking ? 'bg-amber-400' : 'bg-slate-600'}`}></span>
                                                                    Quick Work
                                                                </button>
                                                            )}
                                                            {item.isTechnician && (
                                                                <Link
                                                                    href="/career/technician"
                                                                    {...prefetchPropsFor("/career/technician")}
                                                                    onClick={() => setDrawerOpen(false)}
                                                                    className="w-full flex items-center gap-3 px-4 py-2 pl-[44px] text-left text-[11px] font-bold uppercase tracking-wider transition text-slate-400 hover:text-cyan-400 hover:bg-slate-800/40"
                                                                >
                                                                    <Wrench size={12} className="shrink-0" />
                                                                    Workshop
                                                                </Link>
                                                            )}
                                                            {item.isCustoms && (
                                                                <Link
                                                                    href="/career/customs"
                                                                    {...prefetchPropsFor("/career/customs")}
                                                                    onClick={() => setDrawerOpen(false)}
                                                                    className="w-full flex items-center gap-3 px-4 py-2 pl-[44px] text-left text-[11px] font-bold uppercase tracking-wider transition text-slate-400 hover:text-cyan-400 hover:bg-slate-800/40"
                                                                >
                                                                    <AirplaneTilt size={12} className="shrink-0" />
                                                                    Customs
                                                                </Link>
                                                            )}
                                                            {item.hasActions && item.actionsUrl && (
                                                                <Link
                                                                    href={item.actionsUrl}
                                                                    {...prefetchPropsFor(item.actionsUrl)}
                                                                    onClick={() => setDrawerOpen(false)}
                                                                    className="w-full flex items-center gap-3 px-4 py-2 pl-[44px] text-left text-[11px] font-bold uppercase tracking-wider transition text-slate-400 hover:text-cyan-400 hover:bg-slate-800/40"
                                                                >
                                                                    <span className="w-1.5 h-1.5 rounded-full shrink-0 bg-slate-600"></span>
                                                                    Actions
                                                                </Link>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                ))}
                            </nav>
                        </aside>
                    </div>
                )}

                <aside className="hidden nav:flex w-60 xl:w-64 border-r border-slate-800/50 flex-col overflow-hidden shrink-0">
                    <div className="p-4 border-b border-slate-800/50">
                        <div className="min-w-0 text-center">
                            <div
                                className="font-bold text-white text-sm truncate"
                                title={character.displayName}
                            >
                                {character.displayName}
                            </div>
                            <div className="text-xs text-cyan-400 mt-2">
                                <span className="truncate block">
                                    {character.rank}
                                </span>
                            </div>
                            <div className="text-xs text-slate-400 mt-1">
                                <span className="truncate block">
                                    {character.career}
                                </span>
                            </div>
                            <div className="text-[10px] text-slate-500 mt-2 flex items-center justify-center gap-3 flex-wrap">
                                <span className="flex items-center gap-0.5 truncate">
                                    <MapPin size={9} weight="bold" />
                                    <span className="truncate">
                                        {character.cityName}
                                    </span>
                                </span>
                                <span className="flex items-center gap-0.5 truncate">
                                    <House size={9} weight="bold" />
                                    <span className="truncate">
                                        {character.homeCity}
                                    </span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div className="p-4 border-b border-slate-800/50 space-y-3">
                        <div>
                            <div className="flex justify-between text-[11px] text-slate-400 mb-1">
                                <span className="flex items-center gap-1">
                                    <Heart size={11} weight="fill" /> Health
                                </span>
                                <span
                                    className={`font-semibold tabular-nums ${healthPercentage <= 30 ? "text-red-400" : healthPercentage <= 70 ? "text-yellow-400" : "text-emerald-400"}`}
                                >
                                    {character.health}/{character.maxHealth}
                                </span>
                            </div>
                            <div className="h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                <div
                                    className={`h-full ${getHealthColor(healthPercentage)} transition-all duration-500`}
                                    style={{ width: `${healthPercentage}%` }}
                                />
                            </div>
                        </div>

                        <div>
                            <div className="flex justify-between text-[11px] text-slate-400 mb-1">
                                <span className="flex items-center gap-1">
                                    <Target size={11} weight="bold" /> Rank
                                </span>
                                <span className="text-emerald-400 font-semibold tabular-nums">
                                    {character.rankProgress}%
                                </span>
                            </div>
                            <div className="h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                <div
                                    className="h-full bg-emerald-500 transition-all duration-500"
                                    style={{
                                        width: `${character.rankProgress}%`,
                                    }}
                                />
                            </div>
                        </div>
                        <div>

                            <div>
                                <div className="flex justify-between text-[11px] text-slate-400 mb-1">
                                    <span className="flex items-center gap-1">
                                        <Barbell size={11} weight="bold" /> Strength
                                    </span>
                                    <span className="text-emerald-400 font-semibold tabular-nums">
                                        {character.strength || "N/A"}
                                    </span>
                                </div>
                                <div className="h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                    <div
                                        className="h-full bg-emerald-500 transition-all duration-500"
                                        style={{
                                            width: `${Math.min(100, character.strength || 0)}%`,
                                        }}
                                    />
                                </div>
                            </div>
                        </div>
                        <div>
                            <Works24hBar count={character.works24h} compact />
                        </div>
                    </div>

                    <div className="p-4 border-b border-slate-800/50">
                        <div className="grid grid-cols-2 gap-2">
                            <div className="bg-slate-900/60 rounded-lg p-2.5">
                                <div className="text-[10px] text-slate-500 uppercase tracking-wider mb-0.5">
                                    Cash
                                </div>
                                <div
                                    className="text-emerald-400 font-bold text-sm tabular-nums truncate"
                                    title={`$${character.cleanCash.toLocaleString()}`}
                                >
                                    {formatCash(character.cleanCash)}
                                </div>
                            </div>
                            <div className="bg-slate-900/60 rounded-lg p-2.5">
                                <div className="text-[10px] text-slate-500 uppercase tracking-wider mb-0.5">
                                    Dirty
                                </div>
                                <div
                                    className="text-red-400 font-bold text-sm tabular-nums truncate"
                                    title={`$${character.dirtyCash.toLocaleString()}`}
                                >
                                    {formatCash(character.dirtyCash)}
                                </div>
                            </div>
                        </div>
                    </div>

                    <nav className="flex-1 overflow-y-auto py-3">
                        {navSections.map((section) => (
                            <div key={section.section} className="mb-4">
                                <div className="px-4 text-[10px] uppercase tracking-widest text-cyan-400/85 mb-1.5 font-black">
                                    {section.section}
                                </div>
                                {section.items.map((item) => {
                                    const hasQuickWork = item.label === 'Work' && localStorage.getItem('last_earn_id');
                                    const isExpandable = hasQuickWork || item.isTechnician || item.isCustoms || item.hasActions || item.hasWithdraw;
                                    return (
                                        <div key={item.label} className="flex flex-col">
                                            <div className="flex items-center">
                                                <Link
                                                    href={item.href}
                                                    {...prefetchPropsFor(item.href)}
                                                    className="flex-1 px-4 py-2.5 flex items-center justify-between text-slate-300 hover:text-white hover:bg-slate-800/40 transition text-left group"
                                                >
                                                    <div className="flex items-center gap-3">
                                                        {(() => {
                                                            const Icon = resolveIcon(item.icon);
                                                            return item.isAlert ? (
                                                                <motion.span
                                                                    animate={{ scale: [1, 1.18, 1] }}
                                                                    transition={{ duration: 1.6, repeat: Infinity, ease: 'easeInOut' }}
                                                                    className="text-amber-400 shrink-0 flex"
                                                                >
                                                                    <Icon size={16} />
                                                                </motion.span>
                                                            ) : (
                                                                <Icon size={16} className="text-slate-400 group-hover:text-cyan-400 transition shrink-0" />
                                                            );
                                                        })()}
                                                        <span className={`text-sm font-medium ${item.isAlert ? 'text-amber-300' : ''}`}>{item.label}</span>
                                                    </div>
                                                    {item.badge > 0 && !isExpandable && (
                                                        <span className="bg-cyan-500 text-slate-900 text-[10px] font-bold px-1.5 py-0.5 rounded-full">{item.badge}</span>
                                                    )}
                                                    {item.isAlert && !isExpandable && (
                                                        <motion.span
                                                            animate={{ opacity: [0.4, 1, 0.4] }}
                                                            transition={{ duration: 1.6, repeat: Infinity, ease: 'easeInOut' }}
                                                            className="w-1.5 h-1.5 rounded-full bg-amber-400 shrink-0"
                                                        />
                                                    )}
                                                </Link>
                                                {isExpandable && (
                                                    <button
                                                        onClick={(e) => {
                                                            e.preventDefault();
                                                            item.hasWithdraw ? setBankExpanded(!bankExpanded) : setWorkExpanded(!workExpanded);
                                                        }}
                                                        className="px-4 py-2.5 text-slate-400 hover:text-white transition shrink-0"
                                                    >
                                                        {(item.hasWithdraw ? bankExpanded : workExpanded) ? <CaretUp size={14} /> : <CaretDown size={14} />}
                                                    </button>
                                                )}
                                            </div>
                                            {isExpandable && (item.hasWithdraw ? bankExpanded : workExpanded) && (
                                                <div className="bg-slate-900/30 py-1 border-t border-slate-800/30">
                                                    {item.hasWithdraw && (
                                                        <div className="px-4 py-2 pl-[44px] space-y-2">
                                                            <div className="flex gap-2">
                                                                <input
                                                                    type="text"
                                                                    placeholder="Amount"
                                                                    value={bankWithdrawAmount}
                                                                    onChange={e => setBankWithdrawAmount(e.target.value.replace(/[^0-9kmKMbB.]/g, ''))}
                                                                    className="flex-1 bg-slate-800 border border-slate-700/60 rounded-lg px-2.5 py-1.5 text-xs text-white placeholder:text-slate-600 focus:outline-none focus:border-cyan-500/40 min-w-0"
                                                                />
                                                                <button
                                                                    disabled={bankWithdrawBusy || !bankWithdrawAmount}
                                                                    onClick={() => {
                                                                        const parsedVal = parseSymbolicAmount(bankWithdrawAmount);
                                                                        if (!parsedVal || parsedVal <= 0 || bankWithdrawBusy) return;
                                                                        setBankWithdrawBusy(true);
                                                                        router.post(item.withdrawUrl, { amount: parsedVal }, {
                                                                            preserveScroll: true,
                                                                            onSuccess: () => { setBankWithdrawAmount(''); setBankExpanded(false); },
                                                                            onFinish: () => setBankWithdrawBusy(false),
                                                                        });
                                                                    }}
                                                                    className="px-3 py-1.5 rounded-lg bg-cyan-500/20 border border-cyan-500/40 text-[10px] font-black text-cyan-300 uppercase tracking-widest hover:bg-cyan-500/30 disabled:opacity-40 transition-all whitespace-nowrap"
                                                                >
                                                                    {bankWithdrawBusy ? '…' : 'Withdraw'}
                                                                </button>
                                                            </div>
                                                        </div>
                                                    )}
                                                    {hasQuickWork && (
                                                        <button
                                                            onClick={handleQuickWork}
                                                            disabled={quickWorking}
                                                            className={`w-full flex items-center gap-3 px-4 py-2 pl-[44px] text-left text-[11px] font-bold uppercase tracking-wider transition ${quickWorking ? 'text-amber-400 animate-pulse' : 'text-slate-400 hover:text-amber-400 hover:bg-slate-800/40'}`}
                                                        >
                                                            <span className={`w-1.5 h-1.5 rounded-full shrink-0 ${quickWorking ? 'bg-amber-400' : 'bg-slate-600'}`}></span>
                                                            Quick Work
                                                        </button>
                                                    )}
                                                    {item.isTechnician && (
                                                        <Link
                                                            href="/career/technician"
                                                            {...prefetchPropsFor("/career/technician")}
                                                            className="w-full flex items-center gap-3 px-4 py-2 pl-[44px] text-left text-[11px] font-bold uppercase tracking-wider transition text-slate-400 hover:text-cyan-400 hover:bg-slate-800/40"
                                                        >
                                                            <Wrench size={12} className="shrink-0" />
                                                            Workshop
                                                        </Link>
                                                    )}
                                                    {item.isCustoms && (
                                                        <Link
                                                            href="/career/customs"
                                                            {...prefetchPropsFor("/career/customs")}
                                                            className="w-full flex items-center gap-3 px-4 py-2 pl-[44px] text-left text-[11px] font-bold uppercase tracking-wider transition text-slate-400 hover:text-cyan-400 hover:bg-slate-800/40"
                                                        >
                                                            <AirplaneTilt size={12} className="shrink-0" />
                                                            Airport
                                                        </Link>
                                                    )}
                                                    {item.hasActions && item.actionsUrl && (
                                                        <Link
                                                            href={item.actionsUrl}
                                                            {...prefetchPropsFor(item.actionsUrl)}
                                                            className="w-full flex items-center gap-3 px-4 py-2 pl-[44px] text-left text-[11px] font-bold uppercase tracking-wider transition text-slate-400 hover:text-cyan-400 hover:bg-slate-800/40"
                                                        >
                                                            <span className="w-1.5 h-1.5 rounded-full shrink-0 bg-slate-600"></span>
                                                            Actions
                                                        </Link>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        ))}
                    </nav>
                </aside>
                <div className="flex-1 flex flex-col min-w-0">

                    {(flash?.success || flash?.error || flash?.warning) && (
                        <div className="px-3 pt-3 pb-3 border-b border-slate-800/50 flex flex-col gap-2 w-full">
                            {/* Success — suppressed on conflict pages where outcomes render inline */}
                            {flash.success && (
                                <div className="w-full px-4 py-2.5 rounded-lg text-sm max-w-2xl mx-auto text-center bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                                    {flash.success}
                                </div>
                            )}
                            {/* Warning — always shown beneath success when both are present */}
                            {flash.warning && (
                                <div className="w-full px-4 py-2.5 rounded-lg text-sm max-w-2xl mx-auto text-center bg-amber-500/10 text-amber-400 border border-amber-500/30">
                                    {flash.warning}
                                </div>
                            )}
                            {/* Error */}
                            {flash.error && (
                                <div className="w-full px-4 py-2.5 rounded-lg text-sm max-w-2xl mx-auto text-center bg-red-500/10 text-red-400 border border-red-500/30">
                                    {flash.error}
                                </div>
                            )}
                        </div>
                    )}

                    <main
                        className={`flex-1 overflow-x-hidden ${isFlipped ? "overflow-y-hidden" : "overflow-y-auto"} flex flex-col ${flush ? "items-stretch justify-start" : "items-center justify-center p-4 sm:p-6"} [perspective:2000px] relative`}
                    >
                        <motion.div
                            animate={{ rotateY: isFlipped ? 180 : 0 }}
                            transition={{
                                duration: 0.7,
                                ease: [0.23, 1, 0.32, 1],
                            }}
                            className={`w-full ${flush ? "max-w-none min-h-0 flex flex-col items-stretch justify-start" : `${wide ? "max-w-7xl" : "max-w-6xl"} min-h-[400px] flex flex-col items-center justify-center`} relative`}
                            style={{
                                transformStyle: "preserve-3d",
                            }}
                        >
                            <div
                                className="w-full h-full"
                                style={{
                                    backfaceVisibility: "hidden",
                                    WebkitBackfaceVisibility: "hidden",
                                }}
                            >
                                {children}
                            </div>
                            <div
                                className="absolute inset-0 bg-slate-900/90 backdrop-blur-md border border-slate-700 flex flex-col items-center justify-center rounded-xl overflow-hidden"
                                style={{
                                    backfaceVisibility: "hidden",
                                    WebkitBackfaceVisibility: "hidden",
                                    transform: "rotateY(180deg)",
                                }}
                            >
                                <div className="relative z-10 text-cyan-400 font-bold tracking-[0.2em] text-2xl drop-shadow-[0_0_15px_#22d3ee55]">
                                    THE
                                    <span className="text-white">DIRECTOR</span>
                                </div>
                                <div className="absolute inset-0 bg-[radial-gradient(circle_at_center,rgba(6,182,212,0.1),transparent)] transition-opacity duration-1000" />
                            </div>
                        </motion.div>
                    </main>

                    <div className="border-t border-slate-900/20 bg-slate-900/20 backdrop-blur-md pb-16 nav:pb-0">
                        <div className={`${wide ? "w-full" : "max-w-6xl"} mx-auto px-4 py-3 flex flex-col gap-2.5`}>
                            <div className="flex justify-end">
                                <div className="flex items-center gap-5">
                                    <button
                                        onClick={() => handleFilterChange("city")}
                                        className={`relative inline-flex items-center gap-1.5 pb-1 text-[10px] font-black uppercase tracking-[0.18em] transition-colors after:absolute after:bottom-0 after:left-0 after:h-px after:transition-all ${onlineFilter === "city"
                                            ? "text-cyan-300 after:w-full after:bg-cyan-400"
                                            : "text-white hover:text-cyan-200 after:w-0 after:bg-cyan-400"
                                            }`}
                                    >
                                        <MapPin size={10} weight="bold" />
                                        {character.cityName}
                                    </button>
                                    <button
                                        onClick={() => handleFilterChange("all")}
                                        className={`relative inline-flex items-center gap-1.5 pb-1 text-[10px] font-black uppercase tracking-[0.18em] transition-colors after:absolute after:bottom-0 after:left-0 after:h-px after:transition-all ${onlineFilter === "all"
                                            ? "text-amber-300 after:w-full after:bg-amber-400"
                                            : "text-white hover:text-amber-200 after:w-0 after:bg-amber-400"
                                            }`}
                                    >
                                        <Globe size={10} weight="bold" />
                                        Global
                                    </button>
                                </div>
                            </div>

                            <div className="flex min-h-[34px] flex-wrap items-center gap-2.5 pb-1">
                                {onlinePlayers === undefined ? (
                                    <div className="flex items-center gap-2 py-2">
                                        <div className="h-3.5 w-3.5 rounded-full border-2 border-cyan-500/20 border-t-cyan-300 animate-spin" />
                                        <span className="text-[10px] font-black uppercase tracking-widest text-cyan-100/70">
                                            Finding players
                                        </span>
                                    </div>
                                ) : onlinePlayers === null ? (
                                    <div className="py-2 text-xs font-semibold text-cyan-100/70">
                                        Online list unavailable
                                    </div>
                                ) : (
                                    currentOnlineList &&
                                    currentOnlineList.length > 0 ? (
                                        <OnlinePlayerList
                                            players={currentOnlineList}
                                            tooltipRef={tooltipRef}
                                        />
                                    ) : (
                                        <div className="py-2 text-xs font-semibold text-cyan-100/70">
                                            No other active connections
                                        </div>
                                    )
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="nav:hidden fixed bottom-0 left-0 right-0 z-40 bg-slate-950/95 backdrop-blur-md border-t border-slate-800/50">
                <div className="flex items-center justify-around h-14">
                    {getBottomTabs(character).map((tab) => (
                        <Link
                            key={tab.label}
                            href={tab.href}
                            {...prefetchPropsFor(tab.href)}
                            className="flex flex-col items-center gap-0.5 px-3 py-1.5 text-slate-400 hover:text-cyan-400 transition"
                        >
                            <tab.icon size={18} />
                            <span className="text-[10px] font-medium">
                                {tab.label}
                            </span>
                        </Link>
                    ))}
                    <button
                        onClick={() => setDrawerOpen(true)}
                        className="flex flex-col items-center gap-0.5 px-3 py-1.5 text-slate-400 hover:text-cyan-400 transition"
                    >
                        <List size={18} weight="bold" />
                        <span className="text-[10px] font-medium">More</span>
                    </button>
                </div>
            </div>

            { }
            <OnlinePlayerTooltip ref={tooltipRef} />

        </div>
    );
}
