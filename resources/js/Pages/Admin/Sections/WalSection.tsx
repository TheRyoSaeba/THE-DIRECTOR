import { Badge, StatCard, TableShell, TD } from '../Components';

import type { LaravelLog, WalDiagnostics } from './types';

function valueOrDash(value?: string | number | null): string {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    return String(value);
}

function formatNumber(value?: number | null): string {
    return value === null || value === undefined ? '-' : value.toLocaleString();
}

function formatPercent(value?: number | null): string {
    return value === null || value === undefined ? '-' : `${Number(value).toFixed(2)}%`;
}

function prettyBytes(value?: number | null): string {
    if (value === null || value === undefined) {
        return '-';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let amount = value;
    let unit = 0;

    while (amount >= 1024 && unit < units.length - 1) {
        amount /= 1024;
        unit++;
    }

    return `${amount.toFixed(unit === 0 ? 0 : amount >= 10 ? 1 : 2)} ${units[unit]}`;
}

function boolLabel(value?: boolean): string {
    return value ? 'on' : 'off';
}

function logVariant(level: string): 'green' | 'red' | 'amber' | 'cyan' | 'purple' | 'slate' {
    const normalized = level.toLowerCase();

    if (['error', 'critical', 'alert', 'emergency'].includes(normalized)) {
        return 'red';
    }

    if (['warning', 'warn'].includes(normalized)) {
        return 'amber';
    }

    if (['notice', 'info'].includes(normalized)) {
        return 'cyan';
    }

    return 'slate';
}

function SectionTitle({ title, detail }: { title: string; detail?: string }) {
    return (
        <div className="border-b border-slate-800/70 pb-3">
            <h2 className="text-[11px] font-black uppercase tracking-[0.2em] text-cyan-300">{title}</h2>
            {detail && <p className="mt-1 text-xs font-medium text-slate-300">{detail}</p>}
        </div>
    );
}

function InfoRows({ rows }: { rows: { label: string; value: string; mono?: boolean }[] }) {
    return (
        <div className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
            {rows.map((row) => (
                <div key={row.label} className="border-b border-slate-800/50 pb-2">
                    <div className="text-[9px] font-black uppercase tracking-widest text-slate-500">{row.label}</div>
                    <div className={['mt-1 text-sm font-bold text-white', row.mono ? 'font-mono break-all' : ''].join(' ')}>
                        {row.value}
                    </div>
                </div>
            ))}
        </div>
    );
}

function RuntimeLogs({ logs }: { logs: LaravelLog[] }) {
    if (!logs.length) {
        return <div className="py-8 text-center text-xs font-bold text-slate-500">No runtime logs available.</div>;
    }

    return (
        <div className="max-h-[360px] overflow-y-auto border-y border-slate-800/70 divide-y divide-slate-800/50">
            {logs.map((log, index) => (
                <article key={`${log.timestamp}-${index}`} className="py-3">
                    <div className="mb-1 flex flex-wrap items-center gap-2">
                        <Badge label={log.level} variant={logVariant(log.level)} />
                        <span className="font-mono text-[11px] text-slate-500">{log.timestamp}</span>
                    </div>
                    <pre className="whitespace-pre-wrap break-words font-mono text-[11px] leading-relaxed text-slate-300">
                        {log.message}
                    </pre>
                </article>
            ))}
        </div>
    );
}

export function WalSection({ wal }: { wal: WalDiagnostics }) {
    const settingsRows = Object.entries(wal.settings ?? {}).sort((a, b) => a[0].localeCompare(b[0]));
    const runtime = wal.runtime;
    const logs = wal.logs ?? [];

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="text-[10px] font-black uppercase tracking-[0.22em] text-slate-500">
                        Admin Diagnostics
                    </div>
                    <h1 className="mt-1 text-xl font-black tracking-tight text-white">Database Health / FrankenPHP Logs</h1>

                </div>
                <Badge label={wal.meta.connected ? 'database online' : 'database offline'} variant={wal.meta.connected ? 'green' : 'red'} />
            </div>

            {wal.notes?.length > 0 && (
                <div className="rounded-xl border border-amber-500/20 bg-amber-500/5 p-3">
                    <div className="mb-2 text-[10px] font-black uppercase tracking-widest text-amber-300">Notes</div>
                    <ul className="space-y-1 text-xs font-medium text-amber-100/80">
                        {wal.notes.map((note, index) => (
                            <li key={index}>- {note}</li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:items-start">
                <section className="space-y-4 rounded-2xl border border-slate-800/70 bg-slate-950/40 p-4 lg:max-h-[calc(100vh-13rem)] lg:overflow-y-auto">
                    <SectionTitle title="Database" detail={wal.meta.server_version ?? undefined} />

                    <div className="grid grid-cols-2 gap-3">
                        <StatCard label="DB Ping" value={wal.meta.latency_ms === null ? '-' : `${wal.meta.latency_ms} ms`} accent={wal.meta.connected ? 'emerald' : 'red'} />
                        <StatCard label="Connections" value={`${formatNumber(wal.connections.total)} / ${valueOrDash(wal.connections.max)}`} sub={`${formatNumber(wal.connections.active)} active`} accent="cyan" />
                        <StatCard label="Cache Hit" value={formatPercent(wal.database_stats.cache_hit_ratio)} accent="emerald" />
                        <StatCard label="DB Size" value={wal.database_size.pretty ?? prettyBytes(wal.database_size.bytes)} accent="amber" />
                    </div>

                    <InfoRows
                        rows={[
                            { label: 'Connection', value: `${wal.meta.connection} / ${wal.meta.driver}` },
                            { label: 'Database', value: valueOrDash(wal.meta.database) },
                            { label: 'Commits', value: formatNumber(wal.database_stats.commits) },
                            { label: 'Rollbacks', value: formatNumber(wal.database_stats.rollbacks) },
                            { label: 'Deadlocks', value: formatNumber(wal.database_stats.deadlocks) },
                            { label: 'Temp Bytes', value: prettyBytes(wal.database_stats.temp_bytes) },
                            { label: 'Current WAL File', value: valueOrDash(wal.wal?.current_file), mono: true },
                            { label: 'Current LSN', value: valueOrDash(wal.wal?.current_lsn), mono: true },
                        ]}
                    />

                    <div className="grid gap-4 2xl:grid-cols-2">
                        <div className="max-h-[260px] overflow-y-auto">
                            <TableShell headers={['Setting', 'Value']} empty={settingsRows.length === 0 ? 'No settings available' : undefined}>
                                {settingsRows.map(([key, value]) => (
                                    <tr key={key} className="hover:bg-slate-900/30 transition">
                                        <TD className="font-mono text-xs text-slate-500">{key}</TD>
                                        <TD className="break-all font-mono text-xs text-slate-300">{value ?? '-'}</TD>
                                    </tr>
                                ))}
                            </TableShell>
                        </div>

                        <div className="max-h-[260px] overflow-y-auto">
                            <TableShell headers={['Lock', 'Granted', 'Count']} empty={!wal.locks.length ? 'No locks found' : undefined}>
                                {wal.locks.map((lock) => (
                                    <tr key={`${lock.mode}-${String(lock.granted)}`} className="hover:bg-slate-900/30 transition">
                                        <TD className="font-mono text-xs text-slate-300">{lock.mode}</TD>
                                        <TD className={lock.granted ? 'text-emerald-300 text-xs font-bold' : 'text-amber-300 text-xs font-bold'}>
                                            {lock.granted ? 'yes' : 'waiting'}
                                        </TD>
                                        <TD className="font-mono text-xs text-slate-300">{formatNumber(lock.count)}</TD>
                                    </tr>
                                ))}
                            </TableShell>
                        </div>
                    </div>

                    <div className="max-h-[260px] overflow-y-auto">
                        <TableShell headers={['PID', 'State', 'Wait', 'Duration', 'Query']} empty={!wal.long_running.length ? 'No active long-running queries' : undefined}>
                            {wal.long_running.map((query) => (
                                <tr key={query.pid} className="hover:bg-slate-900/30 transition">
                                    <TD className="font-mono text-xs text-slate-500">{query.pid}</TD>
                                    <TD className="text-xs font-bold text-slate-300">{query.state}</TD>
                                    <TD className="text-xs text-slate-400">{query.wait || '-'}</TD>
                                    <TD className="whitespace-nowrap font-mono text-xs text-amber-300">{query.duration}</TD>
                                    <TD>
                                        <code className="line-clamp-2 break-all font-mono text-[11px] text-slate-400">{query.query}</code>
                                    </TD>
                                </tr>
                            ))}
                        </TableShell>
                    </div>
                </section>

                <section className="space-y-4 rounded-2xl border border-slate-800/70 bg-slate-950/40 p-4 lg:max-h-[calc(100vh-13rem)] lg:overflow-y-auto">
                    <SectionTitle title="FrankenPHP / Laravel" detail={`Checked ${wal.checked_at ?? '-'}`} />

                    <div className="grid grid-cols-2 gap-3">
                        <StatCard label="Runtime" value={runtime?.server ?? '-'} sub={`Octane ${boolLabel(runtime?.octane)}`} accent="purple" />
                        <StatCard label="Rate Limit" value={runtime?.cache.limiter_store ?? '-'} sub={runtime?.cache.redis_client ? `Redis ${runtime.cache.redis_client}` : undefined} accent="cyan" />
                        <StatCard label="OPcache" value={formatPercent(runtime?.opcache.hit_rate)} sub={boolLabel(runtime?.opcache.enabled)} accent="emerald" />
                        <StatCard label="Memory" value={prettyBytes(runtime?.memory.usage_bytes)} sub={`peak ${prettyBytes(runtime?.memory.peak_bytes)}`} accent="amber" />
                    </div>

                    <InfoRows
                        rows={[
                            { label: 'PHP', value: runtime?.php_version ?? '-' },
                            { label: 'SAPI', value: runtime?.sapi ?? '-' },
                            { label: 'Session Driver', value: runtime?.cache.session_driver ?? '-' },
                            { label: 'Cache Store', value: runtime?.cache.default_store ?? '-' },
                            { label: 'Memory Limit', value: runtime?.memory.limit ?? '-' },
                            { label: 'Cloud Run Service', value: runtime?.cloud_run.service ?? '-' },
                            { label: 'Cloud Run Revision', value: runtime?.cloud_run.revision ?? '-', mono: true },
                        ]}
                    />

                    <div className="space-y-3">
                        <SectionTitle title="Recent Runtime Logs" detail="Laravel log locally, Cloud Run stdout/stderr and error request logs in production." />
                        <RuntimeLogs logs={logs} />
                    </div>
                </section>
            </div>
        </div>
    );
}
