import type { CSSProperties } from 'react';
import { cn } from './styles';

export interface SkeletonProps {
    /** Fixed width (px number or any CSS length). Required to avoid layout shift — use '100%' to fill. */
    width: number | string;
    /** Fixed height (px number or CSS length). */
    height: number | string;
    rounded?: 'sm' | 'md' | 'lg' | 'xl' | '2xl' | 'full';
    className?: string;
    style?: CSSProperties;
}

const radii = {
    sm: 'rounded-sm',
    md: 'rounded-md',
    lg: 'rounded-lg',
    xl: 'rounded-xl',
    '2xl': 'rounded-2xl',
    full: 'rounded-full',
} as const;

/** Placeholder block with explicit dimensions so swapping in real content does not shift layout. */
export function Skeleton({ width, height, rounded = 'md', className, style }: SkeletonProps) {
    return (
        <span
            aria-hidden
            className={cn('block shrink-0 animate-pulse bg-slate-800/80 motion-reduce:animate-none', radii[rounded], className)}
            style={{ width, height, ...style }}
        />
    );
}

export interface SkeletonTextProps {
    lines?: number;
    /** Line height in px (matches the text it stands in for). Default 14 (body). */
    lineHeight?: number;
    className?: string;
}

/** N lines of text placeholder; the last line is shorter. */
export function SkeletonText({ lines = 3, lineHeight = 14, className }: SkeletonTextProps) {
    return (
        <span aria-hidden className={cn('flex flex-col gap-2', className)}>
            {Array.from({ length: lines }, (_, i) => (
                <Skeleton key={i} width={i === lines - 1 && lines > 1 ? '60%' : '100%'} height={lineHeight} rounded="sm" />
            ))}
        </span>
    );
}

export default Skeleton;
