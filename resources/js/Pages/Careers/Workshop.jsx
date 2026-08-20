import { Head, router } from '@inertiajs/react';
import GameLayout from '@/Layouts/GameLayout';
import { Wrench, Car, CurrencyDollar, Warning, Heart, House, CheckCircle, Fire, Buildings } from '@phosphor-icons/react';
import { useState } from 'react';
import { route } from 'ziggy-js';

const VehicleCard = ({ vehicle, onRepair, repairing, disabled }) => (
    <div className="relative flex flex-col sm:flex-row items-start sm:items-center gap-4 p-4 rounded-xl bg-slate-900/40 border border-slate-800/80 hover:border-slate-700 transition-all">
        <div className="shrink-0 w-12 h-12 rounded-lg bg-slate-800 flex items-center justify-center overflow-hidden">
            {vehicle.vehicle_image ? (
                <img src={vehicle.vehicle_image} alt={vehicle.vehicle_name} className="w-full h-full object-cover" />
            ) : (
                <Car size={24} className="text-slate-500" />
            )}
        </div>

        <div className="flex-1 min-w-0">
            <div className="text-sm font-bold text-white uppercase tracking-wider truncate">
                {vehicle.vehicle_name}
            </div>

            <div className="text-[10px] text-white font-bold uppercase tracking-wider mt-0.5">
                Owner: {vehicle.owner_name}
            </div>
            <div className="flex flex-wrap items-center gap-3 mt-2">


                <span className="text-[10px] font-bold text-emerald-400 uppercase tracking-wider flex items-center gap-1">
                    <CurrencyDollar size={10} />
                    ${vehicle.repair_cost.toLocaleString()} repair
                </span>
            </div>
        </div>

        <button
            onClick={() => onRepair(vehicle.id)}
            disabled={disabled || repairing}
            className={`shrink-0 px-5 py-2.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all ${disabled || repairing
                ? 'bg-slate-800/50 text-slate-600 border border-slate-800/50 cursor-not-allowed'
                : 'bg-white text-slate-950 hover:bg-cyan-400 hover:shadow-[0_0_15px_rgba(6,182,212,0.3)]'
                }`}
        >
            {repairing ? (
                <span className="flex items-center gap-2">
                    <span className="w-3 h-3 border-2 border-slate-950/30 border-t-slate-950 rounded-full animate-spin" />
                    Repairing...
                </span>
            ) : (
                <span className="flex items-center gap-2">
                    <Wrench size={12} />
                    Repair
                </span>
            )}
        </button>
    </div>
);

const HomeCard = ({ home, onInspect, inspecting, disabled }) => (
    <div className="relative flex flex-col sm:flex-row items-start sm:items-center gap-4 p-4 rounded-xl bg-slate-900/40 border border-slate-800/80 hover:border-slate-700 transition-all">
        <div className="shrink-0 w-12 h-12 rounded-lg bg-slate-800 flex items-center justify-center overflow-hidden">
            {home.property_image ? (
                <img src={home.property_image} alt={home.property_name} className="w-full h-full object-cover" />
            ) : (
                <House size={24} className="text-slate-500" />
            )}
        </div>

        <div className="flex-1 min-w-0">
            <div className="text-sm font-bold text-white uppercase tracking-wider truncate">
                {home.property_name}
            </div>
            <div className="text-[10px] text-slate-500 font-bold uppercase tracking-wider mt-0.5">
                Owner: {home.owner_name}
            </div>
            <div className="flex flex-wrap items-center gap-3 mt-2">
                <span className="text-[10px] font-bold text-amber-400 uppercase tracking-wider flex items-center gap-1">
                    <Warning size={10} weight="bold" />
                    Awaiting Inspection
                </span>
                <span className="text-[10px] font-bold text-emerald-400 uppercase tracking-wider flex items-center gap-1">
                    <CurrencyDollar size={10} />
                    ${home.inspect_fee.toLocaleString()} fee
                </span>
            </div>
        </div>

        <button
            onClick={() => onInspect(home.owner_id)}
            disabled={disabled || inspecting}
            className={`shrink-0 px-5 py-2.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all ${disabled || inspecting
                ? 'bg-slate-800/50 text-slate-600 border border-slate-800/50 cursor-not-allowed'
                : 'bg-white text-slate-950 hover:bg-cyan-400 hover:shadow-[0_0_15px_rgba(6,182,212,0.3)]'
                }`}
        >
            {inspecting ? (
                <span className="flex items-center gap-2">
                    <span className="w-3 h-3 border-2 border-slate-950/30 border-t-slate-950 rounded-full animate-spin" />
                    Inspecting...
                </span>
            ) : (
                <span className="flex items-center gap-2">
                    <CheckCircle size={12} />
                    Inspect
                </span>
            )}
        </button>
    </div>
);

export default function Workshop({ vehicles, uninspectedHomes, destroyedHomes, pendingCorpProperties = [] }) {
    const [repairingId, setRepairingId] = useState(null);
    const [inspectingId, setInspectingId] = useState(null);
    const [repairingHomeId, setRepairingHomeId] = useState(null);
    const [constructingId, setConstructingId] = useState(null);

    const handleRepair = (itemId) => {
        if (repairingId) return;
        setRepairingId(itemId);
        router.post(route('career.technician.repair'), { item_id: itemId }, {
            preserveScroll: true,
            onFinish: () => setRepairingId(null),
        });
    };

    const handleInspect = (ownerId) => {
        if (inspectingId) return;
        setInspectingId(ownerId);
        router.post(route('career.technician.inspect'), { owner_id: ownerId }, {
            preserveScroll: true,
            onFinish: () => setInspectingId(null),
        });
    };

    const handleRepairHome = (ownerId) => {
        if (repairingHomeId) return;
        setRepairingHomeId(ownerId);
        router.post(route('career.technician.repair-home'), { owner_id: ownerId }, {
            preserveScroll: true,
            onFinish: () => setRepairingHomeId(null),
        });
    };

    const handleConstructProperty = (propertyId) => {
        if (constructingId) return;
        setConstructingId(propertyId);
        router.post(route('career.technician.construct-corp-property'), { property_id: propertyId }, {
            preserveScroll: true,
            onFinish: () => setConstructingId(null),
        });
    };

    return (
        <div className="w-full">
            <Head title="Workshop - TheDirector" />

            <div className="w-full max-w-4xl mx-auto space-y-10 py-4 sm:py-8 px-4">

                {/* Vehicle Repair */}
                <div className="space-y-4">
                    <div className="border-b border-slate-800/50 pb-6">
                        <h1 className="text-3xl sm:text-4xl font-black text-white tracking-tight">
                            VEHICLE <span className="text-cyan-400 italic">REPAIR</span>
                        </h1>

                    </div>
                    <div className="space-y-3">
                        {vehicles.length === 0 ? (
                            <div className="bg-slate-950/50 border border-dashed border-white/5 rounded-2xl p-8 text-center">
                                <Car size={24} className="text-slate-800 mx-auto mb-3" />
                                <p className="text-[10px] font-black text-slate-700 uppercase tracking-widest italic">
                                    No totaled vehicles found in this city.
                                </p>
                            </div>
                        ) : (
                            vehicles.map((vehicle) => (
                                <VehicleCard
                                    key={vehicle.id}
                                    vehicle={vehicle}
                                    onRepair={handleRepair}
                                    repairing={repairingId === vehicle.id}
                                    disabled={repairingId !== null && repairingId !== vehicle.id}
                                />
                            ))
                        )}
                    </div>
                </div>

                {/* Home Inspections */}
                <div className="space-y-4">
                    <div className="border-b border-slate-800/50 pb-6">
                        <h1 className="text-3xl sm:text-4xl font-black text-white tracking-tight">
                            HOME <span className="text-cyan-400 italic">INSPECTIONS</span>
                        </h1>

                    </div>
                    <div className="space-y-3">
                        {uninspectedHomes.length === 0 ? (
                            <div className="bg-slate-950/50 border border-dashed border-white/5 rounded-2xl p-8 text-center">
                                <House size={24} className="text-slate-800 mx-auto mb-3" />
                                <p className="text-[10px] font-black text-slate-700 uppercase tracking-widest italic">
                                    No properties awaiting inspection.
                                </p>
                            </div>
                        ) : (
                            uninspectedHomes.map((home) => (
                                <HomeCard
                                    key={home.owner_id}
                                    home={home}
                                    onInspect={handleInspect}
                                    inspecting={inspectingId === home.owner_id}
                                    disabled={inspectingId !== null && inspectingId !== home.owner_id}
                                />
                            ))
                        )}
                    </div>
                </div>

                {/* Destroyed Home Reconstruction */}
                <div className="space-y-4">
                    <div className="border-b border-slate-800/50 pb-6">
                        <h1 className="text-3xl sm:text-4xl font-black text-white tracking-tight">
                            HOME <span className="text-red-400 italic">RECONSTRUCTION</span>
                        </h1>

                    </div>
                    <div className="space-y-3">
                        {destroyedHomes.length === 0 ? (
                            <div className="bg-slate-950/50 border border-dashed border-white/5 rounded-2xl p-8 text-center">
                                <House size={24} className="text-slate-800 mx-auto mb-3" />
                                <p className="text-[10px] font-black text-slate-700 uppercase tracking-widest italic">
                                    No destroyed properties in this city.
                                </p>
                            </div>
                        ) : (
                            destroyedHomes.map((home) => (
                                <div key={home.owner_id} className="relative flex flex-col sm:flex-row items-start sm:items-center gap-4 p-4 rounded-xl bg-slate-900/40 border border-red-900/30 hover:border-red-800/50 transition-all">
                                    <div className="shrink-0 w-12 h-12 rounded-lg bg-slate-800 flex items-center justify-center overflow-hidden">
                                        {home.property_image ? (
                                            <img src={home.property_image} alt={home.property_name} className="w-full h-full object-cover opacity-50 grayscale" />
                                        ) : (
                                            <House size={24} className="text-slate-500" />
                                        )}
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <div className="text-sm font-bold text-white uppercase tracking-wider truncate">
                                            {home.property_name}
                                        </div>
                                        <div className="text-[10px] text-slate-500 font-bold uppercase tracking-wider mt-0.5">
                                            Owner: {home.owner_name}
                                        </div>
                                        <div className="flex flex-wrap items-center gap-3 mt-2">
                                            <span className="text-[10px] font-bold text-red-400 uppercase tracking-wider flex items-center gap-1">
                                                <Fire size={10} weight="bold" />
                                                Bombed
                                            </span>
                                            <span className="text-[10px] font-bold text-emerald-400 uppercase tracking-wider flex items-center gap-1">
                                                <CurrencyDollar size={10} />
                                                ${home.repair_fee.toLocaleString()} reconstruction
                                            </span>
                                        </div>
                                    </div>
                                    <button
                                        onClick={() => handleRepairHome(home.owner_id)}
                                        disabled={repairingHomeId !== null}
                                        className={`shrink-0 px-5 py-2.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all ${repairingHomeId !== null
                                            ? 'bg-slate-800/50 text-slate-600 border border-slate-800/50 cursor-not-allowed'
                                            : 'bg-white text-slate-950 hover:bg-red-400 hover:shadow-[0_0_15px_rgba(248,113,113,0.3)]'
                                            }`}
                                    >
                                        {repairingHomeId === home.owner_id ? (
                                            <span className="flex items-center gap-2">
                                                <span className="w-3 h-3 border-2 border-slate-950/30 border-t-slate-950 rounded-full animate-spin" />
                                                Rebuilding...
                                            </span>
                                        ) : (
                                            <span className="flex items-center gap-2">
                                                <Wrench size={12} />
                                                Rebuild
                                            </span>
                                        )}
                                    </button>
                                </div>
                            ))
                        )}
                    </div>
                </div>


                <div className="space-y-4">
                    <div className="border-b border-slate-800/50 pb-6">
                        <h1 className="text-3xl sm:text-4xl font-black text-white tracking-tight">
                            CORPORATE <span className="text-cyan-400 italic">CONSTRUCTION</span>
                        </h1>
                    </div>
                    <div className="space-y-3">
                        {pendingCorpProperties.length === 0 ? (
                            <div className="bg-slate-950/50 border border-dashed border-white/5 rounded-2xl p-8 text-center">
                                <Buildings size={24} className="text-slate-800 mx-auto mb-3" />
                                <p className="text-[10px] font-black text-slate-700 uppercase tracking-widest italic">
                                    No corporation properties awaiting construction.
                                </p>
                            </div>
                        ) : (
                            pendingCorpProperties.map((property) => (
                                <div key={property.property_id} className="relative flex flex-col sm:flex-row items-start sm:items-center gap-4 p-4 rounded-xl bg-slate-900/40 border border-slate-800/80 hover:border-slate-700 transition-all">
                                    <div className="shrink-0 w-12 h-12 rounded-lg bg-slate-800 flex items-center justify-center overflow-hidden">
                                        {property.property_image ? (
                                            <img src={property.property_image} alt={property.property_name} className="w-full h-full object-cover" />
                                        ) : (
                                            <Buildings size={24} className="text-slate-500" />
                                        )}
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <div className="text-sm font-bold text-white uppercase tracking-wider truncate">
                                            {property.property_name}
                                        </div>
                                        <div className="text-[10px] text-slate-500 font-bold uppercase tracking-wider mt-0.5">
                                            Owner: {property.corporation_name}
                                        </div>
                                        <div className="flex flex-wrap items-center gap-3 mt-2">

                                            <span className="text-[10px] font-bold text-emerald-400 uppercase tracking-wider flex items-center gap-1">

                                                ${property.build_fee.toLocaleString()} fee
                                            </span>
                                        </div>
                                    </div>
                                    <button
                                        onClick={() => handleConstructProperty(property.property_id)}
                                        disabled={constructingId !== null}
                                        className={`shrink-0 px-5 py-2.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all ${constructingId !== null
                                            ? 'bg-slate-800/50 text-slate-600 border border-slate-800/50 cursor-not-allowed'
                                            : 'bg-white text-slate-950 hover:bg-cyan-400 hover:shadow-[0_0_15px_rgba(6,182,212,0.3)]'
                                            }`}
                                    >
                                        {constructingId === property.property_id ? (
                                            <span className="flex items-center gap-2">
                                                <span className="w-3 h-3 border-2 border-slate-950/30 border-t-slate-950 rounded-full animate-spin" />
                                                Building...
                                            </span>
                                        ) : (
                                            <span className="flex items-center gap-2">

                                                Build
                                            </span>
                                        )}
                                    </button>
                                </div>
                            ))
                        )}
                    </div>
                </div>

            </div>
        </div>
    );
}

Workshop.layout = page => <GameLayout children={page} />;
