import type { ReactNode } from 'react';
import { isValidElement, type ComponentType } from 'react';
import { cn, textLabel, toneText, type Tone } from './styles';

export interface StatTileProps {
    label: ReactNode;
    value: ReactNode;
    /** Secondary line under the value (e.g. "+12% today", a Countdown). */
    sub?: ReactNode;
    icon?: ComponentType<{ size?: number | string; weight?: any; className?: string }> | ReactNode;
    /** Colours the value. Default slate (white). */
    tone?: Tone;
    /** Right-aligned slot in the label row. */
    aside?: ReactNode;
    className?: string;
}

/** Small "label over big number" tile. Replaces styledmodal `InfoCard` and ad-hoc stat boxes. */
export function StatTile({ label, value, sub, icon, tone = 'slate', aside, className }: StatTileProps) {
    let iconNode: ReactNode = null;
    if (icon) {
        if (isValidElement(icon)) iconNode = icon;
        else if (typeof icon === 'function' || typeof icon === 'object') {
            const Icon = icon as ComponentType<{ size?: number; weight?: any; className?: string }>;
            iconNode = <Icon size={12} weight="bold" className="text-slate-400" />;
        }
    }

    return (
        <div className={cn('min-w-0 rounded-xl border border-slate-800 bg-slate-950/50 p-3', className)}>
            <div className="mb-1 flex items-center justify-between gap-2">
                <span className={cn('flex min-w-0 items-center gap-1.5 truncate text-slate-400', textLabel)}>
                    {iconNode}
                    {label}
                </span>
                {aside}
            </div>
            <div className={cn('truncate text-base font-black tabular-nums', toneText[tone])}>{value}</div>
            {sub && <div className="mt-0.5 truncate text-xs text-slate-400">{sub}</div>}
        </div>
    );
}

export default StatTile;
