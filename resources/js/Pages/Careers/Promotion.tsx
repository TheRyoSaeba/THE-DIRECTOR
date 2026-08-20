import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { motion, AnimatePresence } from 'framer-motion';
import { ArrowRight, CheckCircle, Sparkle, Medal } from '@phosphor-icons/react';
import GameLayout from '@/Layouts/GameLayout';

interface Props {
    currentRank: string;
    nextRank: string;
    career: string;
    promotion: {
        scenario: string;
        option_1: string;
        option_2: string;
    } | null;
}

function OptionCard({ index, text, selected, onSelect, disabled }: {
    index: 1 | 2;
    text: string;
    selected: boolean;
    onSelect: () => void;
    disabled: boolean;
}) {
    return (
        <motion.button
            onClick={onSelect}
            disabled={disabled}
            whileTap={!disabled ? { scale: 0.985 } : {}}
            className={`w-full text-left relative rounded-xl border transition-all duration-200 overflow-hidden group ${
                selected
                    ? 'border-cyan-500/50 bg-cyan-500/[0.06] shadow-[0_0_30px_rgba(34,211,238,0.08)]'
                    : 'border-white/[0.06] bg-slate-900/40 hover:border-white/[0.12] hover:bg-slate-900/60'
            } ${disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}`}
        >
            <div className={`absolute left-0 top-0 bottom-0 w-0.5 transition-all duration-200 ${selected ? 'bg-cyan-400' : 'bg-transparent'}`} />
            <div className="px-5 py-4 flex items-start gap-4">
                <div className={`shrink-0 w-8 h-8 rounded-lg flex items-center justify-center text-xs font-black border transition-colors ${
                    selected
                        ? 'bg-cyan-500/20 border-cyan-500/40 text-cyan-400'
                        : 'bg-slate-800/60 border-white/[0.07] text-slate-500 group-hover:text-slate-300'
                }`}>
                    {index}
                </div>
                <p className={`flex-1 text-sm leading-relaxed transition-colors ${
                    selected ? 'text-white' : 'text-slate-400 group-hover:text-slate-300'
                }`}>
                    {text}
                </p>
                <div className={`shrink-0 mt-0.5 transition-all duration-200 ${selected ? 'opacity-100 scale-100' : 'opacity-0 scale-75'}`}>
                    <CheckCircle size={18} weight="fill" className="text-cyan-400" />
                </div>
            </div>
        </motion.button>
    );
}

export default function Promotion({ currentRank, nextRank, career, promotion }: Props) {
    const [selected, setSelected] = useState<1 | 2 | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const isFallback = promotion === null;

    const handleSubmit = () => {
        if (submitting || (!isFallback && selected === null)) return;
        setSubmitting(true);
        router.post('/career/promote', { option: selected ?? 0 }, {
            onFinish: () => setSubmitting(false),
        });
    };

    return (
        <>
            <Head title={`Promotion — ${nextRank}`} />
            <div className="w-full max-w-2xl mx-auto px-4 py-10 sm:py-14 flex flex-col gap-8">

                {/* Header */}
                <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} className="text-center">
                    <div className="flex justify-center mb-6">
                        <div className="relative">
                            <div className="absolute inset-0 rounded-full bg-cyan-500/10 blur-2xl scale-150" />
                            <motion.div
                                animate={{ rotateY: 360 }}
                                transition={{ duration: 6, repeat: Infinity, ease: 'linear' }}
                                style={{ transformStyle: 'preserve-3d', perspective: 1000 }}
                                className="relative w-20 h-20"
                            >
                                <img src="https://images.thedirector.app/Careers/promo.png" className="w-full h-full object-contain" alt="" />
                            </motion.div>
                        </div>
                    </div>
                    <p className="text-[10px] text-cyan-400 font-black uppercase tracking-[0.3em] mb-3">{career} · Promotion</p>
                    <div className="flex items-center justify-center gap-3 flex-wrap mb-4">
                        <span className="text-slate-500 text-sm font-bold uppercase tracking-wider">{currentRank}</span>
                        <ArrowRight size={14} className="text-slate-700 shrink-0" />
                        <span className="text-white text-lg font-black uppercase tracking-wider">{nextRank}</span>
                    </div>
                    <div className="w-16 h-px bg-slate-800 mx-auto" />
                </motion.div>

                {/* Scenario or fallback */}
                <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.15 }}
                    className="rounded-xl border border-white/[0.06] bg-slate-900/40 px-5 py-5"
                >
                    <p className="text-sm text-slate-300 leading-[1.8]">
                        {isFallback
                            ? 'Your record speaks for itself. You have earned this promotion. Accept your new rank and the responsibilities that come with it.'
                            : promotion!.scenario
                        }
                    </p>
                </motion.div>

                {/* Options */}
                {!isFallback && (
                    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.25 }} className="space-y-3">
                        <OptionCard index={1} text={promotion!.option_1} selected={selected === 1} onSelect={() => setSelected(1)} disabled={submitting} />
                        <OptionCard index={2} text={promotion!.option_2} selected={selected === 2} onSelect={() => setSelected(2)} disabled={submitting} />
                    </motion.div>
                )}

                {/* Submit */}
                <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.35 }} className="space-y-3">
                    <button
                        onClick={handleSubmit}
                        disabled={submitting || (!isFallback && selected === null)}
                        className={`w-full h-12 rounded-xl text-[11px] font-black uppercase tracking-[0.25em] flex items-center justify-center gap-2 transition-all duration-200 ${
                            submitting || (!isFallback && selected === null)
                                ? 'bg-slate-800/60 text-slate-600 cursor-not-allowed border border-white/[0.04]'
                                : 'bg-cyan-600 hover:bg-cyan-500 text-white shadow-[0_0_24px_rgba(34,211,238,0.15)]'
                        }`}
                    >
                        {submitting
                            ? <><div className="w-3.5 h-3.5 border border-slate-500/40 border-t-slate-400 rounded-full animate-spin" /> Processing…</>
                            : <><Sparkle size={13} weight="fill" />{isFallback ? 'Accept Promotion' : 'Confirm Decision'}</>
                        }
                    </button>
                    <AnimatePresence>
                        {!isFallback && selected === null && !submitting && (
                            <motion.p initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                                className="text-[10px] text-slate-700 text-center uppercase tracking-wider font-bold"
                            >
                               
                            </motion.p>
                        )}
                    </AnimatePresence>
                    <p className="text-[9px] text-slate-700 text-center uppercase tracking-wider">
                        You can leave and return to this page from your dashboard
                    </p>
                </motion.div>

            </div>
        </>
    );
}

Promotion.layout = (page: React.ReactNode) => <GameLayout children={page} />;
