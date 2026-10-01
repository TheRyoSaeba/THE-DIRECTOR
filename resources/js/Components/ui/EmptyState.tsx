import { isValidElement, type ComponentType, type ReactNode } from 'react';
import { cn } from './styles';

export interface EmptyStateProps {
    icon?: ComponentType<{ size?: number | string; weight?: any; className?: string }> | ReactNode;
    title: ReactNode;
    description?: ReactNode;
    /** CTA slot, e.g. a <Button>. */
    action?: ReactNode;
    /** Tighter vertical padding for use inside lists / panels. */
    compact?: boolean;
    className?: string;
}

/** Replaces Law/Police `Empty` and the many "No X yet" blocks. Text meets contrast (slate-300/400). */
export function EmptyState({ icon, title, description, action, compact, className }: EmptyStateProps) {
    let iconNode: ReactNode = null;
    if (icon) {
        if (isValidElement(icon)) iconNode = icon;
        else if (typeof icon === 'function' || typeof icon === 'object') {
            const Icon = icon as ComponentType<{ size?: number; weight?: any; className?: string }>;
            iconNode = <Icon size={compact ? 28 : 40} weight="duotone" className="text-slate-500" />;
        }
    }

    return (
        <div className={cn('flex flex-col items-center justify-center gap-3 text-center', compact ? 'py-8' : 'py-16', className)}>
            {iconNode && <span aria-hidden>{iconNode}</span>}
            <p className="text-sm font-black uppercase tracking-[0.16em] text-slate-300">{title}</p>
            {description && <p className="max-w-xs text-xs text-slate-400">{description}</p>}
            {action && <div className="mt-1">{action}</div>}
        </div>
    );
}

export default EmptyState;
