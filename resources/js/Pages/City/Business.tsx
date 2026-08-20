import React, { useState, useMemo } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { motion, AnimatePresence } from 'framer-motion';
// @ts-ignore
import { route } from 'ziggy-js';
import {
    House, Briefcase, Buildings, Lock, Check,
    Car, Warning, ArrowDown, ArrowRight,
    CaretRight, X, VaultIcon, WalletIcon, PencilSimple
} from '@phosphor-icons/react';
import GameLayout from '@/Layouts/GameLayout';
import { getCityImage } from '@/utils/cityImages';

// ── Types ─────────────────────────────────────────────────────────────────────

interface Property {
    id: number;
    name: string;
    price: number;
    image_url: string | null;
    vehicle_capacity: number;
    safe_capacity: number;
    has_alarm: boolean;
    bonuses: { influence: number; intelligence: number; offense: number; defense: number };
}

interface BusinessVenture {
    id: number;
    code: string;
    name: string;
    slug: string;
    icon: string;
    image_url: string | null;
    description: string;
    base_price: number;
    is_purchasable: boolean;
    owner_id: number | null;
    owner_name: string | null;
    settings?: any;
    balance?: number;
}

interface OwnedProperty {
    id: number;
    name: string;
    image_url: string | null;
    sell_price: number;
    vehicle_capacity: number;
    safe_capacity: number;
    has_alarm: boolean;
    property_condition: string | null;
}

interface Props {
    properties: Property[];
    businesses: BusinessVenture[];
    ownedProperty: OwnedProperty | null;
    ownedBusinesses: BusinessVenture[];
    cityData: { name: string; slug: string; image_url: string | null };
    character: { id: number; cleanCash: number };
}

type Tab = 'real_estate' | 'business' | 'manage';

const fmt$ = (n: number) => `$${Math.round(n).toLocaleString()}`;

// ── Property card ─────────────────────────────────────────────────────────────

function PropertyCard({ property, owned, ownedAnother, canAfford, processing, actionId, onBuy }: {
    property: Property;
    owned: boolean;
    ownedAnother: boolean;
    canAfford: boolean;
    processing: boolean;
    actionId: number | null;
    onBuy: () => void;
}) {
    return (
        <div className={`group relative flex flex-col overflow-hidden rounded-xl border transition-all ${owned ? 'border-cyan-500/30 bg-slate-900/60' : 'border-white/[0.06] bg-slate-900/40 hover:border-white/10'
            }`}>
            {/* Image */}
            <div className="relative h-36 overflow-hidden shrink-0">
                {property.image_url ? (
                    <img
                        src={property.image_url}
                        alt={property.name}
                        className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
                        style={{ filter: 'brightness(0.85) contrast(1.05) saturate(1.1)' }}
                    />
                ) : (
                    <div className="w-full h-full bg-slate-800 flex items-center justify-center">
                        <House size={28} className="text-slate-600" />
                    </div>
                )}
                <div className="absolute inset-0 bg-gradient-to-t from-slate-950/70 to-transparent" />
                {owned && (
                    <div className="absolute top-2.5 right-2.5 flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider bg-cyan-500/20 border border-cyan-500/30 text-cyan-400">
                        <Check size={10} weight="bold" /> Owned
                    </div>
                )}
            </div>

            {/* Info */}
            <div className="flex flex-col gap-3 p-4">
                <div>
                    <p className="text-[10px] text-white-600 uppercase tracking-widest mb-0.5">Residential</p>
                    <h3 className="text-base font-bold text-white truncate leading-tight">{property.name}</h3>
                </div>

                <div className="flex flex-wrap gap-1.5">
                    <span className="flex items-center gap-1 text-[10px] text-slate-500 bg-slate-800/60 px-2 py-0.5 rounded border border-white/[0.04]">
                        <Car size={11} /> Garage {property.vehicle_capacity}
                    </span>
                    <span className="flex items-center gap-1 text-[10px] text-slate-500 bg-slate-800/60 px-2 py-0.5 rounded border border-white/[0.04]">
                        <VaultIcon size={11} /> Safe {property.safe_capacity.toLocaleString()}
                    </span>
                    {property.has_alarm && (
                        <span className="flex items-center gap-1 text-[10px] text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded border border-emerald-500/20">
                            <Warning size={11} weight="fill" /> Alarm
                        </span>
                    )}
                </div>

                {owned ? (
                    <div className="text-[11px] font-black text-cyan-400/60 text-center py-1">Currently owned</div>
                ) : (
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-sm font-bold text-slate-300 tabular-nums">{fmt$(property.price)}</span>
                        <button
                            onClick={onBuy}
                            disabled={processing}
                            className="px-3.5 py-1.5 rounded-lg text-[11px] font-black uppercase tracking-widest transition-all bg-cyan-700 hover:bg-cyan-600 text-white disabled:opacity-40"
                        >
                            {actionId === property.id ? '…' : 'Buy'}
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

// ── Business card ─────────────────────────────────────────────────────────────

function BusinessCard({ business, characterId, canAfford, processing, actionId, onBuy }: {
    business: BusinessVenture;
    characterId: number;
    canAfford: boolean;
    processing: boolean;
    actionId: number | null;
    onBuy: () => void;
}) {
    const isOwnedByMe = business.owner_id === characterId;
    const isOwnedByOther = Boolean(business.owner_id && !isOwnedByMe);
    const isListed = isOwnedByMe && business.is_purchasable;

    return (
        <div className="group relative flex flex-col overflow-hidden rounded-xl border border-white/[0.06] bg-slate-900/40 hover:border-white/10 transition-all">
            <div className="relative h-36 overflow-hidden shrink-0">
                {business.image_url ? (
                    <img
                        src={business.image_url}
                        alt={business.name}
                        className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
                        style={{ filter: 'brightness(0.85) contrast(1.05) saturate(1.1)' }}
                    />
                ) : (
                    <div className="w-full h-full bg-slate-800 flex items-center justify-center">
                        <Buildings size={28} className="text-slate-600" />
                    </div>
                )}
                <div className="absolute inset-0 bg-gradient-to-t from-slate-950/70 to-transparent" />
                <div className={`absolute top-2.5 right-2.5 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider border backdrop-blur-sm ${isListed ? 'bg-amber-500/30 border-amber-400/50 text-amber-300'
                    : isOwnedByMe ? 'bg-emerald-500/30 border-emerald-400/50 text-emerald-300'
                        : business.is_purchasable ? 'bg-cyan-500/30 border-cyan-400/50 text-cyan-300'
                            : isOwnedByOther ? 'bg-red-500/25 border-red-400/40 text-red-300'
                                : 'bg-slate-600/40 border-slate-500/50 text-slate-300'
                    }`}>
                    {isListed ? 'Listed' : isOwnedByMe ? 'Owned' : business.is_purchasable ? 'For Sale' : isOwnedByOther ? 'Private' : 'Govt'}
                </div>
            </div>

            <div className="flex flex-col gap-2.5 p-4">
                <div className="space-y-1.5">
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-[10px] text-white-500 uppercase tracking-widest">Business</span>
                        <span className="text-sm font-semibold text-white truncate text-right">{business.name}</span>
                    </div>
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-[10px] text-white-500 uppercase tracking-widest">Owner</span>
                        <span className="text-sm font-semibold text-white truncate text-right">{business.owner_name || 'State Owned'}</span>
                    </div>
                </div>

                {isOwnedByMe ? (
                    <div className="text-[11px] font-black text-emerald-400/60 text-center py-1">You own this</div>
                ) : business.is_purchasable ? (
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-sm font-bold text-slate-300 tabular-nums">{fmt$(business.base_price)}</span>
                        <button
                            onClick={onBuy}
                            disabled={processing}
                            className="px-3.5 py-1.5 rounded-lg text-[11px] font-black uppercase tracking-widest transition-all bg-cyan-700 hover:bg-cyan-600 text-white disabled:opacity-40"
                        >
                            {actionId === business.id ? '…' : 'Buy'}
                        </button>
                    </div>
                ) : (
                    <div className="flex items-center gap-1 text-[11px] text-slate-600 justify-center py-1">
                        <Lock size={12} /> Not for sale
                    </div>
                )}
            </div>
        </div>
    );
}

// ── Manage tab ────────────────────────────────────────────────────────────────

function ManageTab({
    ownedProperty, ownedBusinesses, cityData,
    processing, actionId,
    listingPrices, setListingPrices,
    withdrawAmounts, setWithdrawAmounts,
    onSellProperty, onSellBusiness, onWithdraw, onCancelSale, onVisitBusiness,
    onUpdateDescription,
}: any) {
    const [editingDescId, setEditingDescId] = useState<number | null>(null);
    const [descDraft, setDescDraft] = useState('');
    return (
        <div className="p-5 space-y-6">
            {/* ── Property ── */}
            <div>
                <p className="text-[10px] font-black text-white-500 uppercase tracking-widest mb-3 flex items-center gap-1.5">
                    <House size={11} /> Residence
                </p>
                {ownedProperty ? (
                    <div className="flex flex-col sm:flex-row gap-4 p-4 rounded-xl border border-cyan-500/20 bg-slate-900/60">
                        <div className="w-full sm:w-40 h-28 rounded-lg overflow-hidden shrink-0">
                            {ownedProperty.image_url ? (
                                <img src={ownedProperty.image_url} className="w-full h-full object-cover" style={{ filter: 'brightness(0.75) contrast(1.05)' }} alt="" />
                            ) : (
                                <div className="w-full h-full bg-slate-800 flex items-center justify-center">
                                    <House size={20} className="text-slate-600" />
                                </div>
                            )}
                        </div>
                        <div className="flex-1 min-w-0">
                            <div className="flex items-start justify-between gap-2 mb-2">
                                <div>
                                    <p className="text-[9px] text-slate-600 uppercase tracking-widest mb-0.5">Primary Residence</p>
                                    <h3 className="text-base font-bold text-white">{ownedProperty.name}</h3>
                                </div>
                                <span className={`shrink-0 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider border ${ownedProperty.property_condition === 'DESTROYED'
                                    ? 'bg-red-500/10 border-red-500/20 text-red-400'
                                    : !ownedProperty.property_condition
                                        ? 'bg-amber-500/10 border-amber-500/20 text-amber-400'
                                        : 'bg-cyan-500/10 border-cyan-500/20 text-cyan-400'
                                    }`}>
                                    {ownedProperty.property_condition === 'DESTROYED' ? 'Destroyed' : !ownedProperty.property_condition ? 'Inspection Req.' : 'CONSTRUCTED'}
                                </span>
                            </div>
                            <div className="flex flex-wrap gap-3 mb-3 text-xs text-slate-400">
                                <span className="flex items-center gap-1"><Car size={11} /> Garage {ownedProperty.vehicle_capacity}</span>
                                <span className="flex items-center gap-1"><VaultIcon size={11} /> Safe {ownedProperty.safe_capacity.toLocaleString()}</span>
                                <span className={`flex items-center gap-1 ${ownedProperty.has_alarm ? 'text-emerald-400' : 'text-slate-600'}`}>
                                    <Warning size={11} weight={ownedProperty.has_alarm ? 'fill' : 'regular'} /> {ownedProperty.has_alarm ? 'Alarm' : 'No alarm'}
                                </span>
                            </div>
                            <div className="flex items-center gap-3">
                                <button
                                    onClick={onSellProperty}
                                    disabled={processing && actionId === ownedProperty.id}
                                    className="px-4 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest bg-red-500/10 border border-red-500/20 text-red-400 hover:bg-red-500/15 transition-colors disabled:opacity-40"
                                >
                                    {processing && actionId === ownedProperty.id ? 'Selling…' : `Sell · ${fmt$(ownedProperty.sell_price)}`}
                                </button>
                                <span className="text-[9px] text-slate-600 flex items-center gap-1">
                                    <Warning size={9} /> Returns 50% of value
                                </span>
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-col items-center justify-center py-10 rounded-xl border border-dashed border-white/[0.06] text-slate-700 gap-2">
                        <House size={22} />
                        <p className="text-[10px] font-black uppercase tracking-widest">No residence owned</p>
                    </div>
                )}
            </div>

            {/* ── Businesses ── */}
            <div>
                <p className="text-[10px] font-black text-white-500 uppercase tracking-widest mb-3 flex items-center gap-1.5">
                    <Buildings size={11} /> Business Ventures
                </p>
                {ownedBusinesses.length > 0 ? (
                    <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                        {ownedBusinesses.map((biz: BusinessVenture) => (
                            <div key={biz.id} className="rounded-xl border border-emerald-500/15 bg-slate-900/50 overflow-hidden">
                                {/* Image strip */}
                                <div className="relative h-20 overflow-hidden">
                                    {biz.image_url ? (
                                        <img src={biz.image_url} className="w-full h-full object-cover" style={{ filter: 'brightness(0.65) contrast(1.05) saturate(1.1)' }} alt="" />
                                    ) : (
                                        <div className="w-full h-full bg-slate-800 flex items-center justify-center">
                                            <Buildings size={18} className="text-slate-600" />
                                        </div>
                                    )}
                                    <div className="absolute inset-0 bg-gradient-to-t from-slate-950/80 to-transparent" />
                                    <div className="absolute bottom-2 left-3">
                                        <p className="text-sm font-bold text-white leading-tight truncate max-w-[180px]">{biz.name}</p>
                                    </div>
                                </div>

                                <div className="p-3 space-y-2.5">
                                    {/* Balance + status */}
                                    <div className="grid grid-cols-2 gap-2">
                                        <div className="bg-slate-950/50 rounded-lg px-2.5 py-2 border border-white/[0.04]">
                                            <p className="text-[9px] text-slate-600 uppercase tracking-widest">Balance</p>
                                            <p className="text-xs font-bold text-emerald-400 tabular-nums">{fmt$(biz.balance ?? 0)}</p>
                                        </div>
                                        <div className="bg-slate-950/50 rounded-lg px-2.5 py-2 border border-white/[0.04]">
                                            <p className="text-[9px] text-slate-600 uppercase tracking-widest">Status</p>
                                            <p className={`text-xs font-bold ${biz.is_purchasable ? 'text-amber-400' : 'text-emerald-400'}`}>
                                                {biz.is_purchasable ? 'Listed' : 'Active'}
                                            </p>
                                        </div>
                                    </div>

                                    {/* Description */}
                                    <div>
                                        <p className="text-[9px] text-slate-600 uppercase tracking-widest mb-1 flex items-center gap-1">
                                            <PencilSimple size={9} weight="bold" /> Description
                                        </p>
                                        {editingDescId === biz.id ? (
                                            <div className="flex gap-1">
                                                <input
                                                    type="text"
                                                    value={descDraft}
                                                    onChange={(e: any) => setDescDraft(e.target.value)}
                                                    maxLength={30}
                                                    className="flex-1 px-2.5 py-1.5 bg-slate-950/70 border border-white/[0.07] rounded-lg text-xs text-white placeholder:text-slate-700 focus:border-cyan-500/30 outline-none transition-colors"
                                                    placeholder="Enter description…"
                                                />
                                                <button
                                                    onClick={() => { onUpdateDescription(biz.id, descDraft); setEditingDescId(null); }}
                                                    disabled={processing || !descDraft.trim()}
                                                    className="px-2.5 py-1.5 bg-cyan-700 hover:bg-cyan-600 text-white text-[10px] font-black uppercase tracking-widest rounded-lg transition-all disabled:opacity-40"
                                                >
                                                    Save
                                                </button>
                                                <button
                                                    onClick={() => setEditingDescId(null)}
                                                    className="px-1.5 py-1.5 text-slate-500 hover:text-white rounded-lg transition-colors"
                                                >
                                                    <X size={11} weight="bold" />
                                                </button>
                                            </div>
                                        ) : (
                                            <div
                                                onClick={() => { setEditingDescId(biz.id); setDescDraft(biz.description || ''); }}
                                                className="px-2.5 py-1.5 bg-slate-950/50 border border-white/[0.04] rounded-lg text-xs text-slate-400 cursor-pointer hover:border-white/10 transition-colors truncate"
                                            >
                                                {biz.description || <span className="text-slate-600 italic">Click to set</span>}
                                            </div>
                                        )}
                                    </div>

                                    {/* Withdraw */}
                                    <div>
                                        <p className="text-[9px] text-slate-600 uppercase tracking-widest mb-1 flex items-center gap-1">
                                            <ArrowDown size={9} weight="bold" /> Withdraw
                                        </p>
                                        <div className="flex gap-1">
                                            <div className="relative flex-1">
                                                <span className="absolute left-2 top-1/2 -translate-y-1/2 text-slate-500 text-[10px]">$</span>
                                                <input
                                                    type="number"
                                                    placeholder="0"
                                                    value={withdrawAmounts[biz.id] || ''}
                                                    onChange={(e: any) => setWithdrawAmounts({ ...withdrawAmounts, [biz.id]: parseInt(e.target.value) })}
                                                    className="w-full pl-5 pr-2 py-1.5 bg-slate-950/70 border border-white/[0.07] rounded-lg text-xs text-white focus:border-cyan-500/30 outline-none transition-colors"
                                                />
                                            </div>
                                            <button
                                                onClick={() => onWithdraw(biz.id)}
                                                disabled={processing && actionId === biz.id}
                                                className="px-3 py-1.5 bg-cyan-700 hover:bg-cyan-600 text-white text-[10px] font-black uppercase tracking-widest rounded-lg transition-all disabled:opacity-40"
                                            >
                                                Go
                                            </button>
                                        </div>
                                    </div>

                                    {/* List / cancel */}
                                    <div>
                                        <p className="text-[9px] text-slate-600 uppercase tracking-widest mb-1 flex items-center gap-1">
                                            <CaretRight size={9} weight="bold" /> List for Sale
                                        </p>
                                        <div className="flex gap-1">
                                            <div className="relative flex-1">
                                                <span className="absolute left-2 top-1/2 -translate-y-1/2 text-slate-500 text-[10px]">$</span>
                                                <input
                                                    type="number"
                                                    placeholder="Price"
                                                    value={listingPrices[biz.id] || ''}
                                                    onChange={(e: any) => setListingPrices({ ...listingPrices, [biz.id]: parseInt(e.target.value) })}
                                                    className="w-full pl-5 pr-2 py-1.5 bg-slate-950/70 border border-white/[0.07] rounded-lg text-xs text-white focus:border-amber-500/30 outline-none transition-colors"
                                                />
                                            </div>
                                            <button
                                                onClick={() => onSellBusiness(biz.id)}
                                                disabled={processing && actionId === biz.id}
                                                className="px-3 py-1.5 bg-amber-600/20 border border-amber-500/30 text-amber-400 text-[10px] font-black uppercase tracking-widest rounded-lg transition-all disabled:opacity-40 hover:bg-amber-600/30"
                                            >
                                                {biz.is_purchasable ? 'Upd' : 'List'}
                                            </button>
                                            {biz.is_purchasable && (
                                                <button
                                                    onClick={() => onCancelSale(biz.id)}
                                                    disabled={processing && actionId === biz.id}
                                                    className="px-2.5 py-1.5 bg-red-500/10 border border-red-500/20 text-red-400 rounded-lg transition-all hover:bg-red-500/15 disabled:opacity-40"
                                                >
                                                    <X size={11} weight="bold" />
                                                </button>
                                            )}
                                        </div>
                                    </div>

                                    {/* Visit */}
                                    <button
                                        onClick={() => onVisitBusiness(biz)}
                                        className="w-full flex items-center justify-center gap-1.5 py-2 rounded-lg bg-slate-800/60 border border-white/[0.06] text-[10px] font-black uppercase tracking-widest text-slate-400 hover:text-white hover:border-white/10 transition-all"
                                    >
                                        <ArrowRight size={11} /> Visit
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="flex flex-col items-center justify-center py-10 rounded-xl border border-dashed border-white/[0.06] text-slate-700 gap-2">
                        <Buildings size={22} />
                        <p className="text-[10px] font-black uppercase tracking-widest">No ventures owned</p>
                    </div>
                )}
            </div>
        </div>
    );
}

// ── Root ──────────────────────────────────────────────────────────────────────

export default function BusinessPage({ properties, businesses, ownedProperty, ownedBusinesses, cityData, character }: Props) {
    const { errors } = usePage().props as any;
    const [activeTab, setActiveTab] = useState<Tab>('real_estate');
    const [actionId, setActionId] = useState<number | null>(null);
    const [processing, setProcessing] = useState(false);
    const [listingPrices, setListingPrices] = useState<Record<number, number>>({});
    const [withdrawAmounts, setWithdrawAmounts] = useState<Record<number, number>>({});
    const [businessPage, setBusinessPage] = useState(1);

    const BUSINESSES_PER_PAGE = 12;

    const totalBusinessPages = useMemo(() => Math.max(1, Math.ceil(businesses.length / BUSINESSES_PER_PAGE)), [businesses.length]);
    const pagedBusinesses = useMemo(() => businesses.slice((businessPage - 1) * BUSINESSES_PER_PAGE, businessPage * BUSINESSES_PER_PAGE), [businesses, businessPage]);

    const post = (routeName: string, params: any, data: any, onDone?: () => void) => {
        setProcessing(true);
        router.post(route(routeName, { city: cityData.slug }), data, {
            preserveScroll: true,
            onFinish: () => { setActionId(null); setProcessing(false); onDone?.(); },
        });
    };

    const handleBuyProperty = (id: number) => { setActionId(id); post('city.property.purchase', {}, { property_id: id }); };
    const handleSellProperty = () => { setActionId(ownedProperty?.id || 0); post('city.property.sell', {}, {}); };
    const handleBuyBusiness = (id: number) => { setActionId(id); post('city.business.venture.purchase', {}, { business_id: id }); };
    const handleSellBusiness = (id: number) => {
        const price = listingPrices[id];
        if (!price || price <= 0) { alert('Enter a valid price.'); return; }
        setActionId(id); post('city.business.venture.sell', {}, { business_id: id, price });
    };
    const handleWithdraw = (id: number) => {
        const amount = withdrawAmounts[id];
        if (!amount || amount <= 0) { alert('Enter a valid amount.'); return; }
        setActionId(id); post('city.business.withdraw', {}, { business_id: id, amount }, () => setWithdrawAmounts(a => ({ ...a, [id]: 0 })));
    };
    const handleCancelSale = (id: number) => { setActionId(id); post('city.business.venture.cancel', {}, { business_id: id }); };
    const handleUpdateDescription = (id: number, description: string) => {
        setActionId(id);
        post('city.business.venture.update-description', {}, { business_id: id, description });
    };
    const handleVisitBusiness = (biz: BusinessVenture) => {
        if (biz.code.startsWith('shop-')) {
            router.visit(route('city.shop.index', { city: cityData.slug, business_slug: biz.slug }));
        } else {
            try { router.visit(route(`city.${biz.code}.index`, { city: cityData.slug })); }
            catch { router.visit(route('city.business.index', { city: cityData.slug })); }
        }
    };

    const bgImage = getCityImage(cityData.name, cityData.image_url);

    const TABS: { id: Tab; label: string; icon: any }[] = [
        { id: 'real_estate', label: 'Real Estate', icon: House },
        { id: 'business', label: 'Businesses', icon: Buildings },
        { id: 'manage', label: 'Manage Assets', icon: WalletIcon },
    ];

    return (
        <>
            <Head title={`Business — ${cityData.name}`} />
            <div className="w-full max-w-6xl mx-auto px-1 md:px-4 py-3 md:py-6 flex flex-col gap-4 md:gap-6 h-full">

                {/* ── Hero ── */}
                <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4 }}
                    className="relative rounded-t-2xl overflow-hidden border border-white/5 border-b-0 shadow-2xl shrink-0 min-h-[14rem] md:min-h-[18rem] flex flex-col justify-end"
                >
                    <div className="absolute inset-0">
                        <img
                            src={bgImage}
                            alt={cityData.name}
                            className="absolute inset-0 w-full h-full object-cover"
                            style={{ filter: 'brightness(0.7) contrast(1.1) saturate(1.15)' }}
                        />
                        <div className="absolute right-0 top-0 w-96 h-96 bg-cyan-500/10 blur-[100px] rounded-full mix-blend-screen pointer-events-none" />
                        <div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/40 to-transparent" />
                    </div>

                    <div className="relative px-8 pt-10 pb-6 md:px-10 md:pb-8">
                        <div className="flex items-center gap-2 mb-3">

                        </div>
                        <h1 className="text-4xl md:text-5xl lg:text-6xl font-light text-white tracking-tight leading-none mb-3">
                            {cityData.name}
                        </h1>
                        <p className="text-white text-sm leading-relaxed max-w-md">
                            Acquire a home, Purchase and manage businesses.
                        </p>
                    </div>
                </motion.div>

                {/* ── Tab bar ── */}
                <div className="flex overflow-x-auto no-scrollbar border-b border-slate-800/40 gap-1 shrink-0 px-1 lg:px-0">
                    {TABS.map(tab => {
                        const Icon = tab.icon;
                        const active = activeTab === tab.id;
                        return (
                            <button
                                key={tab.id}
                                onClick={() => setActiveTab(tab.id)}
                                className={`flex items-center gap-2 px-5 py-3 text-[10px] font-black uppercase tracking-widest border-b-2 transition whitespace-nowrap ${active
                                    ? 'border-cyan-400 text-white'
                                    : 'border-transparent text-slate-500 hover:text-slate-300'
                                    }`}
                            >
                                <Icon size={13} weight={active ? 'fill' : 'bold'} />
                                {tab.label}
                            </button>
                        );
                    })}
                </div>

                {/* ── Tab content ── */}
                <div className="flex-1 min-h-0 bg-slate-900/60 border border-slate-700/40 rounded-xl shadow-lg overflow-y-auto">
                    <AnimatePresence mode="wait">

                        {/* Real Estate */}
                        {activeTab === 'real_estate' && (
                            <motion.div key="re" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="p-5">
                                <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3">
                                    {properties.map(p => (
                                        <PropertyCard
                                            key={p.id}
                                            property={p}
                                            owned={ownedProperty?.id === p.id}
                                            ownedAnother={!!ownedProperty && ownedProperty.id !== p.id}
                                            canAfford={character.cleanCash >= p.price}
                                            processing={processing}
                                            actionId={actionId}
                                            onBuy={() => handleBuyProperty(p.id)}
                                        />
                                    ))}
                                </div>
                            </motion.div>
                        )}

                        {/* Businesses */}
                        {activeTab === 'business' && (
                            <motion.div key="biz" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="p-5">
                                <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3">
                                    {pagedBusinesses.map(b => (
                                        <BusinessCard
                                            key={b.id}
                                            business={b}
                                            characterId={character.id}
                                            canAfford={character.cleanCash >= b.base_price}
                                            processing={processing}
                                            actionId={actionId}
                                            onBuy={() => handleBuyBusiness(b.id)}
                                        />
                                    ))}
                                </div>
                                {totalBusinessPages > 1 && (
                                    <div className="flex items-center justify-between mt-5 pt-4 border-t border-white/[0.04]">
                                        <span className="text-[10px] text-slate-600">Page {businessPage} / {totalBusinessPages}</span>
                                        <div className="flex gap-2">
                                            <button onClick={() => setBusinessPage(p => Math.max(1, p - 1))} disabled={businessPage === 1} className="px-3 py-1.5 rounded-lg border border-white/[0.06] text-[10px] font-black uppercase tracking-widest text-slate-500 hover:text-white disabled:opacity-30 transition">‹ Prev</button>
                                            <button onClick={() => setBusinessPage(p => Math.min(totalBusinessPages, p + 1))} disabled={businessPage === totalBusinessPages} className="px-3 py-1.5 rounded-lg border border-white/[0.06] text-[10px] font-black uppercase tracking-widest text-slate-500 hover:text-white disabled:opacity-30 transition">Next ›</button>
                                        </div>
                                    </div>
                                )}
                            </motion.div>
                        )}

                        {/* Manage */}
                        {activeTab === 'manage' && (
                            <motion.div key="manage" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                                <ManageTab
                                    ownedProperty={ownedProperty}
                                    ownedBusinesses={ownedBusinesses}
                                    cityData={cityData}
                                    processing={processing}
                                    actionId={actionId}
                                    listingPrices={listingPrices}
                                    setListingPrices={setListingPrices}
                                    withdrawAmounts={withdrawAmounts}
                                    setWithdrawAmounts={setWithdrawAmounts}
                                    onSellProperty={handleSellProperty}
                                    onSellBusiness={handleSellBusiness}
                                    onWithdraw={handleWithdraw}
                                    onCancelSale={handleCancelSale}
                                    onVisitBusiness={handleVisitBusiness}
                                    onUpdateDescription={handleUpdateDescription}
                                />
                            </motion.div>
                        )}

                    </AnimatePresence>
                </div>

            </div>
        </>
    );
}

BusinessPage.layout = (page: any) => <GameLayout wide children={page} />;
