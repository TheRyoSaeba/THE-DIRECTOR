
import React, { useState, useEffect } from 'react';
import { motion } from 'framer-motion';
import {
    REGIME_LABELS,
    REGIME_COLORS,
    type TickerState,
} from '@/utils/tickerEngine';

const MARKET_POLL_MS = 5000;

export function useMarketFeed(initialStates: TickerState[], initialServerTime: number, feedUrl: string) {
    const [states, setStates] = useState<TickerState[]>(initialStates);
    const [serverTime, setServerTime] = useState(initialServerTime || Math.floor(Date.now() / 1000));
    const [utcNow, setUtcNow] = useState(serverTime);

    useEffect(() => {
        const baseUtc = serverTime || Math.floor(Date.now() / 1000);
        const loadedAt = Date.now();
        const id = setInterval(() => {
            const elapsed = Math.floor((Date.now() - loadedAt) / 1000);
            setUtcNow(baseUtc + elapsed);
        }, 3000);
        return () => clearInterval(id);
    }, [serverTime]);

    useEffect(() => {
        let cancelled = false;

        const refresh = async () => {
            try {
                const response = await fetch(feedUrl, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (! response.ok) {
                    return;
                }

                const data = await response.json();
                if (cancelled || ! Array.isArray(data.snapshots)) {
                    return;
                }

                setStates(data.snapshots);
                setServerTime(Number(data.server_time) || Math.floor(Date.now() / 1000));
            } catch {
                // Keep the last quote visible; failed polling must not blank the trading terminal.
            }
        };

        const id = setInterval(refresh, MARKET_POLL_MS);
        return () => {
            cancelled = true;
            clearInterval(id);
        };
    }, [feedUrl]);

    return { states, utcNow, marketTime: serverTime };
}


export function RegimeBadge({ regime }: { regime: TickerState['regime'] }) {
    const c = REGIME_COLORS[regime];
    return (
        <span className={`inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-black tracking-widest border ${c.bg} ${c.border} ${c.text}`}>
            <span className={`w-1 h-1 rounded-full ${c.dot}`} />
            {REGIME_LABELS[regime]}
        </span>
    );
}


interface TickerCellProps {
    state: TickerState;
    selected: boolean;
    onClick: () => void;
}

function TickerCell({ state, selected, onClick }: TickerCellProps) {
    const { profile, price, priceDelta, regime } = state;
    const up = priceDelta >= 0;
    const c = REGIME_COLORS[regime];
    const priceColor = up ? 'text-emerald-400' : 'text-red-400';
    const deltaColor = up ? 'text-emerald-500/70' : 'text-red-500/70';
    const delta = up ? `+${priceDelta.toFixed(2)}` : priceDelta.toFixed(2);

    return (
        <motion.button
            onClick={onClick}
            whileTap={{ scale: 0.97 }}
            className={`flex flex-1 flex-col items-start px-4 py-2.5 border-r border-white/[0.04] transition-colors min-w-[150px] border-b-2 ${selected
                ? `${c.bg} ${c.border.replace('border-', 'border-b-')}`
                : 'hover:bg-white/[0.02] border-b-transparent'
                }`}
        >
            <span className={`text-xs font-black tracking-widest mb-0.5 ${selected ? c.text : 'text-white/80'}`}>
                {profile.displaySymbol}
            </span>
            <div className="flex items-baseline gap-1.5">
                <motion.span
                    key={Math.round(price * 100)}
                    initial={{ opacity: 0.7 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.2 }}
                    className={`text-sm font-semibold tabular-nums ${priceColor}`}
                >
                    ${price.toFixed(2)}
                </motion.span>
                <span className={`text-xs tabular-nums ${deltaColor}`}>
                    {delta}
                </span>
            </div>
            <div className="mt-1">
                <RegimeBadge regime={regime} />
            </div>
        </motion.button>
    );
}


interface TickerStripProps {
    states: TickerState[];
    selectedSymbol: string;
    onSelect: (symbol: string) => void;
}

export function TickerStrip({ states, selectedSymbol, onSelect }: TickerStripProps) {
    return (
        <div className="flex w-full gap-0 overflow-x-auto border-b border-white/[0.04] scrollbar-none">
            {states.map(state => (
                <TickerCell
                    key={state.profile.symbol}
                    state={state}
                    selected={state.profile.symbol === selectedSymbol}
                    onClick={() => onSelect(state.profile.symbol)}
                />
            ))}
        </div>
    );
}


const CHART_W = 400;
const CHART_H = 80;
interface MiniChartProps {
    state: TickerState;
}

export function MiniChart({ state }: MiniChartProps) {
    const { profile } = state;
    const prices = state.chartPrices?.length ? state.chartPrices : [state.price];
    const sampleCount = Math.max(1, prices.length);

    const minP = Math.min(...prices);
    const maxP = Math.max(...prices);
    const range = maxP - minP || 1;


    const pad = range * 0.08;
    const lo = minP - pad;
    const hi = maxP + pad;
    const span = hi - lo;

    const toY = (p: number) => CHART_H - ((p - lo) / span) * CHART_H;
    const toX = (i: number) => sampleCount === 1 ? CHART_W : (i / (sampleCount - 1)) * CHART_W;

    const pts = prices.map((p, i) => `${toX(i).toFixed(1)},${toY(p).toFixed(1)}`).join(' ');


    const firstX = toX(0).toFixed(1);
    const lastX = toX(sampleCount - 1).toFixed(1);
    const areaD =
        `M ${firstX},${CHART_H} ` +
        prices.map((p, i) => `L ${toX(i).toFixed(1)},${toY(p).toFixed(1)}`).join(' ') +
        ` L ${lastX},${CHART_H} Z`;

    const currentY = toY(prices[prices.length - 1]);
    const currentX = toX(sampleCount - 1);
    const openPrice = prices[0];
    const closePrice = prices[prices.length - 1];
    const isUp = closePrice >= openPrice;
    const lineColor = isUp ? '#34d399' : '#f87171';
    const gradId = `grad-${profile.symbol}`;

    return (
        <div className="w-full flex flex-col gap-1">

            <div className="flex justify-between text-xs font-mono text-white/60 px-0.5 mb-1.5">
                <span>${openPrice.toFixed(2)}</span>
                <span className={isUp ? 'text-emerald-500' : 'text-red-500'}>
                    ${closePrice.toFixed(2)}
                </span>
            </div>

            <svg
                viewBox={`0 0 ${CHART_W} ${CHART_H}`}
                preserveAspectRatio="none"
                className="w-full h-16"
            >
                <defs>
                    <linearGradient id={gradId} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor={lineColor} stopOpacity="0.25" />
                        <stop offset="100%" stopColor={lineColor} stopOpacity="0.02" />
                    </linearGradient>
                </defs>


                <line
                    x1="0" y1={CHART_H / 2}
                    x2={CHART_W} y2={CHART_H / 2}
                    stroke="#334155" strokeWidth="0.5" strokeDasharray="4 4"
                />


                <path d={areaD} fill={`url(#${gradId})`} />


                <polyline
                    points={pts}
                    fill="none"
                    stroke={lineColor}
                    strokeWidth="1.5"
                    strokeLinejoin="round"
                    strokeLinecap="round"
                />


                <circle cx={currentX} cy={currentY} r="3" fill={lineColor} />
                <circle cx={currentX} cy={currentY} r="5" fill={lineColor} fillOpacity="0.25" />
            </svg>


            <div className="flex items-center justify-between px-0.5">
                <span className="text-[10px] text-white/45 font-mono uppercase tracking-widest mt-1">
                    1HR
                </span>
            </div>
        </div>
    );
}
