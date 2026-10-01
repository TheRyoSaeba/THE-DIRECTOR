import { router, usePage } from '@inertiajs/react';
import { CheckCircle, Info, Warning, WarningCircle, X } from '@phosphor-icons/react';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import { useEffect, useRef, useSyncExternalStore, type ReactNode } from 'react';
import { cn, focusRing, zToast } from './styles';

/* ──────────────────────────────────────────────────────────────────────────
 * Store
 *
 * Module-level (not React state) so that:
 *  - toasts survive GameLayout re-mounting on Inertia page swaps,
 *  - `toast.success()` can be called from anywhere, including router callbacks,
 *  - auto-dismiss timers keep running between page renders.
 * ────────────────────────────────────────────────────────────────────────── */

export type ToastTone = 'success' | 'error' | 'warning' | 'info';

export interface ToastItem {
    id: number;
    tone: ToastTone;
    title?: ReactNode;
    message: ReactNode;
    /** ms; 0 = sticky until dismissed. */
    duration: number;
    /** How many times an identical toast was raised while visible (shown as ×N). */
    count: number;
    /** Dedupe key for identical string messages. */
    key?: string;
}

export interface ToastOptions {
    title?: ReactNode;
    duration?: number;
}

const DEFAULT_DURATION: Record<ToastTone, number> = {
    success: 4000,
    info: 4000,
    warning: 5000,
    error: 6000,
};
const MAX_VISIBLE = 4;

let toasts: ToastItem[] = [];
let nextId = 1;
const listeners = new Set<() => void>();
const timers = new Map<number, { handle: number | undefined; remaining: number; startedAt: number }>();

function emit() {
    listeners.forEach((l) => l());
}

function startTimer(id: number, ms: number) {
    if (ms <= 0) return;
    const handle = window.setTimeout(() => dismiss(id), ms);
    timers.set(id, { handle, remaining: ms, startedAt: Date.now() });
}

function dismiss(id?: number) {
    const ids = id === undefined ? toasts.map((t) => t.id) : [id];
    ids.forEach((i) => {
        const t = timers.get(i);
        if (t) window.clearTimeout(t.handle);
        timers.delete(i);
    });
    toasts = id === undefined ? [] : toasts.filter((t) => t.id !== id);
    emit();
}

function push(tone: ToastTone, message: ReactNode, opts: ToastOptions = {}): number {
    const duration = opts.duration ?? DEFAULT_DURATION[tone];
    const key = typeof message === 'string' ? `${tone}\u0000${message}` : undefined;

    // Same text already on screen → bump it (×2) and restart its timer instead of stacking.
    const existing = key ? toasts.find((t) => t.key === key) : undefined;
    if (existing) {
        const t = timers.get(existing.id);
        if (t) window.clearTimeout(t.handle);
        timers.delete(existing.id);
        toasts = toasts.map((t) => (t.id === existing.id ? { ...t, count: t.count + 1 } : t));
        startTimer(existing.id, duration);
        emit();
        return existing.id;
    }

    const id = nextId++;
    toasts = [...toasts, { id, tone, message, title: opts.title, duration, count: 1, key }];
    // Drop the oldest when over the cap.
    while (toasts.length > MAX_VISIBLE) {
        const oldest = toasts[0];
        const t = timers.get(oldest.id);
        if (t) window.clearTimeout(t.handle);
        timers.delete(oldest.id);
        toasts = toasts.slice(1);
    }
    startTimer(id, duration);
    emit();
    return id;
}

function pause(id: number) {
    const t = timers.get(id);
    if (!t || t.handle === undefined) return;
    window.clearTimeout(t.handle);
    timers.set(id, { handle: undefined, remaining: Math.max(500, t.remaining - (Date.now() - t.startedAt)), startedAt: 0 });
}

function resume(id: number) {
    const t = timers.get(id);
    if (!t || t.handle !== undefined) return;
    startTimer(id, t.remaining);
}

type ToastFn = ((message: ReactNode, opts?: ToastOptions & { tone?: ToastTone }) => number) & {
    success: (message: ReactNode, opts?: ToastOptions) => number;
    error: (message: ReactNode, opts?: ToastOptions) => number;
    warning: (message: ReactNode, opts?: ToastOptions) => number;
    info: (message: ReactNode, opts?: ToastOptions) => number;
    dismiss: (id?: number) => void;
};

/** Imperative API — usable anywhere (components, router callbacks, utils). */
export const toast: ToastFn = Object.assign(
    (message: ReactNode, opts: ToastOptions & { tone?: ToastTone } = {}) => push(opts.tone ?? 'info', message, opts),
    {
        success: (m: ReactNode, o?: ToastOptions) => push('success', m, o),
        error: (m: ReactNode, o?: ToastOptions) => push('error', m, o),
        warning: (m: ReactNode, o?: ToastOptions) => push('warning', m, o),
        info: (m: ReactNode, o?: ToastOptions) => push('info', m, o),
        dismiss,
    },
);

function subscribe(l: () => void) {
    listeners.add(l);
    return () => listeners.delete(l);
}
const getSnapshot = () => toasts;
const getServerSnapshot = () => [] as ToastItem[];

/** Returns the stable `toast` API. (No context needed — the store is global.) */
export function useToast(): ToastFn {
    return toast;
}

/**
 * Convenience wrapper: renders children plus a <Toaster/>.
 * Optional — you can mount <Toaster/> directly in the layout instead.
 */
export function ToastProvider({ children, toaster = true }: { children?: ReactNode; toaster?: boolean }) {
    return (
        <>
            {children}
            {toaster && <Toaster />}
        </>
    );
}

/* ──────────────────────────────────────────────────────────────────────────
 * Toaster (view)
 * ────────────────────────────────────────────────────────────────────────── */

const toneStyle: Record<ToastTone, { icon: typeof CheckCircle; iconCls: string; border: string; bar: string }> = {
    success: { icon: CheckCircle, iconCls: 'text-emerald-400', border: 'border-emerald-500/30', bar: 'bg-emerald-400' },
    error: { icon: WarningCircle, iconCls: 'text-red-400', border: 'border-red-500/40', bar: 'bg-red-500' },
    warning: { icon: Warning, iconCls: 'text-amber-400', border: 'border-amber-500/30', bar: 'bg-amber-400' },
    info: { icon: Info, iconCls: 'text-cyan-400', border: 'border-cyan-500/30', bar: 'bg-cyan-400' },
};

/**
 * Fixed toast stack. Top-right under the header on desktop (≥ `nav` breakpoint),
 * bottom-centre above the 56px mobile tab bar below it. aria-live="polite".
 */
export function Toaster({ className }: { className?: string }) {
    const items = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
    const reduce = useReducedMotion();

    return (
        <div
            data-ui-layer="toast"
            role="region"
            aria-label="Notifications"
            className={cn(
                'pointer-events-none fixed flex flex-col gap-2',
                // mobile: above the bottom tab bar (h-14) + safe area
                'inset-x-3 bottom-[calc(3.5rem+env(safe-area-inset-bottom)+0.75rem)] items-stretch',
                // desktop: top-right under the 48px header
                'nav:inset-x-auto nav:bottom-auto nav:right-4 nav:top-16 nav:w-[22rem]',
                zToast,
                className,
            )}
        >
            <div aria-live="polite" aria-relevant="additions" className="flex w-full flex-col gap-2">
                <AnimatePresence initial={false}>
                    {items.map((t) => {
                        const s = toneStyle[t.tone];
                        const Icon = s.icon;
                        return (
                            <motion.div
                                key={t.id}
                                layout={!reduce}
                                initial={reduce ? { opacity: 0 } : { opacity: 0, y: 8, scale: 0.98 }}
                                animate={reduce ? { opacity: 1 } : { opacity: 1, y: 0, scale: 1 }}
                                exit={reduce ? { opacity: 0 } : { opacity: 0, scale: 0.98 }}
                                transition={{ duration: reduce ? 0 : 0.15, ease: 'easeOut' }}
                                role={t.tone === 'error' ? 'alert' : 'status'}
                                onPointerEnter={() => pause(t.id)}
                                onPointerLeave={() => resume(t.id)}
                                onFocus={() => pause(t.id)}
                                onBlur={() => resume(t.id)}
                                className={cn(
                                    'pointer-events-auto relative flex w-full items-start gap-3 overflow-hidden rounded-xl border bg-slate-900/95 py-3 pl-4 pr-2 shadow-[0_12px_30px_rgba(2,6,23,0.45)] backdrop-blur',
                                    s.border,
                                )}
                            >
                                <span aria-hidden className={cn('absolute inset-y-0 left-0 w-1', s.bar)} />
                                <Icon size={18} weight="fill" className={cn('mt-0.5 shrink-0', s.iconCls)} aria-hidden />
                                <div className="min-w-0 flex-1 text-sm text-slate-100">
                                    {t.title && <p className="font-bold text-white">{t.title}</p>}
                                    <div className="break-words">{t.message}</div>
                                </div>
                                {t.count > 1 && (
                                    <span className="mt-0.5 shrink-0 rounded-full bg-slate-800 px-1.5 py-0.5 text-[10px] font-extrabold tabular-nums text-slate-300">
                                        ×{t.count}
                                    </span>
                                )}
                                <button
                                    type="button"
                                    onClick={() => dismiss(t.id)}
                                    aria-label="Dismiss notification"
                                    className={cn(
                                        '-my-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-slate-400 transition-colors duration-150 hover:bg-slate-800 hover:text-white',
                                        focusRing,
                                    )}
                                >
                                    <X size={14} weight="bold" />
                                </button>
                            </motion.div>
                        );
                    })}
                </AnimatePresence>
            </div>
        </div>
    );
}

/* ──────────────────────────────────────────────────────────────────────────
 * Inertia flash → toast bridge
 *
 * The server shares `flash` as an Inertia::always() prop, so the same
 * {success,error,warning} can ride along on several responses (e.g. a
 * controller that flashes and renders without redirecting keeps it in the
 * session for one more request, which a poll/partial reload then picks up),
 * and the page object is re-created on every partial reload, back/forward
 * restore and layout re-mount. Keying on page/props identity alone would
 * either repeat toasts or swallow legitimate repeats.
 *
 * Strategy:
 *  - Only look at flash when a *server response is applied* — Inertia's
 *    `beforeUpdate` event fires exactly once per applied response and NOT for
 *    history restores or prefetch-cache population. (Plus the very first page
 *    on first mount, which never gets a beforeUpdate.)
 *  - Every visit that represents a new user intent bumps an "epoch":
 *    any non-GET visit, or a full (non-partial, non-prefetch) GET.
 *    Partial reloads (only/except) and prefetches do not.
 *  - Within an epoch each (kind, message) toasts at most once. A new epoch
 *    clears that set, so the same message after a NEW action shows again.
 * ────────────────────────────────────────────────────────────────────────── */

export type FlashKind = 'success' | 'error' | 'warning';
const FLASH_KINDS: FlashKind[] = ['success', 'warning', 'error'];

export interface UseFlashToastsOptions {
    /** Return true to skip a flash (e.g. conflict pages render outcomes inline). Receives the incoming page. */
    ignore?: (kind: FlashKind, message: string, page: { component: string; url: string; props: Record<string, unknown> }) => boolean;
}

let flashInstalled = false;
let epoch = 0;
let shownEpoch = -1;
const shownInEpoch = new Set<string>();
let flashIgnore: UseFlashToastsOptions['ignore'];

function flashMessage(v: unknown): string | null {
    if (typeof v === 'string') return v.trim() ? v : null;
    if (typeof v === 'number') return String(v);
    if (Array.isArray(v)) {
        const joined = v.filter((x) => typeof x === 'string' && x.trim()).join(' ');
        return joined || null;
    }
    return null;
}

function processFlash(page: { component: string; url: string; props: Record<string, unknown> } | undefined) {
    const flash = page?.props?.flash as Record<string, unknown> | undefined;
    if (!flash) return;
    if (shownEpoch !== epoch) {
        shownEpoch = epoch;
        shownInEpoch.clear();
    }
    for (const kind of FLASH_KINDS) {
        const msg = flashMessage(flash[kind]);
        if (!msg) continue;
        const key = `${kind}\u0000${msg}`;
        if (shownInEpoch.has(key)) continue;
        shownInEpoch.add(key);
        if (flashIgnore?.(kind, msg, page!)) continue;
        push(kind, msg);
    }
}

function installFlashListeners() {
    if (flashInstalled) return;
    flashInstalled = true;

    router.on('start', (event) => {
        const visit = event.detail.visit as {
            method?: string;
            only?: string[];
            except?: string[];
            prefetch?: boolean;
        };
        if (visit.prefetch) return;
        const method = String(visit.method ?? 'get').toLowerCase();
        const partial = (visit.only?.length ?? 0) > 0 || (visit.except?.length ?? 0) > 0;
        if (method !== 'get' || !partial) epoch++;
    });

    router.on('beforeUpdate', (event) => {
        processFlash(event.detail.page as unknown as Parameters<typeof processFlash>[0]);
    });
}

/**
 * Turns Inertia's shared `flash.success / .error / .warning` into toasts,
 * exactly once per user action. Mount once, in the layout, next to <Toaster/>.
 */
export function useFlashToasts(options: UseFlashToastsOptions = {}) {
    const page = usePage();
    flashIgnore = options.ignore;
    const firstMount = useRef(true);

    useEffect(() => {
        if (!firstMount.current) return;
        firstMount.current = false;
        if (flashInstalled) return;
        installFlashListeners();
        // The page we were mounted with never fired beforeUpdate for us.
        processFlash(page as unknown as Parameters<typeof processFlash>[0]);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
}

export default Toaster;
