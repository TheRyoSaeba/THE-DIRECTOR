import type { ElementType, ReactNode } from 'react';
import { cn, panelShadow, textLabel } from './styles';

export type PanelTone = 'default' | 'danger' | 'gold';
export type PanelPadding = 'none' | 'sm' | 'md' | 'lg';

export interface PanelProps {
    /** Tracked uppercase eyebrow. */
    title?: ReactNode;
    subtitle?: ReactNode;
    /** Right-aligned header slot (buttons, badges, a Countdown…). */
    actions?: ReactNode;
    tone?: PanelTone;
    padding?: PanelPadding;
    as?: ElementType;
    className?: string;
    /** Extra classes for the body wrapper. */
    bodyClassName?: string;
    id?: string;
    children?: ReactNode;
}

const tones: Record<PanelTone, { border: string; eyebrow: string; bg: string }> = {
    default: { border: 'border-slate-800', eyebrow: 'text-cyan-400', bg: 'bg-slate-900/65' },
    danger: { border: 'border-red-900/60', eyebrow: 'text-red-400', bg: 'bg-red-950/20' },
    gold: { border: 'border-amber-300/30', eyebrow: 'text-amber-300', bg: 'bg-slate-900/65' },
};

const paddings: Record<PanelPadding, string> = {
    none: 'p-0',
    sm: 'p-3',
    md: 'p-4',
    lg: 'p-5 sm:p-6',
};

/**
 * The standard bordered, rounded surface. Replaces Corporate.jsx's Panel and
 * the dozens of `rounded-2xl border border-slate-700/xx bg-slate-900/xx` divs.
 */
export function Panel({
    title,
    subtitle,
    actions,
    tone = 'default',
    padding = 'md',
    as: As = 'section',
    className,
    bodyClassName,
    id,
    children,
}: PanelProps) {
    const t = tones[tone];
    const hasHeader = Boolean(title || subtitle || actions);
    const headerPad = padding === 'none' ? 'px-4 pt-4' : '';

    return (
        <As id={id} className={cn('rounded-2xl border', t.border, t.bg, panelShadow, paddings[padding], className)}>
            {hasHeader && (
                <div className={cn('mb-3 flex items-start justify-between gap-3', headerPad)}>
                    <div className="min-w-0">
                        {title && <p className={cn(textLabel, t.eyebrow)}>{title}</p>}
                        {subtitle && <p className="mt-1 text-xs text-slate-400">{subtitle}</p>}
                    </div>
                    {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
                </div>
            )}
            {bodyClassName ? <div className={bodyClassName}>{children}</div> : children}
        </As>
    );
}

export default Panel;
