import { X } from '@phosphor-icons/react';
import { motion, AnimatePresence } from 'framer-motion';
import React, { ReactNode } from 'react';

interface Badge {
    text: string;
    color: 'purple' | 'emerald' | 'amber' | 'red' | 'cyan' | 'slate';
}

interface StyledModalProps {
    isOpen: boolean;
    onClose: () => void;
    headerImage?: string;
    headerIcon?: ReactNode;
    headerGradient?: string;
    title: string;
    subtitle?: string;
    badges?: Badge[];
    children: ReactNode;
    maxWidth?: string;
    borderColor?: string;
}

export default function StyledModal({
    isOpen,
    onClose,
    headerImage,
    headerIcon,
    headerGradient = 'from-slate-900 via-transparent to-transparent',
    title,
    subtitle,
    badges = [],
    children,
    maxWidth = 'max-w-md',
    borderColor = 'border-slate-700'
}: StyledModalProps) {
    const badgeColors: Record<Badge['color'], string> = {
        purple: 'bg-purple-500/20 text-purple-400 border-purple-500/30',
        emerald: 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30',
        amber: 'bg-amber-500/20 text-amber-400 border-amber-500/30',
        red: 'bg-red-500/20 text-red-400 border-red-500/30',
        cyan: 'bg-cyan-500/20 text-cyan-400 border-cyan-500/30',
        slate: 'bg-slate-800 text-slate-400 border-slate-700',
    };

    return (
        <AnimatePresence>
            {isOpen && (
                // Wrapper catches outside clicks for close behavior but paints
                // nothing — the page underneath stays at full brightness.
                <div className="fixed inset-0 z-[100] flex items-center justify-center p-4 pointer-events-none">
                    <div
                        className="absolute inset-0 pointer-events-auto"
                        onClick={onClose}
                    />
                    <motion.div
                        initial={{ opacity: 0, scale: 0.95, y: 20 }}
                        animate={{ opacity: 1, scale: 1, y: 0 }}
                        exit={{ opacity: 0, scale: 0.95, y: 20 }}
                        className={`relative pointer-events-auto bg-slate-900 border ${borderColor} w-full ${maxWidth} rounded-2xl overflow-hidden shadow-2xl z-10`}
                    >
                        <div className="relative aspect-video bg-slate-950 flex items-center justify-center">
                            <div className={`absolute inset-0 bg-gradient-to-t ${headerGradient}`} />

                            {headerImage ? (
                                <img
                                    src={headerImage}
                                    alt={title}
                                    className="w-full h-full object-cover opacity-80"
                                />
                            ) : headerIcon ? (
                                <div className="relative z-10">{headerIcon}</div>
                            ) : null}

                            <button
                                onClick={onClose}
                                className="absolute top-4 right-4 p-2 bg-black/50 hover:bg-black/80 text-white rounded-full transition-colors"
                            >
                                <X size={18} />
                            </button>

                            <div className="absolute bottom-4 left-6">
                                <h3 className="text-xl font-black text-white uppercase tracking-tight">
                                    {title}
                                </h3>
                                {subtitle && (
                                    <p className="text-xs text-slate-400 mt-1">{subtitle}</p>
                                )}
                                {badges.length > 0 && (
                                    <div className="flex gap-2 mt-1">
                                        {badges.map((badge, i) => (
                                            <span
                                                key={i}
                                                className={`text-[10px] font-black px-2 py-0.5 rounded border uppercase tracking-widest ${badgeColors[badge.color] || badgeColors.slate
                                                    }`}
                                            >
                                                {badge.text}
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>

                        {children}
                    </motion.div>
                </div>
            )}
        </AnimatePresence>
    );
}

interface InfoCardProps {
    label: string;
    children: ReactNode;
}

export function InfoCard({ label, children }: InfoCardProps) {
    return (
        <div className="bg-slate-950/50 p-3 rounded-xl border border-slate-800">
            <div className="text-[10px] text-slate-500 font-bold uppercase tracking-wider mb-1">
                {label}
            </div>
            {children}
        </div>
    );
}

interface ActionButtonProps {
    onClick?: () => void;
    icon?: any;
    children: ReactNode;
    variant?: 'primary' | 'secondary' | 'success' | 'warning' | 'danger' | 'disabled' | 'cyan';
    disabled?: boolean;
    type?: 'button' | 'submit';
    className?: string;
}

export function ActionButton({
    onClick,
    icon: Icon,
    children,
    variant = 'primary',
    disabled = false,
    type = 'button',
    className = ''
}: ActionButtonProps) {
    const variants = {
        primary: 'bg-white text-slate-950 hover:bg-purple-400',
        secondary: 'bg-slate-800 border border-slate-700 text-slate-400 hover:bg-slate-700',
        success: 'bg-emerald-600 hover:bg-emerald-500 text-white',
        warning: 'bg-amber-600 hover:bg-amber-500 text-white',
        danger: 'bg-red-950/30 border border-red-900/50 text-red-500 hover:bg-red-500 hover:text-white',
        cyan: 'bg-cyan-600 hover:bg-cyan-500 text-white',
        disabled: 'bg-slate-800 text-slate-500 border border-slate-700 cursor-not-allowed',
    };

    return (
        <button
            type={type}
            onClick={onClick}
            disabled={disabled}
            className={`h-12 flex items-center justify-center gap-2 rounded-xl text-xs font-black uppercase tracking-[0.2em] transition-all ${disabled ? variants.disabled : (variants[variant] || variants.primary)
                } ${className}`}
        >
            {Icon && <Icon size={14} />}
            {children}
        </button>
    );
}


interface StyledInputProps {
    label: string;
    type?: string;
    value: string | number;
    onChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
    placeholder?: string;
    prefix?: string;
    suffix?: string;
    hint?: string;
    required?: boolean;
    min?: number;
    maxLength?: number;
}

export function StyledInput({
    label,
    type = 'text',
    value,
    onChange,
    placeholder,
    prefix,
    suffix,
    hint,
    required = false,
    min,
    maxLength,
}: StyledInputProps) {
    return (
        <div className="bg-slate-950/50 p-4 rounded-xl border border-slate-800">
            <label className="text-[10px] text-slate-500 font-bold uppercase tracking-wider mb-2 block">
                {label}
            </label>
            <div className="relative">
                {prefix && (
                    <div className="absolute left-3 top-1/2 -translate-y-1/2 text-emerald-400 font-bold">
                        {prefix}
                    </div>
                )}
                <input
                    type={type}
                    value={value}
                    onChange={onChange}
                    placeholder={placeholder}
                    required={required}
                    min={min}
                    maxLength={maxLength}
                    className={`w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-white text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition ${prefix ? 'pl-8' : ''} ${suffix ? 'pr-8' : ''}`}
                />
                {suffix && (
                    <div className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 font-bold">
                        {suffix}
                    </div>
                )}
            </div>
            {hint && (
                <p className="mt-2 text-xs text-slate-500 italic">{hint}</p>
            )}
        </div>
    );
}
