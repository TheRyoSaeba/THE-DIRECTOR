import { cn } from './styles';

export type MoneyKind = 'clean' | 'dirty' | 'neutral' | 'delta';

export interface FormatMoneyOptions {
    /** Abbreviate large values ("1.2M"). */
    compact?: boolean;
    /** Only abbreviate at or above this absolute value. Default 100,000 (matches formatCash). */
    compactFrom?: number;
    /** Always show a sign ("+$1,200" / "−$1,200"). */
    signed?: boolean;
    symbol?: string;
}

const full = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });
const short = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 });

/** "$1,234,567", compact "$1.2M", signed "+$1.2M" / "−$500". Non-finite → "$0". */
export function formatMoney(amount: number | string | null | undefined, opts: FormatMoneyOptions = {}): string {
    const { compact = false, compactFrom = 100_000, signed = false, symbol = '$' } = opts;
    const n = Number(amount);
    const v = Number.isFinite(n) ? n : 0;
    const abs = Math.abs(v);
    const body = compact && abs >= compactFrom ? short.format(abs) : full.format(Math.round(abs));
    const sign = v < 0 ? '−' : signed && v > 0 ? '+' : '';
    return `${sign}${symbol}${body}`;
}

export interface MoneyProps {
    amount: number | string | null | undefined;
    /** clean = green, dirty = red, neutral = inherits colour, delta = green/red by sign with +/−. */
    kind?: MoneyKind;
    compact?: boolean;
    compactFrom?: number;
    className?: string;
}

const kindClass: Record<Exclude<MoneyKind, 'delta'>, string> = {
    clean: 'text-emerald-400',
    dirty: 'text-red-400',
    neutral: '',
};

/**
 * Money amount in the house colours. The full, unabbreviated value is always in `title`
 * (hover / long-press), so compact mode never hides information.
 */
export function Money({ amount, kind = 'clean', compact = false, compactFrom, className }: MoneyProps) {
    const n = Number(amount);
    const v = Number.isFinite(n) ? n : 0;
    const isDelta = kind === 'delta';
    const text = formatMoney(v, { compact, compactFrom, signed: isDelta });
    const color = isDelta ? (v > 0 ? 'text-emerald-400' : v < 0 ? 'text-red-400' : 'text-slate-400') : kindClass[kind];
    const fullText = formatMoney(v, { signed: isDelta });

    return (
        <span
            className={cn('whitespace-nowrap font-bold tabular-nums', color, className)}
            title={fullText}
            aria-label={kind === 'dirty' ? `${fullText} dirty cash` : undefined}
        >
            {text}
        </span>
    );
}

export default Money;
