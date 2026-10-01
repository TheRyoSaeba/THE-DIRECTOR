/**
 * Shared class fragments for the UI kit.
 *
 * Every component pulls its typography / surface / focus classes from here so
 * that, once the proposed tailwind.config.js patch lands (see README.md), the
 * arbitrary values below can be swapped for named tokens (`text-label`,
 * `z-dialog`, …) in one place.
 */

/** Join class names, skipping falsy values. (No tailwind-merge: later classes do not override earlier ones.) */
export function cn(...parts: Array<string | false | null | undefined | 0>): string {
    return parts.filter(Boolean).join(' ');
}

// ── Typography scale (mirrors the proposed `fontSize` patch) ─────────────────
/** 10/14, uppercase, .16em tracking, weight 800 — eyebrows, button text, badges. */
export const textLabel = 'text-label uppercase';
/** 12/16 — hints, meta, table secondary text. */
export const textCaption = 'text-xs';
/** 14/20 — default body copy. */
export const textBody = 'text-sm';
/** 16/24 */
export const textLead = 'text-base';
/** 20/28 */
export const textTitle = 'text-xl';
/** 30/36 */
export const textDisplay = 'text-3xl';

// ── Colour floors ────────────────────────────────────────────────────────────
/** Secondary text floor on slate-950/900: slate-400 (≈7.9:1). Never go darker for text. */
export const textMuted = 'text-slate-400';

// ── Focus ────────────────────────────────────────────────────────────────────
export const focusRing =
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950';
/** For elements sitting inside a slate-900 surface. */
export const focusRingInset = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cyan-400';

// ── Surfaces ─────────────────────────────────────────────────────────────────
export const panelShadow = 'shadow-[0_12px_30px_rgba(2,6,23,0.2)]';
export const surfaceRaised = 'bg-slate-900 border border-slate-800';

// ── z-index layers (mirrors the proposed `zIndex` patch) ─────────────────────
export const zHeader = 'z-header';
export const zDrawer = 'z-drawer';
export const zDialog = 'z-dialog';
export const zToast = 'z-toast';
export const zTooltip = 'z-tooltip';

// ── Tones ────────────────────────────────────────────────────────────────────
export type Tone = 'slate' | 'cyan' | 'emerald' | 'red' | 'amber' | 'gold' | 'purple' | 'blue';

/** Soft (tinted bg + border) tone classes, used by Badge and friends. */
export const toneSoft: Record<Tone, string> = {
    slate: 'bg-slate-800/80 text-slate-300 border-slate-700',
    cyan: 'bg-cyan-500/15 text-cyan-300 border-cyan-500/30',
    emerald: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
    red: 'bg-red-500/15 text-red-300 border-red-500/30',
    amber: 'bg-amber-500/15 text-amber-300 border-amber-500/30',
    gold: 'bg-amber-300/10 text-amber-200 border-amber-300/40',
    purple: 'bg-purple-500/15 text-purple-300 border-purple-500/30',
    blue: 'bg-blue-500/15 text-blue-300 border-blue-500/30',
};

/** Foreground-only tone classes. */
export const toneText: Record<Tone, string> = {
    slate: 'text-slate-100',
    cyan: 'text-cyan-400',
    emerald: 'text-emerald-400',
    red: 'text-red-400',
    amber: 'text-amber-400',
    gold: 'text-amber-300',
    purple: 'text-purple-400',
    blue: 'text-blue-400',
};

/** Solid fill (bars, dots). */
export const toneFill: Record<Tone, string> = {
    slate: 'bg-slate-400',
    cyan: 'bg-cyan-400',
    emerald: 'bg-emerald-400',
    red: 'bg-red-500',
    amber: 'bg-amber-400',
    gold: 'bg-amber-300',
    purple: 'bg-purple-400',
    blue: 'bg-blue-400',
};
