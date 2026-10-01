// Technician — the fix. Three meshed gears (12 / 18 / 8 teeth, module 5) turning
// at their true ratios, circuit traces with energy pulses, a ring spanner
// ratcheting a hex nut and a solder joint throwing sparks, over blueprint
// annotations. The un-animated markup is the reduced-motion hero frame.

import type { CSSProperties } from 'react';

const E = '#34d399'; // emerald accent
const C = '#22d3ee'; // cyan highlight
const D = '#07140f';
const M = 5; // gear module: pitch radius = M * teeth / 2

function gearPath(n: number, cx: number, cy: number): string {
    const rp = (M * n) / 2;
    const ro = rp + M;
    const rr = rp - 1.25 * M;
    const p = (Math.PI * 2) / n;
    const pt = (r: number, a: number) => `${(cx + r * Math.cos(a)).toFixed(1)} ${(cy + r * Math.sin(a)).toFixed(1)}`;
    let d = '';
    for (let i = 0; i < n; i++) {
        const a = i * p;
        d += `${i ? 'L' : 'M'}${pt(rr, a - p * 0.3)}L${pt(ro, a - p * 0.14)}L${pt(ro, a + p * 0.14)}L${pt(rr, a + p * 0.3)}`;
    }
    const h = rp * 0.32;
    return `${d}ZM${cx + h} ${cy}a${h} ${h} 0 1 0 ${-2 * h} 0a${h} ${h} 0 1 0 ${2 * h} 0Z`;
}

// Mesh: A has a tooth on the A→B line, so B is offset by half a tooth there; same for B→C.
const deg = Math.PI / 180;
const A = { n: 12, x: 172, y: 152, off: 0 };
const thAB = -30; // multiple of A's 30° pitch
const B = { n: 18, x: A.x + 75 * Math.cos(thAB * deg), y: A.y + 75 * Math.sin(thAB * deg), off: thAB + 180 + 10 };
const thBC = B.off - 100; // a tooth of B (B.off + k·20°)
const Cg = { n: 8, x: B.x + 65 * Math.cos(thBC * deg), y: B.y + 65 * Math.sin(thBC * deg), off: thBC + 180 + 22.5 };
const GEARS = [
    { ...A, cls: 'cc-te-ga' },
    { ...B, cls: 'cc-te-gb' },
    { ...Cg, cls: 'cc-te-gc' },
].map((g) => ({ ...g, d: gearPath(g.n, g.x, g.y) }));

const TRACES = [
    'M20 196H58L74 180H112L128 164',
    'M90 232H150L166 216H246L262 232H380',
    'M302 30V58L318 74H380',
    'M20 118H40L58 100H72',
    'M330 210H352L368 194V140',
];
const PADS: Array<[number, number]> = [
    [20, 196], [128, 164], [380, 232], [302, 30], [380, 74], [20, 118], [330, 210], [368, 140],
];
const SPARKS = [-160, -128, -96, -64, -32, -112];
const NUT = Array.from({ length: 6 }, (_, i) => {
    const a = (i * 60 + 30) * deg;
    return `${(96 + 9 * Math.cos(a)).toFixed(1)},${(98 + 9 * Math.sin(a)).toFixed(1)}`;
}).join(' ');

export default function TechnicianScene({ uid }: { uid: string }) {
    const glow = `${uid}g`;
    return (
        <>
            <defs>
                <radialGradient id={glow}>
                    <stop offset="0" stopColor={C} stopOpacity=".9" />
                    <stop offset="1" stopColor={C} stopOpacity="0" />
                </radialGradient>
            </defs>

            {/* blueprint annotations */}
            <g fill="none" stroke={C} strokeOpacity=".22">
                <circle cx={B.x} cy={B.y} r="60" strokeDasharray="3 4" />
                <path d={`M${A.x} ${A.y}L${B.x} ${B.y}`} strokeDasharray="2 3" />
                {GEARS.map((g) => (
                    <path key={g.cls} d={`M${g.x - 6} ${g.y}h12M${g.x} ${g.y - 6}v12`} strokeOpacity=".5" />
                ))}
            </g>
            <g fontSize="8" fontFamily="ui-monospace,monospace" fill={C} fillOpacity=".5">
                <text x={B.x - 62} y={B.y - 48} textAnchor="end">Ø90 · z18</text>
                <text x="24" y="276">REV C · M5 DRIVE</text>
            </g>

            {/* circuit traces + pulses */}
            <g fill="none" strokeLinejoin="round">
                {TRACES.map((d) => (
                    <path key={d} d={d} stroke={E} strokeOpacity=".35" strokeWidth="2" />
                ))}
                {TRACES.map((d, i) => (
                    <path
                        key={`p${d}`}
                        className="cc-te-p"
                        d={d}
                        pathLength={100}
                        stroke={C}
                        strokeWidth="2.5"
                        strokeLinecap="round"
                        strokeDasharray="7 93"
                        strokeDashoffset={(i * 37) % 100}
                        style={{ '--d': `${-i * 0.45}s` } as CSSProperties}
                    />
                ))}
            </g>
            <g fill={D} stroke={E} strokeWidth="1.5">
                {PADS.map(([x, y]) => (
                    <circle key={`${x}${y}`} cx={x} cy={y} r="3.2" />
                ))}
            </g>

            {/* chip */}
            <g stroke={E} strokeOpacity=".6">
                {[0, 1, 2, 3].map((i) => (
                    <path key={i} d={`M${48 + i * 10} 212v6M${48 + i * 10} 246v6`} />
                ))}
            </g>
            <rect x="40" y="218" width="50" height="28" rx="3" fill={D} stroke={E} strokeOpacity=".8" />
            <circle cx="47" cy="225" r="1.6" fill={E} />

            {/* gears */}
            {GEARS.map((g) => (
                <g key={g.cls} className={g.cls} style={{ transformOrigin: `${g.x}px ${g.y}px` }}>
                    <g transform={`rotate(${g.off} ${g.x} ${g.y})`}>
                        <path d={g.d} fillRule="evenodd" fill={E} fillOpacity=".1" stroke={E} strokeWidth="1.5" strokeLinejoin="round" />
                        {g.n > 10 && (
                            <path
                                d={`M${g.x - g.n * 1.3} ${g.y}H${g.x - g.n * 0.85}M${g.x + g.n * 0.85} ${g.y}H${g.x + g.n * 1.3}M${g.x} ${g.y - g.n * 1.3}V${g.y - g.n * 0.85}M${g.x} ${g.y + g.n * 0.85}V${g.y + g.n * 1.3}`}
                                stroke={E}
                                strokeOpacity=".5"
                                strokeWidth="2"
                            />
                        )}
                    </g>
                    <circle cx={g.x} cy={g.y} r="2.5" fill={C} />
                </g>
            ))}

            {/* spanner on a hex nut */}
            <polygon className="cc-te-nut" points={NUT} fill={D} stroke={E} strokeWidth="1.5" style={{ transformOrigin: '96px 98px' }} />
            <g className="cc-te-wr" style={{ transformOrigin: '96px 98px' }} fill="none" strokeLinecap="round">
                <path d="M86 108L42 152" stroke={E} strokeWidth="12" />
                <circle cx="96" cy="98" r="13" stroke={E} strokeWidth="9" />
                <path d="M86 108L42 152" stroke={D} strokeWidth="8.5" />
                <circle cx="96" cy="98" r="13" stroke={D} strokeWidth="5.5" />
            </g>

            {/* solder joint + sparks */}
            <circle className="cc-te-joint" cx="166" cy="216" r="10" fill={`url(#${glow})`} />
            <circle cx="166" cy="216" r="3" fill="#ecfeff" />
            <g stroke="#a5f3fc" strokeWidth="1.5" strokeLinecap="round">
                {SPARKS.map((a, i) => (
                    <line
                        key={a}
                        className="cc-te-sp"
                        x1={166 + 5 * Math.cos(a * deg)}
                        y1={216 + 5 * Math.sin(a * deg)}
                        x2={166 + (12 + (i % 3) * 3) * Math.cos(a * deg)}
                        y2={216 + (12 + (i % 3) * 3) * Math.sin(a * deg)}
                        opacity={i % 2 ? 0.4 : 0.85}
                        style={{ transformOrigin: '166px 216px', '--d': `${i * 0.07}s` } as CSSProperties}
                    />
                ))}
            </g>
        </>
    );
}
