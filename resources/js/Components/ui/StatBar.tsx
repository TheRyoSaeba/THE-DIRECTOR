import type { ReactNode } from 'react';
import { cn, textLabel, toneFill, type Tone } from './styles';

export interface StatBarProps {
    value: number;
    max?: number;
    tone?: Tone;
    /** Label shown above the bar (left). */
    label?: ReactNode;
    /** Right side of the label row. `true` = "value / max", or pass a node. */
    valueLabel?: ReactNode | true;
    size?: 'xs' | 'sm' | 'md';
    className?: string;
    /** Accessible name when there is no visible label. */
    'aria-label'?: string;
}

const heights = { xs: 'h-1', sm: 'h-1.5', md: 'h-2.5' } as const;

/**
 * Health / XP / progress bar. Renders at its real width on first paint — the
 * width transition only runs on subsequent changes (no animate-from-zero).
 */
export function StatBar({
    value,
    max = 100,
    tone = 'cyan',
    label,
    valueLabel,
    size = 'sm',
    className,
    'aria-label': ariaLabel,
}: StatBarProps) {
    const safeMax = max > 0 ? max : 1;
    const pct = Math.max(0, Math.min(100, (value / safeMax) * 100));
    const right =
        valueLabel === true ? (
            <span className="tabular-nums">
                {Math.round(value).toLocaleString()} / {Math.round(max).toLocaleString()}
            </span>
        ) : (
            valueLabel
        );

    return (
        <div className={cn('min-w-0', className)}>
            {(label || right) && (
                <div className={cn('mb-1 flex items-center justify-between gap-2 text-slate-400', textLabel)}>
                    <span className="truncate">{label}</span>
                    {right && <span className="shrink-0 text-slate-300">{right}</span>}
                </div>
            )}
            <div
                role="progressbar"
                aria-label={ariaLabel ?? (typeof label === 'string' ? label : undefined)}
                aria-valuemin={0}
                aria-valuemax={max}
                aria-valuenow={Math.round(value)}
                className={cn('w-full overflow-hidden rounded-full bg-slate-800', heights[size])}
            >
                <div
                    className={cn(
                        'h-full rounded-full transition-[width] duration-150 ease-out motion-reduce:transition-none',
                        toneFill[tone],
                    )}
                    style={{ width: `${pct}%` }}
                />
            </div>
        </div>
    );
}

export default StatBar;
