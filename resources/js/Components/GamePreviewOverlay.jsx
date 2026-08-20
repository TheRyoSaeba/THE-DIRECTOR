import { ArrowLeft, CaretLeft, CaretRight } from '@phosphor-icons/react';
import { AnimatePresence, motion } from 'framer-motion';

export const gamePreviewItems = [
    {
        src: 'https://images.thedirector.app/Promotional/mainpromotional.png',
        alt: 'Main dashboard',
        title: 'Main dashboard',
        description: 'Your main dashboard page, this is where you can view a shorthand summary of your character ,education level and general game overview.',
    },
    {
        src: 'https://images.thedirector.app/Promotional/workpromotional.png',
        alt: 'Work',
        title: 'Work',
        description: 'The Work cycle is the secret to building your character and earning experience and cash, it\'s the basic unit of gameplay. Career and rank milestones unlocks new classes of works, and even certain achievements can unlock secret works as well.',
    },

    {
        src: 'https://images.thedirector.app/Promotional/profilepromotional1.png',
        alt: 'Profile',
        title: 'Profile',
        description: 'The Profile page is the center of your identity and what other players will see when they visit you from the online list ',
    },

    {
        src: 'https://images.thedirector.app/Promotional/tooltippromotional1.png',
        alt: 'Tooltip',
        title: 'ONLINE LIST',
        description: '',
    },

    {
        src: 'https://images.thedirector.app/Promotional/actionpromotional.png',
        alt: 'Actions',
        title: 'Actions',
        description: 'Actions are activties you can do around the city in the game, some are general while someone require you to hold specific careers or degrees ',
    },

    {
        src: 'https://images.thedirector.app/Promotional/universitypromotional.png',
        alt: 'University',
        title: 'University',
        description: 'The university is your destination for enrolling in new degrees and one of  the main ways to change careers',
    },

    {
        src: 'https://images.thedirector.app/Promotional/corporation2promotional.png',
        alt: 'Career',
        title: 'Career Activities',
        description: 'Each Career in TheDirector has its own sets of works and career specific activities, from solving crimes and  running the city to managing your own  corporation.   ',
    },
    {
        src: 'https://images.thedirector.app/Promotional/rankings.png',
        alt: 'Rankings',
        title: 'Rankings',
        description: 'Make your mark enough on this world and you might just become immortalized in our rankings!',
    },

    {
        src: 'https://images.thedirector.app/Promotional/journalpromotional1.png',
        alt: 'Journal',
        title: 'Journal',
        description: 'Your journals page is important, it\'s where you can get in-game notifictions of all the events that have happend to your character in game',
    },
    {
        src: 'https://images.thedirector.app/Promotional/wardrobe.png',
        alt: 'Outfit',
        title: 'Outfit',
        description: 'Customize your character with a variety of outfits. Outfits are mostly cosemtic.',
    },
];

export default function GamePreviewOverlay({ open, activeIndex, items = gamePreviewItems, onBack, onNext, onPrevious }) {
    const activeItem = items[activeIndex] ?? items[0];

    return (
        <AnimatePresence>
            {open && activeItem && (
                <motion.div
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    exit={{ opacity: 0 }}
                    transition={{ duration: 0.18 }}
                    className="fixed inset-0 z-[80] overflow-hidden bg-black/48 text-white backdrop-blur-[1px]"
                    aria-modal="true"
                    role="dialog"
                    aria-label="Game preview"
                >
                    <div className="relative flex min-h-screen flex-col px-4 py-5 sm:px-6 sm:py-6 lg:px-8 lg:py-7">
                        <div className="flex items-center justify-between gap-4">
                            <button
                                type="button"
                                onClick={onBack}
                                className="inline-flex min-h-11 items-center gap-2 rounded-full border border-white/15 bg-black/45 px-4 text-[11px] font-black uppercase tracking-[0.2em] text-white/80 shadow-lg shadow-black/30 transition hover:border-cyan-300/45 hover:bg-cyan-300/10 hover:text-white"
                            >
                                <ArrowLeft size={15} weight="bold" />
                                Back
                            </button>
                        </div>

                        <div className="grid flex-1 place-items-center py-5 sm:py-6">
                            <div className="relative w-full max-w-[min(88vw,1040px)]">
                                <button
                                    type="button"
                                    onClick={onPrevious}
                                    className="absolute -left-20 top-1/2 z-20 hidden h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full border border-white/15 bg-black/55 text-white/75 shadow-xl shadow-black/40 transition hover:border-cyan-300/45 hover:bg-cyan-300/10 hover:text-white xl:flex"
                                    aria-label="Previous preview"
                                >
                                    <CaretLeft size={24} weight="bold" />
                                </button>
                                <button
                                    type="button"
                                    onClick={onNext}
                                    className="absolute -right-20 top-1/2 z-20 hidden h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full border border-white/15 bg-black/55 text-white/75 shadow-xl shadow-black/40 transition hover:border-cyan-300/45 hover:bg-cyan-300/10 hover:text-white xl:flex"
                                    aria-label="Next preview"
                                >
                                    <CaretRight size={24} weight="bold" />
                                </button>

                                <div className="mx-auto overflow-hidden rounded-xl border border-white/12 bg-black shadow-2xl shadow-black/60">
                                    <AnimatePresence mode="wait">
                                        <motion.img
                                            key={activeItem.src}
                                            src={activeItem.src}
                                            alt={activeItem.alt}
                                            initial={{ opacity: 0, x: 24 }}
                                            animate={{ opacity: 1, x: 0 }}
                                            exit={{ opacity: 0, x: -24 }}
                                            transition={{ duration: 0.22 }}
                                            className="block h-auto max-h-[68vh] w-full object-contain"
                                            draggable={false}
                                        />
                                    </AnimatePresence>
                                    <div className="border-t border-white/10 bg-black/92 px-4 py-3 sm:px-5">
                                        <p className="text-sm font-black uppercase tracking-[0.18em] text-white sm:text-base">
                                            {activeItem.title}
                                        </p>
                                        <p className="mt-1 max-w-3xl text-xs font-semibold leading-relaxed text-slate-300">
                                            {activeItem.description}
                                        </p>
                                    </div>
                                </div>

                                <div className="mt-4 flex items-center justify-between gap-3 xl:hidden">
                                    <button
                                        type="button"
                                        onClick={onPrevious}
                                        className="inline-flex min-h-12 flex-1 items-center justify-center gap-2 rounded-full border border-white/15 bg-white/5 text-[11px] font-black uppercase tracking-[0.18em] text-white/75"
                                    >
                                        <CaretLeft size={18} weight="bold" />
                                        Previous
                                    </button>
                                    <button
                                        type="button"
                                        onClick={onNext}
                                        className="inline-flex min-h-12 flex-1 items-center justify-center gap-2 rounded-full border border-white/15 bg-white/5 text-[11px] font-black uppercase tracking-[0.18em] text-white/75"
                                    >
                                        Next
                                        <CaretRight size={18} weight="bold" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </motion.div>
            )}
        </AnimatePresence>
    );
}
