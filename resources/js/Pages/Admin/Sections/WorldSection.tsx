import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { Check, PencilSimple, Plus, Warning } from '@phosphor-icons/react';
import { formatCash } from '@/Layouts/GameLayoutComponents';

import {
    AdminInput,
    AdminModal,
    AdminTextarea,
    AdminToggle,
    Badge,
    FormActions,
    SubTabs,
    TableShell,
    TD,
    useConfirm,
} from '../Components';

import type { Business, Property } from './types';

export function WorldSection({
    properties,
    businesses,
}: {
    properties: Property[];
    businesses: Business[];
}) {
    const [tab, setTab] = useState<'businesses' | 'properties'>('businesses');
    const [businessPage, setBusinessPage] = useState(1);
    const [propertyPage, setPropertyPage] = useState(1);
    const [propModal, setPropModal] = useState(false);
    const [editProp, setEditProp] = useState<Property | null>(null);
    const [bizModal, setBizModal] = useState(false);
    const [editBiz, setEditBiz] = useState<Business | null>(null);
    const { confirm, ConfirmNode } = useConfirm();

    const PAGE_SIZE = 8;

    const businessTotalPages = Math.max(1, Math.ceil(businesses.length / PAGE_SIZE));
    const propertyTotalPages = Math.max(1, Math.ceil(properties.length / PAGE_SIZE));

    const pagedBusinesses = businesses.slice(
        (businessPage - 1) * PAGE_SIZE,
        businessPage * PAGE_SIZE,
    );

    const pagedProperties = properties.slice(
        (propertyPage - 1) * PAGE_SIZE,
        propertyPage * PAGE_SIZE,
    );

    const propForm = useForm({
        name: '',
        price: 0,
        image_url: '',
        vehicle_capacity: 0,
        safe_capacity: 0,
        has_alarm: false,
        influence_bonus_pct: 0,
        intelligence_bonus_pct: 0,
        offense_bonus_pct: 0,
        defense_bonus_pct: 0,
    });

    const openProp = (p?: Property) => {
        if (p) {
            propForm.setData(p as any);
            setEditProp(p);
        } else {
            propForm.reset();
            setEditProp(null);
        }
        setPropModal(true);
    };

    const submitProp = (e: React.FormEvent) => {
        e.preventDefault();
        const url = editProp ? route('admin.properties.update', editProp.id) : route('admin.properties.create');
        propForm.post(url, {
            onSuccess: () => {
                setPropModal(false);
                setEditProp(null);
            },
        });
    };

    const bizForm = useForm({
        name: '',
        description: '',
        base_price: 0,
        is_purchasable: false,
        is_active: true,
    });

    const openBiz = (b: Business) => {
        bizForm.setData({
            name: b.name,
            description: b.description ?? '',
            base_price: b.base_price ?? 0,
            is_purchasable: b.is_purchasable,
            is_active: b.is_active,
        });
        setEditBiz(b);
        setBizModal(true);
    };

    const submitBiz = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editBiz) return;
        bizForm.post(route('admin.businesses.update', editBiz.id), {
            onSuccess: () => {
                setBizModal(false);
                setEditBiz(null);
            },
        });
    };

    const clearBizOwner = (biz: Business) => {
        confirm(
            `Clear owner of "${biz.name}"?`,
            () => router.post(route('admin.businesses.clearOwner', biz.id)),
            'The business will become unowned. No refund is issued.',
            true,
        );
    };

    return (
        <div className="space-y-4">
            {ConfirmNode}

            <div className="flex items-start gap-3 p-3 bg-amber-500/8 border border-amber-500/20 rounded-xl">
                <Warning size={15} className="text-amber-400 shrink-0 mt-0.5" />
                <p className="text-xs text-amber-300/80 leading-relaxed">
                    World section is for correcting stuck businesses and property definitions. Do not use to grant player advantages.
                </p>
            </div>

            <SubTabs
                tabs={[
                    { id: 'businesses', label: `Businesses (${businesses.length})` },
                    { id: 'properties', label: `Properties (${properties.length})` },
                ]}
                active={tab}
                onChange={(v) => setTab(v as any)}
            />

        
            {tab === 'businesses' && (
                <div className="space-y-3">
                    <TableShell
                        headers={['Business', 'City', 'Owner', 'Balance', 'Status', '']}
                        empty={businesses.length === 0 ? 'No businesses found' : undefined}
                    >
                        {pagedBusinesses.map((biz) => (
                            <tr key={biz.id} className="hover:bg-slate-900/30 transition">
                                <TD>
                                    <div>
                                        <div className="font-semibold text-white text-[13px]">{biz.name}</div>
                                        <div className="text-[10px] text-slate-600 font-mono">{biz.slug}</div>
                                    </div>
                                </TD>
                                <TD className="text-slate-400 text-xs">{biz.city?.name ?? '—'}</TD>
                                <TD>
                                    {biz.owner_id ? (
                                        <span className="text-amber-400 font-mono text-xs">#{biz.owner_id}</span>
                                    ) : (
                                        <span className="text-slate-700 text-xs italic">none</span>
                                    )}
                                </TD>
                                <TD className="text-emerald-400 font-mono text-xs tabular-nums">{formatCash(biz.balance ?? 0)}</TD>
                                <TD>
                                    <div className="flex gap-1.5">
                                        {biz.is_active ? <Badge label="Active" variant="green" /> : <Badge label="Inactive" variant="red" />}
                                        {biz.is_purchasable && <Badge label="For Sale" variant="cyan" />}
                                    </div>
                                </TD>
                                <TD className="text-right">
                                    <div className="flex gap-1 justify-end">
                                        <button
                                            onClick={() => openBiz(biz)}
                                            className="h-7 px-3 bg-slate-800 border border-slate-700 rounded-lg text-[10px] font-bold text-slate-400 hover:text-white hover:border-slate-600 transition"
                                        >
                                            Edit
                                        </button>
                                        {biz.owner_id && (
                                            <button
                                                onClick={() => clearBizOwner(biz)}
                                                className="h-7 px-3 bg-red-500/10 border border-red-500/20 rounded-lg text-[10px] font-bold text-red-400 hover:bg-red-500/20 transition"
                                            >
                                                Clear Owner
                                            </button>
                                        )}
                                    </div>
                                </TD>
                            </tr>
                        ))}
                    </TableShell>
                    {businessTotalPages > 1 && (
                        <div className="flex items-center justify-between mt-3 text-[11px] text-slate-500">
                            <span>
                                Page {businessPage} of {businessTotalPages}
                            </span>
                            <div className="flex gap-1.5">
                                <button
                                    type="button"
                                    disabled={businessPage === 1}
                                    onClick={() => setBusinessPage((p) => Math.max(1, p - 1))}
                                    className="h-7 px-3 rounded-lg border border-slate-800 bg-slate-900/60 disabled:opacity-40 text-[10px] font-bold uppercase tracking-wider"
                                >
                                    Prev
                                </button>
                                <button
                                    type="button"
                                    disabled={businessPage === businessTotalPages}
                                    onClick={() => setBusinessPage((p) => Math.min(businessTotalPages, p + 1))}
                                    className="h-7 px-3 rounded-lg border border-slate-800 bg-slate-900/60 disabled:opacity-40 text-[10px] font-bold uppercase tracking-wider"
                                >
                                    Next
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}

           
            {tab === 'properties' && (
                <div className="space-y-3">
                    <div className="flex justify-end">
                        <button
                            onClick={() => openProp()}
                            className="flex items-center gap-1.5 h-8 px-4 bg-amber-500/15 border border-amber-500/25 hover:bg-amber-500/25 rounded-lg text-xs font-black uppercase tracking-wider text-amber-300 transition"
                        >
                            <Plus size={12} weight="bold" /> New Property
                        </button>
                    </div>

                    <TableShell
                        headers={['Property', 'Price', 'Vehicles', 'Safe', 'Alarm', 'Bonuses', '']}
                        empty={properties.length === 0 ? 'No properties defined' : undefined}
                    >
                        {pagedProperties.map((p) => (
                            <tr key={p.id} className="hover:bg-slate-900/30 transition group">
                                <TD className="font-semibold text-white text-[13px]">{p.name}</TD>
                                <TD className="text-emerald-400 font-mono text-xs tabular-nums">${p.price.toLocaleString()}</TD>
                                <TD className="text-slate-400 font-mono text-xs">{p.vehicle_capacity}</TD>
                                <TD className="text-slate-400 font-mono text-xs">${(p.safe_capacity / 1000).toFixed(0)}K</TD>
                                <TD>
                                    {p.has_alarm ? (
                                        <Check size={13} className="text-emerald-400" weight="bold" />
                                    ) : (
                                        <span className="text-slate-700 text-xs">—</span>
                                    )}
                                </TD>
                                <TD>
                                    <div className="flex flex-wrap gap-1">
                                        {p.influence_bonus_pct > 0 && (
                                            <span className="text-[9px] font-bold px-1.5 py-0.5 rounded bg-purple-500/10 border border-purple-500/20 text-purple-400">
                                                INF+{p.influence_bonus_pct}%
                                            </span>
                                        )}
                                        {p.intelligence_bonus_pct > 0 && (
                                            <span className="text-[9px] font-bold px-1.5 py-0.5 rounded bg-cyan-500/10 border border-cyan-500/20 text-cyan-400">
                                                INT+{p.intelligence_bonus_pct}%
                                            </span>
                                        )}
                                        {p.offense_bonus_pct > 0 && (
                                            <span className="text-[9px] font-bold px-1.5 py-0.5 rounded bg-red-500/10 border border-red-500/20 text-red-400">
                                                OFF+{p.offense_bonus_pct}%
                                            </span>
                                        )}
                                        {p.defense_bonus_pct > 0 && (
                                            <span className="text-[9px] font-bold px-1.5 py-0.5 rounded bg-emerald-500/10 border border-emerald-500/20 text-emerald-400">
                                                DEF+{p.defense_bonus_pct}%
                                            </span>
                                        )}
                                        {!p.influence_bonus_pct && !p.intelligence_bonus_pct && !p.offense_bonus_pct && !p.defense_bonus_pct && (
                                            <span className="text-slate-700 text-xs">—</span>
                                        )}
                                    </div>
                                </TD>
                                <TD className="text-right">
                                    <button
                                        onClick={() => openProp(p)}
                                        className="opacity-0 group-hover:opacity-100 w-7 h-7 flex items-center justify-center rounded-lg bg-slate-800 border border-slate-700 text-slate-400 hover:text-amber-400 hover:border-amber-500/30 transition ml-auto"
                                    >
                                        <PencilSimple size={12} weight="bold" />
                                    </button>
                                </TD>
                            </tr>
                        ))}
                    </TableShell>
                    {propertyTotalPages > 1 && (
                        <div className="flex items-center justify-between mt-3 text-[11px] text-slate-500">
                            <span>
                                Page {propertyPage} of {propertyTotalPages}
                            </span>
                            <div className="flex gap-1.5">
                                <button
                                    type="button"
                                    disabled={propertyPage === 1}
                                    onClick={() => setPropertyPage((p) => Math.max(1, p - 1))}
                                    className="h-7 px-3 rounded-lg border border-slate-800 bg-slate-900/60 disabled:opacity-40 text-[10px] font-bold uppercase tracking-wider"
                                >
                                    Prev
                                </button>
                                <button
                                    type="button"
                                    disabled={propertyPage === propertyTotalPages}
                                    onClick={() => setPropertyPage((p) => Math.min(propertyTotalPages, p + 1))}
                                    className="h-7 px-3 rounded-lg border border-slate-800 bg-slate-900/60 disabled:opacity-40 text-[10px] font-bold uppercase tracking-wider"
                                >
                                    Next
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}

           
            <AdminModal
                isOpen={propModal}
                onClose={() => {
                    setPropModal(false);
                    setEditProp(null);
                }}
                title={editProp ? `Edit — ${editProp.name}` : 'New Property'}
                size="lg"
            >
                <form onSubmit={submitProp} className="space-y-4">
                    <AdminInput
                        label="Name"
                        value={propForm.data.name}
                        onChange={(e) => propForm.setData('name', e.target.value)}
                        required
                    />
                    <div className="grid grid-cols-2 gap-3">
                        <AdminInput
                            label="Price ($)"
                            type="number"
                            value={propForm.data.price}
                            onChange={(e) => propForm.setData('price', +e.target.value)}
                            min={0}
                            required
                        />
                        <AdminInput
                            label="Image URL"
                            value={propForm.data.image_url ?? ''}
                            onChange={(e) => propForm.setData('image_url', e.target.value)}
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <AdminInput
                            label="Vehicle Capacity"
                            type="number"
                            value={propForm.data.vehicle_capacity}
                            onChange={(e) => propForm.setData('vehicle_capacity', +e.target.value)}
                            min={0}
                            required
                        />
                        <AdminInput
                            label="Safe Capacity ($)"
                            type="number"
                            value={propForm.data.safe_capacity}
                            onChange={(e) => propForm.setData('safe_capacity', +e.target.value)}
                            min={0}
                            required
                        />
                    </div>
                    <div className="rounded-xl border border-slate-800/60 bg-slate-900/40 p-4 space-y-3">
                        <div className="text-[9px] font-black uppercase tracking-[0.14em] text-slate-600">Stat Bonus %</div>
                        <div className="grid grid-cols-2 gap-3">
                            <AdminInput label="Influence %" type="number" value={propForm.data.influence_bonus_pct} onChange={(e) => propForm.setData('influence_bonus_pct', +e.target.value)} min={0} />
                            <AdminInput label="Intelligence %" type="number" value={propForm.data.intelligence_bonus_pct} onChange={(e) => propForm.setData('intelligence_bonus_pct', +e.target.value)} min={0} />
                            <AdminInput label="Offense %" type="number" value={propForm.data.offense_bonus_pct} onChange={(e) => propForm.setData('offense_bonus_pct', +e.target.value)} min={0} />
                            <AdminInput label="Defense %" type="number" value={propForm.data.defense_bonus_pct} onChange={(e) => propForm.setData('defense_bonus_pct', +e.target.value)} min={0} />
                        </div>
                    </div>
                    <AdminToggle
                        label="Has Alarm"
                        checked={propForm.data.has_alarm}
                        onChange={(v) => propForm.setData('has_alarm', v)}
                    />
                    <FormActions
                        onCancel={() => {
                            setPropModal(false);
                            setEditProp(null);
                        }}
                        submitLabel={editProp ? 'Update' : 'Create'}
                        processing={propForm.processing}
                    />
                </form>
            </AdminModal>

          
            <AdminModal
                isOpen={bizModal}
                onClose={() => {
                    setBizModal(false);
                    setEditBiz(null);
                }}
                title={`Edit — ${editBiz?.name}`}
                size="md"
            >
                <form onSubmit={submitBiz} className="space-y-4">
                    <AdminInput
                        label="Name"
                        value={bizForm.data.name}
                        onChange={(e) => bizForm.setData('name', e.target.value)}
                        required
                    />
                    <AdminTextarea
                        label="Description"
                        value={bizForm.data.description}
                        onChange={(e) => bizForm.setData('description', e.target.value)}
                        rows={2}
                    />
                    <AdminInput
                        label="Base Price ($)"
                        type="number"
                        value={bizForm.data.base_price}
                        onChange={(e) => bizForm.setData('base_price', +e.target.value)}
                        min={0}
                    />
                    <AdminToggle
                        label="Purchasable"
                        detail="Listed for player purchase"
                        checked={bizForm.data.is_purchasable}
                        onChange={(v) => bizForm.setData('is_purchasable', v)}
                    />
                    <AdminToggle
                        label="Active"
                        detail="Business is operational"
                        checked={bizForm.data.is_active}
                        onChange={(v) => bizForm.setData('is_active', v)}
                    />
                    <FormActions
                        onCancel={() => {
                            setBizModal(false);
                            setEditBiz(null);
                        }}
                        submitLabel="Update"
                        processing={bizForm.processing}
                    />
                </form>
            </AdminModal>
        </div>
    );
}

