import React, { useState, useCallback } from 'react';
import { router, useForm } from '@inertiajs/react';
import { route } from 'ziggy-js';
import {
    MagnifyingGlass, Prohibit, UserCheck, CaretDown, Trophy, Briefcase, Shield,
    Heart, Sword, ShieldCheck, Brain, Star, Lightning, ChatTeardrop, Article, Scroll,
    SpinnerGap,
} from '@phosphor-icons/react';
import { formatCash } from '@/Layouts/GameLayoutComponents';

import {
    AdminModal,
    AdminTextarea,
    Badge,
    FormActions,
    PageControls,
    TableShell,
    TD,
    useConfirm,
} from '../Components';

import type { User } from './types';

interface UserDetail {
    user: any;
    character: any;
    achievements: any[];
    journals: any[];
    messages: any[];
}

export function UsersSection({
    users,
    filters,
}: {
    users: { data: User[]; links: any[]; current_page: number; total: number };
    filters: { search: string; filter: string };
}) {
    const { confirm, ConfirmNode } = useConfirm();
    const [expandedUser, setExpandedUser] = useState<number | null>(null);
    const [banTarget, setBanTarget] = useState<User | null>(null);
    const [detailData, setDetailData] = useState<Record<number, UserDetail>>({});
    const [detailLoading, setDetailLoading] = useState<number | null>(null);
    const [detailTab, setDetailTab] = useState<'overview' | 'achievements' | 'journal' | 'messages'>('overview');

    const searchForm = useForm({
        search: filters.search || '',
        filter: filters.filter || 'all',
    });
    const banForm = useForm({ reason: '', duration: 'permanent' });

    const toggleExpand = useCallback((userId: number) => {
        if (expandedUser === userId) {
            setExpandedUser(null);
            return;
        }
        setExpandedUser(userId);
        setDetailTab('overview');
        if (!detailData[userId]) {
            setDetailLoading(userId);
            fetch(route('admin.users.detail', userId), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then(r => r.json())
                .then(data => {
                    setDetailData(prev => ({ ...prev, [userId]: data }));
                    setDetailLoading(null);
                })
                .catch(() => setDetailLoading(null));
        }
    }, [expandedUser, detailData]);

    const submitSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            route('admin.index'),
            {
                section: 'users',
                search: searchForm.data.search,
                filter: searchForm.data.filter,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const handleBanSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!banTarget) return;
        banForm.post(route('admin.users.ban', banTarget.id), {
            onSuccess: () => {
                setBanTarget(null);
                banForm.reset();
            },
        });
    };

    const handleUnban = (user: User) => {
        confirm(
            `Unban ${user.username}?`,
            () => router.post(route('admin.users.unban', user.id)),
            'This will restore their account and character.',
            false,
        );
    };

    const fmt = (d?: string) =>
        d
            ? new Date(d).toLocaleString('en-GB', {
                day: '2-digit',
                month: 'short',
                year: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
            })
            : '—';

    const DETAIL_TABS = [
        { id: 'overview' as const, label: 'Overview', icon: Shield },
        { id: 'achievements' as const, label: 'Achievements', icon: Trophy },
        { id: 'journal' as const, label: 'Journal', icon: Article },
        { id: 'messages' as const, label: 'Messages', icon: ChatTeardrop },
    ];

    const renderDetailContent = (detail: UserDetail) => {
        const { character, achievements, journals, messages } = detail;

        if (detailTab === 'overview') {
            return (
                <div className="space-y-4">
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <MiniStat label="Registered" value={fmt(detail.user.created_at)} />
                        <MiniStat label="Last IP" value={detail.user.last_ip || '—'} mono />
                        <MiniStat label="Admin" value={detail.user.is_admin ? 'Yes' : 'No'} accent={detail.user.is_admin ? 'amber' : undefined} />
                        <MiniStat label="Status" value={detail.user.is_banned ? 'Banned' : 'Active'} accent={detail.user.is_banned ? 'red' : 'green'} />
                    </div>

                    {character ? (
                        <>
                            <div className="text-[9px] font-black uppercase tracking-widest text-slate-500 mt-4">Character</div>
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <MiniStat label="Name" value={character.display_name} />
                                <MiniStat label="Health" value={`${character.health} / ${character.max_health}`} icon={Heart} accent={character.is_dead ? 'red' : undefined} />
                                <MiniStat label="Career" value={character.career} icon={Briefcase} />
                                <MiniStat label="Career Rank" value={character.career_rank ?? '—'} icon={Trophy} />
                                <MiniStat label="Career XP" value={character.career_xp?.toLocaleString()} mono />
                                <MiniStat label="Total Earns" value={character.total_earns?.toLocaleString()} mono accent="emerald" />
                                <MiniStat label="Degrees" value={character.degrees ? Object.keys(character.degrees).length : 0} icon={Scroll} />
                                <MiniStat label="Total EXP" value={character.total_character_exp?.toLocaleString()} mono />
                            </div>

                            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <MiniStat label="Cash" value={formatCash(character.cash_on_hand)} accent="emerald" />
                                <MiniStat label="Bank" value={formatCash(character.cash_in_bank)} accent="emerald" />
                                <MiniStat label="Dirty" value={formatCash(character.dirty_cash)} accent="amber" />
                                {character.is_dead && <MiniStat label="Died" value={fmt(character.deleted_at)} accent="red" />}
                            </div>

                            {character.stats && (
                                <>
                                    <div className="text-[9px] font-black uppercase tracking-widest text-slate-500 mt-2">Stats</div>
                                    <div className="grid grid-cols-5 gap-2">
                                        <StatPill label="OFF" value={character.stats.offense} color="text-red-400 bg-red-500/10 border-red-500/20" icon={Sword} />
                                        <StatPill label="DEF" value={character.stats.defense} color="text-cyan-400 bg-cyan-500/10 border-cyan-500/20" icon={ShieldCheck} />
                                        <StatPill label="INT" value={character.stats.intelligence} color="text-purple-400 bg-purple-500/10 border-purple-500/20" icon={Brain} />
                                        <StatPill label="INF" value={character.stats.influence} color="text-amber-400 bg-amber-500/10 border-amber-500/20" icon={Star} />
                                        <StatPill label="LCK" value={character.stats.luck} color="text-pink-400 bg-pink-500/10 border-pink-500/20" icon={Lightning} />
                                    </div>
                                </>
                            )}
                        </>
                    ) : (
                        <div className="py-6 text-center text-xs text-slate-600 italic border border-dashed border-slate-800/60 rounded-xl">
                            No character created
                        </div>
                    )}

                    {detail.user.is_banned && detail.user.ban_reason && (
                        <div className="p-3 rounded-xl bg-red-500/8 border border-red-500/20">
                            <div className="text-[9px] font-black uppercase tracking-widest text-red-500 mb-1">Ban Reason</div>
                            <div className="text-xs text-red-300 italic">"{detail.user.ban_reason}"</div>
                        </div>
                    )}
                </div>
            );
        }

        if (detailTab === 'achievements') {
            return achievements.length === 0 ? (
                <div className="py-8 text-center text-xs text-slate-600 italic">No achievements unlocked</div>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                    {achievements.map((a: any) => (
                        <div key={a.id} className="flex items-center gap-3 p-3 rounded-xl bg-slate-900/50 border border-slate-800/50">
                            {a.icon_url ? (
                                <img src={a.icon_url} alt="" className="w-8 h-8 object-contain shrink-0" />
                            ) : (
                                <div className="w-8 h-8 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center shrink-0">
                                    <Trophy size={14} className="text-amber-400/60" weight="fill" />
                                </div>
                            )}
                            <div className="min-w-0 flex-1">
                                <div className="text-xs font-bold text-white truncate">{a.name}</div>
                                {a.description && <div className="text-[10px] text-slate-500 truncate">{a.description}</div>}
                            </div>
                            <div className="text-[9px] text-slate-600 font-mono shrink-0">{fmt(a.unlocked_at)}</div>
                        </div>
                    ))}
                </div>
            );
        }

        if (detailTab === 'journal') {
            return journals.length === 0 ? (
                <div className="py-8 text-center text-xs text-slate-600 italic">No journal entries</div>
            ) : (
                <div className="space-y-1.5 max-h-[300px] overflow-y-auto pr-1">
                    {journals.map((j: any) => (
                        <div key={j.id} className="flex items-start gap-3 p-2.5 rounded-lg bg-slate-900/40 border border-slate-800/40">
                            <div className="w-6 h-6 rounded-md bg-slate-800 flex items-center justify-center shrink-0 mt-0.5">
                                <Article size={11} className="text-slate-500" />
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2">
                                    <span className="text-[11px] font-bold text-slate-300 truncate">{j.title}</span>
                                    <span className="text-[9px] text-slate-600 font-mono ml-auto shrink-0">{fmt(j.created_at)}</span>
                                </div>
                                {j.description && <div className="text-[10px] text-slate-500 mt-0.5 line-clamp-2">{j.description}</div>}
                                <Badge label={j.type} variant="slate" />
                            </div>
                        </div>
                    ))}
                </div>
            );
        }

        if (detailTab === 'messages') {
            return messages.length === 0 ? (
                <div className="py-8 text-center text-xs text-slate-600 italic">No messages</div>
            ) : (
                <div className="space-y-1.5 max-h-[300px] overflow-y-auto pr-1">
                    {messages.map((m: any) => (
                        <div key={m.id} className={`flex gap-3 p-2.5 rounded-lg border ${m.is_outgoing ? 'bg-cyan-500/5 border-cyan-500/15' : 'bg-slate-900/40 border-slate-800/40'}`}>
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2 text-[10px]">
                                    <span className={`font-bold ${m.is_outgoing ? 'text-cyan-400' : 'text-amber-400'}`}>
                                        {m.sender}
                                    </span>
                                    <span className="text-slate-700">→</span>
                                    <span className="text-slate-400 font-bold">{m.recipient}</span>
                                    <span className="text-[9px] text-slate-600 font-mono ml-auto">{fmt(m.created_at)}</span>
                                </div>
                                <div className="text-[11px] text-slate-300 mt-1 line-clamp-2">{m.body}</div>
                            </div>
                        </div>
                    ))}
                </div>
            );
        }

        return null;
    };

    return (
        <div className="space-y-4">
            {ConfirmNode}

            <AdminModal
                isOpen={!!banTarget}
                onClose={() => {
                    setBanTarget(null);
                    banForm.reset();
                }}
                title={`Ban — ${banTarget?.username}`}
                size="sm"
            >
                <form onSubmit={handleBanSubmit} className="space-y-4">
                    <div className="p-3 rounded-xl bg-red-500/8 border border-red-500/20 text-sm text-red-300 leading-relaxed">
                        Banning <strong className="text-white">{banTarget?.username}</strong>{' '}
                        will kill their character and lock the account.
                    </div>

                    <div>
                        <label className="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">
                            Duration
                        </label>
                        <div className="grid grid-cols-3 gap-1.5">
                            {[
                                { id: '1week', label: '1 Week' },
                                { id: '1month', label: '1 Month' },
                                { id: 'permanent', label: 'Permanent' },
                            ].map((d) => (
                                <button
                                    key={d.id}
                                    type="button"
                                    onClick={() => banForm.setData('duration', d.id)}
                                    className={[
                                        'h-9 rounded-lg text-[11px] font-black uppercase tracking-wider border transition',
                                        banForm.data.duration === d.id
                                            ? 'bg-red-500/20 border-red-500/40 text-red-200'
                                            : 'bg-slate-900 border-slate-800 text-slate-500 hover:text-white',
                                    ].join(' ')}
                                >
                                    {d.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    <AdminTextarea
                        label="Reason"
                        value={banForm.data.reason}
                        onChange={(e) => banForm.setData('reason', e.target.value)}
                        placeholder="State the reason for this ban…"
                        rows={3}
                        required
                    />
                    <FormActions
                        onCancel={() => {
                            setBanTarget(null);
                            banForm.reset();
                        }}
                        submitLabel={banForm.data.duration === 'permanent' ? 'Ban Permanently' : 'Ban Account'}
                        processing={banForm.processing}
                        danger
                    />
                </form>
            </AdminModal>

            <div className="flex flex-wrap gap-2">
                <form onSubmit={submitSearch} className="flex gap-2 flex-1 min-w-0">
                    <div className="relative flex-1 min-w-0">
                        <MagnifyingGlass
                            className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 pointer-events-none"
                            size={14}
                        />
                        <input
                            type="text"
                            placeholder="Search username or email…"
                            value={searchForm.data.search}
                            onChange={(e) => searchForm.setData('search', e.target.value)}
                            className="w-full pl-9 pr-3 h-9 bg-slate-900 border border-slate-800 rounded-lg text-sm text-white placeholder:text-slate-600 focus:outline-none focus:ring-1 focus:ring-amber-500/40 focus:border-amber-500/30 transition"
                        />
                    </div>
                    <select
                        value={searchForm.data.filter}
                        onChange={(e) => searchForm.setData('filter', e.target.value)}
                        className="h-9 px-3 bg-slate-900 border border-slate-800 rounded-lg text-sm text-white focus:outline-none focus:ring-1 focus:ring-amber-500/40 transition cursor-pointer"
                    >
                        <option value="all">All</option>
                        <option value="active">Active</option>
                        <option value="banned">Banned</option>
                        <option value="admin">Admins</option>
                    </select>
                    <button
                        type="submit"
                        className="h-9 px-4 bg-amber-500/15 border border-amber-500/25 hover:bg-amber-500/25 rounded-lg text-xs font-black uppercase tracking-wider text-amber-300 transition"
                    >
                        Filter
                    </button>
                </form>

                <div className="flex items-center gap-2 text-xs text-slate-600">
                    <span className="font-mono">{users.total.toLocaleString()} users</span>
                    <span>·</span>
                    <span>Page {users.current_page}</span>
                </div>
            </div>

            <TableShell
                headers={['User', 'Email', 'Character', 'Economy', 'Last Login', 'Status', '']}
                empty={users.data.length === 0 ? 'No users found' : undefined}
            >
                {users.data.map((user) => (
                    <React.Fragment key={user.id}>
                        <tr
                            className={[
                                'transition-colors cursor-pointer select-none',
                                expandedUser === user.id ? 'bg-slate-900/60' : 'hover:bg-slate-900/30',
                                user.is_banned
                                    ? 'border-l-2 border-red-500/50'
                                    : user.is_admin
                                        ? 'border-l-2 border-amber-500/40'
                                        : '',
                            ].join(' ')}
                            onClick={() => toggleExpand(user.id)}
                        >
                            <TD>
                                <div className="flex items-center gap-2.5">
                                    <div
                                        className={[
                                            'w-7 h-7 rounded-lg flex items-center justify-center text-xs font-black shrink-0 relative',
                                            user.is_banned
                                                ? 'bg-red-500/15 text-red-400'
                                                : user.is_admin
                                                    ? 'bg-amber-500/15 text-amber-400'
                                                    : 'bg-slate-800 text-slate-300',
                                        ].join(' ')}
                                    >
                                        {user.username.charAt(0).toUpperCase()}
                                        {user.is_admin && (
                                            <div className="absolute -top-1 -right-1 bg-slate-950 rounded-full p-0.5">
                                                <Shield weight="fill" className="text-amber-400 text-[10px]" />
                                            </div>
                                        )}
                                    </div>
                                    <div>
                                        <div className="font-bold text-white text-[13px]">{user.username}</div>
                                        <div className="text-[10px] text-slate-600 font-mono">#{user.id}</div>
                                    </div>
                                </div>
                            </TD>
                            <TD className="text-slate-400 font-mono text-xs">{user.email}</TD>
                            <TD>
                                {user.character ? (
                                    <span className="text-slate-300 text-[13px]">
                                        {user.character.display_name}
                                    </span>
                                ) : (
                                    <span className="text-slate-700 italic text-xs">none</span>
                                )}
                            </TD>
                            <TD>
                                {user.character ? (
                                    <div className="text-xs font-mono space-y-0.5">
                                        <div className="text-emerald-400">
                                            {formatCash(user.character.cash_on_hand + user.character.cash_in_bank)}
                                        </div>
                                        {user.character.dirty_cash > 0 && (
                                            <div className="text-amber-500/80">
                                                {formatCash(user.character.dirty_cash)} dirty
                                            </div>
                                        )}
                                    </div>
                                ) : (
                                    <span className="text-slate-700 text-xs">—</span>
                                )}
                            </TD>
                            <TD className="text-slate-500 text-xs font-mono whitespace-nowrap">
                                {fmt(user.last_login_at)}
                            </TD>
                            <TD>
                                <div className="flex items-center gap-1.5">
                                    {user.is_admin && <Badge label="Admin" variant="amber" />}
                                    {user.is_banned ? (
                                        <Badge label="Banned" variant="red" />
                                    ) : (
                                        <Badge label="Active" variant="green" />
                                    )}
                                </div>
                            </TD>
                            <TD className="text-right">
                                <CaretDown
                                    size={14}
                                    className={`text-slate-600 transition-transform ${expandedUser === user.id ? 'rotate-180' : ''}`}
                                />
                            </TD>
                        </tr>

                        {expandedUser === user.id && (
                            <tr className="bg-slate-900/40">
                                <td colSpan={7} className="px-4 py-4">
                                    {detailLoading === user.id ? (
                                        <div className="flex items-center justify-center gap-2 py-8">
                                            <SpinnerGap size={18} className="text-amber-400 animate-spin" />
                                            <span className="text-xs text-slate-500">Loading user data…</span>
                                        </div>
                                    ) : detailData[user.id] ? (
                                        <div className="space-y-4">
                                            <div className="flex gap-1 border-b border-slate-800/50 pb-2">
                                                {DETAIL_TABS.map(t => (
                                                    <button
                                                        key={t.id}
                                                        onClick={(e) => { e.stopPropagation(); setDetailTab(t.id); }}
                                                        className={[
                                                            'flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-wider transition',
                                                            detailTab === t.id
                                                                ? 'bg-amber-500/15 border border-amber-500/25 text-amber-300'
                                                                : 'text-slate-500 hover:text-slate-300 border border-transparent',
                                                        ].join(' ')}
                                                    >
                                                        <t.icon size={12} weight={detailTab === t.id ? 'fill' : 'regular'} />
                                                        {t.label}
                                                        {t.id === 'achievements' && detailData[user.id].achievements.length > 0 && (
                                                            <span className="text-[8px] opacity-60">{detailData[user.id].achievements.length}</span>
                                                        )}
                                                        {t.id === 'messages' && detailData[user.id].messages.length > 0 && (
                                                            <span className="text-[8px] opacity-60">{detailData[user.id].messages.length}</span>
                                                        )}
                                                    </button>
                                                ))}
                                            </div>

                                            {renderDetailContent(detailData[user.id])}

                                            <div className="flex gap-2 pt-3 border-t border-slate-800/50">
                                                {user.is_banned ? (
                                                    <button
                                                        onClick={(e) => { e.stopPropagation(); handleUnban(user); }}
                                                        className="h-8 px-4 bg-emerald-500/15 border border-emerald-500/25 rounded-lg text-xs font-black uppercase tracking-wider text-emerald-400 hover:bg-emerald-500/25 transition"
                                                    >
                                                        <UserCheck size={12} className="inline mr-1.5" weight="bold" />
                                                        Unban
                                                    </button>
                                                ) : (
                                                    !user.is_admin && (
                                                        <button
                                                            onClick={(e) => { e.stopPropagation(); setBanTarget(user); }}
                                                            className="h-8 px-4 bg-red-500/10 border border-red-500/20 rounded-lg text-xs font-black uppercase tracking-wider text-red-400 hover:bg-red-500/20 transition"
                                                        >
                                                            <Prohibit size={12} className="inline mr-1.5" weight="bold" />
                                                            Ban
                                                        </button>
                                                    )
                                                )}
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="py-6 text-center text-xs text-slate-600 italic">Failed to load user data</div>
                                    )}
                                </td>
                            </tr>
                        )}
                    </React.Fragment>
                ))}
            </TableShell>

            <PageControls links={users.links} />
        </div>
    );
}

function MiniStat({ label, value, mono, accent, icon: Icon }: {
    label: string;
    value: string | number;
    mono?: boolean;
    accent?: 'emerald' | 'amber' | 'red' | 'green' | 'cyan' | 'purple';
    icon?: React.ElementType;
}) {
    const accentColor = accent
        ? { emerald: 'text-emerald-400', amber: 'text-amber-400', red: 'text-red-400', green: 'text-emerald-400', cyan: 'text-cyan-400', purple: 'text-purple-400' }[accent]
        : 'text-white';
    return (
        <div className="p-2.5 rounded-lg bg-slate-950/50 border border-slate-800/40">
            <div className="text-[9px] font-black uppercase tracking-widest text-slate-600 mb-0.5 flex items-center gap-1">
                {Icon && <Icon size={10} />}
                {label}
            </div>
            <div className={`text-xs font-bold truncate ${accentColor} ${mono ? 'font-mono' : ''}`}>{value}</div>
        </div>
    );
}

function StatPill({ label, value, color, icon: Icon }: {
    label: string;
    value: number;
    color: string;
    icon: React.ElementType;
}) {
    return (
        <div className={`flex items-center gap-2 px-3 py-2 rounded-lg border ${color}`}>
            <Icon size={13} weight="bold" />
            <div>
                <div className="text-[9px] font-black uppercase tracking-wider opacity-70">{label}</div>
                <div className="text-sm font-black tabular-nums">{value}</div>
            </div>
        </div>
    );
}

