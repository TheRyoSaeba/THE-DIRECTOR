import React, { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { CaretDown, MagnifyingGlass } from '@phosphor-icons/react';
import { formatCash } from '@/Layouts/GameLayoutComponents';

import { AdminModal, PageControls, StatCard, SubTabs, TableShell, TD, TH } from '../Components';

import type { ActivityLogEntry, LaravelLog, LogFile, Stats } from './types';

const LOG_LEVEL_STYLES: Record<string, { dot: string; badge: string; row: string }> = {
    emergency: {
        dot: 'bg-red-400',
        badge: 'bg-red-500/15 text-red-300 border-red-500/25',
        row: 'bg-red-500/5',
    },
    alert: {
        dot: 'bg-red-400',
        badge: 'bg-red-500/15 text-red-300 border-red-500/25',
        row: 'bg-red-500/5',
    },
    critical: {
        dot: 'bg-red-400',
        badge: 'bg-red-500/15 text-red-300 border-red-500/25',
        row: 'bg-red-500/5',
    },
    error: {
        dot: 'bg-red-400',
        badge: 'bg-red-500/15 text-red-300 border-red-500/25',
        row: 'bg-red-500/5',
    },
    warning: {
        dot: 'bg-amber-400',
        badge: 'bg-amber-500/15 text-amber-300 border-amber-500/25',
        row: 'bg-amber-500/4',
    },
    notice: {
        dot: 'bg-cyan-400',
        badge: 'bg-cyan-500/15 text-cyan-300 border-cyan-500/25',
        row: '',
    },
    info: {
        dot: 'bg-cyan-500',
        badge: 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',
        row: '',
    },
    debug: {
        dot: 'bg-slate-600',
        badge: 'bg-slate-800/60 text-slate-500 border-slate-700/50',
        row: 'opacity-70',
    },
};

export function ActivitySection({
    logs,
    stats,
    laravel_logs,
    log_files,
    current_log,
}: {
    logs: { data: ActivityLogEntry[]; links: any[] };
    stats: Stats;
    laravel_logs: LaravelLog[];
    log_files: LogFile[];
    current_log: string;
}) {
    const [tab, setTab] = useState<'activity' | 'laravel'>('activity');
    const [actionFilter, setActionFilter] = useState('');
    const [levelFilter, setLevelFilter] = useState('all');
    const [expandedLaravel, setExpandedLaravel] = useState<number | null>(null);
    const [changesModal, setChangesModal] = useState<{ open: boolean; data: any }>({
        open: false,
        data: null,
    });

    const fmt = (d: string) =>
        new Date(d).toLocaleString('en-GB', {
            day: '2-digit',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });

    const fmtBytes = (b: number) =>
        b > 1024 * 1024 ? `${(b / 1024 / 1024).toFixed(1)} MB` : `${(b / 1024).toFixed(0)} KB`;

    const filteredActivity = useMemo(() => {
        if (!actionFilter) return logs.data;
        return logs.data.filter((l) => l.action.toLowerCase().includes(actionFilter.toLowerCase()));
    }, [logs.data, actionFilter]);

    const filteredLaravel = useMemo(() => {
        if (levelFilter === 'all') return laravel_logs;
        return laravel_logs.filter((l) => l.level === levelFilter);
    }, [laravel_logs, levelFilter]);

    const laravelLevelCounts = useMemo(() => {
        const counts: Record<string, number> = {};
        for (const l of laravel_logs) {
            counts[l.level] = (counts[l.level] ?? 0) + 1;
        }
        return counts;
    }, [laravel_logs]);

    const actionGroups = useMemo(() => {
        const groups: Record<string, number> = {};
        for (const l of logs.data) {
            const prefix = l.action.split('.')[0];
            groups[prefix] = (groups[prefix] ?? 0) + 1;
        }
        return Object.entries(groups)
            .sort((a, b) => b[1] - a[1])
            .slice(0, 5);
    }, [logs.data]);

    return (
        <div className="space-y-5">
           
            <div className="grid grid-cols-3 sm:grid-cols-6 gap-3">
                <StatCard label="Total Users" value={stats.total_users} accent="cyan" />
                <StatCard label="Active (7d)" value={stats.active_users} accent="emerald" />
                <StatCard label="Banned" value={stats.banned_users} accent="red" />
                <StatCard
                    label="Characters"
                    value={stats.alive_characters}
                    sub={`${stats.total_characters} total`}
                    accent="purple"
                />
                <StatCard label="Clean Economy" value={formatCash(stats.total_economy)} accent="emerald" />
                <StatCard label="Dirty Economy" value={formatCash(stats.dirty_economy)} accent="amber" />
            </div>

            <SubTabs
                tabs={[
                    { id: 'activity', label: 'Admin Activity' },
                    { id: 'laravel', label: 'Laravel Log' },
                ]}
                active={tab}
                onChange={(v) => setTab(v as any)}
            />

 
            {tab === 'activity' && (
                <div className="space-y-3">
 
                    <div className="flex flex-wrap gap-2 items-center">
                        <div className="relative flex-1 min-w-[200px]">
                            <MagnifyingGlass
                                className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 pointer-events-none"
                                size={13}
                            />
                            <input
                                type="text"
                                placeholder="Filter action (e.g. earn.create)…"
                                value={actionFilter}
                                onChange={(e) => setActionFilter(e.target.value)}
                                className="w-full pl-8 pr-3 h-8 bg-slate-900 border border-slate-800 rounded-lg text-xs text-white placeholder:text-slate-600 focus:outline-none focus:ring-1 focus:ring-amber-500/40 transition"
                            />
                        </div>
 
                        {actionGroups.map(([prefix, count]) => (
                            <button
                                key={prefix}
                                onClick={() => setActionFilter(actionFilter === prefix ? '' : prefix)}
                                className={[
                                    'h-8 px-3 rounded-lg text-[10px] font-black uppercase tracking-wider transition border',
                                    actionFilter === prefix
                                        ? 'bg-amber-500/20 border-amber-500/30 text-amber-300'
                                        : 'bg-slate-900 border-slate-800 text-slate-500 hover:text-slate-300',
                                ].join(' ')}
                            >
                                {prefix} <span className="opacity-50 ml-1">{count}</span>
                            </button>
                        ))}
                    </div>

                    <TableShell
                        headers={['Action', 'Admin', 'Subject', 'IP', 'Changes', 'Time']}
                        empty={
                            filteredActivity.length === 0
                                ? 'No log entries match the current filter'
                                : undefined
                        }
                    >
                        {filteredActivity.map((log) => (
                            <tr key={log.id} className="hover:bg-slate-900/30 transition">
                                <TD>
                                    <code className="text-[11px] text-cyan-400 font-mono bg-cyan-500/8 px-1.5 py-0.5 rounded">
                                        {log.action}
                                    </code>
                                </TD>
                                <TD className="text-slate-300 text-xs">
                                    {log.user?.username ?? (
                                        <span className="text-slate-600 italic">system</span>
                                    )}
                                </TD>
                                <TD className="text-slate-500 text-xs font-mono">
                                    {log.subject_type
                                        ? `${log.subject_type.split('\\').pop()} #${log.subject_id}`
                                        : '—'}
                                </TD>
                                <TD className="text-slate-600 text-xs font-mono">{log.ip_address ?? '—'}</TD>
                                <TD>
                                    {log.changes && Object.keys(log.changes).length > 0 ? (
                                        <button
                                            onClick={() =>
                                                setChangesModal({
                                                    open: true,
                                                    data: log.changes,
                                                })
                                            }
                                            className="h-6 px-2.5 bg-slate-800 border border-slate-700 rounded text-[10px] font-bold text-slate-400 hover:text-cyan-400 hover:border-cyan-500/30 transition"
                                        >
                                            View
                                        </button>
                                    ) : (
                                        <span className="text-slate-700 text-xs">—</span>
                                    )}
                                </TD>
                                <TD className="text-slate-500 text-[11px] font-mono whitespace-nowrap">
                                    {fmt(log.created_at)}
                                </TD>
                            </tr>
                        ))}
                    </TableShell>
                    <PageControls links={logs.links ?? []} />
                </div>
            )}
 
            {tab === 'laravel' && (
                <div className="space-y-3">
 
                    <div className="flex flex-wrap gap-2 items-center">
 
                        {log_files.length > 1 && (
                            <div className="flex gap-1.5">
                                {log_files.map((f) => (
                                    <button
                                        key={f.name}
                                        onClick={() =>
                                            router.get(
                                                route('admin.index'),
                                                { section: 'activity', log_file: f.name },
                                                { preserveState: true, preserveScroll: true },
                                            )
                                        }
                                        className={[
                                            'h-8 px-3 rounded-lg text-[10px] font-black uppercase tracking-wider border transition',
                                            current_log === f.name
                                                ? 'bg-amber-500/15 border-amber-500/25 text-amber-300'
                                                : 'bg-slate-900 border-slate-800 text-slate-500 hover:text-white',
                                        ].join(' ')}
                                    >
                                        {f.name}
                                        <span className="ml-1.5 opacity-40">{fmtBytes(f.size)}</span>
                                    </button>
                                ))}
                            </div>
                        )}

  
                        <div className="flex gap-1.5 flex-wrap">
                            <button
                                onClick={() => setLevelFilter('all')}
                                className={[
                                    'h-8 px-3 rounded-lg text-[10px] font-black uppercase tracking-wider border transition',
                                    levelFilter === 'all'
                                        ? 'bg-slate-700 border-slate-600 text-white'
                                        : 'bg-slate-900 border-slate-800 text-slate-500 hover:text-white',
                                ].join(' ')}
                            >
                                All <span className="opacity-40 ml-1">{laravel_logs.length}</span>
                            </button>
                            {Object.entries(laravelLevelCounts)
                                .sort((a, b) => b[1] - a[1])
                                .map(([level, count]) => {
                                    const s = LOG_LEVEL_STYLES[level] ?? LOG_LEVEL_STYLES.info;
                                    return (
                                        <button
                                            key={level}
                                            onClick={() =>
                                                setLevelFilter(levelFilter === level ? 'all' : level)
                                            }
                                            className={[
                                                'h-8 px-3 rounded-lg text-[10px] font-black uppercase tracking-wider border transition flex items-center gap-1.5',
                                                levelFilter === level
                                                    ? s.badge
                                                    : 'bg-slate-900 border-slate-800 text-slate-500 hover:text-white',
                                            ].join(' ')}
                                        >
                                            <span className={`w-1.5 h-1.5 rounded-full ${s.dot}`} />
                                            {level}
                                            <span className="opacity-40">{count}</span>
                                        </button>
                                    );
                                })}
                        </div>

                        <span className="ml-auto text-[10px] text-slate-600 font-mono">
                            {current_log}.log · last 100 entries
                        </span>
                    </div>
 
                    <div className="rounded-xl border border-slate-800/70 overflow-hidden bg-slate-950/40">
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead>
                                    <tr className="border-b border-slate-800/70 bg-slate-900/60">
                                        <TH className="w-36">Timestamp</TH>
                                        <TH className="w-24">Level</TH>
                                        <TH>Message</TH>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-800/40">
                                    {filteredLaravel.length === 0 ? (
                                        <tr>
                                            <td colSpan={3} className="py-10 text-center text-xs text-slate-600 italic">
                                                {laravel_logs.length === 0
                                                    ? `No logs found in ${current_log}.log`
                                                    : 'No entries at this level'}
                                            </td>
                                        </tr>
                                    ) : (
                                        filteredLaravel.map((entry, i) => {
                                            const s = LOG_LEVEL_STYLES[entry.level] ?? LOG_LEVEL_STYLES.info;
                                            const isExpanded = expandedLaravel === i;
                                            const firstLine = entry.message.split('\n')[0];
                                            const hasMore = entry.message.includes('\n');
                                            return (
                                                <React.Fragment key={i}>
                                                    <tr
                                                        className={[
                                                            'transition',
                                                            s.row,
                                                            hasMore ? 'cursor-pointer hover:bg-slate-900/40' : '',
                                                        ].join(' ')}
                                                        onClick={() => hasMore && setExpandedLaravel(isExpanded ? null : i)}
                                                    >
                                                        <TD className="text-[11px] text-slate-500 font-mono whitespace-nowrap">
                                                            {entry.timestamp}
                                                        </TD>
                                                        <TD>
                                                            <span className={`inline-flex items-center gap-1.5 h-5 px-2 rounded text-[9px] font-black uppercase tracking-wider border ${s.badge}`}>
                                                                <span className={`w-1.5 h-1.5 rounded-full ${s.dot}`} />
                                                                {entry.level}
                                                            </span>
                                                        </TD>
                                                        <TD>
                                                            <div className="flex items-center gap-2">
                                                                <span className="text-[11px] text-slate-300 font-mono break-all leading-relaxed">
                                                                    {firstLine}
                                                                </span>
                                                                {hasMore && (
                                                                    <CaretDown
                                                                        size={11}
                                                                        className={`text-slate-600 shrink-0 transition-transform ${isExpanded ? 'rotate-180' : ''}`}
                                                                    />
                                                                )}
                                                            </div>
                                                        </TD>
                                                    </tr>
                                                    {isExpanded && hasMore && (
                                                        <tr className={s.row}>
                                                            <td colSpan={3} className="px-3 pb-3">
                                                                <pre className="text-[10px] text-slate-400 font-mono whitespace-pre-wrap break-all leading-relaxed bg-black/30 rounded-lg p-3 border border-slate-800/50 mt-1">
                                                                    {entry.message}
                                                                </pre>
                                                            </td>
                                                        </tr>
                                                    )}
                                                </React.Fragment>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            )}
 
            <AdminModal
                isOpen={changesModal.open}
                onClose={() => setChangesModal({ open: false, data: null })}
                title="Change Record"
                size="lg"
            >
                <pre className="text-[11px] text-cyan-400 font-mono whitespace-pre-wrap break-all leading-relaxed bg-black/40 rounded-xl p-4 border border-slate-800/50">
                    {JSON.stringify(changesModal.data, null, 2)}
                </pre>
            </AdminModal>
        </div>
    );
}

