// Corporation — the climb. A night skyline whose office windows light up floor
// by floor, a stock line that draws itself upward with a glowing head, and an
// elevator light that rides the tallest tower to a penthouse that ignites.
// The un-animated markup is the reduced-motion hero frame.

import type { CSSProperties } from 'react';

const P = '#c084fc'; // purple accent
const C = '#22d3ee'; // cyan highlight
const BASE = 246;

// [x, width, top]
const TOWERS: Array<[number, number, number]> = [
    [30, 40, 176],
    [74, 34, 146],
    [112, 46, 116],
    [172, 56, 58],
    [232, 42, 126],
    [278, 36, 156],
    [318, 48, 186],
];
const BACK: Array<[number, number, number]> = [
    [8, 26, 206],
    [56, 30, 192],
    [150, 26, 166],
    [254, 30, 178],
    [356, 34, 200],
];

interface Win {
    x: number;
    y: number;
    d: number;
    t: boolean;
}

// Deterministic "random" lit windows: ~40% of the grid, lit bottom-up.
const WINDOWS: Win[] = [];
TOWERS.forEach(([tx, w, top], ti) => {
    for (let x = tx + 5, c = 0; x + 4 <= tx + w - 4; x += 9, c++) {
        if (ti === 3 && Math.abs(x + 2 - 200) < 6) continue; // elevator shaft
        for (let y = top + 10, r = 0; y + 5 <= BASE - 6; y += 12, r++) {
            const h = (ti * 31 + r * 17 + c * 7) % 10;
            if (h < 4) WINDOWS.push({ x, y, d: ((BASE - y) / 190) * 2.6 + (h % 3) * 0.08, t: (r + c + ti) % 4 === 0 });
        }
    }
});

const LINE = 'M20 230L58 214L90 222L128 186L158 196L194 150L226 160L260 112L290 124L328 74L380 44';

export default function CorporationScene({ uid }: { uid: string }) {
    const win = `${uid}w`;
    const area = `${uid}a`;
    const halo = `${uid}h`;
    const frame = `${uid}f`;
    return (
        <>
            <defs>
                <pattern id={win} width="9" height="12" patternUnits="userSpaceOnUse">
                    <rect x="5" y="2" width="4" height="5" fill={P} fillOpacity=".1" />
                </pattern>
                <linearGradient id={area} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stopColor={C} stopOpacity=".22" />
                    <stop offset="1" stopColor={C} stopOpacity="0" />
                </linearGradient>
                <clipPath id={frame}>
                    <rect width="400" height="300" />
                </clipPath>
                <radialGradient id={halo}>
                    <stop offset="0" stopColor={P} stopOpacity=".55" />
                    <stop offset="1" stopColor={P} stopOpacity="0" />
                </radialGradient>
            </defs>

            {/* penthouse halo */}
            <ellipse className="cc-co-pent" cx="200" cy="52" rx="74" ry="40" fill={`url(#${halo})`} />

            {/* far skyline */}
            <g fill="#0a0f1c" stroke={P} strokeOpacity=".12">
                {BACK.map(([x, w, t]) => (
                    <rect key={x} x={x} y={t} width={w} height={BASE - t} />
                ))}
            </g>

            {/* area under the stock line, behind the towers */}
            <path className="cc-co-area" d={`${LINE}L380 ${BASE}L20 ${BASE}Z`} fill={`url(#${area})`} />

            {/* towers */}
            {TOWERS.map(([x, w, t]) => (
                <g key={x}>
                    <rect x={x} y={t} width={w} height={BASE - t} fill="#0b1020" stroke={P} strokeOpacity=".3" />
                    <rect x={x} y={t} width={w} height={BASE - t} fill={`url(#${win})`} />
                </g>
            ))}

            {/* lit windows */}
            <g fill={P}>
                {WINDOWS.map((w, i) => (
                    <rect
                        key={i}
                        className={w.t ? 'cc-co-w cc-co-t' : 'cc-co-w'}
                        x={w.x}
                        y={w.y}
                        width="4"
                        height="5"
                        opacity={i % 3 === 2 ? 0.25 : 0.9}
                        style={{ '--d': `${w.d.toFixed(2)}s`, '--n': `-${(i % 9) * 1.1}s` } as CSSProperties}
                    />
                ))}
            </g>

            {/* stock line, in front of the skyline on a dark casing */}
            <g className="cc-co-line" fill="none" strokeLinejoin="round">
                <path d={LINE} pathLength={100} strokeDasharray="100 100" stroke="#020617" strokeOpacity=".75" strokeWidth="5" />
                <path d={LINE} pathLength={100} strokeDasharray="100 100" stroke={C} strokeWidth="2" />
            </g>

            {/* tallest tower: crown, penthouse, spire, elevator */}
            <rect x="182" y="44" width="36" height="14" fill="#0b1020" stroke={P} strokeOpacity=".6" />
            <rect className="cc-co-pent" x="185" y="47" width="30" height="8" fill={P} />
            <line x1="200" y1="44" x2="200" y2="16" stroke={P} strokeOpacity=".7" strokeWidth="1.5" />
            <circle className="cc-co-beacon" cx="200" cy="15" r="2.4" fill={C} />
            <line x1="200" y1="62" x2="200" y2={BASE} stroke={P} strokeOpacity=".22" />
            <rect className="cc-co-lift" x="197" y="62" width="6" height="9" rx="1" fill={C} />

            {/* glowing head of the stock line */}
            <path className="cc-co-head" d={LINE} pathLength={100} strokeDasharray="3 200" strokeDashoffset="-97" fill="none" stroke={C} strokeOpacity=".3" strokeWidth="9" strokeLinecap="round" />
            <path className="cc-co-head" d={LINE} pathLength={100} strokeDasharray="3 200" strokeDashoffset="-97" fill="none" stroke="#e0f2fe" strokeWidth="3" strokeLinecap="round" />

            {/* ticker */}
            <rect x="0" y="262" width="400" height="15" fill="#0b1020" fillOpacity=".85" />
            <line x1="0" x2="400" y1="262" y2="262" stroke={P} strokeOpacity=".3" />
            <g clipPath={`url(#${frame})`}>
            <g className="cc-co-tick" fontSize="8" fontFamily="ui-monospace,monospace" fill={P} fillOpacity=".75">
                {[0, 400].map((x) => (
                    <text key={x} x={x} y="272.5" textLength="400" lengthAdjust="spacing">
                        DRCT 412.80 ▲2.4% · CORP 88.15 ▲0.9% · NYX 120.04 ▼0.3% · TKO 64.70 ▲1.2% ·
                    </text>
                ))}
            </g>
            </g>
        </>
    );
}
