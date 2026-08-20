import { useState } from "react";
import { Head, router } from '@inertiajs/react';
import { formatUTC } from '@/Layouts/GameLayoutComponents';
import {
    Calendar, Bell, Clock, Sword, Shield, Crown,
    ArrowCircleDown, Skull, Trash,
    ArrowsClockwise, UserPlus, Bookmark, Article, Check, X, ShoppingBag, CurrencyDollar, Hammer, Trophy,
    BookmarkSimple, CaretLeft, CaretRight,
    Buildings, UserMinus, Vault, SignOut, Wrench, Scales,
    Files, PoliceCar, Warning, Detective, Gavel, Heartbeat, Newspaper, SealCheck, CurrencyBtc, Handshake, PaperPlaneRight, House,
    Fire, ShieldCheck, CheckCircle, TrendUp,Van, Desktop, SuitcaseRollingIcon,EyesIcon,HandPalmIcon,EnvelopeIcon,ProhibitIcon,PillIcon,HandGrabbingIcon, BootIcon
} from '@phosphor-icons/react';
import GameLayout from '@/Layouts/GameLayout';
import AchievementEntry from '@/Components/AchievementEntry';
import RevivedEntry from '@/Components/RevivedEntry';

const CustomGbhHeart = ({ className }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
        <line x1="12" y1="8" x2="12" y2="13" />
        <line x1="12" y1="16" x2="12" y2="16" />
    </svg>
);

const IconMap = {
    Calendar, Bell, Clock, Sword, Shield, Crown,
    ArrowCircleDown, Skull, Article, X,
    ShoppingBag, CurrencyDollar, CustomGbhHeart, Hammer, Trophy,
    ArrowsClockwise, Buildings, UserMinus, Vault, SignOut, Wrench, Scales,
    Files, PoliceCar, Warning, Detective, Gavel, Heartbeat, Newspaper, SealCheck, Handshake, PaperPlaneRight, House,
    Fire, ShieldCheck, CheckCircle, TrendUp,CurrencyBtc, Van, Desktop, SuitcaseRollingIcon,EyesIcon,HandPalmIcon,EnvelopeIcon,ProhibitIcon,PillIcon,HandGrabbingIcon,BootIcon
};

// ── Shared Journal Entry renderer ───────────────────────────────────────────
function SharedJournalEntry({ entry, onDelete, onSave, processingId, getIcon }) {
    const d        = entry.data || {};
    const sender   = d.sender_name     || 'Unknown';
    const origDate = d.orig_created_at || null;

    return (
        // Outer envelope — cyan-tinted, clearly a wrapper
        <div className={`relative rounded-xl border border-cyan-500/25 bg-cyan-950/20 overflow-hidden transition-all ${
            !entry.is_read ? 'ring-1 ring-cyan-500/40' : ''
        } ${processingId === entry.id ? 'opacity-50' : ''}`}>

            {/* ── Forward header ── */}
            <div className="flex items-center gap-2.5 px-4 py-3 border-b border-cyan-500/20">
                <PaperPlaneRight className="h-4 w-4 text-cyan-400 shrink-0" weight="fill" />
                <div className="flex-1 min-w-0">
                    <span className="text-xs font-black text-cyan-300 tracking-wide">{sender}</span>
                    <span className="text-xs text-cyan-600 ml-1.5">forwarded this to you</span>
                </div>
                {!entry.is_read && (
                    <span className="w-2 h-2 rounded-full bg-cyan-400 shadow-[0_0_6px_rgba(34,211,238,0.8)] shrink-0" />
                )}
                <span className="text-[10px] text-cyan-700 flex items-center gap-1 shrink-0">
                    <Clock className="h-3 w-3" />
                    {formatUTC(entry.created_at)}
                </span>
            </div>

            {/* ── Inner journal entry — exact same markup as a regular entry ── */}
            <div className="p-3">
                <div className={`rounded-lg border border-slate-700/40 overflow-hidden ${
                    d.orig_color || 'bg-slate-800/20'
                }`}>
                    <div className="p-4">
                        <div className="flex items-start gap-4">
                            <div className="w-10 h-10 rounded-xl flex items-center justify-center border border-slate-700/30 bg-slate-900/50 shrink-0">
                                {getIcon(d.orig_icon || 'Article')}
                            </div>
                            <div className="flex-1 min-w-0">
                                <div className="flex items-center gap-3 mb-1">
                                    <span className="font-semibold text-white">{d.orig_title || 'Journal Entry'}</span>
                                    <span className="ml-auto text-xs text-slate-500 flex items-center gap-1 shrink-0">
                                        <Clock className="h-3 w-3" />
                                        {origDate ? formatUTC(origDate) : '—'}
                                    </span>
                                </div>
                                <p className="text-sm text-slate-300 whitespace-pre-line leading-relaxed">
                                    {d.orig_desc || ''}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* ── Actions ── */}
            <div className="flex flex-wrap gap-2 px-4 pb-3">
                <button
                    onClick={() => onDelete(entry.id)}
                    disabled={processingId === entry.id}
                    className="px-4 py-2 rounded-lg text-xs font-medium bg-red-500/20 hover:bg-red-500/30 text-red-400 border border-red-500/30 transition disabled:opacity-50 flex items-center gap-2"
                >
                    <Trash className="h-3.5 w-3.5" /> Delete
                </button>
                <button
                    onClick={() => onSave(entry.id)}
                    disabled={processingId === entry.id}
                    className="px-4 py-2 rounded-lg text-xs font-medium bg-cyan-600 hover:bg-cyan-500 text-white transition disabled:opacity-50 flex items-center gap-2"
                >
                    <BookmarkSimple className="h-3.5 w-3.5" /> Save
                </button>
            </div>
        </div>
    );
}

const ITEMS_PER_PAGE = 5;

export default function Journal({ entries = [], requests = [], saved = [], unread_count = 0 }) {
    const [activeTab, setActiveTab] = useState("activity");
    const [refreshing, setRefreshing] = useState(false);
    const [processingId, setProcessingId] = useState(null);
    const [page, setPage] = useState({ activity: 1, requests: 1, saved: 1 });
    const [sendingId, setSendingId] = useState(null);   // entry id whose send form is open
    const [sendRecipient, setSendRecipient] = useState('');
    const [sendBusy, setSendBusy] = useState(false);

    const openSend = (id) => {
        setSendingId(id);
        setSendRecipient('');
    };
    const closeSend = () => setSendingId(null);

    const sendEntry = (entry) => {
        const name = sendRecipient.trim();
        if (!name || sendBusy) return;
        setSendBusy(true);
        router.post(`/journal/${entry.id}/forward`, {
            recipient_name: name,
        }, {
            preserveScroll: true,
            onSuccess: () => { setSendingId(null); setSendRecipient(''); },
            onFinish: () => setSendBusy(false),
        });
    };

    const fetchJournal = () => {
        setRefreshing(true);
        router.reload({
            only: ['entries', 'unread_count'],
            onFinish: () => setRefreshing(false)
        });
    };

    const deleteEntry = (id) => {
        setProcessingId(id);
        router.delete(`/journal/${id}`, {
            preserveScroll: true,
            onFinish: () => setProcessingId(null)
        });
    };

    const deleteAll = () => {
        router.delete(`/journal/all`);
    };

    const saveEntry = (id) => {
        setProcessingId(id);
        router.post(`/journal/${id}/save`, {}, {
            preserveScroll: true,
            onFinish: () => setProcessingId(null)
        });
    };

    const acceptRequest = (id) => {
        setProcessingId(id);
        router.post(`/journal/${id}/accept`, {}, {
            preserveScroll: true,
            onFinish: () => setProcessingId(null)
        });
    };

    const declineRequest = (id) => {
        setProcessingId(id);
        router.post(`/journal/${id}/decline`, {}, {
            preserveScroll: true,
            onFinish: () => setProcessingId(null)
        });
    };



    const getIcon = (iconName) => {
        const Icon = IconMap[iconName];
        if (!Icon) return <Calendar className="h-5 w-5 text-slate-400" />;
        return <Icon className="h-5 w-5 text-slate-400" />;
    };


    const renderRequestIcon = (request) => {
        if (request.actor_avatar) {
            return (
                <div className="w-10 h-10 rounded-full overflow-hidden border border-emerald-500/30 bg-emerald-500/10 shrink-0">
                    <img
                        src={request.actor_avatar}
                        alt=""
                        className="w-full h-full object-cover"
                        onError={(e) => {
                            e.target.style.display = 'none';
                            e.target.nextSibling.style.display = 'flex';
                        }}
                    />
                    <div className="w-10 h-10 hidden items-center justify-center bg-slate-800">
                        {getIcon(request.icon)}
                    </div>
                </div>
            );
        }
        return (
            <div className="w-10 h-10 rounded-xl flex items-center justify-center border border-emerald-500/30 bg-emerald-500/10 shrink-0">
                {getIcon(request.icon)}
            </div>
        );
    };


    const paginatedData = (items, tabKey) => {
        const start = (page[tabKey] - 1) * ITEMS_PER_PAGE;
        return items.slice(start, start + ITEMS_PER_PAGE);
    };

    const totalPages = (items) => Math.ceil(items.length / ITEMS_PER_PAGE);

    const Pagination = ({ items, tabKey }) => {
        const total = totalPages(items);
        if (total <= 1) return null;

        return (
            <div className="flex items-center justify-center gap-2 mt-4 pt-4 border-t border-slate-700/30">
                <button
                    onClick={() => setPage(p => ({ ...p, [tabKey]: Math.max(1, p[tabKey] - 1) }))}
                    disabled={page[tabKey] === 1}
                    className="p-1.5 rounded-lg bg-slate-800/50 border border-slate-700/30 hover:bg-slate-700 transition disabled:opacity-30 disabled:cursor-not-allowed"
                >
                    <CaretLeft className="h-4 w-4 text-slate-400" weight="bold" />
                </button>
                <span className="text-sm text-slate-400 px-3">
                    {page[tabKey]} / {total}
                </span>
                <button
                    onClick={() => setPage(p => ({ ...p, [tabKey]: Math.min(total, p[tabKey] + 1) }))}
                    disabled={page[tabKey] === total}
                    className="p-1.5 rounded-lg bg-slate-800/50 border border-slate-700/30 hover:bg-slate-700 transition disabled:opacity-30 disabled:cursor-not-allowed"
                >
                    <CaretRight className="h-4 w-4 text-slate-400" weight="bold" />
                </button>
            </div>
        );
    };

    return (
        <>
            <Head title="Journal - TheDirector" />
            <div className="max-w-4xl mx-auto p-6">

                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-2xl font-bold text-white mb-1">Journal</h1>
                        <p className="text-sm text-slate-400">Your game activity log</p>
                    </div>
                    <div className="flex items-center gap-4">
                        <div className="px-4 py-2 rounded-xl bg-slate-800/50 border border-slate-700/30">
                            <p className="text-xs text-slate-500">Unread</p>
                            <p className="text-lg font-bold text-cyan-400">{unread_count}</p>
                        </div>
                        <button
                            onClick={fetchJournal}
                            disabled={refreshing}
                            className="p-2 rounded-lg bg-slate-800/50 border border-slate-700/30 hover:bg-slate-800 transition disabled:opacity-50"
                        >
                            <ArrowsClockwise className={`h-4 w-4 text-slate-400 ${refreshing ? 'animate-spin' : ''}`} weight="bold" />
                        </button>
                        {entries.length > 0 && (
                            <button
                                onClick={deleteAll}
                                className="px-4 py-2 rounded-lg bg-red-500/20 text-red-400 border border-red-500/30 hover:bg-red-500/30 transition text-sm font-medium"
                            >
                                Delete All
                            </button>
                        )}
                    </div>
                </div>


                <div className="flex gap-2 mb-6 border-b border-slate-800/50">
                    <button
                        onClick={() => setActiveTab("activity")}
                        className={`px-4 py-2 text-sm font-medium transition border-b-2 ${activeTab === "activity"
                            ? "text-cyan-400 border-cyan-400"
                            : "text-slate-400 border-transparent hover:text-slate-300"}`}
                    >
                        <Bell className="h-4 w-4 inline mr-2" />
                        Activity Log
                        {requests.length > 0 && activeTab === "activity" && (
                            <span className="ml-2 px-2 py-0.5 bg-emerald-500/20 text-emerald-400 text-[10px] font-black rounded border border-emerald-500/30 uppercase tracking-wider">
                                Request[{requests.length}]
                            </span>
                        )}
                    </button>
                    <button
                        onClick={() => setActiveTab("requests")}
                        className={`px-4 py-2 text-sm font-medium transition border-b-2 ${activeTab === "requests"
                            ? "text-cyan-400 border-cyan-400"
                            : "text-slate-400 border-transparent hover:text-slate-300"}`}
                    >
                        <UserPlus className="h-4 w-4 inline mr-2" />
                        Requests
                        {requests.length > 0 && (
                            <span className="ml-2 px-2 py-0.5 bg-emerald-500/20 text-emerald-400 text-[10px] font-black rounded border border-emerald-500/30">
                                {requests.length}
                            </span>
                        )}
                    </button>
                    <button
                        onClick={() => setActiveTab("saved")}
                        className={`px-4 py-2 text-sm font-medium transition border-b-2 ${activeTab === "saved"
                            ? "text-cyan-400 border-cyan-400"
                            : "text-slate-400 border-transparent hover:text-slate-300"}`}
                    >
                        <Bookmark className="h-4 w-4 inline mr-2" />
                        Saved
                    </button>
                </div>


                {activeTab === "activity" && (
                    <>
                        <div className="space-y-3">
                            {entries.length === 0 ? (
                                <div className="p-12 text-center rounded-xl border border-slate-700/30 bg-slate-800/30">
                                    <Calendar className="h-12 w-12 text-slate-600 mx-auto mb-3" />
                                    <p className="text-slate-400">No journal entries yet</p>
                                    <p className="text-sm text-slate-500 mt-2">Your game activity will appear here</p>
                                </div>
                            ) : (
                                paginatedData(entries, 'activity').map((entry) => (
                                    entry.type === 'achievement_unlocked' ? (
                                        <AchievementEntry
                                            key={entry.id}
                                            entry={entry}
                                            onDelete={deleteEntry}
                                            onSave={saveEntry}
                                            processingId={processingId}
                                        />
                                    ) : entry.type === 'revived' ? (
                                        <RevivedEntry
                                            key={entry.id}
                                            entry={entry}
                                            onDelete={deleteEntry}
                                            onSave={saveEntry}
                                            processingId={processingId}
                                        />
                                    ) : entry.type === 'journal_shared' ? (
                                        <SharedJournalEntry
                                            key={entry.id}
                                            entry={entry}
                                            onDelete={deleteEntry}
                                            onSave={saveEntry}
                                            processingId={processingId}
                                            getIcon={getIcon}
                                        />
                                    ) : (
                                        <div
                                            key={entry.id}
                                            className={`relative rounded-xl border transition-all ${!entry.is_read ? 'ring-1 ring-cyan-500/30' : ''} ${entry.color_class || 'bg-slate-800/20 border-slate-700/30'} ${processingId === entry.id ? 'opacity-50' : ''}`}
                                        >
                                            <div className="p-4">
                                                <div className="flex items-start gap-4">
                                                    <div className="w-10 h-10 rounded-xl flex items-center justify-center border border-slate-700/30 bg-slate-900/50 shrink-0">
                                                        {getIcon(entry.icon)}
                                                    </div>
                                                    <div className="flex-1 min-w-0">
                                                        <div className="flex items-center gap-3 mb-1">
                                                            <span className="font-semibold text-white">{entry.title}</span>
                                                            {!entry.is_read && (
                                                                <span className="w-2 h-2 rounded-full bg-cyan-400" />
                                                            )}
                                                            <span className="ml-auto text-xs text-slate-500 flex items-center gap-1">
                                                                <Clock className="h-3 w-3" />
                                                                {formatUTC(entry.created_at)}
                                                            </span>
                                                        </div>
                                                        <p className="text-sm text-slate-300 whitespace-pre-line mb-3">
                                                            {entry.description}
                                                        </p>
                                                        <div className="flex flex-wrap gap-2 mt-3">
                                                            <button
                                                                onClick={() => deleteEntry(entry.id)}
                                                                disabled={processingId === entry.id}
                                                                className="px-4 py-2 rounded-lg text-xs font-medium bg-red-500/20 hover:bg-red-500/30 text-red-400 border border-red-500/30 transition disabled:opacity-50 flex items-center gap-2"
                                                            >
                                                                <Trash className="h-3.5 w-3.5" /> Delete
                                                            </button>
                                                            <button
                                                                onClick={() => saveEntry(entry.id)}
                                                                disabled={processingId === entry.id}
                                                                className="px-4 py-2 rounded-lg text-xs font-medium bg-cyan-600 hover:bg-cyan-500 text-white transition disabled:opacity-50 flex items-center gap-2"
                                                            >
                                                                <BookmarkSimple className="h-3.5 w-3.5" /> Save
                                                            </button>
                                                            <button
                                                                onClick={() => sendingId === entry.id ? closeSend() : openSend(entry.id)}
                                                                disabled={processingId === entry.id}
                                                                className="px-4 py-2 rounded-lg text-xs font-medium bg-slate-700/60 hover:bg-slate-600/60 text-slate-300 border border-slate-600/40 transition disabled:opacity-50 flex items-center gap-2"
                                                            >
                                                                <PaperPlaneRight className="h-3.5 w-3.5" /> Send
                                                            </button>
                                                        </div>
                                                        {sendingId === entry.id && (
                                                            <div className="mt-3 flex items-center gap-2">
                                                                <input
                                                                    type="text"
                                                                    value={sendRecipient}
                                                                    onChange={e => setSendRecipient(e.target.value)}
                                                                    onKeyDown={e => e.key === 'Enter' && sendEntry(entry)}
                                                                    placeholder="Recipient name…"
                                                                    autoFocus
                                                                    className="flex-1 px-3 py-2 rounded-lg bg-slate-900/70 border border-slate-600/50 text-white text-xs placeholder:text-slate-600 focus:outline-none focus:border-cyan-500/50 transition"
                                                                />
                                                                <button
                                                                    onClick={() => sendEntry(entry)}
                                                                    disabled={!sendRecipient.trim() || sendBusy}
                                                                    className="px-3 py-2 rounded-lg text-xs font-medium bg-emerald-600 hover:bg-emerald-500 text-white transition disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1.5 shrink-0"
                                                                >
                                                                    {sendBusy
                                                                        ? <div className="w-3 h-3 border border-white/30 border-t-white rounded-full animate-spin" />
                                                                        : <PaperPlaneRight className="h-3 w-3" weight="fill" />}
                                                                    Send
                                                                </button>
                                                                <button
                                                                    onClick={closeSend}
                                                                    className="p-2 rounded-lg text-slate-500 hover:text-white transition"
                                                                >
                                                                    <X className="h-3.5 w-3.5" />
                                                                </button>
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    )
                                ))
                            )}
                        </div>
                        <Pagination items={entries} tabKey="activity" />
                    </>
                )}


                {activeTab === "requests" && (
                    <>
                        <div className="space-y-3">
                            {requests.length === 0 ? (
                                <div className="p-12 text-center rounded-xl border border-slate-700/30 bg-slate-800/30">
                                    <UserPlus className="h-12 w-12 text-slate-600 mx-auto mb-3" />
                                    <p className="text-slate-400">No pending requests</p>
                                    <p className="text-sm text-slate-500 mt-2">Sale requests and other offers will appear here</p>
                                </div>
                            ) : (
                                paginatedData(requests, 'requests').map((request) => {
                                    const isItemSale = ['item_sale_request', 'corporate_medicine_sale_request'].includes(request.type) && request.item_image;

                                    return (
                                        <div
                                            key={request.id}
                                            className={`relative rounded-xl border transition-all ${request.color_class || 'bg-gradient-to-br from-emerald-900/40 to-emerald-800/30 border-emerald-600/60'} ${processingId === request.id ? 'opacity-50' : ''} ring-2 ring-emerald-500/30 shadow-[0_0_20px_rgba(16,185,129,0.3)]`}
                                        >
                                            <div className="p-4">
                                                {isItemSale ? (
                                                    <div className="flex gap-4">
                                                        <div className="flex-[3] min-w-0">
                                                            <div className="flex items-start gap-4">
                                                                {renderRequestIcon(request)}
                                                                <div className="flex-1 min-w-0">
                                                                    <div className="flex items-center gap-3 mb-1">
                                                                        <span className="font-semibold text-white">{request.title}</span>
                                                                        <span className="ml-auto text-xs text-slate-500 flex items-center gap-1">
                                                                        <Clock className="h-3 w-3" />
                                                                        {formatUTC(request.created_at)}
                                                                        </span>
                                                                    </div>
                                                                    <p className="text-sm text-slate-300 whitespace-pre-line mb-3">
                                                                        {request.description}
                                                                    </p>
                                                                    <div className="flex gap-2 mt-3">
                                                                        <button
                                                                            onClick={() => acceptRequest(request.id)}
                                                                            disabled={processingId === request.id}
                                                                            className="px-4 py-2 rounded-lg text-xs font-medium bg-emerald-600 hover:bg-emerald-500 text-white transition disabled:opacity-50 flex items-center gap-2"
                                                                        >
                                                                            <Check className="h-3.5 w-3.5" />
                                                                            Accept
                                                                        </button>
                                                                        <button
                                                                            onClick={() => declineRequest(request.id)}
                                                                            disabled={processingId === request.id}
                                                                            className="px-4 py-2 rounded-lg text-xs font-medium bg-red-500/20 hover:bg-red-500/30 text-red-400 border border-red-500/30 transition disabled:opacity-50 flex items-center gap-2"
                                                                        >
                                                                            <X className="h-3.5 w-3.5" />
                                                                            Decline
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div className="flex-1 min-w-[100px] max-w-[140px]">
                                                            <div className="h-full rounded-lg overflow-hidden border border-emerald-500/20 bg-slate-900/50 flex items-center justify-center">
                                                                <img
                                                                    src={request.item_image}
                                                                    alt="Item"
                                                                    className="w-full h-auto max-h-[120px] object-contain"
                                                                    onError={(e) => { e.target.style.display = 'none'; }}
                                                                />
                                                            </div>
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <div className="flex items-start gap-4">
                                                        {renderRequestIcon(request)}
                                                        <div className="flex-1 min-w-0">
                                                            <div className="flex items-center gap-3 mb-1">
                                                                <span className="font-semibold text-white">{request.title}</span>
                                                                <span className="ml-auto text-xs text-slate-500 flex items-center gap-1">
                                                                <Clock className="h-3 w-3" />
                                                                {formatUTC(request.created_at)}
                                                                </span>
                                                            </div>
                                                            <p className="text-sm text-slate-300 whitespace-pre-line mb-3">
                                                                {request.description}
                                                            </p>
                                                            <div className="flex gap-2 mt-3">
                                                                <button
                                                                    onClick={() => acceptRequest(request.id)}
                                                                    disabled={processingId === request.id}
                                                                    className="px-4 py-2 rounded-lg text-xs font-medium bg-emerald-600 hover:bg-emerald-500 text-white transition disabled:opacity-50 flex items-center gap-2"
                                                                >
                                                                    <Check className="h-3.5 w-3.5" />
                                                                    Accept
                                                                </button>
                                                                <button
                                                                    onClick={() => declineRequest(request.id)}
                                                                    disabled={processingId === request.id}
                                                                    className="px-4 py-2 rounded-lg text-xs font-medium bg-red-500/20 hover:bg-red-500/30 text-red-400 border border-red-500/30 transition disabled:opacity-50 flex items-center gap-2"
                                                                >
                                                                    <X className="h-3.5 w-3.5" />
                                                                    Decline
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })
                            )}
                        </div>
                        <Pagination items={requests} tabKey="requests" />
                    </>
                )}


                {activeTab === "saved" && (
                    <>
                        <div className="space-y-3">
                            {saved.length === 0 ? (
                                <div className="p-12 text-center rounded-xl border border-slate-700/30 bg-slate-800/30">
                                    <Bookmark className="h-12 w-12 text-slate-600 mx-auto mb-3" />
                                    <p className="text-slate-400">No saved entries</p>
                                    <p className="text-sm text-slate-500 mt-2">Important entries you save will appear here</p>
                                </div>
                            ) : (
                                paginatedData(saved, 'saved').map((entry) => entry.type === 'journal_shared' ? (
                                    <SharedJournalEntry
                                        key={entry.id}
                                        entry={entry}
                                        onDelete={deleteEntry}
                                        onSave={saveEntry}
                                        processingId={processingId}
                                        getIcon={getIcon}
                                    />
                                ) : entry.type === 'revived' ? (
                                    <RevivedEntry
                                        key={entry.id}
                                        entry={entry}
                                        onDelete={deleteEntry}
                                        onSave={saveEntry}
                                        processingId={processingId}
                                    />
                                ) : (
                                    <div
                                        key={entry.id}
                                        className={`relative rounded-xl border transition-all ${entry.color_class || 'bg-slate-800/20 border-slate-700/30'} ${processingId === entry.id ? 'opacity-50' : ''}`}
                                    >
                                        <div className="p-4">
                                            <div className="flex items-start gap-4">
                                                <div className="w-10 h-10 rounded-xl flex items-center justify-center border border-slate-700/30 bg-slate-900/50 shrink-0">
                                                    {getIcon(entry.icon)}
                                                </div>
                                                <div className="flex-1 min-w-0">
                                                    <div className="flex items-center gap-3 mb-1">
                                                        <span className="font-semibold text-white">{entry.title}</span>
                                                        <Bookmark className="h-3.5 w-3.5 text-cyan-400" weight="fill" />
                                                        <span className="ml-auto text-xs text-slate-500 flex items-center gap-1">
                                                        <Clock className="h-3 w-3" />
                                                        {formatUTC(entry.created_at)}
                                                        </span>
                                                    </div>
                                                    <p className="text-sm text-slate-300 whitespace-pre-line mb-3">
                                                        {entry.description}
                                                    </p>
                                                    <button
                                                        onClick={() => deleteEntry(entry.id)}
                                                        disabled={processingId === entry.id}
                                                        className="px-4 py-2 rounded-lg text-xs font-medium bg-red-500/20 hover:bg-red-500/30 text-red-400 border border-red-500/30 transition disabled:opacity-50 flex items-center gap-2"
                                                    >
                                                        <Trash className="h-3.5 w-3.5" />
                                                        Delete
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                        <Pagination items={saved} tabKey="saved" />
                    </>
                )}
            </div>
        </>
    );
}

Journal.layout = page => <GameLayout wide children={page} />;
