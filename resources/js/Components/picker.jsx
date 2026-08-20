import { useState, useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { CaretDown, CaretUp, CheckCircle } from '@phosphor-icons/react';

/**
 * Picker — a portal-based dropdown with search.
 *
 * Key design decisions:
 *  - Rendered into document.body via portal so it escapes any overflow:hidden
 *    ancestor. Position is fixed, recalculated on open.
 *  - NO scroll-to-close. Scroll-close was the root cause of the "disappears
 *    when you scroll the list" bug — the capture-phase scroll listener fired
 *    on scrolling inside the dropdown itself. We rely solely on outside-click.
 *  - The scrollable list uses overscroll-contain so momentum scroll doesn't
 *    bubble to the page.
 *  - The outer motion.div has a fixed pixel height cap; the inner list fills
 *    the remainder after the search bar. Both heights are derived from the
 *    same constant so they can never fight each other.
 */

const LIST_MAX_PX   = 280; // max height of the scrollable list section
const SEARCH_BAR_PX = 44;  // search input bar height
const DROPDOWN_MAX  = LIST_MAX_PX + SEARCH_BAR_PX; // total dropdown cap

export default function Picker({ options, value, onChange, placeholder, className = '', placement = 'auto' }) {
    const [open, setOpen]               = useState(false);
    const [search, setSearch]           = useState('');
    const [pos, setPos]                 = useState({ top: 0, left: 0, width: 0, openUp: false });

    const triggerRef  = useRef(null);
    const dropdownRef = useRef(null);
    const searchRef   = useRef(null);

    const selected        = options.find(opt => opt.value === value);
    const filteredOptions = search
        ? options.filter(opt => String(opt.label).toLowerCase().includes(search.toLowerCase()))
        : options;

    // ── Position calculation ─────────────────────────────────────────────────

    const recalc = () => {
        if (!triggerRef.current) return;
        const rect     = triggerRef.current.getBoundingClientRect();
        const vpHeight = window.innerHeight;
        const below    = vpHeight - rect.bottom;
        const openUp = placement === 'top'
            ? true
            : placement === 'bottom'
                ? false
                : below < DROPDOWN_MAX + 8 && rect.top > below;

        setPos({
            top:    openUp ? rect.top - DROPDOWN_MAX - 6 : rect.bottom + 4,
            left:   rect.left,
            width:  rect.width,
            openUp,
        });
    };

    // ── Open / close ─────────────────────────────────────────────────────────

    const toggle = () => {
        if (!open) {
            recalc();
            setSearch('');
        }
        setOpen(o => !o);
    };

    // Focus the search input as soon as the dropdown is open.
    useEffect(() => {
        if (open && searchRef.current) {
            // rAF ensures the portal node is mounted before we call focus.
            requestAnimationFrame(() => searchRef.current?.focus());
        }
    }, [open]);

    // ── Outside-click close only — NO scroll listener ────────────────────────
    // Scroll-to-close was removed because the capture-phase scroll event fired
    // when the user scrolled inside the list itself, closing the dropdown
    // mid-scroll. Outside-click is sufficient and correct.

    useEffect(() => {
        if (!open) return;
        const onMouseDown = (e) => {
            if (
                dropdownRef.current?.contains(e.target) ||
                triggerRef.current?.contains(e.target)
            ) return;
            setOpen(false);
        };
        document.addEventListener('mousedown', onMouseDown);
        return () => document.removeEventListener('mousedown', onMouseDown);
    }, [open]);

    // Recalculate if the window resizes while open (e.g. mobile keyboard).
    useEffect(() => {
        if (!open) return;
        window.addEventListener('resize', recalc, { passive: true });
        return () => window.removeEventListener('resize', recalc);
    }, [open]);

    // ── Dropdown portal ──────────────────────────────────────────────────────

    const dropdown = (
        <AnimatePresence>
            {open && (
                <motion.div
                    ref={dropdownRef}
                    initial={{ opacity: 0, scaleY: 0.88 }}
                    animate={{ opacity: 1, scaleY: 1 }}
                    exit={{ opacity: 0, scaleY: 0.9 }}
                    transition={{ duration: 0.12, ease: 'easeOut' }}
                    style={{
                        position:        'fixed',
                        top:             pos.top,
                        left:            pos.left,
                        width:           pos.width,
                        transformOrigin: `${pos.openUp ? 'bottom' : 'top'} center`,
                        zIndex:          99999,
                    }}
                    className="rounded-xl border border-slate-700/60 bg-slate-900 shadow-2xl overflow-hidden"
                >
                    {/* Search bar — fixed height, never scrolls */}
                    <div
                        style={{ height: SEARCH_BAR_PX }}
                        className="flex items-center px-3 border-b border-slate-800/60 bg-slate-900/90 shrink-0"
                    >
                        <input
                            ref={searchRef}
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            placeholder="Type to filter…"
                            className="w-full bg-slate-950/60 border border-slate-700/60 rounded-lg px-2.5 py-1.5 text-xs text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-cyan-500/60"
                        />
                    </div>

                    {/* Scrollable list — fixed max height, overscroll contained */}
                    <div
                        style={{ maxHeight: LIST_MAX_PX }}
                        className="overflow-y-auto overscroll-contain"
                    >
                        {filteredOptions.length === 0 ? (
                            <div className="px-4 py-4 text-xs text-slate-500 text-center">No matches</div>
                        ) : (
                            filteredOptions.map(opt => (
                                <button
                                    key={opt.value}
                                    type="button"
                                    onMouseDown={(e) => {
                                        // Use onMouseDown (not onClick) so the selection
                                        // registers before the outside-click handler fires.
                                        e.preventDefault();
                                        onChange(opt.value);
                                        setOpen(false);
                                        setSearch('');
                                    }}
                                    className={`w-full flex items-center justify-between gap-2 px-4 py-2.5 text-xs text-left transition-colors ${
                                        opt.value === value
                                            ? 'bg-cyan-900/30 text-cyan-300 font-bold'
                                            : 'text-slate-300 hover:bg-slate-800/80 hover:text-white'
                                    }`}
                                >
                                    <span className="truncate">{opt.label}</span>
                                    {opt.value === value && (
                                        <CheckCircle size={11} weight="fill" className="text-cyan-400 shrink-0" />
                                    )}
                                </button>
                            ))
                        )}
                    </div>
                </motion.div>
            )}
        </AnimatePresence>
    );

    // ── Trigger button ───────────────────────────────────────────────────────

    return (
        <div className={`relative ${className}`}>
            <button
                ref={triggerRef}
                type="button"
                onClick={toggle}
                className={`w-full flex items-center justify-between gap-2 px-4 py-3 rounded-xl border text-sm font-bold transition-all focus:outline-none ${
                    selected
                        ? 'bg-slate-800/80 border-cyan-600/50 text-white hover:border-cyan-500/70'
                        : 'bg-slate-800/50 border-slate-600/50 text-slate-400 hover:border-slate-500/70'
                }`}
            >
                <span className="truncate">{selected ? selected.label : placeholder}</span>
                {open ? <CaretUp size={12} weight="bold" /> : <CaretDown size={12} weight="bold" />}
            </button>

            {typeof window !== 'undefined' && document.body
                ? createPortal(dropdown, document.body)
                : dropdown}
        </div>
    );
}
