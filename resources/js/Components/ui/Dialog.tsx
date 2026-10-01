import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import { X } from '@phosphor-icons/react';
import {
    useEffect,
    useId,
    useRef,
    useState,
    type ReactNode,
    type RefObject,
} from 'react';
import { createPortal } from 'react-dom';
import { Button } from './Button';
import { cn, focusRing, textLabel, toneSoft, zDialog, type Tone } from './styles';

// ── Module-level bookkeeping (shared by every Dialog instance) ────────────────

/** Open dialogs, innermost last — only the top one reacts to Escape / owns focus. */
const dialogStack: string[] = [];
let scrollLocks = 0;
let savedOverflow = '';
let savedPaddingRight = '';

function lockScroll() {
    if (scrollLocks++ > 0) return;
    const body = document.body;
    const scrollbar = window.innerWidth - document.documentElement.clientWidth;
    savedOverflow = body.style.overflow;
    savedPaddingRight = body.style.paddingRight;
    body.style.overflow = 'hidden';
    if (scrollbar > 0) body.style.paddingRight = `${scrollbar}px`;
}

function unlockScroll() {
    if (--scrollLocks > 0) return;
    scrollLocks = 0;
    document.body.style.overflow = savedOverflow;
    document.body.style.paddingRight = savedPaddingRight;
}

const FOCUSABLE =
    'a[href],area[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),iframe,[tabindex]:not([tabindex="-1"]),[contenteditable="true"]';

function focusables(root: HTMLElement): HTMLElement[] {
    return Array.from(root.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
        (el) => !el.hasAttribute('inert') && el.getClientRects().length > 0,
    );
}

// ── Types ────────────────────────────────────────────────────────────────────

export type DialogSize = 'sm' | 'md' | 'lg' | 'xl';

export interface DialogMedia {
    /** Header photo (aspect-video, styledmodal look). */
    image?: string;
    /** Shown centred when there is no image. */
    icon?: ReactNode;
    /** Tailwind gradient stops for the overlay, e.g. 'from-slate-900 via-slate-900/30 to-transparent'. */
    gradient?: string;
    badges?: Array<{ text: ReactNode; tone?: Tone }>;
    /** Image alt; defaults to empty (decorative) since the title is rendered as text. */
    alt?: string;
}

export interface DialogProps {
    open: boolean;
    onClose: () => void;
    title: ReactNode;
    /** Rendered under the title; also wired to aria-describedby. */
    description?: ReactNode;
    media?: DialogMedia;
    size?: DialogSize;
    /** Sticky footer slot (actions). */
    footer?: ReactNode;
    /** When false, Escape / scrim click / close button do nothing (e.g. while submitting). */
    dismissible?: boolean;
    hideClose?: boolean;
    /** Element to focus on open (defaults to the first focusable element in the body, then the panel). */
    initialFocusRef?: RefObject<HTMLElement | null>;
    /** 'dim' (default) darkens + slightly blurs the page; 'clear' keeps it at full brightness. */
    scrim?: 'dim' | 'clear';
    /** Border accent. */
    tone?: 'default' | 'danger' | 'gold';
    className?: string;
    bodyClassName?: string;
    children?: ReactNode;
}

const sizes: Record<DialogSize, string> = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-2xl',
};

const toneBorder = {
    default: 'border-slate-700',
    danger: 'border-red-900/70',
    gold: 'border-amber-300/40',
} as const;

// ── Dialog ───────────────────────────────────────────────────────────────────

/**
 * Accessible modal: portal into <body>, scrim, focus trap, Escape to close,
 * body scroll lock, role="dialog" + aria-modal + aria-labelledby, single z layer (60).
 * Replaces StyledModal / StyledModalConflict and the ~22 hand-rolled `fixed inset-0` overlays.
 */
export function Dialog(props: DialogProps) {
    const reduce = useReducedMotion();
    if (typeof document === 'undefined') return null;

    return createPortal(
        <AnimatePresence>
            {props.open && <DialogInner key="dialog" {...props} reduce={Boolean(reduce)} />}
        </AnimatePresence>,
        document.body,
    );
}

function DialogInner({
    onClose,
    title,
    description,
    media,
    size = 'md',
    footer,
    dismissible = true,
    hideClose = false,
    initialFocusRef,
    scrim = 'dim',
    tone = 'default',
    className,
    bodyClassName,
    children,
    reduce,
}: DialogProps & { reduce: boolean }) {
    const uid = useId();
    const titleId = `${uid}-title`;
    const descId = `${uid}-desc`;
    const panelRef = useRef<HTMLDivElement>(null);
    const bodyRef = useRef<HTMLDivElement>(null);
    const pointerDownOnScrim = useRef(false);

    const onCloseRef = useRef(onClose);
    onCloseRef.current = onClose;
    const dismissibleRef = useRef(dismissible);
    dismissibleRef.current = dismissible;

    // Stack, scroll lock, initial focus, focus restore.
    useEffect(() => {
        const previouslyFocused = document.activeElement as HTMLElement | null;
        dialogStack.push(uid);
        lockScroll();

        const panel = panelRef.current;
        const target =
            initialFocusRef?.current ?? (bodyRef.current ? focusables(bodyRef.current)[0] : undefined) ?? panel;
        // Wait a frame so the portal is laid out before focusing.
        const raf = requestAnimationFrame(() => target?.focus({ preventScroll: true }));

        return () => {
            cancelAnimationFrame(raf);
            const i = dialogStack.lastIndexOf(uid);
            if (i !== -1) dialogStack.splice(i, 1);
            unlockScroll();
            if (previouslyFocused && document.contains(previouslyFocused)) {
                previouslyFocused.focus({ preventScroll: true });
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Escape + Tab trap + keep focus inside while topmost.
    useEffect(() => {
        const isTop = () => dialogStack[dialogStack.length - 1] === uid;

        const onKeyDown = (e: KeyboardEvent) => {
            if (!isTop()) return;
            const panel = panelRef.current;
            if (!panel) return;

            if (e.key === 'Escape') {
                e.stopPropagation();
                if (dismissibleRef.current) onCloseRef.current();
                return;
            }
            if (e.key !== 'Tab') return;

            const items = focusables(panel);
            if (items.length === 0) {
                e.preventDefault();
                panel.focus();
                return;
            }
            const first = items[0];
            const last = items[items.length - 1];
            const active = document.activeElement;
            if (e.shiftKey && (active === first || active === panel || !panel.contains(active))) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && (active === last || !panel.contains(active))) {
                e.preventDefault();
                first.focus();
            }
        };

        const onFocusIn = (e: FocusEvent) => {
            if (!isTop()) return;
            const panel = panelRef.current;
            const t = e.target as Node | null;
            // Allow focus into other portals layered above (tooltips, pickers, toasts).
            if (panel && t && !panel.contains(t) && !(t as HTMLElement).closest?.('[data-ui-layer]')) {
                panel.focus({ preventScroll: true });
            }
        };

        document.addEventListener('keydown', onKeyDown, true);
        document.addEventListener('focusin', onFocusIn);
        return () => {
            document.removeEventListener('keydown', onKeyDown, true);
            document.removeEventListener('focusin', onFocusIn);
        };
    }, [uid]);

    const close = () => {
        if (dismissible) onClose();
    };

    const dur = reduce ? 0 : 0.15;

    return (
        <div className={cn('fixed inset-0 flex items-center justify-center p-4', zDialog)} data-ui-layer="dialog">
            <motion.div
                aria-hidden
                className={cn(
                    'absolute inset-0',
                    scrim === 'dim' ? 'bg-slate-950/70 backdrop-blur-[2px]' : 'bg-transparent',
                )}
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                transition={{ duration: dur }}
                onPointerDown={(e) => {
                    pointerDownOnScrim.current = e.target === e.currentTarget;
                }}
                onClick={(e) => {
                    if (pointerDownOnScrim.current && e.target === e.currentTarget) close();
                    pointerDownOnScrim.current = false;
                }}
            />

            <motion.div
                ref={panelRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-describedby={description ? descId : undefined}
                tabIndex={-1}
                initial={reduce ? { opacity: 0 } : { opacity: 0, scale: 0.97, y: 8 }}
                animate={reduce ? { opacity: 1 } : { opacity: 1, scale: 1, y: 0 }}
                exit={reduce ? { opacity: 0 } : { opacity: 0, scale: 0.98, y: 4 }}
                transition={{ duration: dur, ease: 'easeOut' }}
                className={cn(
                    'relative flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden rounded-2xl border bg-slate-900 shadow-2xl outline-none',
                    toneBorder[tone],
                    sizes[size],
                    className,
                )}
            >
                {media ? (
                    <div className="relative aspect-video shrink-0 bg-slate-950">
                        {media.image ? (
                            <img
                                src={media.image}
                                alt={media.alt ?? ''}
                                className="h-full w-full object-cover opacity-80"
                                decoding="async"
                            />
                        ) : media.icon ? (
                            <div className="absolute inset-0 flex items-center justify-center">{media.icon}</div>
                        ) : null}
                        <div
                            aria-hidden
                            className={cn(
                                'pointer-events-none absolute inset-0 bg-gradient-to-t',
                                media.gradient ?? 'from-slate-900 via-slate-900/20 to-transparent',
                            )}
                        />
                        <div className="absolute inset-x-5 bottom-4 pr-6">
                            <h2 id={titleId} className="text-xl font-black uppercase tracking-tight text-white">
                                {title}
                            </h2>
                            {description && (
                                <p id={descId} className="mt-1 text-xs text-slate-300">
                                    {description}
                                </p>
                            )}
                            {media.badges && media.badges.length > 0 && (
                                <div className="mt-2 flex flex-wrap gap-1.5">
                                    {media.badges.map((b, i) => (
                                        <span
                                            key={i}
                                            className={cn('rounded border px-2 py-0.5', textLabel, toneSoft[b.tone ?? 'slate'])}
                                        >
                                            {b.text}
                                        </span>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                ) : (
                    <div className={cn('flex shrink-0 items-start justify-between gap-3 px-5 py-4', !hideClose && 'pr-14', Boolean(children) && 'border-b border-slate-800')}>
                        <div className="min-w-0">
                            <h2 id={titleId} className="text-base font-black uppercase tracking-tight text-white">
                                {title}
                            </h2>
                            {description && (
                                <p id={descId} className="mt-1 text-xs text-slate-400">
                                    {description}
                                </p>
                            )}
                        </div>
                    </div>
                )}

                {!hideClose && (
                    <button
                        type="button"
                        onClick={close}
                        disabled={!dismissible}
                        aria-label="Close"
                        className={cn(
                            'absolute right-3 top-3 flex h-9 w-9 items-center justify-center rounded-full text-white transition-colors duration-150 disabled:opacity-40',
                            media ? 'bg-black/50 hover:bg-black/80' : 'text-slate-400 hover:bg-slate-800 hover:text-white',
                            focusRing,
                        )}
                    >
                        <X size={18} weight="bold" />
                    </button>
                )}

                {children !== undefined && children !== null && children !== false && (
                    <div ref={bodyRef} className={cn('min-h-0 flex-1 overflow-y-auto overscroll-contain', bodyClassName ?? 'p-5')}>
                        {children}
                    </div>
                )}

                {footer && (
                    <div className="flex shrink-0 flex-col-reverse gap-2 border-t border-slate-800 bg-slate-900 px-5 py-4 sm:flex-row sm:justify-end">
                        {footer}
                    </div>
                )}
            </motion.div>
        </div>
    );
}

// ── ConfirmDialog ────────────────────────────────────────────────────────────

export interface ConfirmDialogProps {
    open: boolean;
    onClose: () => void;
    /** May return a promise; the confirm button shows a spinner and the dialog stays open until it settles. */
    onConfirm: () => void | Promise<unknown>;
    title: ReactNode;
    description?: ReactNode;
    confirmLabel?: ReactNode;
    cancelLabel?: ReactNode;
    tone?: 'primary' | 'danger';
    /** External loading flag (e.g. Inertia form `processing`). */
    loading?: boolean;
    /** Close automatically after onConfirm resolves. Default true. */
    closeOnConfirm?: boolean;
    media?: DialogMedia;
    children?: ReactNode;
}

/** Yes/no confirmation. Replaces Corporate `ConfirmDanger` and inline "Are you sure?" toggles. */
export function ConfirmDialog({
    open,
    onClose,
    onConfirm,
    title,
    description,
    confirmLabel = 'Confirm',
    cancelLabel = 'Cancel',
    tone = 'primary',
    loading: loadingProp = false,
    closeOnConfirm = true,
    media,
    children,
}: ConfirmDialogProps) {
    const [pending, setPending] = useState(false);
    const loading = loadingProp || pending;
    const cancelRef = useRef<HTMLButtonElement>(null);

    const confirm = async () => {
        const result = onConfirm();
        if (result && typeof (result as Promise<unknown>).then === 'function') {
            setPending(true);
            try {
                await result;
                if (closeOnConfirm) onClose();
            } finally {
                setPending(false);
            }
        } else if (closeOnConfirm && !loadingProp) {
            onClose();
        }
    };

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={title}
            description={description}
            media={media}
            size="sm"
            tone={tone === 'danger' ? 'danger' : 'default'}
            dismissible={!loading}
            // Destructive confirmations focus Cancel first so Enter doesn't fire them.
            initialFocusRef={tone === 'danger' ? cancelRef : undefined}
            footer={
                <>
                    <Button ref={cancelRef} variant="ghost" onClick={onClose} disabled={loading}>
                        {cancelLabel}
                    </Button>
                    <Button variant={tone === 'danger' ? 'danger' : 'primary'} loading={loading} onClick={confirm}>
                        {confirmLabel}
                    </Button>
                </>
            }
        >
            {children}
        </Dialog>
    );
}

export default Dialog;
