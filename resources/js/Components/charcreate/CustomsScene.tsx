// Customs — the border checkpoint. A container rides the conveyor into the
// X-ray gantry, the beam sweeps and reveals what is inside, the stamp drops
// (CLEARED on one pass, SEIZED on the next), then the barrier lifts — or the
// box is pulled. The un-animated markup is the reduced-motion hero frame.
// All motion lives in scenes.css (transform / opacity / stroke-dashoffset only).

const A = '#fbbf24'; // amber accent
const C = '#22d3ee'; // cyan highlight
const R = '#f87171'; // seized
const D = '#0b1220'; // steel

const ribs = Array.from({ length: 11 }, (_, i) => 156 + i * 9);
const rollers = Array.from({ length: 12 }, (_, i) => 34 + i * 30);
const scan = Array.from({ length: 26 }, (_, i) => i * 12);

export default function CustomsScene({ uid }: { uid: string }) {
    const beam = `${uid}b`;
    const clip = `${uid}c`;
    const sweep = `${uid}s`;
    return (
        <>
            <defs>
                <linearGradient id={beam} x1="0" x2="1">
                    <stop offset="0" stopColor={C} stopOpacity="0" />
                    <stop offset=".5" stopColor={C} stopOpacity=".38" />
                    <stop offset="1" stopColor={C} stopOpacity="0" />
                </linearGradient>
                <linearGradient id={sweep} x1="0" y1="1" x2="1" y2="0">
                    <stop offset="0" stopColor={A} stopOpacity="0" />
                    <stop offset="1" stopColor={A} stopOpacity=".45" />
                </linearGradient>
                <clipPath id={clip}>
                    <rect className="cc-cu-rev" x="146" y="150" width="108" height="80" style={{ transformOrigin: '146px 0' }} />
                </clipPath>
            </defs>

            {/* scan-line texture */}
            <g className="cc-cu-scan" stroke={A} strokeOpacity=".07">
                {scan.map((y) => (
                    <line key={y} x1="0" x2="400" y1={y - 12} y2={y - 12} />
                ))}
            </g>

            {/* radar */}
            <g fill="none" stroke={A} strokeOpacity=".22">
                <circle cx="56" cy="58" r="16" />
                <circle cx="56" cy="58" r="32" />
                <path d="M56 22v72M20 58h72" strokeOpacity=".12" />
            </g>
            <path className="cc-cu-radar" d="M56 58V26a32 32 0 0 1 27.7 16Z" fill={`url(#${sweep})`} style={{ transformOrigin: '56px 58px' }} />
            <circle className="cc-cu-blip" cx="74" cy="44" r="2.2" fill={A} />

            {/* floor + conveyor */}
            <path d="M10 250h380" stroke="#334155" />
            <rect x="20" y="232" width="360" height="9" rx="2" fill={D} stroke={A} strokeOpacity=".35" />
            <line className="cc-cu-belt" x1="22" x2="378" y1="236.5" y2="236.5" stroke={A} strokeOpacity=".55" strokeWidth="2" strokeDasharray="6 10" />
            <g fill={D} stroke={A} strokeOpacity=".4">
                {rollers.map((x) => (
                    <circle key={x} cx={x} cy="245" r="3" />
                ))}
            </g>

            {/* stamp piston (behind the gantry beam) */}
            <g className="cc-cu-stamp">
                <line x1="200" y1="40" x2="200" y2="108" stroke="#94a3b8" strokeWidth="3" />
                <rect x="191" y="98" width="18" height="11" rx="2" fill={D} stroke={A} />
                <rect x="179" y="108" width="42" height="8" rx="1.5" fill={A} fillOpacity=".9" />
            </g>

            {/* the container */}
            <g className="cc-cu-box">
                <rect x="146" y="150" width="108" height="80" rx="2" fill="#111827" stroke={A} strokeWidth="1.5" />
                <g stroke={A} strokeOpacity=".22">
                    {ribs.map((x) => (
                        <line key={x} x1={x} x2={x} y1="156" y2="224" />
                    ))}
                </g>
                <text x="151" y="162" fontSize="6" fontFamily="ui-monospace,monospace" fill={A} fillOpacity=".6" letterSpacing="1">
                    TDR 0417 · 40FT
                </text>
                {/* what the X-ray finds */}
                <g clipPath={`url(#${clip})`} fill={C} fillOpacity=".07" stroke={C} strokeWidth="1.2" strokeOpacity=".9">
                    <rect x="154" y="184" width="28" height="40" />
                    <path d="M154 184l28 40M182 184l-28 40" strokeOpacity=".45" />
                    <rect x="186" y="198" width="22" height="26" />
                    <rect x="212" y="190" width="34" height="34" rx="2" />
                    <rect x="217" y="196" width="11" height="7" />
                    <rect x="230" y="196" width="11" height="7" />
                    <rect x="217" y="206" width="11" height="7" />
                    <rect x="230" y="206" width="11" height="7" />
                    <rect className="cc-cu-flag" x="209" y="187" width="40" height="40" rx="3" fill="none" stroke={R} strokeWidth="1.5" strokeDasharray="4 3" opacity="0" />
                </g>
                {/* imprints */}
                <g transform="rotate(-11 200 172)">
                    <g className="cc-cu-ok" style={{ transformOrigin: '200px 172px' }}>
                        <rect x="152" y="159" width="96" height="27" rx="3" fill="#020617" fillOpacity=".72" stroke={C} strokeWidth="2" />
                        <text x="200" y="178" textAnchor="middle" fontSize="15" fontWeight="900" letterSpacing="3" fill={C}>
                            CLEARED
                        </text>
                    </g>
                    <g className="cc-cu-no" opacity="0" style={{ transformOrigin: '200px 172px' }}>
                        <rect x="158" y="159" width="84" height="27" rx="3" fill="#020617" fillOpacity=".72" stroke={R} strokeWidth="2" />
                        <text x="200" y="178" textAnchor="middle" fontSize="15" fontWeight="900" letterSpacing="3" fill={R}>
                            SEIZED
                        </text>
                    </g>
                </g>
            </g>

            {/* X-ray beam */}
            <g className="cc-cu-beam" opacity=".85">
                <rect x="186" y="78" width="28" height="152" fill={`url(#${beam})`} />
                <line x1="200" x2="200" y1="78" y2="230" stroke={C} strokeWidth="1.5" />
            </g>

            {/* gantry */}
            <g fill={D} stroke={A} strokeOpacity=".7">
                <rect x="190" y="30" width="20" height="34" rx="2" />
                <rect x="132" y="70" width="8" height="162" />
                <rect x="260" y="70" width="8" height="162" />
                <rect x="126" y="62" width="148" height="12" rx="1.5" />
            </g>
            <line x1="130" x2="270" y1="68" y2="68" stroke={A} strokeWidth="5" strokeDasharray="6 6" strokeOpacity=".5" />
            <g fill={C}>
                <rect x="139" y="96" width="3" height="18" />
                <rect x="258" y="96" width="3" height="18" />
            </g>
            <circle className="cc-cu-warn" cx="200" cy="24" r="4" fill={A} />

            {/* barrier */}
            <rect x="346" y="152" width="8" height="80" fill={D} stroke={A} strokeOpacity=".7" />
            <g className="cc-cu-arm" style={{ transformOrigin: '350px 160px' }}>
                <rect x="280" y="156" width="72" height="8" rx="2" fill={D} stroke={A} />
                <line x1="283" x2="348" y1="160" y2="160" stroke={A} strokeWidth="5" strokeDasharray="6 6" />
            </g>
            <circle cx="350" cy="160" r="4.5" fill={D} stroke={A} />
            <circle className="cc-cu-go" cx="350" cy="144" r="3.5" fill={C} />
            <circle className="cc-cu-stop" cx="350" cy="144" r="3.5" fill={R} opacity="0" />
        </>
    );
}
