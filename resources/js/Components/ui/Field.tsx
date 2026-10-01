import {
    createContext,
    useContext,
    useId,
    type InputHTMLAttributes,
    type ReactNode,
    type Ref,
} from 'react';
import { parseSymbolicAmount } from '@/Layouts/GameLayoutComponents';
import { formatMoney } from './Money';
import { cn, textLabel } from './styles';

interface FieldCtx {
    id: string;
    describedBy?: string;
    invalid: boolean;
}

const FieldContext = createContext<FieldCtx | null>(null);

export interface FieldProps {
    label?: ReactNode;
    hint?: ReactNode;
    /** Error message (e.g. Inertia `errors.amount`). Sets aria-invalid on the input. */
    error?: ReactNode;
    /** Right side of the label row, e.g. "Balance: $12,000". */
    aside?: ReactNode;
    required?: boolean;
    /** Override the generated id (the input picks it up automatically). */
    id?: string;
    className?: string;
    children: ReactNode;
}

/**
 * Label + control + hint/error, with ids wired for screen readers.
 * Any <Input>/<MoneyInput> inside picks up id / aria-describedby / aria-invalid from context.
 */
export function Field({ label, hint, error, aside, required, id, className, children }: FieldProps) {
    const auto = useId();
    const fieldId = id ?? auto;
    const hintId = hint ? `${fieldId}-hint` : undefined;
    const errorId = error ? `${fieldId}-error` : undefined;
    const describedBy = [errorId, hintId].filter(Boolean).join(' ') || undefined;

    return (
        <FieldContext.Provider value={{ id: fieldId, describedBy, invalid: Boolean(error) }}>
            <div className={cn('min-w-0', className)}>
                {(label || aside) && (
                    <div className="mb-1.5 flex items-center justify-between gap-2">
                        {label && (
                            <label htmlFor={fieldId} className={cn(textLabel, 'text-slate-400')}>
                                {label}
                                {required && (
                                    <span aria-hidden className="ml-0.5 text-red-400">
                                        *
                                    </span>
                                )}
                            </label>
                        )}
                        {aside && <span className="text-xs text-slate-400">{aside}</span>}
                    </div>
                )}
                {children}
                {error ? (
                    <p id={errorId} className="mt-1.5 text-xs text-red-400">
                        {error}
                    </p>
                ) : hint ? (
                    <p id={hintId} className="mt-1.5 text-xs text-slate-400">
                        {hint}
                    </p>
                ) : null}
            </div>
        </FieldContext.Provider>
    );
}

export function useFieldContext() {
    return useContext(FieldContext);
}

export interface InputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'prefix' | 'size'> {
    prefix?: ReactNode;
    suffix?: ReactNode;
    prefixClassName?: string;
    size?: 'md' | 'lg';
    invalid?: boolean;
    ref?: Ref<HTMLInputElement>;
}

const inputBase =
    'w-full rounded-lg border bg-slate-950/60 text-sm text-white placeholder:text-slate-400 transition-colors duration-150 outline-none focus:outline-none focus:ring-1 focus:ring-offset-0 disabled:cursor-not-allowed disabled:opacity-50';

/** Styled text input. Use inside <Field> for label/hint/error wiring. */
export function Input({
    prefix,
    suffix,
    prefixClassName = 'text-slate-400',
    size = 'md',
    invalid,
    className,
    id,
    ref,
    ...rest
}: InputProps) {
    const ctx = useFieldContext();
    const isInvalid = invalid ?? ctx?.invalid ?? false;

    return (
        <div className="relative">
            {prefix && (
                <span
                    aria-hidden
                    className={cn(
                        'pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm font-bold',
                        prefixClassName,
                    )}
                >
                    {prefix}
                </span>
            )}
            <input
                ref={ref}
                id={id ?? ctx?.id}
                aria-describedby={rest['aria-describedby'] ?? ctx?.describedBy}
                aria-invalid={isInvalid || undefined}
                className={cn(
                    inputBase,
                    size === 'lg' ? 'h-12' : 'h-10',
                    'px-3',
                    prefix ? 'pl-7' : '',
                    suffix ? 'pr-16' : '',
                    isInvalid
                        ? 'border-red-500/70 focus:border-red-400 focus:ring-red-400'
                        : 'border-slate-700 hover:border-slate-600 focus:border-cyan-400 focus:ring-cyan-400',
                    className,
                )}
                {...rest}
            />
            {suffix && <span className="absolute right-1.5 top-1/2 flex -translate-y-1/2 items-center">{suffix}</span>}
        </div>
    );
}

export interface MoneyInputProps extends Omit<InputProps, 'value' | 'onChange' | 'prefix' | 'type' | 'max'> {
    /** Raw text as typed ("1.5m", "250k", "12,000"). */
    value: string;
    /** Receives the raw text and the parsed whole-dollar amount. */
    onChange: (raw: string, amount: number) => void;
    /** Adds a MAX button that fills this amount. */
    max?: number;
    /** Prefix colour: clean (green, default), dirty (red), neutral. */
    kind?: 'clean' | 'dirty' | 'neutral';
    /** Show "= $1,500,000" under the field when shorthand is used. Default true. */
    showPreview?: boolean;
}

/**
 * Money input accepting k/m/b shorthand via the existing `parseSymbolicAmount`
 * (same parser the Bank / Banking / Actions pages use), with a live preview.
 */
export function MoneyInput({
    value,
    onChange,
    max,
    kind = 'clean',
    showPreview = true,
    placeholder = '0 — try 250k, 1.5m',
    ...rest
}: MoneyInputProps) {
    const amount = parseSymbolicAmount(value) as number;
    const usesShorthand = /[kmb]\s*$/i.test(value.trim());
    const prefixClassName = kind === 'clean' ? 'text-emerald-400' : kind === 'dirty' ? 'text-red-400' : 'text-slate-400';

    return (
        <div>
            <Input
                {...rest}
                type="text"
                inputMode="decimal"
                autoComplete="off"
                spellCheck={false}
                placeholder={placeholder}
                value={value}
                onChange={(e) => onChange(e.target.value, parseSymbolicAmount(e.target.value) as number)}
                prefix="$"
                prefixClassName={prefixClassName}
                className={cn('font-bold tabular-nums', rest.className)}
                suffix={
                    max !== undefined ? (
                        <button
                            type="button"
                            onClick={() => onChange(String(max), max)}
                            className={cn(
                                'h-7 rounded-md border border-slate-700 bg-slate-800 px-2 text-slate-300 transition-colors duration-150 hover:border-cyan-400/50 hover:text-cyan-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400',
                                textLabel,
                            )}
                        >
                            Max
                        </button>
                    ) : undefined
                }
            />
            {showPreview && usesShorthand && amount > 0 && (
                <p className="mt-1 text-xs tabular-nums text-slate-400" aria-live="polite">
                    = {formatMoney(amount)}
                </p>
            )}
        </div>
    );
}

export default Field;
