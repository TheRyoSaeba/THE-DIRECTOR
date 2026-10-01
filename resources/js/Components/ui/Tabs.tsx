import { router } from '@inertiajs/react';
import { motion, useReducedMotion } from 'framer-motion';
import {
    isValidElement,
    useCallback,
    useEffect,
    useId,
    useRef,
    useState,
    type ComponentType,
    type KeyboardEvent,
    type ReactNode,
} from 'react';
import { cn, focusRingInset } from './styles';

export interface TabItem<T extends string = string> {
    id: T;
    label: ReactNode;
    icon?: ComponentType<{ size?: number | string; weight?: any; className?: string }> | ReactNode;
    /** Shown as a small counter; hidden when 0/undefined. */
    count?: number;
    disabled?: boolean;
}

export interface TabsProps<T extends string = string> {
    items: TabItem<T>[];
    value: T;
    onChange: (id: T) => void;
    variant?: 'underline' | 'pill';
    /** Accessible name for the tablist. */
    'aria-label'?: string;
    /** Prefix for tab/panel ids so <TabPanel> can link up. Defaults to an auto id. */
    idBase?: string;
    /** Stretch tabs to fill the row (underline variant on wide layouts). */
    fill?: boolean;
    className?: string;
}

export const tabId = (base: string, id: string) => `${base}-tab-${id}`;
export const panelId = (base: string, id: string) => `${base}-panel-${id}`;

function renderTabIcon(icon: TabItem['icon']) {
    if (!icon) return null;
    if (isValidElement(icon)) return icon;
    if (typeof icon === 'function' || typeof icon === 'object') {
        const Icon = icon as ComponentType<{ size?: number; weight?: any; className?: string }>;
        return <Icon size={14} weight="bold" className="shrink-0" />;
    }
    return null;
}

/**
 * Tab strip. Controlled (`value`/`onChange`); pair with `useTabState` for ?tab= URL sync.
 * Scrolls horizontally on narrow screens and keeps the active tab in view.
 * Replaces Law/Police `TabBar` and the per-page tab button rows.
 */
export function Tabs<T extends string = string>({
    items,
    value,
    onChange,
    variant = 'underline',
    'aria-label': ariaLabel,
    idBase,
    fill = false,
    className,
}: TabsProps<T>) {
    const autoId = useId();
    const base = idBase ?? autoId;
    const reduce = useReducedMotion();
    const listRef = useRef<HTMLDivElement>(null);

    // Keep the active tab visible inside the horizontal scroller.
    useEffect(() => {
        const el = listRef.current?.querySelector<HTMLElement>('[aria-selected="true"]');
        el?.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: reduce ? 'auto' : 'smooth' });
    }, [value, reduce]);

    const enabled = items.filter((i) => !i.disabled);

    const onKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
        const idx = enabled.findIndex((i) => i.id === value);
        let next: TabItem<T> | undefined;
        if (e.key === 'ArrowRight') next = enabled[(idx + 1) % enabled.length];
        else if (e.key === 'ArrowLeft') next = enabled[(idx - 1 + enabled.length) % enabled.length];
        else if (e.key === 'Home') next = enabled[0];
        else if (e.key === 'End') next = enabled[enabled.length - 1];
        if (!next) return;
        e.preventDefault();
        onChange(next.id);
        requestAnimationFrame(() => document.getElementById(tabId(base, next!.id))?.focus());
    };

    const isPill = variant === 'pill';

    return (
        <div
            ref={listRef}
            role="tablist"
            aria-label={ariaLabel}
            onKeyDown={onKeyDown}
            className={cn(
                'flex overflow-x-auto overscroll-x-contain [scrollbar-width:none] [&::-webkit-scrollbar]:hidden',
                isPill ? 'gap-1 rounded-xl border border-slate-800 bg-slate-950/60 p-1' : 'border-b border-slate-800',
                className,
            )}
        >
            {items.map((item) => {
                const active = item.id === value;
                return (
                    <button
                        key={item.id}
                        id={tabId(base, item.id)}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        aria-controls={panelId(base, item.id)}
                        tabIndex={active ? 0 : -1}
                        disabled={item.disabled}
                        onClick={() => onChange(item.id)}
                        className={cn(
                            'relative flex shrink-0 items-center justify-center gap-2 whitespace-nowrap text-[11px] font-extrabold uppercase tracking-[0.16em] transition-colors duration-150 disabled:cursor-not-allowed disabled:opacity-40',
                            fill && 'flex-1',
                            isPill
                                ? cn(
                                      'h-9 rounded-lg px-3',
                                      active ? 'bg-slate-800 text-white' : 'text-slate-400 hover:text-slate-100',
                                  )
                                : cn('h-11 px-4', active ? 'text-cyan-400' : 'text-slate-400 hover:text-slate-100'),
                            focusRingInset,
                        )}
                    >
                        {renderTabIcon(item.icon)}
                        {item.label}
                        {item.count ? (
                            <span
                                className={cn(
                                    'rounded-full border px-1.5 py-0.5 text-[10px] leading-none tabular-nums',
                                    active
                                        ? 'border-cyan-500/30 bg-cyan-500/20 text-cyan-300'
                                        : 'border-slate-700 bg-slate-800 text-slate-300',
                                )}
                            >
                                {item.count}
                            </span>
                        ) : null}
                        {!isPill &&
                            active &&
                            (reduce ? (
                                <span aria-hidden className="absolute inset-x-0 bottom-0 h-0.5 bg-cyan-400" />
                            ) : (
                                <motion.span
                                    aria-hidden
                                    layoutId={`${base}-underline`}
                                    transition={{ duration: 0.15, ease: 'easeOut' }}
                                    className="absolute inset-x-0 bottom-0 h-0.5 bg-cyan-400 shadow-[0_0_6px_rgba(34,211,238,0.5)]"
                                />
                            ))}
                    </button>
                );
            })}
        </div>
    );
}

export interface TabPanelProps {
    /** Same idBase passed to <Tabs>. */
    idBase: string;
    id: string;
    className?: string;
    children: ReactNode;
}

/** Optional: wires role="tabpanel" + aria-labelledby to the matching tab. */
export function TabPanel({ idBase, id, className, children }: TabPanelProps) {
    return (
        <div role="tabpanel" id={panelId(idBase, id)} aria-labelledby={tabId(idBase, id)} tabIndex={0} className={cn('outline-none', className)}>
            {children}
        </div>
    );
}

export interface UseTabStateOptions<T extends string> {
    defaultTab?: T;
    /** Query-string key. Default "tab". */
    param?: string;
    /** Mirror the active tab into the URL (history.replace, no server round trip). Default true. */
    sync?: boolean;
}

/**
 * Tab state that is initialised from `?tab=` and written back with
 * `router.replace` (client-side only — no request, page state and scroll preserved).
 * The default tab is removed from the URL to keep links clean.
 */
export function useTabState<T extends string>(
    ids: readonly T[],
    { defaultTab, param = 'tab', sync = true }: UseTabStateOptions<T> = {},
): [T, (id: T) => void] {
    const fallback = (defaultTab ?? ids[0]) as T;

    const [tab, setTabState] = useState<T>(() => {
        if (typeof window === 'undefined') return fallback;
        const fromUrl = new URLSearchParams(window.location.search).get(param);
        return fromUrl && (ids as readonly string[]).includes(fromUrl) ? (fromUrl as T) : fallback;
    });

    const setTab = useCallback(
        (id: T) => {
            setTabState(id);
            if (!sync || typeof window === 'undefined') return;
            const url = new URL(window.location.href);
            if (id === fallback) url.searchParams.delete(param);
            else url.searchParams.set(param, id);
            const next = url.pathname + url.search + url.hash;
            if (next === window.location.pathname + window.location.search + window.location.hash) return;
            router.replace({ url: next, preserveScroll: true, preserveState: true });
        },
        [fallback, param, sync],
    );

    return [tab, setTab];
}

export default Tabs;
