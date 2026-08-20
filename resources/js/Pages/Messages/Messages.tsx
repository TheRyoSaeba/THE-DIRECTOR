import { useState, useEffect, useRef, type WheelEvent } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { motion, AnimatePresence } from 'framer-motion';
import {
    ChatTeardrop, MagnifyingGlass, Plus, PaperPlaneRight, ArrowLeft, Users, X, Checks,
    Spinner, UserPlus
} from '@phosphor-icons/react';
import GameLayout from '@/Layouts/GameLayout';
import StyledModal, { ActionButton, StyledInput } from '@/Layouts/styledmodal';

interface Conversation {
    id: string;
    isGroup: boolean;
    name: string;
    avatar: string | null;
    lastMessage: string;
    lastMessageAt: number;
    unread: number;
}

interface Message {
    id: number;
    sender_id: number;
    sender: {
        id: number;
        display_name: string;
        avatar_url: string | null;
    } | null;
    body: string;
    created_at: string;
    isMe: boolean;
}

interface Props {
    conversations?: Conversation[];
    selectedConversation?: string | null;
    conversation?: {
        id: string;
        isGroup: boolean;
        name: string;
        avatar: string | null;
    } | null;
    messages?: Message[];
}

function formatTime(timestamp: number): string {
    const now = Math.floor(Date.now() / 1000);
    const diff = now - timestamp;
    const minutes = Math.floor(diff / 60);
    const hours = Math.floor(diff / 3600);
    const days = Math.floor(diff / 86400);

    if (minutes < 1) return 'Just now';
    if (minutes < 60) return `${minutes}m ago`;
    if (hours < 24) return `${hours}h ago`;
    if (days < 7) return `${days}d ago`;
    return new Date(timestamp * 1000).toLocaleDateString();
}

function formatMessageTime(dateString: string): string {
    return new Date(dateString).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit'
    });
}

export default function Messages({ conversations = [], selectedConversation, conversation, messages = [] }: Props) {
    const { auth } = usePage().props as any;
    const character = auth?.character;
    const [searchQuery, setSearchQuery] = useState('');
    const [activeTab, setActiveTab] = useState<'all' | 'groups' | 'personal'>('all');

    const [convPage, setConvPage] = useState(0);
    const CONVS_PER_PAGE = 5;

    // Reset conversation page whenever the filter or search changes.
    useEffect(() => { setConvPage(0); }, [activeTab, searchQuery]);

    const [showNewModal, setShowNewModal] = useState(false);
    const [message, setMessage] = useState('');
    const [recipients, setRecipients] = useState<string[]>([]);
    const [currentRecipient, setCurrentRecipient] = useState('');
    const [messageBody, setMessageBody] = useState('');
    const [subject, setSubject] = useState('');
    const [sending, setSending] = useState(false);
    const messagesScrollRef = useRef<HTMLDivElement>(null);
    const shellHeightClass = selectedConversation
        ? 'h-[calc(100dvh-8rem)] min-h-[420px] max-h-[760px] md:h-[clamp(500px,calc(100dvh-12rem),760px)]'
        : 'md:h-[clamp(500px,calc(100dvh-12rem),760px)]';

    const filteredConversations = conversations.filter(conv => {
        const matchesSearch = searchQuery === '' ||
            conv.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
            conv.lastMessage.toLowerCase().includes(searchQuery.toLowerCase());

        if (activeTab === 'groups') return conv.isGroup && matchesSearch;
        if (activeTab === 'personal') return !conv.isGroup && matchesSearch;
        return matchesSearch;
    });

    const totalUnread = conversations.reduce((sum, conv) => sum + conv.unread, 0);
    const totalConvPages = Math.ceil(filteredConversations.length / CONVS_PER_PAGE);
    const pagedConversations = filteredConversations.slice(convPage * CONVS_PER_PAGE, (convPage + 1) * CONVS_PER_PAGE);

    useEffect(() => {
        if (!selectedConversation || messages.length === 0) {
            return;
        }

        const frame = window.requestAnimationFrame(() => {
            const node = messagesScrollRef.current;
            if (!node) {
                return;
            }

            node.scrollTop = node.scrollHeight;
        });

        return () => window.cancelAnimationFrame(frame);
    }, [messages, selectedConversation]);

    const handleSendMessage = () => {
        if (!message.trim() || !selectedConversation || sending) return;

        setSending(true);
        router.post(route('messages.send', { conversationId: selectedConversation }), {
            body: message.trim()
        }, {
            preserveScroll: true,
            onFinish: () => {
                setSending(false);
                setMessage('');
            }
        });
    };

    const handleStartConversation = () => {
        if (!messageBody.trim() || recipients.length === 0 || sending) return;

        setSending(true);
        router.post(route('messages.store'), {
            recipients,
            message: messageBody.trim(),
            subject: subject.trim() || undefined
        }, {
            onFinish: () => {
                setSending(false);
                setShowNewModal(false);
                setRecipients([]);
                setCurrentRecipient('');
                setMessageBody('');
                setSubject('');
            }
        });
    };

    const addRecipient = () => {
        const trimmed = currentRecipient.trim();
        if (trimmed && !recipients.includes(trimmed)) {
            setRecipients([...recipients, trimmed]);
            setCurrentRecipient('');
        }
    };

    const handlePanelWheel = (e: WheelEvent<HTMLDivElement>) => {
        const node = e.currentTarget;
        const canScroll = node.scrollHeight > node.clientHeight;

        if (!canScroll) {
            return;
        }

        node.scrollTop += e.deltaY;
        e.preventDefault();
    };

    return (
        <>
            <Head title="Messages - TheDirector" />

            <div className="max-w-7xl mx-auto px-3 py-4 sm:px-4 md:p-6">
                <div className="mb-4 md:mb-6">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-2">
                        <div className="flex items-center gap-3 min-w-0">
                            <h1 className="text-3xl font-black text-white uppercase tracking-tight">Messages</h1>
                            {totalUnread > 0 && (
                                <span className="px-2.5 py-1 rounded-full bg-emerald-500/20 text-emerald-400 text-xs font-black border border-emerald-500/30">
                                    {totalUnread} NEW
                                </span>
                            )}
                        </div>
                        <button
                            onClick={() => setShowNewModal(true)}
                            className="w-full sm:w-auto justify-center px-4 py-2 bg-gradient-to-r from-cyan-500/20 to-blue-500/20 text-cyan-400 border border-cyan-500/30 rounded-xl hover:from-cyan-500/30 hover:to-blue-500/30 transition flex items-center gap-2 text-sm font-black uppercase tracking-wider"
                        >
                            <Plus size={16} />
                            New Message
                        </button>
                    </div>
                </div>

                <div className={`rounded-2xl border border-slate-800/50 bg-gradient-to-br from-slate-900/90 to-slate-950/90 backdrop-blur-sm overflow-hidden shadow-2xl flex flex-col md:flex-row ${shellHeightClass}`}>
                    {/* Left panel — conversation list */}
                    <div className={`w-full md:w-96 md:min-h-0 md:border-r border-slate-800/50 flex flex-col bg-slate-900/30 ${selectedConversation ? 'hidden md:flex' : 'flex'}`}>
                        <div className="p-4 md:p-5 space-y-4 border-b border-slate-800/50 shrink-0">
                            <div className="flex gap-2 p-1 bg-slate-950/50 rounded-xl border border-slate-800/50">
                                {(['all', 'groups', 'personal'] as const).map((tab) => (
                                    <button
                                        key={tab}
                                        onClick={() => setActiveTab(tab)}
                                        className={`flex-1 px-3 py-2 md:px-4 rounded-lg text-[11px] md:text-xs font-black uppercase tracking-wider transition ${activeTab === tab
                                            ? 'bg-gradient-to-r from-cyan-500/20 to-blue-500/20 text-cyan-400 border border-cyan-500/30'
                                            : 'text-slate-500 hover:text-slate-300'
                                            }`}
                                    >
                                        {tab}
                                    </button>
                                ))}
                            </div>

                            <div className="relative">
                                <MagnifyingGlass className="absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-500" weight="bold" />
                                <input
                                    type="text"
                                    placeholder="Search conversations..."
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    className="w-full pl-10 pr-4 py-2.5 bg-slate-950/50 border border-slate-800/50 rounded-xl text-sm text-slate-200 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/40 focus:border-cyan-500/40 transition"
                                />
                            </div>
                        </div>

                        {/* Conversation list — fixed height, no overflow stretch */}
                        <div className="flex flex-col flex-1 min-h-0">
                            <div
                                className="flex-1 min-h-0 md:overflow-y-auto px-3 pt-3 space-y-1.5"
                                onWheel={handlePanelWheel}
                            >
                                {filteredConversations.length === 0 ? (
                                    <div className="text-center py-12 text-slate-500">
                                        <ChatTeardrop className="h-12 w-12 mx-auto mb-3 opacity-50" weight="bold" />
                                        <p className="text-sm font-medium">
                                            {searchQuery ? 'No conversations found' : 'No conversations yet'}
                                        </p>
                                        {!searchQuery && (
                                            <button
                                                onClick={() => setShowNewModal(true)}
                                                className="mt-4 px-4 py-2 bg-gradient-to-r from-cyan-500/20 to-blue-500/20 text-cyan-400 border border-cyan-500/30 rounded-lg hover:from-cyan-500/30 hover:to-blue-500/30 transition text-sm font-black uppercase tracking-wider"
                                            >
                                                Start Conversation
                                            </button>
                                        )}
                                    </div>
                                ) : (
                                    pagedConversations.map((conv) => (
                                        <button
                                            key={conv.id}
                                            onClick={() => router.get(route('messages.show', { conversationId: conv.id }))}
                                            className={`w-full p-3.5 text-left transition rounded-xl ${selectedConversation === conv.id
                                                ? 'bg-gradient-to-r from-cyan-500/10 to-blue-500/10 border border-cyan-500/20'
                                                : 'hover:bg-slate-800/40 border border-transparent'
                                                }`}
                                        >
                                            <div className="flex gap-3.5">
                                                <div className="relative shrink-0">
                                                    {conv.isGroup ? (
                                                        <div className={`w-12 h-12 rounded-full flex items-center justify-center ${selectedConversation === conv.id ? 'bg-cyan-500/20' : 'bg-slate-800/50'
                                                            } border border-slate-700/50`}>
                                                            <Users className={`h-5 w-5 ${selectedConversation === conv.id ? 'text-cyan-300' : 'text-slate-400'
                                                                }`} />
                                                        </div>
                                                    ) : (
                                                        <div className="w-12 h-12 ring-2 ring-slate-700/50 rounded-full overflow-hidden bg-slate-800">
                                                            {conv.avatar ? (
                                                                <img
                                                                    src={conv.avatar}
                                                                    alt={conv.name}
                                                                    className="w-full h-full object-cover"
                                                                />
                                                            ) : (
                                                                <div className={`flex items-center justify-center w-full h-full text-sm font-black ${selectedConversation === conv.id
                                                                    ? 'bg-cyan-500/20 text-cyan-300'
                                                                    : 'bg-slate-700 text-slate-300'
                                                                    }`}>
                                                                    {conv.name.split(' ').map(n => n[0]).join('').slice(0, 2) || '?'}
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>

                                                <div className="flex-1 min-w-0">
                                                    <div className="flex items-start justify-between gap-2 mb-1">
                                                        <span className={`font-bold truncate text-sm ${selectedConversation === conv.id ? 'text-cyan-300' : 'text-slate-200'
                                                            }`}>
                                                            {conv.name}
                                                        </span>
                                                        {conv.unread > 0 && (
                                                            <span className="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-emerald-500 text-white text-xs font-black shrink-0">
                                                                {conv.unread}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <p className={`text-xs truncate leading-relaxed ${selectedConversation === conv.id
                                                        ? 'text-cyan-100/80'
                                                        : 'text-slate-400'
                                                        }`}>
                                                        {conv.lastMessage || 'No messages yet'}
                                                    </p>
                                                    <div className="text-[10px] text-slate-600 mt-1">
                                                        {formatTime(conv.lastMessageAt)}
                                                    </div>
                                                </div>
                                            </div>
                                        </button>
                                    ))
                                )}
                            </div>

                            {/* Pagination — only shown when list exceeds one page */}
                            {totalConvPages > 1 && (
                                <div className="flex items-center justify-between px-4 py-2.5 mt-auto border-t border-slate-800/40 shrink-0">
                                    <button
                                        onClick={() => setConvPage(p => Math.max(0, p - 1))}
                                        disabled={convPage === 0}
                                        className="px-3 py-1.5 text-[10px] font-black uppercase tracking-wider text-slate-500 hover:text-cyan-400 disabled:opacity-30 disabled:cursor-not-allowed transition"
                                    >
                                        ← Prev
                                    </button>
                                    <span className="text-[10px] text-slate-600 font-mono tabular-nums">
                                        {convPage + 1} / {totalConvPages}
                                    </span>
                                    <button
                                        onClick={() => setConvPage(p => Math.min(totalConvPages - 1, p + 1))}
                                        disabled={convPage >= totalConvPages - 1}
                                        className="px-3 py-1.5 text-[10px] font-black uppercase tracking-wider text-slate-500 hover:text-cyan-400 disabled:opacity-30 disabled:cursor-not-allowed transition"
                                    >
                                        Next →
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Right panel — active conversation */}
                    <div className={`flex-1 min-w-0 min-h-0 flex flex-col ${!selectedConversation ? 'hidden md:flex' : 'flex'}`}>
                        {selectedConversation && conversation ? (
                            <>
                                <div className="px-4 py-4 md:px-6 border-b border-slate-800/50 flex items-center gap-4 bg-slate-900/40 shrink-0">
                                    <button
                                        onClick={() => router.get(route('messages'))}
                                        className="md:hidden p-2 -ml-2 hover:bg-slate-800/50 rounded-lg transition"
                                    >
                                        <ArrowLeft className="h-5 w-5 text-slate-400" />
                                    </button>

                                    {conversation.isGroup ? (
                                        <div className="w-11 h-11 rounded-full bg-slate-800/50 flex items-center justify-center ring-2 ring-slate-700/50">
                                            <Users className="h-5 w-5 text-slate-400" />
                                        </div>
                                    ) : (
                                        <div className="w-11 h-11 ring-2 ring-slate-700/50 rounded-full overflow-hidden bg-slate-800">
                                            {conversation.avatar ? (
                                                <img
                                                    src={conversation.avatar}
                                                    alt={conversation.name}
                                                    className="w-full h-full object-cover"
                                                />
                                            ) : (
                                                <div className="flex items-center justify-center w-full h-full bg-slate-700 text-slate-300 text-sm font-black">
                                                    {conversation.name.split(' ').map(n => n[0]).join('').slice(0, 2) || '?'}
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    <div className="flex-1 min-w-0">
                                        <h3 className="font-bold text-white truncate text-base">
                                            {conversation.name}
                                        </h3>
                                        <p className="text-xs text-slate-500 mt-0.5">
                                            {conversation.isGroup ? 'Group conversation' : 'Direct message'}
                                        </p>
                                    </div>
                                </div>

                                <div
                                    ref={messagesScrollRef}
                                    className="flex-1 min-w-0 min-h-0 overflow-x-hidden overflow-y-auto px-4 py-4 md:p-6"
                                    onWheel={handlePanelWheel}
                                >
                                    {messages.length === 0 ? (
                                        <div className="text-center py-12 text-slate-500">
                                            <ChatTeardrop className="h-12 w-12 mx-auto mb-3 opacity-50" weight="bold" />
                                            <p className="text-sm font-medium">No messages yet. Start the conversation!</p>
                                        </div>
                                    ) : (
                                        <div className="space-y-4">
                                            {messages.map((msg, index) => {
                                                const showSender = index === 0 ||
                                                    messages[index - 1].sender_id !== msg.sender_id ||
                                                    (new Date(msg.created_at).getTime() - new Date(messages[index - 1].created_at).getTime()) > 300000;

                                                return (
                                                    <div key={msg.id} className={`flex min-w-0 gap-3 ${msg.isMe ? 'justify-end' : 'justify-start'}`}>
                                                        {!msg.isMe && showSender && (
                                                            <div className="w-9 h-9 shrink-0 ring-2 ring-slate-700/50 rounded-full overflow-hidden bg-slate-800">
                                                                {msg.sender?.avatar_url ? (
                                                                    <img
                                                                        src={msg.sender.avatar_url}
                                                                        alt={msg.sender?.display_name || 'User'}
                                                                        className="w-full h-full object-cover"
                                                                    />
                                                                ) : (
                                                                    <div className="flex items-center justify-center w-full h-full bg-slate-700 text-slate-300 text-xs font-black">
                                                                        {msg.sender?.display_name?.split(' ').map((n: string) => n[0]).join('').slice(0, 2) || '?'}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        )}
                                                        {!msg.isMe && !showSender && <div className="w-9 shrink-0"></div>}

                                                        <div className={`max-w-[min(82%,42rem)] min-w-0 sm:max-w-[70%] ${msg.isMe ? 'items-end' : 'items-start'} flex flex-col`}>
                                                            {!msg.isMe && showSender && (
                                                                <span className="text-xs text-slate-500 mb-1 px-1 font-bold">{msg.sender?.display_name || 'Unknown'}</span>
                                                            )}
                                                            <div className={`max-w-full px-4 py-3 rounded-2xl ${msg.isMe
                                                                ? 'bg-gradient-to-r from-cyan-500/20 to-blue-500/20 text-cyan-100 border border-cyan-500/30 rounded-br-md'
                                                                : 'bg-slate-800/60 text-slate-200 rounded-bl-md'
                                                                }`}>
                                                                <p className="text-sm leading-relaxed whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{msg.body}</p>
                                                            </div>
                                                            <div className={`flex items-center gap-1.5 mt-1 px-1 ${msg.isMe ? 'flex-row-reverse' : ''}`}>
                                                                <span className="text-xs text-slate-600">{formatMessageTime(msg.created_at)}</span>
                                                                {msg.isMe && (
                                                                    <Checks className="h-3.5 w-3.5 text-cyan-400" weight="bold" />
                                                                )}
                                                            </div>
                                                        </div>

                                                        {msg.isMe && (
                                                            <div className="w-9 h-9 shrink-0 ring-2 ring-slate-700/50 rounded-full overflow-hidden bg-cyan-500/20 border border-cyan-500/30 flex items-center justify-center">
                                                                <span className="text-xs font-black text-cyan-300">You</span>
                                                            </div>
                                                        )}
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>

                                <div className="px-4 py-4 md:px-5 border-t border-slate-800/50 bg-slate-900/40 shrink-0">
                                    <div className="relative">
                                        <textarea
                                            placeholder="Message... (Enter to send, Shift+Enter for new line)"
                                            value={message}
                                            onChange={(e) => {
                                                setMessage(e.target.value);
                                                e.target.style.height = 'auto';
                                                e.target.style.height = Math.min(e.target.scrollHeight, 180) + 'px';
                                            }}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter' && !e.shiftKey) {
                                                    e.preventDefault();
                                                    handleSendMessage();
                                                    (e.target as HTMLTextAreaElement).style.height = 'auto';
                                                }
                                            }}
                                            disabled={sending}
                                            rows={1}
                                            className="w-full pl-4 pr-14 py-3 bg-slate-950/60 border border-slate-700/50 rounded-2xl text-sm text-slate-200 placeholder:text-slate-600 focus:outline-none focus:ring-1 focus:ring-cyan-500/50 focus:border-cyan-500/40 transition disabled:opacity-50 resize-none overflow-hidden leading-relaxed"
                                        />
                                        <button
                                            onClick={() => {
                                                handleSendMessage();
                                                const el = document.activeElement as HTMLTextAreaElement;
                                                if (el?.tagName === 'TEXTAREA') el.style.height = 'auto';
                                            }}
                                            disabled={!message.trim() || sending}
                                            className={`absolute right-2 bottom-2 w-9 h-9 rounded-xl flex items-center justify-center transition-all ${message.trim() && !sending
                                                ? 'bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-400 border border-cyan-500/30'
                                                : 'bg-slate-800/40 text-slate-700 border border-slate-700/30 cursor-not-allowed'
                                                }`}
                                        >
                                            {sending
                                                ? <Spinner className="w-4 h-4 animate-spin" weight="bold" />
                                                : <PaperPlaneRight className="w-4 h-4" weight="bold" />
                                            }
                                        </button>
                                    </div>
                                </div>
                            </>
                        ) : (
                            <div className="flex-1 flex items-center justify-center bg-slate-900/20">
                                <div className="text-center">
                                    <div className="w-20 h-20 rounded-full bg-slate-800/50 flex items-center justify-center mx-auto mb-4 ring-4 ring-slate-800/30">
                                        <ChatTeardrop className="h-10 w-10 text-slate-600" weight="bold" />
                                    </div>
                                    <h3 className="text-lg font-bold text-slate-300 mb-2">No conversation selected</h3>
                                    <p className="text-sm text-slate-500">Choose a conversation from the list</p>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* New conversation modal */}
            <StyledModal
                isOpen={showNewModal}
                onClose={() => {
                    setShowNewModal(false);
                    setRecipients([]);
                    setCurrentRecipient('');
                    setMessageBody('');
                    setSubject('');
                }}
                title="New Conversation"
                headerIcon={<UserPlus className="h-12 w-12 text-cyan-400" />}
                maxWidth="max-w-lg"
            >
                <div className="px-6 pb-8 pt-2 space-y-6">
                    <div>
                        <label className="block text-[10px] text-slate-400 font-black uppercase tracking-wider mb-2">Recipients</label>
                        <div className="flex gap-2 mb-2">
                            <input
                                type="text"
                                value={currentRecipient}
                                onChange={(e) => setCurrentRecipient(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && addRecipient()}
                                placeholder="Enter display name..."
                                className="flex-1 px-3 py-2 bg-slate-950/50 border border-slate-800 rounded-lg text-white placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/40 text-sm"
                            />
                            <button
                                onClick={addRecipient}
                                disabled={!currentRecipient.trim()}
                                className="px-4 py-2 bg-cyan-500/20 text-cyan-400 border border-cyan-500/30 rounded-lg hover:bg-cyan-500/30 transition disabled:opacity-50"
                            >
                                <UserPlus className="h-4 w-4" />
                            </button>
                        </div>
                        <div className="flex flex-wrap gap-2 min-h-8">
                            {recipients.map((recipient) => (
                                <div
                                    key={recipient}
                                    className="px-3 py-1 bg-slate-800 rounded-full flex items-center gap-2 border border-slate-700"
                                >
                                    <span className="text-sm text-white font-medium">{recipient}</span>
                                    <button
                                        onClick={() => setRecipients(recipients.filter(r => r !== recipient))}
                                        className="text-slate-400 hover:text-red-400"
                                    >
                                        <X className="h-3 w-3" />
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>

                    {recipients.length > 1 && (
                        <StyledInput
                            label="Group Subject (optional)"
                            value={subject}
                            onChange={(e) => setSubject(e.target.value)}
                            placeholder="Group conversation subject..."
                            maxLength={100}
                        />
                    )}

                    <div>
                        <label className="block text-[10px] text-slate-400 font-black uppercase tracking-wider mb-2">Message</label>
                        <textarea
                            value={messageBody}
                            onChange={(e) => setMessageBody(e.target.value)}
                            placeholder="Write your message..."
                            maxLength={1000}
                            rows={4}
                            className="w-full px-3 py-2 bg-slate-950/50 border border-slate-800 rounded-lg text-white placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/40 resize-none text-sm"
                        />
                        <div className="text-xs text-slate-600 mt-1 text-right font-bold">
                            {messageBody.length}/1000
                        </div>
                    </div>

                    <div className="flex gap-3 pt-4">
                        <ActionButton
                            onClick={() => {
                                setShowNewModal(false);
                                setRecipients([]);
                                setCurrentRecipient('');
                                setMessageBody('');
                                setSubject('');
                            }}
                            variant="secondary"
                            className="flex-1"
                        >
                            Cancel
                        </ActionButton>
                        <ActionButton
                            onClick={handleStartConversation}
                            disabled={sending || recipients.length === 0 || !messageBody.trim()}
                            variant="primary"
                            className="flex-1"
                        >
                            {sending ? (
                                <>
                                    <Spinner className="w-5 h-5 animate-spin" weight="bold" />
                                    Starting...
                                </>
                            ) : (
                                <>
                                    <PaperPlaneRight className="w-5 h-5" weight="bold" />
                                    Start Conversation
                                </>
                            )}
                        </ActionButton>
                    </div>
                </div>
            </StyledModal>
        </>
    );
}

Messages.layout = (page: any) => <GameLayout children={page} />;
