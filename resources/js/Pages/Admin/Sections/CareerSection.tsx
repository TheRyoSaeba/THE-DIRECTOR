import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { Briefcase, CaretLeft, CaretRight, Package, PencilSimple, Plus, Trash } from '@phosphor-icons/react';

import {
    AdminInput,
    AdminModal,
    AdminSelect,
    AdminTextarea,
    AdminToggle,
    Badge,
    FormActions,
    RangePair,
    SubTabs,
    TableShell,
    TD,
    useConfirm,
} from '../Components';
import StyledModal, { ActionButton } from '@/Layouts/styledmodal';

import type { Career, CareerEarn, GameItem } from './types';

export function CareerSection({
    careers,
    selectedCareer,
    earns,
    items,
}: {
    careers: Career[];
    selectedCareer?: Career;
    earns?: CareerEarn[];
    items: GameItem[];
}) {
    const [tab, setTab] = useState<'careers' | 'items'>('careers');
    const [earnModal, setEarnModal] = useState(false);
    const [editEarn, setEditEarn] = useState<CareerEarn | null>(null);
    const [itemModal, setItemModal] = useState(false);
    const [editItem, setEditItem] = useState<GameItem | null>(null);
    const [itemCursor, setItemCursor] = useState<number | null>(null);
    const [itemPage, setItemPage] = useState(1);
    const { confirm, ConfirmNode } = useConfirm();

    const ITEMS_PER_PAGE = 8;

    const totalItemPages = Math.max(1, Math.ceil(items.length / ITEMS_PER_PAGE));
    const pagedItems = items.slice(
        (itemPage - 1) * ITEMS_PER_PAGE,
        itemPage * ITEMS_PER_PAGE,
    );

    const blankEarn = {
        code: '',
        title: '',
        min_rank: 1,
        min_career_xp: 0,
        rng_min: 0,
        rng_max: 100,
        payout_min: 0,
        payout_max: 0,
        xp_gain_min: 0,
        xp_gain_max: 0,
        stat_intelligence_min: 0,
        stat_intelligence_max: 0,
        stat_offense_min: 0,
        stat_offense_max: 0,
        stat_defense_min: 0,
        stat_defense_max: 0,
        stat_luck_min: 0,
        stat_luck_max: 0,
        stat_influence_min: 0,
        stat_influence_max: 0,
        success_message: '',
        failure_message: '',
    };
    const earnForm = useForm(blankEarn);

    const openEarn = (earn?: CareerEarn) => {
        if (earn) {
            earnForm.setData(earn as any);
            setEditEarn(earn);
        } else {
            earnForm.reset();
            earnForm.setData(blankEarn as any);
            setEditEarn(null);
        }
        setEarnModal(true);
    };

    const submitEarn = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedCareer) return;
        const url = editEarn
            ? route('admin.careers.earns.update', { career: selectedCareer.id, earn: editEarn.id })
            : route('admin.careers.earns.create', { career: selectedCareer.id });
        earnForm.post(url, {
            onSuccess: () => {
                setEarnModal(false);
                setEditEarn(null);
            },
        });
    };




    const blankItem = {
        name: '',
        slug: '',
        type: 'weapon',
        slot: '',
        description: '',
        image_url: '',
        price: 0,
        durability: null as number | null,
        is_active: true,
        stock: null as number | null,
        max_stock: null as number | null,
        offense: 0,
        defense: 0,
        intelligence: 0,
        influence: 0,
        luck: 0,
        data: '',
    };
    const itemForm = useForm(blankItem as any);

    const openItem = (item?: GameItem) => {
        if (item) {
            const idx = items.findIndex((i) => i.id === item.id);
            setItemCursor(idx >= 0 ? idx : null);
            itemForm.setData({
                ...item,
                durability: item.durability,
                data: item.data ? JSON.stringify(item.data, null, 2) : '',
            } as any);
            setEditItem(item);
        } else {
            itemForm.reset();
            itemForm.setData(blankItem as any);
            setEditItem(null);
            setItemCursor(null);
        }
        setItemModal(true);
    };

    const goToItem = (direction: -1 | 1) => {
        if (itemCursor === null) return;
        const count = items.length;
        if (!count) return;
        const next = (itemCursor + direction + count) % count;
        const nextItem = items[next];
        setItemCursor(next);
        setEditItem(nextItem);
        itemForm.setData({
            ...nextItem,
            data: nextItem.data ? JSON.stringify(nextItem.data, null, 2) : '',
        } as any);
    };

    const submitItem = (e: React.FormEvent) => {
        e.preventDefault();
        const url = editItem ? route('admin.items.update', editItem.id) : route('admin.items.create');
        itemForm.post(url, {
            onSuccess: () => {
                setItemModal(false);
                setEditItem(null);
            },
        });
    };

    const deleteItem = (item: GameItem) => {
        confirm(
            `Delete "${item.name}"?`,
            () => router.delete(route('admin.items.delete', item.id)),
            'Item definition will be removed. Character instances remain until dropped.',
        );
    };

    const ITEM_TYPE_STYLE: Record<string, string> = {
        weapon: 'text-red-400 bg-red-500/10 border-red-500/20',
        armor: 'text-cyan-400 bg-cyan-500/10 border-cyan-500/20',
        gadget: 'text-purple-400 bg-purple-500/10 border-purple-500/20',
        vehicle: 'text-amber-400 bg-amber-500/10 border-amber-500/20',
        clothing: 'text-pink-400 bg-pink-500/10 border-pink-500/20',
        item: 'text-slate-400 bg-slate-800/50 border-slate-700/40',
    };

    const selectCareer = (career: Career) => {
        const isSame = selectedCareer?.id === career.id;
        router.get(
            route('admin.index'),
            { section: 'career', ...(isSame ? {} : { career_id: career.id }) },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <>
            {ConfirmNode}

            <SubTabs
                tabs={[
                    { id: 'careers', label: 'Careers & Earns' },
                    { id: 'items', label: `Items (${items.length})` },
                ]}
                active={tab}
                onChange={(v) => setTab(v as any)}
            />


            {tab === 'careers' && (
                <div className="flex gap-4 min-h-[600px]">

                    <div className="w-56 xl:w-64 shrink-0 space-y-1">
                        <div className="text-[9px] font-black uppercase tracking-[0.14em] text-slate-600 px-1 mb-2">
                            Careers
                        </div>
                        {careers.map((career) => {
                            const active = selectedCareer?.id === career.id;
                            return (
                                <button
                                    key={career.id}
                                    onClick={() => selectCareer(career)}
                                    className={[
                                        'w-full text-left px-3 py-2.5 rounded-xl border transition-all',
                                        active
                                            ? 'bg-amber-500/12 border-amber-500/30 shadow-[inset_0_0_16px_rgba(245,158,11,0.06)]'
                                            : 'bg-slate-900/40 border-slate-800/60 hover:border-slate-700 hover:bg-slate-900/70',
                                    ].join(' ')}
                                >
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <div className={`text-sm font-bold ${active ? 'text-amber-300' : 'text-slate-200'}`}>
                                                {career.name}
                                            </div>
                                            <div className="text-[10px] text-slate-600 font-mono mt-0.5">{career.code}</div>
                                        </div>
                                        <div className="text-right">
                                            <div className={`text-[11px] font-black tabular-nums ${active ? 'text-amber-400' : 'text-slate-500'}`}>
                                                {career.earns_count}
                                            </div>
                                            <div className="text-[9px] text-slate-700 uppercase tracking-wider">earns</div>
                                        </div>
                                    </div>
                                </button>
                            );
                        })}
                    </div>

                    <div className="flex-1 min-w-0">
                        {!selectedCareer ? (
                            <div className="h-full flex flex-col items-center justify-center gap-3 text-center border border-dashed border-slate-800/60 rounded-2xl">
                                <Briefcase size={28} className="text-slate-700" />
                                <div>
                                    <p className="text-sm font-bold text-slate-500">Select a career</p>
                                    <p className="text-xs text-slate-700 mt-0.5">Choose from the list to manage its earns</p>
                                </div>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                <div className="flex items-center justify-between px-1">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <div className="w-6 h-6 rounded-lg bg-amber-500/15 border border-amber-500/25 flex items-center justify-center">
                                                <Briefcase size={12} className="text-amber-400" weight="fill" />
                                            </div>
                                            <span className="text-sm font-black text-white">{selectedCareer.name}</span>
                                            <span className="text-[10px] text-slate-600 font-mono">{selectedCareer.code}</span>
                                        </div>
                                        <p className="text-[11px] text-slate-500 mt-1 ml-8">
                                            {earns?.length ?? 0} earns · {selectedCareer.ranks_count ?? 0} ranks
                                        </p>
                                    </div>
                                    <button
                                        onClick={() => openEarn()}
                                        className="flex items-center gap-1.5 h-8 px-4 bg-amber-500/15 border border-amber-500/25 hover:bg-amber-500/25 rounded-lg text-xs font-black uppercase tracking-wider text-amber-300 transition"
                                    >
                                        <Plus size={12} weight="bold" /> Add Earn
                                    </button>
                                </div>


                                {!earns || earns.length === 0 ? (
                                    <div className="flex flex-col items-center justify-center gap-2 py-14 border border-dashed border-slate-800/60 rounded-2xl">
                                        <Package size={22} className="text-slate-700" />
                                        <p className="text-xs text-slate-600 italic">No earns for this career yet</p>
                                        <button
                                            onClick={() => openEarn()}
                                            className="mt-1 h-7 px-4 bg-amber-500/10 border border-amber-500/20 rounded-lg text-[10px] font-black uppercase tracking-wider text-amber-400 hover:bg-amber-500/20 transition"
                                        >
                                            Create first earn
                                        </button>
                                    </div>
                                ) : (
                                    <TableShell
                                        headers={[
                                            'Title',
                                            'Code',
                                            'Min Rank',
                                            'Min XP',
                                            'Payout',
                                            'RNG',
                                            'XP Gain',
                                            'Stats',
                                            '',
                                        ]}
                                    >
                                        {earns.map((earn) => (
                                            <tr key={earn.id} className="hover:bg-slate-900/30 transition group">
                                                <TD className="font-semibold text-white text-[13px]">{earn.title}</TD>
                                                <TD>
                                                    <code className="text-[10px] text-slate-500 font-mono">{earn.code}</code>
                                                </TD>
                                                <TD className="text-slate-400 tabular-nums text-xs font-mono">≥{earn.min_rank}</TD>
                                                <TD className="text-cyan-500 tabular-nums text-xs font-mono">≥{earn.min_career_xp.toLocaleString()}</TD>
                                                <TD className="text-emerald-400 tabular-nums text-xs font-mono whitespace-nowrap">
                                                    ${earn.payout_min.toLocaleString()}–${earn.payout_max.toLocaleString()}
                                                </TD>
                                                <TD className="text-slate-500 tabular-nums text-xs font-mono">
                                                    {earn.rng_min}–{earn.rng_max}
                                                </TD>
                                                <TD className="text-purple-400 tabular-nums text-xs font-mono">
                                                    {earn.xp_gain_min}–{earn.xp_gain_max}
                                                </TD>
                                                <TD>
                                                    {earn.stat_offense_max > 0 ||
                                                        earn.stat_defense_max > 0 ||
                                                        earn.stat_intelligence_max > 0 ||
                                                        earn.stat_luck_max > 0 ||
                                                        earn.stat_influence_max > 0 ? (
                                                        <span className="text-[10px] text-purple-400 bg-purple-500/10 border border-purple-500/15 px-1.5 py-0.5 rounded font-bold">
                                                            +stats
                                                        </span>
                                                    ) : (
                                                        <span className="text-slate-700 text-xs">—</span>
                                                    )}
                                                </TD>
                                                <TD className="text-right">
                                                    <div className="flex gap-1 justify-end opacity-0 group-hover:opacity-100 transition">
                                                        <button
                                                            onClick={() => openEarn(earn)}
                                                            className="w-7 h-7 flex items-center justify-center rounded-lg bg-slate-800 border border-slate-700 text-slate-400 hover:text-amber-400 hover:border-amber-500/30 transition"
                                                        >
                                                            <PencilSimple size={12} weight="bold" />
                                                        </button>
                                                    </div>
                                                </TD>
                                            </tr>
                                        ))}
                                    </TableShell>
                                )}

                                {earns && earns.length > 0 && (() => {
                                    const avgXpMin = Math.round(earns.reduce((s, e) => s + e.xp_gain_min, 0) / earns.length);
                                    const avgXpMax = Math.round(earns.reduce((s, e) => s + e.xp_gain_max, 0) / earns.length);
                                    const avgXpMid = Math.round((avgXpMin + avgXpMax) / 2);
                                    const avgPayMin = Math.round(earns.reduce((s, e) => s + e.payout_min, 0) / earns.length);
                                    const avgPayMax = Math.round(earns.reduce((s, e) => s + e.payout_max, 0) / earns.length);
                                    const rngAvg = Math.round(earns.reduce((s, e) => s + (e.rng_max - e.rng_min), 0) / earns.length);

                                    return (
                                        <div className="mt-4 p-4 rounded-xl border border-slate-800/60 bg-slate-900/30">
                                            <div className="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500 mb-3">
                                                Earn Impact Legend
                                            </div>
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs text-slate-400">
                                                <div className="flex gap-2">
                                                    <div className="w-1 bg-purple-500/40 rounded-full shrink-0" />
                                                    <div>
                                                        <strong className="text-purple-300 block mb-0.5">XP per Work</strong>
                                                        Avg <span className="text-white font-mono">{avgXpMin}–{avgXpMax}</span> XP per Work.
                                                        {avgXpMid > 0 && (
                                                            <>
                                                                {' '}At midpoint (<span className="text-white font-mono">{avgXpMid}</span> XP),
                                                                reaching <span className="text-white font-mono">1,000</span> XP takes ~<span className="text-white font-mono">{Math.ceil(1000 / avgXpMid)}</span> Works.
                                                            </>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="flex gap-2">
                                                    <div className="w-1 bg-emerald-500/40 rounded-full shrink-0" />
                                                    <div>
                                                        <strong className="text-emerald-300 block mb-0.5">Payout Range</strong>
                                                        Avg <span className="text-white font-mono">${avgPayMin.toLocaleString()}–${avgPayMax.toLocaleString()}</span> per action.
                                                        {' '}RNG window avg <span className="text-white font-mono">{rngAvg}</span> pts (wider = more variance).
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })()}
                            </div>
                        )}
                    </div>
                </div>
            )}


            {tab === 'items' && (
                <div className="space-y-3">
                    <div className="flex justify-end">
                        <button
                            onClick={() => openItem()}
                            className="flex items-center gap-1.5 h-8 px-4 bg-amber-500/15 border border-amber-500/25 hover:bg-amber-500/25 rounded-lg text-xs font-black uppercase tracking-wider text-amber-300 transition"
                        >
                            <Plus size={12} weight="bold" /> New Item
                        </button>
                    </div>

                    <TableShell
                        headers={['Item', 'Type / Slot', 'Price', 'Dur', 'Stats', 'Stock', 'Status', '']}
                        empty={items.length === 0 ? 'No items defined' : undefined}
                    >
                        {pagedItems.map((item) => (
                            <tr key={item.id} className="hover:bg-slate-900/30 transition group">
                                <TD>
                                    <div className="flex items-center gap-2.5">
                                        <div className="w-12 h-12 rounded-xl bg-slate-900 border border-slate-800 overflow-hidden shrink-0 flex items-center justify-center">
                                            {item.image_url ? (
                                                <img src={item.image_url} alt="" className="w-full h-full object-cover" />
                                            ) : (
                                                <Package size={14} className="text-slate-600" />
                                            )}
                                        </div>
                                        <div>
                                            <div className="text-sm font-semibold text-white">{item.name}</div>
                                            <div className="text-xs text-slate-500 font-mono">{item.slug}</div>
                                        </div>
                                    </div>
                                </TD>
                                <TD>
                                    <span className={`text-[11px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded border ${ITEM_TYPE_STYLE[item.type]}`}>
                                        {item.type}
                                    </span>
                                    {item.slot && <span className="ml-1.5 text-xs text-slate-500">{item.slot}</span>}
                                </TD>
                                <TD className="text-emerald-400 font-mono text-xs tabular-nums">${item.price.toLocaleString()}</TD>
                                <TD className="text-slate-500 font-mono text-xs tabular-nums">{item.durability}</TD>
                                <TD>
                                    <div className="flex flex-wrap gap-x-2 gap-y-0.5 text-xs font-mono">
                                        {item.offense > 0 && <span className="text-red-400">OFF+{item.offense}</span>}
                                        {item.defense > 0 && <span className="text-cyan-400">DEF+{item.defense}</span>}
                                        {item.intelligence > 0 && <span className="text-purple-400">INT+{item.intelligence}</span>}
                                        {item.influence > 0 && <span className="text-amber-400">INF+{item.influence}</span>}
                                        {item.luck > 0 && <span className="text-yellow-400">LCK+{item.luck}</span>}
                                        {!item.offense && !item.defense && !item.intelligence && !item.influence && !item.luck && (
                                            <span className="text-slate-700">—</span>
                                        )}
                                    </div>
                                </TD>
                                <TD className="text-slate-400 font-mono text-xs">
                                    {item.stock != null ? `${item.stock}/${item.max_stock ?? '∞'}` : '∞'}
                                </TD>
                                <TD>{item.is_active ? <Badge label="Active" variant="green" /> : <Badge label="Inactive" variant="red" />}</TD>
                                <TD className="text-right">
                                    <div className="flex gap-1 justify-end opacity-0 group-hover:opacity-100 transition">
                                        <button
                                            onClick={() => openItem(item)}
                                            className="w-7 h-7 flex items-center justify-center rounded-lg bg-slate-800 border border-slate-700 text-slate-400 hover:text-amber-400 hover:border-amber-500/30 transition"
                                        >
                                            <PencilSimple size={12} weight="bold" />
                                        </button>
                                    </div>
                                </TD>
                            </tr>
                        ))}
                    </TableShell>
                    {totalItemPages > 1 && (
                        <div className="flex items-center justify-between mt-3 text-[11px] text-slate-500">
                            <span>
                                Page {itemPage} of {totalItemPages}
                            </span>
                            <div className="flex gap-1.5">
                                <button
                                    type="button"
                                    disabled={itemPage === 1}
                                    onClick={() => setItemPage((p) => Math.max(1, p - 1))}
                                    className="h-7 px-3 rounded-lg border border-slate-800 bg-slate-900/60 disabled:opacity-40 text-[10px] font-bold uppercase tracking-wider"
                                >
                                    Prev
                                </button>
                                <button
                                    type="button"
                                    disabled={itemPage === totalItemPages}
                                    onClick={() => setItemPage((p) => Math.min(totalItemPages, p + 1))}
                                    className="h-7 px-3 rounded-lg border border-slate-800 bg-slate-900/60 disabled:opacity-40 text-[10px] font-bold uppercase tracking-wider"
                                >
                                    Next
                                </button>
                            </div>
                        </div>
                    )}


                    <div className="mt-6 p-4 rounded-xl border border-slate-800/60 bg-slate-900/30">
                        <div className="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500 mb-3">
                            Item Configuration Rules
                        </div>
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs text-slate-400">
                            <div className="flex gap-2">
                                <div className="w-1 bg-slate-700 rounded-full shrink-0" />
                                <div>
                                    <strong className="text-slate-300 block mb-0.5">Slug Format</strong>
                                    Must be lowercase with dashes only (e.g., <code className="bg-slate-800 px-1 rounded text-slate-300">iron-sword</code>).
                                </div>
                            </div>
                            <div className="flex gap-2">
                                <div className="w-1 bg-slate-700 rounded-full shrink-0" />
                                <div>
                                    <strong className="text-slate-300 block mb-0.5">Vehicle Durability</strong>
                                    Vehicles and weapons cannot exceed <strong className="text-white">10</strong> durability.
                                </div>
                            </div>
                            <div className="flex gap-2">
                                <div className="w-1 bg-slate-700 rounded-full shrink-0" />
                                <div>
                                    <strong className="text-slate-300 block mb-0.5">Type & Slot Matching</strong>
                                    Vehicles → <code className="text-slate-300">vehicle</code> slot<br />
                                    Weapons → <code className="text-slate-300">weapon</code> slot<br />
                                    Armor → <code className="text-slate-300">armor</code> slot
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}


            <AdminModal
                isOpen={earnModal}
                onClose={() => {
                    setEarnModal(false);
                    setEditEarn(null);
                }}
                title={editEarn ? `Edit Earn — ${editEarn.title}` : `New Earn — ${selectedCareer?.name}`}
                size="3xl"
            >
                <form onSubmit={submitEarn} className="space-y-4">
                    <div className="grid grid-cols-2 gap-3">
                        <AdminInput
                            label="Code"
                            value={earnForm.data.code}
                            onChange={(e) => earnForm.setData('code', e.target.value)}
                            disabled={!!editEarn}
                            placeholder="police_patrol"
                            required
                        />
                        <AdminInput
                            label="Title"
                            value={earnForm.data.title}
                            onChange={(e) => earnForm.setData('title', e.target.value)}
                            required
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <AdminInput
                            label="Min Rank"
                            type="number"
                            value={earnForm.data.min_rank}
                            onChange={(e) => earnForm.setData('min_rank', +e.target.value)}
                            min={1}
                            required
                        />
                        <AdminInput
                            label="Min Career XP"
                            type="number"
                            value={earnForm.data.min_career_xp}
                            onChange={(e) => earnForm.setData('min_career_xp', +e.target.value)}
                            min={0}
                            required
                        />
                    </div>

                    <div className="rounded-xl border border-slate-800/60 bg-slate-900/40 p-4 space-y-3">
                        <div className="text-[9px] font-black uppercase tracking-[0.14em] text-slate-600">
                            Payout, XP & RNG
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <RangePair
                                label="Payout ($)"
                                minValue={earnForm.data.payout_min}
                                maxValue={earnForm.data.payout_max}
                                onMinChange={(v) => earnForm.setData('payout_min', v)}
                                onMaxChange={(v) => earnForm.setData('payout_max', v)}
                            />
                            <RangePair
                                label="XP Gain"
                                minValue={earnForm.data.xp_gain_min}
                                maxValue={earnForm.data.xp_gain_max}
                                onMinChange={(v) => earnForm.setData('xp_gain_min', v)}
                                onMaxChange={(v) => earnForm.setData('xp_gain_max', v)}
                            />
                        </div>
                        <RangePair
                            label="RNG Range (success threshold)"
                            minValue={earnForm.data.rng_min}
                            maxValue={earnForm.data.rng_max}
                            onMinChange={(v) => earnForm.setData('rng_min', v)}
                            onMaxChange={(v) => earnForm.setData('rng_max', v)}
                        />
                    </div>

                    <div className="rounded-xl border border-slate-800/60 bg-slate-900/40 p-4 space-y-3">
                        <div className="text-[9px] font-black uppercase tracking-[0.14em] text-slate-600">
                            Stat Gains (optional)
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <RangePair
                                label="Intelligence"
                                minValue={earnForm.data.stat_intelligence_min}
                                maxValue={earnForm.data.stat_intelligence_max}
                                onMinChange={(v) => earnForm.setData('stat_intelligence_min', v)}
                                onMaxChange={(v) => earnForm.setData('stat_intelligence_max', v)}
                            />
                            <RangePair
                                label="Offense"
                                minValue={earnForm.data.stat_offense_min}
                                maxValue={earnForm.data.stat_offense_max}
                                onMinChange={(v) => earnForm.setData('stat_offense_min', v)}
                                onMaxChange={(v) => earnForm.setData('stat_offense_max', v)}
                            />
                            <RangePair
                                label="Defense"
                                minValue={earnForm.data.stat_defense_min}
                                maxValue={earnForm.data.stat_defense_max}
                                onMinChange={(v) => earnForm.setData('stat_defense_min', v)}
                                onMaxChange={(v) => earnForm.setData('stat_defense_max', v)}
                            />
                            <RangePair
                                label="Luck"
                                minValue={earnForm.data.stat_luck_min}
                                maxValue={earnForm.data.stat_luck_max}
                                onMinChange={(v) => earnForm.setData('stat_luck_min', v)}
                                onMaxChange={(v) => earnForm.setData('stat_luck_max', v)}
                            />
                            <RangePair
                                label="Influence"
                                minValue={earnForm.data.stat_influence_min}
                                maxValue={earnForm.data.stat_influence_max}
                                onMinChange={(v) => earnForm.setData('stat_influence_min', v)}
                                onMaxChange={(v) => earnForm.setData('stat_influence_max', v)}
                            />
                        </div>
                    </div>

                    <AdminTextarea
                        label="Success Message (use {payout})"
                        value={earnForm.data.success_message}
                        onChange={(e) => earnForm.setData('success_message', e.target.value)}
                        rows={2}
                        required
                    />
                    <AdminTextarea
                        label="Failure Message"
                        value={earnForm.data.failure_message}
                        onChange={(e) => earnForm.setData('failure_message', e.target.value)}
                        rows={2}
                        required
                    />

                    <FormActions
                        onCancel={() => {
                            setEarnModal(false);
                            setEditEarn(null);
                        }}
                        submitLabel={editEarn ? 'Update Earn' : 'Create Earn'}
                        processing={earnForm.processing}
                    />
                </form>
            </AdminModal>


            <StyledModal
                isOpen={itemModal}
                onClose={() => {
                    setItemModal(false);
                    setEditItem(null);
                }}
                headerImage={itemForm.data.image_url}
                title={editItem ? editItem.name : 'New Item'}
                subtitle={editItem ? editItem.slug : 'Define a new game item'}
                badges={
                    editItem
                        ? [
                            {
                                text: editItem.type,
                                color:
                                    editItem.type === 'weapon'
                                        ? 'red'
                                        : editItem.type === 'armor'
                                            ? 'cyan'
                                            : editItem.type === 'gadget'
                                                ? 'purple'
                                                : editItem.type === 'vehicle'
                                                    ? 'amber'
                                                    : 'slate',
                            },
                            {
                                text: editItem.is_active ? 'Active' : 'Inactive',
                                color: editItem.is_active ? 'emerald' : 'red',
                            },
                        ]
                        : [
                            {
                                text: 'Draft',
                                color: 'slate',
                            },
                        ]
                }
                maxWidth="max-w-4xl"
            >
                <div className="p-6 space-y-5 ">
                    {editItem && (
                        <div className="flex items-center justify-between mb-4 bg-slate-950/50 p-3 rounded-xl border border-slate-800/50 shadow-inner">
                            <div className="flex flex-col">
                                <span className="text-[10px] text-slate-500 font-mono tracking-widest uppercase">
                                    Item ID #{editItem.id}
                                </span>
                                <span className="text-sm text-slate-300 font-mono font-bold tracking-tight">
                                    {editItem.slug}
                                </span>
                            </div>
                            {itemCursor !== null && items.length > 1 && (
                                <div className="flex gap-2">
                                    <button
                                        type="button"
                                        onClick={() => goToItem(-1)}
                                        className="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-bold transition-all hover:shadow-lg hover:shadow-cyan-500/10 active:scale-95 border border-slate-700 hover:border-slate-600"
                                    >
                                        <CaretLeft weight="bold" /> Prev
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => goToItem(1)}
                                        className="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-bold transition-all hover:shadow-lg hover:shadow-cyan-500/10 active:scale-95 border border-slate-700 hover:border-slate-600"
                                    >
                                        Next <CaretRight weight="bold" />
                                    </button>
                                </div>
                            )}
                        </div>
                    )}

                    <form onSubmit={submitItem} className="space-y-5">

                        <div className="grid grid-cols-12 gap-4 items-start">
                            <div className="col-span-4">
                                <AdminInput
                                    label="Name"
                                    value={itemForm.data.name}
                                    onChange={(e) => itemForm.setData('name', e.target.value)}
                                    error={itemForm.errors.name}
                                    required
                                />
                            </div>
                            <div className="col-span-3">
                                <AdminInput
                                    label="Slug"
                                    value={itemForm.data.slug}
                                    onChange={(e) => itemForm.setData('slug', e.target.value)}
                                    disabled={!!editItem}
                                    error={itemForm.errors.slug}
                                    placeholder="steel-knife"
                                    required
                                />
                            </div>
                            <div className="col-span-3">
                                <AdminSelect
                                    label="Type"
                                    value={itemForm.data.type}
                                    onChange={(e) => itemForm.setData('type', e.target.value as any)}
                                    error={itemForm.errors.type}
                                    required
                                >
                                    <option value="weapon">Weapon</option>
                                    <option value="armor">Armor</option>
                                    <option value="gadget">Gadget</option>
                                    <option value="vehicle">Vehicle</option>
                                    <option value="clothing">Clothing</option>
                                    <option value="item">Item</option>
                                </AdminSelect>
                            </div>
                            <div className="col-span-2 pt-6 flex justify-end">
                                <AdminToggle
                                    label="Active"
                                    checked={itemForm.data.is_active}
                                    onChange={(v) => itemForm.setData('is_active', v)}
                                />
                            </div>
                        </div>


                        <div className="grid grid-cols-4 gap-4">
                            <AdminInput
                                label="Slot"
                                value={itemForm.data.slot ?? ''}
                                onChange={(e) => itemForm.setData('slot', e.target.value)}
                                error={itemForm.errors.slot}
                                placeholder="e.g. head"
                            />
                            <AdminInput
                                label="Price ($)"
                                type="number"
                                value={itemForm.data.price}
                                onChange={(e) => itemForm.setData('price', +e.target.value)}
                                min={0}
                                error={itemForm.errors.price}
                                required
                            />
                            <AdminInput
                                label="Durability"
                                type="number"
                                value={itemForm.data.durability ?? ''}
                                onChange={(e) => itemForm.setData('durability', e.target.value === '' ? null : +e.target.value)}
                                min={1}
                                error={itemForm.errors.durability}
                                placeholder="∞"
                            />
                            <AdminInput
                                label="Image URL"
                                value={itemForm.data.image_url ?? ''}
                                onChange={(e) => itemForm.setData('image_url', e.target.value)}
                                error={itemForm.errors.image_url}
                                placeholder="https://…"
                            />
                        </div>


                        <div>
                            <AdminTextarea
                                label="Description"
                                value={itemForm.data.description ?? ''}
                                onChange={(e) => itemForm.setData('description', e.target.value)}
                                error={itemForm.errors.description}
                                rows={1}
                            />
                        </div>


                        <div className="p-4 rounded-xl border border-slate-800/60 bg-slate-900/40 shadow-sm">
                            <div className="flex items-center justify-between mb-3">
                                <div className="text-[9px] font-black uppercase tracking-[0.14em] text-cyan-500/80">
                                    Combat Stats
                                </div>
                                <div className="text-[9px] font-black uppercase tracking-[0.14em] text-emerald-500/80">
                                    Inventory Limits
                                </div>
                            </div>
                            <div className="grid grid-cols-7 gap-3">
                                <AdminInput label="OFF" type="number" value={itemForm.data.offense} onChange={(e) => itemForm.setData('offense', +e.target.value)} min={0} className="text-center font-mono font-bold text-red-400" />
                                <AdminInput label="DEF" type="number" value={itemForm.data.defense} onChange={(e) => itemForm.setData('defense', +e.target.value)} min={0} className="text-center font-mono font-bold text-cyan-400" />
                                <AdminInput label="INT" type="number" value={itemForm.data.intelligence} onChange={(e) => itemForm.setData('intelligence', +e.target.value)} min={0} className="text-center font-mono font-bold text-purple-400" />
                                <AdminInput label="INF" type="number" value={itemForm.data.influence} onChange={(e) => itemForm.setData('influence', +e.target.value)} min={0} className="text-center font-mono font-bold text-amber-400" />
                                <AdminInput label="LCK" type="number" value={itemForm.data.luck} onChange={(e) => itemForm.setData('luck', +e.target.value)} min={0} className="text-center font-mono font-bold text-pink-400" />
                                <div className="col-span-1 border-l border-slate-700/50 pl-3">
                                    <AdminInput
                                        label="Stock"
                                        type="number"
                                        value={itemForm.data.stock ?? ''}
                                        onChange={(e) => itemForm.setData('stock', e.target.value === '' ? null : +e.target.value)}
                                        placeholder="∞"
                                        className="text-center bg-slate-900/50"
                                    />
                                </div>
                                <div className="col-span-1">
                                    <AdminInput
                                        label="Max"
                                        type="number"
                                        value={itemForm.data.max_stock ?? ''}
                                        onChange={(e) => itemForm.setData('max_stock', e.target.value === '' ? null : +e.target.value)}
                                        placeholder="∞"
                                        className="text-center bg-slate-900/50"
                                    />
                                </div>
                            </div>
                        </div>


                        <div>
                            <div className="flex justify-between items-end mb-1">
                                <label className="text-[10px] font-bold text-slate-500 uppercase tracking-wider">JSON Data</label>
                            </div>
                            <textarea
                                value={itemForm.data.data ?? ''}
                                onChange={(e) => itemForm.setData('data', e.target.value)}
                                className="w-full bg-slate-950/50 border border-slate-800 rounded-lg px-3 py-2 text-xs font-mono text-slate-300 focus:outline-none focus:border-amber-500/50 focus:ring-1 focus:ring-amber-500/50 placeholder:text-slate-700 transition h-12 min-h-[48px] resize-none"
                                placeholder='{"bonuses": ...}'
                            />
                        </div>

                        <div className="flex justify-end gap-3 pt-4 border-t border-slate-800/50">
                            <button
                                type="button"
                                onClick={() => {
                                    setEditItem(null);
                                }}
                                className="px-4 py-2 rounded-lg text-xs font-bold uppercase tracking-wider text-slate-400 hover:text-slate-200 hover:bg-slate-800 transition-colors"
                            >
                                Cancel
                            </button>

                            <button
                                type="submit"
                                disabled={itemForm.processing}
                                className="px-6 py-2 rounded-lg bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-xs font-black uppercase tracking-widest shadow-lg shadow-emerald-500/20 hover:shadow-emerald-500/30 active:scale-95 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {itemForm.processing
                                    ? editItem
                                        ? 'Saving...'
                                        : 'Creating...'
                                    : editItem
                                        ? 'Update Item'
                                        : 'Create Item'}
                            </button>
                        </div>
                    </form>
                </div>
            </StyledModal>
        </>
    );
}

