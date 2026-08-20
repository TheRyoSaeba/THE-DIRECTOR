import { useEffect, useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
// @ts-ignore
import { route } from 'ziggy-js';
import {
    ShoppingBag, ArrowsClockwise, Buildings,
    CaretLeft, CaretRight, Heart, Gear,
} from '@phosphor-icons/react';
import { motion } from 'framer-motion';
import GameLayout from '@/Layouts/GameLayout';
import ShopModal from '@/Components/ShopModal';
import { getCityImage } from '@/utils/cityImages';

// ─────────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────────

interface ShopItem {
    id: number;
    name: string;
    slug: string;
    price: number;
    image_url: string | null;
    is_active: boolean;
    attributes: {
        slot?: string | null;
        stock?: number;
        description?: string | null;
    };
}

interface ShopData {
    name: string;
    description: string;
    image_url: string | null;
    balance: number | null;
    owner_name: string;
    owner_avatar: string | null;
    is_owner: boolean;
    slug: string;
    code: string;
    can_restock: boolean;
    next_restock_at?: number | null;
    has_insurance?: boolean;
    insurance_expires_at?: number | null;
    insurance_premium?: number | null;
}

interface Props {
    city: { name: string; slug: string };
    shop: ShopData;
    products: ShopItem[];
    character: { money: number; inventory_items: { slug: string; location: string }[] };
}

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────

const fmt$ = (n: number) => `$${Math.round(n).toLocaleString()}`;
const ITEMS_PER_PAGE = 8;
const INSURANCE_DISCOUNT = 30;

function detectShopKind(code: string): 'pharmacy' | 'vehicle' | 'weapon' | 'general' {
    const c = code.toLowerCase();
    if (c.includes('pharmacy')) return 'pharmacy';
    if (c.includes('vehicle')) return 'vehicle';
    if (c.includes('ammunation') || c.includes('bullets-and-mullets') || c.includes('bullets-mullets')) return 'weapon';
    return 'general';
}

function useRestockCountdown(target: number | null | undefined, canRestock: boolean): string {
    const [label, setLabel] = useState('');
    useEffect(() => {
        if (!target || canRestock) { setLabel(''); return; }
        const tick = () => {
            const diff = (target as number) - Math.floor(Date.now() / 1000);
            if (diff <= 0) { setLabel('Ready'); return; }
            const h = Math.floor(diff / 3600);
            const m = Math.floor((diff % 3600) / 60);
            const s = diff % 60;
            setLabel(`${h}h ${m}m ${s}s`);
        };
        tick();
        const id = setInterval(tick, 1000);
        return () => clearInterval(id);
    }, [target, canRestock]);
    return label;
}

// ─────────────────────────────────────────────────────────────
// Item row - flat, compact
// ─────────────────────────────────────────────────────────────

function ItemRow({
    item, character, shop, kind, processing, onBuy,
}: {
    item: ShopItem;
    character: Props['character'];
    shop: ShopData;
    kind: ReturnType<typeof detectShopKind>;
    processing: number | string | null;
    onBuy: (item: ShopItem) => void;
}) {
    const stock = item.attributes?.stock ?? 0;
    const hasStock = stock > 0;
    const owned = character.inventory_items?.find(i => i.slug === item.slug);

    const insured = kind === 'pharmacy' && shop.has_insurance;
    const effectivePrice = insured
        ? Math.round(item.price * (1 - INSURANCE_DISCOUNT / 100))
        : item.price;

    const dim = !item.is_active;

    return (
        <div className={`grid grid-cols-[3rem_minmax(0,1fr)] items-center gap-x-3 gap-y-3 px-4 py-3 border-b border-white/[0.03] last:border-0 transition-colors nav:grid-cols-[3.5rem_minmax(0,1fr)_5rem_7rem_auto] nav:gap-4 nav:px-5 nav:py-3.5 ${dim ? 'opacity-40' : 'hover:bg-white/[0.02]'}`}>
            {/* Thumbnail */}
            <div className="h-12 w-12 shrink-0 rounded-xl bg-slate-950/60 border border-white/[0.05] flex items-center justify-center overflow-hidden nav:h-14 nav:w-14">
                {item.image_url
                    ? <img src={item.image_url} alt="" className="h-10 w-10 object-contain" />
                    : <ShoppingBag size={18} className="text-slate-700" />
                }
            </div>

            {/* Name + meta */}
            <div className="min-w-0">
                <div className="flex flex-wrap items-baseline gap-2">
                    <span className="min-w-0 text-sm font-semibold text-white nav:truncate">{item.name}</span>
                    {owned && (
                        <span className={`text-[9px] font-black uppercase tracking-widest px-1.5 py-0.5 rounded ${
                            owned.location === 'on_hand'
                                ? 'bg-emerald-500/10 text-emerald-400'
                                : 'bg-amber-500/10 text-amber-400'
                        }`}>
                            {owned.location === 'on_hand' ? 'Owned' : 'Stored'}
                        </span>
                    )}
                </div>
                {item.attributes?.description && (
                    <p className="mt-0.5 truncate text-xs text-slate-300/85">{item.attributes.description}</p>
                )}
            </div>

            {/* Stock indicator */}
            <div className="text-left nav:text-right">
                {hasStock ? (
                    <div className="text-xs font-mono text-slate-400 tabular-nums">
                        {stock} <span className="text-slate-600">in stock</span>
                    </div>
                ) : (
                    <span className="text-[10px] font-black uppercase tracking-widest text-red-400/70">Out</span>
                )}
            </div>

            {/* Price */}
            <div className="text-right nav:w-28 nav:shrink-0">
                {insured && (
                    <div className="text-[10px] text-slate-600 line-through font-mono tabular-nums">
                        {fmt$(item.price)}
                    </div>
                )}
                <div className={`text-sm font-bold tabular-nums ${insured ? 'text-emerald-400' : 'text-white'}`}>
                    {fmt$(effectivePrice)}
                </div>
            </div>

            {/* Buy button */}
            <button
                onClick={() => onBuy(item)}
                disabled={!hasStock || !!processing}
                className={`col-span-2 min-h-10 rounded-lg px-4 py-2 text-[10px] font-black uppercase tracking-widest transition-colors nav:col-span-1 nav:shrink-0 ${
                    !hasStock || processing
                        ? 'bg-slate-800/60 text-slate-600 cursor-not-allowed'
                        : 'bg-emerald-700 hover:bg-emerald-600 text-white'
                }`}
            >
                {processing === item.id ? '...' : 'Buy'}
            </button>

        </div>
    );
}


function OwnerControls({
    shop, kind, processing, onRestock, onSavePremium,
}: {
    shop: ShopData;
    kind: ReturnType<typeof detectShopKind>;
    processing: number | string | null;
    onRestock: () => void;
    onSavePremium: (premium: number) => void;
}) {
    const [premium, setPremium] = useState(String(shop.insurance_premium ?? 50000));
    const restockTimer = useRestockCountdown(shop.next_restock_at, shop.can_restock);

    const savePremium = () => {
        const val = Math.max(10000, Math.min(100000, parseInt(premium) || 50000));
        setPremium(String(val));
        onSavePremium(val);
    };

    return (
        <motion.aside
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.18 }}
            className="order-first overflow-hidden rounded-xl border border-slate-700/40 bg-slate-900/60 shadow-lg nav:order-none"
        >
            <div className="p-5">

                <div className="flex items-center gap-3 mb-5">
                    <div className="w-9 h-9 bg-amber-500/10 border border-amber-500/20 rounded-xl flex items-center justify-center">
                        <Gear size={16} weight="bold" className="text-amber-300" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-[10px] font-black uppercase tracking-[0.18em] text-cyan-300/80">Owner Controls</p>
                        <h3 className="mt-0.5 text-sm font-black uppercase tracking-[0.12em] text-white truncate">{shop.name}</h3>
                    </div>
                </div>

                <div className="space-y-4">
                    {/* Restock */}
                    <div className="rounded-xl border border-white/[0.05] bg-slate-950/45 p-4">
                        <div className="mb-3 flex items-center justify-between gap-3">
                            <div>
                                <p className="text-xs font-black uppercase tracking-[0.18em] text-slate-300">Restock</p>
                                {shop.can_restock && <p className="mt-1 text-xs text-slate-400">Ready now.</p>}
                            </div>
                            {!shop.can_restock && restockTimer && (
                                <span className="rounded-lg border border-amber-500/20 bg-amber-500/10 px-2.5 py-1 text-xs font-mono font-bold tabular-nums text-amber-200">{restockTimer}</span>
                            )}
                        </div>
                        <button
                            onClick={onRestock}
                            disabled={!shop.can_restock || !!processing}
                            className={`min-h-11 w-full rounded-xl text-xs font-black uppercase tracking-widest flex items-center justify-center gap-2 transition-colors ${
                                shop.can_restock && !processing
                                    ? 'bg-emerald-700 hover:bg-emerald-600 text-white'
                                    : 'bg-slate-800/60 text-slate-500 cursor-not-allowed'
                            }`}
                        >
                            <ArrowsClockwise size={12} weight="bold" className={processing === 'restock' ? 'animate-spin' : ''} />
                            Restock Catalog
                        </button>
                    </div>

                    {/* Insurance premium (pharmacy only) */}
                    {kind === 'pharmacy' && (
                        <div className="rounded-xl border border-white/[0.05] bg-slate-950/45 p-4">
                            <div className="mb-3 flex items-center justify-between gap-3">
                                <div>
                                    <p className="text-xs font-black uppercase tracking-[0.18em] text-slate-300">Insurance Premium</p>
                                    <p className="mt-1 text-xs text-slate-400">Applies to new coverage purchases.</p>
                                </div>
                                <span className="rounded-lg border border-white/[0.07] bg-slate-900 px-2.5 py-1 text-xs font-mono font-bold tabular-nums text-white">
                                    {fmt$(Math.max(10000, Math.min(100000, parseInt(premium) || 50000)))}
                                </span>
                            </div>
                            <input
                                type="range"
                                min={10000}
                                max={100000}
                                step={5000}
                                value={Math.max(10000, Math.min(100000, parseInt(premium) || 50000))}
                                onChange={e => setPremium(e.target.value)}
                                className="w-full h-1.5 bg-slate-800 rounded-full appearance-none cursor-pointer accent-white"
                            />
                            <div className="mt-1.5 flex justify-between text-[9px] font-mono text-slate-500">
                                <span>$10k</span>
                                <span>$100k</span>
                            </div>
                            <button
                                onClick={savePremium}
                                disabled={!!processing}
                                className={`mt-4 min-h-10 w-full rounded-xl text-xs font-black uppercase tracking-widest transition-colors ${
                                    processing
                                        ? 'bg-slate-800/60 text-slate-500 cursor-not-allowed'
                                        : 'bg-white text-slate-900 hover:bg-slate-100'
                                }`}
                            >
                                {processing === 'premium' ? 'Saving...' : 'Save Premium'}
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </motion.aside>
    );
}

// ─────────────────────────────────────────────────────────────
// Root
// ─────────────────────────────────────────────────────────────

export default function Shop({ city, shop, products, character }: Props) {
    const [selectedItem, setSelectedItem] = useState<ShopItem | null>(null);
    const [processing, setProcessing] = useState<number | string | null>(null);
    const [page, setPage] = useState(1);

    const kind = useMemo(() => detectShopKind(shop.code), [shop.code]);
    const heroImage = shop.image_url || getCityImage(city.name);

    // Categories from slot attribute. Only show the pill bar when there's more
    // than one. Many shops have one or no slot - pills would be noise.
    const categories = useMemo(() => {
        const slots = products.map(p => p.attributes?.slot).filter((s): s is string => !!s);
        return Array.from(new Set(slots)).sort();
    }, [products]);

    const [activeCategory, setActiveCategory] = useState<string | null>(categories[0] ?? null);
    useEffect(() => { setPage(1); }, [activeCategory]);

    const filtered = useMemo(() => {
        if (!activeCategory || categories.length === 0) return products;
        return products.filter(p => !p.attributes?.slot || p.attributes.slot === activeCategory);
    }, [products, activeCategory, categories.length]);

    const totalPages = Math.max(1, Math.ceil(filtered.length / ITEMS_PER_PAGE));
    const safePage = Math.min(page, totalPages);
    const visible = filtered.slice((safePage - 1) * ITEMS_PER_PAGE, safePage * ITEMS_PER_PAGE);

    // ── Actions ──────────────────────────────────────────────
    const executePurchase = (item: ShopItem) => {
        if (processing) return;
        setProcessing(item.id);
        router.post(
            route('city.shop.purchase', { city: city.slug, business_slug: shop.slug }),
            { item_slug: item.slug },
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(null);
                    setSelectedItem(null);
                },
            }
        );
    };

    const restock = () => {
        if (processing) return;
        setProcessing('restock');
        router.post(
            route('city.shop.restock', { city: city.slug, business_slug: shop.slug }),
            {},
            { preserveScroll: true, onFinish: () => setProcessing(null) }
        );
    };

    const saveInsurancePremium = (premium: number) => {
        if (processing) return;
        setProcessing('premium');
        router.post(
            route('city.shop.settings', { city: city.slug, business_slug: shop.slug }),
            { insurance_premium: premium },
            { preserveScroll: true, onFinish: () => setProcessing(null) }
        );
    };

    const buyInsurance = () => {
        if (processing) return;
        setProcessing('insurance');
        router.post(
            route('city.shop.insurance', { city: city.slug, business_slug: shop.slug }),
            {},
            { preserveScroll: true, onFinish: () => setProcessing(null) }
        );
    };

    return (
        <>
            <Head title={`${shop.name} - ${city.name}`} />

            <ShopModal
                item={selectedItem}
                onClose={() => setSelectedItem(null)}
                onConfirm={item => executePurchase(item)}
                processing={processing === selectedItem?.id}
                characterMoney={character.money}
                shopType={kind === 'weapon' || kind === 'general' ? 'general' : kind}
                hasInsurance={shop.has_insurance}
                insuranceDiscount={INSURANCE_DISCOUNT}
            />

            <div className="w-full max-w-6xl mx-auto px-2 sm:px-4 py-3 nav:py-6 flex flex-col gap-4 nav:gap-6 h-full">

                {/* ── Hero ─────────────────────────────────────────── */}
                <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4 }}
                    className="relative rounded-t-2xl overflow-hidden border border-white/5 border-b-0 shadow-2xl shrink-0 min-h-[13rem] nav:min-h-[16rem] flex flex-col justify-end"
                >
                    <div className="absolute inset-0 bg-slate-900 overflow-hidden">
                        <img src={heroImage} className="absolute inset-0 w-full h-full object-cover" alt="" />
                        <div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/80 to-transparent" />
                        <div className="absolute inset-0 bg-gradient-to-r from-slate-950/80 to-transparent" />
                    </div>

                    <div className="relative px-5 pt-8 pb-5 nav:px-10 nav:pb-7">
                        <div className="flex flex-col nav:flex-row nav:items-end justify-between gap-5">
                            <div className="max-w-2xl">

                                <h1 className="text-2xl sm:text-3xl nav:text-4xl xl:text-5xl font-light text-white tracking-tight leading-none mb-3">
                                    {shop.name}
                                </h1>
                                {shop.description && (
                                    <p className="text-sm text-slate-400 max-w-xl leading-relaxed">{shop.description}</p>
                                )}
                            </div>

                            <div className="flex items-center gap-3 shrink-0">
                                <div className="w-10 h-10 nav:w-12 nav:h-12 rounded-full overflow-hidden border border-amber-500/30 bg-slate-800 shrink-0 flex items-center justify-center">
                                    {shop.owner_avatar
                                        ? <img src={shop.owner_avatar} className="w-full h-full object-cover" alt="" />
                                        : <Buildings size={16} className="text-slate-600" />
                                    }
                                </div>
                                <div>
                                    <p className="text-[9px] font-black uppercase tracking-widest text-amber-400/80">Owner</p>
                                    <p className="text-sm nav:text-base font-semibold text-white">{shop.owner_name}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </motion.div>

                {/* ── Insurance status pill (pharmacy only) ────────── */}
                {kind === 'pharmacy' && (
                    <div className="shrink-0 flex flex-col gap-3 rounded-xl border border-white/[0.05] bg-slate-900/60 px-4 py-3 nav:flex-row nav:items-center nav:py-2.5">
                        <Heart size={14} weight="fill" className={shop.has_insurance ? 'text-emerald-400' : 'text-slate-600'} />
                        <span className="text-xs text-slate-300 flex-1">
                            {shop.has_insurance
                                ? <>Insurance active - <span className="text-emerald-400 font-bold">{INSURANCE_DISCOUNT}% off</span> all drugs.</>
                                : <>Buy insurance to get {INSURANCE_DISCOUNT}% off all drugs for 7 days.</>
                            }
                        </span>
                        {!shop.has_insurance && (
                            <button
                                onClick={buyInsurance}
                                disabled={!!processing || character.money < (shop.insurance_premium ?? 50000)}
                                className={`min-h-10 w-full rounded-lg px-4 py-1.5 text-[10px] font-black uppercase tracking-widest transition-colors nav:min-h-0 nav:w-auto nav:min-w-44 ${
                                    character.money < (shop.insurance_premium ?? 50000) || processing
                                        ? 'bg-slate-800/60 text-slate-600 cursor-not-allowed'
                                        : 'bg-emerald-700 hover:bg-emerald-600 text-white'
                                }`}
                            >
                                {processing === 'insurance'
                                    ? 'Buying...'
                                    : `Buy Insurance - ${fmt$(shop.insurance_premium ?? 50000)}`}
                            </button>
                        )}
                    </div>
                )}

                {/* ── Filter strip ─────────────────────────────────── */}
                {categories.length > 1 && (
                    <div className="shrink-0 flex items-center gap-3 px-1">
                        <div className="flex gap-1.5 overflow-x-auto no-scrollbar flex-1 min-w-0">
                            {categories.map(cat => {
                                const active = activeCategory === cat;
                                return (
                                    <button
                                        key={cat}
                                        onClick={() => setActiveCategory(cat)}
                                        className={`shrink-0 px-3.5 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest border transition-colors ${
                                            active
                                                ? 'border-cyan-500/40 bg-cyan-500/10 text-cyan-300'
                                                : 'border-white/[0.06] text-slate-500 hover:text-slate-300 hover:border-white/10'
                                        }`}
                                    >
                                        {cat}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                )}

                {/* ── Item list ────────────────────────────────────── */}
                <div className={`grid items-start gap-4 ${shop.is_owner ? 'xl:grid-cols-[minmax(0,1fr)_20rem]' : ''}`}>
                    <div className="bg-slate-900/60 border border-slate-700/40 rounded-xl shadow-lg overflow-hidden flex flex-col">
                        {visible.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-16 text-slate-700 gap-3">
                                <ShoppingBag size={28} />
                                <p className="text-xs uppercase tracking-widest">No items available</p>
                            </div>
                        ) : (
                            <>
                                <div>
                                    {visible.map(item => (
                                        <ItemRow
                                            key={item.id}
                                            item={item}
                                            character={character}
                                            shop={shop}
                                            kind={kind}
                                            processing={processing}
                                            onBuy={setSelectedItem}
                                        />
                                    ))}
                                </div>

                                {totalPages > 1 && (
                                    <div className="flex items-center justify-between px-5 py-3 border-t border-white/[0.06] bg-slate-950/60 shrink-0">
                                        <span className="text-xs text-slate-500">{filtered.length} items</span>
                                        <div className="flex items-center gap-1">
                                            <button
                                                onClick={() => setPage(p => Math.max(1, p - 1))}
                                                disabled={safePage === 1}
                                                className="px-3 py-1 text-xs text-slate-500 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                                            >
                                                <CaretLeft size={12} weight="bold" />
                                            </button>
                                            <span className="text-xs text-slate-500 font-mono tabular-nums w-14 text-center">
                                                {safePage} / {totalPages}
                                            </span>
                                            <button
                                                onClick={() => setPage(p => Math.min(totalPages, p + 1))}
                                                disabled={safePage === totalPages}
                                                className="px-3 py-1 text-xs text-slate-500 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                                            >
                                                <CaretRight size={12} weight="bold" />
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                    {shop.is_owner && (
                        <OwnerControls
                            shop={shop}
                            kind={kind}
                            processing={processing}
                            onRestock={restock}
                            onSavePremium={saveInsurancePremium}
                        />
                    )}
                </div>
            </div>
        </>
    );
}

Shop.layout = (page: React.ReactNode) => <GameLayout wide children={page} />;
