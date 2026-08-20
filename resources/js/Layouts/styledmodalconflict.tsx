import { X, Crosshair, Skull, Shield, Sword, Heart, Target, Heartbeat, Lightning } from '@phosphor-icons/react';
import { motion, AnimatePresence } from 'framer-motion';


const outcomes = {
    kill: {
        title: 'MURDERED',
        icon: Skull,
        accentClass: 'text-emerald-400',
        glowClass: 'shadow-[0_0_80px_rgba(52,211,153,0.15)]',
        bgGlow: 'radial-gradient(ellipse at center top, rgba(52,211,153,0.08) 0%, transparent 60%)',
        ringClass: 'border-emerald-500/30',
        buttonClass: 'bg-emerald-600 border-emerald-500/50 text-white hover:bg-emerald-500 hover:shadow-[0_0_30px_rgba(52,211,153,0.4)]',
        particleColor: 'bg-emerald-400',
    },
    damage: {
        title: 'DAMAGED',
        icon: Sword,
        accentClass: 'text-cyan-400',
        glowClass: 'shadow-[0_0_80px_rgba(34,211,238,0.12)]',
        bgGlow: 'radial-gradient(ellipse at center top, rgba(34,211,238,0.06) 0%, transparent 60%)',
        ringClass: 'border-cyan-500/30',
        buttonClass: 'bg-cyan-600 border-cyan-500/50 text-white hover:bg-cyan-500 hover:shadow-[0_0_30px_rgba(34,211,238,0.4)]',
        particleColor: 'bg-cyan-400',
    },
    hospitalized: {
        title: 'HOSPITALIZED',
        icon: Heart,
        accentClass: 'text-amber-400',
        glowClass: 'shadow-[0_0_80px_rgba(251,191,36,0.12)]',
        bgGlow: 'radial-gradient(ellipse at center top, rgba(251,191,36,0.06) 0%, transparent 60%)',
        ringClass: 'border-amber-500/30',
        buttonClass: 'bg-amber-600 border-amber-500/50 text-white hover:bg-amber-500 hover:shadow-[0_0_30px_rgba(251,191,36,0.4)]',
        particleColor: 'bg-amber-400',
    },
    miss: {
        title: 'MISSED',
        icon: Shield,
        accentClass: 'text-red-400',
        glowClass: 'shadow-[0_0_80px_rgba(248,113,113,0.12)]',
        bgGlow: 'radial-gradient(ellipse at center top, rgba(248,113,113,0.06) 0%, transparent 60%)',
        ringClass: 'border-red-500/30',
        buttonClass: 'bg-red-600 border-red-500/50 text-white hover:bg-red-500 hover:shadow-[0_0_30px_rgba(248,113,113,0.4)]',
        particleColor: 'bg-red-400',
    },
};

type Outcome = keyof typeof outcomes;

function Particles({ color }: { color: string }) {
    return (
        <div className="absolute inset-0 overflow-hidden pointer-events-none">
            {Array.from({ length: 20 }).map((_, i) => {
                const size = 1 + Math.random() * 3;
                const x = Math.random() * 100;
                const delay = Math.random() * 2;
                const duration = 3 + Math.random() * 4;
                return (
                    <motion.div
                        key={i}
                        className={`absolute rounded-full ${color} opacity-0`}
                        style={{ width: size, height: size, left: `${x}%`, bottom: -10 }}
                        animate={{
                            y: [0, -400 - Math.random() * 200],
                            opacity: [0, 0.6, 0],
                        }}
                        transition={{
                            duration,
                            delay,
                            repeat: Infinity,
                            ease: 'easeOut',
                        }}
                    />
                );
            })}
        </div>
    );
}


function ScanLine() {
    return (
        <motion.div
            className="absolute inset-x-0 h-px bg-gradient-to-r from-transparent via-white/20 to-transparent pointer-events-none"
            initial={{ top: 0 }}
            animate={{ top: '100%' }}
            transition={{ duration: 2.5, repeat: Infinity, ease: 'linear' }}
        />
    );
}


export default function StyledModalConflict({
    isOpen,
    onClose,
    outcome = 'damage' as Outcome,
    message = '',
    image,
    onPrimary,
    onSecondary,
    primaryLabel,
    secondaryLabel,
}: {
    isOpen: boolean;
    onClose: () => void;
    outcome?: Outcome;
    message?: string;
    image?: string;
    onPrimary?: () => void;
    onSecondary?: () => void;
    primaryLabel?: string;
    secondaryLabel?: string;
}) {
    const cfg = outcomes[outcome] || outcomes.damage;
    const Icon = cfg.icon;

    const defaultPrimary = outcome === 'kill' ? 'Done' : 'Retry';
    const defaultSecondary = 'Home';

    return (
        <AnimatePresence>
            {isOpen && (
                <motion.div
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    exit={{ opacity: 0 }}
                    className="fixed inset-0 z-[100] flex items-center justify-center p-4"
                >

                    <div className="absolute inset-0 bg-black/30 backdrop-blur-[2px]" onClick={onClose} />


                    <motion.div
                        initial={{ opacity: 0, scale: 0.9, y: 30 }}
                        animate={{ opacity: 1, scale: 1, y: 0 }}
                        exit={{ opacity: 0, scale: 0.9, y: 30 }}
                        transition={{ type: 'spring', damping: 28, stiffness: 320 }}
                        className={`relative w-full max-w-md bg-slate-950 border ${cfg.ringClass} rounded-2xl overflow-hidden ${cfg.glowClass}`}
                    >
                        <Particles color={cfg.particleColor} />
                        <ScanLine />


                        <div className="relative h-52 overflow-hidden">

                            <div className="absolute inset-0" style={{ background: cfg.bgGlow }} />


                            {image && (
                                <motion.img
                                    src={image}
                                    alt=""
                                    className="absolute inset-0 w-full h-full object-contain p-4"
                                    initial={{ scale: 1.1, opacity: 0 }}
                                    animate={{ scale: 1, opacity: 0.8 }}
                                    transition={{ duration: 1.2, ease: 'easeOut' }}
                                />
                            )}


                            <div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/60 to-transparent" />
                            <div className="absolute inset-0 bg-gradient-to-b from-slate-950/40 to-transparent h-20" />


                            <button
                                onClick={onClose}
                                className="absolute top-3 right-3 z-20 p-1.5 bg-black/50 hover:bg-black/80 text-white/60 hover:text-white rounded-full transition-all backdrop-blur-md border border-white/5"
                            >
                                <X size={14} weight="bold" />
                            </button>


                            <div className="absolute bottom-0 inset-x-0 px-6 pb-5 flex items-end gap-4">

                                <motion.div
                                    initial={{ scale: 0, rotate: -30 }}
                                    animate={{ scale: 1, rotate: 0 }}
                                    transition={{ type: 'spring', damping: 14, stiffness: 200, delay: 0.2 }}
                                    className={`relative flex-shrink-0 w-14 h-14 rounded-xl border ${cfg.ringClass} bg-slate-900/80 backdrop-blur-md flex items-center justify-center`}
                                >
                                    <Icon className={cfg.accentClass} size={24} weight="bold" />

                                    <motion.div
                                        className={`absolute inset-0 rounded-xl border ${cfg.ringClass}`}
                                        animate={{ scale: [1, 1.3], opacity: [0.5, 0] }}
                                        transition={{ duration: 1.5, repeat: Infinity, ease: 'easeOut' }}
                                    />
                                </motion.div>

                                <motion.div
                                    initial={{ opacity: 0, x: -15 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    transition={{ delay: 0.3, duration: 0.5 }}
                                >
                                    <div className="text-[9px] text-slate-500 font-bold uppercase tracking-[0.25em] mb-1">
                                        Attack Outcome
                                    </div>
                                    <h3 className={`text-lg font-black uppercase tracking-tight ${cfg.accentClass}`}>
                                        {cfg.title}
                                    </h3>
                                </motion.div>
                            </div>
                        </div>


                        <div className="relative px-6 pt-4 pb-6 space-y-5">

                            <motion.div
                                initial={{ opacity: 0, y: 12 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ delay: 0.4, duration: 0.5 }}
                                className="bg-slate-900/60 border border-slate-800/60 rounded-xl p-4"
                            >
                                <div className="text-[9px] text-slate-600 font-bold uppercase tracking-[0.2em] mb-2">

                                </div>
                                <p className="text-sm text-slate-300 leading-relaxed">
                                    {message || 'No additional details available.'}
                                </p>
                            </motion.div>


                            <motion.div
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                transition={{ delay: 0.55, duration: 0.5 }}
                                className="flex items-center gap-3"
                            >
                                <div className="flex-1 h-px bg-slate-800" />
                                <div className="flex items-center gap-2">
                                    <Crosshair size={10} className="text-slate-600" weight="bold" />
                                    <span className="text-[8px] text-slate-600 font-bold uppercase tracking-[0.3em]">
                                        Attack Complete
                                    </span>
                                    <Target size={10} className="text-slate-600" weight="bold" />
                                </div>
                                <div className="flex-1 h-px bg-slate-800" />
                            </motion.div>


                            <motion.div
                                initial={{ opacity: 0, y: 10 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ delay: 0.6, duration: 0.4 }}
                                className="grid grid-cols-2 gap-3"
                            >
                                <button
                                    onClick={onPrimary || onClose}
                                    className={`h-12 flex items-center justify-center gap-2 rounded-xl text-[10px] font-black uppercase tracking-[0.2em] transition-all border ${cfg.buttonClass}`}
                                >
                                    {outcome === 'kill' ? <Skull size={13} weight="bold" /> : <Heartbeat size={13} weight="bold" />}
                                    {primaryLabel || defaultPrimary}
                                </button>
                                <button
                                    onClick={onSecondary || onClose}
                                    className="h-12 flex items-center justify-center gap-2 rounded-xl text-[10px] font-black uppercase tracking-[0.2em] transition-all bg-slate-900/60 border border-slate-800/60 text-slate-500 hover:bg-slate-800 hover:text-slate-300"
                                >
                                    <Shield size={13} weight="bold" />
                                    {secondaryLabel || defaultSecondary}
                                </button>
                            </motion.div>
                        </div>
                    </motion.div>
                </motion.div>
            )}
        </AnimatePresence>
    );
}
