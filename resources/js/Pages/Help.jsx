import { useState, useEffect, useMemo, useCallback } from 'react';
import { Head } from '@inertiajs/react';
import GameLayout from '@/Layouts/GameLayout';
import MarkdownRenderer from '@/Components/MarkdownRenderer';
import ForumBBCode, { BBCodeLegend } from '@/Components/Forum/ForumBBCode';
import {
    BookOpen, ChatTeardrop, PaperPlaneTilt, PushPin, Lock,
    Plus, Sparkle, Shield, ArrowLeft, SpinnerGap, Pencil,
    CaretRight, CaretLeft, CaretUp, CaretDown, MagnifyingGlass, BookOpenIcon,
    User, Sword, Buildings, GraduationCap, Briefcase, Scroll,
    Star, Warning, Clock, ArrowRight, Trash, ArrowBendUpLeft,
    Check, ChatCircleDots, X, Article, TrendUp,
    DiscordLogo, Bug, Fire, ListChecks, Lightbulb,
} from '@phosphor-icons/react';


function getCategoryIcon(name) {
    const n = (name || '').toLowerCase();
    if (n.includes('bug')) return Bug;
    if (n.includes('changelog')) return ListChecks;
    if (n.includes('idea')) return Lightbulb;
    if (n.includes('popular')) return Fire;
    return BookOpenIcon;
}
import { formatUTC } from '@/Layouts/GameLayoutComponents';
import { useServerClock } from '@/contexts/ClockContext';

// ─── Constants ────────────────────────────────────────────────────────────────
const FORUM_PER_PAGE = 8;
const REPLIES_PER_PAGE = 5;
const DISCORD_INVITE_URL = 'https://discord.gg/esGpNynbER';

// ─── Utilities ────────────────────────────────────────────────────────────────
const getCsrf = () => {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
};
const postJson = (url, data = {}) => fetch(url, {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': getCsrf() },
    body: JSON.stringify(data),
});
const deleteJson = (url) => fetch(url, {
    method: 'DELETE', credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': getCsrf() },
});
const timeAgo = (ts, serverNow) => {
    const now = serverNow || Math.floor(Date.now() / 1000);
    const diff = now - ts;
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
    return formatUTC(ts, false) + ' UTC';
};

// ─── Micro-components ─────────────────────────────────────────────────────────

function Spinner({ size = 18 }) {
    return <SpinnerGap size={size} className="text-cyan-400 animate-spin" weight="bold" />;
}

// Fixed: single className + single style — no duplicate JSX attributes
function AvatarBubble({ src, name, isAdmin = false, size = 36 }) {
    const [err, setErr] = useState(false);
    const initials = name?.[0]?.toUpperCase() ?? '?';
    const cls = `shrink-0 rounded-full overflow-hidden flex items-center justify-center font-bold select-none border ${isAdmin ? 'border-red-500/40 bg-red-500/10' : 'border-slate-700/60 bg-slate-800'
        }`;
    return (
        <div className={cls} style={{ width: size, height: size, fontSize: Math.round(size * 0.38) }}>
            {src && !err
                ? <img src={src} className="w-full h-full object-cover" alt="" onError={() => setErr(true)} />
                : <span className={isAdmin ? 'text-red-400' : 'text-slate-400'}>{initials}</span>
            }
        </div>
    );
}

function StatusBadge({ type }) {
    const variants = {
        admin: 'bg-red-500/15 text-red-400 border-red-500/25',
        op: 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',
        pinned: 'bg-amber-500/10 text-amber-400 border-amber-500/20',
        locked: 'bg-slate-800 text-slate-500 border-slate-700/60',
    };
    const labels = { admin: 'Admin', op: 'OP', pinned: 'Pinned', locked: 'Locked' };
    const icons = { pinned: <PushPin size={9} weight="fill" />, locked: <Lock size={9} weight="fill" /> };
    return (
        <span className={`inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider rounded border ${variants[type]}`}>
            {icons[type]}{labels[type]}
        </span>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// FAQ PANEL
// ─────────────────────────────────────────────────────────────────────────────
function Pagination({ current, total, onChange }) {
    if (total <= 1) return null;
    const W = 5;
    let s = Math.max(0, current - Math.floor(W / 2));
    let e = Math.min(total - 1, s + W - 1);
    if (e - s + 1 < W) s = Math.max(0, e - W + 1);
    const pages = [];
    for (let i = s; i <= e; i++) pages.push(i);

    const Btn = ({ onClick, disabled, children }) => (
        <button onClick={onClick} disabled={disabled}
            className="w-8 h-8 flex items-center justify-center rounded-lg text-slate-500 hover:text-white hover:bg-slate-800 disabled:opacity-20 disabled:cursor-not-allowed transition-all">
            {children}
        </button>
    );

    return (
        <div className="flex items-center gap-1">
            <Btn onClick={() => onChange(current - 1)} disabled={current === 0}><CaretLeft size={14} weight="bold" /></Btn>
            {s > 0 && <span className="w-8 text-center text-slate-700 text-sm">…</span>}
            {pages.map(p => (
                <button key={p} onClick={() => onChange(p)}
                    className={`w-8 h-8 rounded-lg text-xs font-black transition-all ${p === current ? 'bg-cyan-600 text-white' : 'text-slate-500 hover:text-white hover:bg-slate-800'}`}>
                    {p + 1}
                </button>
            ))}
            {e < total - 1 && <span className="w-8 text-center text-slate-700 text-sm">…</span>}
            <Btn onClick={() => onChange(current + 1)} disabled={current >= total - 1}><CaretRight size={14} weight="bold" /></Btn>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// FORUM PANEL
// ─────────────────────────────────────────────────────────────────────────────

function ForumPanel() {
    const serverClock = useServerClock();
    const [loading, setLoading] = useState(false);
    const [categories, setCategories] = useState([]);
    const [posts, setPosts] = useState([]);
    const [isAdmin, setIsAdmin] = useState(false);
    const [viewerCharId, setViewerCharId] = useState(null);
    const [page, setPage] = useState(0);
    const [catFilter, setCatFilter] = useState(null);
    const [threadView, setThreadView] = useState(null);
    const [replies, setReplies] = useState([]);
    const [loadingReplies, setLR] = useState(false);
    const [replyPage, setReplyPage] = useState(0);
    const [replyBody, setReplyBody] = useState('');
    const [composing, setComposing] = useState(false);
    const [newTitle, setNewTitle] = useState('');
    const [newBody, setNewBody] = useState('');
    const [newCat, setNewCat] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [editingPostId, setEditingPostId] = useState(null);
    const [editPostBody, setEditPostBody] = useState('');
    const [editingReplyId, setEditingReplyId] = useState(null);
    const [editReplyBody, setEditReplyBody] = useState('');

    const fetchForum = useCallback(() => {
        setLoading(true);
        fetch('/forum/data', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                setCategories(data.categories || []);
                setPosts(data.posts || []);
                setIsAdmin(!!data.is_admin);
                setViewerCharId(data.viewer_character_id ?? null);
                if (data.categories?.length && !newCat) setNewCat(data.categories[0].id);
            })
            .catch(() => { })
            .finally(() => setLoading(false));
    }, []);
    useEffect(() => { fetchForum(); }, []);

    const fetchReplies = post => {
        setLR(true);
        fetch(`/forum/post/${post.id}/detail`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => { setReplies(data.replies || []); setReplyPage(0); })
            .catch(() => { })
            .finally(() => setLR(false));
    };

    const openThread = post => {
        setThreadView(post);
        setReplyBody('');
        setEditingPostId(null);
        setEditingReplyId(null);
        fetchReplies(post);
        setTimeout(() => window.scrollTo({ top: 0, behavior: 'smooth' }), 50);
    };

    const filtered = useMemo(() => {
        const p = catFilter ? posts.filter(x => x.categoryId === catFilter) : posts;
        return [...p].sort((a, b) => (a.isPinned && !b.isPinned) ? -1 : (!a.isPinned && b.isPinned) ? 1 : 0);
    }, [posts, catFilter]);

    const totalPages = Math.max(1, Math.ceil(filtered.length / FORUM_PER_PAGE));
    const safePage = Math.min(page, totalPages - 1);
    const paged = filtered.slice(safePage * FORUM_PER_PAGE, (safePage + 1) * FORUM_PER_PAGE);
    const replyTotalPages = Math.max(1, Math.ceil(replies.length / REPLIES_PER_PAGE));
    const safeReplyPage = Math.min(replyPage, replyTotalPages - 1);
    const pagedReplies = replies.slice(safeReplyPage * REPLIES_PER_PAGE, (safeReplyPage + 1) * REPLIES_PER_PAGE);

    const adminOnlyCat = useMemo(() =>
        !!categories.find(c => c.id === parseInt(newCat))?.admin_only,
        [categories, newCat]);
    const getCatName = id => categories.find(c => c.id === id)?.name ?? '';
    const isOwn = cId => viewerCharId && cId === viewerCharId;

    const submitPost = () => {
        const catId = parseInt(newCat) || categories[0]?.id;
        if (!catId || !newTitle.trim() || !newBody.trim() || submitting) return;
        setSubmitting(true);
        postJson('/forum', { category_id: catId, title: newTitle.trim(), body: newBody.trim() })
            .then(r => { if (!r.ok) throw r; })
            .then(() => { setNewTitle(''); setNewBody(''); setComposing(false); setPage(0); fetchForum(); })
            .catch(() => { })
            .finally(() => setSubmitting(false));
    };

    const submitReply = () => {
        if (!replyBody.trim() || !threadView || submitting) return;
        setSubmitting(true);
        postJson(`/forum/post/${threadView.id}/reply`, { body: replyBody.trim() })
            .then(r => { if (!r.ok) throw r; })
            .then(() => { setReplyBody(''); fetchReplies(threadView); })
            .catch(() => { })
            .finally(() => setSubmitting(false));
    };

    const saveEditPost = () => {
        if (!editPostBody.trim() || !threadView || submitting) return;
        setSubmitting(true);
        postJson(`/forum/post/${threadView.id}/edit`, { body: editPostBody.trim() })
            .then(r => r.json())
            .then(data => {
                if (data.success) { setThreadView(p => ({ ...p, body: data.body })); setEditingPostId(null); }
            })
            .catch(() => { })
            .finally(() => setSubmitting(false));
    };

    const saveEditReply = rId => {
        if (!editReplyBody.trim() || submitting) return;
        setSubmitting(true);
        postJson(`/forum/reply/${rId}/edit`, { body: editReplyBody.trim() })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    setReplies(p => p.map(r => r.id === rId ? { ...r, body: data.body } : r));
                    setEditingReplyId(null);
                }
            })
            .catch(() => { })
            .finally(() => setSubmitting(false));
    };

    // Direct delete — no confirmation modal
    const deletePost = id => {
        if (submitting) return;
        setSubmitting(true);
        deleteJson(`/forum/post/${id}`)
            .then(r => r.json())
            .then(data => { if (data.success) { setThreadView(null); fetchForum(); } })
            .catch(() => { })
            .finally(() => setSubmitting(false));
    };

    const deleteReply = id => {
        if (submitting) return;
        setSubmitting(true);
        deleteJson(`/forum/reply/${id}`)
            .then(r => r.json())
            .then(data => { if (data.success) setReplies(p => p.filter(r => r.id !== id)); })
            .catch(() => { })
            .finally(() => setSubmitting(false));
    };

    const quoteReply = r => {
        const stripped = r.body.replace(/\[quote=[^\]]*\][\s\S]*?\[\/quote\]/gi, '').trim();
        const truncated = stripped.length > 300 ? stripped.slice(0, 300).trimEnd() + '…' : stripped;
        setReplyBody(`[quote=${r.author}]${truncated}[/quote]\n\n`);
        setTimeout(() => document.getElementById('reply-composer')?.scrollIntoView({ behavior: 'smooth' }), 100);
    };

    const Crumb = ({ label, onClick }) => (
        <button onClick={onClick} className="text-slate-500 font-medium hover:text-cyan-400 transition-colors">{label}</button>
    );

    // ── Compose ───────────────────────────────────────────────────────────────
    // Flush composer matching City Hall's pattern: header strip with back
    // button + eyebrow + title, then form rows in a constrained max-w-3xl
    // column with inputs styled to match the rest of the game's forms.
    if (composing) return (
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
                    <label className="block text-sm font-bold text-slate-300 uppercase tracking-wider">Category</label>
                    <select
                        value={newCat}
                        onChange={e => setNewCat(parseInt(e.target.value))}
                        style={{ colorScheme: 'dark' }}
                        className="w-full sm:w-64 bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 text-sm text-slate-100 focus:outline-none focus:border-cyan-500/60"
                    >
                        {categories.map(c => <option key={c.id} value={c.id} className="bg-slate-900">{c.name}</option>)}
                    </select>
                </div>

                {adminOnlyCat && !isAdmin ? (
                    <div className="flex items-start gap-3 p-4 rounded-lg bg-amber-500/5 border border-amber-500/20">
                        <Lock size={14} weight="fill" className="text-amber-400 mt-0.5 shrink-0" />
                        <p className="text-sm text-slate-400">This category is admin-only.</p>
                    </div>
                ) : (
                    <>
                        <div className="space-y-2">
                            <label className="block text-sm font-bold text-slate-300 uppercase tracking-wider">Title</label>
                            <input
                                type="text"
                                value={newTitle}
                                onChange={e => setNewTitle(e.target.value)}
                                placeholder="Thread title"
                                maxLength={50}
                                className="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 text-base font-semibold text-white placeholder:text-slate-500 focus:outline-none focus:border-cyan-500/60"
                            />
                            <span className="block text-xs text-slate-500 tabular-nums">{newTitle.length}/50</span>
                        </div>

                        <div className="space-y-2">
                            <label className="block text-sm font-bold text-slate-300 uppercase tracking-wider">Message</label>
                            <textarea
                                value={newBody}
                                onChange={e => setNewBody(e.target.value)}
                                placeholder="Write your post…"
                                maxLength={5000}
                                rows={10}
                                onWheel={e => e.currentTarget.focus()}
                                className="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 text-[15px] text-slate-100 placeholder:text-slate-500 resize-none focus:outline-none focus:border-cyan-500/60 leading-relaxed"
                            />
                            <span className="block text-xs text-slate-500 tabular-nums">{newBody.length}/5000</span>
                        </div>

                        <BBCodeLegend isAdmin={isAdmin} />

                        <div className="flex items-center justify-end gap-2 pt-1">
                            <button
                                onClick={() => setComposing(false)}
                                className="px-4 py-2 text-sm font-bold text-slate-300 hover:text-white uppercase tracking-wider transition-colors"
                            >
                                Cancel
                            </button>
                            <button
                                onClick={submitPost}
                                disabled={newTitle.trim().length < 3 || newBody.trim().length < 10 || submitting}
                                className="flex items-center gap-2 px-5 py-2 rounded-lg bg-cyan-500/20 border border-cyan-500/50 text-cyan-200 hover:bg-cyan-500/30 hover:text-cyan-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors text-sm font-bold uppercase tracking-wider"
                            >
                                {submitting ? <Spinner size={13} /> : <PaperPlaneTilt size={13} weight="fill" />}
                                {submitting ? 'Posting…' : 'Post Thread'}
                            </button>
                        </div>
                    </>
                )}
            </div>
        </div>
    );

    // ── Thread view ───────────────────────────────────────────────────────────
    // Flush layout matching City Hall ThreadView:
    //   1. Breadcrumb strip (slate-950/60)
    //   2. Centered title + meta (by Author · N posts · time ago)
    //   3. divide-y posts with LEFT author rail (avatar/name/role on desktop,
    //      horizontal strip on mobile)
    //   4. Bottom pager
    //   5. Locked notice OR reply composer
    if (threadView) {
        const allPosts = [
            {
                id: threadView.id,
                body: threadView.body,
                author: threadView.author,
                authorAvatar: threadView.authorAvatar,
                authorRole: threadView.authorRole,
                characterId: threadView.characterId,
                createdAt: threadView.createdAt,
                isOp: true,
            },
            ...pagedReplies.map(r => ({
                id: r.id,
                body: r.body,
                author: r.author,
                authorAvatar: r.authorAvatar,
                authorRole: r.authorRole,
                characterId: r.characterId,
                createdAt: r.createdAt,
                isOp: false,
            })),
        ];

        const totalPosts = (replies.length || 0) + 1;

        return (
            <div>
                {/* Breadcrumb strip */}
                <div className="px-5 py-2.5 border-b border-slate-800 bg-slate-950/60 flex items-center gap-2 text-sm min-w-0">
                    <Crumb label="Forum" onClick={() => { setThreadView(null); setCatFilter(null); }} />
                    <CaretRight size={12} weight="bold" className="text-slate-600 shrink-0" />
                    <Crumb label={getCatName(threadView.categoryId)} onClick={() => { setThreadView(null); setCatFilter(threadView.categoryId); }} />
                    <CaretRight size={12} weight="bold" className="text-slate-600 shrink-0" />
                    <span className="text-slate-200 font-semibold truncate">{threadView.title}</span>
                </div>

                {/* Centered title + meta */}
                <div className="px-5 py-4 border-b border-slate-800 text-center">
                    <div className="flex items-center justify-center gap-2 mb-1.5">
                        {threadView.isPinned && <PushPin size={14} weight="fill" className="text-amber-400" />}
                        {threadView.isLocked && <Lock size={14} weight="fill" className="text-red-400" />}
                        <h2 className="text-xl sm:text-2xl font-extrabold text-white leading-tight tracking-tight">
                            {threadView.title}
                        </h2>
                    </div>
                    <div className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-xs text-slate-400">
                        <span>
                            by <span className={`font-semibold ${threadView.authorRole === 'admin' ? 'text-red-400' : 'text-slate-200'}`}>{threadView.author}</span>
                        </span>
                        <span className="text-slate-600">·</span>
                        <span className="tabular-nums">
                            <span className="text-slate-200 font-medium">{totalPosts}</span> posts
                        </span>
                        <span className="text-slate-600">·</span>
                        <span>{timeAgo(threadView.createdAt, serverClock)}</span>
                    </div>
                </div>

                {/* Posts */}
                <div className="divide-y divide-slate-800">
                    {loadingReplies ? (
                        <div className="flex justify-center py-12"><Spinner size={26} /></div>
                    ) : (
                        allPosts.map((p, i) => {
                            const absoluteIndex = p.isOp ? 1 : (safeReplyPage * REPLIES_PER_PAGE + i + 1);
                            const isEditingThis = p.isOp ? editingPostId === p.id : editingReplyId === p.id;
                            const editValue = p.isOp ? editPostBody : editReplyBody;
                            const onEditChange = p.isOp ? setEditPostBody : setEditReplyBody;
                            const onSaveEdit = p.isOp ? saveEditPost : () => saveEditReply(p.id);
                            const onCancelEdit = p.isOp
                                ? () => setEditingPostId(null)
                                : () => setEditingReplyId(null);
                            const onStartEdit = p.isOp
                                ? () => { setEditingPostId(p.id); setEditPostBody(p.body); }
                                : () => { setEditingReplyId(p.id); setEditReplyBody(p.body); };
                            const onDelete = p.isOp ? () => deletePost(p.id) : () => deleteReply(p.id);
                            const onQuote = !p.isOp && !threadView.isLocked
                                ? () => quoteReply(p)
                                : undefined;

                            return (
                                <PostCard
                                    key={p.id}
                                    body={p.body}
                                    author={p.author}
                                    authorAvatar={p.authorAvatar}
                                    authorRole={p.authorRole}
                                    characterId={p.characterId}
                                    createdAt={p.createdAt}
                                    postIndex={absoluteIndex}
                                    isOp={p.isOp}
                                    isEditing={isEditingThis}
                                    editBody={editValue}
                                    onEditChange={onEditChange}
                                    onSaveEdit={onSaveEdit}
                                    onCancelEdit={onCancelEdit}
                                    onStartEdit={onStartEdit}
                                    onDelete={onDelete}
                                    onQuote={onQuote}
                                    canEdit={isOwn(p.characterId) || isAdmin}
                                    submitting={submitting}
                                    serverClock={serverClock}
                                    opCharId={threadView.characterId}
                                />
                            );
                        })
                    )}
                </div>

                {replyTotalPages > 1 && (
                    <div className="border-t border-slate-800 bg-slate-950/40 flex justify-center py-3">
                        <Pagination current={safeReplyPage} total={replyTotalPages} onChange={setReplyPage} />
                    </div>
                )}

                {threadView.isLocked ? (
                    <div className="border-t border-red-500/30 bg-red-950/30 px-5 py-3 flex items-center gap-3">
                        <Lock size={16} weight="fill" className="text-red-400 shrink-0" />
                        <div>
                            <p className="text-sm font-bold text-red-300">Thread Locked</p>
                            <p className="text-xs text-red-400/80 mt-0.5">No further replies can be posted.</p>
                        </div>
                    </div>
                ) : (
                    <ReplyComposer
                        id="reply-composer"
                        value={replyBody}
                        onChange={setReplyBody}
                        onSubmit={submitReply}
                        submitting={submitting}
                        isAdmin={isAdmin}
                    />
                )}
            </div>
        );
    }

    // ── Category index ────────────────────────────────────────────────────────
    // Flush-inside-card: header strip + divide-y rows. No nested rounded
    // container — the outer panel card owns the border + radius.
    if (catFilter === null) return (
        <div>
            <div className="px-5 py-3 bg-slate-950/60 border-b border-slate-800">
                <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Boards</p>
            </div>
            {loading ? (
                <div className="flex justify-center py-20"><Spinner size={28} /></div>
            ) : (
                <div className="divide-y divide-slate-800">
                    {categories.map(cat => {
                        const catPosts = posts.filter(p => p.categoryId === cat.id);
                        const latest = [...catPosts].sort((a, b) => b.createdAt - a.createdAt)[0];
                        const Icon = getCategoryIcon(cat.name);
                        return (
                            <button
                                key={cat.id}
                                onClick={() => { setCatFilter(cat.id); setPage(0); }}
                                className="w-full text-left group flex items-center gap-4 px-5 py-4 hover:bg-slate-800/40 transition-colors"
                            >
                                <div className="w-10 h-10 rounded-xl bg-slate-800 border border-slate-700 flex items-center justify-center shrink-0 group-hover:border-cyan-500/40 group-hover:bg-cyan-500/10 transition-colors">
                                    <Icon size={18} weight="fill" className="text-slate-400 group-hover:text-cyan-400 transition-colors" />
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-base font-bold text-white group-hover:text-cyan-300 transition-colors">{cat.name}</p>
                                    <p className="text-xs text-slate-500 mt-0.5 truncate">{cat.description || `Discuss ${cat.name} with the community.`}</p>
                                </div>
                                <div className="hidden sm:flex flex-col items-end shrink-0 w-20 gap-0.5">
                                    <span className="text-base font-bold text-white tabular-nums">{catPosts.length}</span>
                                    <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">threads</span>
                                </div>
                                <div className="hidden md:flex flex-col shrink-0 w-44 text-right gap-0.5">
                                    {latest ? (
                                        <>
                                            <p className="text-xs font-semibold text-slate-300 truncate">{latest.title}</p>
                                            <p className="text-[10px] text-slate-500 tabular-nums">{timeAgo(latest.createdAt, serverClock)}</p>
                                        </>
                                    ) : (
                                        <span className="text-xs text-slate-600 italic">No posts yet</span>
                                    )}
                                </div>
                                <CaretRight size={14} weight="bold" className="text-slate-600 group-hover:text-cyan-400 shrink-0 transition-colors" />
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );

    // ── Thread list ───────────────────────────────────────────────────────────
    // Flush layout matches the City Hall ForumPanel:
    //   1. Toolbar bar (breadcrumb + New Thread button) — slate-900/60
    //   2. Column header row (Author / Subject / Posts / Last) — slate-950/60
    //   3. divide-y ThreadRow rows on the card surface
    //   4. Bottom pager strip — slate-950/40
    const currentCat = categories.find(c => c.id === catFilter);
    return (
        <div>
            {/* Toolbar */}
            <div className="flex flex-col sm:flex-row sm:items-center gap-3 px-5 py-3 border-b border-slate-800 bg-slate-900/60">
                <nav className="flex items-center gap-2 text-sm min-w-0 flex-1">
                    <Crumb label="Forum" onClick={() => setCatFilter(null)} />
                    <CaretRight size={12} weight="bold" className="text-slate-600 shrink-0" />
                    <span className="text-white font-bold truncate">{currentCat?.name}</span>
                </nav>
                <button
                    onClick={() => { setComposing(true); setNewCat(catFilter); }}
                    className="flex items-center gap-2 px-4 py-2 rounded-lg bg-cyan-500/20 border border-cyan-500/50 text-cyan-200 hover:bg-cyan-500/30 hover:text-cyan-100 transition-colors text-sm font-bold uppercase tracking-wider whitespace-nowrap"
                >
                    <Plus size={13} weight="bold" />
                    New Thread
                </button>
            </div>

            {loading ? (
                <div className="flex justify-center py-20"><Spinner size={28} /></div>
            ) : paged.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-20 gap-4 px-6">
                    <ChatTeardrop size={36} weight="duotone" className="text-slate-600" />
                    <div className="text-center">
                        <p className="text-base font-bold text-slate-300">No threads yet</p>
                        <p className="text-sm text-slate-500 mt-1">Be the first to start a discussion.</p>
                    </div>
                </div>
            ) : (
                <>
                    {/* Column headers */}
                    <div className="flex items-stretch border-b border-slate-800 bg-slate-950/60 text-xs font-bold uppercase tracking-wider text-slate-400">
                        <div className="w-14 sm:w-44 shrink-0 py-2.5 pl-3 sm:pl-4 border-r border-slate-800">
                            <span className="hidden sm:inline">Author</span>
                        </div>
                        <div className="flex-1 py-2.5 pl-3 sm:pl-4 pr-4">Subject</div>
                        <div className="hidden xl:flex w-24 shrink-0 items-center justify-center border-l border-slate-800">Posts</div>
                        <div className="hidden xl:flex w-44 shrink-0 items-center pl-3 border-l border-slate-800">Last Post</div>
                    </div>

                    {/* Rows */}
                    <div className="divide-y divide-slate-800">
                        {paged.map(post => (
                            <ThreadRow key={post.id} post={post} onClick={() => openThread(post)} serverClock={serverClock} />
                        ))}
                    </div>
                </>
            )}

            {totalPages > 1 && (
                <div className="border-t border-slate-800 bg-slate-950/40 flex justify-center py-3">
                    <Pagination current={safePage} total={totalPages} onChange={setPage} />
                </div>
            )}
        </div>
    );
}

// ── ThreadRow ─────────────────────────────────────────────────────────────────
// Mirrors the City Hall ThreadRow layout (Author | Title | Posts | Last Post)
// since that's the pattern players already recognize from phpBB/vBulletin
// forums. Author column has a fixed width with name + admin chip; title
// column flexes to fill remaining space with pin/lock as leading icons
// (not afterthought chips); posts and last-post columns only appear at
// xl: so the title never gets squeezed on mid-width screens.
function ThreadRow({ post, onClick, serverClock }) {
    return (
        <div
            onClick={onClick}
            className={`group flex items-stretch min-w-0 cursor-pointer transition-colors ${post.isPinned
                ? 'bg-amber-500/[0.05] hover:bg-amber-500/[0.10]'
                : 'hover:bg-slate-800/40'
                }`}
        >
            {/* Author column — icon-only on mobile, full on desktop */}
            <div className="w-14 sm:w-44 shrink-0 flex items-center gap-3 pl-3 sm:pl-4 pr-2 sm:pr-3 py-3 border-r border-slate-800">
                <AvatarBubble src={post.authorAvatar} name={post.author} isAdmin={post.authorRole === 'admin'} size={38} />
                <div className="min-w-0 flex-1 hidden sm:block">
                    <p className={`text-sm font-semibold truncate min-w-0 ${post.authorRole === 'admin' ? 'text-red-400' : 'text-slate-200'}`}>
                        {post.author}
                    </p>
                    {post.authorRole === 'admin' && (
                        <p className="text-[10px] font-bold uppercase tracking-wider text-red-400/80 mt-0.5">
                            Admin
                        </p>
                    )}
                </div>
            </div>

            {/* Title column — pin/lock as leading icons (not afterthought chips) */}
            <div className="flex-1 min-w-0 py-3 pr-3 sm:pr-4 pl-3 sm:pl-4 flex items-center gap-2">
                {post.isPinned && (
                    <PushPin size={14} weight="fill" className="text-amber-400 shrink-0" />
                )}
                {post.isLocked && (
                    <Lock size={14} weight="fill" className="text-red-400 shrink-0" />
                )}
                <div className="min-w-0 flex-1">
                    <h3 className="text-base font-bold text-white leading-tight truncate group-hover:text-cyan-300 transition-colors">
                        {post.title}
                    </h3>
                    {/* On mobile, author appears here below title (since author column is icon-only). */}
                    <div className="text-xs text-slate-500 mt-0.5 flex items-center gap-1.5 min-w-0 flex-wrap">
                        <span className="sm:hidden flex items-center gap-1.5 min-w-0">
                            <span className={`truncate ${post.authorRole === 'admin' ? 'text-red-400' : 'text-slate-400'}`}>
                                {post.author}
                            </span>
                            <span className="text-slate-700">·</span>
                        </span>
                        <span className="tabular-nums">{timeAgo(post.createdAt, serverClock)}</span>
                        <span className="sm:hidden tabular-nums">
                            <span className="text-slate-700 mx-1.5">·</span>
                            {(post.replyCount || 0) + 1} posts
                        </span>
                    </div>
                </div>
            </div>

            {/* Posts column — only at xl+ so the title never gets squeezed */}
            <div className="hidden xl:flex w-24 shrink-0 flex-col items-center justify-center text-center border-l border-slate-800">
                <span className="text-base font-bold text-white tabular-nums leading-none">
                    {(post.replyCount || 0) + 1}
                </span>
                <p className="mt-1 text-[10px] uppercase tracking-wider text-slate-500 font-medium">posts</p>
            </div>

            {/* Last activity — only at xl+ */}
            <div className="hidden xl:flex w-44 shrink-0 items-center gap-2 pl-3 pr-3 border-l border-slate-800 py-3">
                <Clock size={12} className="text-slate-600 shrink-0" />
                <p className="text-xs text-slate-400 tabular-nums truncate">
                    {timeAgo(post.lastActivityAt || post.createdAt, serverClock)}
                </p>
            </div>
        </div>
    );
}

// ── PostCard ──────────────────────────────────────────────────────────────────
// Header-bar layout (NOT the city-hall left-rail). Community-forum posts are
// long-form essays, sometimes thousands of characters with embedded images.
// A 100px-tall author rail next to a 600px-tall body leaves a giant empty
// column. Discord / Reddit / Medium / Substack all use this same pattern:
//
//   ┌─────────────────────────────────────────────┐
//   │ ◐ Author  ADMIN OP        time · #N · quote │  ← compact header bar
//   ├─────────────────────────────────────────────┤
//   │                                             │
//   │   full-width body, no left rail eating      │
//   │   horizontal space, grows naturally with    │
//   │   content, images render at full container  │
//   │   width                                     │
//   │                                             │
//   ├─────────────────────────────────────────────┤
//   │  ✎ Edit   🗑 Delete                          │  ← action bar (if owner)
//   └─────────────────────────────────────────────┘
//
// Each PostCard sits as a row inside the parent's divide-y, so we don't add
// our own border/rounded — the divider lines + outer panel card own that.
// Single threshold: anything over N characters gets clamped to the first
// N characters of the body, with a "Show more" toggle revealing the rest.
// One number — same value drives both decisions, because they ARE the
// same decision (when do we cut, and where do we cut).
const COLLAPSED_BODY_CHARS = 1000;

// Slice a body to roughly N chars without breaking a BBCode tag. We cut
// at the last newline-or-space at-or-before the limit. If we would land
// inside an open tag bracket (e.g. mid-"[img]https://..."), we retreat
// to the last `]` so the renderer never sees a half-tag.
function clampBody(body, limit) {
    if (body.length <= limit) return body;
    let cut = body.lastIndexOf('\n', limit);
    if (cut < limit / 2) cut = body.lastIndexOf(' ', limit);
    if (cut < limit / 2) cut = limit;
    const tail = body.slice(0, cut);
    const lastOpen = tail.lastIndexOf('[');
    const lastClose = tail.lastIndexOf(']');
    if (lastOpen > lastClose) cut = lastClose + 1;
    return body.slice(0, cut).trimEnd();
}

function PostCard({
    body, author, authorAvatar, authorRole, characterId, createdAt, postIndex,
    isOp, isEditing, editBody, onEditChange, onSaveEdit, onCancelEdit, onStartEdit,
    onDelete, onQuote, canEdit, submitting, serverClock, opCharId,
}) {
    const adminRole = authorRole === 'admin';
    const isOpAuthor = isOp || characterId === opCharId;

    // Character-length clamp. If the raw body exceeds COLLAPSED_BODY_CHARS,
    // we render it in a fixed-height container with a fade + Show more
    // toggle. Cheap, deterministic, no DOM measurement, no race with
    // image-load timing. Short posts skip the toggle entirely.
    const overflows = !isEditing && (body?.length ?? 0) > COLLAPSED_BODY_CHARS;
    const [expanded, setExpanded] = useState(false);

    return (
        <article className="bg-slate-900/60">
            {/* Compact author header bar — full width, fixed height */}
            <header className="flex items-center gap-3 px-4 sm:px-5 py-2.5 border-b border-slate-800 bg-slate-950/40">
                <AvatarBubble src={authorAvatar} name={author} isAdmin={adminRole} size={32} />
                <div className="flex items-center gap-2 min-w-0">
                    <span className={`text-sm font-bold truncate ${adminRole ? 'text-red-400' : 'text-white'}`}>
                        {author}
                    </span>
                    {adminRole && <StatusBadge type="admin" />}
                    {isOpAuthor && <StatusBadge type="op" />}
                </div>
                <div className="ml-auto flex items-center gap-3 shrink-0 text-xs">
                    <span className="text-slate-400 tabular-nums">{timeAgo(createdAt, serverClock)}</span>
                    <span className="text-slate-600 hidden sm:inline">·</span>
                    <span className="text-slate-500 tabular-nums font-mono hidden sm:inline">#{postIndex}</span>
                    {onQuote && (
                        <button
                            onClick={onQuote}
                            title="Quote"
                            className="flex items-center gap-1 px-2 py-0.5 rounded text-slate-400 hover:text-cyan-400 hover:bg-cyan-500/10 transition-colors font-medium"
                        >
                            <ArrowBendUpLeft size={11} weight="bold" />
                            <span className="hidden sm:inline">Quote</span>
                        </button>
                    )}
                </div>
            </header>

            {/* Body or editor — full width, no rail eating horizontal space.
                OPs cap at 5000, replies at 1000 — matches the backend
                validation (ForumController::store / ::reply). */}
            {isEditing ? (
                <div className="px-4 sm:px-5 py-4 space-y-3">
                    <textarea
                        value={editBody}
                        onChange={e => onEditChange(e.target.value)}
                        maxLength={isOp ? 5000 : 1000}
                        rows={10}
                        className="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500 outline-none focus:border-cyan-500/60 leading-relaxed resize-none"
                    />
                    <div className="flex gap-2">
                        <button
                            onClick={onSaveEdit}
                            disabled={!editBody.trim() || submitting}
                            className="flex items-center gap-1.5 px-4 py-1.5 rounded-lg bg-cyan-500/20 border border-cyan-500/50 text-cyan-200 hover:bg-cyan-500/30 hover:text-cyan-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors text-xs font-bold uppercase tracking-wider"
                        >
                            {submitting ? <Spinner size={12} /> : <Check size={12} weight="bold" />}
                            Save
                        </button>
                        <button
                            onClick={onCancelEdit}
                            className="px-4 py-1.5 text-xs font-bold uppercase tracking-wider text-slate-400 hover:text-white transition-colors"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            ) : (
                <div className="px-4 sm:px-5 py-3 text-[14px] text-slate-100 leading-[1.6] break-words">
                    {/* Render either the safe-sliced body (collapsed) or
                        the full body (expanded / short post). The slice IS
                        the preview — no separate maxHeight clamp, no
                        guessing where the visible window ends. */}
                    <ForumBBCode>
                        {overflows && !expanded ? clampBody(body, COLLAPSED_BODY_CHARS) : body}
                    </ForumBBCode>
                    {overflows && (
                        <button
                            onClick={() => setExpanded(v => !v)}
                            className="mt-2 flex items-center gap-1 px-2 py-1 rounded text-xs font-bold uppercase tracking-wider text-cyan-400 hover:text-cyan-300 hover:bg-cyan-500/10 transition-colors"
                        >
                            {expanded ? <CaretUp size={11} weight="bold" /> : <CaretDown size={11} weight="bold" />}
                            {expanded ? 'Show less' : 'Show more'}
                        </button>
                    )}
                </div>
            )}

            {/* Edit/Delete action strip (owner / admin only) */}
            {!isEditing && canEdit && (
                <div className="flex items-center gap-3 px-4 sm:px-5 py-1.5 border-t border-slate-800 bg-slate-950/40 text-xs">
                    <button
                        onClick={onStartEdit}
                        className="flex items-center gap-1 px-1.5 py-0.5 rounded text-slate-400 hover:text-cyan-400 hover:bg-cyan-500/10 transition-colors font-medium"
                    >
                        <Pencil size={11} weight="bold" /> Edit
                    </button>
                    <button
                        onClick={onDelete}
                        disabled={submitting}
                        className="flex items-center gap-1 px-1.5 py-0.5 rounded text-slate-400 hover:text-red-400 hover:bg-red-500/10 disabled:opacity-40 transition-colors font-medium"
                    >
                        <Trash size={11} weight="bold" /> Delete
                    </button>
                </div>
            )}
        </article>
    );
}

// ── ReplyComposer ─────────────────────────────────────────────────────────────
// Flush strip at the bottom of the thread view — no rounded child container.
// Mirrors the City Hall reply composer: small icon+label header, compact
// textarea, BBCode chips + char count + post button on one row.
function ReplyComposer({ id, value, onChange, onSubmit, submitting, isAdmin }) {
    return (
        <div id={id} className="border-t border-slate-800 bg-slate-950/40">
            <div className="px-4 py-2 sm:px-5">
                {/* Header strip: Reply label + char count + Post button on one row.
                    Putting the button HERE (not at the bottom) saves the height
                    of a second action row. */}
                <div className="flex items-center gap-2 mb-2">
                    <PaperPlaneTilt size={12} weight="fill" className="text-cyan-400" />
                    <span className="text-xs font-bold text-cyan-400 uppercase tracking-wider">Reply</span>
                    <span className="ml-auto text-xs text-slate-500 tabular-nums">{value.length}/1000</span>
                    <button
                        onClick={onSubmit}
                        disabled={!value.trim() || submitting}
                        className="flex items-center gap-1.5 px-3 py-1 rounded bg-cyan-500/20 border border-cyan-500/50 text-cyan-200 hover:bg-cyan-500/30 hover:text-cyan-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors text-xs font-bold uppercase tracking-wider shrink-0"
                    >
                        {submitting ? <Spinner size={11} /> : <PaperPlaneTilt size={11} weight="fill" />}
                        {submitting ? 'Posting…' : 'Post'}
                    </button>
                </div>
                {/* rows={2} — compact starting height, user can grow it with
                    the resize handle if a long reply needs more room. */}
                <textarea
                    value={value}
                    onChange={e => onChange(e.target.value)}
                    placeholder="Write a reply… [b]bold[/b] [q=name]…[/q] [sp]hide[/sp]"
                    maxLength={1000}
                    rows={2}
                    onWheel={e => e.currentTarget.focus()}
                    className="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500 resize-y focus:outline-none focus:border-cyan-500/60 leading-relaxed"
                />
                {/* BBCode legend on its own row below — no horizontal squeeze
                    competing with the button anymore. */}
                <div className="mt-1.5">
                    <BBCodeLegend isAdmin={isAdmin} />
                </div>
            </div>
        </div>
    );
}

// BBCodeTags was removed — the shared <BBCodeLegend> from
// @/Components/Forum/ForumBBCode now renders the same inline strip of tag
// chips for both city hall and the community forum, so the formatting help
// looks identical across the two forums.

// ─────────────────────────────────────────────────────────────────────────────
// ROOT PAGE
// ─────────────────────────────────────────────────────────────────────────────

export default function Help() {
    return (
        <>
            <Head title="Community Forum — The Director" />

            {/* Match the City Hall shell pattern exactly:
                  * Tall hero (14-18rem) with bottom-anchored title
                  * Main panel below wrapped in ONE outer card
                    (bg-slate-900/95 rounded-2xl shadow-2xl). All toolbar /
                    columns / rows are flat siblings inside it, divided by
                    border lines — never stacked translucent rectangles.
                The ForumPanel itself owns the inner toolbar, column headers,
                divide-y rows and pager (same shape as ForumPanel in
                CityHall.tsx). */}
            {/* max-w-[64rem] = 1024px, ~10% narrower than the prior max-w-6xl
                (72rem / 1152px). The whole panel feels tighter without any
                element-by-element scaling — type stays sharp and the
                outer card still has comfortable side gutters. */}
            <div className="max-w-[64rem] mx-auto px-4 sm:px-6 py-5 space-y-4">
                {/* Hero — background image is /biggerbanner.png from public/.
                    Anything in public/ ships unchanged through Cloud Run +
                    Cloudflare in prod, so this URL is stable across envs. */}
                <div className="relative rounded-2xl overflow-hidden border border-white/5 shadow-2xl min-h-[14rem] sm:min-h-[18rem] flex flex-col justify-end">
                    <img
                        src="/biggerbanner.png"
                        alt=""
                        aria-hidden
                        className="absolute inset-0 w-full h-full object-cover"
                    />
                    {/* Dark gradient so the title stays readable on top of the image */}
                    <div className="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-950/40 to-slate-950/30" />

                    <div className="relative px-6 pt-8 pb-5 md:px-8 md:pb-6">
                        <div className="flex flex-col md:flex-row md:items-end justify-between gap-5">
                            <div className="flex items-end gap-4 max-w-2xl">
                                <div className="hidden sm:flex w-14 h-14 rounded-2xl bg-cyan-500/10 border border-cyan-500/30 items-center justify-center shrink-0 backdrop-blur-sm">
                                    {/* Game logo from public/favicon.ico — same asset
                                        the browser tab uses, so it's already familiar. */}
                                    <img
                                        src="/favicon.ico"
                                        alt=""
                                        aria-hidden
                                        className="w-8 h-8"
                                    />
                                </div>
                                <div>

                                    <h1 className="text-3xl md:text-4xl lg:text-5xl font-light text-white tracking-tight leading-none drop-shadow-lg">
                                        Community Forum
                                    </h1>
                                </div>
                            </div>
                            <a
                                href={DISCORD_INVITE_URL}
                                target="_blank"
                                rel="noreferrer"
                                className="hidden sm:flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-500/30 border border-indigo-400/40 text-indigo-100 hover:bg-indigo-500/50 hover:text-white transition-colors text-xs font-bold uppercase tracking-wider self-start md:self-auto no-underline backdrop-blur-sm"
                            >
                                <DiscordLogo size={14} weight="fill" />
                                Discord
                            </a>
                        </div>
                    </div>
                </div>

                {/* Outer panel card — mirrors City Hall's tab card */}
                <div className="bg-slate-900/95 border border-slate-800 rounded-2xl shadow-2xl overflow-hidden">
                    <ForumPanel />
                </div>
            </div>
        </>
    );
}

Help.layout = page => <GameLayout flush noFlip children={page} />;
