import {
    cloneElement,
    isValidElement,
    useCallback,
    useEffect,
    useId,
    useLayoutEffect,
    useRef,
    useState,
    type ReactElement,
    type ReactNode,
} from 'react';
import { createPortal } from 'react-dom';
import { cn, zTooltip } from './styles';

export interface TooltipProps {
    content: ReactNode;
    /** Preferred side; flips when there is no room. */
    side?: 'top' | 'bottom';
    /** Hover open delay in ms. Default 120. Focus and tap open immediately. */
    delay?: number;
    /** Make the wrapper itself focusable (when the child is not, e.g. an icon or text). */
    focusable?: boolean;
    className?: string;
    /** Classes for the trigger wrapper. */
    triggerClassName?: string;
    children: ReactNode;
}

const GAP = 8;
const MARGIN = 8;

/**
 * Lightweight tooltip: opens on mouse hover, keyboard focus AND touch tap
 * (tap toggles; tapping elsewhere or Escape closes). Portalled, fixed-position, z 80.
 */
export function Tooltip({
    content,
    side = 'top',
    delay = 120,
    focusable = false,
    className,
    triggerClassName,
    children,
}: TooltipProps) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const [pos, setPos] = useState<{ top: number; left: number; side: 'top' | 'bottom' } | null>(null);
    const triggerRef = useRef<HTMLSpanElement>(null);
    const tipRef = useRef<HTMLDivElement>(null);
    const timer = useRef<number | undefined>(undefined);
    const lastPointer = useRef<string>('mouse');

    const clear = () => window.clearTimeout(timer.current);
    const show = useCallback((wait = 0) => {
        clear();
        if (wait) timer.current = window.setTimeout(() => setOpen(true), wait);
        else setOpen(true);
    }, []);
    const hide = useCallback(() => {
        clear();
        setOpen(false);
    }, []);

    useEffect(() => clear, []);

    // Measure + place after render (tooltip size is only known once it exists).
    useLayoutEffect(() => {
        if (!open) {
            setPos(null);
            return;
        }
        const place = () => {
            const t = triggerRef.current?.getBoundingClientRect();
            const tip = tipRef.current;
            if (!t || !tip) return;
            const w = tip.offsetWidth;
            const h = tip.offsetHeight;
            let s = side;
            if (s === 'top' && t.top - h - GAP < MARGIN) s = 'bottom';
            else if (s === 'bottom' && t.bottom + h + GAP > window.innerHeight - MARGIN) s = 'top';
            const top = s === 'top' ? t.top - h - GAP : t.bottom + GAP;
            const left = Math.min(Math.max(MARGIN, t.left + t.width / 2 - w / 2), window.innerWidth - w - MARGIN);
            setPos({ top, left, side: s });
        };
        place();
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);
        return () => {
            window.removeEventListener('scroll', place, true);
            window.removeEventListener('resize', place);
        };
    }, [open, side, content]);

    // Tap-outside + Escape close.
    useEffect(() => {
        if (!open) return;
        const onDown = (e: PointerEvent) => {
            if (!triggerRef.current?.contains(e.target as Node)) hide();
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') hide();
        };
        document.addEventListener('pointerdown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('pointerdown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open, hide]);

    const child =
        isValidElement(children) && !focusable
            ? cloneElement(children as ReactElement<{ 'aria-describedby'?: string }>, {
                  'aria-describedby': open ? id : undefined,
              })
            : children;

    return (
        <>
            <span
                ref={triggerRef}
                className={cn('inline-flex', triggerClassName)}
                tabIndex={focusable ? 0 : undefined}
                aria-describedby={focusable && open ? id : undefined}
                onPointerDown={(e) => {
                    lastPointer.current = e.pointerType;
                }}
                onPointerEnter={(e) => {
                    if (e.pointerType === 'mouse' || e.pointerType === 'pen') show(delay);
                }}
                onPointerLeave={(e) => {
                    if (e.pointerType === 'mouse' || e.pointerType === 'pen') hide();
                }}
                onFocus={() => {
                    // Taps also focus buttons; let onClick own the toggle in that case.
                    if (lastPointer.current !== 'touch') show();
                }}
                onBlur={hide}
                onClick={() => {
                    if (lastPointer.current === 'touch') {
                        if (open) hide();
                        else show();
                    }
                }}
            >
                {child}
            </span>
            {open &&
                typeof document !== 'undefined' &&
                createPortal(
                    <div
                        ref={tipRef}
                        id={id}
                        role="tooltip"
                        data-ui-layer="tooltip"
                        style={{
                            position: 'fixed',
                            top: pos?.top ?? -9999,
                            left: pos?.left ?? -9999,
                            visibility: pos ? 'visible' : 'hidden',
                        }}
                        className={cn(
                            'pointer-events-none max-w-xs rounded-lg border border-slate-700 bg-slate-900/95 px-2.5 py-1.5 text-xs text-slate-100 shadow-xl backdrop-blur',
                            zTooltip,
                            className,
                        )}
                    >
                        {content}
                    </div>,
                    document.body,
                )}
        </>
    );
}

export default Tooltip;
