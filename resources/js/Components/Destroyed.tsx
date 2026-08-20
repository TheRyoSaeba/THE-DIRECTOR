import { useEffect, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { X, WarningOctagon } from '@phosphor-icons/react';

interface Props {
    isOpen: boolean;
    onExit: () => void;
    onShakeStart?: () => void;
    onShakeEnd?: () => void;
}

const HEADER_IMAGE = 'https://images.thedirector.app/properties/bombed.jpg';
const SHAKE_DURATION_MS = 1000;

export { SHAKE_DURATION_MS };

export default function Destroyed({ isOpen, onExit, onShakeStart, onShakeEnd }: Props) {
    const [modalVisible, setModalVisible] = useState(false);

    useEffect(() => {
        if (!isOpen) {
            setModalVisible(false);
            return;
        }

        // Notify parent to start shaking the life section
        onShakeStart?.();

        const t = setTimeout(() => {
            onShakeEnd?.();
            setModalVisible(true);
        }, SHAKE_DURATION_MS);

        return () => {
            clearTimeout(t);
            onShakeEnd?.();
        };
    }, [isOpen]);

    return (
        <AnimatePresence>
            {modalVisible && (
                <div
                    className="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/55 backdrop-blur-[3px]"
                    onClick={onExit}
                >
                    <motion.div
                        initial={{ opacity: 0, scale: 0.92, y: 24 }}
                        animate={{ opacity: 1, scale: 1, y: 0 }}
                        exit={{ opacity: 0, scale: 0.92, y: 24 }}
                        transition={{ duration: 0.26, ease: [0.22, 1, 0.36, 1] }}
                        className="bg-slate-900 border border-red-900/50 w-full max-w-md rounded-2xl overflow-hidden shadow-2xl"
                        role="dialog"
                        aria-modal="true"
                        onClick={e => e.stopPropagation()}
                    >
                        {/* Full-bleed image */}
                        <div className="relative h-52 overflow-hidden">
                            <img
                                src={HEADER_IMAGE}
                                alt="Property destroyed"
                                className="w-full h-full object-cover object-center"
                                loading="eager"
                            />
                            <div className="absolute inset-0 bg-gradient-to-t from-slate-900 via-slate-900/30 to-transparent" />

                            <button
                                type="button"
                                onClick={onExit}
                                className="absolute top-3 right-3 p-1.5 bg-black/60 hover:bg-black/80 text-white rounded-full transition-colors"
                                aria-label="Close"
                            >
                                <X size={16} />
                            </button>

                            <div className="absolute bottom-4 left-5 right-12 flex items-center gap-2">
                                <WarningOctagon size={18} weight="fill" className="text-red-400 shrink-0" />
                                <h3 className="text-lg font-black text-white uppercase tracking-tight leading-tight">
                                    YOUR PROPERTY HAS BEEN DESTROYED
                                </h3>
                            </div>
                        </div>
                        {/* TODO should not prevent a user from being able to access inventory */}
                        <div className="p-5 space-y-4">
                            <div className="space-y-3">
                                <p className="text-sm text-slate-300 leading-relaxed">
                                    An explosion has reduced your home to rubble. A technician can attempt to rebuild
                                    the structure — though reconstruction is not guaranteed. Alternatively, you can sell the land for nothing and purchase a new property.
                                </p>
                                <div className="border-t border-slate-800 pt-3 space-y-2">
                                    <p className="text-[11px] font-bold text-red-400 uppercase tracking-wider">What you've lost</p>
                                    <ul className="text-xs text-slate-400 space-y-1">
                                        <li className="flex items-start gap-2"><span className="text-red-500 mt-0.5">•</span>At least some of your items in the safe or garage.</li>
                                        <li className="flex items-start gap-2"><span className="text-red-500 mt-0.5">•</span>Access to storage.</li>
                                    </ul>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={onExit}
                                className="w-full h-11 bg-slate-700 hover:bg-slate-600 rounded-xl text-xs font-black uppercase tracking-[0.2em] text-white transition-all"
                            >
                                Understood
                            </button>
                        </div>
                    </motion.div>
                </div>
            )}
        </AnimatePresence>
    );
}
