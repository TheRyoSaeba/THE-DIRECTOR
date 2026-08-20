import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { ArrowsClockwise, Plus } from '@phosphor-icons/react';

import {
    AdminInput,
    AdminModal,
    AdminTextarea,
    AdminToggle,
    Badge,
    FormActions,
    PageControls,
    SubTabs,
    TableShell,
    TD,
    useConfirm,
} from '../Components';

import type { Announcement, CronJob } from './types';

export function EngineSection({
    isInstalled,
    jobs,
    announcements,
}: {
    isInstalled: boolean;
    jobs: CronJob[];
    announcements: { data: Announcement[]; links: any[] };
}) {
    const [tab, setTab] = useState<'cron' | 'announcements'>('cron');
    const [cronModal, setCronModal] = useState(false);
    const [editCron, setEditCron] = useState<CronJob | null>(null);
    const [annModal, setAnnModal] = useState(false);
    const [editAnn, setEditAnn] = useState<Announcement | null>(null);
    const [syncing, setSyncing] = useState(false);
    const { confirm, ConfirmNode } = useConfirm();

    const cronForm = useForm({
        jobid: 0,
        jobname: '',
        schedule: '',
        command: '',
        active: true,
        database: '',
    });

    const openCron = (job: CronJob) => {
        cronForm.setData(job as any);
        setEditCron(job);
        setCronModal(true);
    };

    const submitCron = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(route('admin.cron.update'), cronForm.data, {
            onSuccess: () => {
                setCronModal(false);
                setEditCron(null);
            },
        });
    };

    const toggleCron = (job: CronJob) => {
        confirm(
            job.active ? `Pause "${job.jobname}"?` : `Activate "${job.jobname}"?`,
            () => router.post(route('admin.cron.toggle'), { job_id: job.jobid, active: !job.active }),
            job.active ? 'The job will stop running until reactivated.' : 'The job will resume on its schedule.',
            job.active,
        );
    };

    const handleSync = () => {
        setSyncing(true);
        router.post(route('admin.cron.sync'), {}, { onFinish: () => setSyncing(false) });
    };



    const annForm = useForm({
        title: '',
        message: '',
        type: 'info' as Announcement['type'],
        is_active: true,
        published_at: '' as string | '',
        expires_at: '' as string | '',
        also_email: false,
    });

    const openAnn = (ann?: Announcement) => {
        // datetime-local accepts "YYYY-MM-DDTHH:MM" (no timezone). We store
        // UTC server-side, so we format the value as UTC components and let
        // the admin understand they're editing UTC. Label below the input
        // reinforces this.
        const fmt = (value?: string | null) => {
            if (!value) return '';
            const d = new Date(value);
            const pad = (n: number) => n.toString().padStart(2, '0');
            const year = d.getUTCFullYear();
            const month = pad(d.getUTCMonth() + 1);
            const day = pad(d.getUTCDate());
            const hours = pad(d.getUTCHours());
            const minutes = pad(d.getUTCMinutes());
            return `${year}-${month}-${day}T${hours}:${minutes}`;
        };

        if (ann) {
            annForm.setData({
                title: ann.title,
                message: ann.message,
                type: ann.type,
                is_active: ann.is_active,
                published_at: fmt(ann.published_at),
                expires_at: fmt(ann.expires_at ?? null),
                also_email: false,
            });
            setEditAnn(ann);
        } else {
            annForm.reset();
            annForm.setData({
                title: '',
                message: '',
                type: 'info' as Announcement['type'],
                is_active: true,
                published_at: '',
                expires_at: '',
                also_email: false,
            });
            setEditAnn(null);
        }
        setAnnModal(true);
    };

    const submitAnn = (e: React.FormEvent) => {
        e.preventDefault();
        const url = editAnn ? route('admin.announcements.update', editAnn.id) : route('admin.announcements.create');
        annForm.post(url, {
            onSuccess: () => {
                setAnnModal(false);
                setEditAnn(null);
            },
        });
    };

    const deleteAnn = (ann: Announcement) => {
        confirm(
            `Delete "${ann.title}"?`,
            () => router.delete(route('admin.announcements.delete', ann.id)),
            'This announcement will be permanently removed.',
            true,
        );
    };

    const ANN_TYPE_STYLE: Record<string, string> = {
        info: 'text-cyan-400 bg-cyan-500/10 border-cyan-500/20',
        warning: 'text-amber-400 bg-amber-500/10 border-amber-500/20',
    };

    const JOB_DESC: Record<string, string> = {
        health_regen: 'Regenerates player health over time',
        strength_recovery: 'Recovers player strength stat',
        auto_logout: 'Logs out inactive players',
        daily_maintenance: 'Daily cleanup tasks',
        restock_shops: 'Restocks shops on schedule',
    };

    return (
        <>
            {ConfirmNode}

            <SubTabs
                tabs={[
                    { id: 'cron', label: 'Cron Jobs' },
                    { id: 'announcements', label: `Announcements (${announcements.data.length})` },
                ]}
                active={tab}
                onChange={(v) => setTab(v as any)}
            />

           
            {tab === 'cron' && (
                <div className="space-y-3">
                    <div className="flex items-center justify-between">
                        <div
                            className={[
                                'flex items-center gap-2 px-3 py-1.5 rounded-lg border text-xs font-bold',
                                isInstalled
                                    ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400'
                                    : 'bg-red-500/10 border-red-500/20 text-red-400',
                            ].join(' ')}
                        >
                            <span
                                className={`w-1.5 h-1.5 rounded-full ${isInstalled ? 'bg-emerald-400 animate-pulse' : 'bg-red-500'}`}
                            />
                            {isInstalled ? 'pg_cron Active' : 'pg_cron Offline'}
                        </div>
                        <button
                            onClick={handleSync}
                            disabled={syncing || !isInstalled}
                            className="flex items-center gap-1.5 h-8 px-4 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg text-xs font-bold text-slate-300 transition disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            <ArrowsClockwise size={13} className={syncing ? 'animate-spin' : ''} weight="bold" />
                            {syncing ? 'Syncing…' : 'Sync Jobs'}
                        </button>
                    </div>

                    <TableShell
                        headers={['Job', 'Schedule', 'Status', 'SQL Command', '']}
                        empty={!jobs?.length ? 'No cron jobs found. Click Sync to import from pg_cron.' : undefined}
                    >
                        {jobs?.map((job) => (
                            <tr key={job.jobid} className="hover:bg-slate-900/30 transition">
                                <TD>
                                    <div>
                                        <div className="font-semibold text-white text-[13px]">{job.jobname}</div>
                                        <div className="text-[10px] text-slate-600 font-mono mt-0.5">
                                            {JOB_DESC[job.jobname] ?? 'Scheduled database task'}
                                        </div>
                                    </div>
                                </TD>
                                <TD>
                                    <div className="space-y-0.5">
                                        <div className="text-[11px] text-cyan-400 font-mono bg-cyan-500/8 px-1.5 py-0.5 rounded inline-block">
                                            {job.schedule}
                                        </div>
                                    </div>
                                </TD>
                                <TD>{job.active ? <Badge label="Active" variant="green" /> : <Badge label="Paused" variant="slate" />}</TD>
                                <TD className="max-w-xs">
                                    <code className="text-[10px] text-slate-500 font-mono break-all line-clamp-2">
                                        {job.command}
                                    </code>
                                </TD>
                                <TD className="text-right">
                                    <div className="flex gap-1 justify-end">
                                        <button
                                            onClick={() => openCron(job)}
                                            className="h-7 px-3 bg-slate-800 border border-slate-700 rounded-lg text-[10px] font-bold text-slate-400 hover:text-white hover:border-slate-600 transition"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            onClick={() => toggleCron(job)}
                                            className={[
                                                'h-7 px-3 rounded-lg text-[10px] font-bold border transition',
                                                job.active
                                                    ? 'bg-red-500/10 border-red-500/20 text-red-400 hover:bg-red-500/20'
                                                    : 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20',
                                            ].join(' ')}
                                        >
                                            {job.active ? 'Pause' : 'Activate'}
                                        </button>
                                    </div>
                                </TD>
                            </tr>
                        ))}
                    </TableShell>

                    <p className="text-[10px] text-slate-700 text-center italic pt-1">
                        Cron jobs cannot be deleted from this panel. Use the database directly.
                    </p>
                </div>
            )}

            
            {tab === 'announcements' && (
                <div className="space-y-3">
                    <div className="flex justify-end">
                        <button
                            onClick={() => openAnn()}
                            className="flex items-center gap-1.5 h-8 px-4 bg-amber-500/15 border border-amber-500/25 hover:bg-amber-500/25 rounded-lg text-xs font-black uppercase tracking-wider text-amber-300 transition"
                        >
                            <Plus size={12} weight="bold" /> New Announcement
                        </button>
                    </div>

                    <TableShell
                        headers={['Title', 'Message', 'Published', 'Expires', 'Status', '']}
                        empty={announcements.data.length === 0 ? 'No announcements yet' : undefined}
                    >
                        {announcements.data.map((ann) => (
                            <tr key={ann.id} className="hover:bg-slate-900/30 transition">
                                <TD className="font-semibold text-white text-[13px]">{ann.title}</TD>
                                <TD className="text-slate-400 text-xs max-w-xs">
                                    <span className="line-clamp-2">{ann.message}</span>
                                </TD>
                                <TD className="text-slate-500 text-[11px] font-mono whitespace-nowrap">
                                    {ann.published_at
                                        ? new Date(ann.published_at).toLocaleDateString('en-GB', {
                                            day: '2-digit',
                                            month: 'short',
                                            year: '2-digit',
                                        })
                                        : '—'}
                                </TD>
                                <TD className="text-slate-500 text-[11px] font-mono whitespace-nowrap">
                                    {ann.expires_at
                                        ? new Date(ann.expires_at).toLocaleDateString('en-GB', {
                                            day: '2-digit',
                                            month: 'short',
                                            year: '2-digit',
                                        })
                                        : '—'}
                                </TD>
                                <TD>{ann.is_active ? <Badge label="Live" variant="green" /> : <Badge label="Draft" variant="slate" />}</TD>
                                <TD className="text-right">
                                    <div className="flex gap-1 justify-end">
                                        <button
                                            onClick={() => openAnn(ann)}
                                            className="h-7 px-3 bg-slate-800 border border-slate-700 rounded-lg text-[10px] font-bold text-slate-400 hover:text-white hover:border-slate-600 transition"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            onClick={() => deleteAnn(ann)}
                                            className="h-7 px-3 bg-red-500/10 border border-red-500/20 rounded-lg text-[10px] font-bold text-red-400 hover:bg-red-500/20 transition"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </TD>
                            </tr>
                        ))}
                    </TableShell>

                    <PageControls links={announcements.links ?? []} />
                </div>
            )}

                    
            <AdminModal
                isOpen={cronModal}
                onClose={() => {
                    setCronModal(false);
                    setEditCron(null);
                }}
                title={`Edit Cron — ${editCron?.jobname}`}
                size="4xl"
            >
                <form onSubmit={submitCron} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <AdminInput
                            label="Job Name"
                            value={cronForm.data.jobname}
                            onChange={(e) => cronForm.setData('jobname', e.target.value)}
                            required
                        />
                        <div>
                            <AdminInput
                                label="Schedule (cron expression)"
                                value={cronForm.data.schedule}
                                onChange={(e) => cronForm.setData('schedule', e.target.value)}
                                placeholder="*/30 * * * *"
                                required
                            />
                            <p className="text-[10px] text-cyan-400 mt-1.5 font-mono">
                                Standard Cron Expression
                            </p>
                        </div>
                    </div>
                    <div>
                        <AdminTextarea
                            label="SQL Command"
                            value={cronForm.data.command}
                            onChange={(e) => cronForm.setData('command', e.target.value)}
                            rows={12}
                            required
                        />
                        <div className="mt-2 p-3 bg-amber-500/8 border border-amber-500/20 rounded-lg">
                            <p className="text-[10px] text-amber-400 font-mono">
                                DROP, TRUNCATE, and ALTER statements are blocked server-side.
                            </p>
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <AdminInput
                            label="Database (optional)"
                            value={cronForm.data.database ?? ''}
                            onChange={(e) => cronForm.setData('database', e.target.value)}
                            placeholder="postgres"
                        />
                        <AdminToggle
                            label="Active"
                            detail="Job runs on schedule when active"
                            checked={cronForm.data.active}
                            onChange={(v) => cronForm.setData('active', v)}
                        />
                    </div>
                    <FormActions
                        onCancel={() => {
                            setCronModal(false);
                            setEditCron(null);
                        }}
                        submitLabel="Save Changes"
                        processing={cronForm.processing}
                    />
                </form>
            </AdminModal>

          
            <AdminModal
                isOpen={annModal}
                onClose={() => {
                    setAnnModal(false);
                    setEditAnn(null);
                }}
                title={editAnn ? `Edit — ${editAnn.title}` : 'New Announcement'}
                size="lg"
            >
                <form onSubmit={submitAnn} className="space-y-4">
                    <AdminInput
                        label="Title"
                        value={annForm.data.title}
                        onChange={(e) => annForm.setData('title', e.target.value)}
                        required
                    />

                    <AdminTextarea
                        label="Message"
                        value={annForm.data.message}
                        onChange={(e) => annForm.setData('message', e.target.value)}
                        rows={4}
                        required
                    />

                    {/* Type — inline two-pill toggle. Form state already
                        had `type` but no UI control wrote to it; default
                        was always 'info'. Wiring it up here. */}
                    <div>
                        <label className="block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500 mb-2">Type</label>
                        <div className="flex gap-2">
                            {(['info', 'warning'] as const).map((t) => {
                                const selected = annForm.data.type === t;
                                const accent = t === 'info'
                                    ? 'border-cyan-500/40 bg-cyan-500/15 text-cyan-300'
                                    : 'border-amber-500/40 bg-amber-500/15 text-amber-300';
                                return (
                                    <button
                                        type="button"
                                        key={t}
                                        onClick={() => annForm.setData('type', t)}
                                        className={[
                                            'flex-1 rounded-lg border px-3 py-2 text-[11px] font-black uppercase tracking-widest transition',
                                            selected
                                                ? accent
                                                : 'border-slate-700/60 bg-slate-900/40 text-slate-500 hover:text-slate-300',
                                        ].join(' ')}
                                    >
                                        {t === 'info' ? 'Update' : 'Hotfix'}
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    <AdminToggle
                        label="Published"
                        detail="Visible to all players when publish time has passed (UTC)"
                        checked={annForm.data.is_active}
                        onChange={(v) => annForm.setData('is_active', v)}
                    />

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <DateTimeField
                            label="Publish At (UTC)"
                            value={annForm.data.published_at}
                            onChange={(v) => annForm.setData('published_at', v)}
                            presets={[
                                { label: 'Now', offset: 0 },
                            ]}
                            hint="Leave blank to publish immediately on save."
                        />
                        <DateTimeField
                            label="Expires At (UTC)"
                            value={annForm.data.expires_at}
                            onChange={(v) => annForm.setData('expires_at', v)}
                            presets={[
                                { label: '+1h',  offset: 60 * 60 * 1000 },
                                { label: '+24h', offset: 24 * 60 * 60 * 1000 },
                                { label: '+7d',  offset: 7 * 24 * 60 * 60 * 1000 },
                            ]}
                            hint="Leave blank to use 24-hour default."
                        />
                    </div>

                    {/* Email broadcast — only shown for create flow. Editing
                        an existing announcement should never silently spam
                        users a second time, so the checkbox is hidden in
                        edit mode (server also requires is_active=true). */}
                    {!editAnn && (
                        <div className="p-3 rounded-lg border border-emerald-500/20 bg-emerald-500/[0.04]">
                            <label className="flex items-start gap-3 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={annForm.data.also_email}
                                    onChange={(e) => annForm.setData('also_email', e.target.checked)}
                                    className="mt-1 w-4 h-4 rounded border-slate-700 bg-slate-950 text-emerald-500 focus:ring-emerald-500/40 focus:ring-offset-0"
                                />
                                <div className="flex-1">
                                    <p className="text-xs font-bold text-emerald-300">
                                        Also email all opted-in users
                                    </p>
                                    <p className="text-[10px] text-slate-500 mt-0.5 leading-relaxed">
                                        Sends the title + message as an email via Gmail SMTP.
                                        Skips users who've unsubscribed or are banned. Takes ~30 seconds.
                                    </p>
                                </div>
                            </label>
                        </div>
                    )}

                    <FormActions
                        onCancel={() => {
                            setAnnModal(false);
                            setEditAnn(null);
                        }}
                        submitLabel={editAnn ? 'Update' : 'Publish'}
                        processing={annForm.processing}
                    />
                </form>
            </AdminModal>
        </>
    );
}

// Date+time input with quick presets. Native datetime-local picker for the
// calendar UX, plus chips that compute offsets from now and write the
// value as UTC-formatted YYYY-MM-DDTHH:MM (which is what datetime-local
// emits and what the server validates as nullable|date).
function DateTimeField({
    label,
    value,
    onChange,
    presets,
    hint,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
    presets: { label: string; offset: number }[];
    hint?: string;
}) {
    const fmt = (d: Date) => {
        const pad = (n: number) => n.toString().padStart(2, '0');
        return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}T${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`;
    };

    const applyOffset = (ms: number) => onChange(fmt(new Date(Date.now() + ms)));

    return (
        <div>
            <label className="block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500 mb-2">{label}</label>
            <input
                type="datetime-local"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="w-full rounded-lg border border-slate-700/60 bg-slate-950/60 px-3 py-2 text-sm font-mono text-white focus:border-cyan-400/50 focus:outline-none"
            />
            <div className="mt-2 flex flex-wrap gap-1.5">
                {presets.map((p) => (
                    <button
                        type="button"
                        key={p.label}
                        onClick={() => applyOffset(p.offset)}
                        className="rounded border border-slate-700/60 bg-slate-900/40 px-2 py-1 text-[10px] font-black uppercase tracking-widest text-slate-400 transition hover:border-cyan-500/40 hover:text-cyan-300"
                    >
                        {p.label}
                    </button>
                ))}
                {value && (
                    <button
                        type="button"
                        onClick={() => onChange('')}
                        className="rounded border border-slate-700/60 bg-slate-900/40 px-2 py-1 text-[10px] font-black uppercase tracking-widest text-slate-500 transition hover:border-red-500/30 hover:text-red-400"
                    >
                        Clear
                    </button>
                )}
            </div>
            {hint && <p className="mt-1.5 text-[10px] text-slate-600">{hint}</p>}
        </div>
    );
}

