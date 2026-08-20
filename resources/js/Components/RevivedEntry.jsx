import { useState, useEffect } from 'react';
import { motion } from 'framer-motion';
import { Heartbeat, Trash, BookmarkSimple, PaperPlaneRight, Clock } from '@phosphor-icons/react';
import { formatUTC } from '@/Layouts/GameLayoutComponents';

// Module-level set — persists across re-mounts within the same page session.
// Once an entry's animation has completed, any re-mount (e.g. after Save/Delete
// triggers an Inertia partial reload) skips straight to the settled alive state.
const animatedIds = new Set();

// ── ECG line SVG ─────────────────────────────────────────────────────────────
// Simplified ECG: flatline → flatline → P wave → QRS spike → T wave → flatline
// viewBox width 300, designed to be drawn via strokeDashoffset animation
const ECG_PATH = "M0,20 L60,20 L65,20 L68,14 L70,20 L72,20 L75,5 L78,35 L81,12 L84,20 L90,20 L95,26 L100,20 L160,20 L165,20 L168,14 L170,20 L172,20 L175,5 L178,35 L181,12 L184,20 L190,20 L195,26 L200,20 L300,20";
const ECG_LENGTH = 520; // approximate path length

const EcgLine = ({ phase }) => (
    <svg
        viewBox="0 0 300 40"
        className="w-full h-8"
        style={{ overflow: 'visible' }}
        preserveAspectRatio="none"
    >
        {/* dim flatline always visible as baseline */}
        <line x1="0" y1="20" x2="300" y2="20" stroke="rgba(16,185,129,0.12)" strokeWidth="1" />

        {/* animated ECG trace */}
        <motion.path
            d={ECG_PATH}
            fill="none"
            stroke={phase === 'flatline' ? 'rgba(16,185,129,0.25)' : 'rgba(16,185,129,0.85)'}
            strokeWidth={phase === 'flatline' ? 1 : 1.5}
            strokeLinecap="round"
            strokeLinejoin="round"
            initial={{ strokeDasharray: ECG_LENGTH, strokeDashoffset: ECG_LENGTH }}
            animate={
                phase === 'flatline'
                    ? { strokeDashoffset: ECG_LENGTH * 0.6 }
                    : { strokeDashoffset: 0 }
            }
            transition={
                phase === 'flatline'
                    ? { duration: 1.2, ease: 'linear' }
                    : { duration: 1.8, ease: 'easeInOut' }
            }
            style={{
                filter: phase !== 'flatline' ? 'drop-shadow(0 0 3px rgba(16,185,129,0.8))' : 'none',
            }}
        />

        {/* travelling pulse dot — only during alive phase */}
        {phase === 'alive' && (
            <motion.circle
                r="3"
                fill="rgba(52,211,153,0.9)"
                style={{ filter: 'drop-shadow(0 0 4px rgba(52,211,153,1))' }}
                initial={{ offsetDistance: '0%' }}
                animate={{ offsetDistance: '100%' }}
                transition={{ duration: 2.4, repeat: Infinity, ease: 'linear', repeatDelay: 0.4 }}
            />
        )}
    </svg>
);

// ── Heartbeat icon — lub-dub pattern ─────────────────────────────────────────
const LubDubHeart = ({ phase }) => (
    <motion.div
        animate={
            phase === 'alive'
                ? {
                    scale: [1, 1.35, 1, 1.18, 1, 1, 1],
                    opacity: [1, 1, 1, 1, 1, 1, 1],
                }
                : phase === 'pulse'
                ? { scale: [1, 1.5, 1] }
                : { scale: 1 }
        }
        transition={
            phase === 'alive'
                ? {
                    duration: 0.8,
                    repeat: Infinity,
                    repeatDelay: 0.9,
                    times: [0, 0.1, 0.22, 0.34, 0.46, 0.73, 1],
                    ease: 'easeOut',
                }
                : phase === 'pulse'
                ? { duration: 0.4, ease: 'easeOut' }
                : {}
        }
        className="w-14 h-14 rounded-xl flex items-center justify-center border-2 shrink-0"
        style={{
            borderColor: phase === 'flatline' ? 'rgba(16,185,129,0.2)' : 'rgba(52,211,153,0.7)',
            background:
                phase === 'flatline'
                    ? 'rgba(16,185,129,0.05)'
                    : 'linear-gradient(135deg, rgba(16,185,129,0.3), rgba(6,95,70,0.4))',
            boxShadow:
                phase === 'alive'
                    ? '0 0 18px rgba(16,185,129,0.5)'
                    : phase === 'pulse'
                    ? '0 0 40px rgba(52,211,153,0.9)'
                    : 'none',
        }}
    >
        <Heartbeat
            className="h-8 w-8"
            weight="fill"
            style={{
                color:
                    phase === 'flatline'
                        ? 'rgba(16,185,129,0.3)'
                        : 'rgba(52,211,153,1)',
                filter:
                    phase !== 'flatline'
                        ? 'drop-shadow(0 0 6px rgba(52,211,153,0.8))'
                        : 'none',
            }}
        />
    </motion.div>
);

// ── Scan line — passes top to bottom once on revival ─────────────────────────
const ScanLine = ({ show }) =>
    show ? (
        <motion.div
            className="absolute inset-x-0 h-px pointer-events-none z-10"
            style={{
                background:
                    'linear-gradient(90deg, transparent 0%, rgba(52,211,153,0.6) 30%, rgba(52,211,153,0.9) 50%, rgba(52,211,153,0.6) 70%, transparent 100%)',
                boxShadow: '0 0 8px rgba(52,211,153,0.5)',
            }}
            initial={{ top: 0, opacity: 0 }}
            animate={{ top: '100%', opacity: [0, 1, 1, 0] }}
            transition={{ duration: 1.0, ease: 'linear' }}
        />
    ) : null;

// ── Main component ────────────────────────────────────────────────────────────
const RevivedEntry = ({ entry, onDelete, onSave, processingId }) => {
    const alreadySeen = animatedIds.has(entry.id);
    const [phase, setPhase] = useState(alreadySeen ? 'alive' : 'flatline');
    const [showScan, setShowScan] = useState(false);

    useEffect(() => {
        if (alreadySeen) return;

        const timers = [
            setTimeout(() => setShowScan(true),    1200),
            setTimeout(() => setShowScan(false),   2300),
            setTimeout(() => setPhase('pulse'),    1600),
            setTimeout(() => {
                setPhase('alive');
                animatedIds.add(entry.id);
            }, 2400),
        ];
        return () => timers.forEach(clearTimeout);
    }, []);

    const cardGlow =
        phase === 'flatline'
            ? '0 0 0 rgba(16,185,129,0)'
            : phase === 'pulse'
            ? '0 0 60px rgba(52,211,153,0.8), 0 0 120px rgba(16,185,129,0.4)'
            : '0 0 20px rgba(16,185,129,0.35), 0 0 40px rgba(16,185,129,0.15)';

    const cardBorder =
        phase === 'flatline'
            ? 'rgba(16,185,129,0.2)'
            : phase === 'pulse'
            ? 'rgba(52,211,153,0.9)'
            : 'rgba(52,211,153,0.5)';

    const cardBg =
        phase === 'flatline'
            ? 'linear-gradient(135deg, rgba(2,44,34,0.7) 0%, rgba(1,26,20,0.8) 100%)'
            : 'linear-gradient(135deg, rgba(6,78,59,0.6) 0%, rgba(4,120,87,0.25) 50%, rgba(2,44,34,0.7) 100%)';

    return (
        <div className="relative my-3" style={{ minHeight: '130px' }}>
            <motion.div
                initial={{ y: 40, opacity: 0 }}
                animate={{ y: 0, opacity: 1 }}
                transition={{ type: 'spring', stiffness: 70, damping: 18 }}
                style={{ boxShadow: cardGlow, borderColor: cardBorder }}
                className="relative z-10 rounded-xl border-2 overflow-hidden transition-colors duration-500"
            >
                <div style={{ background: cardBg }} className="transition-all duration-700">

                    {/* ── scan line overlay ── */}
                    <div className="relative">
                        <ScanLine show={showScan} />
                    </div>

                    {/* ── ECG strip header ── */}
                    <div
                        className="px-4 pt-3 pb-1 border-b"
                        style={{ borderColor: 'rgba(16,185,129,0.15)' }}
                    >
                        <div className="flex items-center gap-2 mb-1">
                            {/* blinking monitor dot */}
                            <motion.span
                                className="w-2 h-2 rounded-full"
                                style={{ backgroundColor: phase === 'flatline' ? 'rgba(239,68,68,0.7)' : 'rgba(52,211,153,0.9)' }}
                                animate={
                                    phase === 'alive'
                                        ? { opacity: [1, 0.2, 1], scale: [1, 0.8, 1] }
                                        : phase === 'flatline'
                                        ? { opacity: [1, 0.3, 1] }
                                        : { opacity: 1, scale: 1.3 }
                                }
                                transition={
                                    phase === 'alive'
                                        ? { duration: 0.8, repeat: Infinity, repeatDelay: 0.9 }
                                        : phase === 'flatline'
                                        ? { duration: 1.2, repeat: Infinity }
                                        : {}
                                }
                            />
                            <span
                                className="text-[9px] font-black uppercase tracking-[0.25em]"
                                style={{ color: phase === 'flatline' ? 'rgba(239,68,68,0.6)' : 'rgba(52,211,153,0.8)' }}
                            >
                                {phase === 'flatline' ? 'FLATLINE' : 'VITAL SIGNS RESTORED'}
                            </span>
                            <span className="ml-auto text-[10px] flex items-center gap-1" style={{ color: 'rgba(100,116,139,0.8)' }}>
                                <Clock className="h-3 w-3" />
                                {formatUTC(entry.created_at)}
                            </span>
                        </div>
                        <EcgLine phase={phase} />
                    </div>

                    {/* ── main content ── */}
                    <div className="p-4">
                        <div className="flex items-start gap-4">
                            <LubDubHeart phase={phase} />

                            <div className="flex-1 min-w-0">
                                <div className="flex items-center gap-3 mb-1">
                                    <span
                                        className="font-bold text-lg tracking-wide"
                                        style={{
                                            color: phase === 'flatline' ? 'rgba(52,211,153,0.5)' : 'rgba(167,243,208,1)',
                                            textShadow: phase !== 'flatline' ? '0 0 12px rgba(52,211,153,0.4)' : 'none',
                                        }}
                                    >
                                        {entry.title}
                                    </span>
                                    {!entry.is_read && (
                                        <motion.span
                                            className="w-2.5 h-2.5 rounded-full bg-emerald-400"
                                            animate={{ scale: [1, 1.4, 1], opacity: [1, 0.5, 1] }}
                                            transition={{ duration: 1.2, repeat: Infinity }}
                                        />
                                    )}
                                </div>
                                <p
                                    className="text-sm leading-relaxed mb-4"
                                    style={{ color: 'rgba(167,243,208,0.8)' }}
                                >
                                    {entry.description}
                                </p>

                                <div className="flex flex-wrap gap-2">
                                    <button
                                        onClick={() => onDelete(entry.id)}
                                        disabled={processingId === entry.id}
                                        className="px-4 py-2 rounded-lg text-xs font-medium bg-red-500/20 hover:bg-red-500/30 text-red-400 border border-red-500/30 transition disabled:opacity-50 flex items-center gap-2"
                                    >
                                        <Trash className="h-3.5 w-3.5" /> Delete
                                    </button>
                                    {!entry.is_saved && (
                                        <button
                                            onClick={() => onSave(entry.id)}
                                            disabled={processingId === entry.id}
                                            className="px-4 py-2 rounded-lg text-xs font-medium bg-emerald-600/40 hover:bg-emerald-500/50 text-emerald-200 border border-emerald-500/40 transition disabled:opacity-50 flex items-center gap-2"
                                        >
                                            <BookmarkSimple className="h-3.5 w-3.5" /> Save
                                        </button>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </motion.div>
        </div>
    );
};

export default RevivedEntry;
