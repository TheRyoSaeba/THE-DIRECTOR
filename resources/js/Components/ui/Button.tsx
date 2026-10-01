import { Link, type InertiaLinkProps } from '@inertiajs/react';
import {
    isValidElement,
    type ButtonHTMLAttributes,
    type ComponentType,
    type ReactElement,
    type ReactNode,
    type Ref,
} from 'react';
import { Spinner } from './Spinner';
import { cn, focusRing } from './styles';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'success' | 'warning';
export type ButtonSize = 'sm' | 'md' | 'lg';

/** A Phosphor icon component (or anything taking `size`), or an already-rendered element. */
export type IconLike = ComponentType<{ size?: number | string; weight?: any; className?: string }> | ReactElement;

interface ButtonOwnProps {
    variant?: ButtonVariant;
    size?: ButtonSize;
    /** Shows a spinner in place of the icon (or over the label) without changing width; blocks clicks. */
    loading?: boolean;
    icon?: IconLike;
    iconRight?: IconLike;
    /** Square icon-only button. Provide `aria-label`. */
    iconOnly?: boolean;
    fullWidth?: boolean;
    className?: string;
    children?: ReactNode;
}

export type ButtonAsButtonProps = ButtonOwnProps &
    Omit<ButtonHTMLAttributes<HTMLButtonElement>, keyof ButtonOwnProps> & {
        href?: undefined;
        ref?: Ref<HTMLButtonElement>;
    };

export type ButtonAsLinkProps = ButtonOwnProps &
    Omit<InertiaLinkProps, keyof ButtonOwnProps | 'href'> & {
        href: string;
        /** Render a plain <a> (off-site / non-Inertia URL). */
        external?: boolean;
        disabled?: boolean;
    };

export type ButtonProps = ButtonAsButtonProps | ButtonAsLinkProps;

const base =
    'relative inline-flex select-none items-center justify-center gap-2 whitespace-nowrap rounded-xl font-extrabold uppercase transition-colors duration-150';

const sizes: Record<ButtonSize, string> = {
    sm: 'h-8 px-3 text-[10px] leading-[14px] tracking-[0.16em]',
    md: 'h-10 px-4 text-[10px] leading-[14px] tracking-[0.18em]',
    lg: 'h-12 px-5 text-xs tracking-[0.18em]',
};

const iconOnlySizes: Record<ButtonSize, string> = {
    sm: 'h-8 w-8',
    md: 'h-10 w-10',
    lg: 'h-12 w-12',
};

const iconPx: Record<ButtonSize, number> = { sm: 14, md: 16, lg: 18 };

const variants: Record<ButtonVariant, string> = {
    // House style: white slab, cyan on hover.
    primary: 'bg-white text-slate-950 hover:bg-cyan-400 active:bg-cyan-300',
    secondary: 'border border-slate-700 bg-slate-800 text-slate-100 hover:border-slate-600 hover:bg-slate-700',
    ghost: 'text-slate-300 hover:bg-slate-800/70 hover:text-white',
    danger: 'border border-red-900/60 bg-red-950/40 text-red-300 hover:border-red-500 hover:bg-red-600 hover:text-white',
    success: 'bg-emerald-600 text-white hover:bg-emerald-500',
    warning: 'bg-amber-500 text-slate-950 hover:bg-amber-400',
};

const disabledCls = 'cursor-not-allowed border border-slate-800 bg-slate-800/70 text-slate-500 hover:bg-slate-800/70 hover:text-slate-500';

function renderIcon(icon: IconLike | undefined, px: number) {
    if (!icon) return null;
    if (isValidElement(icon)) return icon;
    const Icon = icon as ComponentType<{ size?: number; weight?: any; className?: string }>;
    return <Icon size={px} weight="bold" className="shrink-0" />;
}

export function Button(props: ButtonProps) {
    const {
        variant = 'primary',
        size = 'md',
        loading = false,
        icon,
        iconRight,
        iconOnly = false,
        fullWidth = false,
        className,
        children,
        ...rest
    } = props;

    const disabled = Boolean((rest as { disabled?: boolean }).disabled);
    const px = iconPx[size];

    const classes = cn(
        base,
        iconOnly ? iconOnlySizes[size] : sizes[size],
        disabled ? disabledCls : variants[variant],
        fullWidth && 'w-full',
        loading && 'cursor-wait',
        focusRing,
        className,
    );

    // With an icon, the spinner takes the icon's slot. Without one, the label is
    // hidden (still laid out) and the spinner is centred over it — width never changes.
    const leading = loading && icon ? <Spinner size={px} label={null} /> : renderIcon(icon, px);
    const overlaySpinner = loading && !icon;

    const content = (
        <>
            <span className={cn('inline-flex items-center gap-2', overlaySpinner && 'invisible')}>
                {leading}
                {children}
                {renderIcon(iconRight, px)}
            </span>
            {overlaySpinner && (
                <span className="absolute inset-0 flex items-center justify-center">
                    <Spinner size={px} label={null} />
                </span>
            )}
        </>
    );

    if ('href' in props && props.href !== undefined) {
        const { href, external, disabled: _d, ...linkRest } = rest as Omit<ButtonAsLinkProps, keyof ButtonOwnProps>;
        if (disabled || loading) {
            return (
                <span className={classes} aria-disabled="true" aria-busy={loading || undefined} role="link">
                    {content}
                </span>
            );
        }
        if (external) {
            const anchorRest = linkRest as Record<string, unknown>;
            return (
                <a href={href} className={classes} {...(anchorRest as object)}>
                    {content}
                </a>
            );
        }
        const method = (linkRest as { method?: string }).method;
        return (
            <Link
                href={href}
                as={method && method.toLowerCase() !== 'get' ? 'button' : undefined}
                className={classes}
                {...(linkRest as Omit<InertiaLinkProps, 'href'>)}
            >
                {content}
            </Link>
        );
    }

    const { type = 'button', onClick, ...buttonRest } = rest as Omit<ButtonAsButtonProps, keyof ButtonOwnProps>;
    return (
        <button
            type={type}
            className={classes}
            aria-busy={loading || undefined}
            onClick={loading ? (e) => e.preventDefault() : onClick}
            {...buttonRest}
            disabled={disabled}
        >
            {content}
        </button>
    );
}

export default Button;
