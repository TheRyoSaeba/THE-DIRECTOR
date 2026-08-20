import { useState, useMemo, useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
import GameLayout from '@/Layouts/GameLayout';
import { motion, AnimatePresence } from 'framer-motion';
import { parseSymbolicAmount } from '@/Layouts/GameLayoutComponents';
import {
    X, Users, CheckCircle, ArrowRight, Prohibit,
    UserCircle, CaretDown, CaretUp, CaretLeft, CaretRight, Storefront, Scales,
    Crosshair, Target,
    Skull, CurrencyDollar, Vault, Warning, Buildings, Shield, Files, Shuffle, CurrencyBtcIcon, DesktopIcon, PillIcon, PrescriptionIcon
} from '@phosphor-icons/react';

// ─── Types ────────────────────────────────────────────────────────────────────

interface ActiveMember {
    id: number;
    name: string;
    accepted: boolean;
}

interface Selectable {
    id: string | number;
    name: string;
    image_url?: string | null;
    maxValue?: number;
    maxPacks?: number;
    badge?: string;
    amount_sent?: number;
    payout_per_pack?: number;
}

interface PendingMedicineOffer {
    request_key: string;
    buyer_id: number;
    buyer_name: string;
    buyer_avatar_url?: string | null;
    product: string;
    product_name: string;
    product_image_url?: string | null;
    pack_count: number;
    price: number;
    expires_at: number;
    status: 'waiting';
}

interface ActionShape {
    id: string;
    title: string;

    category: string;
    description: string;
    image_url: string | null;
    icon: string;
    button_label: string;
    group_init_label: string | null;
    execute_route: string;
    cancel_route: string | null;
    refund_route?: string | null;
    is_group_action: boolean;
    available?: boolean;
    blocker?: string | null;
    is_waiting: boolean;
    is_ready: boolean;
    active_members: ActiveMember[] | null;
    pending_offers?: PendingMedicineOffer[];
    has_amount_input?: number | boolean;
    amount_label?: string;
    has_product_input?: boolean;
    product_label?: string;
    has_pack_input?: boolean;
    pack_label?: string;
    pick_label?: string;
    target_icon?: 'user' | 'store' | 'scales';
    targets?: Selectable[];
    products?: Selectable[];
    accomplices?: Selectable[];
}

// ─── Icon resolver ────────────────────────────────────────────────────────────

const ICON_MAP: Record<string, React.ElementType> = {
    Skull, CurrencyDollar, Vault, Warning, Buildings, Shield,
    Files, Shuffle, Scales, Crosshair, Users, Target, CurrencyBtcIcon, DesktopIcon, PrescriptionIcon, PillIcon
};

const ACTIONS_PER_PAGE = 6;
const PICKER_SCROLL_HINT_THRESHOLD = 6;

function resolveIcon(iconName: string | null | undefined): React.ElementType {
    if (!iconName) return Crosshair;
    return ICON_MAP[iconName] ?? Crosshair;
}

// ─── Sub-components ───────────────────────────────────────────────────────────

function TargetSelectIcon({ icon, size, weight, className }: {
    icon?: 'user' | 'store' | 'scales';
    size: number;
    weight?: 'regular' | 'fill' | 'bold';
    className?: string;
}) {
    if (icon === 'store') return <Storefront size={size} weight={weight ?? 'regular'} className={className} />;
    if (icon === 'scales') return <Scales size={size} weight={weight ?? 'regular'} className={className} />;
    return <UserCircle size={size} weight={weight ?? 'regular'} className={className} />;
}

function MemberRoster({ members }: { members: ActiveMember[] }) {
    return (
        <div className="flex flex-wrap gap-2">
            {members.map(m => (
                <div
                    key={m.id}
                    className={`px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase tracking-wider border ${m.accepted
                        ? 'bg-emerald-500/15 text-emerald-400 border-emerald-600/40'
                        : 'bg-slate-800/60 text-slate-500 border-slate-700/60'
                        }`}
                >
                    {m.accepted && <CheckCircle size={9} weight="fill" className="inline mr-1 mb-0.5" />}
                    {m.name}
                </div>
            ))}
        </div>
    );
}

function usePickerScrollHint(isOpen: boolean, optionCount: number) {
    const [showHint, setShowHint] = useState(false);

    useEffect(() => {
        setShowHint(isOpen && optionCount > PICKER_SCROLL_HINT_THRESHOLD);
    }, [isOpen, optionCount]);

    const onScroll = (event: React.UIEvent<HTMLDivElement>) => {
        const el = event.currentTarget;
        setShowHint(el.scrollHeight - el.scrollTop - el.clientHeight > 8);
    };

    return { showHint, onScroll };
}

function PickerScrollHint({ show }: { show: boolean }) {
    if (!show) return null;

    return (
        <div className="pointer-events-none absolute inset-x-0 bottom-0 flex justify-center bg-gradient-to-t from-slate-950 via-slate-950/95 to-transparent px-3 pb-2 pt-8">
            <span className="rounded-full border border-cyan-400/25 bg-slate-950/90 px-2.5 py-1 text-[9px] font-black uppercase tracking-[0.22em] text-cyan-300">
                Scroll for more
            </span>
        </div>
    );
}

// ── Single-select dropdown ────────────────────────────────────────────────────

interface SinglePickerProps {
    label: string;
    options: Selectable[];
    selectedId: string | number | null;
    onSelect: (id: string | number) => void;
    icon?: 'user' | 'store' | 'scales';
    isOpen: boolean;
    onToggle: () => void;
}

function SinglePicker({ label, options, selectedId, onSelect, icon, isOpen, onToggle }: SinglePickerProps) {
    const selected = options.find(o => o.id === selectedId);
    const scrollHint = usePickerScrollHint(isOpen, options.length);

    return (
        <div className="space-y-1.5">
            <label className="text-[9px] uppercase font-black tracking-widest text-slate-500">{label}</label>
            <div className="relative">
                <button
                    type="button"
                    onClick={onToggle}
                    className={`w-full flex items-center justify-between gap-3 px-4 py-3 rounded-xl border text-sm font-bold transition-all ${selectedId !== null
                        ? 'bg-slate-800/80 border-cyan-600/50 text-white'
                        : 'bg-slate-800/50 border-slate-600/50 text-slate-400 hover:border-slate-500/60'
                        }`}
                >
                    <span className="truncate min-w-0">{selected?.name ?? 'Choose…'}</span>
                    {isOpen
                        ? <CaretUp size={12} weight="bold" className="text-slate-400 shrink-0" />
                        : <CaretDown size={12} weight="bold" className="text-slate-400 shrink-0" />
                    }
                </button>

                <AnimatePresence>
                    {isOpen && (
                        <motion.div
                            initial={{ opacity: 0, y: -6 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: -6 }}
                            transition={{ duration: 0.12 }}
                            className="absolute z-20 w-full mt-1.5 bg-slate-950 border border-slate-700 rounded-xl overflow-hidden shadow-2xl"
                        >
                            <div
                                className={`max-h-80 overflow-y-auto ${options.length > PICKER_SCROLL_HINT_THRESHOLD ? 'pb-7' : ''}`}
                                onScroll={scrollHint.onScroll}
                            >
                                {options.length === 0 && (
                                    <div className="px-4 py-3 text-xs text-slate-600 italic">No options available</div>
                                )}
                                {options.map(opt => (
                                    <button
                                        key={String(opt.id)}
                                        type="button"
                                        onClick={() => { onSelect(opt.id); onToggle(); }}
                                        className={`w-full flex items-center gap-3 px-4 py-2.5 text-sm text-left transition-colors ${opt.id === selectedId
                                            ? 'bg-cyan-500/10 text-cyan-300'
                                            : 'hover:bg-slate-800/80 text-slate-300'
                                            }`}
                                    >
                                        <div className="flex-1 min-w-0">
                                            <div className="font-semibold">{opt.name}</div>

                                        </div>
                                        {opt.id === selectedId && (
                                            <CheckCircle size={12} weight="fill" className="text-cyan-400 shrink-0" />
                                        )}
                                    </button>
                                ))}
                            </div>
                            <PickerScrollHint show={scrollHint.showHint} />
                        </motion.div>
                    )}
                </AnimatePresence>
            </div>

        </div>
    );
}

// ── Multi-select crew picker ──────────────────────────────────────────────────

interface CrewPickerProps {
    options: Selectable[];
    selectedIds: number[];
    onToggle: (id: number) => void;
    isOpen: boolean;
    onOpenToggle: () => void;
}

function CrewPicker({ options, selectedIds, onToggle, isOpen, onOpenToggle }: CrewPickerProps) {
    const scrollHint = usePickerScrollHint(isOpen, options.length);

    return (
        <div className="space-y-1.5">
            <label className="text-[9px] uppercase font-black tracking-widest text-slate-500">Select Crew</label>
            <div className="relative">
                <button
                    type="button"
                    onClick={onOpenToggle}
                    className={`w-full flex items-center justify-between gap-3 px-4 py-3 rounded-xl border text-sm font-bold transition-all ${selectedIds.length > 0
                        ? 'bg-slate-800/80 border-rose-600/50 text-white'
                        : 'bg-slate-800/50 border-slate-600/50 text-slate-400 hover:border-slate-500/60'
                        }`}
                >
                    <span className="flex items-center gap-2.5">
                        <Users
                            size={15}
                            weight={selectedIds.length > 0 ? 'fill' : 'regular'}
                            className={selectedIds.length > 0 ? 'text-rose-400' : 'text-slate-500'}
                        />
                        <span>
                            {selectedIds.length > 0
                                ? `${selectedIds.length} crew member${selectedIds.length !== 1 ? 's' : ''} selected`
                                : 'Choose your crew…'
                            }
                        </span>
                    </span>
                    {isOpen
                        ? <CaretUp size={12} weight="bold" className="text-slate-400" />
                        : <CaretDown size={12} weight="bold" className="text-slate-400" />
                    }
                </button>

                <AnimatePresence>
                    {isOpen && (
                        <motion.div
                            initial={{ opacity: 0, y: -6 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: -6 }}
                            transition={{ duration: 0.12 }}
                            className="absolute z-20 w-full mt-1.5 bg-slate-950 border border-slate-700 rounded-xl overflow-hidden shadow-2xl"
                        >
                            <div
                                className={`max-h-80 overflow-y-auto ${options.length > PICKER_SCROLL_HINT_THRESHOLD ? 'pb-7' : ''}`}
                                onScroll={scrollHint.onScroll}
                            >
                                {options.length === 0 && (
                                    <div className="px-4 py-3 text-xs text-slate-600 italic">No crew available</div>
                                )}
                                {options.map(opt => {
                                    const picked = selectedIds.includes(Number(opt.id));
                                    return (
                                        <button
                                            key={String(opt.id)}
                                            type="button"
                                            onClick={() => onToggle(Number(opt.id))}
                                            className={`w-full flex items-center gap-3 px-4 py-2.5 text-sm text-left transition-colors ${picked
                                                ? 'bg-rose-500/10 text-rose-300'
                                                : 'hover:bg-slate-800/80 text-slate-300'
                                                }`}
                                        >
                                            <UserCircle
                                                size={13}
                                                weight={picked ? 'fill' : 'regular'}
                                                className={`shrink-0 ${picked ? 'text-rose-400' : 'text-slate-500'}`}
                                            />
                                            <span className="flex-1 break-words">{opt.name}</span>
                                            {picked && <CheckCircle size={12} weight="fill" className="text-rose-400 shrink-0" />}
                                        </button>
                                    );
                                })}
                            </div>
                            <PickerScrollHint show={scrollHint.showHint} />
                        </motion.div>
                    )}
                </AnimatePresence>
            </div>

            {selectedIds.length > 0 && (
                <div className="flex flex-wrap gap-1.5 pt-0.5">
                    {selectedIds.map(id => {
                        const opt = options.find(o => Number(o.id) === id);
                        if (!opt) return null;
                        return (
                            <span
                                key={id}
                                className="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded-md bg-rose-500/10 text-rose-400 border border-rose-500/30"
                            >
                                {opt.name}
                                <button type="button" onClick={() => onToggle(id)} className="hover:text-white ml-0.5">
                                    <X size={9} weight="bold" />
                                </button>
                            </span>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

function PackCountPicker({
    label,
    value,
    max,
    disabled,
    onChange,
}: {
    label: string;
    value: number;
    max: number;
    disabled: boolean;
    onChange: (value: number) => void;
}) {
    const activeValue = Math.min(value, max);

    return (
        <div className="space-y-1.5">
            <div className="flex items-center justify-between gap-3">
                <label className="text-[9px] font-black uppercase tracking-widest text-slate-500">{label}</label>
                <span className="text-[9px] font-bold uppercase tracking-wider text-slate-600">3 doses each</span>
            </div>
            <div className="grid grid-cols-2 gap-2">
                {[1, 2].map(count => {
                    const optionDisabled = disabled || count > max;
                    const active = activeValue === count && !disabled;

                    return (
                        <button
                            key={count}
                            type="button"
                            disabled={optionDisabled}
                            onClick={() => onChange(count)}
                            className={`h-12 rounded-lg border px-3 text-[11px] font-black uppercase tracking-widest transition-colors ${active
                                ? 'border-emerald-500/50 bg-emerald-500/15 text-emerald-300'
                                : 'border-slate-700/60 bg-slate-900/70 text-slate-400 hover:border-slate-500/70 hover:text-slate-200'
                                } disabled:cursor-not-allowed disabled:border-slate-800 disabled:bg-slate-900/40 disabled:text-slate-700`}
                        >
                            {count} Pack{count === 1 ? '' : 's'}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

function ProductPicker({
    label,
    products,
    selectedId,
    onSelect,
}: {
    label: string;
    products: Selectable[];
    selectedId: string | number | null;
    onSelect: (id: string | number) => void;
}) {
    return (
        <div className="space-y-1.5">
            <label className="block text-[9px] font-black uppercase tracking-widest text-slate-500">{label}</label>
            <div className="grid gap-1.5 sm:grid-cols-2">
                {products.map(product => {
                    const active = product.id === selectedId;

                    return (
                        <button
                            key={String(product.id)}
                            type="button"
                            onClick={() => onSelect(product.id)}
                            className={`flex h-10 items-center gap-2 rounded-md border px-2 text-left transition-colors ${active
                                ? 'border-cyan-500/40 bg-cyan-500/[0.06] text-cyan-200'
                                : 'border-slate-800/70 bg-slate-950/30 text-slate-300 hover:border-slate-600/70 hover:text-white'
                                }`}
                        >
                            {product.image_url ? (
                                <img
                                    src={product.image_url}
                                    alt={product.name}
                                    className="h-9 w-9 shrink-0 object-contain"
                                />
                            ) : (
                                <span className="h-8 w-8 shrink-0 rounded-md border border-slate-800/70" />
                            )}
                            <span className="min-w-0 truncate text-[11px] font-black uppercase tracking-widest">{product.name}</span>
                        </button>
                    );
                })}
            </div>
            {products.length === 0 && (
                <p className="text-xs text-slate-600 italic">No medicine available.</p>
            )}
        </div>
    );
}

// ─── Main page ────────────────────────────────────────────────────────────────

function PendingMedicineOffers({
    offers,
    cancelRoute,
    cancelingId,
    onCancel,
}: {
    offers: PendingMedicineOffer[];
    cancelRoute: string | null;
    cancelingId: string | null;
    onCancel: (requestKey: string) => void;
}) {
    if (offers.length === 0) return null;

    const offer = offers[0]!;
    const cancelKey = `medicine:${offer.request_key}`;
    const canceling = cancelingId === cancelKey;

    return (
        <div className="overflow-hidden rounded-xl border border-cyan-500/20 bg-cyan-500/[0.04]">
            <div className="grid gap-3 p-3 sm:grid-cols-[1fr_auto] sm:items-center">
                <div className="flex min-w-0 items-center gap-3">
                    <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg border border-slate-700/70 bg-slate-950/70">
                        {offer.product_image_url ? (
                            <img
                                src={offer.product_image_url}
                                alt={offer.product_name}
                                className="h-12 w-12 object-contain"
                            />
                        ) : (
                            <Files size={18} className="text-slate-500" />
                        )}
                    </div>
                    <div className="min-w-0">
                        <p className="text-[9px] font-black uppercase tracking-[0.24em] text-cyan-300">
                            Offer Sent To {offer.buyer_name}
                        </p>
                        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                            <p className="truncate text-sm font-black uppercase tracking-wide text-white">{offer.product_name}</p>
                            <span className="rounded border border-slate-700/70 px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wider text-slate-400">
                                {offer.pack_count} Pack{offer.pack_count === 1 ? '' : 's'}
                            </span>
                        </div>
                    </div>
                </div>
                <div className="flex items-center justify-between gap-3 sm:justify-end">
                    {cancelRoute && (
                        <button
                            type="button"
                            onClick={() => onCancel(offer.request_key)}
                            disabled={canceling}
                            className="h-9 rounded-lg border border-slate-700 bg-slate-900 px-3 text-[10px] font-black uppercase tracking-widest text-slate-300 transition-colors hover:border-rose-500/50 hover:text-rose-300 disabled:cursor-wait disabled:opacity-50"
                        >
                            {canceling ? 'Cancelling' : 'Cancel'}
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}

interface ActionsProps {
    actions: ActionShape[];
}

export default function Actions({ actions }: ActionsProps) {
    const [selectedActionId, setSelectedActionId] = useState<string | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [actionPage, setActionPage] = useState(1);
    const [processingId, setProcessingId] = useState<string | null>(null);
    const [cancelingId, setCancelingId] = useState<string | null>(null);
    const [refundingId, setRefundingId] = useState<string | null>(null);
    const [targetId, setTargetId] = useState<string | number | null>(null);
    const [productId, setProductId] = useState<string | number | null>(null);
    const [amount, setAmount] = useState<string>('');
    const [packCount, setPackCount] = useState(1);
    const [accompliceIds, setAccompliceIds] = useState<number[]>([]);
    const [pickerOpen, setPickerOpen] = useState<'target' | 'crew' | null>(null);

    // Reload actions on window focus so max capacity / bank balance stays current
    // without forcing the user to manually refresh the page.
    useEffect(() => {
        const onFocus = () => {
            if (processingId === null) {
                router.reload({ only: ['actions'] });
            }
        };
        window.addEventListener('focus', onFocus);
        return () => window.removeEventListener('focus', onFocus);
    }, [processingId]);

    const selectedAction = actions.find(a => a.id === selectedActionId) ?? null;

    // Group action in ready_for_execution phase — crew committed, just hit execute.
    const isGroupExecutePhase = !!(selectedAction?.is_group_action && selectedAction?.is_ready);
    const pendingMedicineOffers = selectedAction?.id === 'medicine_sale'
        ? (selectedAction.pending_offers ?? [])
        : [];
    const isMedicineWaiting = pendingMedicineOffers.length > 0;

    // Pickers/inputs — always show unless it's a group action already committed.
    const isGroupPending = !!(selectedAction?.is_group_action && (selectedAction?.is_waiting || selectedAction?.is_ready));
    const isInputLocked = isGroupPending || isMedicineWaiting;
    const hasTargets = !isInputLocked && selectedAction?.targets != null;
    const hasAccomplices = !isInputLocked && selectedAction?.accomplices != null;
    const hasAmountInput = !isInputLocked && !!selectedAction?.has_amount_input;
    const hasProductInput = !isInputLocked && !!selectedAction?.has_product_input;
    const hasPackInput = !isInputLocked && !!selectedAction?.has_pack_input;

    const targetOptions = (selectedAction?.targets ?? []).filter(t => !accompliceIds.includes(Number(t.id)));
    const accompliceOptions = (selectedAction?.accomplices ?? []).filter(a => Number(a.id) !== targetId);
    const selectedTarget = selectedAction?.targets?.find(t => t.id === targetId) ?? null;
    const productOptions = selectedAction?.products ?? [];
    const selectedProduct = productOptions.find(product => product.id === productId) ?? null;
    const maxPackCount = Math.max(
        1,
        Math.min(
            2,
            Number(selectedProduct?.maxPacks ?? 1)
        )
    );
    const effectivePackCount = hasPackInput ? Math.min(packCount, maxPackCount) : packCount;
    const parsedAmount = parseSymbolicAmount(amount);

    // Can always execute — backend validates. Only block during processing or missing required inputs.
    const canExecute = !!(
        selectedAction &&
        processingId === null &&
        !isMedicineWaiting &&
        !(hasTargets && targetId === null) &&
        !(hasProductInput && productId === null) &&
        !(hasAmountInput && parsedAmount <= 0) &&
        !(hasAccomplices && accompliceIds.length < 1)
    );

    const executeLabel = (): string => {
        if (!selectedAction) return '...';
        if (isGroupExecutePhase) return selectedAction.button_label ?? 'Execute Operation';
        if (isMedicineWaiting) return 'Waiting for Buyer';
        if (selectedAction.is_group_action && selectedAction.is_waiting) {
            const accepted = selectedAction.active_members?.filter(m => m.accepted).length ?? 0;
            const total = selectedAction.active_members?.length ?? 0;
            return `Waiting for Crew (${accepted}/${total} accepted)…`;
        }
        if (hasTargets && targetOptions.length === 0) return 'No targets available';
        if (hasTargets && targetId === null) return selectedAction.pick_label ?? 'Select a Target First';
        if (hasProductInput && productOptions.length === 0) return 'No medicine available';
        if (hasProductInput && productId === null) return 'Select Medicine';
        if (hasAmountInput && parsedAmount <= 0) return 'Enter an Amount';
        if (hasAccomplices && accompliceOptions.length === 0) return 'No crew available';
        if (hasAccomplices && accompliceIds.length < 1) return 'Select Your Crew First';
        if (selectedAction.is_group_action) return selectedAction.group_init_label ?? 'Launch Operation';
        return selectedAction.button_label ?? 'Execute';
    };

    // ── Filtering & grouping ─────────────────────────────────────────────────

    const filteredActions = useMemo(() => {
        if (!searchQuery.trim()) return actions;
        const q = searchQuery.toLowerCase();
        return actions.filter(a =>
            a.title.toLowerCase().includes(q) ||
            a.category.toLowerCase().includes(q)
        );
    }, [actions, searchQuery]);

    const totalActionPages = Math.max(1, Math.ceil(filteredActions.length / ACTIONS_PER_PAGE));
    const currentActionPage = Math.min(actionPage, totalActionPages);
    const visibleActions = useMemo(() => {
        const start = (currentActionPage - 1) * ACTIONS_PER_PAGE;

        return filteredActions.slice(start, start + ACTIONS_PER_PAGE);
    }, [filteredActions, currentActionPage]);
    const actionRangeStart = filteredActions.length === 0 ? 0 : (currentActionPage - 1) * ACTIONS_PER_PAGE + 1;
    const actionRangeEnd = Math.min(filteredActions.length, currentActionPage * ACTIONS_PER_PAGE);

    const groupedActions = useMemo(() =>
        visibleActions.reduce<Record<string, ActionShape[]>>((acc, a) => {
            (acc[a.category] ??= []).push(a);
            return acc;
        }, {}),
        [visibleActions]);

    useEffect(() => {
        setActionPage(1);
    }, [searchQuery]);

    useEffect(() => {
        setActionPage(page => Math.min(page, totalActionPages));
    }, [totalActionPages]);

    useEffect(() => {
        if (!hasPackInput) return;

        if (targetId === null || productId === null) {
            if (packCount !== 1) setPackCount(1);
            return;
        }

        if (packCount > maxPackCount) {
            setPackCount(maxPackCount);
        }
    }, [hasPackInput, maxPackCount, packCount, productId, targetId]);

    // ── Event handlers ───────────────────────────────────────────────────────

    const resetForm = () => {
        setTargetId(null);
        setProductId(null);
        setAmount('');
        setPackCount(1);
        setAccompliceIds([]);
        setPickerOpen(null);
    };

    const selectAction = (id: string | null) => {
        setSelectedActionId(id);
        resetForm();
    };

    const toggleAccomplice = (id: number) =>
        setAccompliceIds(prev =>
            prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]
        );

    const handleExecute = () => {
        if (!selectedAction || !canExecute) return;
        setProcessingId(selectedAction.id);

        const payload: Record<string, unknown> = {};
        if (targetId !== null) payload.target_id = targetId;
        if (productId !== null) payload.product = productId;
        if (amount) payload.amount = parsedAmount;
        if (hasPackInput) payload.pack_count = effectivePackCount;
        if (accompliceIds.length) payload.accomplice_ids = accompliceIds;

        router.post(selectedAction.execute_route, payload as any, {
            preserveScroll: true,
            only: ['actions', 'flash', 'auth'],
            onFinish: () => setProcessingId(null),
        });
    };

    const handleCancel = () => {
        if (!selectedAction?.cancel_route || cancelingId || processingId) return;
        setCancelingId(selectedAction.id);
        const cancelUrl = selectedAction.cancel_route.replace('/0/', `/${String(targetId ?? 0)}/`);
        router.post(cancelUrl, {}, {
            preserveScroll: true,
            only: ['actions', 'flash', 'auth'],
            onFinish: () => { setCancelingId(null); setTargetId(null); },
        });
    };

    const handleCancelMedicineOffer = (requestKey: string) => {
        if (!selectedAction?.cancel_route || cancelingId || processingId) return;
        const cancelKey = `medicine:${requestKey}`;

        setCancelingId(cancelKey);
        router.post(selectedAction.cancel_route, { request_key: requestKey } as any, {
            preserveScroll: true,
            only: ['actions', 'flash', 'auth'],
            onFinish: () => setCancelingId(null),
        });
    };

    const handleRefund = () => {
        if (!selectedAction?.refund_route || refundingId || !targetId) return;
        setRefundingId(selectedAction.id);
        router.post(selectedAction.refund_route, { target_id: targetId } as any, {
            preserveScroll: true,
            only: ['actions', 'flash', 'auth'],
            onFinish: () => setRefundingId(null),
        });
    };

    // ── Render ───────────────────────────────────────────────────────────────

    return (
        <>
            <Head title="Actions" />
            <div className="max-w-6xl mx-auto px-4 sm:px-6 py-6 space-y-5">

                {/* ── Hero ────────────────────────────────────────────────── */}
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.45 }}
                    className="relative rounded-[2rem] overflow-hidden border border-slate-800 shadow-2xl bg-slate-900 h-60 sm:h-72"
                >
                    <div
                        className="absolute inset-0 bg-cover bg-center"
                        style={{ backgroundImage: `url('https://images.thedirector.app/actions/action.png')` }}
                    />
                    <div className="absolute inset-0 bg-gradient-to-t from-slate-950/95 via-slate-950/40 to-transparent" />
                    <div className="relative h-full p-6 sm:p-8 flex flex-col justify-end">
                        <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                            <div className="space-y-2">
                                <div className="flex items-center gap-3">


                                </div>
                                <h1 className="text-3xl sm:text-4xl font-black text-white tracking-tight">
                                    CHARACTER <span className="text-cyan-400 italic">ACTIONS</span>
                                </h1>
                            </div>

                        </div>
                    </div>
                </motion.div>

                {/* ── Two-panel layout ────────────────────────────────────── */}
                <div className="flex flex-col lg:flex-row gap-5">

                    {/* Left panel — action list */}
                    <motion.div
                        initial={{ opacity: 0, x: -16 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.15, duration: 0.4 }}
                        className="lg:w-80 shrink-0"
                    >
                        <div className="bg-slate-900/60 border border-slate-800/50 rounded-2xl overflow-hidden shadow-xl">
                            {/* Search bar */}
                            <div className="p-4 border-b border-slate-800/50">
                                <div className="relative">
                                    <Target size={13} weight="bold" className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500" />
                                    <input
                                        type="text"
                                        value={searchQuery}
                                        onChange={e => setSearchQuery(e.target.value)}
                                        placeholder="Search operations…"
                                        className="w-full pl-8 pr-4 py-2 bg-slate-950/50 border border-slate-700/50 rounded-lg text-sm text-white placeholder:text-slate-600 focus:outline-none focus:border-cyan-500/40"
                                    />
                                </div>
                            </div>

                            {/* Grouped action list */}
                            <div className="divide-y divide-slate-800/30">
                                {Object.entries(groupedActions).map(([category, list]) => (
                                    <div key={category}>
                                        <div className="px-4 py-2 bg-slate-950/60">
                                            <span className="text-[9px] font-black uppercase tracking-widest text-slate-600">
                                                {category}
                                            </span>
                                        </div>
                                        {list.map(action => {
                                            const isSelected = selectedActionId === action.id;
                                            const ActionIcon = resolveIcon(action.icon);

                                            return (
                                                <button
                                                    key={action.id}
                                                    onClick={() => !processingId && selectAction(action.id)}
                                                    className={`w-full px-4 py-3 flex items-center gap-3 text-left transition-colors ${isSelected ? 'bg-cyan-500/10' : 'hover:bg-slate-800/40'
                                                        } ${processingId ? 'opacity-40 cursor-not-allowed' : ''}`}
                                                >
                                                    <div className="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 border bg-slate-800/50 border-slate-700/50">
                                                        <ActionIcon size={14} weight="bold" className="text-slate-300" />
                                                    </div>
                                                    <div className="flex-1 min-w-0">
                                                        <div className="text-sm font-black text-white uppercase tracking-wider truncate">{action.title}</div>

                                                    </div>
                                                    {isSelected && (
                                                        <CheckCircle size={14} weight="fill" className="text-cyan-400 shrink-0" />
                                                    )}
                                                </button>
                                            );
                                        })}
                                    </div>
                                ))}

                                {Object.keys(groupedActions).length === 0 && (
                                    <div className="py-12 text-center text-slate-600 text-sm">No operations found.</div>
                                )}
                            </div>

                            {filteredActions.length > ACTIONS_PER_PAGE && (
                                <div className="flex items-center justify-between gap-3 border-t border-slate-800/50 px-4 py-3">
                                    <span className="text-[9px] font-black uppercase tracking-widest text-slate-600">
                                        {actionRangeStart}-{actionRangeEnd} of {filteredActions.length}
                                    </span>
                                    <div className="flex items-center gap-1.5">
                                        <button
                                            type="button"
                                            onClick={() => setActionPage(page => Math.max(1, page - 1))}
                                            disabled={currentActionPage <= 1 || !!processingId}
                                            className="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-700/60 bg-slate-950/50 text-slate-400 transition-colors hover:border-cyan-500/40 hover:text-cyan-300 disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:border-slate-700/60 disabled:hover:text-slate-400"
                                            aria-label="Previous actions page"
                                        >
                                            <CaretLeft size={13} weight="bold" />
                                        </button>
                                        <span className="min-w-12 text-center text-[10px] font-black uppercase tracking-wider text-slate-500">
                                            {currentActionPage}/{totalActionPages}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => setActionPage(page => Math.min(totalActionPages, page + 1))}
                                            disabled={currentActionPage >= totalActionPages || !!processingId}
                                            className="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-700/60 bg-slate-950/50 text-slate-400 transition-colors hover:border-cyan-500/40 hover:text-cyan-300 disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:border-slate-700/60 disabled:hover:text-slate-400"
                                            aria-label="Next actions page"
                                        >
                                            <CaretRight size={13} weight="bold" />
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </motion.div>

                    {/* Right panel — detail + form */}
                    <motion.div
                        initial={{ opacity: 0, x: 16 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.25, duration: 0.4 }}
                        className="flex-1 min-w-0"
                    >
                        <AnimatePresence mode="wait">
                            {selectedAction ? (
                                <motion.div
                                    key={selectedAction.id}
                                    initial={{ opacity: 0, y: 10 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    exit={{ opacity: 0, y: -10 }}
                                    transition={{ duration: 0.15 }}
                                    className="bg-slate-900/60 border border-slate-800/50 rounded-2xl shadow-xl min-h-[420px]"
                                >
                                    {/* Image hero */}
                                    <div className="relative h-44 bg-slate-950 overflow-hidden">
                                        {selectedAction.image_url && (
                                            <img
                                                src={selectedAction.image_url}
                                                alt={selectedAction.title}
                                                className="absolute inset-0 w-full h-full object-cover opacity-70"
                                            />
                                        )}
                                        <div className="absolute inset-0 bg-gradient-to-t from-slate-900 via-slate-900/50 to-transparent" />
                                        <div className="absolute bottom-0 left-0 right-0 p-5">

                                            <h2 className="text-2xl font-black text-white uppercase tracking-tight">
                                                {selectedAction.title}
                                            </h2>
                                        </div>
                                    </div>

                                    {/* Content body */}
                                    <div className="p-5 space-y-4">
                                        <p className="text-xs text-slate-400 leading-relaxed">{selectedAction.description}</p>

                                        {/* Group action member roster */}
                                        {selectedAction.is_group_action && selectedAction.active_members &&
                                            (selectedAction.is_waiting || isGroupExecutePhase) && (
                                                <div className={`p-4 rounded-xl border ${isGroupExecutePhase
                                                    ? 'bg-emerald-500/10 border-emerald-500/30'
                                                    : 'bg-cyan-500/10 border-cyan-500/30'
                                                    }`}>
                                                    <p className={`text-[9px] uppercase font-black tracking-widest mb-3 ${isGroupExecutePhase ? 'text-emerald-400' : 'text-cyan-400'
                                                        }`}>
                                                        {isGroupExecutePhase ? 'Enough members have accepted — execute when ready' : 'Waiting for crew to accept'}
                                                    </p>
                                                    <MemberRoster members={selectedAction.active_members} />
                                                </div>
                                            )}

                                        {/* ── Form inputs — always visible ── */}

                                        {selectedAction.id === 'medicine_sale' && (
                                            <PendingMedicineOffers
                                                offers={pendingMedicineOffers}
                                                cancelRoute={selectedAction.cancel_route}
                                                cancelingId={cancelingId}
                                                onCancel={handleCancelMedicineOffer}
                                            />
                                        )}

                                        {hasTargets && (
                                            <SinglePicker
                                                label={selectedAction.pick_label ?? 'Select Target'}
                                                options={targetOptions}
                                                selectedId={targetId}
                                                onSelect={setTargetId}
                                                icon={selectedAction.target_icon}
                                                isOpen={pickerOpen === 'target'}
                                                onToggle={() => setPickerOpen(p => p === 'target' ? null : 'target')}
                                            />
                                        )}

                                        {selectedAction.id === 'medicine_production' && selectedTarget && (
                                            <div className="flex items-baseline justify-between gap-3 px-1 pt-1">
                                                <p className="text-[10px] font-black uppercase tracking-[0.2em] text-cyan-300">
                                                    PPP
                                                </p>
                                                <p className="text-sm font-black tabular-nums text-white">
                                                    ${Number(selectedTarget.payout_per_pack ?? 0).toLocaleString()}
                                                    <span className="ml-2 text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">
                                                        / pack
                                                    </span>
                                                </p>
                                            </div>
                                        )}

                                        {(hasProductInput || hasPackInput) && (
                                            <div className="grid gap-3 sm:grid-cols-[1.35fr_0.65fr]">
                                                {hasProductInput && (
                                                    <ProductPicker
                                                        label={selectedAction.product_label ?? 'Medicine'}
                                                        products={productOptions}
                                                        selectedId={productId}
                                                        onSelect={setProductId}
                                                    />
                                                )}

                                                {hasPackInput && (
                                                    <PackCountPicker
                                                        label={selectedAction.pack_label ?? 'Quantity'}
                                                        value={packCount}
                                                        max={productId === null ? 1 : maxPackCount}
                                                        disabled={productId === null}
                                                        onChange={setPackCount}
                                                    />
                                                )}
                                            </div>
                                        )}

                                        {hasAccomplices && (
                                            <CrewPicker
                                                options={accompliceOptions}
                                                selectedIds={accompliceIds}
                                                onToggle={toggleAccomplice}
                                                isOpen={pickerOpen === 'crew'}
                                                onOpenToggle={() => setPickerOpen(p => p === 'crew' ? null : 'crew')}
                                            />
                                        )}

                                        {/* ── Launder info panel (banker_launder_client only) ── */}
                                        {selectedAction.id === 'banker_launder_client' && targetId !== null && (() => {
                                            const t = selectedAction.targets?.find(t => t.id === targetId);
                                            if (!t) return null;
                                            const cutPct = Math.round(((t as any).cut_pct ?? 0) * 100);
                                            const feePct = Math.round(((t as any).overhead ?? 0.06) * 100);
                                            const maxAmt = (t as any).max_amount ?? 0;
                                            const queued = (t as any).amount_sent ?? 0;
                                            return (
                                                <div className="rounded-xl border border-slate-700/50 bg-slate-900/60 overflow-hidden">
                                                    <div className="grid grid-cols-2 divide-x divide-slate-700/50">
                                                        <div className="px-3 py-2.5 flex flex-col gap-0.5">
                                                            <span className="text-[9px] text-slate-600 uppercase tracking-widest">Banker cut</span>
                                                            <span className="text-sm font-black text-amber-400">{cutPct}%</span>
                                                        </div>
                                                        <div className="px-3 py-2.5 flex flex-col gap-0.5">
                                                            <span className="text-[9px] text-slate-600 uppercase tracking-widest">Bank fee</span>
                                                            <span className="text-sm font-black text-slate-400">{feePct}%</span>
                                                        </div>
                                                    </div>
                                                    <div className="grid grid-cols-2 divide-x divide-slate-700/50 border-t border-slate-700/50">
                                                        <div className="px-3 py-2.5 flex flex-col gap-0.5">
                                                            <span className="text-[9px] text-slate-600 uppercase tracking-widest">Max capacity</span>
                                                            <span className="text-sm font-black text-white">${maxAmt.toLocaleString()}</span>
                                                        </div>
                                                        <div className="px-3 py-2.5 flex flex-col gap-0.5">
                                                            <span className="text-[9px] text-slate-600 uppercase tracking-widest">Queued</span>
                                                            <span className={`text-sm font-black ${queued > 0 ? 'text-amber-400' : 'text-slate-600'}`}>
                                                                {queued > 0 ? `${queued.toLocaleString()}` : 'None'}
                                                            </span>
                                                        </div>
                                                    </div>
                                                    {queued > 0 && selectedAction.refund_route && (
                                                        <div className="border-t border-slate-700/50 px-3 py-2.5">
                                                            <button
                                                                onClick={handleRefund}
                                                                disabled={!!processingId || !!cancelingId || !!refundingId}
                                                                className="w-full py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest text-amber-400/70 border border-amber-500/20 hover:border-amber-500/40 hover:text-amber-400 transition-colors disabled:opacity-40 flex items-center justify-center gap-1.5"
                                                            >
                                                                {refundingId ? <div className="w-2.5 h-2.5 border-2 border-amber-400/30 border-t-amber-400 rounded-full animate-spin" /> : null}
                                                                Reclaim ${queued.toLocaleString()} Queued Funds
                                                            </button>
                                                        </div>
                                                    )}
                                                </div>
                                            );
                                        })()}

                                        {hasAmountInput && (
                                            <div className="space-y-1.5">
                                                <label className="text-[9px] uppercase font-black tracking-widest text-slate-500">
                                                    {selectedAction.amount_label ?? 'Amount'}
                                                </label>
                                                <div className="relative">
                                                    <span className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500 font-bold text-sm">$</span>
                                                    <input
                                                        type="text"
                                                        inputMode="decimal"
                                                        value={amount}
                                                        onChange={e => setAmount(e.target.value)}
                                                        placeholder="50k"
                                                        className="w-full bg-slate-800/50 border border-slate-700/60 rounded-xl py-3 pl-8 pr-4 text-white font-bold focus:outline-none focus:border-cyan-500/40 transition-colors"
                                                    />
                                                </div>
                                                {targetId !== null && (() => {
                                                    const mv = selectedAction.targets?.find(t => t.id === targetId)?.maxValue;
                                                    return mv && mv > 0
                                                        ? <p className="text-[10px] text-slate-500">Max: ${mv.toLocaleString()}</p>
                                                        : null;
                                                })()}
                                            </div>
                                        )}

                                        {/* ── Buttons ── */}
                                        {!isMedicineWaiting && (
                                            <div className="flex gap-3 pt-1">
                                                {/* Abort — group actions waiting/ready, OR non-group with queued funds */}
                                                {selectedAction.cancel_route && (
                                                    (selectedAction.is_group_action && (selectedAction.is_waiting || isGroupExecutePhase)) ||
                                                    (selectedAction.id === 'banker_launder_client' && targetId !== null &&
                                                        (selectedAction.targets?.find(t => t.id === targetId)?.amount_sent ?? 0) > 0)
                                                ) && (
                                                        <button
                                                            onClick={handleCancel}
                                                            disabled={!!processingId || !!cancelingId}
                                                            className="h-11 px-5 bg-slate-800 hover:bg-slate-700 text-white rounded-xl text-xs font-black uppercase tracking-widest border border-slate-700 transition-colors disabled:opacity-40 flex items-center gap-2"
                                                        >
                                                            {cancelingId === selectedAction.id
                                                                ? <div className="w-3 h-3 border-2 border-white/30 border-t-white rounded-full animate-spin shrink-0" />
                                                                : <Prohibit size={13} weight="bold" />
                                                            }
                                                            {selectedAction.id === 'banker_launder_client' ? 'Cancel Arrangement' : 'Abort'}
                                                        </button>
                                                    )}

                                                <motion.button
                                                    whileTap={canExecute ? { scale: 0.97 } : {}}
                                                    onClick={handleExecute}
                                                    disabled={!canExecute}
                                                    className={`flex-1 h-11 flex items-center justify-center gap-2 rounded-xl text-xs font-black uppercase tracking-widest border transition-all ${canExecute
                                                        ? 'bg-emerald-600 hover:bg-emerald-500 text-white border-emerald-500/50 shadow-lg shadow-emerald-500/15'
                                                        : 'bg-slate-800 text-slate-500 border-slate-700 cursor-not-allowed'
                                                        }`}
                                                >
                                                    {processingId === selectedAction.id ? (
                                                        <>
                                                            <div className="w-3 h-3 border-2 border-white/30 border-t-white rounded-full animate-spin shrink-0" />
                                                            {executeLabel()}
                                                        </>
                                                    ) : (
                                                        <>
                                                            {canExecute && <Crosshair size={14} weight="bold" />}
                                                            {executeLabel()}
                                                            {canExecute && <ArrowRight size={14} weight="bold" />}
                                                        </>
                                                    )}
                                                </motion.button>
                                            </div>
                                        )}
                                    </div>
                                </motion.div>

                            ) : (
                                /* Empty state — no action selected */
                                <div className="min-h-[420px] flex flex-col items-center justify-center text-center p-8 bg-slate-900/30 border border-slate-800/50 rounded-2xl">
                                    <div className="w-16 h-16 rounded-2xl bg-slate-800/50 flex items-center justify-center mb-4">
                                        <Crosshair size={26} className="text-slate-600" />
                                    </div>
                                    <h3 className="text-base font-bold text-slate-400 mb-2">Select an Operation</h3>
                                    <p className="text-sm text-slate-600 max-w-xs">
                                        Choose an action from the list to view details and execute it
                                    </p>
                                </div>
                            )}
                        </AnimatePresence>
                    </motion.div>
                </div>
            </div>
        </>
    );
}

Actions.layout = (page: React.ReactNode) => <GameLayout wide children={page} />;
