import { useState, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
// @ts-ignore
import { route } from 'ziggy-js';
import {
    AirplaneTilt, Car, Clock,
    GraduationCap, BookOpen, CheckCircle, ShieldCheck,
    AirplaneInFlight, Compass, CaretRight, Crown,
} from '@phosphor-icons/react';
import { motion, AnimatePresence } from 'framer-motion';
import GameLayout from '@/Layouts/GameLayout';
import { getCityImage } from '@/utils/cityImages';
import StyledModal from '@/Layouts/styledmodal';

// ── Types ────────────────────────────────────────────────────────────────────

interface Destination {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    image_url: string | null;
    distance: number;
    flight_time: number;
    flight_cost: number;

}

interface AcademyState {
    isCustoms: boolean;
    requiredCycles: number;
    training: {
        enrolled: boolean;
        cycles: number;
        completed: boolean;
        cityId: number;
        nextStudyAt: number | null;
    } | null;
}

interface IncomingPrice {
    city_slug: string;
    city_name: string;
    price: number;
}

interface OwnerSettings {
    incoming_prices: IncomingPrice[];
    min_price: number;
    max_price: number;
}

interface Props {
    cityName: string;
    citySlug: string;
    cityImage: string | null;
    hubName: string;
    hubImage: string | null;
    isOwner: boolean;
    ownerName: string;
    ownerAvatar: string | null;
    director: {
        id: number;
        name: string;
        rank: string;
        since: string;
        avatar_url: string | null;
    } | null;
    ownerSettings: OwnerSettings | null;
    destinations: Destination[];
    equippedVehicle: { name: string; image_url: string | null; slug: string } | null;
    academy: AcademyState;
    timers: {
        next_travel_at: number | null;
        next_study_at: number | null;
    };
}

const fmt$ = (n: number) => `$${n.toLocaleString()}`;
const fmtTime = (s: number) => {
    const m = Math.floor(s / 60);
    const sec = s % 60;
    return `${m}m ${sec < 10 ? '0' : ''}${sec}s`;
};

// ── Departure Card ───────────────────────────────────────────────────────────

function DepartureCard({
    dest, hasVehicle, onSelect,
}: {
    dest: Destination;
    hasVehicle: boolean;
    onSelect: () => void;
}) {
    const bg = getCityImage(dest.name, dest.image_url);

    return (
        <button
            onClick={onSelect}
            className="group relative block h-32 md:h-36 rounded-xl overflow-hidden border border-amber-500/[0.08] hover:border-amber-400/40 transition-all duration-300 text-left w-full cursor-pointer"
        >
            <img
                src={bg}
                alt={dest.name}
                className="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover:scale-105"
                style={{ filter: 'brightness(0.95) contrast(1.05) saturate(1.15)' }}
            />
            {/* Subtle left-side fade only — keep the city image bright */}
            <div className="absolute inset-0 bg-gradient-to-r from-slate-950/80 via-slate-950/10 to-transparent" />
            {/* Bottom-right corner shade so the price stays legible */}
            <div className="absolute bottom-0 right-0 w-1/2 h-full bg-gradient-to-l from-slate-950/70 to-transparent" />
            <div className="absolute inset-0 bg-amber-500/0 group-hover:bg-amber-500/[0.06] transition-colors" />

            {/* Top-left — destination identity */}
            <div className="absolute top-4 left-5 md:left-7 right-20 flex items-center gap-3 min-w-0">
                <AirplaneTilt size={16} weight="fill" className="text-amber-400 shrink-0" />
                <div className="min-w-0">
                    <div className="text-[9px] font-black uppercase tracking-[0.2em] text-amber-300/90 mb-0.5">
                        Gate · {dest.distance.toLocaleString()}km
                    </div>
                    <h3 className="text-xl md:text-2xl font-light text-white tracking-wide truncate drop-shadow-lg">
                        {dest.name}
                    </h3>
                </div>
            </div>

            {/* Bottom-right — price, anchored */}
            <div className="absolute bottom-3 right-4 md:right-6 text-right">
                <div className="text-white font-black text-lg md:text-xl tabular-nums leading-none drop-shadow-lg">
                    {fmt$(dest.flight_cost)}
                </div>
                <div className="flex items-center justify-end gap-1.5 text-base font-black text-white mt-1">
                    <Clock size={12} className="text-white/70" />
                    <span className="tabular-nums">{fmtTime(dest.flight_time)}</span>
                </div>
            </div>
        </button>
    );
}

// ── Customs Academy Panel ────────────────────────────────────────────────────

function AcademyPanel({
    academy, citySlug, cityName, processing, setProcessing, countdown,
}: {
    academy: AcademyState;
    citySlug: string;
    cityName: string;
    processing: boolean;
    setProcessing: (v: boolean) => void;
    countdown: number;
}) {
    const required = academy.requiredCycles;
    const t = academy.training;
    const cycles = t?.cycles ?? 0;
    const progress = required > 0 ? Math.round((cycles / required) * 100) : 0;
    const done = t?.completed ?? false;

    const enroll = () => {
        if (processing) return;
        setProcessing(true);
        router.post(route('city.transit-hub.academy.enroll', { city: citySlug }), {}, {
            only: ['auth', 'flash', 'academy', 'timers'],
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    const train = () => {
        if (processing || countdown > 0) return;
        setProcessing(true);
        router.post(route('city.transit-hub.academy.train', { city: citySlug }), {}, {
            only: ['auth', 'flash', 'academy', 'timers'],
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    const graduate = () => {
        if (processing) return;
        setProcessing(true);
        router.post(route('city.transit-hub.academy.graduate', { city: citySlug }), {}, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    if (academy.isCustoms) {
        return (
            <div className="flex flex-col items-center justify-center py-20 px-6 text-center gap-3">
                <ShieldCheck size={32} className="text-cyan-400" weight="fill" />
                <p className="text-sm font-bold text-white">You are already a Customs Officer.</p>

            </div>
        );
    }

    return (
        <div className="px-6 py-8 max-w-md mx-auto">
            <p className="text-[10px] font-black uppercase tracking-[0.28em] text-amber-400/80 mb-2">
                Customs Academy
            </p>
            <h2 className="text-2xl font-light text-white tracking-tight mb-2">
                The Bureau is hiring.
            </h2>
            <p className="text-sm text-slate-400 leading-relaxed mb-8">
                {required} training sessions stand between you and the badge.
            </p>

            <div className="flex items-baseline justify-between mb-2">
                <span className="text-[10px] font-black uppercase tracking-[0.22em] text-slate-500">
                    Progress
                </span>
                <span className="text-base font-light text-white tabular-nums">
                    {cycles}<span className="text-slate-600"> / {required}</span>
                </span>
            </div>
            <div className="h-px bg-slate-800 relative mb-8">
                <div
                    className="absolute inset-y-0 left-0 bg-amber-400 transition-all duration-700"
                    style={{ width: `${progress}%` }}
                />
            </div>

            {!t ? (
                <button
                    onClick={enroll}
                    disabled={processing}
                    className="w-full py-2.5 px-6 rounded-lg bg-amber-500 hover:bg-amber-400 text-slate-950 font-black text-xs uppercase tracking-[0.2em] flex items-center justify-center gap-2 transition disabled:opacity-50"
                >
                    <BookOpen size={14} weight="bold" />
                    {processing ? 'Enrolling…' : 'Enroll'}
                </button>
            ) : done ? (
                <button
                    onClick={graduate}
                    disabled={processing}
                    className="w-full py-2.5 px-6 rounded-lg bg-amber-500 hover:bg-amber-400 text-slate-950 font-black text-xs uppercase tracking-[0.2em] flex items-center justify-center gap-2 transition disabled:opacity-50"
                >
                    <ShieldCheck size={14} weight="fill" />
                    {processing ? 'Taking the Oath…' : 'Take the Oath'}
                </button>
            ) : (
                <button
                    onClick={train}
                    disabled={processing || countdown > 0}
                    className={`w-full py-2.5 px-6 rounded-lg font-black text-xs uppercase tracking-[0.2em] flex items-center justify-center gap-2 transition ${
                        countdown > 0
                            ? 'bg-slate-900 text-slate-500 cursor-not-allowed'
                            : 'bg-amber-500 hover:bg-amber-400 text-slate-950 disabled:opacity-50'
                    }`}
                >
                    {countdown > 0 ? (
                        <>
                            <Clock size={14} weight="bold" className="animate-pulse" />
                            {fmtTime(countdown)}
                        </>
                    ) : (
                        <>
                            <BookOpen size={14} weight="bold" />
                            {processing ? 'Training…' : 'Train'}
                        </>
                    )}
                </button>
            )}
        </div>
    );
}

// ── Owner Settings Panel ─────────────────────────────────────────────────────

function OwnerSettingsPanel({
    settings, citySlug, cityName,
}: {
    settings: OwnerSettings;
    citySlug: string;
    cityName: string;
}) {
    // Local draft of price-per-incoming-city; synced to the slider input
    const [draft, setDraft] = useState<Record<string, number>>(
        Object.fromEntries(settings.incoming_prices.map(p => [p.city_slug, p.price]))
    );
    const [saving, setSaving] = useState(false);

    const dirty = settings.incoming_prices.some(p => draft[p.city_slug] !== p.price);

    const save = () => {
        if (!dirty || saving) return;
        setSaving(true);
        router.post(
            route('city.transit-hub.settings', { city: citySlug }),
            { ticket_prices: draft },
            { preserveScroll: true, onFinish: () => setSaving(false) }
        );
    };

    return (
        <div className="px-6 py-6 max-w-2xl mx-auto">
            <div className="text-center mb-8">
                <h2 className="text-2xl font-light text-white tracking-tight mb-2">
                    Inbound Ticket Prices
                </h2>
                <p className="text-sm text-slate-400 max-w-md mx-auto leading-relaxed">
                    Set what travelers from each city pay to fly INTO {cityName}.

                </p>
            </div>

            <div className="space-y-3 mb-5">
                {settings.incoming_prices.map(row => (
                    <div
                        key={row.city_slug}
                        className="rounded-xl border border-white/[0.06] bg-slate-950/50 p-4"
                    >
                        <div className="flex items-center justify-between mb-3">
                            <div className="flex items-center gap-2">
                                <AirplaneInFlight size={12} className="text-slate-500" weight="bold" />
                                <span className="text-sm font-bold text-white">From {row.city_name}</span>
                            </div>
                            <span className="text-amber-300 font-bold text-base tabular-nums">
                                {fmt$(draft[row.city_slug] ?? row.price)}
                            </span>
                        </div>
                        <input
                            type="range"
                            min={settings.min_price}
                            max={settings.max_price}
                            step={50}
                            value={draft[row.city_slug] ?? row.price}
                            onChange={e => setDraft({ ...draft, [row.city_slug]: parseInt(e.target.value) })}
                            className="w-full h-2 bg-slate-800 rounded-lg appearance-none cursor-pointer accent-amber-500"
                        />
                        <div className="flex justify-between text-[10px] text-slate-600 mt-1.5 tabular-nums">
                            <span>{fmt$(settings.min_price)}</span>
                            <span>{fmt$(settings.max_price)}</span>
                        </div>
                    </div>
                ))}
            </div>

            <button
                onClick={save}
                disabled={!dirty || saving}
                className="w-full py-4 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-black text-xs uppercase tracking-[0.2em] flex items-center justify-center gap-2 transition disabled:opacity-30 disabled:cursor-not-allowed"
            >

                {saving ? 'Saving…' : dirty ? 'Save Prices' : 'No Changes'}
            </button>
        </div>
    );
}

// ── Root page ────────────────────────────────────────────────────────────────

type TabId = 'departures' | 'academy' | 'owner';

export default function TransitHub({
    cityName, citySlug, cityImage, hubName, hubImage,
    isOwner, ownerName, ownerAvatar, ownerSettings,
    destinations, equippedVehicle, academy, timers,
}: Props) {
    const { auth } = usePage<any>().props;
    const [tab, setTab] = useState<TabId>('departures');
    const [selectedDest, setSelectedDest] = useState<Destination | null>(null);
    const [processing, setProcessing] = useState<string | null>(null);
    const [academyProcessing, setAcademyProcessing] = useState(false);

    // Study countdown for academy training cooldown
    const [studyCountdown, setStudyCountdown] = useState(0);
    const serverTime = auth?.serverTime;



    const handleTravel = (method: 'flight' | 'vehicle') => {
        if (!selectedDest || processing) return;
        setProcessing(method);
        router.post(
            route('city.transit-hub.travel', { city: citySlug, destination: selectedDest.slug }),
            { method },
            {
                preserveScroll: true,
                onFinish: () => { setProcessing(null); setSelectedDest(null); },
            }
        );
    };

    const heroBg = hubImage || getCityImage(cityName, cityImage);

    const modalHeader = selectedDest
        ? (equippedVehicle?.image_url ?? getCityImage(selectedDest.name, selectedDest.image_url))
        : undefined;

    const tabs: { id: TabId; label: string; icon: any }[] = [
        { id: 'departures', label: `Departures (${destinations.length})`, icon: AirplaneInFlight },
        { id: 'academy', label: 'Customs Academy', icon: GraduationCap },
    ];
    if (isOwner && ownerSettings) {
        tabs.push({ id: 'owner', label: "Owner's Settings", icon: Crown });
    }

    return (
        <>
            <Head title={`${hubName} — TheDirector`} />
            <div className="w-full max-w-5xl mx-auto px-1 md:px-4 py-3 md:py-6 flex flex-col gap-4">

                {/* ── Hero ───────────────────────────────────────────────────── */}
                <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4 }}
                    className="relative rounded-2xl overflow-hidden border border-amber-500/[0.08] shadow-2xl shrink-0 h-56 md:h-64"
                >
                    <img
                        src={heroBg}
                        alt={hubName}
                        className="absolute inset-0 w-full h-full object-cover"
                        style={{ filter: 'brightness(0.55) contrast(1.1) saturate(1.1)' }}
                    />
                    <div className="absolute right-0 top-0 w-96 h-96 bg-amber-500/10 blur-[100px] rounded-full mix-blend-screen pointer-events-none" />
                    <div className="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-950/40 to-transparent" />

                    <div className="relative h-full flex flex-col md:flex-row md:items-end justify-between gap-6 px-7 md:px-10 pb-7 md:pb-9">
                        <div className="flex flex-col justify-end">
                            <h1 className="text-3xl md:text-5xl font-light text-white tracking-tight mb-2">
                                {hubName}
                            </h1>
                            <p className="text-sm text-slate-300/80 max-w-md">
                                All flights, all training, all paperwork — handled here.
                            </p>
                        </div>

                        {/* Owner + Director cards — mirrors Bank/Hospital convention. */}
                        <div className="flex items-center gap-3 flex-wrap">
                            <div className="flex items-center gap-3 bg-slate-950/80 backdrop-blur-xl border border-white/5 rounded-2xl pl-2 pr-5 py-1.5 shadow-2xl">
                                <div className="w-10 h-10 rounded-xl overflow-hidden ring-2 ring-amber-500/20 bg-slate-900 flex items-center justify-center shrink-0">
                                    {ownerAvatar ? (
                                        <img src={ownerAvatar} alt={ownerName} className="w-full h-full object-cover" />
                                    ) : (
                                        <Crown size={18} className="text-amber-400/40" weight="fill" />
                                    )}
                                </div>
                                <div className="text-left">
                                    <div className="text-[8px] uppercase tracking-[0.2em] text-amber-400/70 font-black mb-0.5">Hub Owner</div>
                                    <div className="text-white font-black text-xs leading-none">{ownerName}</div>
                                </div>
                            </div>


                        </div>
                    </div>
                </motion.div>

                {/* ── Tab bar ────────────────────────────────────────────────── */}
                <div className="flex gap-1 shrink-0 border-b border-slate-800/40 px-1 lg:px-0">
                    {tabs.map(t => {
                        const Icon = t.icon;
                        const active = tab === t.id;
                        return (
                            <button
                                key={t.id}
                                onClick={() => setTab(t.id)}
                                className={`flex items-center gap-2 px-5 py-3 text-[10px] font-black uppercase tracking-widest border-b-2 transition ${active
                                    ? 'border-amber-400 text-white'
                                    : 'border-transparent text-slate-500 hover:text-slate-300'
                                    }`}
                            >
                                <Icon size={12} weight={active ? 'fill' : 'bold'} />
                                {t.label}
                            </button>
                        );
                    })}
                </div>

                {/* ── Content ────────────────────────────────────────────────── */}
                <div className="flex-1 min-h-0 bg-slate-900/40 border border-slate-800/40 rounded-xl overflow-hidden">
                    <AnimatePresence mode="wait">

                        {tab === 'departures' && (
                            <motion.div
                                key="departures"
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                exit={{ opacity: 0 }}
                                className="p-3 md:p-4"
                            >
                                {destinations.length === 0 ? (
                                    <div className="flex flex-col items-center justify-center py-16 text-slate-700 gap-3">
                                        <AirplaneTilt size={28} />
                                        <p className="text-[10px] font-black uppercase tracking-widest">
                                            No outbound flights from {cityName}
                                        </p>
                                    </div>
                                ) : (
                                    <div className="flex flex-col gap-2">
                                        {destinations.map(dest => (
                                            <DepartureCard
                                                key={dest.id}
                                                dest={dest}
                                                hasVehicle={!!equippedVehicle}
                                                onSelect={() => setSelectedDest(dest)}
                                            />
                                        ))}
                                    </div>
                                )}
                            </motion.div>
                        )}

                        {tab === 'academy' && (
                            <motion.div
                                key="academy"
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                exit={{ opacity: 0 }}
                            >
                                <AcademyPanel
                                    academy={academy}
                                    citySlug={citySlug}
                                    cityName={cityName}
                                    processing={academyProcessing}
                                    setProcessing={setAcademyProcessing}
                                    countdown={studyCountdown}
                                />
                            </motion.div>
                        )}

                        {tab === 'owner' && isOwner && ownerSettings && (
                            <motion.div
                                key="owner"
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                exit={{ opacity: 0 }}
                            >
                                <OwnerSettingsPanel
                                    settings={ownerSettings}
                                    citySlug={citySlug}
                                    cityName={cityName}
                                />
                            </motion.div>
                        )}

                    </AnimatePresence>
                </div>

            </div>

            {/* ── Travel Modal ────────────────────────────────────────────────── */}
            <StyledModal
                isOpen={!!selectedDest}
                onClose={() => !processing && setSelectedDest(null)}
                headerImage={modalHeader}
                title={selectedDest ? `To ${selectedDest.name}` : ''}
                badges={[]}
                maxWidth="max-w-md"

            >
                {selectedDest && (
                    <div className="px-6 pb-6 pt-2 space-y-3">

                        {equippedVehicle && (
                            <button
                                onClick={() => handleTravel('vehicle')}
                                disabled={!!processing}
                                className="group w-full h-14 flex items-center justify-between gap-4 px-5 rounded-xl bg-white/10 border border-white/20 hover:bg-white/15 transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                            >
                                <div className="text-sm font-black text-white">{equippedVehicle.name}</div>
                                {processing === 'vehicle'
                                    ? <span className="text-xs text-slate-400 animate-pulse">Departing…</span>
                                    : <CaretRight size={14} className="text-slate-300" />
                                }
                            </button>
                        )}

                        <button
                            onClick={() => handleTravel('flight')}
                            disabled={!!processing}
                            className="group w-full h-14 flex items-center justify-between gap-4 px-5 rounded-xl bg-white/10 border border-white/20 hover:bg-white/15 transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            <div className="text-sm font-black text-white">Commercial Flight</div>
                            {processing === 'flight'
                                ? <span className="text-xs text-slate-400 animate-pulse">Departing…</span>
                                : <CaretRight size={14} className="text-slate-300" />
                            }
                        </button>

                    </div>
                )}
            </StyledModal>
        </>
    );
}

TransitHub.layout = (page: React.ReactNode) => <GameLayout children={page} />;
