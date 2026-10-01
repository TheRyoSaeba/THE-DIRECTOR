import { Link } from '@inertiajs/react';
import { CaretLeft, CaretRight } from '@phosphor-icons/react';
import type { ReactNode } from 'react';
import { cn, focusRing } from './styles';

export interface PaginationProps {
    /** 1-based current page. */
    page: number;
    /** Total number of items (with `pageSize`)… */
    total?: number;
    pageSize?: number;
    /** …or the page count directly (e.g. Laravel paginator `last_page`). */
    totalPages?: number;
    /** Client-side paging. */
    onChange?: (page: number) => void;
    /** Server-side paging: build the URL for a page. Rendered as Inertia <Link>s (prefetch on hover). */
    getHref?: (page: number) => string;
    /** Prev / "3 / 12" / next only — for tight spaces. */
    compact?: boolean;
    /** Neighbours shown either side of the current page. Default 1. */
    siblings?: number;
    className?: string;
    'aria-label'?: string;
}

type Slot = number | 'gap-l' | 'gap-r';

function buildSlots(page: number, pages: number, siblings: number): Slot[] {
    const out: Slot[] = [];
    const start = Math.max(2, page - siblings);
    const end = Math.min(pages - 1, page + siblings);
    out.push(1);
    if (start > 2) out.push('gap-l');
    for (let i = start; i <= end; i++) out.push(i);
    if (end < pages - 1) out.push('gap-r');
    if (pages > 1) out.push(pages);
    return out;
}

const cell =
    'flex h-10 min-w-[2.5rem] items-center justify-center rounded-lg border px-2 text-xs font-extrabold tabular-nums transition-colors duration-150';
const idle = 'border-slate-800 bg-slate-900/40 text-slate-300 hover:border-slate-600 hover:text-white';
const current = 'border-cyan-500/40 bg-cyan-500/15 text-cyan-300';
const disabled = 'pointer-events-none border-slate-800 text-slate-600 opacity-50';

/**
 * Page navigation with 40px touch targets. Renders nothing for a single page.
 * Replaces the 7 hand-rolled paginations (Law, Police, Help, Journal, Leaderboard, Announcements, …).
 */
export function Pagination({
    page,
    total,
    pageSize = 10,
    totalPages,
    onChange,
    getHref,
    compact = false,
    siblings = 1,
    className,
    'aria-label': ariaLabel = 'Pagination',
}: PaginationProps) {
    const pages = Math.max(1, totalPages ?? Math.ceil((total ?? 0) / Math.max(1, pageSize)));
    if (pages <= 1) return null;
    const p = Math.min(Math.max(1, page), pages);

    // A plain render function (not a nested component) so buttons keep identity — and focus — across renders.
    const item = (
        key: string | number,
        to: number,
        label: string,
        children: ReactNode,
        { isCurrent = false, isDisabled = false }: { isCurrent?: boolean; isDisabled?: boolean } = {},
    ) => {
        const cls = cn(cell, isDisabled ? disabled : isCurrent ? current : idle, focusRing);
        if (isDisabled) {
            return (
                <span key={key} className={cls} aria-disabled="true" aria-label={label}>
                    {children}
                </span>
            );
        }
        if (getHref) {
            return (
                <Link
                    key={key}
                    href={getHref(to)}
                    preserveScroll
                    prefetch="hover"
                    className={cls}
                    aria-label={label}
                    aria-current={isCurrent ? 'page' : undefined}
                >
                    {children}
                </Link>
            );
        }
        return (
            <button
                key={key}
                type="button"
                onClick={() => !isCurrent && onChange?.(to)}
                className={cls}
                aria-label={label}
                aria-current={isCurrent ? 'page' : undefined}
            >
                {children}
            </button>
        );
    };

    return (
        <nav aria-label={ariaLabel} className={cn('flex items-center justify-center gap-1', className)}>
            {item('prev', p - 1, 'Previous page', <CaretLeft size={14} weight="bold" />, { isDisabled: p === 1 })}

            {compact ? (
                <span className="min-w-[4.5rem] px-2 text-center text-xs font-extrabold tabular-nums text-slate-300">
                    {p} / {pages}
                </span>
            ) : (
                buildSlots(p, pages, siblings).map((s) =>
                    typeof s === 'number' ? (
                        item(s, s, `Page ${s}`, s, { isCurrent: s === p })
                    ) : (
                        <span key={s} aria-hidden className="w-6 text-center text-xs text-slate-500">
                            …
                        </span>
                    ),
                )
            )}

            {item('next', p + 1, 'Next page', <CaretRight size={14} weight="bold" />, { isDisabled: p === pages })}
        </nav>
    );
}

export default Pagination;
