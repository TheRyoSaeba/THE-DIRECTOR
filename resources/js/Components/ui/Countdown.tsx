import { useEffect, useRef, type ReactNode } from 'react';
import { useServerClock } from '@/contexts/ClockContext';
import { cn } from './styles';

export type CountdownTarget = number | string | Date | null | undefined;
export type CountdownFormat = 'compact' | 'clock';

/**
 * Normalise a target to unix seconds.
 * - number: unix seconds (values > 1e12 are treated as milliseconds)
 * - string: numeric string (as above) or a date string; "Y-m-d H:i:s" without zone is read as UTC
 * - Date
 */
export function toUnixSeconds(target: CountdownTarget): number | null {
    if (target === null || target === undefined || target === '') return null;
    if (target instanceof Date) {
        const t = target.getTime();
        return Number.isFinite(t) ? Math.floor(t / 1000) : null;
    }
    if (typeof target === 'number' || /^\d+(\.\d+)?$/.test(String(target).trim())) {
        const n = Number(target);
        if (!Number.isFinite(n)) return null;
        return Math.floor(n > 1e12 ? n / 1000 : n);
    }
    const s = String(target);
    const normalised = /Z$|[+-]\d{2}:?\d{2}$/.test(s) ? s : s.replace(' ', 'T') + 'Z';
    const t = new Date(normalised).getTime();
    return Number.isFinite(t) ? Math.floor(t / 1000) : null;
}

const pad = (n: number) => String(n).padStart(2, '0');

/**
 * Format a number of seconds.
 * - compact: the two most significant units — "2d 4h", "3h 12m", "5m 3s", "42s"
 * - clock:   "HH:MM:SS" (hours keep counting past 99)
 */
export function formatDuration(totalSeconds: number, format: CountdownFormat = 'compact'): string {
    const s = Math.max(0, Math.floor(totalSeconds));
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;

    if (format === 'clock') {
        return `${pad(d * 24 + h)}:${pad(m)}:${pad(sec)}`;
    }
    if (d > 0) return h > 0 ? `${d}d ${h}h` : `${d}d`;
    if (h > 0) return m > 0 ? `${h}h ${m}m` : `${h}h`;
    if (m > 0) return `${m}m ${sec}s`;
    return `${sec}s`;
}

export interface CountdownState {
    /** Seconds remaining (0 when ready or no target). */
    remaining: number;
    ready: boolean;
    /** The resolved target in unix seconds, or null. */
    target: number | null;
}

/**
 * Seconds until `target`, ticking on the shared server-anchored clock
 * (ClockContext) — never Date.now(), so every timer on screen agrees with the
 * server and with each other.
 */
export function useCountdown(target: CountdownTarget): CountdownState {
    const now = useServerClock() as unknown as number;
    const t = toUnixSeconds(target);
    const remaining = t === null ? 0 : Math.max(0, t - now);
    return { remaining, ready: remaining === 0, target: t };
}

export interface CountdownProps {
    target: CountdownTarget;
    format?: CountdownFormat;
    /** Shown when the countdown has elapsed (or there is no target). Default "Ready". */
    readyLabel?: ReactNode;
    /** Fires once when the countdown crosses zero while mounted (not on mount if already ready). */
    onReady?: () => void;
    /** Text before the time, e.g. "in ". Hidden once ready. */
    prefix?: ReactNode;
    className?: string;
    /** Classes applied only while counting. Default slate-300. */
    activeClassName?: string;
    /** Classes applied when ready. Default emerald-400. */
    readyClassName?: string;
    align?: 'left' | 'right' | 'center';
}

/**
 * Inline countdown. tabular-nums + a fixed min-width keep it from jittering as digits change.
 */
export function Countdown({
    target,
    format = 'compact',
    readyLabel = 'Ready',
    onReady,
    prefix,
    className,
    activeClassName = 'text-slate-300',
    readyClassName = 'text-emerald-400',
    align = 'left',
}: CountdownProps) {
    const { remaining, ready } = useCountdown(target);
    const prev = useRef(remaining);
    const onReadyRef = useRef(onReady);
    onReadyRef.current = onReady;

    useEffect(() => {
        if (prev.current > 0 && remaining === 0) onReadyRef.current?.();
        prev.current = remaining;
    }, [remaining]);

    const text = formatDuration(remaining, format);
    const minWidth = format === 'clock' ? '8ch' : '7ch';

    return (
        <span
            className={cn(
                'inline-block whitespace-nowrap tabular-nums',
                align === 'right' && 'text-right',
                align === 'center' && 'text-center',
                ready ? readyClassName : activeClassName,
                className,
            )}
            style={{ minWidth }}
            // Screen readers: announce the ready transition, not every tick.
            aria-live={ready ? 'polite' : 'off'}
        >
            {ready ? (
                readyLabel
            ) : (
                <>
                    {prefix}
                    <time dateTime={`PT${remaining}S`}>{text}</time>
                </>
            )}
        </span>
    );
}

export default Countdown;
