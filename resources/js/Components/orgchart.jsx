import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import { route } from 'ziggy-js';
import { CaretDown, CaretRight, Crown, MapPin, Star, Users } from '@phosphor-icons/react';
import { Avatar, Button, Dialog, cn, focusRing } from '@/Components/ui';
import './orgchart.css';

/*
 * Corporate hierarchy chart.
 *
 * Mirrors app/Models/Corporation.php:
 *  - Operating company: CEO (ceo_id) at the top; CFO/CTO report to the CEO;
 *    VPs and members report to whoever `corporation_reports_to_id` names
 *    (`reportsToId`), falling back to the CEO when unset or invalid.
 *  - Holding company: board seats (group_president / chairman /
 *    director_of_board), capacity min(subsidiaries, 4) + 1; it owns up to 4
 *    subsidiaries (operating companies, each with its own tree).
 *  - Phase 3: the Director of the Board is the sole beneficiary of the trust
 *    that owns the holding, so it sits above the holding.
 */

const MAX_SUBSIDIARIES = 4;

const ROLE_META = {
    BOARD_DIRECTOR: { label: 'Director of the Board', badge: 'border-amber-300/50 bg-amber-300/15 text-amber-200', order: 0, board: true },
    CHAIRMAN: { label: 'Chairman', badge: 'border-amber-300/40 bg-amber-300/10 text-amber-200', order: 1, board: true },
    GROUP_PRESIDENT: { label: 'Group President', badge: 'border-amber-300/40 bg-amber-300/10 text-amber-200', order: 2, board: true },
    BOARD: { label: 'Board', badge: 'border-amber-300/40 bg-amber-300/10 text-amber-200', order: 3, board: true },
    CEO: { label: 'CEO', badge: 'border-amber-400/50 bg-amber-400/15 text-amber-300', order: 4 },
    CFO: { label: 'CFO', badge: 'border-emerald-400/40 bg-emerald-400/10 text-emerald-300', order: 5 },
    CTO: { label: 'CTO', badge: 'border-cyan-400/40 bg-cyan-400/10 text-cyan-300', order: 6 },
    VP: { label: 'VP', badge: 'border-sky-400/40 bg-sky-400/10 text-sky-300', order: 7 },
    MEMBER: { label: 'Member', badge: 'border-slate-600 bg-slate-800/80 text-slate-300', order: 8 },
};

const EMPTY_SET = new Set();
const WIDE_QUERY = '(min-width: 640px)';
// Trees larger than this start with deep branches collapsed.
const SMALL_TREE = 12;

function positionOf(person) {
    if (person?.isCeo) return 'CEO';
    return (person?.position || 'MEMBER').toString().toUpperCase();
}

function roleMeta(person) {
    return ROLE_META[positionOf(person)] ?? ROLE_META.MEMBER;
}

function sortPeople(people = []) {
    return [...people].sort((left, right) => {
        const order = roleMeta(left).order - roleMeta(right).order;
        return order !== 0 ? order : (left.name ?? '').localeCompare(right.name ?? '');
    });
}

function monogram(name) {
    const words = (name ?? '').trim().split(/\s+/).filter(Boolean);
    if (words.length === 0) return '?';
    if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
    return (words[0][0] + words[1][0]).toUpperCase();
}

// ── Layout helpers ──────────────────────────────────────────────────────────

function subscribeWide(callback) {
    const query = window.matchMedia(WIDE_QUERY);
    query.addEventListener('change', callback);
    return () => query.removeEventListener('change', callback);
}

/** true at >= 640px (top-down chart); false renders the indented outline. */
function useIsWide() {
    return useSyncExternalStore(
        subscribeWide,
        () => window.matchMedia(WIDE_QUERY).matches,
        () => true,
    );
}

/**
 * Builds the reporting tree for one company from the flat member list.
 * parentOf: id -> parent id (null for roots). Unknown / self / cyclic
 * `reportsToId` values fall back to the CEO, as the server does.
 */
function buildHierarchy(members = [], currentUserId = null) {
    const people = members.filter(Boolean);
    const byId = new Map(people.map((person) => [Number(person.id), person]));
    const ceo = people.find((person) => positionOf(person) === 'CEO') ?? null;
    const ceoId = ceo ? Number(ceo.id) : null;
    const parentOf = new Map();

    people.forEach((person) => {
        const id = Number(person.id);
        if (id === ceoId) {
            parentOf.set(id, null);
            return;
        }
        const reportsTo = Number(person.reportsToId);
        parentOf.set(id, byId.has(reportsTo) && reportsTo !== id ? reportsTo : ceoId);
    });

    // Break reporting cycles by re-attaching to the CEO.
    people.forEach((person) => {
        const id = Number(person.id);
        const seen = new Set([id]);
        let cursor = parentOf.get(id);
        while (cursor !== null && cursor !== undefined) {
            if (seen.has(cursor)) {
                parentOf.set(id, ceoId !== id ? ceoId : null);
                break;
            }
            seen.add(cursor);
            cursor = parentOf.get(cursor);
        }
    });

    const childrenOf = new Map();
    const roots = [];
    sortPeople(people).forEach((person) => {
        const id = Number(person.id);
        const parent = parentOf.get(id);
        if (parent === null || parent === undefined) {
            roots.push(id);
        } else {
            if (!childrenOf.has(parent)) childrenOf.set(parent, []);
            childrenOf.get(parent).push(id);
        }
    });

    const descendantCount = new Map();
    const countUnder = (id) => {
        if (descendantCount.has(id)) return descendantCount.get(id);
        const total = (childrenOf.get(id) ?? []).reduce((sum, child) => sum + 1 + countUnder(child), 0);
        descendantCount.set(id, total);
        return total;
    };
    people.forEach((person) => countUnder(Number(person.id)));

    const depthOf = (id) => {
        let depth = 0;
        let cursor = parentOf.get(id);
        while (cursor !== null && cursor !== undefined) {
            depth += 1;
            cursor = parentOf.get(cursor);
        }
        return depth;
    };

    const chain = new Set();
    const me = currentUserId === null || currentUserId === undefined ? null : Number(currentUserId);
    if (me !== null && byId.has(me)) {
        let cursor = me;
        while (cursor !== null && cursor !== undefined) {
            chain.add(cursor);
            cursor = parentOf.get(cursor);
        }
    }

    return { byId, parentOf, childrenOf, roots, descendantCount, depthOf, chain, size: people.length };
}

function initialCollapsed(hierarchy) {
    const collapsed = new Set();
    if (hierarchy.size <= SMALL_TREE) return collapsed;

    hierarchy.childrenOf.forEach((children, id) => {
        if (children.length > 0 && hierarchy.depthOf(id) >= 1 && !hierarchy.chain.has(id)) {
            collapsed.add(id);
        }
    });

    return collapsed;
}

// ── Shared chart context (viewer + selection) ──────────────────────────────

const ChartContext = createContext({ currentUserId: null, select: () => {} });

// ── Small pieces ────────────────────────────────────────────────────────────

/** Corporation logo with an initials monogram when there is no image or it fails to load. */
export function CorpLogo({ src, name, size = 40, className = '', textClassName = '', imgClassName = '' }) {
    const [failed, setFailed] = useState(false);
    useEffect(() => setFailed(false), [src]);
    const style = size ? { width: size, height: size } : undefined;

    if (src && !failed) {
        return (
            <img
                src={src}
                alt={name ? `${name} logo` : ''}
                onError={() => setFailed(true)}
                className={cn('shrink-0 rounded-xl object-cover', className, imgClassName)}
                style={style}
            />
        );
    }

    return (
        <span
            role="img"
            aria-label={name ? `${name} logo` : 'Corporation logo'}
            className={cn(
                'inline-flex shrink-0 select-none items-center justify-center rounded-xl border border-amber-300/25 bg-gradient-to-br from-slate-700 via-slate-800 to-slate-950 font-black tracking-tight text-amber-200 shadow-[inset_0_1px_0_rgba(255,255,255,0.08)]',
                className,
                textClassName,
            )}
            style={size ? { ...style, fontSize: Math.round(size * 0.38) } : undefined}
        >
            {monogram(name)}
        </span>
    );
}

function RoleBadge({ person, className = '' }) {
    const meta = roleMeta(person);

    return (
        <span
            className={cn(
                'inline-flex h-[18px] shrink-0 items-center whitespace-nowrap rounded-md border px-1.5 text-[11px] font-bold uppercase leading-none tracking-[0.06em]',
                meta.badge,
                className,
            )}
        >
            {meta.label}
        </span>
    );
}

function SectionLabel({ children, className = '' }) {
    return (
        <p className={cn('text-[11px] font-bold uppercase tracking-[0.18em] text-slate-400', className)}>
            {children}
        </p>
    );
}

/** Clickable person card: avatar, name, role badge, rank. */
function PersonChip({ person, context, inChain = false, showRank: rankAllowed = true, className = '' }) {
    const { currentUserId, select } = useContext(ChartContext);
    const meta = roleMeta(person);
    const isYou = currentUserId !== null && currentUserId !== undefined && Number(person.id) === Number(currentUserId);
    const showRank = rankAllowed && person.rank && !meta.board && person.rank !== meta.label;

    return (
        <button
            type="button"
            onClick={() => select(person, context)}
            aria-label={`${person.name}, ${meta.label}${isYou ? ' (you)' : ''}. Open details`}
            className={cn(
                'relative flex h-12 items-center gap-2 rounded-xl border bg-slate-900 px-2 text-left transition-colors hover:border-slate-500 hover:bg-slate-800',
                focusRing,
                isYou
                    ? 'border-cyan-400/80 shadow-[0_0_0_1px_rgba(34,211,238,0.35)]'
                    : inChain
                        ? 'border-cyan-500/40'
                        : meta.board
                            ? 'border-amber-300/25'
                            : 'border-slate-700/80',
                className,
            )}
        >
            <Avatar src={person.avatarUrl} name={person.name} size="sm" shape="rounded" />
            <span className="min-w-0 flex-1">
                <span className="flex min-w-0 items-center gap-1">
                    <span className="truncate text-[13px] font-bold leading-[18px] text-white">{person.name ?? 'Vacant'}</span>
                    {person.isFounder && (
                        <Star size={12} weight="fill" className="shrink-0 text-amber-400" aria-label="Founder" />
                    )}
                </span>
                <span className="mt-0.5 flex min-w-0 items-center gap-1.5">
                    <RoleBadge person={person} />
                    {showRank && <span className="truncate text-[11px] leading-[18px] text-slate-400">{person.rank}</span>}
                </span>
            </span>
            {isYou && (
                <span className="absolute -top-2 right-2 rounded-full bg-cyan-400 px-1.5 text-[11px] font-black uppercase leading-4 tracking-wide text-slate-950">
                    You
                </span>
            )}
        </button>
    );
}

function BranchToggle({ person, open, direct, total, onToggle, layout }) {
    const Icon = open ? CaretDown : CaretRight;
    const label = `${open ? 'Collapse' : 'Expand'} ${person.name}'s reports (${total})`;

    return (
        <button
            type="button"
            onClick={onToggle}
            aria-expanded={open}
            aria-label={label}
            title={label}
            className={cn(
                'inline-flex items-center justify-center gap-0.5 rounded-full border border-slate-600 bg-slate-900 text-[11px] font-bold tabular-nums text-slate-300 transition-colors hover:border-cyan-400/60 hover:text-white',
                focusRing,
                layout === 'chart'
                    ? 'absolute left-1/2 top-full z-[2] h-5 min-w-[2.25rem] -translate-x-1/2 -translate-y-1/2 px-1.5'
                    : 'h-7 min-w-[2.5rem] shrink-0 px-1.5',
            )}
        >
            <Icon size={11} weight="bold" aria-hidden />
            {open ? direct : `+${total}`}
        </button>
    );
}

/**
 * <ul> for one chart level. When it contains the viewer's chain, measures the
 * stretch of the sibling bar between the parent stub and that child so it can
 * be drawn in cyan.
 */
function ChartList({ className, chainIndex = -1, children }) {
    const ref = useRef(null);
    const [bar, setBar] = useState(null);
    const hasChain = chainIndex >= 0;

    useLayoutEffect(() => {
        const list = ref.current;
        if (!hasChain || !list) {
            setBar(null);
            return undefined;
        }

        const measure = () => {
            const item = list.children[chainIndex];
            if (!item) return;
            const centre = list.clientWidth / 2;
            const childCentre = item.offsetLeft + item.offsetWidth / 2;
            const next = { left: Math.min(centre, childCentre), width: Math.abs(centre - childCentre) };
            setBar((prev) => (prev && prev.left === next.left && prev.width === next.width ? prev : next));
        };

        measure();
        if (typeof ResizeObserver === 'undefined') return undefined;
        const observer = new ResizeObserver(measure);
        observer.observe(list);
        Array.from(list.children).forEach((item) => observer.observe(item));

        return () => observer.disconnect();
    }, [hasChain, chainIndex, children]);

    return (
        <ul
            ref={ref}
            className={cn(className, hasChain && 'has-chain')}
            style={bar ? { '--oc-bar-l': `${bar.left}px`, '--oc-bar-w': `${bar.width}px` } : undefined}
        >
            {children}
        </ul>
    );
}

function Branch({ ids, hierarchy, layout, root = false, animate = false, collapsed, opened, onToggle, companyName, rootReportsTo, compact = false }) {
    const isChart = layout === 'chart';
    const chainIndex = ids.findIndex((id) => hierarchy.chain.has(id));

    const items = ids.map((id, index) => {
        const person = hierarchy.byId.get(id);
        const children = hierarchy.childrenOf.get(id) ?? [];
        const hasChildren = children.length > 0;
        const open = hasChildren && !collapsed.has(id);
        const inChain = hierarchy.chain.has(id);
        const parentId = hierarchy.parentOf.get(id);
        const context = {
            companyName,
            reportsTo: parentId !== null && parentId !== undefined ? hierarchy.byId.get(parentId) : null,
            rootReportsTo,
            directReports: children.map((childId) => hierarchy.byId.get(childId)),
            totalReports: hierarchy.descendantCount.get(id) ?? 0,
        };
        const toggle = hasChildren ? (
            <BranchToggle
                person={person}
                open={open}
                direct={children.length}
                total={hierarchy.descendantCount.get(id) ?? 0}
                onToggle={() => onToggle(id)}
                layout={layout}
            />
        ) : null;

        return (
            <li
                key={id}
                className={cn(
                    isChart ? 'oc-c-i' : 'oc-o-i',
                    inChain && 'is-chain',
                    !isChart && chainIndex > index && 'rail-chain',
                    isChart && hasChildren && !open && 'pb-3',
                )}
            >
                {isChart ? (
                    <div className="relative">
                        <PersonChip person={person} context={context} inChain={inChain} className="w-[13rem]" />
                        {toggle}
                    </div>
                ) : (
                    <div className="flex items-center gap-1.5">
                        <PersonChip
                            person={person}
                            context={context}
                            inChain={inChain}
                            showRank={!compact}
                            className="min-w-0 max-w-[20rem] flex-1"
                        />
                        {toggle}
                    </div>
                )}
                {open && (
                    <Branch
                        ids={children}
                        hierarchy={hierarchy}
                        layout={layout}
                        animate={opened.has(id)}
                        collapsed={collapsed}
                        opened={opened}
                        onToggle={onToggle}
                        companyName={companyName}
                        rootReportsTo={rootReportsTo}
                        compact={compact}
                    />
                )}
            </li>
        );
    });

    if (isChart) {
        return (
            <ChartList className={cn('oc-c', root && 'oc-c-root', animate && 'oc-enter')} chainIndex={root ? -1 : chainIndex}>
                {items}
            </ChartList>
        );
    }

    return <ul className={cn('oc-o', root && 'oc-o-root', animate && 'oc-enter')}>{items}</ul>;
}

/** One company's reporting tree, as a top-down chart or an indented outline. */
function CompanyTree({ members, layout, companyName, rootReportsTo = null, compact = false }) {
    const { currentUserId } = useContext(ChartContext);
    const hierarchy = useMemo(() => buildHierarchy(members, currentUserId), [members, currentUserId]);
    const [collapsed, setCollapsed] = useState(() => initialCollapsed(hierarchy));
    // Branches the viewer expanded (only those animate in; nothing animates on load).
    const [opened, setOpened] = useState(EMPTY_SET);
    const toggle = useCallback((id) => {
        const expanding = collapsed.has(id);
        const nextCollapsed = new Set(collapsed);
        const nextOpened = new Set(opened);
        if (expanding) {
            nextCollapsed.delete(id);
            nextOpened.add(id);
        } else {
            nextCollapsed.add(id);
            nextOpened.delete(id);
        }
        setCollapsed(nextCollapsed);
        setOpened(nextOpened);
    }, [collapsed, opened]);
    // Fresh identity whenever the tree or its collapsed branches change.
    const shapeKey = useMemo(() => ({}), [hierarchy, collapsed]);
    const fit = useChartFit(layout === 'chart', shapeKey);

    if (hierarchy.size === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-8 text-center">
                <Users size={28} className="mb-2 text-slate-600" weight="duotone" aria-hidden />
                <p className="text-sm font-semibold text-slate-400">No members yet</p>
            </div>
        );
    }

    // A wide tree in a narrow panel would scroll sideways with no visual cue:
    // fall back to the outline instead, and return to the chart once the
    // container is wide enough again for the width the chart needed.
    const effectiveLayout = layout === 'chart' && fit.tooWide ? 'outline' : layout;

    const tree = (
        <Branch
            ids={hierarchy.roots}
            hierarchy={hierarchy}
            layout={effectiveLayout}
            root
            collapsed={collapsed}
            opened={opened}
            onToggle={toggle}
            companyName={companyName}
            rootReportsTo={rootReportsTo}
            compact={compact}
        />
    );

    if (effectiveLayout === 'chart') {
        return (
            <div ref={fit.ref} className="oc-tree overflow-x-auto pb-1">
                <div className="mx-auto w-max min-w-full px-1 pt-2">{tree}</div>
            </div>
        );
    }

    return (
        <div
            ref={layout === 'chart' ? fit.ref : undefined}
            className="oc-tree"
            style={compact ? { '--oc-indent': '0.875rem' } : undefined}
        >
            {tree}
        </div>
    );
}

/**
 * Tracks whether a top-down chart overflows its container. `tooWide` holds the
 * width the chart needed; it clears when the container grows past that width
 * or when the tree's shape changes (so the chart gets re-measured).
 */
function useChartFit(enabled, shapeKey) {
    const ref = useRef(null);
    const [needed, setNeeded] = useState(0);

    useLayoutEffect(() => {
        setNeeded(0);
    }, [shapeKey]);

    useLayoutEffect(() => {
        const el = ref.current;
        if (!enabled || !el) return undefined;

        const check = () => {
            if (needed) {
                if (el.clientWidth >= needed) setNeeded(0);
            } else if (el.scrollWidth > el.clientWidth + 1) {
                setNeeded(el.scrollWidth);
            }
        };

        check();
        if (typeof ResizeObserver === 'undefined') return undefined;
        const observer = new ResizeObserver(check);
        observer.observe(el);

        return () => observer.disconnect();
    }, [enabled, needed, shapeKey]);

    return { ref, tooWide: needed > 0 };
}

// ── Holding-company pieces ──────────────────────────────────────────────────

function SeatMeter({ label, value, max }) {
    const filled = Math.min(value, max);

    return (
        <div className="min-w-[7.5rem]">
            <SectionLabel>{label}</SectionLabel>
            <p className="mt-0.5 whitespace-nowrap text-sm font-black tabular-nums text-white">
                {value}
                <span className="text-slate-500"> / {max}</span>
                <span className="sr-only"> {label.toLowerCase()}</span>
            </p>
            <div className="mt-1 flex gap-1" aria-hidden>
                {Array.from({ length: Math.max(max, 1) }).map((_, index) => (
                    <span
                        key={index}
                        className={cn('h-1.5 flex-1 rounded-full', index < filled ? 'bg-amber-300' : 'bg-slate-700')}
                    />
                ))}
            </div>
        </div>
    );
}

function Trunk({ tone = 'slate', className = '' }) {
    const colour = tone === 'chain' ? 'bg-cyan-400/90' : tone === 'gold' ? 'bg-amber-300/50' : 'bg-slate-600/70';
    return <div aria-hidden className={cn('mx-auto h-6 w-px', colour, className)} />;
}

function TrustBanner({ trust, director, holdingName }) {
    const trustName = trust?.name ?? `${holdingName} Trust`;

    return (
        <section
            aria-label="Trust"
            className="rounded-2xl border border-amber-300/40 bg-gradient-to-br from-amber-300/[0.12] via-slate-950/70 to-slate-950/90 p-3 sm:p-4"
        >
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-amber-300/40 bg-amber-300/10 text-amber-200">
                        <Crown size={22} weight="fill" aria-hidden />
                    </span>
                    <div className="min-w-0">
                        <p className="text-[11px] font-bold uppercase tracking-[0.18em] text-amber-300">The Trust</p>
                        <p className="break-words text-base font-black leading-tight text-white">{trustName}</p>
                        <p className="mt-0.5 text-xs text-slate-400">Owns {holdingName}</p>
                    </div>
                </div>
                <div className="flex flex-col gap-2.5 sm:items-end">
                    <SectionLabel className="text-amber-200/80">Sole beneficiary</SectionLabel>
                    <PersonChip
                        person={{ ...director, position: 'BOARD_DIRECTOR', isCeo: false }}
                        context={{ companyName: trustName, seat: `Director of the Board of ${holdingName}` }}
                        className="w-full sm:w-auto sm:min-w-[13rem] sm:pr-3"
                    />
                </div>
            </div>
        </section>
    );
}

function HoldingBar({ holding, board, seated, capacity, subsidiaryCount }) {
    const vacant = Math.max(0, capacity - seated);
    const holdingName = holding?.name ?? 'Holding company';

    return (
        <section aria-label="Holding company" className="rounded-2xl border border-slate-700/70 bg-slate-950/60 p-3 sm:p-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                <div className="flex min-w-0 flex-1 items-center gap-3">
                    <CorpLogo src={holding?.imageUrl || holding?.image_url} name={holdingName} size={48} />
                    <div className="min-w-0">
                        <p className="text-[11px] font-bold uppercase tracking-[0.18em] text-amber-300/90">Holding company</p>
                        <h3 className="break-words text-base font-black leading-tight text-white sm:text-lg">{holdingName}</h3>
                        {holding?.city && (
                            <p className="mt-0.5 flex items-center gap-1 text-xs text-slate-400">
                                <MapPin size={12} aria-hidden /> {holding.city}
                            </p>
                        )}
                    </div>
                </div>
                <div className="grid grid-cols-2 gap-4 sm:flex sm:shrink-0">
                    <SeatMeter label="Board" value={seated} max={capacity} />
                    <SeatMeter label="Subsidiaries" value={subsidiaryCount} max={MAX_SUBSIDIARIES} />
                </div>
            </div>

            <div className="mt-3 border-t border-slate-800 pt-3">
                <SectionLabel className="mb-3">Board of Directors</SectionLabel>
                {board.length === 0 && vacant === 0 ? (
                    <p className="text-sm text-slate-400">No board members.</p>
                ) : (
                    <ul className="flex flex-wrap gap-x-2 gap-y-3">
                        {board.map((member) => (
                            <li key={member.id} className="w-full sm:w-auto sm:min-w-[13rem]">
                                <PersonChip
                                    person={member}
                                    context={{ companyName: holdingName, seat: `Board of ${holdingName}` }}
                                    className="w-full sm:pr-3"
                                />
                            </li>
                        ))}
                        {Array.from({ length: vacant }).map((_, index) => (
                            <li
                                key={`vacant-${index}`}
                                className="flex h-12 w-full items-center justify-center rounded-xl border border-dashed border-slate-700 text-xs font-semibold text-slate-400 sm:w-[13rem]"
                            >
                                Open seat
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}

function SubsidiaryHeader({ company, isMine, open, collapsible }) {
    const count = (company.members ?? []).length;
    const Icon = open ? CaretDown : CaretRight;

    return (
        <>
            <CorpLogo src={company.imageUrl || company.image_url} name={company.name} size={36} />
            <span className="min-w-0 flex-1 text-left">
                <span className="block break-words text-sm font-black leading-tight text-white">{company.name}</span>
                <span className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-slate-400">
                    {company.city && (
                        <span className="inline-flex items-center gap-1">
                            <MapPin size={12} aria-hidden /> {company.city}
                        </span>
                    )}
                    <span className="inline-flex items-center gap-1">
                        <Users size={12} aria-hidden /> {count} {count === 1 ? 'member' : 'members'}
                    </span>
                    {isMine && <span className="font-bold text-cyan-300">Your company</span>}
                </span>
            </span>
            {collapsible && <Icon size={14} weight="bold" className="shrink-0 text-slate-400" aria-hidden />}
        </>
    );
}

function SubsidiaryCard({ company, holdingName, isMine, collapsible = false, defaultOpen = true, className = '' }) {
    const [open, setOpen] = useState(defaultOpen);
    const [touched, setTouched] = useState(false);
    const bodyOpen = !collapsible || open;
    const frame = cn(
        'rounded-2xl border bg-slate-950/60',
        isMine ? 'border-cyan-500/40' : 'border-slate-700/70',
        className,
    );

    return (
        <section aria-label={company.name} className={frame}>
            {collapsible ? (
                <button
                    type="button"
                    onClick={() => {
                        setTouched(true);
                        setOpen((value) => !value);
                    }}
                    aria-expanded={open}
                    className={cn('flex min-h-[3.75rem] w-full items-center gap-2.5 rounded-2xl px-3 py-2.5', focusRing)}
                >
                    <SubsidiaryHeader company={company} isMine={isMine} open={open} collapsible />
                </button>
            ) : (
                <div className="flex min-h-[3.75rem] items-center gap-2.5 px-3 py-2.5">
                    <SubsidiaryHeader company={company} isMine={isMine} />
                </div>
            )}
            {bodyOpen && (
                <div className={cn('border-t border-slate-800 p-2.5', touched && 'oc-enter')}>
                    <CompanyTree members={company.members ?? []} layout="outline" companyName={company.name} rootReportsTo={holdingName} compact />
                </div>
            )}
        </section>
    );
}

function HoldingChart({ trust, holdingCompany, operatingCompanies }) {
    const { currentUserId } = useContext(ChartContext);
    const wide = useIsWide();
    const holdingName = holdingCompany?.name ?? 'Holding company';
    const allBoard = holdingCompany?.boardMembers ?? [];
    const director = holdingCompany?.director
        ?? trust?.BOARD_DIRECTOR
        ?? allBoard.find((member) => positionOf(member) === 'BOARD_DIRECTOR')
        ?? null;
    const board = sortPeople(allBoard.filter((member) => (
        member && Number(member.id) !== Number(director?.id) && positionOf(member) !== 'BOARD_DIRECTOR'
    )));
    const companies = operatingCompanies ?? [];
    const capacity = holdingCompany?.boardCapacity ?? Math.min(companies.length, MAX_SUBSIDIARIES) + 1;
    const seated = holdingCompany?.boardCount ?? board.length + (director ? 1 : 0);
    const isMe = (person) => currentUserId !== null && currentUserId !== undefined && Number(person?.id) === Number(currentUserId);
    const myCompanyIndex = companies.findIndex((company) => (company.members ?? []).some(isMe));
    const meBelowTrust = myCompanyIndex >= 0 || board.some(isMe);

    return (
        <div className="oc-tree space-y-0 py-1">
            {director && (
                <>
                    <TrustBanner trust={trust} director={director} holdingName={holdingName} />
                    <Trunk tone={meBelowTrust ? 'chain' : 'gold'} />
                </>
            )}

            <HoldingBar
                holding={holdingCompany}
                board={board}
                seated={seated}
                capacity={capacity}
                subsidiaryCount={companies.length}
            />

            {companies.length === 0 ? (
                <p className="mt-3 rounded-xl border border-dashed border-slate-700 px-3 py-4 text-center text-sm text-slate-400">
                    No active subsidiaries.
                </p>
            ) : wide ? (
                <div className="snap-x snap-mandatory overflow-x-auto pb-2">
                    <div className="mx-auto w-max min-w-full">
                        <ul className="oc-c oc-c-root">
                            <li className="oc-c-i">
                                <Trunk tone={myCompanyIndex >= 0 ? 'chain' : 'slate'} className="h-4" />
                                <span className={cn(
                                    'rounded-full border bg-slate-900 px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-[0.16em]',
                                    myCompanyIndex >= 0 ? 'border-cyan-500/50 text-cyan-200' : 'border-slate-700 text-slate-400',
                                )}
                                >
                                    Owns {companies.length} {companies.length === 1 ? 'subsidiary' : 'subsidiaries'}
                                </span>
                                <ChartList className="oc-c" chainIndex={myCompanyIndex}>
                                    {companies.map((company, index) => (
                                        <li
                                            key={company.id ?? index}
                                            className={cn('oc-c-i snap-start', index === myCompanyIndex && 'is-chain')}
                                        >
                                            <SubsidiaryCard company={company} holdingName={holdingName} isMine={index === myCompanyIndex} className="w-[17rem]" />
                                        </li>
                                    ))}
                                </ChartList>
                            </li>
                        </ul>
                    </div>
                </div>
            ) : (
                <ul className="oc-o oc-o-root">
                    <li className="oc-o-i">
                        <ul className="oc-o" style={{ '--oc-indent': '1.25rem', '--oc-elbow': '1.875rem' }}>
                            {companies.map((company, index) => (
                                <li
                                    key={company.id ?? index}
                                    className={cn(
                                        'oc-o-i',
                                        index === myCompanyIndex && 'is-chain',
                                        myCompanyIndex > index && 'rail-chain',
                                    )}
                                >
                                    <SubsidiaryCard
                                        company={company}
                                        holdingName={holdingName}
                                        isMine={index === myCompanyIndex}
                                        collapsible
                                        defaultOpen={myCompanyIndex >= 0 ? index === myCompanyIndex : index === 0}
                                    />
                                </li>
                            ))}
                        </ul>
                    </li>
                </ul>
            )}
        </div>
    );
}

// ── Member dialog ───────────────────────────────────────────────────────────

function Fact({ label, children }) {
    return (
        <div className="rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2">
            <dt className="text-[11px] font-bold uppercase tracking-[0.16em] text-slate-400">{label}</dt>
            <dd className="mt-0.5 truncate text-sm font-semibold text-white">{children}</dd>
        </div>
    );
}

function MemberDialog({ selection, onClose, renderMemberActions }) {
    // Keep the last selection rendered while the dialog animates out.
    const [shown, setShown] = useState(selection);
    useEffect(() => {
        if (selection) setShown(selection);
    }, [selection]);

    const person = shown?.person;
    const context = shown?.context ?? {};
    if (!person) return null;

    const meta = roleMeta(person);
    const direct = (context.directReports ?? []).filter(Boolean);
    const reportsTo = context.reportsTo?.name
        ?? context.rootReportsTo
        ?? (positionOf(person) === 'CEO' ? 'No one' : person.reportsToName ?? null);
    const profileHref = person.isPreview ? null : route('profile.show', { displayName: person.name });
    const actions = renderMemberActions ? renderMemberActions(person, { close: onClose }) : null;

    return (
        <Dialog
            open={Boolean(selection)}
            onClose={onClose}
            size="sm"
            tone={meta.board ? 'gold' : 'default'}
            title={person.name}
            description={context.seat ?? context.companyName ?? null}
            footer={(
                <div className="flex w-full flex-wrap justify-end gap-2">
                    <Button variant="ghost" size="sm" onClick={onClose}>Close</Button>
                    {profileHref && (
                        <Button href={profileHref} size="sm" variant="secondary">View profile</Button>
                    )}
                </div>
            )}
        >
            <div className="flex items-center gap-3">
                <Avatar src={person.avatarUrl} name={person.name} size="lg" shape="rounded" />
                <div className="min-w-0 space-y-1">
                    <RoleBadge person={person} />
                    {person.rank && <p className="text-sm text-slate-300">{person.rank}</p>}
                    {person.isFounder && (
                        <p className="flex items-center gap-1 text-xs text-amber-300">
                            <Star size={12} weight="fill" aria-hidden /> Founder
                        </p>
                    )}
                </div>
            </div>

            <dl className="mt-4 grid grid-cols-2 gap-2">
                {context.seat ? (
                    <Fact label="Seat">{meta.label}</Fact>
                ) : (
                    <Fact label="Reports to">{reportsTo ?? '—'}</Fact>
                )}
                <Fact label="Reports">
                    {context.seat ? '—' : `${direct.length} direct · ${context.totalReports ?? 0} total`}
                </Fact>
            </dl>

            {direct.length > 0 && (
                <div className="mt-3">
                    <SectionLabel className="mb-1.5">Direct reports</SectionLabel>
                    <ul className="flex flex-wrap gap-1.5">
                        {direct.map((report) => (
                            <li key={report.id} className="flex items-center gap-1.5 rounded-lg border border-slate-800 bg-slate-950/60 py-1 pl-1 pr-2">
                                <Avatar src={report.avatarUrl} name={report.name} size="xs" shape="rounded" />
                                <span className="text-xs font-semibold text-slate-200">{report.name}</span>
                                <span className="text-[11px] uppercase text-slate-400">{roleMeta(report).label}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {person.isPreview && (
                <p className="mt-3 text-xs text-slate-400">Preview data — this person is not a real player.</p>
            )}

            {actions && <div className="mt-4 border-t border-slate-800 pt-3">{actions}</div>}
        </Dialog>
    );
}

// ── Public component ────────────────────────────────────────────────────────

export default function OrgChart({
    members = [],
    mode = 'single',
    trust = null,
    holdingCompany = null,
    operatingCompanies = [],
    currentUserId = null,
    companyName = null,
    rootReportsTo = null,
    renderMemberActions = null,
}) {
    const wide = useIsWide();
    const [selection, setSelection] = useState(null);
    const select = useCallback((person, context) => setSelection({ person, context: context ?? {} }), []);
    const close = useCallback(() => setSelection(null), []);
    const context = useMemo(() => ({ currentUserId, select }), [currentUserId, select]);

    return (
        <ChartContext.Provider value={context}>
            {mode === 'holding' ? (
                <HoldingChart trust={trust} holdingCompany={holdingCompany} operatingCompanies={operatingCompanies} />
            ) : (
                <CompanyTree
                    members={members}
                    layout={wide ? 'chart' : 'outline'}
                    companyName={companyName}
                    rootReportsTo={rootReportsTo}
                />
            )}
            <MemberDialog selection={selection} onClose={close} renderMemberActions={renderMemberActions} />
        </ChartContext.Provider>
    );
}
