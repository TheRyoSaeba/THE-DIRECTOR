import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
// @ts-ignore
import { route } from 'ziggy-js';
import {
    Users, MapPin, ArrowRight, CaretLeft, CaretRight
} from '@phosphor-icons/react';
import GameLayout from '@/Layouts/GameLayout';
import { getCityImage } from '@/utils/cityImages';

interface CityPolicies {
    income_tax_rate: number;
    corporate_tax_rate: number;
    corp_regulation_active: boolean;
    bonds_active: boolean;
    death_sentence_active: boolean;
}

interface CityData {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    image_url: string | null;
    crime_rate: number;
    mayor: string | null;
    residents: number;
    policies: CityPolicies | null;
}

interface Service {
    name: string;
    code: string;
    slug: string;
    icon: string;
    image_url: string | null;
    description: string;
}

interface CityNewsItem {
    id?: string;
    type: 'death' | 'promotion' | 'business';
    message: string;
    occurredAt: string;
}

interface CityProps {
    cityData: CityData;
    services: Service[];
    cityNews?: {
        items: CityNewsItem[];
    } | null;
    population?: {
        data: {
            displayName: string;
            avatarUrl: string | null;
            rank: string;
            career: string;
            glowColor: string;
            isDead: boolean;
        }[];
        current_page: number;
        last_page: number;
        links: {
            url: string | null;
            label: string;
            active: boolean;
        }[];
    } | null;
}

const SERVICES_PER_PAGE = 4;

function CityWire({ cityNews }: { cityNews?: { items: CityNewsItem[] } | null }) {
    const items = cityNews?.items ?? [];
    const hasItems = items.length > 0;

    const trackRef = useRef<HTMLDivElement>(null);
    const messageRefs = useRef<(HTMLDivElement | null)[]>([]);
    // Per-message inline style carrying the CSS variables the keyframe reads.
    const [styles, setStyles] = useState<React.CSSProperties[]>([]);

    useEffect(() => {
        if (!hasItems) {
            return;
        }

        const recompute = () => {
            const track = trackRef.current;
            if (!track) return;

            const containerWidth = track.clientWidth;

            // Each message travels containerWidth + its own width (fully on
            // from the right edge to fully off the left). Duration derives
            // from that pixel distance / a fixed 90px/sec, so px/sec is
            // constant regardless of screen size. The total loop time is the
            // SUM of all message durations; messages are evenly phase-shifted
            // across that total so they chase each other without overlapping.
            const widths = messageRefs.current.map((el) => el?.scrollWidth ?? 0);
            const durations = widths.map(
                (w) => (containerWidth + w) / 90
            );
            const totalLoop = durations.reduce((a, b) => a + b, 0) || 1;

            let elapsed = 0;
            const next: React.CSSProperties[] = widths.map((w, i) => {
                // Stagger: each message starts when the previous one has
                // travelled its share of the loop. Negative delay so they're
                // already in motion on first paint instead of all bunched at
                // the right edge waiting their turn.
                const delay = -(totalLoop - elapsed);
                elapsed += durations[i];

                return {
                    // Whole loop shares one duration so phase math stays
                    // coherent; per-message travel difference is absorbed by
                    // the start/end pixel offsets below.
                    ['--ticker-duration' as string]: `${totalLoop}s`,
                    ['--ticker-start' as string]: `${containerWidth}px`,
                    ['--ticker-end' as string]: `${-w}px`,
                    animationDelay: `${delay}s`,
                };
            });

            setStyles(next);
        };

        recompute();

        const ro = new ResizeObserver(recompute);
        if (trackRef.current) ro.observe(trackRef.current);
        return () => ro.disconnect();
    }, [hasItems, items.length, items.map((it) => it.message).join('|')]);

    return (
        <div className="mb-4 overflow-hidden border-y border-cyan-500/20 bg-slate-950/45">
            <div className="flex min-h-12 items-center">
                <div className="z-10 shrink-0 border-r border-red-500/25 bg-red-950/35 px-3 py-2 self-stretch flex items-center text-[10px] font-black uppercase tracking-[0.24em] text-red-300 sm:px-4">
                    Breaking News
                </div>

                <div ref={trackRef} className="relative min-w-0 flex-1 overflow-hidden">
                    {hasItems && (
                        <>
                            <div className="pointer-events-none absolute inset-y-0 left-0 z-10 w-8 bg-gradient-to-r from-slate-950/95 to-transparent" />
                            <div className="pointer-events-none absolute inset-y-0 right-0 z-10 w-8 bg-gradient-to-l from-slate-950/95 to-transparent" />
                        </>
                    )}

                    {hasItems ? (
                        <div className="pointer-events-none relative h-8">
                            {items.map((item, i) => (
                                <div
                                    key={item.id ?? item.message}
                                    ref={(el) => { messageRefs.current[i] = el; }}
                                    className="city-news-ticker absolute whitespace-nowrap py-1.5 text-xs font-semibold text-white/90"
                                    style={styles[i]}
                                >
                                    <span className="px-5">{item.message}</span>
                                </div>
                            ))}
                        </div>
                    ) : (
                        // Resting state — shown both before cityNews loads AND
                        // when there are genuinely no events. Same calm message
                        // for both, so the deferred load doesn't flash a
                        // skeleton then swap to the ticker (which caused the
                        // blur/flicker). The ticker only ever replaces this once
                        // real items exist.
                        <p className="px-4 py-2 text-xs font-medium text-white/55">
                            There are no major events at the moment.
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}

export default function City({ cityData, services, cityNews = null, population = null }: CityProps) {
    const bgImage = getCityImage(cityData.name, cityData.image_url);
    const [pageIndex, setPageIndex] = useState(0);
    const [cityNewsRequested, setCityNewsRequested] = useState(false);
    const [populationRequested, setPopulationRequested] = useState(false);

    // Must mirror CrimeThresholds.php — update both together.
    const getCrimeLevel = (rate: number) => {
        if (rate <= 15) return { label: 'Safe', color: 'text-emerald-400' };
        if (rate <= 35) return { label: 'Low', color: 'text-cyan-400' };
        if (rate <= 55) return { label: 'Moderate', color: 'text-amber-400' };
        if (rate <= 75) return { label: 'High', color: 'text-orange-400' };
        return { label: 'Critical', color: 'text-red-400' };
    };

    const crime = getCrimeLevel(cityData.crime_rate);
    const policies = cityData.policies;

    const getServiceHref = (service: Service) => {
        const isShop = service.code?.startsWith('shop-');
        if (isShop) {
            return route('city.shop.index', { city: cityData.slug, business_slug: service.slug });
        }
        try {
            return route(`city.${service.code}.index`, { city: cityData.slug });
        } catch {
            return route('city.business.index', { city: cityData.slug });
        }
    };


    const totalServicePages = Math.ceil(services.length / SERVICES_PER_PAGE);
    const totalPages = totalServicePages + 1;
    const isLastPage = pageIndex === totalPages - 1;
    const isFirstPage = pageIndex === 0;

    const currentServices = useMemo(() => {
        const start = pageIndex * SERVICES_PER_PAGE;
        return services.slice(start, start + SERVICES_PER_PAGE);
    }, [services, pageIndex]);

    const goNext = () => setPageIndex((p) => Math.min(p + 1, totalPages - 1));
    const goPrev = () => setPageIndex((p) => Math.max(p - 1, 0));

    useEffect(() => {
        if (cityNews || cityNewsRequested) {
            return;
        }

        setCityNewsRequested(true);
        router.reload({
            only: ['cityNews'],
        });
    }, [cityNews, cityNewsRequested]);

    useEffect(() => {
        if (!isLastPage || population || populationRequested) {
            return;
        }

        setPopulationRequested(true);
        router.reload({
            only: ['population'],
        });
    }, [isLastPage, population, populationRequested]);

    return (
        <>
            <Head title={`${cityData.name} — TheDirector`}>
                <script
                    head-key="adsense-loader"
                    async
                    src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1145262174285661"
                    crossOrigin="anonymous"
                />
            </Head>

            <div className="w-full max-w-5xl mx-auto flex flex-col h-full">


                <div className="relative rounded-2xl overflow-hidden border border-white/5 shadow-2xl mb-4">
                    <div className="absolute inset-0">
                        <img
                            src={bgImage}
                            alt={cityData.name}
                            className="w-full h-full object-cover"
                            style={{ filter: 'brightness(0.5) contrast(1.15) saturate(1.1)' }}
                        />
                        <div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/60 to-transparent" />
                    </div>

                    <div className="relative px-8 pt-16 pb-8 md:px-10 md:pt-20 md:pb-10">
                        <div className="max-w-3xl">
                            <div className="flex items-center gap-2 mb-3">

                            </div>
                            <h1 className="text-4xl md:text-5xl font-light text-white tracking-tight mb-2">
                                {cityData.name}
                            </h1>
                            {cityData.description && (
                                <p className="text-white font-small">
                                    {cityData.description}
                                </p>
                            )}
                        </div>

                        <div className="mt-8 flex flex-wrap gap-8 text-sm">
                            <div>
                                <div className="text-[10px] uppercase tracking-widest text-cyan-400/85 mb-1">Residents</div>
                                <div className="text-white font-medium flex items-center gap-1.5">
                                    <Users className="w-3.5 h-3.5 text-cyan-300/80" />
                                    {cityData.residents.toLocaleString()}
                                </div>
                            </div>
                            <div>
                                <div className="text-[10px] uppercase tracking-widest text-cyan-400/85 mb-1">Crime Rate</div>
                                <div className={`font-medium ${crime.color}`}>
                                    {cityData.crime_rate}% — {crime.label}
                                </div>
                            </div>
                            <div>
                                <div className="text-[10px] uppercase tracking-widest text-cyan-400/85 mb-1">Mayor</div>
                                <div className="text-white font-medium">
                                    {cityData.mayor || 'City Council'}
                                </div>
                            </div>
                            {policies && (
                                <>
                                    <div>
                                        <div className="text-[10px] uppercase tracking-widest text-cyan-400/85 mb-1">Income Tax</div>
                                        <div className="text-white font-medium">
                                            {policies.income_tax_rate}%
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-[10px] uppercase tracking-widest text-cyan-400/85 mb-1">Corporate Regulation</div>
                                        <div className={`font-medium ${policies.corp_regulation_active ? 'text-cyan-300' : 'text-white'}`}>
                                            {policies.corp_regulation_active ? 'ACTIVE' : 'INACTIVE'}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-[10px] uppercase tracking-widest text-cyan-400/85 mb-1">Corp Tax</div>
                                        <div className="text-white font-medium">
                                            {policies.corp_regulation_active ? `${policies.corporate_tax_rate}%` : 'INACTIVE'}
                                        </div>
                                    </div>
                                    <div>

                                    </div>
                                    <div>
                                        <div className="text-[10px] uppercase tracking-widest text-cyan-400/85 mb-1">Capital Punishment</div>
                                        <div className={`font-medium ${policies.death_sentence_active ? 'text-cyan-300' : 'text-white'}`}>
                                            {policies.death_sentence_active ? 'ACTIVE' : 'INACTIVE'}
                                        </div>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>
                </div>

                <CityWire cityNews={cityNews} />

                <div className="flex-1 flex flex-col">


                    {!isLastPage && currentServices.length > 0 && (
                        <div className="flex flex-col gap-1">
                            {currentServices.map((service) => {
                                const href = getServiceHref(service);

                                return (
                                    <Link
                                        key={service.slug}
                                        href={href}
                                        className="group relative block h-28 md:h-32 rounded-xl overflow-hidden border border-white/5 hover:border-cyan-500/30 transition-all duration-300"
                                    >
                                        {service.image_url ? (
                                            <img
                                                src={service.image_url}
                                                alt=""
                                                className="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover:scale-105"
                                                style={{ filter: 'brightness(0.8) contrast(1.1) saturate(1.2)' }}
                                            />
                                        ) : (
                                            <div className="absolute inset-0 bg-slate-900" />
                                        )}

                                        <div className="absolute inset-0 bg-gradient-to-r from-transparent via-slate-950/30 to-slate-950/80" />
                                        <div className="absolute inset-0 bg-gradient-to-t from-slate-950/20 to-transparent" />
                                        <div className="absolute inset-0 bg-cyan-500/0 group-hover:bg-cyan-500/5 transition-colors duration-300" />

                                        <div className="relative h-full flex items-center justify-end px-6 md:px-10">
                                            <div className="text-right max-w-sm">
                                                <h3 className="text-xl md:text-2xl font-light text-white tracking-wide group-hover:text-cyan-50 transition-colors">
                                                    {service.name}
                                                </h3>
                                                <p className="text-xs text-white/75 mt-1 leading-relaxed group-hover:text-white/90 transition-colors line-clamp-2">
                                                    {service.description}
                                                </p>
                                            </div>
                                            <ArrowRight className="w-5 h-5 text-slate-600 group-hover:text-cyan-400 ml-4 shrink-0 transition-all duration-300 group-hover:translate-x-1" />
                                        </div>
                                    </Link>
                                );
                            })}
                        </div>
                    )}


                    {isLastPage && (
                        <div className="bg-slate-900/40 border border-white/5 rounded-xl p-6">
                            <h2 className="text-sm font-semibold uppercase tracking-widest text-cyan-400/90 mb-5 flex items-center gap-2">
                                <Users className="w-4 h-4 text-cyan-400" />
                                Population
                            </h2>

                            {!population ? (
                                <p className="text-sm text-slate-500 text-center py-4">Loading population...</p>
                            ) : population.data.length > 0 ? (
                                <>
                                    <div className="flex flex-wrap items-center gap-4">
                                        {population.data.map((resident) => (
                                            <Link
                                                key={resident.displayName}
                                                href={`/profile/${resident.displayName}`}
                                                className="group flex flex-col items-center"
                                            >
                                                <div className={`relative w-14 h-14 rounded-full border-2 overflow-hidden flex items-center justify-center transition-all group-hover:scale-105 ${resident.isDead
                                                    ? 'border-red-900/50 bg-slate-900'
                                                    : 'border-slate-700/50 bg-slate-800 group-hover:border-cyan-500/50'
                                                    }`}>
                                                    {resident.isDead ? (
                                                        <img
                                                            src="https://images.thedirector.app/Conflict/dying.png"
                                                            alt={resident.displayName}
                                                            className="w-full h-full object-cover grayscale opacity-80"
                                                        />
                                                    ) : resident.avatarUrl ? (
                                                        <img
                                                            src={resident.avatarUrl}
                                                            alt={resident.displayName}
                                                            className="w-full h-full object-cover"
                                                        />
                                                    ) : (
                                                        <Users className="w-5 h-5 text-slate-600" />
                                                    )}
                                                </div>
                                                <span className={`mt-1.5 text-[10px] font-medium transition-colors truncate max-w-[70px] ${resident.isDead
                                                    ? 'text-red-900/70'
                                                    : 'text-slate-500 group-hover:text-cyan-400'
                                                    }`}>
                                                    {resident.displayName}
                                                </span>
                                            </Link>
                                        ))}
                                    </div>

                                    {population.last_page > 1 && (
                                        <div className="mt-5 pt-4 border-t border-slate-800/50 flex items-center justify-center gap-3">
                                            {population.links.map((link, i) => {
                                                if (link.label.includes('Previous')) {
                                                    return link.url ? (
                                                        <Link
                                                            key={i}
                                                            href={link.url}
                                                            preserveScroll
                                                            preserveState
                                                            className="px-4 py-1.5 bg-slate-800/50 hover:bg-slate-700/50 text-xs text-slate-400 rounded-lg border border-slate-700/30 transition-colors"
                                                        >
                                                            ← Prev
                                                        </Link>
                                                    ) : (
                                                        <span key={i} className="px-4 py-1.5 text-xs text-slate-600 rounded-lg opacity-40 cursor-not-allowed">
                                                            ← Prev
                                                        </span>
                                                    );
                                                }
                                                if (link.label.includes('Next')) {
                                                    return link.url ? (
                                                        <Link
                                                            key={i}
                                                            href={link.url}
                                                            preserveScroll
                                                            preserveState
                                                            className="px-4 py-1.5 bg-slate-800/50 hover:bg-slate-700/50 text-xs text-slate-400 rounded-lg border border-slate-700/30 transition-colors"
                                                        >
                                                            Next →
                                                        </Link>
                                                    ) : (
                                                        <span key={i} className="px-4 py-1.5 text-xs text-slate-600 rounded-lg opacity-40 cursor-not-allowed">
                                                            Next →
                                                        </span>
                                                    );
                                                }
                                                return null;
                                            })}
                                            <span className="text-[10px] text-slate-600 uppercase tracking-wider">
                                                {population.current_page} / {population.last_page}
                                            </span>
                                        </div>
                                    )}
                                </>
                            ) : (
                                <p className="text-sm text-slate-600 text-center py-4">No residents found</p>
                            )}
                        </div>
                    )}


                    {totalPages > 1 && (
                        <div className="mt-auto pt-4 flex items-center justify-center gap-4">
                            <button
                                onClick={goPrev}
                                disabled={isFirstPage}
                                className={`flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-medium transition-all ${isFirstPage
                                    ? 'text-slate-600 cursor-not-allowed'
                                    : 'text-slate-300 hover:text-white bg-slate-800/40 hover:bg-slate-800/70 border border-slate-700/30'
                                    }`}
                            >
                                <CaretLeft className="w-3.5 h-3.5" weight="bold" />
                                Prev
                            </button>

                            <div className="flex items-center gap-1.5">
                                {Array.from({ length: totalPages }, (_, i) => (
                                    <button
                                        key={i}
                                        onClick={() => setPageIndex(i)}
                                        className={`w-2 h-2 rounded-full transition-all ${i === pageIndex
                                            ? 'bg-cyan-400 w-5'
                                            : 'bg-slate-700 hover:bg-slate-500'
                                            }`}
                                    />
                                ))}
                            </div>

                            <button
                                onClick={goNext}
                                disabled={isLastPage}
                                className={`flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-medium transition-all ${isLastPage
                                    ? 'text-slate-600 cursor-not-allowed'
                                    : 'text-slate-300 hover:text-white bg-slate-800/40 hover:bg-slate-800/70 border border-slate-700/30'
                                    }`}
                            >
                                Next
                                <CaretRight className="w-3.5 h-3.5" weight="bold" />
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

City.layout = (page: React.ReactNode) => <GameLayout wide children={page} />;
