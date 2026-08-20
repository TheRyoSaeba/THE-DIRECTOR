import { useState } from 'react';

import { router } from '@inertiajs/react';

import { AnimatePresence, motion } from 'framer-motion';

import { X, Warning, Check, DotsThree } from '@phosphor-icons/react';


const MODAL_SIZES = {

    sm: 'max-w-md',

    md: 'max-w-xl',

    lg: 'max-w-2xl',

    xl: 'max-w-3xl',

    '2xl': 'max-w-4xl',

    '3xl': 'max-w-5xl',

    '4xl': 'max-w-6xl',

} as const;


type ModalSize = keyof typeof MODAL_SIZES;


export function AdminModal({

    isOpen,

    onClose,

    title,

    children,

    size = 'lg',

}: {

    isOpen: boolean;

    onClose: () => void;

    title: string;

    children: React.ReactNode;

    size?: ModalSize;

}) {

    return (

        <AnimatePresence>

            {isOpen && (

                <div className="fixed inset-0 z-[200] flex items-center justify-center p-6">

                    <motion.div

                        initial={{ opacity: 0 }}

                        animate={{ opacity: 1 }}

                        exit={{ opacity: 0 }}

                        onClick={onClose}

                        className="absolute inset-0 bg-slate-950/70"

                    />

                    <motion.div

                        initial={{ opacity: 0, scale: 0.95, y: 20 }}

                        animate={{ opacity: 1, scale: 1, y: 0 }}

                        exit={{ opacity: 0, scale: 0.95, y: 20 }}

                        transition={{ duration: 0.2, ease: 'easeOut' }}

                        className={`relative w-full ${MODAL_SIZES[size]} bg-gradient-to-b from-slate-900 to-slate-950 border border-amber-500/20 rounded-2xl shadow-2xl shadow-amber-500/10 flex flex-col max-h-[85vh]`}

                    >

                        {}

                        <div className="relative h-12 bg-gradient-to-r from-amber-500/10 via-amber-500/5 to-transparent border-b border-amber-500/10 flex items-center justify-between px-6 shrink-0">

                            <h2 className="text-sm font-black uppercase tracking-wider text-white">

                                {title}

                            </h2>

                            <button

                                onClick={onClose}

                                className="w-8 h-8 flex items-center justify-center rounded-lg bg-slate-800/50 hover:bg-red-500/20 text-slate-400 hover:text-red-400 transition"

                            >

                                <X size={16} weight="bold" />

                            </button>

                        </div>


                        <div className="p-6 overflow-y-auto flex-1">{children}</div>

                    </motion.div>

                </div>

            )}

        </AnimatePresence>

    );

}


 

interface ConfirmState {

    isOpen: boolean;

    message: string;

    detail?: string;

    danger?: boolean;

    onConfirm: () => void;

}


export function useConfirm() {

    const [state, setState] = useState<ConfirmState>({

        isOpen: false,

        message: '',

        onConfirm: () => { },

    });


    const confirm = (message: string, onConfirm: () => void, detail?: string, danger = true) => {

        setState({ isOpen: true, message, detail, danger, onConfirm });

    };


    const close = () => setState(s => ({ ...s, isOpen: false }));


    const handleConfirm = () => {

        state.onConfirm();

        close();

    };


    const ConfirmNode = (

        <AnimatePresence>

            {state.isOpen && (

                <div className="fixed inset-0 z-[300] flex items-center justify-center p-4">

                    <motion.div

                        initial={{ opacity: 0 }}

                        animate={{ opacity: 1 }}

                        exit={{ opacity: 0 }}

                        onClick={close}

                        className="absolute inset-0 bg-slate-950/70"

                    />

                    <motion.div

                        initial={{ opacity: 0, scale: 0.95 }}

                        animate={{ opacity: 1, scale: 1 }}

                        exit={{ opacity: 0, scale: 0.95 }}

                        transition={{ duration: 0.15 }}

                        className="relative w-full max-w-sm bg-slate-950 border border-slate-700/60 rounded-xl shadow-2xl overflow-hidden"

                    >

                        <div className={`h-px w-full bg-gradient-to-r from-transparent ${state.danger ? 'via-red-500/60' : 'via-amber-500/50'} to-transparent`} />

                        <div className="p-6 text-center space-y-4">

                            <div className={`w-10 h-10 rounded-full flex items-center justify-center mx-auto border ${state.danger ? 'bg-red-500/10 border-red-500/30' : 'bg-amber-500/10 border-amber-500/30'}`}>

                                <Warning size={20} weight="fill" className={state.danger ? 'text-red-400' : 'text-amber-400'} />

                            </div>

                            <div>

                                <p className="text-sm font-black text-white uppercase tracking-wide">{state.message}</p>

                                {state.detail && <p className="text-xs text-slate-500 mt-1">{state.detail}</p>}

                            </div>

                            <div className="flex gap-2 pt-1">

                                <button

                                    onClick={close}

                                    className="flex-1 h-9 bg-slate-900 border border-slate-700 rounded-lg text-xs font-bold text-slate-400 hover:text-white hover:border-slate-600 transition uppercase tracking-wider"

                                >

                                    Cancel

                                </button>

                                <button

                                    onClick={handleConfirm}

                                    className={`flex-1 h-9 rounded-lg text-xs font-black uppercase tracking-wider transition flex items-center justify-center gap-1.5 ${state.danger

                                        ? 'bg-red-600 hover:bg-red-500 text-white'

                                        : 'bg-amber-600 hover:bg-amber-500 text-white'

                                        }`}

                                >

                                    <Check size={13} weight="bold" /> Confirm

                                </button>

                            </div>

                        </div>

                    </motion.div>

                </div>

            )}

        </AnimatePresence>

    );


    return { confirm, ConfirmNode };

}


const inputBase =

    'w-full px-3 py-2 bg-slate-900 border border-slate-700/60 rounded-lg text-white text-[13px] font-mono placeholder:text-slate-500 focus:outline-none focus:ring-1 focus:ring-amber-500/50 focus:border-amber-500/40 transition';


export function AdminInput({

    label,

    error,

    className = '',

    ...props

}: React.InputHTMLAttributes<HTMLInputElement> & { label?: string; error?: string }) {

    return (

        <div className={className}>

            {label && (

                <label className="block text-[11px] font-black uppercase tracking-widest text-slate-400 mb-1.5">

                    {label}

                </label>

            )}

            <input className={inputBase} {...props} />

            {error && <p className="text-[10px] text-red-400 mt-1">{error}</p>}

        </div>

    );

}


export function AdminTextarea({

    label,

    error,

    rows = 3,

    className = '',

    ...props

}: React.TextareaHTMLAttributes<HTMLTextAreaElement> & { label?: string; error?: string }) {

    return (

        <div className={className}>

            {label && (

                <label className="block text-[11px] font-black uppercase tracking-widest text-slate-400 mb-1.5">

                    {label}

                </label>

            )}

            <textarea rows={rows} className={`${inputBase} resize-y`} {...props} />

            {error && <p className="text-[10px] text-red-400 mt-1">{error}</p>}

        </div>

    );

}


export function AdminSelect({

    label,

    error,

    children,

    className = '',

    ...props

}: React.SelectHTMLAttributes<HTMLSelectElement> & { label?: string; error?: string }) {

    return (

        <div className={className}>

            {label && (

                <label className="block text-[11px] font-black uppercase tracking-widest text-slate-400 mb-1.5">

                    {label}

                </label>

            )}

            <select className={`${inputBase} cursor-pointer`} {...props}>

                {children}

            </select>

            {error && <p className="text-[10px] text-red-400 mt-1">{error}</p>}

        </div>

    );

}


export function AdminToggle({

    label,

    detail,

    checked,

    onChange,

}: {

    label: string;

    detail?: string;

    checked: boolean;

    onChange: (v: boolean) => void;

}) {

    return (

        <div className="flex items-center justify-between p-3 bg-slate-900/60 border border-slate-800/50 rounded-lg">

            <div>

                <span className="text-xs font-bold text-slate-300">{label}</span>

                {detail && <p className="text-[10px] text-slate-600 mt-0.5">{detail}</p>}

            </div>

            <button

                type="button"

                onClick={() => onChange(!checked)}

                className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors shrink-0 ${checked ? 'bg-amber-500' : 'bg-slate-700'}`}

            >

                <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform ${checked ? 'translate-x-[18px]' : 'translate-x-0.5'}`} />

            </button>

        </div>

    );

}


export function RangePair({

    label,

    minValue,

    maxValue,

    onMinChange,

    onMaxChange,

}: {

    label: string;

    minValue: number;

    maxValue: number;

    onMinChange: (v: number) => void;

    onMaxChange: (v: number) => void;

}) {

    return (

        <div>

            <label className="block text-[11px] font-black uppercase tracking-widest text-slate-400 mb-1.5">

                {label}

            </label>

            <div className="grid grid-cols-2 gap-2">

                <div>
                    <div className="text-[9px] font-bold uppercase tracking-wider text-slate-600 mb-0.5">Min</div>
                <input

                    type="number"

                    value={minValue}

                    onChange={e => onMinChange(parseInt(e.target.value) || 0)}

                    placeholder="Min"

                    className={inputBase}

                />

                </div>
                <div>
                    <div className="text-[9px] font-bold uppercase tracking-wider text-slate-600 mb-0.5">Max</div>
                <input

                    type="number"

                    value={maxValue}

                    onChange={e => onMaxChange(parseInt(e.target.value) || 0)}

                    placeholder="Max"

                    className={inputBase}

                />

                </div>
            </div>

        </div>

    );

}


export function StatCard({

    label,

    value,

    sub,

    accent = 'cyan',

}: {

    label: string;

    value: string | number;

    sub?: string;

    accent?: 'cyan' | 'emerald' | 'amber' | 'red' | 'purple';

}) {

    const colors = {

        cyan: 'text-cyan-400 border-cyan-500/20 bg-cyan-500/5',

        emerald: 'text-emerald-400 border-emerald-500/20 bg-emerald-500/5',

        amber: 'text-amber-400 border-amber-500/20 bg-amber-500/5',

        red: 'text-red-400 border-red-500/20 bg-red-500/5',

        purple: 'text-purple-400 border-purple-500/20 bg-purple-500/5',

    };


    return (

        <div className={`rounded-xl p-4 border ${colors[accent]}`}>

            <div className="text-2xl font-black tabular-nums">{value}</div>

            <div className="text-[11px] font-black uppercase tracking-widest text-slate-500 mt-0.5">

                {label}

            </div>

            {sub && <div className="text-xs text-slate-500 mt-0.5">{sub}</div>}

        </div>

    );

}


const BADGE_VARIANTS = {

    green: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',

    red: 'bg-red-500/10 text-red-400 border-red-500/20',

    amber: 'bg-amber-500/10 text-amber-400 border-amber-500/20',

    cyan: 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',

    purple: 'bg-purple-500/10 text-purple-400 border-purple-500/20',

    slate: 'bg-slate-800/60 text-slate-400 border-slate-700/50',

} as const;


export function Badge({

    label,

    variant = 'slate',

}: {

    label: string;

    variant?: keyof typeof BADGE_VARIANTS;

}) {

    return (

        <span className={`inline-flex px-2 py-0.5 rounded-md border text-[9px] font-black uppercase tracking-wider ${BADGE_VARIANTS[variant]}`}>

            {label}

        </span>

    );

}


export function SectionHeader({

    title,

    action,

}: {

    title: string;

    action?: React.ReactNode;

}) {

    return (

        <div className="flex items-center justify-between mb-4">

            <div className="flex items-center gap-2">

                <div className="w-0.5 h-4 bg-cyan-500 rounded-full" />

                <span className="text-[11px] font-black uppercase tracking-widest text-slate-400">

                    {title}

                </span>

            </div>

            {action}

        </div>

    );

}


export function FormActions({

    onCancel,

    onDelete,

    submitLabel = 'Save',

    processing = false,

    danger = false,

}: {

    onCancel: () => void;

    onDelete?: () => void;

    submitLabel?: string;

    processing?: boolean;

    danger?: boolean;

}) {

    return (

        <div className="flex items-center gap-2 pt-4 border-t border-slate-800 mt-4">

            {onDelete && (

                <button

                    type="button"

                    onClick={onDelete}

                    className="mr-auto px-4 h-9 bg-red-500/10 border border-red-500/20 rounded-lg text-xs font-bold text-red-400 hover:bg-red-500/20 transition uppercase tracking-wider"

                >

                    Delete

                </button>

            )}

            <button

                type="button"

                onClick={onCancel}

                className={`px-4 h-9 bg-slate-900 border border-slate-700 rounded-lg text-xs font-bold text-slate-400 hover:text-white hover:border-slate-600 transition uppercase tracking-wider ${!onDelete ? 'ml-auto' : ''

                    }`}

            >

                Cancel

            </button>

            <button

                type="submit"

                disabled={processing}

                className={`px-5 h-9 rounded-lg text-xs font-black uppercase tracking-wider transition flex items-center gap-1.5 disabled:opacity-50 ${danger

                    ? 'bg-red-600 hover:bg-red-500 text-white'

                    : 'bg-amber-600 hover:bg-amber-500 text-white'

                    }`}

            >

                {processing ? (

                    <span className="w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin" />

                ) : (

                    <Check size={12} weight="bold" />

                )}

                {processing ? 'Saving...' : submitLabel}

            </button>

        </div>

    );

}


export function SubTabs({

    tabs,

    active,

    onChange,

}: {

    tabs: { id: string; label: string }[];

    active: string;

    onChange: (id: string) => void;

}) {

    return (

        <div className="flex border-b border-slate-800 mb-5">

            {tabs.map(t => (

                <button

                    key={t.id}

                    onClick={() => onChange(t.id)}

                    className={`px-4 py-2.5 text-[10px] font-black uppercase tracking-widest transition border-b-2 -mb-px ${active === t.id

                        ? 'text-amber-400 border-amber-400'

                        : 'text-slate-600 border-transparent hover:text-slate-400'

                        }`}

                >

                    {t.label}

                </button>

            ))}

        </div>

    );

}


export function AdminTable({

    headers,

    children,

    empty,

}: {

    headers: string[];

    children: React.ReactNode;

    empty?: string;

}) {

    return (

        <div className="bg-slate-950/60 border border-slate-800/50 rounded-xl overflow-hidden">

            <table className="w-full">

                <thead>

                    <tr className="border-b border-slate-800/80">

                        {headers.map((h, i) => (

                            <th key={i} className="px-4 py-2.5 text-left text-[9px] font-black uppercase tracking-widest text-slate-600">

                                {h}

                            </th>

                        ))}

                    </tr>

                </thead>

                <tbody>

                    {children}

                </tbody>

            </table>

            {empty && (

                <div className="py-10 text-center text-xs text-slate-600 font-mono italic">{empty}</div>

            )}

        </div>

    );

}


export const TH = ({

    children,

    className = '',

}: {

    children: React.ReactNode;

    className?: string;

}) => (

    <th

        className={`px-3 py-2.5 text-left text-[11px] font-black uppercase tracking-[0.14em] text-slate-400 whitespace-nowrap ${className}`}

    >

        {children}

    </th>

);


export const TD = ({

    children,

    className = '',

}: {

    children: React.ReactNode;

    className?: string;

}) => <td className={`px-3 py-2.5 text-sm align-middle ${className}`}>{children}</td>;


export function TableShell({

    headers,

    children,

    empty,

}: {

    headers: React.ReactNode[];

    children: React.ReactNode;

    empty?: string;

}) {

    return (

        <div className="rounded-xl border border-slate-800/70 overflow-hidden bg-slate-950/40">

            <div className="overflow-x-auto">

                <table className="w-full">

                    <thead>

                        <tr className="border-b border-slate-800/70 bg-slate-900/60">

                            {headers.map((h, i) =>

                                typeof h === 'string' ? (

                                    <TH key={i}>{h}</TH>

                                ) : (

                                    <th key={i} className="px-3 py-2.5">

                                        {h}

                                    </th>

                                ),

                            )}

                        </tr>

                    </thead>

                    <tbody className="divide-y divide-slate-800/40">{children}</tbody>

                </table>

            </div>

            {empty && (

                <div className="py-10 text-center text-xs text-slate-600 italic">{empty}</div>

            )}

        </div>

    );

}


export function PageControls({

    links,

    className = '',

}: {

    links: any[];

    className?: string;

}) {

    if (!links || links.length <= 3) return null;

    return (

        <div className={`flex items-center justify-center gap-1 ${className}`}>

            {links.map((link: any, i: number) => (

                <button

                    key={i}

                    onClick={() =>

                        link.url && router.visit(link.url, { preserveScroll: true })

                    }

                    disabled={!link.url}

                    className={[

                        'min-w-[32px] h-8 px-2 rounded-lg text-[11px] font-bold transition',

                        link.active

                            ? 'bg-amber-500/20 border border-amber-500/30 text-amber-300'

                            : link.url

                                ? 'bg-slate-900 border border-slate-800 text-slate-500 hover:text-white hover:border-slate-600'

                                : 'text-slate-700 cursor-not-allowed',

                    ].join(' ')}

                    dangerouslySetInnerHTML={{ __html: link.label }}

                />

            ))}

        </div>

    );

}


export function DetailField({

    label,

    value,

    mono = false,

    icon: Icon,

    className = '',

}: {

    label: string;

    value?: string | number | null;

    mono?: boolean;

    icon?: React.ComponentType<{ size?: number; className?: string }>;

    className?: string;

}) {

    return (

        <div className={className}>

            <div className="text-[9px] font-black uppercase tracking-widest text-slate-600 mb-0.5">

                {label}

            </div>

            <div className={`text-xs text-slate-300 flex items-center gap-1.5 ${mono ? 'font-mono' : ''}`}>

                {Icon && <Icon size={12} className="text-slate-500" />}

                {value ?? '—'}

            </div>

        </div>

    );

}