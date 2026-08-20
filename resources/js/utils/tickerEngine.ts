export type RegimeType = 'bull' | 'bear' | 'sideways' | 'volatile';

export interface TickerProfile {
    symbol: string;
    displaySymbol: string;
    name: string;
    exchange: string;
    sector: string;
    payoutMultiplier: number;
}

export interface TickerState {
    profile: TickerProfile;
    regime: RegimeType;
    price: number;
    priceDelta: number;
    chartPrices: number[];
}

export const REGIME_LABELS: Record<RegimeType, string> = {
    bull: 'BULL',
    bear: 'BEAR',
    sideways: 'RANGE',
    volatile: 'VOLATILE',
};

export const REGIME_COLORS: Record<RegimeType, { text: string; bg: string; border: string; dot: string }> = {
    bull: { text: 'text-emerald-400', bg: 'bg-emerald-500/10', border: 'border-emerald-500/30', dot: 'bg-emerald-400' },
    bear: { text: 'text-red-400', bg: 'bg-red-500/10', border: 'border-red-500/30', dot: 'bg-red-400' },
    sideways: { text: 'text-slate-400', bg: 'bg-slate-500/10', border: 'border-slate-500/30', dot: 'bg-slate-400' },
    volatile: { text: 'text-amber-400', bg: 'bg-amber-500/10', border: 'border-amber-500/30', dot: 'bg-amber-400' },
};
