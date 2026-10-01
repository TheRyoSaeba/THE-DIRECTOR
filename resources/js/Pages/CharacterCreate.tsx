import {
    useCallback,
    useEffect,
    useId,
    useRef,
    useState,
    useSyncExternalStore,
    type CSSProperties,
    type KeyboardEvent,
    type ReactNode,
} from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import {
    ArrowLeft,
    ArrowRight,
    Buildings,
    Check,
    GenderFemale,
    GenderMale,
    GraduationCap,
    MapPin,
    ShieldStar,
    SignOut,
    Warning,
    Wrench,
    type Icon,
} from '@phosphor-icons/react';
import { route } from 'ziggy-js';
import { Badge, Button, Field, Input, StatBar, cn, textLabel, type Tone } from '@/Components/ui';

// ─── Types ──────────────────────────────────────────────────────────────────

interface City {
    id: number;
    name: string;
    description?: string | null;
    image_url?: string | null;
}

interface StartingStats {
    influence: number;
    intelligence: number;
    offense: number;
    defense: number;
    luck: number;
}

interface Career {
    id: number;
    name: string;
    code: string;
    description?: string | null;
    /** Rank-1 title, e.g. "Customs Agent". */
    rank_name?: string | null;
    /** Rank-1 avatar (career_ranks.avatar_url). */
    avatar_url?: string | null;
    /** From CharacterController::STARTER_CAREERS — the numbers store() actually grants. */
    stats?: StartingStats | null;
    perk?: { code: string; label: string; cycles: number } | null;
}

interface Props {
    cities: City[];
    careers: Career[];
}

interface PageProps {
    flash?: { error?: string; success?: string };
    errors?: Record<string, string>;
    [key: string]: unknown;
}

type Gender = 'Male' | 'Female';

// ─── Career look & copy ─────────────────────────────────────────────────────

interface CareerTheme {
    tone: Tone;
    /** "r, g, b" of the accent (kit tone -400) for gradients / glows. */
    rgb: string;
    icon: Icon;
    tagline: string;
    /** Background-image for the fallback art texture. */
    pattern: (rgb: string) => { backgroundImage: string; backgroundSize?: string };
}

const THEMES: Record<string, CareerTheme> = {
    customs: {
        tone: 'amber',
        rgb: '251, 191, 36',
        icon: ShieldStar,
        tagline: 'Work the border. Decide what gets through.',
        pattern: (rgb) => ({
            backgroundImage: `repeating-linear-gradient(135deg, rgba(${rgb}, 0.07) 0 2px, transparent 2px 26px)`,
        }),
    },
    corporation: {
        tone: 'purple',
        rgb: '192, 132, 252',
        icon: Buildings,
        tagline: 'Close the deal. Climb the ladder. Own the room.',
        pattern: (rgb) => ({
            backgroundImage: `repeating-linear-gradient(90deg, rgba(${rgb}, 0.08) 0 1px, transparent 1px 30px)`,
        }),
    },
    technician: {
        tone: 'emerald',
        rgb: '52, 211, 153',
        icon: Wrench,
        tagline: 'Fix anything. Build everything. The city runs on you.',
        pattern: (rgb) => ({
            backgroundImage: `linear-gradient(rgba(${rgb}, 0.07) 1px, transparent 1px), linear-gradient(90deg, rgba(${rgb}, 0.07) 1px, transparent 1px)`,
            backgroundSize: '34px 34px',
        }),
    },
};

const FALLBACK_THEME: CareerTheme = {
    tone: 'cyan',
    rgb: '34, 211, 238',
    icon: Buildings,
    tagline: 'Start from the bottom. Rise through the ranks.',
    pattern: () => ({ backgroundImage: 'none' }),
};

const themeFor = (code: string) => THEMES[code] ?? FALLBACK_THEME;

const STAT_ROWS: Array<{ key: keyof StartingStats; short: string; long: string }> = [
    { key: 'intelligence', short: 'INT', long: 'Intelligence' },
    { key: 'offense', short: 'OFF', long: 'Offense' },
    { key: 'defense', short: 'DEF', long: 'Defense' },
    { key: 'luck', short: 'LUCK', long: 'Luck' },
];
const STAT_MAX = 10;

/** The server description is a secondary line; skip it when it is just the career name. */
function descriptionOf(career: Career): string | null {
    const d = career.description?.trim();
    if (!d || d.toLowerCase() === career.name.toLowerCase()) return null;
    return d;
}

function srSummary(career: Career): string {
    const parts: string[] = [themeFor(career.code).tagline];
    if (career.rank_name) parts.push(`Starts as ${career.rank_name}.`);
    if (career.stats) parts.push(STAT_ROWS.map((s) => `${s.long} ${career.stats![s.key]}`).join(', ') + '.');
    if (career.perk) parts.push(`Starting perk: ${career.perk.label}.`);
    return parts.join(' ');
}

// City photos (same sources the old page used), then the DB column if the schema has it.
const CITY_IMAGES: Record<string, string> = {
    'New York': 'https://images.thedirector.app/newyork.jpeg',
    Tokyo: 'https://images.thedirector.app/tokyo.jpeg',
    Seoul: 'https://images.thedirector.app/seoul.jpeg',
};
const cityImage = (city?: City | null) => (city ? (CITY_IMAGES[city.name] ?? city.image_url ?? null) : null);

// Server cleans names with trim → squish → Str::title before validating.
const NAME_MAX = 15;
const NAME_ALLOWED = /^[A-Za-z0-9 ]*$/;
const previewName = (raw: string) =>
    raw
        .trim()
        .replace(/\s+/g, ' ')
        .split(' ')
        .map((w) => (w ? w[0].toUpperCase() + w.slice(1).toLowerCase() : w))
        .join(' ');

// ─── Motion constants ───────────────────────────────────────────────────────

const EASE = [0.22, 1, 0.36, 1] as const;
const DUR = 0.45; // seconds — every interactive transition stays under ~500ms
const DIAG = 64; // px horizontal run of the angled dividers
const TAB_W = 76; // px width of a collapsed side tab

function useMediaQuery(query: string): boolean {
    const subscribe = useCallback(
        (cb: () => void) => {
            const mql = window.matchMedia(query);
            mql.addEventListener('change', cb);
            return () => mql.removeEventListener('change', cb);
        },
        [query],
    );
    return useSyncExternalStore(
        subscribe,
        () => window.matchMedia(query).matches,
        () => true,
    );
}

/** Image that only shows once it has actually loaded; the caller paints the fallback underneath. */
function LoadedImage({ src, className, style }: { src?: string | null; className?: string; style?: CSSProperties }) {
    const [state, setState] = useState<'loading' | 'ok' | 'error'>('loading');
    useEffect(() => setState('loading'), [src]);
    if (!src || state === 'error') return null;
    return (
        <img
            src={src}
            alt=""
            aria-hidden
            draggable={false}
            onLoad={() => setState('ok')}
            onError={() => setState('error')}
            className={cn('transition-opacity duration-500 motion-reduce:transition-none', state === 'ok' ? 'opacity-100' : 'opacity-0', className)}
            style={style}
        />
    );
}

// ─── Career art (avatar or gradient + icon fallback) ────────────────────────

function CareerArt({
    career,
    index,
    kenBurns,
    compact = false,
    muted = false,
    rightInset = 0,
}: {
    career: Career;
    index: number;
    kenBurns: boolean;
    compact?: boolean;
    /** Collapsed side tab: hide the glyph / numeral so no fragments show. */
    muted?: boolean;
    /** px covered on the right (the form overlay) — the glyph re-centres in what is left. */
    rightInset?: number;
}) {
    const theme = themeFor(career.code);
    const Glyph = theme.icon;
    return (
        <motion.div
            aria-hidden
            className="absolute inset-0 will-change-transform"
            initial={false}
            animate={kenBurns ? { scale: 1.1, x: -14, y: -6 } : { scale: 1.02, x: 0, y: 0 }}
            transition={kenBurns ? { duration: 14, ease: 'linear' } : { duration: DUR, ease: EASE }}
        >
            {/* base: deep slate with an accent bloom */}
            <div
                className="absolute inset-0"
                style={{
                    background: `radial-gradient(120% 70% at 50% ${compact ? '50%' : '28%'}, rgba(${theme.rgb}, 0.22) 0%, rgba(${theme.rgb}, 0.06) 38%, transparent 70%), linear-gradient(180deg, #0b1120 0%, #020617 100%)`,
                }}
            />
            {/* hover / selected bloom */}
            <div
                className={cn('absolute inset-0 transition-opacity duration-[450ms] motion-reduce:transition-none', kenBurns ? 'opacity-100' : 'opacity-0')}
                style={{ background: `radial-gradient(70% 55% at 50% 32%, rgba(${theme.rgb}, 0.2), transparent 70%), linear-gradient(180deg, rgba(${theme.rgb}, 0.1), transparent 30%)` }}
            />
            {/* texture, faded out towards the edges */}
            <div
                className="absolute inset-0"
                style={{
                    ...theme.pattern(theme.rgb),
                    WebkitMaskImage: 'radial-gradient(90% 70% at 50% 35%, #000 20%, transparent 75%)',
                    maskImage: 'radial-gradient(90% 70% at 50% 35%, #000 20%, transparent 75%)',
                }}
            />
            {/* big glyph */}
            <div
                className={cn(
                    'absolute flex items-center justify-center transition-[opacity,right] duration-[450ms] ease-out motion-reduce:transition-none',
                    compact ? 'inset-y-0 right-[-8%] w-[60%]' : 'left-0 top-[11%] h-[50%]',
                    muted && 'opacity-0',
                )}
                style={compact ? undefined : { right: rightInset }}
            >
                <Glyph
                    weight="thin"
                    className={cn(
                        'transition-opacity duration-[450ms] motion-reduce:transition-none',
                        compact ? 'h-[150%] w-[150%] opacity-[0.18]' : 'h-full w-full max-w-[400px]',
                        !compact && (kenBurns ? 'opacity-[0.34]' : 'opacity-[0.2]'),
                    )}
                    style={{ color: `rgb(${theme.rgb})`, filter: `drop-shadow(0 0 28px rgba(${theme.rgb}, 0.45))` }}
                />
            </div>
            {/* index numeral */}
            {!compact && (
                <div
                    className={cn(
                        'absolute right-8 top-28 select-none font-black italic leading-none tracking-tighter text-transparent transition-opacity duration-300',
                        (muted || rightInset > 0) && 'opacity-0',
                    )}
                    style={{ fontSize: 120, WebkitTextStroke: `1px rgba(${theme.rgb}, 0.22)` }}
                >
                    {String(index + 1).padStart(2, '0')}
                </div>
            )}
            {/* rank-1 avatar, when the image host is reachable */}
            <LoadedImage
                src={career.avatar_url}
                className={cn(
                    'absolute object-cover',
                    compact ? 'inset-y-0 right-0 h-full w-1/2 object-top' : 'inset-0 h-full w-full object-top',
                )}
                style={{
                    WebkitMaskImage: compact ? 'linear-gradient(90deg, transparent, #000 45%)' : 'linear-gradient(180deg, #000 55%, transparent 92%)',
                    maskImage: compact ? 'linear-gradient(90deg, transparent, #000 45%)' : 'linear-gradient(180deg, #000 55%, transparent 92%)',
                }}
            />
        </motion.div>
    );
}

function StatGrid({ career, className }: { career: Career; className?: string }) {
    if (!career.stats) return null;
    const tone = themeFor(career.code).tone;
    return (
        <div className={cn('grid grid-cols-2 gap-x-5 gap-y-2.5', className)} aria-hidden>
            {STAT_ROWS.map((s) => (
                <StatBar
                    key={s.key}
                    label={s.short}
                    valueLabel={<span className="tabular-nums text-white">{career.stats![s.key]}</span>}
                    value={career.stats![s.key]}
                    max={STAT_MAX}
                    tone={tone}
                    size="xs"
                />
            ))}
        </div>
    );
}

function PerkBadge({ career }: { career: Career }) {
    if (!career.perk) return null;
    return (
        <Badge tone={themeFor(career.code).tone} shape="tag" icon={<GraduationCap size={12} weight="bold" />}>
            {career.perk.label}
        </Badge>
    );
}

// ─── Radiogroup keyboard handling (shared by desktop + mobile) ──────────────

function useCareerRadios(careers: Career[], selected: string | null, choose: (code: string) => void) {
    const refs = useRef<Array<HTMLElement | null>>([]);
    const [focusIdx, setFocusIdx] = useState(0);
    const selectedIdx = careers.findIndex((c) => c.code === selected);
    const tabStop = selectedIdx >= 0 ? selectedIdx : focusIdx;

    const onKeyDown = (e: KeyboardEvent<HTMLElement>, i: number) => {
        const n = careers.length;
        let next: number | null = null;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = (i + 1) % n;
        else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = (i - 1 + n) % n;
        else if (e.key === 'Home') next = 0;
        else if (e.key === 'End') next = n - 1;
        else if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            choose(careers[i].code);
            return;
        }
        if (next !== null) {
            e.preventDefault();
            setFocusIdx(next);
            refs.current[next]?.focus();
        }
    };

    const radioProps = (career: Career, i: number) => ({
        ref: (el: HTMLElement | null) => {
            refs.current[i] = el;
        },
        role: 'radio' as const,
        'aria-checked': selected === career.code,
        tabIndex: i === tabStop ? 0 : -1,
        'data-career': career.code,
        onKeyDown: (e: KeyboardEvent<HTMLElement>) => onKeyDown(e, i),
        onClick: () => choose(career.code),
    });

    const focusCareer = (code: string | null) => {
        const i = careers.findIndex((c) => c.code === code);
        if (i >= 0) refs.current[i]?.focus();
    };

    return { radioProps, setFocusIdx, focusCareer };
}

// ─── Page ───────────────────────────────────────────────────────────────────

export default function CharacterCreate({ cities, careers }: Props) {
    const isDesktop = useMediaQuery('(min-width: 1024px)');
    const form = useForm({
        display_name: '',
        gender: 'Male' as Gender,
        city_id: (cities[0]?.id ?? null) as number | null,
        career_id: null as number | null,
    });
    const [selected, setSelected] = useState<string | null>(null);

    const choose = (code: string) => {
        const career = careers.find((c) => c.code === code);
        if (!career) return;
        setSelected(code);
        form.setData('career_id', career.id);
        form.clearErrors('career_id');
    };

    const shared = { careers, cities, form, selected, choose };

    return (
        <>
            <Head title="Create Character - TheDirector" />
            {isDesktop ? (
                <DesktopSplit {...shared} back={() => setSelected(null)} />
            ) : (
                <MobileStack {...shared} />
            )}
        </>
    );
}

type FormHandle = ReturnType<typeof useForm<{ display_name: string; gender: Gender; city_id: number | null; career_id: number | null }>>;

interface SharedProps {
    careers: Career[];
    cities: City[];
    form: FormHandle;
    selected: string | null;
    choose: (code: string) => void;
}

function Heading({ lead, accent, className, as: Tag = 'h1', id }: { lead: string; accent: string; className?: string; as?: 'h1' | 'h2'; id?: string }) {
    return (
        <Tag id={id} className={cn('font-black uppercase tracking-tight text-white', className)}>
            {lead} <span className="italic text-cyan-400">{accent}</span>
        </Tag>
    );
}

function LogoutButton() {
    return (
        <Button href={route('logout')} method="post" variant="ghost" size="sm" icon={SignOut}>
            Log out
        </Button>
    );
}

// ─── Desktop: the three-way split ───────────────────────────────────────────

function DesktopSplit({ careers, cities, form, selected, choose, back }: SharedProps & { back: () => void }) {
    const reduce = useReducedMotion();
    const [active, setActive] = useState<string | null>(null);
    const { radioProps, setFocusIdx, focusCareer } = useCareerRadios(careers, selected, choose);
    const headingId = useId();
    const stage: 'split' | 'form' = selected ? 'form' : 'split';
    const selIdx = careers.findIndex((c) => c.code === selected);
    const tabsAfter = selIdx >= 0 ? careers.length - 1 - selIdx : 0;
    const formWidth = useMediaQuery('(min-width: 1280px)') ? 540 : 500;

    const goBack = () => {
        const code = selected;
        back();
        requestAnimationFrame(() => focusCareer(code));
    };

    // Escape returns to the split from anywhere on the form.
    useEffect(() => {
        if (stage !== 'form') return;
        const onKey = (e: globalThis.KeyboardEvent) => {
            if (e.key === 'Escape' && !form.processing) goBack();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [stage, selected, form.processing]);

    const transition = reduce
        ? 'none'
        : ['flex-grow', 'flex-basis', 'clip-path', 'margin-left'].map((p) => `${p} ${DUR * 1000}ms cubic-bezier(${EASE.join(',')})`).join(', ');

    return (
        <div className="relative h-[100dvh] min-h-[640px] w-full overflow-hidden bg-slate-950 text-white">
            {/* Top bar: brand · heading · logout */}
            <div className="pointer-events-none absolute inset-x-0 top-0 z-20 h-44 bg-gradient-to-b from-slate-950/95 via-slate-950/60 to-transparent" />
            <div className="absolute inset-x-0 top-0 z-30 flex items-start justify-between px-8 pt-6">
                <div className={cn(textLabel, 'pt-2 text-slate-300 transition-opacity duration-300', stage === 'form' && 'opacity-0')} aria-hidden={stage === 'form'}>
                    The <span className="text-cyan-400">Director</span>
                </div>
                <motion.div
                    className="pointer-events-none absolute inset-x-0 top-6 text-center"
                    initial={false}
                    animate={{ opacity: stage === 'split' ? 1 : 0, y: stage === 'split' ? 0 : -8 }}
                    transition={{ duration: 0.3, ease: EASE }}
                    aria-hidden={stage !== 'split'}
                >
                    <p className={cn(textLabel, 'text-slate-400')}>New character · Step 1 of 2</p>
                    <Heading id={headingId} lead="Choose your" accent="path" className="mt-2 text-display xl:text-5xl" />
                </motion.div>
                <div className={cn('transition-opacity duration-300', stage === 'split' ? 'opacity-100' : 'pointer-events-none opacity-0')}>
                    <LogoutButton />
                </div>
            </div>

            {/* The split */}
            <div
                role="radiogroup"
                aria-labelledby={headingId}
                className="flex h-full w-full"
                onMouseLeave={() => setActive(null)}
            >
                {careers.map((career, i) => {
                    const theme = themeFor(career.code);
                    const isSel = selected === career.code;
                    const isActive = stage === 'split' ? active === career.code : isSel;
                    const dimmed = stage === 'split' ? active !== null && !isActive : !isSel;
                    const first = i === 0;
                    const last = i === careers.length - 1;
                    const s = stage === 'split' ? DIAG : 0;

                    const grow = stage === 'split' ? (active ? (isActive ? 1.55 : 0.85) : 1) : isSel ? 1 : 0;
                    const basis = stage === 'split' || isSel ? 0 : TAB_W;
                    const clip = `polygon(${first ? 0 : s}px 0, 100% 0, calc(100% - ${last ? 0 : s}px) 100%, 0 100%)`;
                    const summaryId = `career-${career.code}-summary`;

                    return (
                        <div
                            key={career.id}
                            {...radioProps(career, i)}
                            aria-label={career.name}
                            aria-describedby={summaryId}
                            onMouseEnter={() => setActive(career.code)}
                            onFocus={() => {
                                setFocusIdx(i);
                                setActive(career.code);
                            }}
                            className={cn(
                                'group relative h-full min-w-0 cursor-pointer select-none overflow-hidden outline-none focus:outline-none focus-visible:outline-none',
                                isSel && stage === 'form' && 'cursor-default',
                            )}
                            style={{
                                flexGrow: grow,
                                flexShrink: 0,
                                flexBasis: basis,
                                clipPath: clip,
                                WebkitClipPath: clip,
                                marginLeft: first ? 0 : -s,
                                zIndex: i,
                                transition,
                            }}
                        >
                            <span id={summaryId} className="sr-only">
                                {srSummary(career)}
                            </span>

                            <CareerArt
                                career={career}
                                index={i}
                                kenBurns={isActive}
                                muted={stage === 'form' && !isSel}
                                rightInset={stage === 'form' && isSel ? formWidth : 0}
                            />

                            {/* legibility + dimming */}
                            <div className="pointer-events-none absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/55 to-transparent" />
                            <div
                                className={cn(
                                    'pointer-events-none absolute inset-0 bg-slate-950 transition-opacity duration-300 motion-reduce:transition-none',
                                    dimmed ? (stage === 'form' ? 'opacity-70 group-hover:opacity-50' : 'opacity-60') : 'opacity-0',
                                )}
                            />

                            {/* accent edge */}
                            <div
                                className={cn(
                                    'pointer-events-none absolute left-0 right-0 top-0 h-[3px] origin-left transition-transform duration-[450ms] ease-out motion-reduce:transition-none',
                                    isActive ? 'scale-x-100' : 'scale-x-0',
                                )}
                                style={{ background: `linear-gradient(90deg, rgb(${theme.rgb}), rgba(${theme.rgb}, 0.1))` }}
                            />

                            {/* angled divider line */}
                            {!first && (
                                <svg
                                    aria-hidden
                                    className={cn('pointer-events-none absolute inset-y-0 left-0 h-full transition-opacity duration-300', stage === 'split' ? 'opacity-100' : 'opacity-0')}
                                    width={DIAG}
                                    viewBox={`0 0 ${DIAG} 100`}
                                    preserveAspectRatio="none"
                                >
                                    <line x1={DIAG} y1="0" x2="0" y2="100" stroke="rgba(148,163,184,0.35)" strokeWidth="2" vectorEffect="non-scaling-stroke" />
                                </svg>
                            )}

                            {/* Full panel content (split + selected) */}
                            <div
                                className={cn(
                                    'absolute bottom-0 left-0 transition-opacity duration-300 motion-reduce:transition-none',
                                    stage === 'form' && !isSel ? 'pointer-events-none opacity-0' : dimmed ? 'opacity-60' : 'opacity-100',
                                )}
                                style={{ paddingLeft: first ? 40 : 32 }}
                            >
                                <div
                                    className={cn(
                                        'mb-16 w-[320px] rounded-2xl p-4 ring-cyan-400 ring-offset-0 transition-shadow duration-150 group-focus-visible:ring-2',
                                        stage === 'form' && 'group-focus-visible:ring-0',
                                    )}
                                >
                                    <div className={cn(textLabel, 'flex items-center gap-2')} style={{ color: `rgb(${theme.rgb})` }}>
                                        <span className="tabular-nums">{String(i + 1).padStart(2, '0')}</span>
                                        <span aria-hidden className="h-px w-6" style={{ background: `rgba(${theme.rgb}, 0.6)` }} />
                                        <span>Career path</span>
                                    </div>
                                    <div
                                        className={cn(
                                            'mt-2 origin-bottom-left transition-transform duration-[450ms] ease-out motion-reduce:transition-none',
                                            isActive ? 'scale-110' : 'scale-100',
                                        )}
                                    >
                                        <div className="text-4xl font-black uppercase italic leading-none tracking-tight text-white">{career.name}</div>
                                    </div>
                                    <p className={cn('mt-3 text-sm leading-snug text-slate-200 transition-[margin] duration-[450ms]', isActive && 'mt-5')}>
                                        {theme.tagline}
                                    </p>

                                    <div
                                        className={cn(
                                            'grid transition-[grid-template-rows,opacity] duration-[450ms] ease-out motion-reduce:transition-none',
                                            stage === 'form' ? 'grid-rows-[0fr] opacity-0' : 'grid-rows-[1fr] opacity-100',
                                        )}
                                    >
                                        <div className="overflow-hidden">
                                            <p
                                                className={cn(
                                                    'mt-2 line-clamp-2 h-8 text-xs leading-4 text-slate-400 transition-opacity duration-300',
                                                    isActive ? 'opacity-100' : 'opacity-0',
                                                )}
                                            >
                                                {descriptionOf(career)}
                                            </p>
                                            <StatGrid career={career} className="mt-3" />
                                            <div className="mt-4 flex h-5 items-center">
                                                <PerkBadge career={career} />
                                            </div>
                                            <p className="mt-2 h-4 truncate text-xs leading-4 text-slate-400">
                                                {career.rank_name ? `Starts as ${career.rank_name}` : ''}
                                            </p>
                                        </div>
                                    </div>

                                    {stage === 'form' && isSel && career.rank_name && (
                                        <p className="mt-3 text-xs text-slate-400">Starts as {career.rank_name}</p>
                                    )}
                                </div>
                            </div>

                            {/* Collapsed side tab (form stage, not selected) */}
                            <div
                                aria-hidden
                                data-career-tab={career.code}
                                className={cn(
                                    'absolute inset-y-0 left-0 flex flex-col items-center justify-center gap-4 transition-opacity duration-300 motion-reduce:transition-none',
                                    stage === 'form' && !isSel ? 'opacity-100' : 'pointer-events-none opacity-0',
                                )}
                                style={{ width: TAB_W }}
                            >
                                <div className="absolute inset-y-0 left-0 w-[3px]" style={{ background: `rgba(${theme.rgb}, 0.7)` }} />
                                <div className="pointer-events-none absolute inset-2 rounded-xl ring-inset ring-cyan-400 group-focus-visible:ring-2" />
                                <theme.icon size={22} weight="bold" style={{ color: `rgb(${theme.rgb})` }} />
                                <span
                                    className="text-sm font-black uppercase italic tracking-[0.2em] text-slate-200 transition-colors group-hover:text-white"
                                    style={{ writingMode: 'vertical-rl', transform: 'rotate(180deg)' }}
                                >
                                    {career.name}
                                </span>
                                <span className={cn(textLabel, 'text-slate-400 group-hover:text-slate-200')}>Switch</span>
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* Bottom hint (split only) */}
            <div
                className={cn(
                    'pointer-events-none absolute inset-x-0 bottom-0 z-20 flex items-center justify-center gap-6 pb-5 transition-opacity duration-300',
                    stage === 'split' ? 'opacity-100' : 'opacity-0',
                )}
            >
                <span className={cn(textLabel, 'rounded-full border border-slate-800 bg-slate-950/80 px-4 py-2 text-slate-400 backdrop-blur')}>
                    Hover to preview · Click or Enter to choose · ← → to browse
                </span>
            </div>

            {/* Step 2: the form, sliding in over the right of the chosen panel */}
            <AnimatePresence>
                {stage === 'form' && (
                    <motion.aside
                        key="form"
                        aria-label="Create your character"
                        className="absolute inset-y-0 right-0 z-30"
                        style={{ width: formWidth }}
                        initial={{ opacity: 0, x: -tabsAfter * TAB_W + 48 }}
                        animate={{ opacity: 1, x: -tabsAfter * TAB_W }}
                        exit={{ opacity: 0, x: -tabsAfter * TAB_W + 48, transition: { duration: 0.25, ease: EASE } }}
                        transition={{ duration: DUR, ease: EASE }}
                    >
                        <FormPanel careers={careers} cities={cities} form={form} selected={selected} onBack={goBack} variant="overlay" />
                    </motion.aside>
                )}
            </AnimatePresence>
        </div>
    );
}

// ─── Mobile: stacked accordion bands, then the form ─────────────────────────

const BAND_IDLE = 172;
const BAND_COLLAPSED = 88;

function MobileStack({ careers, cities, form, selected, choose }: SharedProps) {
    const reduce = useReducedMotion();
    const { radioProps, setFocusIdx } = useCareerRadios(careers, selected, choose);
    const headingId = useId();

    return (
        <div className="min-h-[100dvh] bg-slate-950 text-white">
            <header className="px-4 pb-5 pt-4">
                <div className="flex items-center justify-between gap-3">
                    <p className={cn(textLabel, 'text-slate-400')}>
                        The <span className="text-cyan-400">Director</span> · New character
                    </p>
                    <div className="-mr-3 shrink-0">
                        <LogoutButton />
                    </div>
                </div>
                <Heading id={headingId} lead="Choose your" accent="path" className="mt-2 text-display" />
                <p className="mt-1 text-sm text-slate-400">Tap a career to see how you start.</p>
            </header>

            <div role="radiogroup" aria-labelledby={headingId} className="flex flex-col gap-px bg-slate-800">
                {careers.map((career, i) => {
                    const theme = themeFor(career.code);
                    const isSel = selected === career.code;
                    const height = selected ? (isSel ? 'auto' : BAND_COLLAPSED) : BAND_IDLE;
                    const summaryId = `m-career-${career.code}-summary`;
                    return (
                        <motion.div
                            key={career.id}
                            {...radioProps(career, i)}
                            aria-label={career.name}
                            aria-describedby={summaryId}
                            onFocus={() => setFocusIdx(i)}
                            initial={false}
                            animate={{ height }}
                            transition={{ duration: reduce ? 0 : DUR, ease: EASE }}
                            className="group relative min-h-[44px] cursor-pointer select-none overflow-hidden bg-slate-950 outline-none focus:outline-none focus-visible:outline-none"
                        >
                            <span id={summaryId} className="sr-only">
                                {srSummary(career)}
                            </span>
                            <CareerArt career={career} index={i} kenBurns={isSel} compact />
                            <div className="pointer-events-none absolute inset-0 bg-gradient-to-r from-slate-950 via-slate-950/75 to-slate-950/10" />
                            <div
                                className={cn(
                                    'pointer-events-none absolute inset-0 bg-slate-950 transition-opacity duration-300',
                                    selected && !isSel ? 'opacity-50' : 'opacity-0',
                                )}
                            />
                            <div className="absolute inset-y-0 left-0 w-[3px]" style={{ background: `rgba(${theme.rgb}, ${isSel ? 1 : 0.55})` }} />
                            <div className="pointer-events-none absolute inset-0 z-10 ring-inset ring-cyan-400 group-focus-visible:ring-2" />

                            <div className="relative flex h-full flex-col px-5 py-5">
                                <div className="flex items-center justify-between gap-3">
                                    <div className={cn(textLabel, 'flex items-center gap-2')} style={{ color: `rgb(${theme.rgb})` }}>
                                        <span className="tabular-nums">{String(i + 1).padStart(2, '0')}</span>
                                        <span aria-hidden className="h-px w-5" style={{ background: `rgba(${theme.rgb}, 0.6)` }} />
                                        <theme.icon size={14} weight="bold" />
                                    </div>
                                    {isSel && (
                                        <span className={cn(textLabel, 'flex items-center gap-1 text-cyan-300')}>
                                            <Check size={12} weight="bold" /> Selected
                                        </span>
                                    )}
                                </div>
                                <div className="mt-1.5 text-3xl font-black uppercase italic leading-none tracking-tight text-white">{career.name}</div>
                                <p className={cn('mt-2 text-sm leading-snug text-slate-200', selected && !isSel && 'truncate opacity-0')}>{theme.tagline}</p>

                                <AnimatePresence initial={false}>
                                    {isSel && (
                                        <motion.div
                                            key="details"
                                            initial={{ opacity: 0 }}
                                            animate={{ opacity: 1, transition: { duration: 0.3, delay: reduce ? 0 : 0.12 } }}
                                            exit={{ opacity: 0, transition: { duration: 0.12 } }}
                                            className="pb-1 pt-4"
                                        >
                                            {descriptionOf(career) && <p className="mb-3 line-clamp-2 text-xs text-slate-400">{descriptionOf(career)}</p>}
                                            <StatGrid career={career} />
                                            <div className="mt-4 flex flex-wrap items-center gap-2">
                                                <PerkBadge career={career} />
                                                {career.rank_name && <span className="text-xs text-slate-400">Starts as {career.rank_name}</span>}
                                            </div>
                                        </motion.div>
                                    )}
                                </AnimatePresence>
                            </div>
                        </motion.div>
                    );
                })}
            </div>

            <AnimatePresence initial={false}>
                {selected ? (
                    <motion.section
                        key="form"
                        aria-label="Create your character"
                        initial={{ opacity: 0, y: 24 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0 }}
                        transition={{ duration: DUR, ease: EASE }}
                    >
                        <FormPanel careers={careers} cities={cities} form={form} selected={selected} variant="inline" />
                    </motion.section>
                ) : (
                    <motion.p
                        key="hint"
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        className={cn(textLabel, 'px-4 py-6 text-center text-slate-400')}
                    >
                        Choose a career to continue
                    </motion.p>
                )}
            </AnimatePresence>
            <Footer />
        </div>
    );
}

function Footer() {
    return (
        <p className={cn(textLabel, 'px-4 pb-8 pt-2 text-center text-slate-400')}>
            &copy; {new Date().getFullYear()} TheDirector · Beta gameplay
        </p>
    );
}

// ─── Step 2: the form ───────────────────────────────────────────────────────

function FormPanel({
    careers,
    cities,
    form,
    selected,
    onBack,
    variant,
}: {
    careers: Career[];
    cities: City[];
    form: FormHandle;
    selected: string | null;
    onBack?: () => void;
    variant: 'overlay' | 'inline';
}) {
    const page = usePage<PageProps>();
    const flash = page.props.flash;
    const pageErrors = (page.props.errors ?? {}) as Record<string, string>;
    const { data, setData, post, processing, errors } = form;
    const career = careers.find((c) => c.code === selected) ?? null;
    const city = cities.find((c) => c.id === data.city_id) ?? null;
    const nameRef = useRef<HTMLInputElement>(null);
    const titleId = useId();

    const generalErrors = [flash?.error, pageErrors.error, errors.career_id, errors.gender].filter(Boolean) as string[];

    // Move focus into the form when it opens (desktop overlay only — on mobile it would yank the scroll).
    useEffect(() => {
        if (variant !== 'overlay') return;
        const t = window.setTimeout(() => nameRef.current?.focus({ preventScroll: true }), 120);
        return () => window.clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('character.store'), {
            preserveState: true,
            preserveScroll: true,
            onError: (errs) => {
                if (errs.display_name) nameRef.current?.focus();
            },
        });
    };

    const name = data.display_name;
    const badChars = Array.from(new Set(name.replace(/[A-Za-z0-9 ]/g, '').split(''))).filter(Boolean);
    const cleaned = previewName(name);
    const nameHint: ReactNode = badChars.length ? (
        <span className="text-amber-300">
            Letters, numbers and spaces only — remove{' '}
            {badChars.map((ch) => (
                <kbd key={ch} className="mr-1 rounded bg-slate-800 px-1 font-mono text-amber-200">
                    {ch}
                </kbd>
            ))}
        </span>
    ) : name.trim().length > 0 && cleaned.length < 3 ? (
        <span className="text-amber-300">At least 3 characters.</span>
    ) : cleaned && cleaned !== name ? (
        <>
            You&rsquo;ll appear as <span className="font-bold text-white">{cleaned}</span>
        </>
    ) : (
        '3–15 characters. Letters, numbers and spaces.'
    );

    const overlay = variant === 'overlay';
    const backdrop = cityImage(city);

    return (
        <div
            className={cn(
                'relative isolate',
                overlay
                    ? 'flex h-full flex-col overflow-y-auto border-l border-white/10 bg-slate-950/85 shadow-[-30px_0_60px_rgba(2,6,23,0.55)] backdrop-blur-xl'
                    : 'border-t border-slate-800 bg-slate-950',
            )}
        >
            {/* city photo backdrop, when it loads */}
            <div aria-hidden className="pointer-events-none absolute inset-x-0 top-0 -z-10 h-72 overflow-hidden">
                <LoadedImage
                    key={backdrop ?? 'none'}
                    src={backdrop}
                    className="h-full w-full object-cover opacity-25"
                    style={{ WebkitMaskImage: 'linear-gradient(180deg, #000, transparent)', maskImage: 'linear-gradient(180deg, #000, transparent)' }}
                />
            </div>

            <form onSubmit={submit} noValidate aria-labelledby={titleId} className={cn('flex flex-col', overlay ? 'my-auto px-10 py-8' : 'px-4 pb-8 pt-7')}>
                <div className="flex min-h-11 items-center justify-between gap-3">
                    {onBack ? (
                        <Button variant="ghost" size="sm" icon={ArrowLeft} onClick={onBack} data-action="back" className="-ml-3 h-11" disabled={processing}>
                            All paths
                        </Button>
                    ) : (
                        <span />
                    )}
                    <span className={cn(textLabel, 'text-slate-400')}>Step 2 of 2</span>
                </div>

                <Heading as="h2" id={titleId} lead="Create your" accent="character" className={cn('mt-3', overlay ? 'text-display' : 'text-3xl')} />

                {career && <CareerSummary career={career} />}

                {generalErrors.length > 0 && (
                    <div role="alert" className="mt-5 flex gap-3 rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                        <Warning size={18} weight="bold" className="mt-0.5 shrink-0" />
                        <div className="space-y-1">
                            {generalErrors.map((m, i) => (
                                <p key={i}>{m}</p>
                            ))}
                        </div>
                    </div>
                )}

                <div className="mt-6 space-y-6">
                    <Field
                        label="Name"
                        required
                        error={errors.display_name}
                        hint={nameHint}
                        aside={
                            <span className={cn('tabular-nums', name.length >= NAME_MAX ? 'text-amber-300' : 'text-slate-400')} aria-live="polite">
                                {name.length}/{NAME_MAX}
                            </span>
                        }
                    >
                        <Input
                            ref={nameRef}
                            name="display_name"
                            size="lg"
                            value={name}
                            onChange={(e) => setData('display_name', e.target.value)}
                            maxLength={NAME_MAX}
                            autoComplete="off"
                            autoCapitalize="words"
                            spellCheck={false}
                            placeholder="Your character's name"
                            disabled={processing}
                            invalid={Boolean(errors.display_name) || (badChars.length > 0 && !NAME_ALLOWED.test(name))}
                        />
                    </Field>

                    <Segmented
                        legend="Gender"
                        name="gender"
                        value={data.gender}
                        onChange={(v) => setData('gender', v as Gender)}
                        disabled={processing}
                        options={[
                            { value: 'Male', label: 'Male', icon: GenderMale },
                            { value: 'Female', label: 'Female', icon: GenderFemale },
                        ]}
                    />

                    <CityPicker cities={cities} value={data.city_id} onChange={(id) => setData('city_id', id)} disabled={processing} error={errors.city_id} />
                </div>

                <div className="pt-8">
                    <Button type="submit" size="lg" fullWidth loading={processing} iconRight={ArrowRight} className="h-14 text-sm">
                        Begin
                    </Button>
                    {career && (
                        <p className="mt-3 text-center text-xs text-slate-400">
                            Starting as {career.rank_name ?? career.name}
                            {city ? ` in ${city.name}` : ''}.
                        </p>
                    )}
                </div>
            </form>
        </div>
    );
}

function CareerSummary({ career }: { career: Career }) {
    const theme = themeFor(career.code);
    return (
        <div
            className="relative mt-5 overflow-hidden rounded-2xl border bg-slate-900/70 p-4"
            style={{ borderColor: `rgba(${theme.rgb}, 0.35)`, boxShadow: `inset 3px 0 0 rgb(${theme.rgb})` }}
        >
            <div className="flex items-center gap-3">
                <div
                    className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl"
                    style={{ background: `rgba(${theme.rgb}, 0.12)`, color: `rgb(${theme.rgb})` }}
                >
                    <theme.icon size={22} weight="bold" />
                </div>
                <div className="min-w-0 flex-1">
                    <p className={cn(textLabel, 'text-slate-400')}>Career path</p>
                    <p className="truncate text-xl font-black uppercase italic leading-tight tracking-tight text-white">{career.name}</p>
                </div>
            </div>
            {(career.perk || career.rank_name) && (
                <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2">
                    <PerkBadge career={career} />
                    {career.rank_name && <span className="text-xs text-slate-400">Starts as {career.rank_name}</span>}
                </div>
            )}
            {career.stats && (
                <dl className="mt-4 grid grid-cols-4 gap-2">
                    {STAT_ROWS.map((s) => (
                        <div key={s.key} className="rounded-lg bg-slate-950/60 px-2 py-1.5 text-center">
                            <dt className={cn(textLabel, 'text-slate-400')}>
                                <abbr title={s.long} className="no-underline">
                                    {s.short}
                                </abbr>
                            </dt>
                            <dd className="text-lg font-black tabular-nums leading-tight" style={{ color: `rgb(${theme.rgb})` }}>
                                {career.stats![s.key]}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
        </div>
    );
}

function Segmented({
    legend,
    name,
    value,
    onChange,
    options,
    disabled,
}: {
    legend: string;
    name: string;
    value: string;
    onChange: (v: string) => void;
    options: Array<{ value: string; label: string; icon?: Icon }>;
    disabled?: boolean;
}) {
    return (
        <fieldset disabled={disabled}>
            <legend className={cn(textLabel, 'mb-1.5 text-slate-400')}>{legend}</legend>
            <div className="grid grid-cols-2 gap-1 rounded-xl border border-slate-700 bg-slate-950/60 p-1">
                {options.map((o) => {
                    const checked = value === o.value;
                    return (
                        <label key={o.value} className="relative cursor-pointer">
                            <input
                                type="radio"
                                name={name}
                                value={o.value}
                                checked={checked}
                                onChange={() => onChange(o.value)}
                                className="peer sr-only"
                            />
                            <span
                                className={cn(
                                    'flex h-11 items-center justify-center gap-2 rounded-lg text-xs font-extrabold uppercase tracking-[0.16em] transition-colors duration-150',
                                    'peer-focus-visible:ring-2 peer-focus-visible:ring-cyan-400',
                                    checked ? 'bg-white text-slate-950' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white',
                                )}
                            >
                                {o.icon && <o.icon size={16} weight="bold" />}
                                {o.label}
                            </span>
                        </label>
                    );
                })}
            </div>
        </fieldset>
    );
}

const CITY_HUES = ['34, 211, 238', '244, 114, 182', '251, 191, 36', '129, 140, 248'];

function CityPicker({
    cities,
    value,
    onChange,
    disabled,
    error,
}: {
    cities: City[];
    value: number | null;
    onChange: (id: number) => void;
    disabled?: boolean;
    error?: string;
}) {
    const selected = cities.find((c) => c.id === value);
    const errorId = useId();
    return (
        <fieldset disabled={disabled} aria-describedby={error ? errorId : undefined}>
            <legend className={cn(textLabel, 'mb-1.5 text-slate-400')}>Home city</legend>
            <div className="grid grid-cols-3 gap-2">
                {cities.map((city, i) => {
                    const checked = city.id === value;
                    const hue = CITY_HUES[i % CITY_HUES.length];
                    return (
                        <label key={city.id} className="relative block cursor-pointer">
                            <input
                                type="radio"
                                name="city_id"
                                value={city.id}
                                checked={checked}
                                onChange={() => onChange(city.id)}
                                className="peer sr-only"
                            />
                            <span
                                className={cn(
                                    'relative flex h-24 flex-col justify-end overflow-hidden rounded-xl border p-2.5 transition-[border-color,box-shadow] duration-150',
                                    'peer-focus-visible:ring-2 peer-focus-visible:ring-cyan-400 peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-slate-950',
                                    checked ? 'border-cyan-400 shadow-[0_0_0_1px_rgba(34,211,238,0.6),0_10px_30px_-10px_rgba(34,211,238,0.45)]' : 'border-slate-700 hover:border-slate-500',
                                )}
                            >
                                <span
                                    aria-hidden
                                    className="absolute inset-0"
                                    style={{
                                        background: `radial-gradient(120% 90% at 80% 0%, rgba(${hue}, 0.28), transparent 60%), linear-gradient(160deg, #0f172a, #020617)`,
                                    }}
                                />
                                <MapPin aria-hidden size={56} weight="thin" className="absolute -right-2 -top-2 opacity-25" style={{ color: `rgb(${hue})` }} />
                                <LoadedImage src={cityImage(city)} className="absolute inset-0 h-full w-full object-cover" />
                                <span aria-hidden className="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-950/30 to-transparent" />
                                {checked && (
                                    <span aria-hidden className="absolute right-2 top-2 flex h-5 w-5 items-center justify-center rounded-full bg-cyan-400 text-slate-950">
                                        <Check size={12} weight="bold" />
                                    </span>
                                )}
                                <span className={cn('relative text-sm font-black uppercase leading-tight tracking-tight', checked ? 'text-white' : 'text-slate-200')}>
                                    {city.name}
                                </span>
                            </span>
                        </label>
                    );
                })}
            </div>
            {error ? (
                <p id={errorId} className="mt-1.5 text-xs text-red-400">
                    {error}
                </p>
            ) : selected?.description ? (
                <p className="mt-1.5 text-xs text-slate-400">{selected.description}</p>
            ) : (
                <p className="mt-1.5 text-xs text-slate-400">Your home city. You can travel to the others later.</p>
            )}
        </fieldset>
    );
}
