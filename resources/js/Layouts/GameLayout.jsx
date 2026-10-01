import { useState, useEffect, useMemo, useRef, useCallback, memo, forwardRef, useImperativeHandle } from "react";
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
    getFooterItems,
    getBottomTabs,
    PlayerTooltip,
    getPlayerBorderClass,
    ClockDisplay,
    CooldownsList,
    MobileCooldownsList,
    formatOnlinePlayerName,
    isExecutivePlayerName,
    prefetchPropsFor,
} from "./GameLayoutComponents";
import NavList, { useNavListState, useActiveHref } from "./NavList";
import { Money, Spinner, StatBar, Toaster, useFlashToasts } from "@/Components/ui";

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

// prefetchPropsFor() (hover prefetch, 5s cache, /journal + /messages
// excluded) now lives in GameLayoutComponents so the cooldown chips and
// NavList share it.

// Flash messages that a page already renders as its own result UI, keyed by
// Inertia component name. Checked against the *incoming* page inside the
// toast bridge (Inertia's beforeUpdate), so it is correct even on the visit
// that switches pages — a layout prop would still hold the previous page's
// value at that moment. Everything else becomes a toast.
const FLASH_RENDERED_BY_PAGE = {
    // Pachinko/slots parse "profit of $X" / "lost $X" / JACKPOT into their
    // own win/loss panel; other Pachinko messages (errors, owner actions)
    // still toast.
    "City/Pachinko": (kind, message) =>
        kind !== "warning" && /profit of \$|lost \$|break even|JACKPOT/i.test(message),
};

const ignoreFlash = (kind, message, page) =>
    Boolean(FLASH_RENDERED_BY_PAGE[page?.component]?.(kind, message));

// Escape closes, focus moves in on open and back to the trigger on close.
function useDrawerA11y(open, onClose, panelRef) {
    useEffect(() => {
        if (!open) return;
        const previouslyFocused = document.activeElement;
        const raf = requestAnimationFrame(() =>
            panelRef.current?.querySelector("button, a[href]")?.focus({ preventScroll: true }),
        );
        const onKey = (e) => {
            if (e.key === "Escape") onClose();
        };
        document.addEventListener("keydown", onKey);
        const prevOverflow = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        return () => {
            cancelAnimationFrame(raf);
            document.removeEventListener("keydown", onKey);
            document.body.style.overflow = prevOverflow;
            if (previouslyFocused && document.contains(previouslyFocused)) {
                previouslyFocused.focus({ preventScroll: true });
            }
        };
    }, [open, onClose, panelRef]);
}

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
    const { auth, onlinePlayers } = usePage().props;
    const { url } = usePage();
    const character = auth?.character;
    const [showSettings, setShowSettings] = useState(false);
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [statsExpanded, setStatsExpanded] = useState(false);
    const [onlineFilter, setOnlineFilter] = useState("city");
    const tooltipRef = useRef(null);
    const drawerRef = useRef(null);
    const settingsRef = useRef(null);
    const pagePath = useMemo(() => url.split("?")[0], [url]);

    // Inertia flash (success/error/warning) → toasts, once per user action.
    // Replaces the inline banner that pushed the page down after every action.
    useFlashToasts({ ignore: ignoreFlash });

    const closeDrawer = useCallback(() => setDrawerOpen(false), []);
    useDrawerA11y(drawerOpen, closeDrawer, drawerRef);

    // Any navigation closes the drawer and the account menu.
    useEffect(() => {
        setDrawerOpen(false);
        setShowSettings(false);
    }, [pagePath]);

    // Account menu: close on outside click / Escape.
    useEffect(() => {
        if (!showSettings) return;
        const onDown = (e) => {
            if (!settingsRef.current?.contains(e.target)) setShowSettings(false);
        };
        const onKey = (e) => {
            if (e.key === "Escape") setShowSettings(false);
        };
        document.addEventListener("pointerdown", onDown);
        document.addEventListener("keydown", onKey);
        return () => {
            document.removeEventListener("pointerdown", onDown);
            document.removeEventListener("keydown", onKey);
        };
    }, [showSettings]);

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

    // Expand / quick-withdraw / quick-work state shared by sidebar + drawer.
    const navState = useNavListState();

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

    const healthTone = healthPercentage <= 30 ? "red" : healthPercentage <= 70 ? "amber" : "emerald";
    const healthText = healthPercentage <= 30 ? "text-red-400" : healthPercentage <= 70 ? "text-amber-400" : "text-emerald-400";

    // Bottom bar: active tab + badges pulled from the server nav items.
    const navItems = useMemo(() => navSections.flatMap((s) => s.items), [navSections]);
    const badgeFor = (href) => navItems.find((i) => i.href === href)?.badge || 0;
    const bottomTabs = getBottomTabs(character);
    const bottomHrefs = bottomTabs.map((t) => t.href);
    // "More" gets a dot when something only reachable from the drawer wants attention.
    const drawerAttention = navItems.some(
        (i) => !bottomHrefs.includes(i.href) && (i.badge > 0 || i.isAlert),
    );
    const activeNavHref = useActiveHref(navSections, pagePath);
    const isTabActive = (href) =>
        pagePath === href || pagePath.startsWith(`${href}/`) || activeNavHref === href;

    return (
        <div className="min-h-screen text-white flex flex-col">
            {isFlipped && (
                <div className="fixed inset-0 z-[100] pointer-events-none">
                    <div className="absolute inset-0 bg-gradient-to-r from-transparent via-cyan-500/10 to-transparent" />
                </div>
            )}

            <header className="h-12 border-b border-slate-800/50 flex items-center justify-between gap-2 px-3 sm:px-5 bg-slate-950/80 backdrop-blur-sm sticky top-0 z-header">
                <Link
                    href="/dashboard"
                    {...prefetchPropsFor("/dashboard")}
                    className="flex h-10 items-center font-bold text-sm hover:text-cyan-400 transition-colors whitespace-nowrap"
                >
                    THE <span className="ml-1 text-cyan-400">DIRECTOR</span>
                </Link>

                <div className="flex-1 min-w-0 flex items-center justify-center overflow-hidden">
                    {/* Clock moves into the mobile HUD below sm: it was truncated to "26 09::" at 390px. */}
                    <div className="hidden sm:flex items-center gap-1.5 shrink-0 text-slate-300">
                        <Clock size={12} className="text-cyan-400" aria-hidden />
                        <ClockDisplay />
                    </div>
                    <div className="hidden nav:flex items-center gap-0.5 ml-3 overflow-hidden">
                        <CooldownsList />
                    </div>
                </div>
                <div className="flex items-center gap-1 shrink-0">
                    {/* Wiki is now a plain Blade/HTML page, not Inertia —
                        a real anchor triggers a full browser navigation
                        instead of going through Inertia's router and
                        falling back. Cleaner intent. */}
                    <a
                        href={route('wiki.index')}
                        className="flex h-10 items-center rounded-lg px-2 text-label uppercase text-cyan-400 hover:text-cyan-200 hover:bg-cyan-500/10 transition-colors whitespace-nowrap no-underline"
                    >
                        Wiki
                    </a>
                    <Link
                        href={route('help')}
                        className="flex h-10 items-center rounded-lg px-2 text-label uppercase text-cyan-400 hover:text-cyan-200 hover:bg-cyan-500/10 transition-colors whitespace-nowrap"
                    >
                        Forum
                    </Link>
                    <div className="relative" ref={settingsRef}>
                        <button
                            type="button"
                            onClick={() => setShowSettings((v) => !v)}
                            aria-haspopup="menu"
                            aria-expanded={showSettings}
                            className="flex h-10 items-center gap-1.5 rounded-lg px-2 hover:bg-slate-800/60 transition-colors"
                        >
                            <span className="text-slate-300 text-xs font-medium max-w-[100px] truncate">
                                {character.displayName}
                            </span>
                            <CaretDown size={12} aria-hidden className={`text-slate-400 transition-transform duration-150 ${showSettings ? "rotate-180" : ""}`} />
                        </button>
                        {showSettings && (
                            <div role="menu" className="absolute right-0 mt-1 w-44 bg-slate-900/95 backdrop-blur-md border border-slate-800 rounded-xl shadow-2xl py-1">
                                {footerItems.map((item) => (
                                    <Link
                                        key={item.label}
                                        role="menuitem"
                                        href={item.route}
                                        {...prefetchPropsFor(item.route)}
                                        onClick={() => setShowSettings(false)}
                                        className="w-full min-h-[40px] px-3 text-left text-sm text-slate-300 hover:bg-slate-800 hover:text-white flex items-center gap-2.5 transition-colors"
                                    >
                                        <item.icon size={14} aria-hidden />
                                        {item.label}
                                    </Link>
                                ))}
                                <div className="border-t border-slate-800 my-1" />
                                <button
                                    type="button"
                                    role="menuitem"
                                    onClick={() => router.post("/logout")}
                                    className="w-full min-h-[40px] px-3 text-left text-sm text-red-400 hover:bg-slate-800 flex items-center gap-2.5 transition-colors"
                                >
                                    <SignOut size={14} weight="bold" aria-hidden />
                                    Logout
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            </header>

            <div className="nav:hidden border-b border-slate-800/50 bg-slate-950/60">
                <button
                    type="button"
                    onClick={() => setStatsExpanded((v) => !v)}
                    aria-expanded={statsExpanded}
                    aria-controls="mobile-hud-panel"
                    aria-label={`${statsExpanded ? "Hide" : "Show"} character stats and cooldowns`}
                    className="w-full min-h-[44px] flex items-center justify-between gap-2 px-3"
                >
                    <div className="flex items-center gap-3 text-xs overflow-hidden">
                        <span className="flex items-center gap-1" title="Health">
                            <Heart size={12} weight="fill" aria-hidden className={healthText} />
                            <span className={`tabular-nums font-semibold ${healthText}`}>
                                {character.health}/{character.maxHealth}
                            </span>
                        </span>
                        <Money amount={character.cleanCash} kind="clean" compact />
                        <Money amount={character.dirtyCash} kind="dirty" compact />
                        <span className="text-cyan-400 truncate">
                            {character.rank}
                        </span>
                    </div>
                    <CaretDown
                        size={14}
                        aria-hidden
                        className={`text-slate-400 shrink-0 transition-transform duration-150 ${statsExpanded ? "rotate-180" : ""}`}
                    />
                </button>
                {statsExpanded && (
                    <div id="mobile-hud-panel" className="px-3 pb-3 space-y-3">
                        <div className="flex items-center gap-1.5 text-slate-400 sm:hidden">
                            <Clock size={12} className="text-cyan-400" aria-hidden />
                            <ClockDisplay />
                        </div>
                        <MobileCooldownsList />
                        <div className="grid grid-cols-2 gap-x-4 gap-y-3">
                            <StatBar
                                label="Health"
                                value={character.health || 0}
                                max={character.maxHealth || 1}
                                tone={healthTone}
                                valueLabel={<span className={`tabular-nums ${healthText}`}>{character.health}/{character.maxHealth}</span>}
                            />
                            <StatBar
                                label="Rank"
                                value={character.rankProgress || 0}
                                tone="emerald"
                                valueLabel={<span className="tabular-nums text-emerald-400">{character.rankProgress || 0}%</span>}
                            />
                            <StatBar
                                label="Strength"
                                value={Math.min(100, character.strength || 0)}
                                tone="emerald"
                                valueLabel={<span className="tabular-nums text-emerald-400">{character.strength || 0}</span>}
                            />
                            <div>
                                <Works24hBar count={character.works24h} compact />
                            </div>
                        </div>
                    </div>
                )}
            </div>

            <div className="flex flex-1 overflow-hidden">
                {/* Mobile drawer: same `nav` breakpoint as the sidebar and the
                    bottom bar (it used lg:, leaving 1024–1071px with no nav). */}
                <AnimatePresence>
                    {drawerOpen && (
                        <div className="nav:hidden fixed inset-0 z-drawer">
                            <motion.div
                                aria-hidden
                                className="absolute inset-0 bg-slate-950/70 backdrop-blur-sm"
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                exit={{ opacity: 0 }}
                                transition={{ duration: 0.15 }}
                                onClick={closeDrawer}
                            />
                            <motion.aside
                                ref={drawerRef}
                                role="dialog"
                                aria-modal="true"
                                aria-label="Navigation"
                                initial={{ x: "-100%" }}
                                animate={{ x: 0 }}
                                exit={{ x: "-100%" }}
                                transition={{ duration: 0.15, ease: "easeOut" }}
                                className="absolute left-0 top-0 bottom-0 w-72 max-w-[85vw] bg-slate-950 border-r border-slate-800 flex flex-col overflow-hidden pb-[env(safe-area-inset-bottom)]"
                            >
                                <div className="p-4 border-b border-slate-800/60 flex items-center justify-between gap-2">
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
                                            <span className="truncate block text-slate-400">
                                                {character.career}
                                            </span>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={closeDrawer}
                                        aria-label="Close navigation"
                                        className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:text-white hover:bg-slate-800/60 transition-colors"
                                    >
                                        <X size={18} />
                                    </button>
                                </div>
                                <NavList
                                    sections={navSections}
                                    pagePath={pagePath}
                                    state={navState}
                                    onNavigate={closeDrawer}
                                />
                            </motion.aside>
                        </div>
                    )}
                </AnimatePresence>

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
                            <div className="text-xs text-slate-400 mt-2 flex items-center justify-center gap-3 flex-wrap">
                                <span className="flex items-center gap-1 truncate" title="Current city">
                                    <MapPin size={12} weight="bold" aria-hidden />
                                    <span className="truncate">
                                        {character.cityName}
                                    </span>
                                </span>
                                <span className="flex items-center gap-1 truncate" title="Home city">
                                    <House size={12} weight="bold" aria-hidden />
                                    <span className="truncate">
                                        {character.homeCity}
                                    </span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div className="p-4 border-b border-slate-800/50 space-y-3">
                        <StatBar
                            label={<span className="flex items-center gap-1"><Heart size={11} weight="fill" aria-hidden /> Health</span>}
                            aria-label="Health"
                            value={character.health || 0}
                            max={character.maxHealth || 1}
                            tone={healthTone}
                            valueLabel={<span className={`tabular-nums ${healthText}`}>{character.health}/{character.maxHealth}</span>}
                        />
                        <StatBar
                            label={<span className="flex items-center gap-1"><Target size={11} weight="bold" aria-hidden /> Rank</span>}
                            aria-label="Rank progress"
                            value={character.rankProgress || 0}
                            tone="emerald"
                            valueLabel={<span className="tabular-nums text-emerald-400">{character.rankProgress}%</span>}
                        />
                        <StatBar
                            label={<span className="flex items-center gap-1"><Barbell size={11} weight="bold" aria-hidden /> Strength</span>}
                            aria-label="Strength"
                            value={Math.min(100, character.strength || 0)}
                            tone="emerald"
                            valueLabel={<span className="tabular-nums text-emerald-400">{character.strength || "N/A"}</span>}
                        />
                        <div>
                            <Works24hBar count={character.works24h} compact />
                        </div>
                    </div>

                    <div className="p-4 border-b border-slate-800/50">
                        <div className="grid grid-cols-2 gap-2">
                            <div className="bg-slate-900/60 rounded-lg p-2.5 min-w-0">
                                <div className="text-label uppercase text-slate-400 mb-0.5">
                                    Cash
                                </div>
                                <Money amount={character.cleanCash} kind="clean" compact className="block truncate text-sm" />
                            </div>
                            <div className="bg-slate-900/60 rounded-lg p-2.5 min-w-0">
                                <div className="text-label uppercase text-slate-400 mb-0.5">
                                    Dirty
                                </div>
                                <Money amount={character.dirtyCash} kind="dirty" compact className="block truncate text-sm" />
                            </div>
                        </div>
                    </div>

                    <NavList
                        sections={navSections}
                        pagePath={pagePath}
                        state={navState}
                    />
                </aside>
                <div className="flex-1 flex flex-col min-w-0">
                    {/* Flash messages are toasts now (useFlashToasts + <Toaster/>),
                        so nothing here pushes the page down after an action. */}

                    {/* Top-aligned (was justify-center): content no longer jumps
                        vertically when its height changes. Still centred horizontally. */}
                    <main
                        className={`flex-1 overflow-x-hidden ${isFlipped ? "overflow-y-hidden" : "overflow-y-auto"} flex flex-col ${flush ? "items-stretch justify-start" : "items-center justify-start p-4 sm:p-6"} [perspective:2000px] relative`}
                    >
                        <motion.div
                            animate={{ rotateY: isFlipped ? 180 : 0 }}
                            transition={{
                                duration: 0.7,
                                ease: [0.23, 1, 0.32, 1],
                            }}
                            className={`w-full ${flush ? "max-w-none min-h-0 flex flex-col items-stretch justify-start" : `${wide ? "max-w-7xl" : "max-w-6xl"} min-h-[400px] flex flex-col items-center justify-start`} relative`}
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

                    <div className="border-t border-slate-900/20 bg-slate-900/20 backdrop-blur-md pb-[calc(3.5rem+env(safe-area-inset-bottom))] nav:pb-0">
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
                                    <div className="flex items-center gap-2 py-2 text-cyan-300">
                                        <Spinner size={14} label={null} />
                                        <span className="text-label uppercase text-cyan-100/70">
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

            <nav
                aria-label="Quick navigation"
                className="nav:hidden fixed bottom-0 left-0 right-0 z-header bg-slate-950/95 backdrop-blur-md border-t border-slate-800/60 pb-[env(safe-area-inset-bottom)]"
            >
                <div className="flex items-stretch h-14">
                    {bottomTabs.map((tab) => {
                        const active = isTabActive(tab.href);
                        const badge = tab.badgeFrom ? badgeFor(tab.badgeFrom) : 0;
                        return (
                            <Link
                                key={tab.label}
                                href={tab.href}
                                {...prefetchPropsFor(tab.href)}
                                aria-current={active ? "page" : undefined}
                                className={`relative flex flex-1 flex-col items-center justify-center gap-0.5 transition-colors ${active ? "text-cyan-400" : "text-slate-400 hover:text-cyan-300"}`}
                            >
                                {active && <span aria-hidden className="absolute top-0 inset-x-4 h-0.5 rounded-b bg-cyan-400" />}
                                <span className="relative">
                                    <tab.icon size={20} weight={active ? "fill" : tab.weight} aria-hidden />
                                    {badge > 0 && (
                                        <span className="absolute -top-1.5 left-3 min-w-[16px] rounded-full bg-cyan-500 px-1 text-center text-[10px] font-bold leading-4 tabular-nums text-slate-950">
                                            {badge > 99 ? "99+" : badge}
                                            <span className="sr-only"> unread</span>
                                        </span>
                                    )}
                                </span>
                                <span className="text-xs font-medium">{tab.label}</span>
                            </Link>
                        );
                    })}
                    <button
                        type="button"
                        onClick={() => setDrawerOpen(true)}
                        aria-expanded={drawerOpen}
                        aria-haspopup="dialog"
                        className="relative flex flex-1 flex-col items-center justify-center gap-0.5 text-slate-400 hover:text-cyan-300 transition-colors"
                    >
                        <span className="relative">
                            <List size={20} weight="bold" aria-hidden />
                            {drawerAttention && (
                                <span aria-hidden className="absolute -top-0.5 -right-1 h-2 w-2 rounded-full bg-amber-400 ring-2 ring-slate-950" />
                            )}
                        </span>
                        <span className="text-xs font-medium">
                            More{drawerAttention && <span className="sr-only"> (new items)</span>}
                        </span>
                    </button>
                </div>
            </nav>

            <OnlinePlayerTooltip ref={tooltipRef} />
            <Toaster />

        </div>
    );
}
