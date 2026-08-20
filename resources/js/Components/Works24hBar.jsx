import { motion } from 'framer-motion';
import { Briefcase } from '@phosphor-icons/react';

const WORKS_MAX = 720;
const WORKS_TIER = 30;

// True gold gradients – metallic, shimmering, not flat orange
const GOLD_GRADIENT_LIGHT = 'linear-gradient(90deg, #D4AF37 0%, #FFDF00 35%, #FFF8DC 55%, #FFDF00 75%, #D4AF37 100%)';
const GOLD_GRADIENT_MEDIUM = 'linear-gradient(90deg, #C5A028 0%, #F0C800 35%, #FFE599 55%, #F0C800 75%, #C5A028 100%)';
const GOLD_GRADIENT_DEEP = 'linear-gradient(90deg, #B8860B 0%, #FFD700 35%, #FFFACD 55%, #FFD700 75%, #B8860B 100%)';

function getStyle(count) {
    const n = count || 0;
    const tier = Math.min(16, Math.floor(n / WORKS_TIER));
    const pct = Math.min(100, (n / WORKS_MAX) * 100);

    // ---- Majestic bar backgrounds ----
    let barBg;
    let numColor;
    let glowColor;
    let boxShadow;

    if (tier === 0) {
        barBg = '#14532d';
        numColor = '#94a3b8';
        glowColor = 'rgba(255,255,255,0.1)';
    } else if (tier <= 3) {
        // deep greens
        const greens = ['#14532d', '#166534', '#15803d'];
        barBg = greens[tier - 1];
        numColor = `hsl(${140 - tier * 4}, 70%, 55%)`;
        glowColor = `rgba(34,197,94,${0.1 + tier * 0.05})`;
    } else if (tier <= 6) {
        // bright greens
        const brightGreens = ['#22c55e', '#4ade80', '#86efac'];
        barBg = brightGreens[tier - 4];
        numColor = `hsl(${130 - tier * 2}, 85%, 60%)`;
        glowColor = `rgba(74,222,128,${0.2 + tier * 0.04})`;
    } else if (tier <= 9) {
        // lime to yellow-green
        const limeGreens = ['#a3e635', '#bef264', '#d9f99d'];
        barBg = limeGreens[tier - 7];
        numColor = `hsl(${110 - tier}, 90%, 65%)`;
        glowColor = `rgba(187,247,208,${0.3 + tier * 0.03})`;
    } else if (tier <= 12) {
        // amber -> starting to gold
        const ambers = ['#d97706', '#f59e0b', '#fbbf24'];
        barBg = ambers[tier - 10];
        numColor = `hsl(${45 - tier * 0.5}, 95%, 60%)`;
        glowColor = `rgba(251,191,36,${0.4 + tier * 0.02})`;
    } else if (tier === 13) {
        // Majestic gold – tier 13: lighter gold gradient
        barBg = GOLD_GRADIENT_LIGHT;
        numColor = '#FFDF00';
        glowColor = 'rgba(255,223,0,0.7)';
    } else if (tier === 14) {
        // Majestic gold – tier 14: medium gold gradient
        barBg = GOLD_GRADIENT_MEDIUM;
        numColor = '#FFD700';
        glowColor = 'rgba(255,215,0,0.8)';
    } else if (tier === 15) {
        // Majestic gold – tier 15: deep rich gold
        barBg = GOLD_GRADIENT_DEEP;
        numColor = '#FFC125';
        glowColor = 'rgba(255,193,37,0.9)';
    } else {
        // tier 16: Chairman shimmer (most intense)
        barBg = 'linear-gradient(90deg, #B8860B 0%, #FFD700 25%, #FFF8DC 50%, #FFD700 75%, #B8860B 100%)';
        numColor = '#FFEA70';
        glowColor = 'rgba(255,234,112,1.0)';
    }

    // Box shadow based on tier, golden for gold tiers
    if (tier >= 13) {
        boxShadow = `0 0 ${12 + tier}px ${glowColor}, 0 0 2px rgba(255,215,0,0.8)`;
    } else if (tier >= 4) {
        boxShadow = `0 0 ${4 + tier}px ${glowColor}`;
    } else {
        boxShadow = 'none';
    }

    return { pct, numColor, barBg, boxShadow };
}

export default function Works24hBar({ count, compact = false }) {
    const { pct, numColor, barBg, boxShadow } = getStyle(count);

    return (
        <div>
            <div className={`flex justify-between ${compact ? 'text-[10px] mb-0.5' : 'text-[11px] mb-1'} text-slate-400`}>
                <span className="flex items-center gap-1">
                    {!compact && <Briefcase size={11} weight="bold" />}
                    Personal Works / 24h
                </span>
                <span className="tabular-nums font-semibold" style={{ color: numColor, textShadow: '0 0 2px rgba(0,0,0,0.5)' }}>
                    {count || 0}
                </span>
            </div>
            <div className="h-1 bg-slate-800 rounded-full overflow-hidden">
                <motion.div
                    className="h-full rounded-full"
                    initial={{ width: 0 }}
                    animate={{ width: `${pct}%` }}
                    transition={{ duration: 0.6, ease: 'easeOut' }}
                    style={{ background: barBg, boxShadow }}
                />
            </div>
        </div>
    );
}