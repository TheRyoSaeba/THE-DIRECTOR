/**
 * ForumBBCode
 * ----------
 * Renders forum post bodies. Auto-detects whether content uses BBCode or
 * legacy Markdown and routes to the appropriate renderer.
 *
 * Security:
 *   - BBCode maps to React.createElement only — never innerHTML, no XSS
 *   - [url] validates https?:// scheme — blocks javascript: and data:
 *   - [img] validates https?:// scheme — blocks data:image/svg+xml XSS vector
 *   - [color] goes through a safe palette map — no arbitrary CSS injection
 *   - Unknown BBCode tags render as literal text — no stray HTML elements
 *   - Markdown fallback uses react-markdown with allowedElements whitelist
 *   - HTML entities decoded before rendering (old admin posts)
 *
 * BBCode supported tags:
 *   [b]            bold
 *   [i]            italic
 *   [u]            underline
 *   [s]            strikethrough
 *   [url=href]     external link (https only, noopener)
 *   [img]url[/img] responsive image (https only)
 *   [code]         monospace preformatted block
 *   [quote=name]   styled quote box with author header
 *   [spoiler]      collapsed spoiler toggle
 *   [color=val]    inline colour (restricted safe palette)
 *   [h]            section heading
 *   [c]            center align
 *   [list]/[*]     bulleted list
 *   [hr]           horizontal rule
 *   [player]       link to profile
 */

import React, { useState, Component } from 'react';
import BBCode from '@bbob/react';
import presetReact from '@bbob/preset-react';
import { getUniqAttr } from '@bbob/plugin-helper';
import { ArrowBendUpLeft, Eye, EyeSlash, Warning } from '@phosphor-icons/react';
import MarkdownRenderer from '@/Components/MarkdownRenderer';

// ── HTML entity decoder ───────────────────────────────────────────────────────
const ENTITIES = {
    '&amp;':   '&',
    '&lt;':    '<',
    '&gt;':    '>',
    '&quot;':  '"',
    '&#039;':  "'",
    '&#39;':   "'",
    '&#x27;':  "'",
    '&apos;':  "'",
    '&#8217;': '\u2019',
    '&#8216;': '\u2018',
    '&#8220;': '\u201C',
    '&#8221;': '\u201D',
    '&#8211;': '\u2013',
    '&#8212;': '\u2014',
};

const decodeEntities = (str) => {
    if (!str) return '';
    let decoded = str;
    // Run up to 3 times to catch double-encoded entities like &amp;#039; -> &#039; -> '
    for (let i = 0; i < 3; i++) {
        const next = decoded.replace(/&(?:#0*39|#x27|amp|lt|gt|quot|apos|#\d+);/gi, (m) => ENTITIES[m.toLowerCase()] ?? m);
        if (next === decoded) break;
        decoded = next;
    }
    return decoded;
};

// ── URL safety ────────────────────────────────────────────────────────────────
const isSafeUrl = (href) => typeof href === 'string' && /^https?:\/\//i.test(href);

// ── BBCode detection ──────────────────────────────────────────────────────────
// IMPORTANT: Must only match well-formed opening or closing tags, not partial
// matches inside URLs or prose. e.g. [b], [/b], [url=...], [quote=name]
// The previous regex matched [c/] (from URLs) because it allowed '/' in the
// character class — that caused React.createElement('c/') → fatal crash.
//
// Rules:
//   Opening: [tagname] or [tagname=attr] or [tagname attr]
//   Closing:  [/tagname]
//   tagname must be one of our known set — no arbitrary tag passthrough.
const KNOWN_TAGS = 'b|i|u|s|url|img|code|quote|q|spoiler|sp|color|centre|center|c|h|hr|list|player';
const BBCODE_OPEN_RE  = new RegExp(`\\[(?:${KNOWN_TAGS})(?:[\\]=\\s][^\\]]*)?\\]`, 'i');
const BBCODE_CLOSE_RE = new RegExp(`\\[/(?:${KNOWN_TAGS})\\]`, 'i');
const hasBBCode = (str) => BBCODE_OPEN_RE.test(str) || BBCODE_CLOSE_RE.test(str);

// ── Safe colour map ───────────────────────────────────────────────────────────
const SAFE_COLORS = {
    red:    '#f87171',
    green:  '#4ade80',
    blue:   '#60a5fa',
    yellow: '#facc15',
    orange: '#fb923c',
    cyan:   '#22d3ee',
    pink:   '#f472b6',
    white:  '#f1f5f9',
    gray:   '#94a3b8',
    grey:   '#94a3b8',
};
const safeColor = (val) => SAFE_COLORS[String(val ?? '').toLowerCase()] ?? null;

// ── Sub-components ────────────────────────────────────────────────────────────
function Spoiler({ children }) {
    const [open, setOpen] = useState(false);
    return (
        <span className="block my-2">
            <button
                type="button"
                onClick={() => setOpen(v => !v)}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-700/60 bg-slate-800/60 text-xs font-semibold text-slate-400 hover:text-white hover:border-slate-600 transition-all"
            >
                {open ? <EyeSlash size={12} weight="bold" /> : <Eye size={12} weight="bold" />}
                {open ? 'Hide spoiler' : 'Show spoiler'}
            </button>
            {open && (
                <span className="block mt-2 px-4 py-3 rounded-xl border border-slate-700/40 bg-slate-800/30 text-sm text-slate-300 leading-relaxed">
                    {children}
                </span>
            )}
        </span>
    );
}

function QuoteBlock({ author, children }) {
    return (
        <span className="block rounded-xl overflow-hidden border border-slate-700/50 my-3">
            <span className="flex items-center gap-2 px-4 py-2 bg-slate-700/30 border-b border-slate-700/40">
                <ArrowBendUpLeft size={11} className="text-slate-500 shrink-0" />
                <span className="text-xs font-bold text-slate-400">
                    {author ? `${author} wrote:` : 'Quote:'}
                </span>
            </span>
            <span className="block px-4 py-3 bg-slate-800/20 text-sm text-slate-400 leading-relaxed">
                {children}
            </span>
        </span>
    );
}

// ── Error boundary — isolates a bad post from crashing the whole thread ───────
// A malformed tag that slips through detection still gets caught here.
class BBCodeErrorBoundary extends Component {
    constructor(props) {
        super(props);
        this.state = { crashed: false };
    }
    static getDerivedStateFromError() {
        return { crashed: true };
    }
    render() {
        if (this.state.crashed) {
            return (
                <span className="flex items-center gap-2 px-3 py-2 rounded-lg bg-amber-500/10 border border-amber-500/20 text-xs text-amber-400/80">
                    <Warning size={13} weight="fill" className="shrink-0" />
                    This post cannot be rendered due to a formatting error.
                </span>
            );
        }
        return this.props.children;
    }
}

// ── Custom BBCode preset ──────────────────────────────────────────────────────
const gamePreset = presetReact.extend((tags) => ({
    ...tags,

    b:     (node) => ({ tag: 'strong', attrs: { className: 'font-bold text-white' },                           content: node.content }),
    i:     (node) => ({ tag: 'em',     attrs: { className: 'italic' },                                        content: node.content }),
    u:     (node) => ({ tag: 'span',   attrs: { className: 'underline underline-offset-2' },                  content: node.content }),
    s:     (node) => ({ tag: 'span',   attrs: { className: 'line-through text-slate-500' },                   content: node.content }),

    color: (node) => {
        const color = safeColor(getUniqAttr(node.attrs));
        return { tag: 'span', attrs: color ? { style: { color } } : {}, content: node.content };
    },

    url: (node, { render }) => {
        const href = getUniqAttr(node.attrs) || render(node.content || []);
        return {
            tag: 'a',
            attrs: isSafeUrl(href)
                ? { href, target: '_blank', rel: 'noopener noreferrer', className: 'text-cyan-400 underline underline-offset-2 hover:text-cyan-300 transition-colors' }
                : { className: 'text-slate-500 line-through cursor-not-allowed', title: 'Unsafe URL removed' },
            content: node.content,
        };
    },

    // Images render inline-block and cap at 60% width × 280px height. The
    // 60% cap means even when an image sits inside a [c] (centered block)
    // there's visible whitespace around it — so consecutive [c][img][/c]
    // / [c]caption[/c] pairs read as figure+caption units, not two
    // dominating blocks with a gap between them.
    //
    // We deliberately don't try to wrap text around the image — every
    // author wraps images in [c] which makes them block-level anyway, so
    // float CSS is fighting the source format. Keeping images flow-block
    // but smaller is the honest tradeoff.
    //
    // Security: same admin-only rule — non-admin posts have [img] stripped
    // server-side (ForumBodySanitizer), and https?:// is re-enforced here.
    img: (node, { render }) => {
        const src = String(render(node.content || []));
        return {
            tag: 'img',
            attrs: {
                src: isSafeUrl(src) ? src : '',
                alt: '',
                loading: 'lazy',
                className: 'inline-block max-w-[60%] max-h-72 my-1 align-middle',
            },
            content: null,
        };
    },

    code: (node) => ({
        tag: 'pre',
        attrs: { className: 'my-2 px-4 py-3 rounded-xl bg-slate-900 border border-slate-800/60 text-xs font-mono text-emerald-300 leading-relaxed overflow-x-auto whitespace-pre-wrap' },
        content: node.content,
    }),

    quote: (node) => ({ tag: QuoteBlock, attrs: { author: getUniqAttr(node.attrs) || null }, content: node.content }),
    q:     (node) => ({ tag: QuoteBlock, attrs: { author: getUniqAttr(node.attrs) || null }, content: node.content }),

    spoiler: (node) => ({ tag: Spoiler, attrs: {}, content: node.content }),
    sp:      (node) => ({ tag: Spoiler, attrs: {}, content: node.content }),

    // [c] / [center] / [centre] — my-0.5 (was my-2) so consecutive
    // centered blocks (e.g. [c][img][/c] + [c]caption[/c]) read as a
    // single figure unit, not two blocks with stacked gaps.
    center:  (node) => ({ tag: 'div', attrs: { className: 'text-center my-0.5' }, content: node.content }),
    centre:  (node) => ({ tag: 'div', attrs: { className: 'text-center my-0.5' }, content: node.content }),
    c:       (node) => ({ tag: 'div', attrs: { className: 'text-center my-0.5' }, content: node.content }),

    // mt-3 + mb-1 instead of mt-4 + mb-2 — and the border-b is decorative
    // enough on its own (no pb-1 padding ladder). When a user types a blank
    // line BEFORE [h], whitespace-pre-wrap already preserves that line as a
    // gap, so adding mt-4 on top compounds the spacing.
    //
    // clear-both ensures a new section header drops below any floated image
    // sitting next to the previous section, instead of squeezing into the
    // text column beside it.
    h: (node) => ({
        tag: 'div',
        attrs: { className: 'text-cyan-400 font-bold text-lg mt-3 mb-1 border-b border-cyan-900/30 clear-both' },
        content: node.content,
    }),

    // my-3 was generous given whitespace-pre-wrap already preserves the
    // user's typed blank lines on either side. my-2 matches paragraph rhythm.
    // clear-both so a horizontal rule visually ends a section that included
    // a floated image, rather than getting wrapped into the float column.
    hr: () => ({ tag: 'hr', attrs: { className: 'border-slate-800 my-2 clear-both' }, content: null }),

    list: (node) => ({
        tag: 'ul',
        attrs: { className: 'list-disc list-inside my-2 space-y-1' },
        content: node.content,
    }),

    '*': (node) => ({
        tag: 'li',
        attrs: { className: 'text-slate-300' },
        content: node.content,
    }),

    player: (node, { render }) => {
        const name = String(render(node.content || []));
        return {
            tag: 'a',
            attrs: {
                href: `/profile/${encodeURIComponent(name)}`,
                className: 'text-emerald-400 font-bold hover:text-emerald-300 transition-colors',
            },
            content: node.content,
        };
    },
}));

const PLUGINS = [gamePreset()];

// ── Whitespace normalizer ─────────────────────────────────────────────────────
// Because the rendered body uses whitespace-pre-wrap (so casual newlines in
// prose still break lines), the user-typed \n characters AROUND block-level
// tags get preserved as visible vertical gaps — on top of the margins the
// block element already has. That compounds into the "too much empty space"
// the user reported.
//
// Fix: before rendering, collapse runs of newlines that sit adjacent to a
// block-level tag's opening or closing bracket. Block tags own their own
// vertical rhythm via Tailwind margins; the surrounding raw \n is noise.
//
// Inline tags ([b] [i] [u] [s] [color] [url] [player]) are NOT in this set —
// blank lines around inline tags ARE meaningful (paragraph breaks in prose).
const BLOCK_TAGS = 'img|h|hr|list|\\*|code|quote|q|spoiler|sp|c|center|centre';
const BLOCK_OPEN_RE  = new RegExp(`\\n+(\\[(?:${BLOCK_TAGS})(?:[=\\s\\]][^\\]]*)?\\])`, 'gi');
const BLOCK_CLOSE_RE = new RegExp(`(\\[/(?:${BLOCK_TAGS})\\])\\n+`, 'gi');

const normalizeBlockWhitespace = (s) =>
    s.replace(BLOCK_OPEN_RE, '\n$1').replace(BLOCK_CLOSE_RE, '$1\n');

// ── Public component ──────────────────────────────────────────────────────────
// Renders the whole body through the BBCode parser in ONE pass. Earlier
// attempts tried to pre-split the body into "sections" on [h]...[/h] before
// parsing — that broke whenever a user wrapped [h] inside [c], because
// slicing the raw string in the middle of an outer tag wrapper left dangling
// [/c] tags that the renderer printed as literal text.
//
// The lesson: BBCode is span-nested by nature, not block-divided like
// markdown. Trust the parser to handle structure; don't try to chunk the
// string yourself first.
export default function ForumBBCode({ children, className = '' }) {
    if (!children) return null;
    const decoded = decodeEntities(String(children));

    if (hasBBCode(decoded)) {
        const normalized = normalizeBlockWhitespace(decoded);
        return (
            <BBCodeErrorBoundary>
                <div className={`forum-bbcode whitespace-pre-wrap ${className}`}>
                    <BBCode plugins={PLUGINS}>{normalized}</BBCode>
                </div>
            </BBCodeErrorBoundary>
        );
    }

    // Legacy markdown fallback — also wrapped so a bad markdown doc can't crash
    return (
        <BBCodeErrorBoundary>
            <MarkdownRenderer className={className}>{decoded}</MarkdownRenderer>
        </BBCodeErrorBoundary>
    );
}

// ── BBCode reference legend ───────────────────────────────────────────────────
// Inline strip of clickable tag chips. NO box, NO collapsible — just a row of
// monospace pills under the composer that the user can click to copy into the
// textarea. Same shape and spirit as the Help.jsx BBCodeTags component, so
// city hall + community forum present the formatting help identically.
//
// We intentionally do NOT show [url], [img], [code] to non-admins since the
// server strips [img] from their posts anyway (see ForumBodySanitizer).
export function BBCodeLegend({ isAdmin = false }) {
    const base = ['[b]', '[i]', '[u]', '[s]', '[q=]', '[sp]', '[c]', '[h]', '[color=]', '[list]', '[*]', '[player]'];
    const admin = isAdmin ? ['[url=]', '[img]', '[code]'] : [];
    const tags = [...base, ...admin];

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <span className="text-[10px] font-bold uppercase tracking-widest text-slate-500 mr-1">
                Tags
            </span>
            {tags.map(tag => (
                <code
                    key={tag}
                    className="text-[11px] font-mono text-slate-200 bg-slate-800/60 border border-slate-700/40 px-1.5 py-0.5 rounded select-all cursor-text whitespace-nowrap hover:bg-slate-800 hover:border-slate-600 transition-colors"
                    title={`Select then copy this ${tag} tag`}
                >
                    {tag}
                </code>
            ))}
        </div>
    );
}
