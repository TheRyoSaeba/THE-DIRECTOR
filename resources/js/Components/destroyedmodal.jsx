import { AnimatePresence, motion } from 'framer-motion';
import { X } from '@phosphor-icons/react';

export default function destroyedmodal({
    isOpen,
    onExit,
}) {
    const headerImageUrl = 'https://images.thedirector.app/properties/bombed.jpg';

    return (
        <AnimatePresence>
            {isOpen && (
                <div className="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/30 backdrop-blur-[2px]">
                    <motion.div
                        initial={{ opacity: 0, scale: 0.95, y: 20 }}
                        animate={{ opacity: 1, scale: 1, y: 0 }}
                        exit={{ opacity: 0, scale: 0.95, y: 20 }}
                        className="bg-slate-900 border border-slate-700 w-full max-w-md rounded-2xl overflow-hidden shadow-2xl"
                        role="dialog"
                        aria-modal="true"
                    >
                        {/* Header (matches Wardrobe modals) */}
                        <div className="relative aspect-video bg-slate-950 flex items-center justify-center">
                            <img
                                src={headerImageUrl}
                                alt="Home ruins"
                                className="w-full h-full object-contain p-6"
                                loading="eager"
                            />
                            <div className="absolute inset-0 bg-gradient-to-t from-slate-900 via-transparent to-transparent" />

                            <button
                                type="button"
                                onClick={onExit}
                                className="absolute top-4 right-4 p-2 bg-black/50 hover:bg-black/80 text-white rounded-full transition-colors"
                                aria-label="Close"
                            >
                                <X size={18} />
                            </button>

                            <div className="absolute bottom-4 left-6">
                                <h3 className="text-xl font-black text-white uppercase tracking-tight">
                                    YOUR HOME HAS BEEN DESTROYED
                                </h3>
                            </div>
                        </div>

                        <div className="p-6 space-y-5">
                            <p className="text-sm text-slate-300 leading-relaxed">
                                You must wait for a technician to repair your home to have any hope of salvaging your belongings.
                            </p>

                            <button
                                type="button"
                                onClick={onExit}
                                className="w-full h-12 bg-cyan-600 hover:bg-cyan-500 rounded-xl text-xs font-black uppercase tracking-[0.2em] transition-all flex items-center justify-center"
                            >
                                Return to settings
                            </button>
                        </div>
                    </motion.div>
                </div>
            )}
        </AnimatePresence>
    );
}

