import { useState } from 'react';
import { router } from '@inertiajs/react';
import { TShirt, Sneaker, Crown, ShoppingBag, Check, Car, Briefcase, Package, Pill, Trash, Shield, Sword, X, Info, CurrencyDollar, Lightning } from '@phosphor-icons/react';
import { motion, AnimatePresence } from 'framer-motion';
import { Bomb } from '@phosphor-icons/react/dist/ssr';

export default function Wardrobe({
    catalogItems = [],
    characterItems = [],
    readOnly = false,
    propertyCondition = null,
}) {
    const [activeTab, setActiveTab] = useState('outfit');
    const [activeSlot, setActiveSlot] = useState('head');
    const [processing, setProcessing] = useState(null);
    const [selectedItem, setSelectedItem] = useState(null);
    const [showSellModal, setShowSellModal] = useState(false);
    const [sellBuyerName, setSellBuyerName] = useState('');
    const [sellPrice, setSellPrice] = useState('');

    const tabs = [
        { id: 'outfit', label: 'Outfit', icon: TShirt },
        { id: 'items', label: 'Items', icon: Package },
        { id: 'vehicle', label: 'Vehicles', icon: Car },
    ];

    const slots = [
        { id: 'head', label: 'Headwear', icon: Crown },
        { id: 'body', label: 'Tops', icon: TShirt },
        { id: 'bottom', label: 'Bottoms', icon: TShirt },
        { id: 'shoes', label: 'Shoes', icon: Sneaker },
        { id: 'accessory', label: 'Accessories', icon: ShoppingBag },
    ];


    const ownedClothing = characterItems.filter(item =>
        item.type === 'clothing' && item.slot === activeSlot
    );

    const gearTypes = ['weapon', 'armor', 'gadget', 'item'];
    const ownedGear = characterItems.filter(item =>
        gearTypes.includes(item.type) && (activeTab === 'items' ? true : item.slot === activeSlot)
    );

    const ownedVehicles = characterItems.filter(item => item.type === 'vehicle');

    const handleDrop = (id) => {
        if (readOnly) return;

        setProcessing(id);
        router.post('/settings/drop', { id: id }, {
            preserveScroll: true,
            onFinish: () => setProcessing(null)
        });
    };

    const handleToggle = (item) => {
        if (readOnly) return;
        setProcessing(item.id);

        if (item.is_equipped) {
            router.post('/settings/unequip', {
                slot: item.equipped_slot || item.slot
            }, {
                preserveScroll: true,
                onFinish: () => setProcessing(null)
            });
        } else {
            router.post('/settings/equip', {
                id: item.id,
                slot: item.slot
            }, {
                preserveScroll: true,
                onFinish: () => setProcessing(null)
            });
        }
    };

    const handleDetonate = (id) => {
        if (readOnly) return;
        setProcessing(id);
        router.post('/settings/detonate', { id }, {
            preserveScroll: true,
            onSuccess: () => setSelectedItem(null),
            onFinish: () => setProcessing(null),
        });
    };

    const handleStash = (id) => {
        if (readOnly) return;
        setProcessing(id);
        router.post('/settings/stash', { id }, {
            preserveScroll: true,
            onFinish: () => setProcessing(null)
        });
    };

    const handleUnstash = (id) => {
        if (readOnly) return;
        setProcessing(id);
        router.post('/settings/unstash', { id }, {
            preserveScroll: true,
            onFinish: () => setProcessing(null)
        });
    };

    const handleSell = () => {
        if (readOnly || !selectedItem) return;

        if (!sellBuyerName.trim() || !sellPrice.trim()) {
            return;
        }

        const price = parseInt(sellPrice);
        if (isNaN(price) || price <= 0) {
            return;
        }

        setProcessing(selectedItem.id);
        router.post('/settings/sell', {
            id: selectedItem.id,
            buyer_name: sellBuyerName.trim(),
            price: price
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setSelectedItem(null);
                setShowSellModal(false);
                setSellBuyerName('');
                setSellPrice('');
            },
            onFinish: () => setProcessing(null)
        });
    };

    const renderItemCard = (item, type) => {
        const isProcessing = processing === item.id;

        return (
            <div
                key={item.id}
                className={`group relative aspect-square rounded-xl border transition-all duration-300 overflow-hidden ${item.is_equipped
                    ? 'bg-purple-900/20 border-purple-500/50 shadow-[0_0_20px_rgba(168,85,247,0.2)] ring-2 ring-purple-500/30'
                    : 'bg-slate-800/50 border-slate-700 hover:border-purple-500/50 hover:bg-slate-800 shadow-sm'
                    } ${isProcessing ? 'opacity-50' : ''}`}
            >
                {item.image_url ? (
                    <img src={item.image_url} alt={item.name} className="w-full h-full object-contain p-2" />
                ) : (
                    <div className="w-full h-full flex items-center justify-center bg-slate-900/50">
                        <Package size={32} className="text-slate-600" />
                    </div>
                )}

                { }
                <div
                    onClick={() => setSelectedItem(item)}
                    className="absolute inset-0 cursor-pointer z-0 group-hover:bg-purple-500/10 transition-colors"
                >
                    <div className="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
                        <div className="bg-slate-900/80 p-1.5 rounded-lg border border-white/10 text-white">
                            <Info size={14} />
                        </div>
                    </div>
                </div>

                { }
                <div className="absolute inset-x-0 bottom-0 p-3 bg-gradient-to-t from-black via-black/80 to-transparent pointer-events-none">
                    <div className="text-[10px] font-bold text-white truncate uppercase tracking-tight">
                        {item.name}
                    </div>
                    {item.location !== 'on_hand' && (
                        <div className="text-[8px] font-black text-amber-500 uppercase tracking-widest mt-0.5">
                            {item.location === 'garage' ? 'Garage' : 'Safe'}
                        </div>
                    )}
                    {item.condition_percent !== null && (
                        <div className="mt-1 h-1 w-full bg-slate-800 rounded-full overflow-hidden">
                            <div
                                className={`h-full transition-all ${item.condition_percent > 30 ? 'bg-emerald-500' : 'bg-red-500'}`}
                                style={{ width: `${item.condition_percent}%` }}
                            />
                        </div>
                    )}
                </div>

                { }
                <div className="absolute top-2 left-2 flex flex-col gap-1">
                    {item.is_equipped && (
                        <div className="bg-purple-500 text-white text-[8px] font-black px-1.5 py-0.5 rounded shadow-lg flex items-center gap-1 animate-pulse uppercase tracking-widest">
                            <Check size={10} strokeWidth={4} />
                            Equipped
                        </div>
                    )}
    {!item.is_equipped && item.location === 'on_hand' && item.slug?.toLowerCase() === 'rcied' && item.data?.target_character_id && (
                        <div className="bg-orange-600/90 text-white text-[8px] font-black px-1.5 py-0.5 rounded shadow-lg uppercase tracking-widest">
                            Planted
                        </div>
                    )}
                    {!item.is_equipped && item.location === 'on_hand' && !(item.slug?.toLowerCase() === 'rcied' && item.data?.target_character_id) && (
                        <div className="bg-emerald-500/80 text-white text-[8px] font-black px-1.5 py-0.5 rounded shadow-lg uppercase tracking-widest">
                            On Person
                        </div>
                    )}
                </div>

                { }
            </div>
        );
    };

    return (
        <div className="bg-slate-900/50 rounded-xl border border-slate-700/50 overflow-hidden shadow-2xl">
            { }
            <div className="flex border-b border-slate-700/50 bg-slate-900/30 p-1">
                {tabs.map(tab => {
                    const Icon = tab.icon;
                    return (
                        <button
                            key={tab.id}
                            onClick={() => {
                                setActiveTab(tab.id);
                                if (tab.id === 'items') setActiveSlot('weapon');
                                else if (tab.id === 'outfit') setActiveSlot('head');
                                else setActiveSlot('vehicle');
                            }}
                            className={`flex-1 flex items-center justify-center gap-2 py-3 text-xs font-black uppercase tracking-widest transition rounded-lg ${activeTab === tab.id
                                ? 'bg-slate-800 text-cyan-400 shadow-inner border border-slate-700'
                                : 'text-slate-500 hover:text-slate-300 hover:bg-slate-800/30'
                                }`}
                        >
                            <Icon size={16} />
                            {tab.label}
                        </button>
                    );
                })}
            </div>

            { }
            {activeTab === 'outfit' && (
                <div className="flex border-b border-slate-700/50 overflow-x-auto bg-slate-950/20 p-1 gap-1">
                    {slots.map(slot => {
                        const Icon = slot.icon;
                        const hasEquipped = characterItems.some(i => i.is_equipped && i.equipped_slot === slot.id);

                        return (
                            <button
                                key={slot.id}
                                onClick={() => setActiveSlot(slot.id)}
                                className={`flex items-center gap-2 px-4 py-2 text-[10px] font-bold uppercase tracking-widest transition rounded-md whitespace-nowrap ${activeSlot === slot.id
                                    ? 'bg-purple-500/20 text-purple-400 border border-purple-500/30'
                                    : 'text-slate-500 hover:text-slate-300 hover:bg-slate-800/30'
                                    }`}
                            >
                                <Icon size={14} />
                                {slot.label}
                                {hasEquipped && <div className="w-1.5 h-1.5 rounded-full bg-purple-400 shadow-[0_0_5px_rgba(168,85,247,0.5)]" />}
                            </button>
                        );
                    })}
                </div>
            )}

            {activeTab === 'items' && (
                <div className="flex border-b border-slate-700/50 overflow-x-auto bg-slate-950/20 p-2 gap-2 px-6">
                    <div className="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] flex items-center gap-2">
                        <Package size={14} />
                        Physical Gear (Max 2 on person)
                    </div>
                </div>
            )}

            { }
            <div className="p-6">
                <style>{`
        
        .scrollbar-purple::-webkit-scrollbar {
            height: 8px;
        }
        .scrollbar-purple::-webkit-scrollbar-track {
            background: #1e293b;
            border-radius: 20px;
        }
        .scrollbar-purple::-webkit-scrollbar-thumb {
            background: #a855f7;
            border-radius: 20px;
        }
        .scrollbar-purple::-webkit-scrollbar-thumb:hover {
            background: #c084fc;
        }
        .scrollbar-purple {
            scrollbar-width: thin;
            scrollbar-color: #a855f7 #1e293b;
        }
    `}</style>

                {activeTab === 'outfit' && (
                    ownedClothing.length > 0 ? (
                        <div className="overflow-x-auto pb-4 scrollbar-purple">
                            <div className="grid grid-flow-col grid-rows-2 gap-4" style={{ gridAutoColumns: 'max-content' }}>
                                {ownedClothing.map(item => (
                                    <div key={item.id} className="w-48">
                                        {renderItemCard(item, 'outfit')}
                                    </div>
                                ))}
                            </div>
                        </div>
                    ) : (
                        <EmptyState icon={TShirt} title={`No ${activeSlot} gear`} subtitle="Visit Plaza Ginza in Tokyo to buy clothes" />
                    )
                )}

                {activeTab === 'items' && (
                    ownedGear.length > 0 ? (
                        <div className="overflow-x-auto pb-4 scrollbar-purple">
                            <div className="grid grid-flow-col grid-rows-2 gap-4" style={{ gridAutoColumns: 'max-content' }}>
                                {ownedGear.map(item => (
                                    <div key={item.id} className="w-48">
                                        {renderItemCard(item, 'items')}
                                    </div>
                                ))}
                            </div>
                        </div>
                    ) : (
                        <EmptyState icon={Package} title="No Equipment" subtitle="Visit any of the shops" />
                    )
                )}

                {activeTab === 'vehicle' && (
                    ownedVehicles.length > 0 ? (
                        <div className="overflow-x-auto pb-4 scrollbar-purple">
                            <div className="grid grid-flow-col grid-rows-2 gap-4" style={{ gridAutoColumns: 'max-content' }}>
                                {ownedVehicles.map(v => (
                                    <div key={v.id} className="w-64">
                                        <div className={`group relative rounded-xl border bg-slate-800/50 overflow-hidden transition-all ${v.is_equipped ? 'border-cyan-500 ring-1 ring-cyan-500/50' : 'border-slate-700'}`}>
                                            <div className="aspect-video relative">
                                                {v.image_url ? <img src={v.image_url} alt={v.name} className="w-full h-full object-contain p-2" /> : <div className="w-full h-full flex items-center justify-center bg-slate-900"><Car size={48} className="text-slate-700" /></div>}
                                                <div onClick={() => setSelectedItem(v)} className="absolute inset-0 cursor-pointer" />
                                                {v.is_equipped && <div className="absolute top-2 right-2 bg-cyan-500 text-[10px] font-black px-2 py-1 rounded shadow-lg animate-pulse uppercase">Equipped</div>}
                                                {!v.is_equipped && v.condition_percent <= 0 && <div className="absolute top-2 right-2 bg-red-500/80 text-[8px] font-black text-white px-2 py-1 rounded shadow-lg uppercase tracking-widest">Totaled</div>}
                                                {!v.is_equipped && v.condition_percent > 0 && v.location === 'on_hand' && <div className="absolute top-2 right-2 bg-emerald-500/80 text-[8px] font-black text-white px-2 py-1 rounded shadow-lg uppercase tracking-widest">On Person</div>}
                                            </div>
                                            <div className="p-3 flex justify-between items-center bg-slate-900/50">
                                                <div className="flex flex-col">
                                                    <span className="text-xs font-bold text-white uppercase tracking-tighter">{v.name}</span>
                                                    {v.location === 'garage' && (
                                                        <span className="text-[8px] font-black text-amber-500 uppercase tracking-widest leading-none mt-1">Garage</span>
                                                    )}
                                                </div>
                                                {!v.is_equipped && <button onClick={() => setSelectedItem(v)} className="text-slate-500 hover:text-cyan-400 transition-colors"><Info size={16} /></button>}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    ) : (
                        <EmptyState icon={Car} title="No Vehicles" subtitle="Visit a dealership to buy a ride" />
                    )
                )}
            </div>

            { }
            <AnimatePresence>
                {selectedItem && !showSellModal && (
                    <div className="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/30 backdrop-blur-[2px]">
                        <motion.div
                            initial={{ opacity: 0, scale: 0.95, y: 20 }}
                            animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.95, y: 20 }}
                            className="bg-slate-900 border border-slate-700 w-full max-w-md rounded-2xl overflow-hidden shadow-2xl"
                        >
                            { }
                            <div className="relative aspect-video bg-slate-950 flex items-center justify-center">
                                {selectedItem.image_url ? (
                                    <img src={selectedItem.image_url} alt={selectedItem.name} className="w-full h-full object-contain p-2" />
                                ) : (
                                    <Package size={64} className="text-slate-800" />
                                )}
                                <div className="absolute inset-0 bg-gradient-to-t from-slate-900 via-transparent to-transparent" />
                                <button
                                    onClick={() => setSelectedItem(null)}
                                    className="absolute top-4 right-4 p-2 bg-black/50 hover:bg-black/80 text-white rounded-full transition-colors"
                                >
                                    <X size={18} />
                                </button>

                                <div className="absolute bottom-4 left-6">
                                    <h3 className="text-xl font-black text-white uppercase tracking-tight">{selectedItem.name}</h3>
                                    <div className="flex gap-2 mt-1">
                                        <span className={`text-[10px] font-black px-2 py-0.5 rounded border ${
                                            selectedItem.is_equipped
                                                ? 'bg-purple-500/20 text-purple-400 border-purple-500/30'
                                                : (selectedItem.slug?.toLowerCase() === 'rcied' && selectedItem.data?.target_character_id)
                                                    ? 'bg-orange-600/20 text-orange-400 border-orange-500/30'
                                                    : 'bg-slate-800 text-slate-400 border-slate-700'
                                        } uppercase tracking-widest`}>
                                            {selectedItem.is_equipped ? 'Equipped' : (selectedItem.slug?.toLowerCase() === 'rcied' && selectedItem.data?.target_character_id) ? 'Planted' : 'Unequipped'}
                                        </span>
                                        <span className={`text-[10px] font-black px-2 py-0.5 rounded border ${selectedItem.location === 'on_hand' ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30' : 'bg-amber-500/20 text-amber-400 border-amber-500/30'} uppercase tracking-widest`}>
                                            {selectedItem.location === 'on_hand' ? 'On Person' : 'Stashed'}
                                        </span>
                                        {selectedItem.type === 'vehicle' && selectedItem.condition_percent <= 0 && (
                                            <span className="text-[10px] font-black px-2 py-0.5 rounded border bg-red-500/20 text-red-400 border-red-500/30 uppercase tracking-widest">
                                                Totaled
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </div>

                            { }
                            <div className="p-6 space-y-6">
                                { }
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="bg-slate-950/50 p-3 rounded-xl border border-slate-800">
                                        <div className="text-[10px] text-slate-500 font-bold uppercase tracking-wider mb-1">Condition</div>
                                        <div className="flex items-center gap-2">
                                            {selectedItem.condition_percent !== null ? (
                                                <>
                                                    <div className="flex-1 h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                                        <div
                                                            className={`h-full ${selectedItem.condition_percent > 30 ? 'bg-emerald-500' : 'bg-red-500'}`}
                                                            style={{ width: `${selectedItem.condition_percent}%` }}
                                                        />
                                                    </div>
                                                    <span className="text-xs font-mono text-white">{selectedItem.condition_percent}%</span>
                                                </>
                                            ) : (
                                                <span className="text-xs font-mono text-slate-500 italic">Ineligible</span>
                                            )}
                                        </div>
                                    </div>
                                    <div className="bg-slate-950/50 p-3 rounded-xl border border-slate-800">
                                        <div className="text-[10px] text-slate-500 font-bold uppercase tracking-wider mb-1">Type</div>
                                        <div className="text-xs text-white font-bold uppercase tracking-tighter">{selectedItem.type} / {selectedItem.slot}</div>
                                    </div>
                                </div>

                                { }
                                <div className="grid grid-cols-2 gap-3">
                                    { }
                                    {selectedItem.type === 'item' ? (
                                        selectedItem.location === 'on_hand' ? (
                                            <button
                                                onClick={() => {
                                                    setProcessing(selectedItem.id);
                                                    router.post('/settings/consume', { id: selectedItem.id }, {
                                                        preserveScroll: true,
                                                        onSuccess: () => setSelectedItem(null),
                                                        onFinish: () => setProcessing(null)
                                                    });
                                                }}
                                                disabled={processing !== null}
                                                className={`h-12 flex items-center justify-center gap-2 rounded-xl text-xs font-black uppercase tracking-[0.2em] transition-all ${processing !== null ? 'bg-slate-800 text-slate-600 cursor-not-allowed opacity-50' : 'bg-emerald-600 hover:bg-emerald-500 text-white'}`}
                                            >
                                                <Pill size={14} weight="fill" />
                                                {processing === selectedItem.id ? 'Consuming...' : 'Consume'}
                                            </button>
                                        ) : (
                                            <button
                                                onClick={() => { handleUnstash(selectedItem.id); setSelectedItem(null); }}
                                                disabled={processing !== null || propertyCondition === 'DESTROYED'}
                                                className={`h-12 rounded-xl text-xs font-black uppercase tracking-[0.2em] transition-all flex items-center justify-center gap-2 ${processing !== null || propertyCondition === 'DESTROYED' ? 'bg-slate-800 text-slate-600 cursor-not-allowed opacity-50' : 'bg-emerald-600 hover:bg-emerald-500 text-white'}`}
                                            >
                                                <Briefcase size={14} />
                                                Retrieve
                                            </button>
                                        )
                                    ) : selectedItem.type === 'gadget' && selectedItem.slug?.toLowerCase() === 'rcied' && selectedItem.data?.target_character_id ? (
                                        // Planted RCIED — show Detonate button (or Retrieve if stashed)
                                        <button
                                            onClick={() => {
                                                if (selectedItem.location !== 'on_hand') {
                                                    handleUnstash(selectedItem.id);
                                                    setSelectedItem(null);
                                                } else {
                                                    handleDetonate(selectedItem.id);
                                                }
                                            }}
                                            disabled={processing !== null || (selectedItem.location !== 'on_hand' && propertyCondition === 'DESTROYED')}
                                            className={`h-12 flex items-center justify-center gap-2 rounded-xl text-xs font-black uppercase tracking-[0.2em] transition-all border ${
                                                processing !== null || (selectedItem.location !== 'on_hand' && propertyCondition === 'DESTROYED')
                                                    ? 'bg-slate-950 border-slate-800 text-slate-600 cursor-not-allowed opacity-50'
                                                    : selectedItem.location !== 'on_hand'
                                                        ? 'bg-emerald-600 hover:bg-emerald-500 text-white'
                                                        : 'bg-red-600 hover:bg-red-500 text-white border-red-500'
                                            }`}
                                        >
                                            {selectedItem.location !== 'on_hand' ? (
                                                <>
                                                    <Briefcase size={14} />
                                                    Retrieve
                                                </>
                                            ) : (
                                                <>
                                                    <Bomb size={14} weight="fill" />
                                                    {processing === selectedItem.id ? 'Detonating...' : 'Detonate'}
                                                </>
                                            )}
                                        </button>
                                    ) : selectedItem.location === 'on_hand' ? (
                                        <button
                                            onClick={() => { handleToggle(selectedItem); setSelectedItem(null); }}
                                            disabled={processing !== null || (selectedItem.type === 'vehicle' && selectedItem.condition_percent <= 0 && !selectedItem.is_equipped)}
                                            className={`h-12 flex items-center justify-center gap-2 rounded-xl text-xs font-black uppercase tracking-[0.2em] transition-all border ${processing !== null || (selectedItem.type === 'vehicle' && selectedItem.condition_percent <= 0 && !selectedItem.is_equipped)
                                                ? 'bg-slate-950 border-slate-800 text-slate-700 cursor-not-allowed opacity-50'
                                                : selectedItem.is_equipped
                                                    ? 'bg-slate-800 border-slate-700 text-slate-400 hover:bg-slate-700'
                                                    : 'bg-white text-slate-950 hover:bg-purple-400'}`}
                                        >
                                            <Check size={14} />
                                            {selectedItem.is_equipped ? 'Unequip' : 'Equip'}
                                        </button>
                                    ) : (
                                        <button
                                            onClick={() => { handleUnstash(selectedItem.id); setSelectedItem(null); }}
                                            disabled={processing !== null || propertyCondition === 'DESTROYED'}
                                            className={`h-12 rounded-xl text-xs font-black uppercase tracking-[0.2em] transition-all flex items-center justify-center gap-2 ${processing !== null || propertyCondition === 'DESTROYED' ? 'bg-slate-800 text-slate-600 cursor-not-allowed opacity-50' : 'bg-emerald-600 hover:bg-emerald-500 text-white'}`}
                                        >
                                            <Briefcase size={14} />
                                            Retrieve
                                        </button>
                                    )}

                                    {selectedItem.location === 'on_hand' ? (
                                        <button
                                            onClick={() => { handleStash(selectedItem.id); setSelectedItem(null); }}
                                            disabled={processing !== null || propertyCondition === 'DESTROYED' || (selectedItem.type === 'vehicle' && selectedItem.condition_percent <= 0)}
                                            className={`h-12 text-white rounded-xl text-xs font-black uppercase tracking-[0.2em] transition-all flex items-center justify-center gap-2 border ${processing !== null || propertyCondition === 'DESTROYED' || (selectedItem.type === 'vehicle' && selectedItem.condition_percent <= 0)
                                                ? 'bg-slate-950 border-slate-800 text-slate-700 cursor-not-allowed opacity-50'
                                                : 'bg-amber-600 hover:bg-amber-500 border-amber-600'}`}
                                        >
                                            <Package size={14} />
                                            Stash
                                        </button>
                                    ) : (
                                        <button
                                            disabled
                                            className="h-12 bg-slate-800 text-slate-500 border border-slate-700 rounded-xl text-xs font-black uppercase tracking-[0.2em] transition-all flex items-center justify-center gap-2 cursor-not-allowed"
                                        >
                                            <Check size={14} />
                                            {selectedItem.type === 'item' ? 'Consume (Stashed)' : 'Equip (Stashed)'}
                                        </button>
                                    )}

                                    { }
                                    <button
                                        onClick={() => setShowSellModal(true)}
                                        disabled={processing !== null || selectedItem.is_equipped || selectedItem.location !== 'on_hand' || (selectedItem.type === 'vehicle' && selectedItem.condition_percent <= 0)}
                                        className={`h-12 rounded-xl text-xs font-black uppercase tracking-[0.2em] transition-all flex items-center justify-center gap-2 border ${processing !== null || selectedItem.is_equipped || selectedItem.location !== 'on_hand' || (selectedItem.type === 'vehicle' && selectedItem.condition_percent <= 0)
                                            ? 'bg-slate-950 border-slate-800 text-slate-700 cursor-not-allowed opacity-50'
                                            : 'bg-slate-950 border-slate-800 text-emerald-400 hover:border-emerald-500/50 hover:text-emerald-300 hover:bg-emerald-500/10'}`}
                                    >
                                        <CurrencyDollar size={14} weight="bold" />
                                        Sell
                                    </button>

                                    <button
                                        onClick={() => { handleDrop(selectedItem.id); setSelectedItem(null); }}
                                        disabled={processing !== null || selectedItem.is_equipped}
                                        className={`h-12 rounded-xl text-xs font-black uppercase tracking-[0.2em] transition-all flex items-center justify-center gap-2 border ${processing !== null || selectedItem.is_equipped
                                            ? 'bg-slate-950 border-slate-800 text-slate-700 cursor-not-allowed opacity-50'
                                            : 'bg-red-950/30 border-red-900/50 text-red-500 hover:bg-red-500 hover:text-white'}`}
                                    >
                                        <Trash size={14} weight="bold" />
                                        Destroy
                                    </button>
                                </div>
                            </div>
                        </motion.div>
                    </div>
                )}

                { }
                {selectedItem && showSellModal && (
                    <div className="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/30 backdrop-blur-[2px]">
                        <motion.div
                            initial={{ opacity: 0, scale: 0.95, y: 20 }}
                            animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.95, y: 20 }}
                            className="bg-slate-900 border border-slate-700 w-full max-w-md rounded-2xl overflow-hidden shadow-2xl"
                        >
                            { }
                            <div className="relative aspect-video bg-slate-950 flex items-center justify-center">
                                {selectedItem.image_url ? (
                                    <img src={selectedItem.image_url} alt={selectedItem.name} className="w-full h-full object-contain p-6" />
                                ) : (
                                    <Package size={64} className="text-slate-800" />
                                )}
                                <div className="absolute inset-0 bg-gradient-to-t from-slate-900 via-transparent to-transparent" />
                                <button
                                    onClick={() => {
                                        setShowSellModal(false);
                                        setSellBuyerName('');
                                        setSellPrice('');
                                    }}
                                    className="absolute top-4 right-4 p-2 bg-black/50 hover:bg-black/80 text-white rounded-full transition-colors"
                                >
                                    <X size={18} />
                                </button>

                                <div className="absolute bottom-4 left-6">
                                    <h3 className="text-xl font-black text-white uppercase tracking-tight">{selectedItem.name}</h3>
                                    <div className="flex gap-2 mt-1">
                                        <span className="text-[10px] font-black px-2 py-0.5 rounded border bg-emerald-500/20 text-emerald-400 border-emerald-500/30 uppercase tracking-widest">
                                            Selling
                                        </span>
                                    </div>
                                </div>
                            </div>

                            { }
                            <div className="p-6 space-y-6">
                                { }
                                <div className="space-y-4">
                                    <div>
                                        <label className="block text-[10px] text-slate-500 font-bold uppercase tracking-wider mb-2">
                                            Buyer Name
                                        </label>
                                        <input
                                            type="text"
                                            value={sellBuyerName}
                                            onChange={(e) => setSellBuyerName(e.target.value)}
                                            className="w-full px-4 py-3 bg-slate-950/50 border border-slate-800 rounded-xl text-white placeholder:text-slate-600 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 text-sm"
                                            placeholder="Enter buyer's display name"
                                            maxLength={30}
                                            autoFocus
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-[10px] text-slate-500 font-bold uppercase tracking-wider mb-2">
                                            Price
                                        </label>
                                        <div className="relative">
                                            <CurrencyDollar className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500" size={18} weight="bold" />
                                            <input
                                                type="number"
                                                value={sellPrice}
                                                onChange={(e) => setSellPrice(e.target.value)}
                                                className="w-full pl-10 pr-4 py-3 bg-slate-950/50 border border-slate-800 rounded-xl text-white placeholder:text-slate-600 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 text-sm"
                                                placeholder="0"
                                                min="1"
                                                max="999999999"
                                            />
                                        </div>
                                    </div>
                                </div>

                                { }
                                <div className="grid grid-cols-2 gap-3">
                                    <button
                                        onClick={() => {
                                            setShowSellModal(false);
                                            setSellBuyerName('');
                                            setSellPrice('');
                                        }}
                                        className="h-12 bg-slate-800 border border-slate-700 text-slate-400 hover:bg-slate-700 hover:text-white rounded-xl text-xs font-black uppercase tracking-[0.2em] transition-all flex items-center justify-center gap-2"
                                    >
                                        <X size={14} />
                                        Cancel
                                    </button>

                                    <button
                                        onClick={handleSell}
                                        disabled={processing === selectedItem.id || !sellBuyerName.trim() || !sellPrice.trim() || parseInt(sellPrice) <= 0}
                                        className="h-12 bg-emerald-600 hover:bg-emerald-500 disabled:bg-slate-800 disabled:text-slate-600 disabled:border-slate-700 disabled:cursor-not-allowed text-white rounded-xl text-xs font-black uppercase tracking-[0.2em] transition-all flex items-center justify-center gap-2 border border-emerald-500/50"
                                    >
                                        <CurrencyDollar size={14} weight="bold" />
                                        {processing === selectedItem.id ? 'Selling...' : 'Confirm Sale'}
                                    </button>
                                </div>
                            </div>
                        </motion.div>
                    </div>
                )}
            </AnimatePresence>
        </div>
    );
}

function EmptyState({ icon: Icon, title, subtitle }) {
    return (
        <div className="flex flex-col items-center justify-center h-full text-center space-y-4 py-12">
            <div className="w-16 h-16 rounded-2xl bg-slate-800/50 border border-slate-700/50 flex items-center justify-center">
                <Icon className="w-8 h-8 text-slate-600" />
            </div>
            <div>
                <h3 className="text-slate-400 font-black uppercase text-sm tracking-widest">{title}</h3>
                <p className="text-[10px] text-slate-600 uppercase font-bold mt-1">{subtitle}</p>
            </div>
        </div>
    );
}
