import type { ReactNode } from 'react';
import { cn, textLabel, toneFill, toneSoft, type Tone } from './styles';

export interface BadgeProps {
    tone?: Tone;
    /** `pill` = rounded-full (status), `tag` = rounded-md (category). */
    shape?: 'pill' | 'tag';
    /** Leading status dot. */
    dot?: boolean;
    icon?: ReactNode;
    className?: string;
    title?: string;
    children: ReactNode;
}

/** Replaces Law/Police `Pill`, Corporate `PositionBadge`, styledmodal badge spans. */
export function Badge({ tone = 'slate', shape = 'pill', dot, icon, className, title, children }: BadgeProps) {
    return (
        <span
            title={title}
            className={cn(
                'inline-flex h-5 shrink-0 items-center gap-1 whitespace-nowrap border px-2',
                shape === 'pill' ? 'rounded-full' : 'rounded-md',
                textLabel,
                toneSoft[tone],
                className,
            )}
        >
            {dot && <span aria-hidden className={cn('h-1.5 w-1.5 rounded-full', toneFill[tone])} />}
            {icon}
            {children}
        </span>
    );
}

export default Badge;
