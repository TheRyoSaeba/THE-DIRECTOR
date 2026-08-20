
import { X, ShieldCheck, Heart, FirstAid, Car } from '@phosphor-icons/react';
import { motion, AnimatePresence } from 'framer-motion';

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

interface ShopModalProps {
    item: ShopItem | null;
    onClose: () => void;
    onConfirm: (item: ShopItem) => void;
    processing: boolean;
    characterMoney: number;
    /** 'pharmacy' | 'vehicle' | 'general' — controls badge, icon, and insurance UI */
    shopType?: 'pharmacy' | 'vehicle' | 'general';
    hasInsurance?: boolean;
    insuranceDiscount?: number;
}

export default function ShopModal({
    item,
    onClose,
    onConfirm,
    processing,
    characterMoney,
    shopType = 'general',
    hasInsurance = false,
    insuranceDiscount = 30,
}: ShopModalProps) {
    if (!item) return null;

    const isPharmacy = shopType === 'pharmacy';
    const isVehicle = shopType === 'vehicle';

    const basePrice = item.price;
    const finalPrice = isPharmacy && hasInsurance
        ? Math.round(basePrice * (1 - insuranceDiscount / 100))
        : basePrice;
    const hasStock = (item.attributes?.stock ?? 1) > 0;
    const canBuy = hasStock && !processing;

    // Badge label + colour — scoped per shop type
    const badgeLabel = isPharmacy ? 'Pharmaceutical' : isVehicle ? 'Vehicle' : item.attributes?.slot ?? 'Item';
    const accentCls = isPharmacy
        ? 'text-emerald-400 border-emerald-500/30 bg-emerald-500/10'
        : isVehicle
            ? 'text-blue-400 border-blue-500/30 bg-blue-500/10'
            : 'text-cyan-400 border-cyan-500/30 bg-cyan-500/10';

    const btnActiveCls = isPharmacy
        ? 'bg-white text-slate-950 hover:bg-emerald-400 hover:text-slate-950'
        : isVehicle
            ? 'bg-white text-slate-950 hover:bg-blue-400 hover:text-slate-950'
            : 'bg-white text-slate-950 hover:bg-cyan-400 hover:text-slate-950';

    // Placeholder icon when no image
    const PlaceholderIcon = isPharmacy ? FirstAid : Car;

    return (
        <AnimatePresence>
            {item && (
                /* Backdrop — same as StyledModal */
                <div className="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/30 backdrop-blur-[2px]">
                    <motion.div
                        initial={{ opacity: 0, scale: 0.95, y: 20 }}
                        animate={{ opacity: 1, scale: 1, y: 0 }}
                        exit={{ opacity: 0, scale: 0.95, y: 20 }}
                        transition={{ duration: 0.18, ease: 'easeOut' }}
                        className="bg-slate-900 border border-slate-700 w-full max-w-md rounded-2xl overflow-hidden shadow-2xl"
                    >
                        {/* ── Header image — aspect-video, mirrors StyledModal ── */}
                        <div className="relative aspect-video bg-slate-950 flex items-center justify-center overflow-hidden">
                            {item.image_url ? (
                                <img
                                    src={item.image_url}
                                    alt={item.name}
                                    className="w-full h-full object-contain p-6"
                                />
                            ) : (
                                <PlaceholderIcon size={96} className="text-slate-800" />
                            )}

                            {/* Gradient overlay — bottom-heavy so title is readable */}
                            <div className="absolute inset-0 bg-gradient-to-t from-slate-900 via-slate-900/30 to-transparent" />

                            {/* Close button — top-right, matching StyledModal exactly */}
                            <button
                                onClick={onClose}
                                className="absolute top-4 right-4 p-2 bg-black/50 hover:bg-black/80 text-white rounded-full transition-colors"
                            >
                                <X size={18} />
                            </button>

                            {/* Title block — bottom-left, matching StyledModal exactly */}
                            <div className="absolute bottom-4 left-5">
                                <h3 className="text-xl font-black text-white uppercase tracking-tight leading-tight">
                                    {item.name}
                                </h3>
                                {/* Small type badge */}
                                <span className={`inline-flex items-center mt-1 px-2 py-0.5 rounded border text-[9px] font-black uppercase tracking-widest ${accentCls}`}>
                                    {badgeLabel}
                                </span>
                            </div>
                        </div>

                        {/* ── Body ── */}
                        <div className="px-6 pb-6 pt-4 space-y-4">

                            {/* Description */}
                            {item.attributes?.description && (
                                <p className="text-xs text-slate-400 leading-relaxed border-l-2 border-slate-700 pl-3">
                                    {item.attributes.description}
                                </p>
                            )}

                            {/* Stat rows */}
                            <div className="bg-slate-950/50 rounded-xl border border-slate-800 divide-y divide-slate-800">

                                {/* Stock row — pharmacy only */}
                                {isPharmacy && (
                                    <div className="flex items-center justify-between px-4 py-2.5">
                                        <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Stock</span>
                                        <span className={`text-sm font-mono font-bold ${hasStock ? 'text-white' : 'text-red-400'}`}>
                                            {hasStock ? `${item.attributes.stock ?? 0} remaining` : 'Out of stock'}
                                        </span>
                                    </div>
                                )}

                                {/* Slot / type row */}
                                {item.attributes?.slot && (
                                    <div className="flex items-center justify-between px-4 py-2.5">
                                        <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Type</span>
                                        <span className="text-sm font-mono font-bold text-white uppercase">
                                            {item.attributes.slot}
                                        </span>
                                    </div>
                                )}

                                {/* Price row — shows strikethrough + discounted price when insured */}
                                <div className="flex items-center justify-between px-4 py-2.5">
                                    <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Price</span>
                                    <div className="text-right">
                                        {isPharmacy && hasInsurance ? (
                                            <div className="flex items-baseline gap-2">
                                                <span className="text-xs font-mono text-slate-600 line-through">
                                                    ${basePrice.toLocaleString()}
                                                </span>
                                                <span className="text-base font-mono font-black text-emerald-400">
                                                    ${finalPrice.toLocaleString()}
                                                </span>
                                            </div>
                                        ) : (
                                            <span className="text-base font-mono font-black text-emerald-400">
                                                ${finalPrice.toLocaleString()}
                                            </span>
                                        )}
                                    </div>
                                </div>


                            </div>

                            {/* Insurance / serial notice */}
                            <div className="flex items-center gap-2.5 text-[10px] font-bold uppercase tracking-wider">
                                {isPharmacy && hasInsurance ? (
                                    <>


                                    </>
                                ) : isPharmacy ? (
                                    <>

                                    </>
                                ) : (
                                    <>
                                    </>
                                )}
                            </div>

                            {/* CTA */}
                            <button
                                onClick={() => canBuy && onConfirm(item)}
                                disabled={!canBuy}
                                className={`w-full h-12 flex items-center justify-center gap-2 rounded-xl text-xs font-black uppercase tracking-[0.2em] transition-all ${!canBuy
                                    ? 'bg-slate-800 text-slate-500 border border-slate-700 cursor-not-allowed'
                                    : btnActiveCls
                                    }`}
                            >
                                {processing ? (
                                    <span className="animate-pulse">Processing…</span>
                                ) : !hasStock ? (
                                    'Out of Stock'

                                ) : isPharmacy ? (
                                    'Purchase Drug'
                                ) : isVehicle ? (
                                    'Purchase Vehicle'
                                ) : (
                                    'Purchase'
                                )}
                            </button>
                        </div>
                    </motion.div>
                </div>
            )}
        </AnimatePresence>
    );
}
