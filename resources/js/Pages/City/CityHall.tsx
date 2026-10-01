import { useEffect, useState } from 'react';
import { Head, router, Link } from '@inertiajs/react';
// @ts-ignore
import { route } from 'ziggy-js';
import {
    Crown, Megaphone, ChatTeardrop,
    MapPin, ArrowRight, User, Clock, PlusCircle,
    Trash, CaretDown, X, PaperPlaneTilt,
    House, Warning, CheckCircle, Buildings,
    ArrowsLeftRight, PushPin, Lock, LockOpen,
    Eye, Quotes, CaretRight, CaretLeft, MagnifyingGlass,
    Gear,
} from '@phosphor-icons/react';
import { motion, AnimatePresence } from 'framer-motion';
import GameLayout from '@/Layouts/GameLayout';
import { getCityImage } from '@/utils/cityImages';
import { formatUTC } from '@/Layouts/GameLayoutComponents';
// @ts-ignore — JSX module
import ForumBBCode, { BBCodeLegend } from '@/Components/Forum/ForumBBCode';

// ─────────────────────────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────────────────────────

interface Mayor {
    name: string;
    avatar_url: string | null;
    term_period: number;
    term_days_remaining: number;
}

interface Aide {
    id: number;
    name: string;
    avatar_url: string | null;
}

interface Policy {
    income_tax_rate: number;
    corporate_tax_rate: number;
    corp_regulation_active: boolean;
    bonds_active: boolean;
    death_sentence_active: boolean;
}

interface Announcement {
    id: number;
    author: string;
    author_avatar: string | null;
    author_role: 'mayor' | 'aide';
    author_rank: string | null;
    body: string;
    pinned_at: string;
}

type ForumRole = 'mayor' | 'aide' | 'resident';

interface ForumPost {
    id: number;
    character_id: number | null;
    author: string;
    author_avatar: string | null;
    author_role: ForumRole;
    author_rank: string | null;
    author_post_count: number;
    title: string;
    body: string;
    reply_count: number;
    views: number;
    is_pinned: boolean;
    is_locked: boolean;
    created_at: string;
    last_reply_at: string;
    last_reply_author: string;
    last_reply_role: ForumRole;
    last_reply_rank: string | null;
    last_reply_avatar: string | null;
    replies: ForumReply[];
}

interface ForumReply {
    id: number;
    character_id: number | null;
    author: string;
    author_avatar: string | null;
    author_role: ForumRole;
    author_rank: string | null;
    author_post_count: number;
    body: string;
    created_at: string;
}

interface ForumSettings {
    post_fee: number;
    fee_min: number;
    fee_max: number;
}

interface PendingApplication {
    id: number;
    display_name: string;
    submitted_at: number;
    career: string;
    rank: number;
    level: number;
    wealth: {
        cash: number;
        property_value: number;
        businesses: string[];
        corporation: string | null;
    };
    convictions: number;
}

interface PaginatedApplications {
    data: PendingApplication[];
    current_page: number;
    last_page: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface CityRelocationInfo {
    pending_application: boolean;
    home_city: string;
    cooldown_days_remaining: number;
}

interface CityHallProps {
    city: { name: string; slug: string; image_url: string | null };
    mayor: Mayor | null;
    aides: Aide[];
    policies: Policy | null;
    bulletin_posts: Announcement[];
    current_user_avatar: string | null;
    forum_posts: ForumPost[];
    forum_settings: ForumSettings;
    relocation: CityRelocationInfo;
    // Not in the first response; requested when the Relocation tab opens.
    pending_applications?: PaginatedApplications | null;
    is_mayor: boolean;
    is_aide: boolean;
    is_resident: boolean;
    is_home_city: boolean;
    is_city_hall_owner: boolean;
    can_post_forum: boolean;
    owner: { name: string; avatar_url: string | null } | null;
}

// Partial-reload prop lists: each action redirects back here and Inertia
// re-requests only these props. 'auth' (cash/timers in the layout) and 'flash'
// (result toast) are shared props needed after every action.
const SHARED_PROPS = ['auth', 'flash'];
const RELOAD_BULLETIN = ['bulletin_posts', ...SHARED_PROPS];
const RELOAD_FORUM = ['forum_posts', ...SHARED_PROPS];
// Aide changes also change the author roles shown on bulletin + forum posts.
const RELOAD_AIDES = ['aides', 'bulletin_posts', 'forum_posts', ...SHARED_PROPS];
const RELOAD_APPLICATIONS = ['pending_applications', ...SHARED_PROPS];

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

const fmt$ = (n: number) => `$${Math.round(n).toLocaleString()}`;

const TABS = [
    { key: 'bulletin', label: 'Bulletin', icon: Megaphone },
    { key: 'forum', label: 'City Forum', icon: ChatTeardrop },
    { key: 'relocation', label: 'Relocation', icon: ArrowsLeftRight },
] as const;

type TabKey = (typeof TABS)[number]['key'];

// ─────────────────────────────────────────────────────────────────────────────
// Avatar
// ─────────────────────────────────────────────────────────────────────────────

function Avatar({ src, name, size = 'md' }: { src: string | null; name: string; size?: 'sm' | 'md' | 'lg' }) {
    const dim = { sm: 'w-7 h-7', md: 'w-9 h-9', lg: 'w-12 h-12' }[size];
    return src ? (
        <img src={src} alt={name} className={`${dim} rounded-xl object-cover shrink-0 border border-white/10`} />
    ) : (
        <div className={`${dim} rounded-xl bg-slate-800 border border-white/10 flex items-center justify-center shrink-0`}>
            <User size={size === 'lg' ? 20 : 14} className="text-slate-500" />
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Section: Bulletin
// ─────────────────────────────────────────────────────────────────────────────

function BulletinPanel({
    announcements, citySlug, canPost, currentUserAvatar,
}: {
    announcements: Announcement[]; citySlug: string; canPost: boolean;
    currentUserAvatar: string | null;
}) {
    const [composing, setComposing] = useState(false);
    const [body, setBody] = useState('');
    const [busy, setBusy] = useState(false);

    const submit = () => {
        if (!body.trim() || busy) return;
        setBusy(true);
        router.post(
            route('city.cityhall.announce', { city: citySlug }),
            { body: body.trim() },
            {
                only: RELOAD_BULLETIN,
                preserveScroll: true,
                onSuccess: () => { setComposing(false); setBody(''); },
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <div>
            {/* Compose strip — drops cleanly into the panel top, no oversized header */}
            {canPost && (
                <div className="border-b border-slate-800 bg-slate-950/40">
                    {!composing ? (
                        <button
                            onClick={() => setComposing(true)}
                            className="w-full flex items-center gap-3 px-4 sm:px-5 py-3 text-left hover:bg-amber-500/[0.06] transition-colors group"
                        >
                            <Avatar src={currentUserAvatar} name="You" size="sm" />
                            <span className="text-sm text-slate-400 group-hover:text-amber-300 transition-colors flex-1">
                                Post an official announcement…
                            </span>
                            <span className="hidden sm:inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-amber-400/80 group-hover:text-amber-300 shrink-0">
                                <PlusCircle size={12} weight="fill" />
                                New
                            </span>
                        </button>
                    ) : (
                        <div className="px-4 sm:px-5 py-4 space-y-3">
                            <div className="flex items-center gap-2">
                                <Megaphone size={13} weight="fill" className="text-amber-400" />
                                <span className="text-xs font-bold text-amber-400 uppercase tracking-wider">Official Announcement</span>
                                <span className="ml-auto text-xs text-slate-500 tabular-nums">{body.length}/300</span>
                            </div>
                            <textarea
                                autoFocus
                                value={body}
                                onChange={e => setBody(e.target.value.slice(0, 300))}
                                placeholder="Write your official announcement… [b]bold[/b] [q=name]…[/q]"
                                rows={4}
                                onWheel={e => e.currentTarget.focus()}
                                className="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500 resize-none focus:outline-none focus:border-amber-500/50 leading-relaxed"
                            />
                            <div className="flex items-end justify-between gap-3 flex-wrap">
                                <div className="flex-1 min-w-0">
                                    <BBCodeLegend />
                                </div>
                                <div className="flex items-center gap-2 shrink-0">
                                    <button
                                        onClick={() => { setComposing(false); setBody(''); }}
                                        className="px-3 py-1.5 text-xs font-bold text-slate-400 hover:text-white uppercase tracking-wider transition-colors"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        onClick={submit}
                                        disabled={!body.trim() || busy}
                                        className="flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-xs font-bold uppercase tracking-wider bg-amber-500/20 border border-amber-500/50 text-amber-200 hover:bg-amber-500/30 hover:text-amber-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                    >
                                        <PaperPlaneTilt size={12} weight="fill" />
                                        {busy ? 'Publishing…' : 'Publish'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* Announcements list */}
            {announcements.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-20 gap-4 px-6">
                    <div className="w-14 h-14 rounded-xl bg-slate-900/60 border border-slate-800 flex items-center justify-center">
                        <Megaphone size={24} weight="duotone" className="text-slate-600" />
                    </div>
                    <div className="text-center">
                        <p className="text-base font-bold text-slate-300">No announcements yet</p>
                        <p className="text-sm text-slate-500 mt-1">The mayor will post official notices here.</p>
                    </div>
                </div>
            ) : (
                <div className="divide-y divide-slate-800">
                    {announcements.map((a, i) => {
                        const isMayor = a.author_role === 'mayor';
                        const railColor = isMayor ? 'bg-amber-400' : 'bg-emerald-400';
                        const roleText = isMayor ? 'text-amber-400' : 'text-emerald-400';
                        const roleLabel = isMayor ? 'Mayor' : 'Aide';

                        return (
                            <motion.article
                                key={a.id}
                                initial={{ opacity: 0, y: 6 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ delay: Math.min(i * 0.04, 0.12) }}
                                className="group relative flex bg-slate-900/40 hover:bg-slate-900/60 transition-colors"
                            >
                                {/* Role rail */}
                                <div className={`w-1 shrink-0 ${railColor}`} />

                                {/* Body */}
                                <div className="flex-1 min-w-0 px-4 sm:px-5 py-4">
                                    {/* Byline */}
                                    <div className="flex items-center gap-3">
                                        <Avatar src={a.author_avatar} name={a.author} size="sm" />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <Link
                                                    href={route('profile.show', { displayName: a.author })}
                                                    className={`text-sm font-bold no-underline hover:underline hover:underline-offset-2 ${roleText}`}
                                                >
                                                    {a.author}
                                                </Link>
                                                <RoleChip role={a.author_role} />
                                            </div>
                                            <p className="text-xs text-slate-500 mt-0.5 tabular-nums">
                                                {formatUTC(a.pinned_at, false)}
                                            </p>
                                        </div>
                                        {canPost && (
                                            <button
                                                onClick={() => router.delete(route('city.cityhall.announce.delete', { city: citySlug, id: a.id }), { only: RELOAD_BULLETIN, preserveScroll: true })}
                                                className="shrink-0 p-1.5 rounded-md text-slate-500 hover:text-red-400 hover:bg-red-500/10 transition-colors"
                                            >
                                                <Trash size={13} weight="bold" />
                                            </button>
                                        )}
                                    </div>

                                    {/* Content */}
                                    <div className="mt-2.5 text-sm text-slate-100 leading-relaxed break-words">
                                        <ForumBBCode>{a.body}</ForumBBCode>
                                    </div>
                                </div>
                            </motion.article>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

// ═════════════════════════════════════════════════════════════════════════════
// Section: City Forum
// Plus Jakarta Sans throughout · cyan-400 accent · solid slate-900/950 chrome
// ═════════════════════════════════════════════════════════════════════════════

// City-hall role chip — Mayor/Aide are official posts; residents get no chip
// (their identity is conveyed by rank instead).
const ROLE_CHIP: Record<ForumRole, string | null> = {
    mayor: 'Mayor',
    aide: 'Aide',
    resident: null,
};

// Small chip component used everywhere a poster's name appears so the
// styling stays identical between bulletin, thread list, and thread view.
function RoleChip({ role, size = 'sm' }: { role: ForumRole; size?: 'xs' | 'sm' }) {
    const label = ROLE_CHIP[role];
    if (!label) return null;
    const tone = role === 'mayor'
        ? 'text-amber-300 bg-amber-500/15 border-amber-500/40'
        : 'text-emerald-300 bg-emerald-500/15 border-emerald-500/40';
    const dim = size === 'xs'
        ? 'text-[9px] px-1 py-0.5'
        : 'text-[10px] px-1.5 py-0.5';
    return (
        <span className={`shrink-0 inline-block font-bold uppercase tracking-wider rounded border ${tone} ${dim}`}>
            {label}
        </span>
    );
}

const ROLE_TEXT: Record<ForumRole, string> = {
    mayor: 'text-amber-400',
    aide: 'text-emerald-400',
    resident: 'text-slate-300',
};

const ROLE_RING: Record<ForumRole, string> = {
    mayor: 'ring-amber-500/40',
    aide: 'ring-emerald-500/40',
    resident: 'ring-slate-700',
};


// Laravel's paginator labels arrive with HTML entities (&laquo;, &raquo;) for the
// prev/next arrows. Decode the safe set so we can render as text instead of using
// dangerouslySetInnerHTML.
const PAGER_ENTITIES: Record<string, string> = {
    '&laquo;': '«',
    '&raquo;': '»',
    '&lsaquo;': '‹',
    '&rsaquo;': '›',
    '&amp;': '&',
};
function decodePagerLabel(label: string): string {
    return label.replace(/&(?:laquo|raquo|lsaquo|rsaquo|amp);/g, (m) => PAGER_ENTITIES[m] ?? m);
}

function timeAgo(iso: string): string {
    const then = new Date(iso).getTime();
    if (isNaN(then)) return '—';
    const diff = Math.max(0, (Date.now() - then) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 2592000) return `${Math.floor(diff / 86400)}d ago`;
    if (diff < 31536000) return `${Math.floor(diff / 2592000)}mo ago`;
    return `${Math.floor(diff / 31536000)}y ago`;
}

// Forum avatar: monogram fallback tinted to role.
function ForumAvatar({
    src, name, role, size = 40,
}: { src: string | null; name: string; role: ForumRole; size?: number }) {
    const initials = name.trim().slice(0, 1).toUpperCase() || '?';
    const fallback = role === 'mayor'
        ? 'bg-amber-500/15 text-amber-300'
        : role === 'aide'
            ? 'bg-emerald-500/15 text-emerald-300'
            : 'bg-slate-800 text-slate-300';
    const dim = { width: size, height: size };
    return src ? (
        <img
            src={src}
            alt={name}
            style={dim}
            className={`object-cover rounded-md shrink-0 ring-1 ${ROLE_RING[role]}`}
        />
    ) : (
        <div
            style={dim}
            className={`rounded-md shrink-0 ring-1 ${ROLE_RING[role]} flex items-center justify-center font-bold ${fallback}`}
        >
            <span style={{ fontSize: Math.round(size * 0.42) }}>{initials}</span>
        </div>
    );
}

const POSTS_PER_PAGE = 6;
const THREADS_PER_PAGE = 8;

// ── Numeric pager ─────────────────────────────────────────────────────────

function Pager({ page, totalPages, onChange }: { page: number; totalPages: number; onChange: (p: number) => void }) {
    if (totalPages <= 1) return null;

    const set = new Set<number>([1, totalPages, page - 1, page, page + 1]);
    const pages = Array.from(set).filter(p => p >= 1 && p <= totalPages).sort((a, b) => a - b);

    return (
        <div className="flex items-center justify-center gap-1 py-3 px-4 text-sm">
            <button
                onClick={() => onChange(Math.max(1, page - 1))}
                disabled={page === 1}
                className="px-3 py-1.5 rounded text-slate-300 hover:text-cyan-400 hover:bg-slate-800/60 disabled:opacity-30 disabled:cursor-not-allowed transition-colors font-medium"
            >
                ← Prev
            </button>
            {pages.map((p, i) => {
                const prev = pages[i - 1];
                const gap = prev !== undefined && p - prev > 1;
                return (
                    <span key={p} className="flex items-center gap-1">
                        {gap && <span className="text-slate-500 px-1">…</span>}
                        <button
                            onClick={() => onChange(p)}
                            className={`min-w-[36px] px-2.5 py-1.5 rounded tabular-nums font-semibold transition-colors ${p === page
                                ? 'bg-cyan-500/15 text-cyan-300 border border-cyan-500/50'
                                : 'text-slate-300 hover:text-cyan-400 hover:bg-slate-800/60 border border-transparent'
                                }`}
                        >
                            {p}
                        </button>
                    </span>
                );
            })}
            <button
                onClick={() => onChange(Math.min(totalPages, page + 1))}
                disabled={page === totalPages}
                className="px-3 py-1.5 rounded text-slate-300 hover:text-cyan-400 hover:bg-slate-800/60 disabled:opacity-30 disabled:cursor-not-allowed transition-colors font-medium"
            >
                Next →
            </button>
        </div>
    );
}

// ── Thread view ───────────────────────────────────────────────────────────

function ThreadView({
    post, citySlug, cityName, canReply, canModerate, onBack,
}: {
    post: ForumPost; citySlug: string; cityName: string;
    canReply: boolean; canModerate: boolean; onBack: () => void;
}) {
    const [replyText, setReplyText] = useState('');
    const [busy, setBusy] = useState(false);
    const [page, setPage] = useState(1);

    const allPosts = [
        {
            id: post.id, author: post.author, avatar: post.author_avatar,
            role: post.author_role, rank: post.author_rank,
            postCount: post.author_post_count,
            body: post.body, date: post.created_at, isOP: true,
        },
        ...post.replies.map(r => ({
            id: r.id, author: r.author, avatar: r.author_avatar,
            role: r.author_role, rank: r.author_rank,
            postCount: r.author_post_count,
            body: r.body, date: r.created_at, isOP: false,
        })),
    ];

    const totalPages = Math.max(1, Math.ceil(allPosts.length / POSTS_PER_PAGE));
    const safePage = Math.min(page, totalPages);
    const start = (safePage - 1) * POSTS_PER_PAGE;
    const visible = allPosts.slice(start, start + POSTS_PER_PAGE);

    const submitReply = () => {
        if (!replyText.trim() || busy || post.is_locked) return;
        setBusy(true);
        router.post(
            route('city.cityhall.forum.reply', { city: citySlug, post: post.id }),
            { body: replyText.trim() },
            {
                only: RELOAD_FORUM,
                preserveScroll: true,
                onSuccess: () => { setReplyText(''); setPage(totalPages); },
                onFinish: () => setBusy(false),
            },
        );
    };

    const quote = (author: string, body: string) => {
        const snippet = body.length > 500 ? body.slice(0, 500) + '…' : body;
        const block = `[q=${author}]${snippet}[/q]\n\n`;
        setReplyText(t => (t ? t + '\n' + block : block));
        document.getElementById('reply-composer')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    const togglePin = () => router.post(route('city.cityhall.forum.pin', { city: citySlug, post: post.id }), {}, { only: RELOAD_FORUM, preserveScroll: true });
    const toggleLock = () => router.post(route('city.cityhall.forum.lock', { city: citySlug, post: post.id }), {}, { only: RELOAD_FORUM, preserveScroll: true });
    const del = () => {
        router.delete(route('city.cityhall.forum.delete', { city: citySlug, post: post.id }), { only: RELOAD_FORUM, preserveScroll: true });
        onBack();
    };

    return (
        <div className="bg-slate-900/95 border border-slate-800 rounded-2xl shadow-2xl overflow-hidden">

            {/* ─── Breadcrumb ──────────────────────────────────────────── */}
            <div className="px-5 py-2.5 border-b border-slate-800 bg-slate-950/60 flex items-center gap-2 text-sm">
                <button onClick={onBack} className="text-slate-400 hover:text-cyan-400 transition-colors font-medium">
                    City Hall
                </button>
                <CaretRight size={12} weight="bold" className="text-slate-600" />
                <button onClick={onBack} className="text-slate-400 hover:text-cyan-400 transition-colors font-medium">
                    {cityName} Forum
                </button>
                <CaretRight size={12} weight="bold" className="text-slate-600" />
                <span className="text-slate-200 truncate font-semibold">{post.title}</span>
            </div>

            {/* ─── Centered thread title + meta ────────────────────────── */}
            <div className="px-5 py-4 border-b border-slate-800 relative">
                {/* Mod actions float to the right corner so they don't compete with the centered title */}
                {canModerate && (
                    <div className="absolute top-3 right-3 flex items-center gap-1.5">
                        <button
                            onClick={togglePin}
                            title={post.is_pinned ? 'Unpin' : 'Pin'}
                            className={`p-1.5 rounded transition-colors ${post.is_pinned
                                ? 'text-amber-300 bg-amber-500/15 border border-amber-500/40'
                                : 'text-slate-400 border border-slate-700 hover:text-amber-300 hover:border-amber-500/40'
                                }`}
                        >
                            <PushPin size={13} weight={post.is_pinned ? 'fill' : 'bold'} />
                        </button>
                        <button
                            onClick={toggleLock}
                            title={post.is_locked ? 'Unlock' : 'Lock'}
                            className={`p-1.5 rounded transition-colors ${post.is_locked
                                ? 'text-red-300 bg-red-500/15 border border-red-500/40'
                                : 'text-slate-400 border border-slate-700 hover:text-red-300 hover:border-red-500/40'
                                }`}
                        >
                            {post.is_locked ? <Lock size={13} weight="fill" /> : <LockOpen size={13} weight="bold" />}
                        </button>
                        <button
                            onClick={del}
                            title="Delete thread"
                            className="p-1.5 rounded text-slate-400 border border-slate-700 hover:text-red-300 hover:border-red-500/40 transition-colors"
                        >
                            <Trash size={13} weight="bold" />
                        </button>
                    </div>
                )}

                <div className="text-center">
                    <div className="flex items-center justify-center gap-2 mb-1.5">
                        {post.is_pinned && (
                            <PushPin size={14} weight="fill" className="text-amber-400" />
                        )}
                        {post.is_locked && (
                            <Lock size={14} weight="fill" className="text-red-400" />
                        )}
                        <h2 className="text-xl sm:text-2xl font-extrabold text-white leading-tight tracking-tight">
                            {post.title}
                        </h2>
                    </div>
                    <div className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-xs text-slate-400">
                        <span>
                            by <Link
                                href={route('profile.show', { displayName: post.author })}
                                className={`font-semibold no-underline hover:underline hover:underline-offset-2 ${ROLE_TEXT[post.author_role]}`}
                            >{post.author}</Link>
                        </span>
                        <span className="text-slate-600">·</span>
                        <span className="tabular-nums">
                            <span className="text-slate-300 font-medium">{post.reply_count + 1}</span> posts
                        </span>
                        <span className="text-slate-600">·</span>
                        <span className="tabular-nums">
                            <span className="text-slate-300 font-medium">{post.views}</span> views
                        </span>
                        <span className="text-slate-600">·</span>
                        <span>{timeAgo(post.created_at)}</span>
                    </div>
                </div>
            </div>

            {/* ─── Posts ───────────────────────────────────────────────── */}
            <div className="divide-y divide-slate-800">
                {visible.map((p, i) => {
                    const absoluteIndex = start + i + 1;
                    return (
                        <article key={p.id} className="flex flex-col nav:flex-row bg-slate-900/60">
                            {/* Mobile: horizontal author strip / Desktop: vertical rail on the left */}
                            <div className="nav:w-36 shrink-0 nav:border-r border-b nav:border-b-0 border-slate-800 bg-slate-950/40 px-3 py-2 nav:py-3 flex nav:flex-col items-center gap-3 nav:gap-0 nav:text-center">
                                <ForumAvatar src={p.avatar} name={p.author} role={p.role} size={44} />
                                <div className="flex-1 nav:flex-none nav:contents min-w-0">
                                    <Link
                                        href={route('profile.show', { displayName: p.author })}
                                        className="text-sm font-bold text-white truncate max-w-full nav:mt-2 no-underline hover:underline hover:underline-offset-2"
                                    >{p.author}</Link>
                                    <div className="nav:mt-1 flex justify-start nav:justify-center">
                                        <RoleChip role={p.role} />
                                    </div>
                                    {p.rank && (
                                        <p className="text-xs font-semibold text-slate-100 nav:mt-1 break-words leading-tight max-w-full">
                                            {p.rank}
                                        </p>
                                    )}
                                </div>
                                <div className="flex items-center gap-2 nav:flex-col nav:gap-1 nav:mt-auto nav:pt-2 shrink-0">
                                    {p.isOP && (
                                        <span className="text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded bg-cyan-500/15 text-cyan-300 border border-cyan-500/40">
                                            OP
                                        </span>
                                    )}
                                    <p className="text-[10px] uppercase tracking-wider text-slate-500 whitespace-nowrap">
                                        Posts: <span className="text-slate-300 tabular-nums">{p.postCount}</span>
                                    </p>
                                </div>
                            </div>

                            {/* Body */}
                            <div className="flex-1 min-w-0 flex flex-col">
                                <div className="flex items-center gap-2.5 px-4 py-1.5 border-b border-slate-800 bg-slate-950/40 text-xs flex-wrap">
                                    <span className="text-slate-400">
                                        <span className="text-slate-200 font-medium">{timeAgo(p.date)}</span>
                                    </span>
                                    <span className="text-slate-600 hidden sm:inline">·</span>
                                    <span className="text-slate-500 tabular-nums hidden sm:inline">{formatUTC(p.date, false)}</span>
                                    <span className="ml-auto text-slate-500 tabular-nums font-semibold">#{absoluteIndex}</span>
                                    {canReply && !post.is_locked && (
                                        <button
                                            onClick={() => quote(p.author, p.body)}
                                            title="Quote"
                                            className="flex items-center gap-1 px-1.5 py-0.5 rounded text-slate-400 hover:text-cyan-400 hover:bg-cyan-500/10 transition-colors font-medium"
                                        >
                                            <Quotes size={11} weight="bold" />
                                            Quote
                                        </button>
                                    )}
                                </div>
                                <div className="px-4 py-3 text-sm text-slate-100 leading-relaxed break-words flex-1">
                                    <ForumBBCode>{p.body}</ForumBBCode>
                                </div>
                            </div>
                        </article>
                    );
                })}
            </div>

            {/* ─── Bottom pager ───────────────────────────────────────── */}
            {totalPages > 1 && (
                <div className="border-t border-slate-800 bg-slate-950/40">
                    <Pager page={safePage} totalPages={totalPages} onChange={setPage} />
                </div>
            )}

            {/* ─── Locked notice ──────────────────────────────────────── */}
            {post.is_locked && (
                <div className="border-t border-red-500/30 bg-red-950/30 px-5 py-3 flex items-center gap-3">
                    <Lock size={16} weight="fill" className="text-red-400 shrink-0" />
                    <div>
                        <p className="text-sm font-bold text-red-300">Thread Locked</p>
                        <p className="text-xs text-red-400/80 mt-0.5">No further replies can be posted.</p>
                    </div>
                </div>
            )}

            {/* ─── Reply composer (compact, bottom) ───────────────────── */}
            {canReply && !post.is_locked && (
                <div id="reply-composer" className="border-t border-slate-800 bg-slate-950/40">
                    <div className="px-4 py-3 sm:px-5">
                        <div className="flex items-center gap-2 mb-2">
                            <PaperPlaneTilt size={12} weight="fill" className="text-cyan-400" />
                            <span className="text-xs font-bold text-cyan-400 uppercase tracking-wider">Reply</span>
                            <span className="ml-auto text-xs text-slate-500 tabular-nums">{replyText.length}/500</span>
                        </div>
                        <textarea
                            value={replyText}
                            onChange={e => setReplyText(e.target.value.slice(0, 500))}
                            placeholder="Write a reply… [b]bold[/b] [q=name]…[/q] [sp]hide[/sp]"
                            rows={3}
                            onWheel={e => e.currentTarget.focus()}
                            className="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500 resize-none focus:outline-none focus:border-cyan-500/60 leading-relaxed"
                        />
                        <div className="mt-2 flex items-end justify-between gap-3 flex-wrap">
                            <div className="flex-1 min-w-0">
                                <BBCodeLegend />
                            </div>
                            <button
                                onClick={submitReply}
                                disabled={!replyText.trim() || busy}
                                className="flex items-center gap-1.5 px-4 py-1.5 rounded-lg bg-cyan-500/20 border border-cyan-500/50 text-cyan-200 hover:bg-cyan-500/30 hover:text-cyan-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors text-xs font-bold uppercase tracking-wider shrink-0"
                            >
                                <PaperPlaneTilt size={12} weight="fill" />
                                {busy ? 'Posting…' : 'Post Reply'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

// ── Thread list row ───────────────────────────────────────────────────────

function ThreadRow({
    post, onOpen, canModerate, citySlug,
}: { post: ForumPost; onOpen: () => void; canModerate: boolean; citySlug: string; index: number }) {

    const togglePin = (e: React.MouseEvent) => {
        e.stopPropagation();
        router.post(route('city.cityhall.forum.pin', { city: citySlug, post: post.id }), {}, { only: RELOAD_FORUM, preserveScroll: true });
    };
    const toggleLock = (e: React.MouseEvent) => {
        e.stopPropagation();
        router.post(route('city.cityhall.forum.lock', { city: citySlug, post: post.id }), {}, { only: RELOAD_FORUM, preserveScroll: true });
    };
    const del = (e: React.MouseEvent) => {
        e.stopPropagation();
        router.delete(route('city.cityhall.forum.delete', { city: citySlug, post: post.id }), { only: RELOAD_FORUM, preserveScroll: true });
    };

    return (
        <div
            onClick={onOpen}
            className={`group flex items-stretch min-w-0 cursor-pointer transition-colors ${post.is_pinned
                ? 'bg-amber-500/[0.05] hover:bg-amber-500/[0.10]'
                : 'hover:bg-slate-800/40'
                }`}
        >
            {/* Author column */}
            <div className="w-14 nav:w-44 shrink-0 flex items-center gap-3 pl-3 nav:pl-4 pr-2 nav:pr-3 py-3 border-r border-slate-800">
                <ForumAvatar src={post.author_avatar} name={post.author} role={post.author_role} size={40} />
                <div className="min-w-0 flex-1 hidden nav:block">
                    <div className="flex items-center gap-1.5 min-w-0">
                        {/* Not a Link here — the whole row is clickable
                            (onClick={onOpen}). Profile links live inside
                            the opened thread instead. */}
                        <p className={`text-sm font-semibold truncate min-w-0 ${ROLE_TEXT[post.author_role]}`}>
                            {post.author}
                        </p>
                        <RoleChip role={post.author_role} size="xs" />
                    </div>
                    {post.author_rank && (
                        <p className="text-xs font-semibold text-slate-100 break-words leading-tight">
                            {post.author_rank}
                        </p>
                    )}
                </div>
            </div>

            {/* Title column */}
            <div className="flex-1 min-w-0 py-3 pr-3 nav:pr-4 pl-3 nav:pl-4 flex items-center gap-2">
                {post.is_pinned && (
                    <PushPin size={14} weight="fill" className="text-amber-400 shrink-0" />
                )}
                {post.is_locked && (
                    <Lock size={14} weight="fill" className="text-red-400 shrink-0" />
                )}
                <div className="min-w-0 flex-1">
                    <h3 className="text-base font-bold text-white leading-tight truncate group-hover:text-cyan-300 transition-colors">
                        {post.title}
                    </h3>
                    {/* On mobile, author appears here below title (since author column is icon-only).
                        On desktop, this collapses to just the timestamp. */}
                    <div className="text-xs text-slate-500 mt-0.5 flex items-center gap-1.5 min-w-0 flex-wrap">
                        <span className="nav:hidden flex items-center gap-1.5 min-w-0">
                            <span className={`truncate ${ROLE_TEXT[post.author_role]}`}>{post.author}</span>
                            <RoleChip role={post.author_role} size="xs" />
                            <span className="text-slate-700">·</span>
                        </span>
                        <span className="tabular-nums">{timeAgo(post.created_at)}</span>
                        <span className="nav:hidden tabular-nums">
                            <span className="text-slate-700 mx-1.5">·</span>
                            {post.reply_count + 1} posts
                        </span>
                    </div>
                </div>
            </div>

            {/* Posts / Views — only wide enough viewports (xl:+) so the title never gets squeezed */}
            <div className="hidden xl:flex w-24 shrink-0 flex-col items-center justify-center text-center border-l border-slate-800">
                <div className="flex items-baseline gap-1">
                    <span className="text-base font-bold text-white tabular-nums leading-none">
                        {post.reply_count + 1}
                    </span>
                    <span className="text-slate-600 text-sm">/</span>
                    <span className="text-sm text-slate-400 tabular-nums">
                        {post.views}
                    </span>
                </div>
                <p className="mt-1 text-[10px] uppercase tracking-wider text-slate-500 font-medium">posts/views</p>
            </div>

            {/* Last post — only at xl:+ so the title never gets squeezed */}
            <div className="hidden xl:flex w-48 shrink-0 items-center gap-2.5 pl-3 pr-3 border-l border-slate-800 py-3">
                <ForumAvatar src={post.last_reply_avatar} name={post.last_reply_author} role={post.last_reply_role} size={30} />
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-1.5 min-w-0">
                        <p className={`text-sm font-semibold truncate ${ROLE_TEXT[post.last_reply_role]}`}>
                            {post.last_reply_author}
                        </p>
                        <RoleChip role={post.last_reply_role} size="xs" />
                    </div>
                    <p className="text-xs text-slate-500 mt-0.5 tabular-nums" title={post.last_reply_rank ?? undefined}>
                        {timeAgo(post.last_reply_at)}
                    </p>
                </div>
            </div>

            {/* Moderation — desktop only (mobile users can mod from inside the thread) */}
            {canModerate && (
                <div className="hidden nav:flex w-24 shrink-0 items-center justify-center gap-1 border-l border-slate-800">
                    <button
                        onClick={togglePin}
                        title={post.is_pinned ? 'Unpin' : 'Pin'}
                        className={`p-1.5 rounded-md transition-colors ${post.is_pinned ? 'text-amber-300 bg-amber-500/15' : 'text-slate-400 hover:text-amber-300 hover:bg-amber-500/10'}`}
                    >
                        <PushPin size={13} weight={post.is_pinned ? 'fill' : 'bold'} />
                    </button>
                    <button
                        onClick={toggleLock}
                        title={post.is_locked ? 'Unlock' : 'Lock'}
                        className={`p-1.5 rounded-md transition-colors ${post.is_locked ? 'text-red-300 bg-red-500/15' : 'text-slate-400 hover:text-red-300 hover:bg-red-500/10'}`}
                    >
                        {post.is_locked ? <Lock size={13} weight="fill" /> : <LockOpen size={13} weight="bold" />}
                    </button>
                    <button
                        onClick={del}
                        title="Delete"
                        className="p-1.5 rounded-md text-slate-400 hover:text-red-300 hover:bg-red-500/10 transition-colors"
                    >
                        <Trash size={13} weight="bold" />
                    </button>
                </div>
            )}
        </div>
    );
}

// ── Forum panel (list view + compose) ─────────────────────────────────────

function ForumPanel({
    posts, citySlug, cityName, canPost, canModerate, mayor, aides,
    activeThread, setActiveThread,
}: {
    posts: ForumPost[]; citySlug: string; cityName: string;
    canPost: boolean; canModerate: boolean;
    mayor: Mayor | null; aides: Aide[];
    activeThread: ForumPost | null;
    setActiveThread: (t: ForumPost | null) => void;
}) {
    const [composing, setComposing] = useState(false);
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [busy, setBusy] = useState(false);
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);

    const refreshedActive = activeThread ? posts.find(p => p.id === activeThread.id) ?? null : null;

    const openThread = (p: ForumPost) => {
        setActiveThread(p);
        router.post(
            route('city.cityhall.forum.view', { city: citySlug, post: p.id }),
            {},
            { preserveScroll: true, preserveState: true, only: RELOAD_FORUM },
        );
    };

    const submit = () => {
        if (!title.trim() || !body.trim() || busy) return;
        setBusy(true);
        router.post(
            route('city.cityhall.forum.store', { city: citySlug }),
            { title: title.trim(), body: body.trim() },
            {
                only: RELOAD_FORUM,
                preserveScroll: true,
                onSuccess: () => { setComposing(false); setTitle(''); setBody(''); },
                onFinish: () => setBusy(false),
            },
        );
    };

    // Thread view — rendered standalone (the parent has already gone full-page).
    if (refreshedActive) {
        return (
            <ThreadView
                post={refreshedActive}
                citySlug={citySlug}
                cityName={cityName}
                canReply={canPost}
                canModerate={canModerate}
                onBack={() => setActiveThread(null)}
            />
        );
    }

    // Compose new thread
    if (composing) {
        return (
            <div>
                <div className="px-6 py-4 border-b border-slate-800 bg-slate-950/40 flex items-center gap-3">
                    <button onClick={() => setComposing(false)} className="p-2 -ml-2 rounded text-slate-300 hover:text-cyan-400 hover:bg-slate-800/60 transition-colors">
                        <CaretLeft size={16} weight="bold" />
                    </button>
                    <div>
                        <p className="text-xs font-bold text-cyan-400 uppercase tracking-wider">New Thread</p>
                        <h2 className="text-xl font-extrabold text-white">Start a Discussion</h2>
                    </div>
                </div>
                <div className="px-6 py-6 space-y-5 max-w-3xl">
                    <div className="space-y-2">
                        <label className="block text-sm font-bold text-slate-300 uppercase tracking-wider">Subject</label>
                        <input
                            autoFocus
                            value={title}
                            onChange={e => setTitle(e.target.value.slice(0, 30))}
                            placeholder="Thread title"
                            className="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 text-base font-semibold text-white placeholder:text-slate-500 focus:outline-none focus:border-cyan-500/60"
                        />
                        <span className="block text-xs text-slate-500 tabular-nums">{title.length}/30</span>
                    </div>
                    <div className="space-y-2">
                        <label className="block text-sm font-bold text-slate-300 uppercase tracking-wider">Message</label>
                        <textarea
                            value={body}
                            onChange={e => setBody(e.target.value.slice(0, 500))}
                            placeholder="Write your post…"
                            rows={8}
                            onWheel={e => e.currentTarget.focus()}
                            className="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 text-[15px] text-slate-100 placeholder:text-slate-500 resize-none focus:outline-none focus:border-cyan-500/60 leading-relaxed"
                        />
                        <span className="block text-xs text-slate-500 tabular-nums">{body.length}/500</span>
                    </div>
                    <BBCodeLegend />
                    <div className="flex items-center justify-end gap-2 pt-1">
                        <button onClick={() => setComposing(false)} className="px-4 py-2 text-sm font-bold text-slate-300 hover:text-white uppercase tracking-wider transition-colors">
                            Cancel
                        </button>
                        <button
                            onClick={submit}
                            disabled={!title.trim() || !body.trim() || busy}
                            className="flex items-center gap-2 px-5 py-2 rounded-lg bg-cyan-500/20 border border-cyan-500/50 text-cyan-200 hover:bg-cyan-500/30 hover:text-cyan-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors text-sm font-bold uppercase tracking-wider"
                        >
                            <PaperPlaneTilt size={13} weight="fill" />
                            {busy ? 'Posting…' : 'Post Thread'}
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    // Filter + paginate
    const filtered = posts.filter(p => {
        if (!query.trim()) return true;
        const q = query.trim().toLowerCase();
        return p.title.toLowerCase().includes(q) || p.author.toLowerCase().includes(q);
    });
    const totalPages = Math.max(1, Math.ceil(filtered.length / THREADS_PER_PAGE));
    const safePage = Math.min(page, totalPages);
    const start = (safePage - 1) * THREADS_PER_PAGE;
    const visible = filtered.slice(start, start + THREADS_PER_PAGE);
    return (
        <div>
            {/* Toolbar — search + New Thread only */}
            <div className="flex flex-col sm:flex-row sm:items-center gap-3 px-5 py-3 border-b border-slate-800 bg-slate-900/60">
                <div className="relative flex-1 min-w-0">
                    <MagnifyingGlass size={14} weight="bold" className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500" />
                    <input
                        value={query}
                        onChange={e => { setQuery(e.target.value); setPage(1); }}
                        placeholder="Search threads or authors…"
                        className="w-full bg-slate-900 border border-slate-700 rounded-lg pl-9 pr-3 py-2 text-sm text-slate-100 placeholder:text-slate-500 focus:outline-none focus:border-cyan-500/60"
                    />
                </div>
                {canPost && (
                    <button
                        onClick={() => setComposing(true)}
                        className="flex items-center gap-2 px-4 py-2 rounded-lg bg-cyan-500/20 border border-cyan-500/50 text-cyan-200 hover:bg-cyan-500/30 hover:text-cyan-100 transition-colors text-sm font-bold uppercase tracking-wider whitespace-nowrap"
                    >
                        <PlusCircle size={13} weight="fill" />
                        New Thread
                    </button>
                )}
            </div>

            {/* Column headers */}
            <div className="flex items-stretch border-b border-slate-800 bg-slate-950/60 text-xs font-bold uppercase tracking-wider text-slate-400">
                <div className="w-14 nav:w-44 shrink-0 py-2.5 pl-3 nav:pl-4 border-r border-slate-800">
                    <span className="hidden nav:inline">Author</span>
                </div>
                <div className="flex-1 py-2.5 pl-3 nav:pl-4 pr-4">Subject</div>
                <div className="hidden xl:flex w-24 shrink-0 items-center justify-center border-l border-slate-800">Posts/Views</div>
                <div className="hidden xl:flex w-48 shrink-0 items-center pl-3 border-l border-slate-800">Last Post</div>
                {canModerate && (
                    <div className="hidden nav:flex w-24 shrink-0 items-center justify-center border-l border-slate-800">Actions</div>
                )}
            </div>

            {/* Rows */}
            {visible.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-20 gap-4 px-6">
                    <ChatTeardrop size={36} weight="duotone" className="text-slate-600" />
                    <div className="text-center">
                        <p className="text-base font-bold text-slate-300">
                            {query.trim() ? 'No matching threads' : 'No threads yet'}
                        </p>
                        <p className="text-sm text-slate-500 mt-1">
                            {query.trim() ? `Nothing matches "${query}"` : 'Be the first to start a discussion.'}
                        </p>
                    </div>
                </div>
            ) : (
                <div className="divide-y divide-slate-800">
                    {visible.map((p, i) => (
                        <ThreadRow
                            key={p.id}
                            post={p}
                            index={i}
                            onOpen={() => openThread(p)}
                            canModerate={canModerate}
                            citySlug={citySlug}
                        />
                    ))}
                </div>
            )}

            {/* Bottom pager */}
            {totalPages > 1 && (
                <div className="border-t border-slate-800 bg-slate-950/40">
                    <Pager page={safePage} totalPages={totalPages} onChange={setPage} />
                </div>
            )}
        </div>
    );
}


// ─────────────────────────────────────────────────────────────────────────────
// Section: Relocation
// ─────────────────────────────────────────────────────────────────────────────

function RelocationPanel({
    relocation, citySlug, cityName, pendingApplications, canModerate,
}: { relocation: CityRelocationInfo; citySlug: string; cityName: string; pendingApplications?: PaginatedApplications | null; canModerate: boolean }) {
    const [busy, setBusy] = useState(false);
    const [busyAction, setBusyAction] = useState<number | null>(null);

    const canApply = relocation.cooldown_days_remaining === 0 && !relocation.pending_application;
    const isAlreadyHome = relocation.home_city === cityName;

    const approve = (id: number) => {
        if (busyAction) return;
        setBusyAction(id);
        router.post(
            route('city.cityhall.relocate.approve', { city: citySlug, character: id }),
            {},
            { only: RELOAD_APPLICATIONS, preserveScroll: true, onFinish: () => setBusyAction(null) }
        );
    };

    const deny = (id: number) => {
        if (busyAction) return;
        setBusyAction(id);
        router.post(
            route('city.cityhall.relocate.deny', { city: citySlug, character: id }),
            {},
            { only: RELOAD_APPLICATIONS, preserveScroll: true, onFinish: () => setBusyAction(null) }
        );
    };

    const submit = () => {
        if (!canApply || busy) return;
        setBusy(true);
        router.post(
            route('city.cityhall.relocate', { city: citySlug }),
            {},
            {
                only: ['relocation', 'is_home_city', 'is_resident', 'can_post_forum', ...SHARED_PROPS],
                preserveScroll: true,
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <div className="p-6 sm:p-8 space-y-6">
            {!canModerate && (
                <>
                    {/* Header */}
                    <div className="border-b border-cyan-500/20 pb-6">

                        <h2 className="text-2xl sm:text-3xl font-black text-white uppercase tracking-tight leading-none mb-3">
                            Apply for Relocation
                        </h2>
                        <p className="text-xs text-slate-500 font-bold uppercase tracking-widest">

                        </p>
                    </div>

                    {/* Status info */}
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        {[
                            { label: 'Current Home', value: relocation.home_city, accent: 'text-white' },
                            { label: 'Target City', value: cityName, accent: 'text-cyan-400' },
                            {
                                label: 'Status',
                                value: relocation.pending_application
                                    ? 'Pending'
                                    : isAlreadyHome
                                        ? 'Home'
                                        : canApply
                                            ? 'Ready'
                                            : `${relocation.cooldown_days_remaining}d cooldown`,
                                accent: relocation.pending_application
                                    ? 'text-amber-400'
                                    : isAlreadyHome
                                        ? 'text-emerald-400'
                                        : canApply
                                            ? 'text-cyan-400'
                                            : 'text-red-400',
                            },
                        ].map(r => (
                            <div key={r.label} className="bg-slate-900/50 border border-slate-700/40 rounded-xl p-4">
                                <p className="text-[9px] font-black text-slate-600 uppercase tracking-widest mb-2">{r.label}</p>
                                <p className={`text-sm font-black ${r.accent}`}>{r.value}</p>
                            </div>
                        ))}
                    </div>

                    {/* Info box */}
                    <div className="bg-gradient-to-br from-cyan-950/30 to-cyan-950/15 border border-cyan-500/20 rounded-2xl p-5 space-y-3">
                        <p className="text-[9px] font-black text-cyan-400 uppercase tracking-[0.3em]">What Happens</p>
                        <ul className="space-y-2">
                            {[
                                'Your registered home city changes to ' + cityName,
                                'You can vote in ' + cityName + ' elections',
                                'You pay income tax to ' + cityName,
                                'Your relocation is automatic after an hour if there is no mayor in office',
                                'You cannot relocate again for 1 day',
                            ].map((item, i) => (
                                <li key={i} className="flex items-start gap-2 text-xs text-slate-400">
                                    <div className="w-1 h-1 rounded-full bg-cyan-400 shrink-0 mt-1.5" />
                                    {item}
                                </li>
                            ))}
                        </ul>
                    </div>

                    {/* Status messages */}
                    {isAlreadyHome && (
                        <div className="flex items-center gap-3 px-5 py-4 bg-emerald-950/20 border border-emerald-500/25 rounded-2xl">
                            <CheckCircle size={18} className="text-emerald-400 shrink-0" weight="fill" />
                            <div>
                                <p className="text-sm font-black text-emerald-400 uppercase tracking-tight">Already Home</p>
                                <p className="text-[9px] text-emerald-400/70 mt-0.5">{cityName} is your current home city</p>
                            </div>
                        </div>
                    )}

                    {relocation.pending_application && !isAlreadyHome && (
                        <div className="flex items-center gap-3 px-5 py-4 bg-amber-950/20 border border-amber-500/25 rounded-2xl">
                            <Clock size={18} className="text-amber-400 shrink-0 animate-pulse" weight="fill" />
                            <div>
                                <p className="text-sm font-black text-amber-400 uppercase tracking-tight">Application Pending</p>
                                <p className="text-[9px] text-amber-400/70 mt-0.5">Your relocation request is being processed</p>
                            </div>
                        </div>
                    )}

                    {relocation.cooldown_days_remaining > 0 && !relocation.pending_application && !isAlreadyHome && (
                        <div className="flex items-center gap-3 px-5 py-4 bg-red-950/20 border border-red-500/25 rounded-2xl">
                            <Warning size={18} className="text-red-400 shrink-0" weight="fill" />
                            <div>
                                <p className="text-sm font-black text-red-400 uppercase tracking-tight">Cooldown Active</p>

                            </div>
                        </div>
                    )}

                    {/* Apply tile — compact, Hospital-style */}
                    {canApply && !isAlreadyHome && (
                        <div className="bg-slate-950/30 border border-slate-800 rounded-2xl p-5 space-y-3">
                            <p className="text-[10px] font-black text-slate-500 uppercase tracking-[0.3em]">Apply to Relocate</p>

                            <button
                                onClick={submit}
                                disabled={busy}
                                className={`w-fit px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest flex items-center justify-center gap-2 transition-all ${busy
                                    ? 'bg-slate-800/60 text-slate-600 cursor-not-allowed'
                                    : 'bg-gradient-to-r from-cyan-600 to-cyan-700 hover:from-cyan-500 hover:to-cyan-600 text-white shadow-lg shadow-cyan-900/30'
                                    }`}
                            >
                                {busy
                                    ? <><div className="w-3 h-3 border border-white/30 border-t-white rounded-full animate-spin" />Submitting…</>
                                    : <><House size={13} weight="bold" />Apply for Relocation</>
                                }
                            </button>
                        </div>
                    )}
                </>
            )}

            {/* Admin: Pending Applications */}
            {canModerate && (
                <div className={!canModerate ? "mt-8 pt-6 border-t border-white/[0.05]" : ""}>

                    {pendingApplications === undefined ? (
                        // Loaded on demand when this tab opens.
                        <div className="space-y-3" aria-busy="true">
                            {[0, 1, 2].map(i => (
                                <div key={i} className="h-20 rounded-xl bg-slate-900/40 border border-slate-700/40 animate-pulse" />
                            ))}
                        </div>
                    ) : pendingApplications && pendingApplications.data.length > 0 ? (
                        <>
                            <div className="space-y-3">
                                {pendingApplications.data.map(app => {
                                    const totalWealth = app.wealth.cash + app.wealth.property_value;
                                    return (
                                        <div key={app.id} className="flex flex-col sm:flex-row sm:items-center justify-between bg-slate-900/40 border border-slate-700/40 rounded-xl px-5 py-4 gap-4 transition-all hover:border-slate-600/50">
                                            <div className="flex-1 space-y-2.5">
                                                <div className="flex items-center justify-between">
                                                    <div className="flex items-center gap-2.5">
                                                        <p className="text-sm font-black text-white">{app.display_name}</p>

                                                        {app.convictions > 0 && (
                                                            <span className="px-2 py-0.5 rounded-md bg-red-500/10 border border-red-500/20 text-[9px] font-black text-red-400 uppercase tracking-widest leading-none flex items-center gap-1">
                                                                <Warning size={10} weight="bold" /> {app.convictions} Convictions
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-[10px] uppercase font-bold tracking-widest">
                                                    <div className="flex items-center gap-1.5 text-slate-400">
                                                        <span>{app.career}</span>
                                                    </div>
                                                    <div className="flex items-center gap-1.5 text-slate-400">
                                                        <span>{fmt$(totalWealth)} Net</span>
                                                    </div>
                                                    {app.wealth.corporation && (
                                                        <div className="flex items-center gap-1.5 text-slate-400">
                                                            <span className="truncate max-w-[120px]">{app.wealth.corporation}</span>
                                                        </div>
                                                    )}
                                                    {app.wealth.businesses.length > 0 && (
                                                        <div className="flex items-center gap-1.5 text-slate-400">
                                                            <span>{app.wealth.businesses.length} Biz</span>
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex sm:flex-col gap-2 shrink-0 w-full sm:w-auto mt-2 sm:mt-0">
                                                <button
                                                    onClick={() => approve(app.id)}
                                                    disabled={busyAction !== null}
                                                    className="flex-1 sm:flex-none w-full sm:w-28 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 hover:bg-emerald-500/20 hover:border-emerald-500/40 disabled:opacity-50 transition-all active:scale-95"
                                                >
                                                    Approve
                                                </button>
                                                <button
                                                    onClick={() => deny(app.id)}
                                                    disabled={busyAction !== null}
                                                    className="flex-1 sm:flex-none w-full sm:w-28 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest bg-red-500/10 text-red-400 border border-red-500/20 hover:bg-red-500/20 hover:border-red-500/40 disabled:opacity-50 transition-all active:scale-95"
                                                >
                                                    Deny
                                                </button>
                                            </div>
                                        </div>
                                    )
                                })}
                            </div>
                            {pendingApplications?.last_page && pendingApplications.last_page > 1 && (
                                <div className="flex justify-center mt-6">
                                    <div className="flex gap-1.5 bg-slate-900/60 p-1.5 rounded-xl border border-white/5 inline-flex">
                                        {pendingApplications?.links.map((link, i) => (
                                            <button
                                                key={i}
                                                onClick={() => link.url && router.get(link.url, {}, { preserveScroll: true, preserveState: true, only: ['pending_applications'] })}
                                                disabled={!link.url || link.active}
                                                className={`px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all ${link.active
                                                    ? 'bg-amber-500/20 text-amber-400'
                                                    : link.url
                                                        ? 'text-slate-500 hover:text-white hover:bg-white/5'
                                                        : 'text-slate-700 cursor-not-allowed hidden'
                                                    }`}
                                            >
                                                {decodePagerLabel(link.label)}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </>
                    ) : (
                        <div className="bg-slate-900/40 border border-slate-700/40 rounded-xl px-5 py-8 text-center shadow-inner">
                            <p className="text-sm font-black text-slate-500 uppercase tracking-widest">No Applications</p>
                            <p className="text-[10px] text-slate-600 mt-1 uppercase font-bold tracking-widest">There are no pending relocation requests to review</p>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Sidebar: Mayor card
// ─────────────────────────────────────────────────────────────────────────────

function MayorCard({ mayor }: { mayor: Mayor | null }) {
    return (
        <div className="bg-gradient-to-br from-amber-950/40 to-amber-950/20 border border-amber-500/25 rounded-2xl p-6 relative overflow-hidden shadow-lg">
            <div className="absolute top-0 right-0 w-32 h-32 bg-amber-500/10 rounded-full -mr-16 -mt-16 blur-2xl pointer-events-none" />
            <div className="relative">
                <div className="flex items-center gap-2 mb-5">
                    <div className="w-1 h-3 bg-amber-500 rounded-full" />
                    <h3 className="text-[9px] font-black text-amber-400 uppercase tracking-[0.3em]">Current Mayor</h3>
                </div>
                {mayor ? (
                    <div className="flex items-start gap-3.5">
                        <div className="relative shrink-0">
                            <Avatar src={mayor.avatar_url} name={mayor.name} size="lg" />
                            <div className="absolute -bottom-2 -right-2 w-6 h-6 bg-gradient-to-br from-amber-400 to-amber-600 rounded-full flex items-center justify-center border-2 border-amber-950/80 shadow-lg">
                                <Crown size={10} className="text-white" weight="fill" />
                            </div>
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-black text-white uppercase tracking-tight truncate">{mayor.name}</p>
                            <p className="text-[9px] text-amber-600/80 font-bold uppercase tracking-widest mt-1">
                                Period {mayor.term_period} of 4
                            </p>
                            <p className="text-[8px] text-amber-600/60 font-bold uppercase tracking-widest mt-0.5">
                                {mayor.term_days_remaining} days remaining
                            </p>
                        </div>
                    </div>
                ) : (
                    <div className="flex items-center gap-3.5 opacity-60">
                        <div className="w-12 h-12 rounded-2xl bg-slate-700/30 border border-white/5 flex items-center justify-center shrink-0">
                            <Crown size={22} className="text-slate-600" weight="fill" />
                        </div>
                        <div>
                            <p className="text-sm font-black text-slate-500 uppercase tracking-tight">Vacant</p>
                            <p className="text-[8px] text-slate-700 font-bold uppercase tracking-widest mt-1">Election pending</p>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Sidebar: Building Owner card
// ─────────────────────────────────────────────────────────────────────────────

function OwnerCard({ owner }: { owner: { name: string; avatar_url: string | null } | null }) {
    return (
        <div className="bg-gradient-to-br from-slate-900/50 to-slate-900/30 border border-slate-700/40 rounded-2xl p-6 shadow-lg">
            <div className="flex items-center gap-2 mb-5">
                <div className="w-1 h-3 bg-slate-600 rounded-full" />
                <h3 className="text-[9px] font-black text-slate-400 uppercase tracking-[0.3em]">Building Owner</h3>
            </div>
            {owner ? (
                <div className="flex items-start gap-3">
                    <Avatar src={owner.avatar_url} name={owner.name} size="md" />
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-black text-white uppercase tracking-tight truncate">{owner.name}</p>
                        <p className="text-[8px] text-slate-600 font-bold uppercase tracking-widest mt-1">City Hall Owner</p>
                    </div>
                </div>
            ) : (
                <div className="flex items-center gap-3 opacity-50">
                    <div className="w-10 h-10 rounded-xl bg-slate-800/40 border border-white/5 flex items-center justify-center shrink-0">
                        <Buildings size={18} className="text-slate-700" weight="fill" />
                    </div>
                    <div>
                        <p className="text-xs font-black text-slate-600 uppercase tracking-tight">Govt. Operated</p>
                        <p className="text-[8px] text-slate-700 font-bold uppercase tracking-widest mt-1">Public institution</p>
                    </div>
                </div>
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Sidebar: City Snapshot
// ─────────────────────────────────────────────────────────────────────────────

function CitySnapshotCard({ policies }: { policies: Policy | null }) {
    const items = policies ? [
        { label: 'Income Tax', value: `${policies.income_tax_rate}%`, color: 'text-white' },
        { label: 'Corp Tax', value: policies.corporate_tax_rate > 0 ? `+${policies.corporate_tax_rate}%` : 'None', color: 'text-white' },
        { label: 'Corp Reg', value: policies.corp_regulation_active ? 'Active' : 'Off', color: policies.corp_regulation_active ? 'text-red-400' : 'text-slate-600' },
        { label: 'Death Penalty', value: policies.death_sentence_active ? 'On' : 'Off', color: policies.death_sentence_active ? 'text-red-400' : 'text-slate-600' },
    ] : [];

    return (
        <div className="bg-gradient-to-br from-slate-900/40 to-slate-900/20 border border-slate-700/40 rounded-2xl p-6 shadow-lg">
            <div className="flex items-center gap-2 mb-5">
                <div className="w-1 h-3 bg-cyan-500 rounded-full" />
                <h3 className="text-[9px] font-black text-cyan-400 uppercase tracking-[0.3em]">City Snapshot</h3>
            </div>
            {policies ? (
                <div className="space-y-3">
                    {items.map((item, i) => (
                        <div key={i}>
                            {i > 0 && <div className="h-px bg-white/[0.04] my-3" />}
                            <div className="flex items-center justify-between px-0.5">
                                <span className="text-[9px] font-black text-slate-500 uppercase tracking-widest">{item.label}</span>
                                <span className={`text-sm font-black tabular-nums ${item.color}`}>{item.value}</span>
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <p className="text-xs text-slate-600 italic">No mayor in office</p>
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Sidebar: Aides
// ─────────────────────────────────────────────────────────────────────────────

function AidesCard({ aides, isMayor, citySlug }: { aides: Aide[]; isMayor: boolean; citySlug: string }) {
    const SLOTS = 4;
    const [appointing, setAppointing] = useState(false);
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(false);

    const appoint = () => {
        if (!name.trim() || busy) return;
        setBusy(true);
        router.post(
            route('city.cityhall.aide.appoint', { city: citySlug }),
            { character_name: name.trim() },
            { only: RELOAD_AIDES, preserveScroll: true, onFinish: () => { setBusy(false); setAppointing(false); setName(''); } },
        );
    };

    return (
        <div className="bg-gradient-to-br from-slate-900/40 to-slate-900/20 border border-slate-700/40 rounded-2xl p-6 shadow-lg">
            <div className="flex items-center gap-2 mb-5">
                <div className="w-1 h-3 bg-emerald-500 rounded-full" />
                <h3 className="text-[9px] font-black text-emerald-400 uppercase tracking-[0.3em]">Mayoral Aides</h3>
                <span className="ml-auto text-[9px] font-black text-slate-600 tabular-nums">{aides.length}/{SLOTS}</span>
            </div>
            <div className="space-y-2.5">
                {Array.from({ length: SLOTS }).map((_, i) => {
                    const aide = aides[i] ?? null;
                    return (
                        <div
                            key={i}
                            className={`flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all ${aide
                                ? 'bg-slate-800/30 border border-emerald-500/15 hover:border-emerald-500/30'
                                : 'border border-dashed border-slate-700/50 hover:border-slate-600'}`}
                        >
                            {aide ? (
                                <>
                                    <Avatar src={aide.avatar_url} name={aide.name} size="sm" />
                                    <span className="text-xs font-black text-white uppercase tracking-tight flex-1 truncate">{aide.name}</span>
                                    {isMayor && (
                                        <button
                                            onClick={() => router.delete(route('city.cityhall.aide.revoke', { city: citySlug, aide: aide.id }), { only: RELOAD_AIDES, preserveScroll: true })}
                                            className="p-1.5 rounded-lg text-slate-700 hover:text-red-400 hover:bg-red-500/10 transition-all"
                                        >
                                            <X size={11} weight="bold" />
                                        </button>
                                    )}
                                </>
                            ) : (
                                <span className="text-[9px] font-bold text-slate-700 uppercase tracking-widest">Slot {i + 1}</span>
                            )}
                        </div>
                    );
                })}
            </div>
            {isMayor && aides.length < SLOTS && (
                <div className="mt-4">
                    {!appointing ? (
                        <button
                            onClick={() => setAppointing(true)}
                            className="w-full flex items-center justify-center gap-2 py-2.5 rounded-lg text-[10px] font-black text-slate-600 hover:text-emerald-400 border border-dashed border-slate-700 hover:border-emerald-500/30 uppercase tracking-widest transition-all"
                        >
                            <PlusCircle size={12} weight="fill" />
                            Add Aide
                        </button>
                    ) : (
                        <div className="space-y-2 mt-2">
                            <input
                                autoFocus
                                value={name}
                                onChange={e => setName(e.target.value)}
                                placeholder="Character name…"
                                className="w-full bg-slate-950/60 border border-emerald-500/20 rounded-lg px-3 py-2.5 text-xs text-white placeholder:text-slate-600 focus:outline-none focus:border-emerald-500/40 focus:ring-1 focus:ring-emerald-500/10"
                            />
                            <div className="flex gap-2">
                                <button onClick={() => { setAppointing(false); setName(''); }}
                                    className="flex-1 py-2 text-[10px] font-black text-slate-600 uppercase tracking-widest hover:text-slate-400 transition-colors">
                                    Cancel
                                </button>
                                <button
                                    onClick={appoint}
                                    disabled={!name.trim() || busy}
                                    className="flex-1 py-2 rounded-lg text-[10px] font-black bg-emerald-500/20 border border-emerald-500/40 text-emerald-300 hover:bg-emerald-500/30 uppercase tracking-widest disabled:opacity-40 transition-all"
                                >
                                    {busy ? '…' : 'Appoint'}
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Owner Settings modal — City Hall building owner sets the per-post fee.
// Mirrors the Bank's OwnerModal pattern.
// ─────────────────────────────────────────────────────────────────────────────

function OwnerSettingsModal({
    citySlug, settings, onClose,
}: { citySlug: string; settings: ForumSettings; onClose: () => void }) {
    const [fee, setFee] = useState<number>(settings.post_fee || settings.fee_min);
    const [saving, setSaving] = useState(false);

    const save = () => {
        setSaving(true);
        router.post(
            route('city.cityhall.settings', { city: citySlug }),
            { post_fee: fee },
            {
                only: ['forum_settings', ...SHARED_PROPS],
                preserveScroll: true,
                onFinish: () => { setSaving(false); onClose(); },
            },
        );
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" onClick={onClose}>
            <div className="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" />
            <motion.div
                initial={{ scale: 0.95, opacity: 0 }}
                animate={{ scale: 1, opacity: 1 }}
                className="relative bg-slate-900 border border-slate-700 rounded-2xl p-6 w-full max-w-sm shadow-2xl"
                onClick={e => e.stopPropagation()}
            >
                <button onClick={onClose} className="absolute top-4 right-4 text-slate-500 hover:text-white transition-colors">
                    <X size={18} />
                </button>
                <div className="flex items-center gap-3 mb-6">
                    <div className="w-9 h-9 bg-slate-800 border border-slate-700 rounded-xl flex items-center justify-center">
                        <Gear size={16} weight="bold" className="text-slate-300" />
                    </div>
                    <div>
                        <h3 className="text-sm font-bold text-white">City Hall Settings</h3>
                        <p className="text-[10px] text-slate-500">Forum & bulletin posting fee</p>
                    </div>
                </div>
                <div className="space-y-5">
                    <div>
                        <div className="flex justify-between items-center mb-2">
                            <span className="text-xs font-semibold text-slate-300">Post Fee</span>
                            <span className="text-xs font-mono font-bold text-white bg-slate-800 border border-slate-700 px-2.5 py-0.5 rounded-lg tabular-nums">
                                ${fee}
                            </span>
                        </div>
                        <input
                            type="range"
                            min={settings.fee_min}
                            max={settings.fee_max}
                            step={5}
                            value={fee}
                            onChange={e => setFee(parseInt(e.target.value, 10))}
                            className="w-full h-1.5 bg-slate-800 rounded-full appearance-none cursor-pointer accent-cyan-400"
                        />
                        <div className="flex justify-between text-[10px] text-slate-500 mt-1.5">
                            <span className="tabular-nums">${settings.fee_min}</span>
                            <span className="text-slate-600">Charged on every announcement, thread, and reply</span>
                            <span className="tabular-nums">${settings.fee_max}</span>
                        </div>
                    </div>
                    <motion.button
                        whileTap={{ scale: 0.97 }}
                        onClick={save}
                        disabled={saving}
                        className={`w-full py-3 rounded-xl text-xs font-black uppercase tracking-widest flex items-center justify-center gap-2 transition-all ${saving
                            ? 'bg-slate-800/60 text-slate-600 cursor-not-allowed'
                            : 'bg-cyan-500/20 border border-cyan-500/50 text-cyan-200 hover:bg-cyan-500/30 hover:text-cyan-100'
                            }`}
                    >
                        {saving ? (
                            <><div className="w-3 h-3 border-2 border-slate-400/30 border-t-slate-400 rounded-full animate-spin" />Saving…</>
                        ) : 'Save'}
                    </motion.button>
                </div>
            </motion.div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Mock data
// ─────────────────────────────────────────────────────────────────────────────

const MOCK_PROPS: CityHallProps = {
    city: { name: 'New York', slug: 'new-york', image_url: null },
    mayor: { name: 'Victor Salazar', avatar_url: null, term_period: 2, term_days_remaining: 4 },
    aides: [
        { id: 1, name: 'Elena Cross', avatar_url: null },
        { id: 2, name: 'Marcus Vane', avatar_url: null },
    ],
    policies: {
        income_tax_rate: 12,
        corporate_tax_rate: 5,
        corp_regulation_active: true,
        bonds_active: false,
        death_sentence_active: false,
    },
    bulletin_posts: [
        {
            id: 1, author: 'Victor Salazar', author_avatar: null, author_role: 'mayor',
            author_rank: 'Mayor',
            body: 'Effective immediately: corporate regulation is active. All corporations operating within city limits are subject to audit at the discretion of the Commissioner.',
            pinned_at: '2026-03-09T14:00:00Z',
        },
        {
            id: 2, author: 'Elena Cross', author_avatar: null, author_role: 'aide',
            author_rank: 'Inspector',
            body: 'Relocation applications will be reviewed by the mayoral office throughout the day. Keep your records clean if you want approval.',
            pinned_at: '2026-03-10T08:30:00Z',
        },
    ],
    current_user_avatar: null,
    forum_posts: [
        {
            id: 1, character_id: 99, author: 'Ghost99', author_avatar: null, author_role: 'resident',
            author_rank: 'Constable',
            author_post_count: 47,
            title: 'The tax rate hike is killing my earnings',
            body: 'Income tax just went from 8% to 12% overnight. That\'s an extra $4k per session. Who voted for this guy?',
            reply_count: 2, views: 124, is_pinned: false, is_locked: false,
            created_at: '2026-03-10T06:00:00Z',
            last_reply_at: '2026-03-10T07:10:00Z',
            last_reply_author: 'Nadia K',
            last_reply_role: 'resident',
            last_reply_rank: 'Attorney',
            last_reply_avatar: null,
            replies: [
                { id: 1, character_id: 41, author: 'Reddock', author_avatar: null, author_role: 'resident', author_rank: 'Notary Public', author_post_count: 18, body: 'Welcome to politics. The mayor needs funds for the bond program apparently.', created_at: '2026-03-10T06:45:00Z' },
                { id: 2, character_id: 12, author: 'Nadia K', author_avatar: null, author_role: 'resident', author_rank: 'Attorney', author_post_count: 33, body: 'He\'ll be gone the moment assembly score drops. Give it a week.', created_at: '2026-03-10T07:10:00Z' },
            ],
        },
    ],
    forum_settings: { post_fee: 25, fee_min: 5, fee_max: 500 },
    relocation: { pending_application: false, home_city: 'Tokyo', cooldown_days_remaining: 0 },
    pending_applications: null,
    is_mayor: false,
    is_aide: false,
    is_resident: true,
    is_home_city: false,
    is_city_hall_owner: false,
    can_post_forum: true,
    owner: { name: 'Victor Salazar', avatar_url: null },
};

// ─────────────────────────────────────────────────────────────────────────────
// Root page
// ─────────────────────────────────────────────────────────────────────────────

export default function CityHall(props: CityHallProps = MOCK_PROPS) {
    const {
        city, mayor, aides, policies, bulletin_posts,
        current_user_avatar, forum_posts, forum_settings,
        relocation, pending_applications,
        is_mayor, is_aide, is_city_hall_owner, can_post_forum,
    } = props;

    const heroImage = city.image_url || getCityImage(city.name);
    const [activeTab, setActiveTab] = useState<TabKey>('bulletin');
    const [activeThread, setActiveThread] = useState<ForumPost | null>(null);
    const [ownerSettingsOpen, setOwnerSettingsOpen] = useState(false);

    const canPostAnnouncements = is_mayor || is_aide;
    const canModerate = is_mayor || is_aide;

    // Pending relocation applications are not part of the first response:
    // fetch them when a moderator opens the Relocation tab.
    const needsApplications = activeTab === 'relocation' && canModerate && pending_applications === undefined;
    useEffect(() => {
        if (needsApplications) {
            router.reload({ only: ['pending_applications'] });
        }
    }, [needsApplications]);

    // When the forum thread is open, the main panel takes the full width (sidebar hidden).
    const threadOpen = activeTab === 'forum' && activeThread !== null;

    // When a forum thread is open: full-page takeover.
    // Hero, tabs and sidebar all disappear; only the ThreadView is shown.
    if (threadOpen) {
        return (
            <>
                <Head title={`${activeThread!.title} — ${city.name} Forum`} />
                <div className="max-w-6xl mx-auto px-4 sm:px-6 py-5">
                    <ForumPanel
                        posts={forum_posts}
                        citySlug={city.slug}
                        cityName={city.name}
                        canPost={can_post_forum}
                        canModerate={canModerate}
                        mayor={mayor}
                        aides={aides}
                        activeThread={activeThread}
                        setActiveThread={setActiveThread}
                    />
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={`City Hall — ${city.name}`} />

            <div className="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-4">

                {/* ── Hero ── */}
                <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4 }}
                    className="relative rounded-t-2xl overflow-hidden border border-white/5 border-b-0 shadow-2xl shrink-0 min-h-[14rem] sm:min-h-[18rem] flex flex-col justify-end"
                >
                    <img
                        src={heroImage}
                        alt={city.name}
                        className="absolute inset-0 w-full h-full object-cover"
                    />
                    <div className="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-950/20 to-transparent" />

                    <div className="relative px-6 pt-8 pb-5 md:px-8 md:pb-6">
                        <div className="flex flex-col md:flex-row md:items-end justify-between gap-5">
                            <div className="max-w-2xl">
                                <h1 className="text-3xl md:text-4xl lg:text-5xl font-light text-white tracking-tight leading-none mb-3">
                                    City Hall
                                </h1>
                            </div>
                            <div className="flex items-stretch gap-2 md:gap-3 shrink-0">
                                <div className="flex flex-col items-end justify-center gap-1 bg-slate-900/60 border border-white/10 rounded-2xl px-5 py-2.5 backdrop-blur-xl">
                                    <div className="text-[8px] font-black uppercase text-slate-500 tracking-widest">Forum Topics</div>
                                    <div className="text-white font-black text-lg leading-none">{forum_posts.length}</div>
                                </div>
                                <div className="flex flex-col items-end justify-center gap-1 bg-slate-900/60 border border-white/10 rounded-2xl px-5 py-2.5 backdrop-blur-xl">
                                    <div className="text-[8px] font-black uppercase text-slate-500 tracking-widest">Post Fee</div>
                                    <div className="text-cyan-400 font-black text-lg leading-none tabular-nums">
                                        {forum_settings.post_fee > 0 ? `$${forum_settings.post_fee}` : 'Free'}
                                    </div>
                                </div>
                                {is_city_hall_owner && (
                                    <button
                                        onClick={() => setOwnerSettingsOpen(true)}
                                        title="City Hall Settings"
                                        className="bg-slate-900/60 border border-white/10 rounded-2xl px-4 py-2.5 backdrop-blur-xl text-slate-300 hover:text-cyan-300 hover:border-cyan-500/40 transition-colors"
                                    >
                                        <Gear size={18} weight="bold" />
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>
                </motion.div>

                {/* ── Tabs Navigation ── */}
                <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 0.15, duration: 0.4 }}
                    className="flex gap-2 p-2 bg-slate-900/95 border border-slate-800 rounded-2xl shadow-lg"
                >
                    {TABS.map(tab => {
                        const Icon = tab.icon;
                        const isActive = activeTab === tab.key;
                        return (
                            <button
                                key={tab.key}
                                onClick={() => setActiveTab(tab.key)}
                                className={`relative flex-1 flex items-center justify-center gap-2 py-3 px-2 rounded-xl text-xs font-bold uppercase tracking-wider transition-all duration-200 ${isActive
                                    ? 'bg-cyan-500/15 text-cyan-400 border border-cyan-500/40 shadow-lg shadow-cyan-900/20'
                                    : 'text-slate-400 hover:text-slate-200 border border-transparent hover:bg-slate-800/40'}`}
                            >
                                <Icon size={14} weight={isActive ? 'fill' : 'regular'} />
                                <span className="hidden sm:inline">{tab.label}</span>
                            </button>
                        );
                    })}
                </motion.div>

                {/* ── Content Grid Layout ── */}
                <div className="grid grid-cols-1 nav:grid-cols-4 gap-4 nav:gap-5">

                    {/* Main Content Panel */}
                    <motion.div
                        key={activeTab}
                        initial={{ opacity: 0, y: 12 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.3 }}
                        className={`col-span-1 nav:col-span-3 overflow-hidden ${activeTab === 'forum' || activeTab === 'bulletin'
                            ? 'bg-slate-900/95 border border-slate-800 rounded-2xl shadow-2xl'
                            : 'bg-slate-900/40 border border-slate-800/60 rounded-3xl shadow-xl'
                            }`}
                    >
                        <AnimatePresence mode="wait">
                            {activeTab === 'bulletin' && (
                                <BulletinPanel
                                    announcements={bulletin_posts}
                                    citySlug={city.slug}
                                    canPost={canPostAnnouncements}
                                    currentUserAvatar={current_user_avatar ?? null}
                                />
                            )}
                            {activeTab === 'forum' && (
                                <ForumPanel
                                    posts={forum_posts}
                                    citySlug={city.slug}
                                    cityName={city.name}
                                    canPost={can_post_forum}
                                    canModerate={canModerate}
                                    mayor={mayor}
                                    aides={aides}
                                    activeThread={activeThread}
                                    setActiveThread={setActiveThread}
                                />
                            )}
                            {activeTab === 'relocation' && (
                                <RelocationPanel
                                    relocation={relocation}
                                    citySlug={city.slug}
                                    cityName={city.name}
                                    pendingApplications={pending_applications}
                                    canModerate={canModerate}
                                />
                            )}
                        </AnimatePresence>
                    </motion.div>

                    {/* Right Sidebar */}
                    <motion.div
                        initial={{ opacity: 0, x: 20 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.25, duration: 0.4 }}
                        className="space-y-4"
                    >
                        <MayorCard mayor={mayor} />
                        <CitySnapshotCard policies={policies} />
                        <AidesCard aides={aides} isMayor={is_mayor} citySlug={city.slug} />
                    </motion.div>
                </div>
            </div>

            {/* Owner Settings modal */}
            <AnimatePresence>
                {ownerSettingsOpen && (
                    <OwnerSettingsModal
                        citySlug={city.slug}
                        settings={forum_settings}
                        onClose={() => setOwnerSettingsOpen(false)}
                    />
                )}
            </AnimatePresence>
        </>
    );
}

CityHall.layout = (page: React.ReactNode) => <GameLayout wide children={page} />;
