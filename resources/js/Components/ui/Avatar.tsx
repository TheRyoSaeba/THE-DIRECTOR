import { useEffect, useState } from 'react';
import { cn } from './styles';

export type AvatarSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl';
export type AvatarStatus = 'online' | 'idle' | 'offline' | 'dead';

export interface AvatarProps {
    src?: string | null;
    /** Used for alt text and the initials fallback. */
    name?: string | null;
    size?: AvatarSize;
    /** Coloured ring around the avatar. */
    status?: AvatarStatus;
    /** Custom ring classes (e.g. a rank colour) — overrides `status`. */
    ringClassName?: string;
    shape?: 'circle' | 'rounded';
    className?: string;
}

const px: Record<AvatarSize, number> = { xs: 24, sm: 32, md: 40, lg: 56, xl: 80 };
const textSize: Record<AvatarSize, string> = {
    xs: 'text-[10px]',
    sm: 'text-xs',
    md: 'text-sm',
    lg: 'text-lg',
    xl: 'text-2xl',
};

const statusRing: Record<AvatarStatus, string> = {
    online: 'ring-emerald-400',
    idle: 'ring-amber-400',
    offline: 'ring-slate-600',
    dead: 'ring-red-500',
};

const fallbackBg = [
    'bg-cyan-900 text-cyan-200',
    'bg-indigo-900 text-indigo-200',
    'bg-emerald-900 text-emerald-200',
    'bg-amber-900 text-amber-200',
    'bg-rose-900 text-rose-200',
    'bg-slate-700 text-slate-100',
];

export function initials(name?: string | null): string {
    const clean = (name ?? '').trim();
    if (!clean) return '?';
    const parts = clean.split(/[\s_\-.]+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return clean.slice(0, 2).toUpperCase();
}

function hash(s: string) {
    let h = 0;
    for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0;
    return Math.abs(h);
}

/** Portrait with an initials fallback (also used when the image fails to load) and optional status ring. */
export function Avatar({ src, name, size = 'md', status, ringClassName, shape = 'circle', className }: AvatarProps) {
    const [failed, setFailed] = useState(false);
    useEffect(() => setFailed(false), [src]);

    const dim = px[size];
    const radius = shape === 'circle' ? 'rounded-full' : 'rounded-xl';
    const ring = ringClassName ?? (status ? statusRing[status] : '');
    const showImg = Boolean(src) && !failed;

    return (
        <span
            className={cn(
                'relative inline-flex shrink-0 items-center justify-center overflow-hidden font-black',
                radius,
                ring && cn('ring-2 ring-offset-2 ring-offset-slate-950', ring),
                !showImg && fallbackBg[hash(name ?? '') % fallbackBg.length],
                textSize[size],
                className,
            )}
            style={{ width: dim, height: dim }}
            title={name ?? undefined}
        >
            {showImg ? (
                <img
                    src={src!}
                    alt={name ?? ''}
                    width={dim}
                    height={dim}
                    loading="lazy"
                    decoding="async"
                    onError={() => setFailed(true)}
                    className="h-full w-full object-cover"
                />
            ) : (
                <span aria-label={name ?? undefined}>{initials(name)}</span>
            )}
        </span>
    );
}

export default Avatar;
