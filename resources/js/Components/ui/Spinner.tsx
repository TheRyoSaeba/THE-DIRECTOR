import { cn } from './styles';

export interface SpinnerProps {
    /** Pixel size. Default 14. */
    size?: number;
    className?: string;
    /** Accessible label. Pass `null` when the spinner is decorative (e.g. inside a busy button). */
    label?: string | null;
}

/** Inherits `currentColor`, so it matches whatever text colour it sits in. */
export function Spinner({ size = 14, className, label = 'Loading' }: SpinnerProps) {
    return (
        <span
            role={label ? 'status' : undefined}
            aria-label={label ?? undefined}
            aria-hidden={label ? undefined : true}
            style={{ width: size, height: size }}
            className={cn(
                'inline-block shrink-0 rounded-full border-2 border-current border-r-transparent opacity-80 animate-spin motion-reduce:[animation-duration:1.5s]',
                className,
            )}
        />
    );
}

export default Spinner;
