import { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { motion } from 'framer-motion';
import Picker from '@/Components/picker';
import OrgChart, { CorpLogo } from '@/Components/orgchart';
import GameLayout from '@/Layouts/GameLayout';
import { formatCash } from '@/Layouts/GameLayoutComponents';
import {
    CaretLeft,
    CaretRight,
    CheckCircle,
    Star,
    User,
    XCircle,
} from '@phosphor-icons/react';

const INPUT_CLS = 'w-full rounded-xl border border-slate-700/60 bg-slate-950 px-3 py-2.5 text-sm text-white placeholder:text-slate-600 outline-none transition focus:border-cyan-500/60 focus:ring-1 focus:ring-cyan-500/20';

const TABS = [
    { id: 'overview', label: 'Overview' },
    { id: 'personnel', label: 'Personnel' },
    { id: 'finance', label: 'Finance' },
    { id: 'properties', label: 'Properties' },
    { id: 'danger', label: 'Company Actions' },
    { id: 'voting', label: 'Voting' },
    { id: 'settings', label: 'Company Settings' },
];

// ── Partial-reload prop lists for actions ──────────────────────────────
// Each POST redirects back to this page; Inertia then re-requests only the
// listed props (nested `corporation.<key>` paths are merged into the current
// corporation object). 'auth' (cash, timers) and 'flash' (result toast) are
// shared props the layout needs after every action. 'boardroom' costs no
// queries and keeps the empty state correct if the corporation is gone.
const SHARED_PROPS = ['boardroom', 'auth', 'flash'];
// Header/overview/personnel/finance data (members, treasury, leadership).
const CORP_SUMMARY_PROPS = [
    'corporation.id',
    'corporation.name',
    'corporation.imageUrl',
    'corporation.boardNotes',
    'corporation.city',
    'corporation.isHoldingCompany',
    'corporation.parentTrustId',
    'corporation.parentTrust',
    'corporation.holdingContext',
    'corporation.cash_reserves',
    'corporation.slush_fund',
    'corporation.total_profits',
    'corporation.ceo',
    'corporation.isCeo',
    'corporation.isCfo',
    'corporation.isFounder',
    'corporation.maxMembers',
    'corporation.ceoCareerRank',
    'corporation.members',
    'corporation.subsidiaries',
    'corporation.pendingMoveRequest',
    'corporation.moveFee',
    'corporation.isPhaseOneOperatingCompany',
];
// Mergers, subsidiary invites, board seats/promotions and trust votes.
const CORP_DEALS_PROPS = ['corporation.merger', 'corporation.subsidiaryInvites', 'corporation.boardActions'];
// Owned properties (incl. medical / laundering data) and purchasable templates.
const CORP_PROPERTY_PROPS = [
    'corporation.properties',
    'corporation.purchasableProperties',
    'corporation.propertyTemplates',
    'corporation.propertyTaxRate',
];
const RELOAD_SUMMARY = [...CORP_SUMMARY_PROPS, ...SHARED_PROPS];
const RELOAD_MEMBERSHIP = [...CORP_SUMMARY_PROPS, ...CORP_DEALS_PROPS, ...SHARED_PROPS];
const RELOAD_PROPERTIES = [...CORP_SUMMARY_PROPS, ...CORP_PROPERTY_PROPS, ...SHARED_PROPS];
// Props the server leaves out of the first response; loaded when their tab opens.
const TAB_LAZY_PROPS = {
    properties: CORP_PROPERTY_PROPS,
    danger: ['corporation.merger'],
};

function TabSkeleton({ rows = 3 }) {
    return (
        <div className="space-y-2" aria-busy="true">
            {Array.from({ length: rows }).map((_, index) => (
                <div key={index} className="h-10 animate-pulse rounded-xl border border-slate-700/40 bg-slate-950/70" />
            ))}
        </div>
    );
}

const POSITION_BADGES = {
    BOARD_DIRECTOR: 'border-slate-300/40 bg-slate-300/10 text-slate-100',
    GROUP_PRESIDENT: 'border-orange-400/40 bg-orange-400/10 text-orange-300',
    CHAIRMAN: 'border-yellow-400/35 bg-yellow-400/10 text-yellow-200',
    Chairman: 'border-yellow-400/35 bg-yellow-400/10 text-yellow-200',
    BOARD: 'border-yellow-400/35 bg-yellow-400/10 text-yellow-200',
    CEO: 'border-amber-400/45 bg-amber-400/10 text-amber-300',
    CFO: 'border-emerald-400/35 bg-emerald-400/10 text-emerald-300',
    CTO: 'border-sky-400/35 bg-sky-400/10 text-sky-300',
    VP: 'border-cyan-400/30 bg-cyan-400/10 text-cyan-300',
    MEMBER: 'border-slate-600/50 bg-slate-800/45 text-slate-300',
};

// ── Local-only org chart previews (Phase 1 / 2 / 3) ───────────────────────
// Mirrors the real progression: a merger turns two same-city operating
// companies into subsidiaries of a new holding company, each CEO takes a board
// seat (Group President, rank 5) and hands their company to a subordinate.
// Board capacity is min(subsidiaries, 4) + 1; companies hold at most 7 members
// (HQ tier 3). Phase 3 adds the Director of the Board (rank 7, trust vote)
// once the board is 3+ Chairmen over 2+ subsidiaries. Fabricated people carry
// `isPreview` so the chart does not link them to profiles.
const PREVIEW_RANK_NAMES = {
    CEO: 'Managing Director',
    CFO: 'Department Head',
    CTO: 'Department Head',
    VP: 'Department Head',
    MEMBER: 'Staff',
    GROUP_PRESIDENT: 'Group President',
    CHAIRMAN: 'Chairman',
    BOARD_DIRECTOR: 'Director of the Board',
};

function previewPerson(id, name, position, reportsToId = null, extra = {}) {
    return {
        id,
        name,
        position,
        reportsToId,
        rank: PREVIEW_RANK_NAMES[position] ?? 'Staff',
        isCeo: position === 'CEO',
        isPreview: true,
        ...extra,
    };
}

// rows: [key, name, position, reportsToKey?, extra?]
function previewCompany({ id, name, city, rows }) {
    const ids = Object.fromEntries(rows.map(([key], index) => [key, id + index + 1]));

    return {
        id,
        name,
        city,
        imageUrl: null,
        members: rows.map(([key, personName, position, parentKey = null, extra = {}]) => (
            previewPerson(ids[key], personName, position, parentKey ? ids[parentKey] : null, extra)
        )),
    };
}

// The viewer's real company after its CEO moves up to the board: the CFO (or
// best-ranked member) becomes CEO; everyone keeps their real reporting line.
function handOffLiveCompany(corporation, members) {
    const formerCeo = members.find((member) => member.isCeo || member.position === 'CEO') ?? null;
    const others = members.filter((member) => member !== formerCeo);
    if (!formerCeo || others.length === 0) return null;

    const successor = others.find((member) => member.position === 'CFO')
        ?? others.find((member) => member.position === 'CTO')
        ?? [...others].sort((left, right) => (right.careerRank ?? 0) - (left.careerRank ?? 0))[0];

    return {
        formerCeo,
        company: {
            id: corporation.id ?? 9100,
            name: corporation.name,
            city: corporation.city,
            imageUrl: corporation.imageUrl,
            members: others.map((member) => (member.id === successor.id
                ? { ...member, position: 'CEO', isCeo: true, reportsToId: null, rank: PREVIEW_RANK_NAMES.CEO }
                : {
                    ...member,
                    reportsToId: Number(member.reportsToId) === Number(formerCeo.id) ? null : member.reportsToId,
                })),
        },
    };
}

function buildOrgPreview(corporation, members, phase = 'phase2') {
    const baseName = corporation.name ?? 'Corporation';
    const city = corporation.city ?? 'Home City';
    const holdingName = `${baseName.split(/\s+/)[0]} Holdings`;
    const live = handOffLiveCompany(corporation, members);
    const formerCeo = live?.formerCeo ?? previewPerson(9001, corporation.ceo?.name ?? 'Founder', 'CEO');
    const yourCompany = live?.company ?? previewCompany({
        id: 9100,
        name: baseName,
        city,
        rows: [
            ['ceo', 'Brock', 'CEO'],
            ['cto', 'Nox', 'CTO', 'ceo'],
            ['vp', 'Volt', 'VP', 'cto'],
            ['m1', 'Sable', 'MEMBER', 'vp', { rank: 'Senior Staff' }],
        ],
    });
    // Merger partner: HQ tier 2 (5 seats).
    const partner = previewCompany({
        id: 9200,
        name: 'Sable Dynamics',
        city,
        rows: [
            ['ceo', 'Mora', 'CEO'],
            ['cfo', 'Cipher', 'CFO', 'ceo'],
            ['vp', 'Rift', 'VP', 'cfo'],
            ['m1', 'Juno', 'MEMBER', 'vp', { rank: 'Senior Staff' }],
            ['m2', 'Vale', 'MEMBER', 'ceo'],
        ],
    });
    // Invited later: HQ tier 1 (3 seats).
    const invited = previewCompany({
        id: 9300,
        name: 'Orbit Logistics',
        city,
        rows: [
            ['ceo', 'Mercy', 'CEO'],
            ['cto', 'Wire', 'CTO', 'ceo'],
            ['m1', 'Echo', 'MEMBER', 'cto'],
        ],
    });
    const asBoard = (person, position) => ({
        ...person,
        position,
        isCeo: false,
        reportsToId: null,
        rank: PREVIEW_RANK_NAMES[position],
    });
    const you = formerCeo;
    const lynx = previewPerson(9401, 'Lynx', 'GROUP_PRESIDENT', null, { isFounder: true });
    const korvan = previewPerson(9402, 'Korvan', 'GROUP_PRESIDENT', null, { isFounder: true });

    if (phase === 'phase3') {
        const companies = [yourCompany, partner, invited];

        return {
            trust: { name: `${holdingName} Trust` },
            holdingCompany: {
                name: holdingName,
                city,
                imageUrl: corporation.imageUrl,
                director: asBoard(you, 'BOARD_DIRECTOR'),
                boardMembers: [asBoard(lynx, 'CHAIRMAN'), asBoard(korvan, 'CHAIRMAN')],
                boardCapacity: Math.min(companies.length, 4) + 1,
            },
            operatingCompanies: companies,
        };
    }

    const companies = [yourCompany, partner];

    return {
        trust: null,
        holdingCompany: {
            name: holdingName,
            city,
            imageUrl: corporation.imageUrl,
            boardMembers: [asBoard(you, 'GROUP_PRESIDENT'), lynx],
            boardCapacity: Math.min(companies.length, 4) + 1,
        },
        operatingCompanies: companies,
    };
}

function PositionBadge({ position }) {
    return (
        <span className={`rounded-full border px-2.5 py-1 text-[9px] font-black uppercase tracking-[0.18em] ${POSITION_BADGES[position] ?? POSITION_BADGES.MEMBER}`}>
            {position}
        </span>
    );
}

function Panel({ title, subtitle, children, accent = 'text-cyan-400', className = '' }) {
    return (
        <section className={`rounded-2xl border border-slate-700/35 bg-slate-900/65 p-3 shadow-[0_12px_30px_rgba(2,6,23,0.2)] ${className}`}>
            {(title || subtitle) && (
                <div className="mb-2.5">
                    {title && <p className={`text-[9px] font-black uppercase tracking-[0.28em] ${accent}`}>{title}</p>}
                    {subtitle && <p className="mt-1 text-[11px] text-slate-400">{subtitle}</p>}
                </div>
            )}
            {children}
        </section>
    );
}

function ActionButton({ children, onClick, disabled, variant = 'primary', className = 'w-full' }) {
    const variants = {
        primary: 'bg-white text-slate-950 hover:bg-cyan-400 disabled:bg-slate-800 disabled:text-slate-600',
        success: 'bg-emerald-600 text-white hover:bg-emerald-500 disabled:bg-slate-800 disabled:text-slate-600',
        warning: 'bg-amber-500 text-slate-950 hover:bg-amber-400 disabled:bg-slate-800 disabled:text-slate-600',
        danger: 'border border-red-900/40 bg-red-950/30 text-red-300 hover:border-transparent hover:bg-red-600 hover:text-white disabled:border-slate-800 disabled:bg-slate-900 disabled:text-slate-600',
        secondary: 'border border-slate-700/60 bg-slate-800 text-slate-200 hover:bg-slate-700 disabled:bg-slate-900 disabled:text-slate-600',
    };

    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            className={`${className} flex h-10 items-center justify-center gap-2 rounded-xl text-[10px] font-black uppercase tracking-[0.22em] transition ${variants[variant]}`}
        >
            {children}
        </button>
    );
}

function ConfirmDanger({ label, confirmText, routeName }) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        setProcessing(true);
        // Full reload on purpose: dissolving/leaving removes the corporation from the page.
        router.post(route(routeName), {}, {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setConfirming(false);
            },
        });
    };

    if (!confirming) {
        return <ActionButton onClick={() => setConfirming(true)} variant="danger">{label}</ActionButton>;
    }

    return (
        <div className="space-y-3 rounded-xl border border-red-500/20 bg-red-500/5 p-4">
            <p className="text-xs leading-relaxed text-red-200/80">{confirmText}</p>
            <div className="flex flex-col gap-2 sm:flex-row">
                <ActionButton onClick={submit} disabled={processing} variant="danger" className="flex-1">
                    {processing ? 'Working...' : 'Confirm'}
                </ActionButton>
                <ActionButton onClick={() => setConfirming(false)} disabled={processing} variant="secondary" className="flex-1">
                    Cancel
                </ActionButton>
            </div>
        </div>
    );
}

function InviteForm() {
    const [target, setTarget] = useState('');
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        if (!target.trim()) return;
        setProcessing(true);
        router.post(route('career.corporation.invite'), { target: target.trim() }, {
            // Only sends a journal invite to the target; nothing else on this page changes.
            only: ['corporation.members', 'corporation.maxMembers', ...SHARED_PROPS],
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setTarget('');
            },
        });
    };

    return (
        <div className="space-y-3">
            <input
                type="text"
                value={target}
                onChange={(e) => setTarget(e.target.value)}
                placeholder="Display name"
                className={INPUT_CLS}
            />
            <ActionButton onClick={submit} disabled={processing || !target.trim()}>
                {processing ? 'Sending...' : 'Send Invite'}
            </ActionButton>
        </div>
    );
}

function KickForm({ members, label = 'Remove Member' }) {
    const [memberId, setMemberId] = useState('');
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        if (!memberId) return;
        setProcessing(true);
        router.post(route('career.corporation.kick'), { member_id: parseInt(memberId, 10) }, {
            only: RELOAD_MEMBERSHIP,
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setMemberId('');
            },
        });
    };

    return (
        <div className="space-y-3">
            <Picker
                options={members.map((member) => ({ value: member.id, label: member.name }))}
                value={memberId}
                onChange={setMemberId}
                placeholder="Select member..."
                placement="bottom"
            />
            {members.length === 0 && <p className="text-xs text-slate-500"></p>}
            <ActionButton onClick={submit} disabled={processing || !memberId} variant="danger">
                {processing ? 'Removing...' : label}
            </ActionButton>
        </div>
    );
}

// Shortcuts shown in the org chart's member dialog. Same endpoints and reload
// lists as the Personnel tab's Demote Rank / Remove Member forms; role changes
// stay in the Personnel tab (they need the reports-to picker).
function MemberQuickActions({ member, onOpenPersonnel, onDone }) {
    const [confirmKick, setConfirmKick] = useState(false);
    const [processing, setProcessing] = useState(null);
    const canDemoteRank = (member.careerRank ?? 0) > 1;

    const submit = (routeName, key) => {
        setProcessing(key);
        router.post(route(routeName), { member_id: parseInt(member.id, 10) }, {
            only: RELOAD_MEMBERSHIP,
            preserveScroll: true,
            onSuccess: () => onDone(),
            onFinish: () => {
                setProcessing(null);
                setConfirmKick(false);
            },
        });
    };

    return (
        <div className="space-y-2">
            <p className="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-400">Manage</p>
            <div className="grid gap-2 sm:grid-cols-2">
                <ActionButton
                    variant="warning"
                    disabled={!canDemoteRank || processing !== null}
                    onClick={() => submit('career.corporation.demote-rank', 'demote')}
                >
                    {processing === 'demote' ? 'Demoting...' : 'Demote Rank'}
                </ActionButton>
                <ActionButton
                    variant="danger"
                    disabled={processing !== null}
                    onClick={() => (confirmKick ? submit('career.corporation.kick', 'kick') : setConfirmKick(true))}
                >
                    {processing === 'kick' ? 'Removing...' : confirmKick ? 'Confirm Remove' : 'Remove Member'}
                </ActionButton>
            </div>
            <ActionButton variant="secondary" onClick={onOpenPersonnel} disabled={processing !== null}>
                Change Role in Personnel
            </ActionButton>
        </div>
    );
}

function AssignForm({ members, managers = [], mode = 'ceo' }) {
    const [memberId, setMemberId] = useState('');
    const [position, setPosition] = useState('');
    const [reportsToId, setReportsToId] = useState('');
    const [processing, setProcessing] = useState(false);
    const isManagerMode = mode === 'manager';
    const positionOptions = isManagerMode
        ? [{ value: 'member', label: 'Member' }]
        : [
            { value: 'cfo', label: 'CFO' },
            { value: 'cto', label: 'CTO' },
            { value: 'vp', label: 'VP' },
            { value: 'member', label: 'Member' },
        ];
    const reportsToRequired = !isManagerMode && ['vp', 'member'].includes(position);
    const reportsToOptions = !isManagerMode
        ? managers
            .filter((member) => member.id !== parseInt(memberId, 10))
            .filter((member) => {
                if (position === 'vp') {
                    return ['CFO', 'CTO'].includes(member.position);
                }

                if (position === 'member') {
                    return ['CFO', 'CTO', 'VP'].includes(member.position);
                }

                return false;
            })
            .map((member) => ({ value: member.id, label: member.name }))
        : [];

    const submit = () => {
        if (!memberId || !position) return;
        if (reportsToRequired && !reportsToId) return;
        setProcessing(true);
        router.post(route('career.corporation.assign-position'), {
            member_id: parseInt(memberId, 10),
            position,
            reports_to_id: reportsToRequired ? parseInt(reportsToId, 10) : null,
        }, {
            only: RELOAD_MEMBERSHIP,
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setMemberId('');
                setPosition('');
                setReportsToId('');
            },
        });
    };

    return (
        <div className="space-y-3">
            <Picker
                options={members.map((member) => ({ value: member.id, label: member.name }))}
                value={memberId}
                onChange={setMemberId}
                placeholder="Select member..."
            />
            <Picker
                options={positionOptions}
                value={position}
                onChange={(value) => {
                    setPosition(value);
                    setReportsToId('');
                }}
                placeholder="Select position..."
            />
            {!isManagerMode && (
                <Picker
                    options={reportsToOptions}
                    value={reportsToId}
                    onChange={setReportsToId}
                    placeholder={['cfo', 'cto'].includes(position) ? 'Reports to CEO' : 'Reports to...'}
                />
            )}
            {members.length === 0 && <p className="text-xs text-slate-500"></p>}
            <ActionButton onClick={submit} disabled={processing || !memberId || !position || (reportsToRequired && !reportsToId)}>
                {processing ? 'Assigning...' : isManagerMode ? 'Demote Position' : 'Assign Position'}
            </ActionButton>
        </div>
    );
}

function DemoteRankForm({ members }) {
    const [memberId, setMemberId] = useState('');
    const [processing, setProcessing] = useState(false);
    const eligible = members.filter((member) => (member.careerRank ?? 0) > 1);

    const submit = () => {
        if (!memberId) return;
        setProcessing(true);
        router.post(route('career.corporation.demote-rank'), { member_id: parseInt(memberId, 10) }, {
            only: RELOAD_MEMBERSHIP,
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setMemberId('');
            },
        });
    };

    return (
        <div className="space-y-3">
            <Picker
                options={eligible.map((member) => ({ value: member.id, label: `${member.name} (${member.rank})` }))}
                value={memberId}
                onChange={setMemberId}
                placeholder="Select member..."
            />
            {eligible.length === 0 && <p className="text-xs text-slate-500"></p>}
            <ActionButton onClick={submit} disabled={processing || !memberId} variant="warning">
                {processing ? 'Demoting...' : 'Demote Rank'}
            </ActionButton>
        </div>
    );
}

function DepositForm() {
    const [amount, setAmount] = useState('');
    const [fund, setFund] = useState('slush');
    const [processing, setProcessing] = useState(false);
    const routeName = fund === 'reserves' ? 'career.corporation.reserves.deposit' : 'career.corporation.deposit';

    const submit = () => {
        const parsed = parseInt(amount, 10);
        if (!parsed || parsed <= 0) return;
        setProcessing(true);
        router.post(route(routeName), { amount: parsed }, {
            only: RELOAD_SUMMARY,
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setAmount('');
            },
        });
    };

    return (
        <div className="space-y-3">
            <Picker
                options={[
                    { value: 'slush', label: 'Slush fund' },
                    { value: 'reserves', label: 'Cash reserves' },
                ]}
                value={fund}
                onChange={setFund}
                placeholder="Deposit to..."
            />
            <input
                type="number"
                min="1"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                placeholder="Amount"
                className={INPUT_CLS}
            />
            <ActionButton onClick={submit} disabled={processing || !amount || parseInt(amount, 10) <= 0} variant={fund === 'reserves' ? 'secondary' : 'success'}>
                {processing ? 'Depositing...' : 'Deposit Funds'}
            </ActionButton>
        </div>
    );
}

function DistributeForm({ members, myId, isHoldingCompany = false }) {
    const [memberId, setMemberId] = useState('');
    const [amount, setAmount] = useState('');
    const [fund, setFund] = useState('slush');
    const [processing, setProcessing] = useState(false);
    const routeName = fund === 'reserves' ? 'career.corporation.reserves.distribute' : 'career.corporation.distribute';

    const submit = () => {
        const parsed = parseInt(amount, 10);
        if (!memberId || !parsed || parsed <= 0) return;
        setProcessing(true);
        router.post(route(routeName), {
            member_id: parseInt(memberId, 10),
            amount: parsed,
        }, {
            only: RELOAD_SUMMARY,
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setMemberId('');
                setAmount('');
            },
        });
    };

    // Holdings can only distribute between board members (Group President /
    // Chairman); operating companies route to their existing C-suite + VP set.
    const recipients = isHoldingCompany
        ? members.filter((member) => member.id !== myId && ['GROUP_PRESIDENT', 'CHAIRMAN', 'BOARD_DIRECTOR'].includes(member.position))
        : members.filter((member) => member.id !== myId && (member.isCeo || ['CFO', 'CTO', 'VP'].includes(member.position)));

    return (
        <div className="space-y-3">
            <Picker
                options={[
                    { value: 'slush', label: 'Slush fund' },
                    { value: 'reserves', label: 'Cash reserves' },
                ]}
                value={fund}
                onChange={setFund}
                placeholder="Distribute from..."
            />
            <Picker
                options={recipients.map((member) => ({ value: member.id, label: member.name }))}
                value={memberId}
                onChange={setMemberId}
                placeholder="Select recipient..."
            />
            <input
                type="number"
                min="1"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                placeholder="Amount"
                className={INPUT_CLS}
            />
            <ActionButton onClick={submit} disabled={processing || !memberId || !amount || parseInt(amount, 10) <= 0} variant={fund === 'reserves' ? 'warning' : 'success'}>
                {processing ? 'Sending...' : 'Distribute Funds'}
            </ActionButton>
        </div>
    );
}

function TransferLeadershipForm({ members }) {
    const [memberId, setMemberId] = useState('');
    const [processing, setProcessing] = useState(false);

    const eligible = members.filter((member) => (member.careerRank ?? 0) >= 3);

    const submit = () => {
        if (!memberId) return;
        setProcessing(true);
        router.post(route('career.corporation.transfer-ceo'), { member_id: parseInt(memberId, 10) }, {
            only: RELOAD_MEMBERSHIP,
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setMemberId('');
            },
        });
    };

    return (
        <div className="space-y-3">
            <Picker
                options={eligible.map((member) => ({ value: member.id, label: `${member.name} (${member.rank})` }))}
                value={memberId}
                onChange={setMemberId}
                placeholder="Select successor..."
                placement="bottom"
            />
            {eligible.length === 0 && <p className="text-xs text-slate-500"></p>}
            <ActionButton onClick={submit} disabled={processing || !memberId} variant="warning">
                {processing ? 'Transferring...' : 'Transfer Leadership'}
            </ActionButton>
        </div>
    );
}

function reportsToLabel(member, corporation) {
    if (['BOARD_DIRECTOR', 'GROUP_PRESIDENT', 'CHAIRMAN', 'BOARD'].includes(member.position)) {
        return 'Board member';
    }

    if (member.isCeo) {
        if (corporation?.parentTrust?.name) {
            return `Reports to ${corporation.parentTrust.name}`;
        }

        return 'Reports to no one';
    }

    if (['CFO', 'CTO'].includes(member.position)) {
        return 'Reports to CEO';
    }

    return member.reportsToName ? `Reports to ${member.reportsToName}` : 'Reports to CEO';
}

function BoardNotes({ notes, isCeo }) {
    const [draft, setDraft] = useState(notes ?? '');
    const [processing, setProcessing] = useState(false);

    const save = () => {
        setProcessing(true);
        router.post(route('career.corporation.board-notes'), {
            board_notes: draft.trim(),
        }, {
            only: ['corporation.boardNotes', ...SHARED_PROPS],
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    if (!isCeo) {
        return (
            <p className="text-sm leading-6 text-slate-400">
                {notes?.trim() || 'No board notes yet.'}
            </p>
        );
    }

    return (
        <div className="space-y-3">
            <textarea
                value={draft}
                onChange={(e) => setDraft(e.target.value.slice(0, 500))}
                rows={4}
                className={`${INPUT_CLS} min-h-28 resize-none leading-6`}
                placeholder="Leave a note for the board."
            />
            <div className="flex items-center justify-between gap-3">
                <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-600">{draft.length}/500</span>
                <ActionButton onClick={save} disabled={processing} variant="secondary" className="w-auto px-5">
                    {processing ? 'Saving...' : 'Save Notes'}
                </ActionButton>
            </div>
        </div>
    );
}

function BannerForm({ imageUrl, errors = {} }) {
    const [draft, setDraft] = useState(imageUrl ?? '');
    const [processing, setProcessing] = useState(false);

    const save = () => {
        setProcessing(true);
        router.post(route('career.corporation.banner'), {
            image_url: draft.trim() || null,
        }, {
            // The banner also appears on the org chart (holding/subsidiary cards).
            only: ['corporation.imageUrl', 'corporation.parentTrust', 'corporation.holdingContext', 'corporation.subsidiaries', ...SHARED_PROPS],
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div className="space-y-3">
            <input
                type="url"
                value={draft}
                onChange={(e) => setDraft(e.target.value.slice(0, 255))}
                className={`${INPUT_CLS} ${errors?.image_url ? 'border-red-500/80' : ''}`}
                placeholder="https://example.com/banner.jpg"
            />
            {errors?.image_url && <p className="text-xs text-red-400">{errors.image_url}</p>}
            <ActionButton onClick={save} disabled={processing} variant="secondary">
                {processing ? 'Saving...' : 'Save Banner'}
            </ActionButton>
        </div>
    );
}

function MergerProposalForm({ merger, errors = {} }) {
    const [targetCorporationId, setTargetCorporationId] = useState('');
    const [successorId, setSuccessorId] = useState('');
    const [holdingName, setHoldingName] = useState('');
    const [holdingImageUrl, setHoldingImageUrl] = useState('');
    const [processing, setProcessing] = useState(false);
    const targets = merger?.targets ?? [];
    const successors = merger?.successors ?? [];
    const canSubmit = targetCorporationId && successorId && holdingName.trim().length >= 5;

    const submit = () => {
        if (!canSubmit) return;
        setProcessing(true);
        router.post(route('career.corporation.merger.propose'), {
            target_corporation_id: parseInt(targetCorporationId, 10),
            requester_successor_id: parseInt(successorId, 10),
            holding_name: holdingName.trim(),
            holding_image_url: holdingImageUrl.trim() || null,
        }, {
            only: RELOAD_MEMBERSHIP,
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2">
                <Picker
                    options={targets.map((target) => ({ value: target.id, label: `${target.name} - ${target.ceoName}` }))}
                    value={targetCorporationId}
                    onChange={setTargetCorporationId}
                    placeholder="Target company..."
                />
                <Picker
                    options={successors.map((successor) => ({ value: successor.id, label: successor.name }))}
                    value={successorId}
                    onChange={setSuccessorId}
                    placeholder="Your successor..."
                />
                <div className="sm:col-span-2">
                    <input
                        type="text"
                        value={holdingName}
                        onChange={(e) => setHoldingName(e.target.value.slice(0, 30))}
                        className={`${INPUT_CLS} ${errors?.holding_name ? 'border-red-500/80' : ''}`}
                        placeholder="Holding company name"
                    />
                    {errors?.holding_name && <p className="mt-2 text-xs text-red-400">{errors.holding_name}</p>}
                </div>
                <div className="sm:col-span-2">
                    <input
                        type="url"
                        value={holdingImageUrl}
                        onChange={(e) => setHoldingImageUrl(e.target.value.slice(0, 255))}
                        className={`${INPUT_CLS} ${errors?.holding_image_url ? 'border-red-500/80' : ''}`}
                        placeholder="https://example.com/holding-banner.jpg"
                    />
                    {errors?.holding_image_url && <p className="mt-2 text-xs text-red-400">{errors.holding_image_url}</p>}
                </div>
            </div>



            <div className="flex flex-col gap-3 rounded-xl border border-amber-400/15 bg-amber-400/5 p-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p className="text-[10px] font-black uppercase tracking-[0.24em] text-amber-300">MERGER FUNDS</p>
                    <p className="mt-1 text-lg font-black text-white">{formatCash(merger?.cost ?? 0)}</p>
                </div>
                <ActionButton onClick={submit} disabled={!canSubmit || processing} variant="warning" className="sm:w-auto sm:px-5">
                    {processing ? 'Sending...' : 'Propose Merger'}
                </ActionButton>
            </div>
        </div>
    );
}

function IncomingMergerCard({ request, successors }) {
    const [successorId, setSuccessorId] = useState('');
    const [processing, setProcessing] = useState(false);

    const accept = () => {
        if (!successorId) return;
        setProcessing(true);
        // Full reload on purpose: accepting turns this company into a holding company.
        router.post(route('career.corporation.merger.accept', request.id), {
            target_successor_id: parseInt(successorId, 10),
        }, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    const decline = () => {
        setProcessing(true);
        router.post(route('career.corporation.merger.cancel', request.id), {}, {
            only: RELOAD_MEMBERSHIP,
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div className="space-y-3 rounded-xl border border-cyan-400/20 bg-cyan-400/5 p-3">
            <div>
                <p className="text-[10px] font-black uppercase tracking-[0.22em] text-cyan-300">Incoming Proposal</p>
                <p className="mt-1 text-sm font-black text-white">{request.holdingName}</p>
                <p className="mt-1 text-xs text-slate-400">{request.requesterCorporationName} wants to merge.</p>
            </div>
            <Picker
                options={successors.map((successor) => ({ value: successor.id, label: successor.name }))}
                value={successorId}
                onChange={setSuccessorId}
                placeholder="Your successor..."
            />
            <div className="flex flex-col gap-2 sm:flex-row">
                <ActionButton onClick={accept} disabled={processing || !successorId} variant="success" className="flex-1">
                    {processing ? 'Working...' : 'Accept'}
                </ActionButton>
                <ActionButton onClick={decline} disabled={processing} variant="secondary" className="flex-1">
                    Decline
                </ActionButton>
            </div>
        </div>
    );
}

function OutgoingMergerCard({ request }) {
    const [processing, setProcessing] = useState(false);

    const cancel = () => {
        setProcessing(true);
        router.post(route('career.corporation.merger.cancel', request.id), {}, {
            only: RELOAD_MEMBERSHIP,
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div className="flex flex-col gap-3 rounded-xl border border-slate-700/45 bg-slate-950/55 p-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
                <p className="text-[10px] font-black uppercase tracking-[0.22em] text-slate-500">Pending Proposal</p>
                <p className="mt-1 truncate text-sm font-black text-white">{request.holdingName}</p>
                <p className="mt-1 truncate text-xs text-slate-400">{request.targetCorporationName}</p>
            </div>
            <ActionButton onClick={cancel} disabled={processing} variant="secondary" className="sm:w-auto sm:px-4">
                {processing ? 'Cancelling...' : 'Cancel'}
            </ActionButton>
        </div>
    );
}

function IncomingSubsidiaryInviteCard({ request, successors }) {
    const [successorId, setSuccessorId] = useState('');
    const [processing, setProcessing] = useState(false);

    const accept = () => {
        if (!successorId) return;
        setProcessing(true);
        // Full reload on purpose: accepting restructures the whole corporation.
        router.post(route('career.corporation.subsidiary-invites.accept', request.id), {
            successor_id: parseInt(successorId, 10),
        }, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    const decline = () => {
        setProcessing(true);
        router.post(route('career.corporation.subsidiary-invites.cancel', request.id), {}, {
            only: RELOAD_MEMBERSHIP,
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div className="space-y-3 rounded-xl border border-amber-400/20 bg-amber-400/5 p-3">
            <div>
                <p className="text-[10px] font-black uppercase tracking-[0.22em] text-amber-300">Subsidiary Invitation</p>
                <p className="mt-1 text-sm font-black text-white">{request.holdingName}</p>
                <p className="mt-1 text-xs text-slate-400">{request.requesterName} wants your company under the holding board.</p>
            </div>
            <Picker
                options={successors.map((successor) => ({ value: successor.id, label: successor.name }))}
                value={successorId}
                onChange={setSuccessorId}
                placeholder="Your successor..."
            />
            <div className="flex flex-col gap-2 sm:flex-row">
                <ActionButton onClick={accept} disabled={processing || !successorId} variant="success" className="flex-1">
                    {processing ? 'Working...' : 'Accept'}
                </ActionButton>
                <ActionButton onClick={decline} disabled={processing} variant="secondary" className="flex-1">
                    Decline
                </ActionButton>
            </div>
        </div>
    );
}

function SubsidiaryInviteForm({ invites }) {
    const [targetCorporationId, setTargetCorporationId] = useState('');
    const [processing, setProcessing] = useState(false);
    const targets = invites?.targets ?? [];

    const submit = () => {
        if (!targetCorporationId) return;
        setProcessing(true);
        router.post(route('career.corporation.subsidiary-invites.create'), {
            target_corporation_id: parseInt(targetCorporationId, 10),
        }, {
            only: RELOAD_MEMBERSHIP,
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setTargetCorporationId('');
            },
        });
    };



    return (
        <div className="space-y-3">
            <Picker
                options={targets.map((target) => ({ value: target.id, label: `${target.name} - ${target.ceoName}` }))}
                value={targetCorporationId}
                onChange={setTargetCorporationId}
                placeholder="Invite to become your subsidiary ..."
                placement="bottom"
            />

            <ActionButton onClick={submit} disabled={processing || !targetCorporationId} variant="warning">
                {processing ? 'Sending...' : 'Invite Subsidiary'}
            </ActionButton>
        </div>
    );
}

function OutgoingSubsidiaryInviteCard({ request }) {
    const [processing, setProcessing] = useState(false);

    const cancel = () => {
        setProcessing(true);
        router.post(route('career.corporation.subsidiary-invites.cancel', request.id), {}, {
            only: RELOAD_MEMBERSHIP,
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div className="flex flex-col gap-3 rounded-xl border border-slate-700/45 bg-slate-950/55 p-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
                <p className="text-[10px] font-black uppercase tracking-[0.22em] text-slate-500">Pending Subsidiary Invite</p>
                <p className="mt-1 truncate text-sm font-black text-white">{request.targetCorporationName}</p>
                <p className="mt-1 truncate text-xs text-slate-400">{request.targetCeoName}</p>
            </div>
            <ActionButton onClick={cancel} disabled={processing} variant="secondary" className="sm:w-auto sm:px-4">
                {processing ? 'Cancelling...' : 'Cancel'}
            </ActionButton>
        </div>
    );
}

function MergerActions({ merger, subsidiaryInvites, isCeo, errors = {} }) {
    const incoming = merger?.incoming ?? [];
    const outgoing = merger?.outgoing ?? [];
    const successors = merger?.successors ?? [];
    const incomingSubsidiaryInvites = subsidiaryInvites?.incoming ?? [];

    return (
        <div className="space-y-4">
            {isCeo && merger?.canPropose && (
                <MergerProposalForm merger={merger} errors={errors} />
            )}

            {incoming.map((request) => (
                <IncomingMergerCard key={request.id} request={request} successors={successors} />
            ))}

            {outgoing.map((request) => (
                <OutgoingMergerCard key={request.id} request={request} />
            ))}

            {incomingSubsidiaryInvites.map((request) => (
                <IncomingSubsidiaryInviteCard key={request.id} request={request} successors={successors} />
            ))}

            {!isCeo && incoming.length === 0 && outgoing.length === 0 && incomingSubsidiaryInvites.length === 0 && (
                <p className="text-sm text-slate-400">No company actions available.</p>
            )}

            {isCeo && !merger?.canPropose && incoming.length === 0 && outgoing.length === 0 && incomingSubsidiaryInvites.length === 0 && (
                <p className="text-sm text-slate-400">No merger action available right now.</p>
            )}
        </div>
    );
}

function BoardPromotionForm({ boardActions }) {
    const targets = (boardActions?.promotionTargets ?? []).filter((target) => target.ready);
    const [subsidiaryId, setSubsidiaryId] = useState('');
    const [successorId, setSuccessorId] = useState('');
    const [processing, setProcessing] = useState(false);
    const selected = targets.find((target) => String(target.id) === String(subsidiaryId));
    const successors = selected?.successors ?? [];
    const canSubmit = boardActions?.canPromote && subsidiaryId && successorId;

    const submit = () => {
        if (!canSubmit) return;
        setProcessing(true);
        router.post(route('career.corporation.board-promotions.create'), {
            subsidiary_id: parseInt(subsidiaryId, 10),
            successor_id: parseInt(successorId, 10),
        }, {
            only: RELOAD_MEMBERSHIP,
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };



    return (
        <div className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2">
                <Picker
                    options={targets.map((target) => ({ value: target.id, label: `${target.name} - ${target.ceoName}` }))}
                    value={subsidiaryId}
                    onChange={(value) => { setSubsidiaryId(value); setSuccessorId(''); }}
                    placeholder="Subsidiary CEO..."
                />
                <Picker
                    options={successors.map((successor) => ({ value: successor.id, label: successor.name }))}
                    value={successorId}
                    onChange={setSuccessorId}
                    placeholder="Successor..."
                />
            </div>
            <ActionButton onClick={submit} disabled={!canSubmit || processing} variant="warning">
                {processing ? 'Preparing...' : 'Prepare Board Promotion'}
            </ActionButton>
        </div>
    );
}

function KickSubsidiaryForm({ subsidiaries, activeCount = 0 }) {
    const [subsidiaryId, setSubsidiaryId] = useState('');
    const [processing, setProcessing] = useState(false);
    const canSubmit = Boolean(subsidiaryId);

    const submit = () => {
        if (!canSubmit) return;
        setProcessing(true);
        // Full reload on purpose: removing a subsidiary restructures the holding company.
        router.post(route('career.corporation.subsidiaries.kick'), {
            subsidiary_id: parseInt(subsidiaryId, 10),
        }, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };



    return (
        <div className="space-y-3">
            <Picker
                options={subsidiaries.map((subsidiary) => ({ value: subsidiary.id, label: `${subsidiary.name} - ${subsidiary.ceoName}` }))}
                value={subsidiaryId}
                onChange={setSubsidiaryId}
                placeholder="Subsidiary..."
                placement="bottom"
            />
            <ActionButton onClick={submit} disabled={!canSubmit || processing} variant="danger">
                {processing ? 'Removing...' : 'Kick Out Subsidiary'}
            </ActionButton>
        </div>
    );
}

function BoardActions({ boardActions }) {
    const pending = boardActions?.pendingPromotions ?? [];
    const subsidiaries = boardActions?.kickableSubsidiaries ?? [];
    const activeSubsidiaryCount = boardActions?.subsidiaryCount ?? subsidiaries.length;
    const subsidiaryInvites = boardActions?.subsidiaryInvites ?? {};
    const outgoingInvites = subsidiaryInvites?.outgoing ?? [];

    if (!boardActions?.isBoardMember) {
        return <p className="text-sm text-slate-400">Only holding-company board members can use company actions.</p>;
    }

    return (
        <div className="space-y-4">
            <div className="grid gap-2 sm:grid-cols-3">
                <HeroFact label="Board" value={`${boardActions.boardCount ?? 0} / ${boardActions.capacity ?? 0}`} />
                <HeroFact label="Open Seats" value={boardActions.availableSlots ?? 0} accent="text-amber-300" />
                <HeroFact label="Subsidiaries" value={activeSubsidiaryCount} />
            </div>

            {pending.length > 0 && (
                <div className="space-y-2">
                    {pending.map((promotion) => (
                        <div key={promotion.id} className="rounded-xl border border-amber-400/15 bg-amber-400/5 p-3">
                            <p className="text-[10px] font-black uppercase tracking-[0.22em] text-amber-300">Pending Board Handoff</p>
                            <p className="mt-1 text-sm font-black text-white">{promotion.ceoName}</p>
                            <p className="mt-1 text-xs text-slate-400">{promotion.subsidiaryName} successor: {promotion.successorName}</p>
                        </div>
                    ))}
                </div>
            )}

            <BoardPromotionForm boardActions={boardActions} />
            <div className="border-t border-slate-800/70 pt-4">
                <SubsidiaryInviteForm invites={subsidiaryInvites} />
            </div>
            {outgoingInvites.length > 0 && (
                <div className="space-y-2">
                    {outgoingInvites.map((request) => (
                        <OutgoingSubsidiaryInviteCard key={request.id} request={request} />
                    ))}
                </div>
            )}
            <div className="border-t border-slate-800/70 pt-4">
                <KickSubsidiaryForm subsidiaries={subsidiaries} activeCount={activeSubsidiaryCount} />
            </div>
        </div>
    );
}

const ROUND_TABLE_LAYOUTS = {
    1: [{ x: 50, y: 14 }],
    2: [{ x: 15, y: 50 }, { x: 85, y: 50 }],
    3: [{ x: 15, y: 50 }, { x: 85, y: 50 }, { x: 50, y: 14 }],
    4: [{ x: 50, y: 12 }, { x: 84, y: 50 }, { x: 50, y: 86 }, { x: 16, y: 50 }],
    5: [{ x: 50, y: 10 }, { x: 84, y: 36 }, { x: 72, y: 82 }, { x: 28, y: 82 }, { x: 16, y: 36 }],
};

function roundTableSeatStyle(index, total) {
    const layout = ROUND_TABLE_LAYOUTS[total];

    if (layout?.[index]) {
        return { left: `${layout[index].x}%`, top: `${layout[index].y}%` };
    }

    const angle = (index / Math.max(total, 1)) * Math.PI * 2 - Math.PI / 2;
    const radius = 42;

    return {
        left: `${50 + Math.cos(angle) * radius}%`,
        top: `${50 + Math.sin(angle) * radius}%`,
    };
}

function directorPositionLabel(position) {
    const labels = {
        BOARD_DIRECTOR: 'DIRECTOR',
        GROUP_PRESIDENT: 'GROUP PRESIDENT',
        BOARD: 'BOARD',
    };

    return labels[position] ?? String(position ?? 'MEMBER').replace(/_/g, ' ');
}

function BoardSeat({ member, index, total, selectedCandidateId, pendingPromotion, onSelect }) {
    const selected = Number(selectedCandidateId) === Number(member.id);
    const canClick = !pendingPromotion;

    return (
        <div
            className={`absolute w-[5.8rem] -translate-x-1/2 -translate-y-1/2 text-center sm:w-[7.4rem] ${selected ? 'z-20' : 'z-10'}`}
            style={roundTableSeatStyle(index, total)}
        >
            <motion.button
                type="button"
                onClick={() => onSelect(member.id)}
                disabled={!canClick}
                custom={index}
                initial={{ opacity: 0, scale: 0.86 }}
                animate={{ opacity: 1, scale: 1 }}
                whileHover={canClick ? { scale: 1.03 } : undefined}
                transition={{ delay: index * 0.06, duration: 0.24 }}
                className={`w-full ${canClick ? 'cursor-pointer' : 'cursor-default'}`}
            >
                <div
                    className={`relative mx-auto h-14 w-14 overflow-hidden rounded-xl border bg-slate-900 shadow-[0_12px_34px_rgba(2,6,23,0.35)] sm:h-16 sm:w-16 ${selected
                        ? 'border-cyan-300/60'
                        : member.isCandidate
                            ? 'border-amber-400/45'
                            : 'border-slate-700/50'
                        }`}
                >
                    {member.avatarUrl ? (
                        <img src={member.avatarUrl} alt={member.name} className="h-full w-full object-cover" />
                    ) : (
                        <div className="flex h-full w-full items-center justify-center text-slate-500">
                            <User size={22} weight="bold" />
                        </div>
                    )}

                    {member.hasVoted && (
                        <span className="absolute right-1 top-1 flex h-4 w-4 items-center justify-center rounded-full border border-emerald-200/70 bg-emerald-500 text-slate-950">
                            <CheckCircle size={12} weight="fill" />
                        </span>
                    )}
                </div>

                <p className="mt-2 truncate text-[11px] font-black uppercase tracking-[0.08em] text-white">{member.name}</p>
                <p className="mt-1 truncate text-[8px] font-black uppercase tracking-[0.2em] text-amber-300/85">{directorPositionLabel(member.position)}</p>
            </motion.button>
        </div>
    );
}

function VoteLedger({ boardMembers, candidates, activeVote, pendingPromotion, requiredVotes, selectedMember, processing, onSubmitVote }) {
    const ballotCount = activeVote?.ballotCount ?? boardMembers.filter((member) => member.hasVoted).length;
    const currentMember = boardMembers.find((member) => member.isMe);
    const hasMyVote = Boolean(currentMember?.hasVoted);
    const votedMembers = boardMembers.filter((member) => member.hasVoted);

    return (
        <motion.aside
            initial={{ opacity: 0, x: 14 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: 0.25 }}
            className="rounded-3xl border border-slate-700/45 bg-slate-950/70 p-4"
        >
            <div className="flex items-start justify-between gap-3 border-b border-slate-800/80 pb-3">
                <div>
                    <p className="text-[9px] font-black uppercase tracking-[0.28em] text-amber-300">Board ledger</p>
                    <p className="mt-1 text-sm font-black text-white">{pendingPromotion ? 'Mandate secured' : 'Director vote'}</p>
                </div>
                <div className="text-right">
                    <p className="text-[9px] font-black uppercase tracking-[0.22em] text-slate-500">Votes</p>
                    <p className="mt-1 text-sm font-black text-emerald-300">{ballotCount}/{requiredVotes}</p>
                </div>
            </div>

            <div className="mt-3 border-b border-slate-800/80 pb-3">
                <p className="text-[9px] font-black uppercase tracking-[0.25em] text-slate-500">Selected seat</p>
                {selectedMember ? (
                    <div className="mt-2 flex items-center gap-2.5">
                        <div className="h-9 w-9 overflow-hidden rounded-xl border border-cyan-200/40 bg-slate-900">
                            {selectedMember.avatarUrl ? (
                                <img src={selectedMember.avatarUrl} alt={selectedMember.name} className="h-full w-full object-cover" />
                            ) : (
                                <div className="flex h-full w-full items-center justify-center text-slate-500">
                                    <User size={16} weight="bold" />
                                </div>
                            )}
                        </div>
                        <div className="min-w-0">
                            <p className="truncate text-xs font-black uppercase text-white">{selectedMember.name}</p>
                            <p className="mt-0.5 text-[8px] font-black uppercase tracking-[0.2em] text-amber-300/80">{directorPositionLabel(selectedMember.position)}</p>
                        </div>
                    </div>
                ) : (
                    <p className="mt-2 text-xs font-bold text-slate-500">Select a board member at the table.</p>
                )}

                {selectedMember && !pendingPromotion && (
                    <ActionButton
                        onClick={onSubmitVote}
                        disabled={processing || !selectedMember || hasMyVote}
                        variant={selectedMember && !hasMyVote ? 'success' : 'secondary'}
                        className="mt-3 h-9 w-full"
                    >
                        {hasMyVote ? 'Ballot Recorded' : 'Vote'}
                    </ActionButton>
                )}

                {pendingPromotion && (
                    <p className="mt-3 text-xs font-bold text-emerald-300">{pendingPromotion.winnerName} has the mandate.</p>
                )}
            </div>

            <div className="mt-3 space-y-2">
                <p className="text-[9px] font-black uppercase tracking-[0.25em] text-slate-500">Tally</p>
                {(candidates.length > 0 ? candidates : boardMembers).map((candidate) => {
                    const votes = Number(candidate.votes ?? 0);
                    const pct = requiredVotes > 0 ? Math.min(100, (votes / requiredVotes) * 100) : 0;

                    return (
                        <div key={candidate.id} className="border-b border-slate-800/70 pb-2 last:border-0 last:pb-0">
                            <div className="flex items-center justify-between gap-2">
                                <p className="truncate text-[11px] font-black uppercase text-white">{candidate.name}</p>
                                <span className="text-[10px] font-black text-slate-300">{votes}</span>
                            </div>
                            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-800">
                                <div className="h-full rounded-full bg-emerald-300" style={{ width: `${pct}%` }} />
                            </div>
                        </div>
                    );
                })}
            </div>

            {votedMembers.length > 0 && (
                <div className="mt-4 space-y-1.5">
                    <p className="text-[9px] font-black uppercase tracking-[0.25em] text-slate-500">Voted</p>
                    {votedMembers.map((member) => (
                        <div key={member.id} className="flex items-center justify-between gap-2 border-b border-slate-800/60 py-1.5 last:border-0">
                            <span className="truncate text-[11px] font-bold text-slate-200">{member.name}</span>
                            <CheckCircle size={13} weight="fill" className="shrink-0 text-emerald-300" />
                        </div>
                    ))}
                </div>
            )}
        </motion.aside>
    );
}

function TrustVotePanel({ trustVote }) {
    const [processing, setProcessing] = useState(false);
    const [selectedCandidateId, setSelectedCandidateId] = useState(null);
    const candidates = trustVote?.candidates ?? [];
    const boardMembers = trustVote?.boardMembers?.length ? trustVote.boardMembers : candidates;
    const activeVote = trustVote?.active ?? null;
    const pendingPromotion = trustVote?.promotionPending ?? null;
    const requiredVotes = Number(trustVote?.requiredVotes ?? 0);
    const selectedMember = boardMembers.find((member) => Number(member.id) === Number(selectedCandidateId)) ?? null;

    const submitVote = () => {
        if (processing || !selectedMember) return;
        setProcessing(true);
        router.post(route('career.corporation.trust-votes.vote'), {
            candidate_id: selectedMember.id,
        }, {
            only: RELOAD_MEMBERSHIP,
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_18rem]">
            <div className="relative min-h-[24rem] overflow-hidden rounded-3xl bg-transparent p-3 sm:min-h-[27rem]">
                <div className="absolute left-[18%] right-[18%] top-1/2 h-[48%] -translate-y-1/2 rounded-full border border-amber-200/20 bg-transparent shadow-[inset_0_0_45px_rgba(251,191,36,0.05),0_24px_70px_rgba(2,6,23,0.22)]" />
                <div className="absolute left-[23%] right-[23%] top-1/2 h-px bg-amber-100/10" />
                <div className="absolute left-1/2 top-[31%] h-[38%] w-px -translate-x-1/2 bg-amber-100/10" />

                {boardMembers.length > 0 ? (
                    boardMembers.map((member, index) => (
                        <BoardSeat
                            key={member.id}
                            member={member}
                            index={index}
                            total={boardMembers.length}
                            selectedCandidateId={selectedCandidateId}
                            pendingPromotion={pendingPromotion}
                            onSelect={setSelectedCandidateId}
                        />
                    ))
                ) : (
                    <div className="absolute inset-0 flex items-center justify-center">
                        <p className="text-xs font-bold text-slate-500">No board members listed.</p>
                    </div>
                )}
            </div>

            <VoteLedger
                boardMembers={boardMembers}
                candidates={candidates}
                activeVote={activeVote}
                pendingPromotion={pendingPromotion}
                requiredVotes={requiredVotes}
                selectedMember={selectedMember}
                processing={processing}
                onSubmitVote={submitVote}
            />
        </div>
    );
}

function propertyTypeLabel(type) {
    return String(type ?? 'property').replace(/_/g, ' ').toUpperCase();
}

function propertyStatusClass(property) {
    if (property?.operational === false) {
        return property?.condition === 'PENDING' ? 'text-amber-300' : 'text-red-300';
    }
    return 'text-emerald-300';
}

function PropertyData({ data, emptyText = null }) {
    const entries = Object.entries(data ?? {}).filter(([, value]) => value !== null && value !== undefined && value !== '');

    if (entries.length === 0) {
        if (!emptyText) {
            return null;
        }

        return (
            <div className="mt-3 rounded-xl border border-slate-800/70 bg-slate-950/55 px-3 py-2.5">
                <p className="text-[9px] font-black uppercase tracking-[0.24em] text-slate-500">More Information</p>
                <p className="mt-1 text-xs font-bold text-slate-500">{emptyText}</p>
            </div>
        );
    }

    return (
        <div className="mt-3 rounded-xl border border-slate-800/70 bg-slate-950/55 p-3">
            <p className="text-[9px] font-black uppercase tracking-[0.24em] text-slate-500">More Information</p>
            <div className="mt-2 grid gap-2 sm:grid-cols-2">
                {entries.map(([key, value]) => (
                    <div key={key} className="rounded-lg border border-slate-800/70 bg-slate-950/60 px-3 py-2">
                        <p className="text-[9px] font-black uppercase tracking-[0.18em] text-slate-500">{key.replace(/_/g, ' ')}</p>
                        <p className="mt-1 truncate text-xs font-bold text-slate-200">
                            {typeof value === 'object' ? JSON.stringify(value) : String(value)}
                        </p>
                    </div>
                ))}
            </div>
        </div>
    );
}

const MEDICAL_PRODUCTS = {
    'cx-717': 'CX-717',
    'tak-925': 'TAK-925',
};
const MEDICAL_PAYOUT_OPTIONS = [1000, 2000, 3000, 4000, 5000];
const MIRROR_BANKER_PERCENTAGE_OPTIONS = [1, 2, 3, 4, 5];

function medicalStockRows(property) {
    const stock = property?.data?.stock ?? {};

    return Object.entries(MEDICAL_PRODUCTS).map(([slug, fallbackLabel]) => {
        const item = stock[slug] ?? {};

        return {
            slug,
            label: item.label ?? fallbackLabel,
            imageUrl: item.imageUrl ?? null,
            stocked: Number(item.stocked_packs ?? 0),
            reserved: Number(item.reserved_packs ?? 0),
            total: Number(item.stocked_packs ?? 0) + Number(item.reserved_packs ?? 0),
            blackMarketMin: Number(item.black_market_min ?? 0),
            blackMarketMax: Number(item.black_market_max ?? 0),
        };
    });
}

function BlackMarketIntel({ property }) {
    const rows = medicalStockRows(property).filter((row) => row.blackMarketMin > 0);

    if (rows.length === 0) {
        return null;
    }

    return (
        <div className="mt-3 rounded-xl border border-emerald-500/15 bg-slate-950/55 p-3">
            <p className="text-[9px] font-black uppercase tracking-[0.24em] text-emerald-300">Tor Marketplace</p>
            <div className="mt-2 space-y-1.5">
                {rows.map((row) => (
                    <div
                        key={row.slug}
                        className="flex items-center justify-between gap-3 rounded-lg border border-slate-800/70 bg-slate-950/65 px-3 py-2"
                    >
                        <p className="text-xs font-black text-white">{row.label}</p>
                        <p className="text-[10px] font-black uppercase tracking-[0.14em] text-emerald-400/80 tabular-nums">
                            {formatCash(row.blackMarketMin)} – {formatCash(row.blackMarketMax)}
                        </p>
                    </div>
                ))}
            </div>
        </div>
    );
}

function DrugInformation({ property }) {
    const rows = medicalStockRows(property);
    const packUnits = Number(property?.data?.pack_units ?? 3);

    return (
        <div className="mt-3 rounded-xl border border-emerald-500/15 bg-slate-950/55 p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-[9px] font-black uppercase tracking-[0.24em] text-emerald-300">Drug information</p>
                <p className="text-[9px] font-black uppercase tracking-[0.18em] text-slate-500">1 pack = {packUnits} capsules</p>
            </div>
            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                {rows.map((row) => (
                    <div
                        key={row.slug}
                        className="grid grid-cols-[4.5rem_minmax(0,1fr)] items-center gap-3 rounded-lg border border-slate-800/70 bg-slate-950/65 px-3 py-3"
                    >
                        <div className="flex h-18 w-18 items-center justify-center" style={{ height: '4.5rem', width: '4.5rem' }}>
                            {row.imageUrl ? (
                                <img src={row.imageUrl} alt={row.label} className="h-full w-full object-contain drop-shadow-[0_4px_8px_rgba(0,0,0,0.4)]" />
                            ) : (
                                <span className="text-xs font-black text-slate-500">RX</span>
                            )}
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="flex min-w-0 flex-wrap items-center justify-between gap-x-2 gap-y-1">
                                <p className="min-w-0 text-xs font-black text-white">{row.label}</p>
                                <div className={`flex shrink-0 items-center gap-1.5 text-[9px] font-black uppercase tracking-[0.12em] ${row.total > 0 ? 'text-emerald-300' : 'text-slate-600'}`}>
                                    {row.total > 0 ? <CheckCircle size={15} weight="fill" /> : <XCircle size={15} weight="fill" />}
                                    <span>{row.total > 0 ? 'Stocked' : 'No stock'}</span>
                                </div>
                            </div>
                            <p className="mt-1 text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">
                                x {row.total} {row.total === 1 ? 'pack' : 'packs'}
                            </p>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

function LaunderingInformation({ property }) {
    const offshoreBalance = Number(property?.data?.offshore_balance ?? 0);

    return (
        <div className="mt-3 rounded-xl border border-cyan-500/15 bg-slate-950/55 p-3">
            <div>
                <p className="text-[10px] font-black uppercase tracking-[0.18em] text-cyan-300">Offshore Trust</p>
                <p className="mt-1 text-sm font-black text-white tabular-nums">{formatTreasuryCash(offshoreBalance)}</p>

            </div>
        </div>
    );
}

function MedicalPayoutRateForm({ property }) {
    const [processing, setProcessing] = useState(false);
    const currentRate = String(property?.data?.medical_payout_per_pack ?? 1000);

    const submit = (value) => {
        const parsedRate = parseInt(value, 10);
        if (processing || parsedRate < 500 || parsedRate > 5000) return;

        setProcessing(true);
        router.post(route('career.corporation.profits.medical.payout-rate'), { rate: parsedRate }, {
            only: RELOAD_PROPERTIES,
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div className="rounded-xl border border-slate-700/40 bg-slate-950/70 p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-[10px] font-black uppercase tracking-[0.25em] text-slate-400">PPP</p>
                <p className="text-[9px] font-black uppercase tracking-[0.18em] text-slate-500">Payout per pack</p>
            </div>
            <select
                value={currentRate}
                onChange={(event) => submit(event.target.value)}
                disabled={processing}
                className={`${INPUT_CLS} mt-2`}
            >
                {MEDICAL_PAYOUT_OPTIONS.map((option) => (
                    <option key={option} value={option}>{formatCash(option)}</option>
                ))}
            </select>
            <p className="mt-2 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">
                {processing ? 'Saving...' : `Range ${formatCash(1000)} - ${formatCash(5000)}`}
            </p>
        </div>
    );
}

function MedicalNpcSellButtons({ property }) {
    const [processing, setProcessing] = useState(null);
    const rows = medicalStockRows(property);

    const submit = (row) => {
        if (processing) return;

        setProcessing(row.slug);
        router.post(route('career.corporation.profits.medical.sell-npc'), { product: row.slug }, {
            only: RELOAD_PROPERTIES,
            preserveScroll: true,
            onFinish: () => setProcessing(null),
        });
    };

    return (
        <div className="rounded-xl border border-slate-700/40 bg-slate-950/70 p-3">
            <div className="space-y-2">
                {rows.map((row) => (
                    <ActionButton
                        key={row.slug}
                        onClick={() => submit(row)}
                        disabled={Boolean(processing) || row.total <= 0}
                        variant="warning"
                    >
                        {processing === row.slug ? 'Selling...' : `Sell ${row.label} Directly`}
                    </ActionButton>
                ))}
            </div>
        </div>
    );
}

function MirrorBankerPercentageForm({ property }) {
    const [processing, setProcessing] = useState(false);
    const currentPercentage = String(property?.data?.banker_percentage ?? 2);

    const submit = (value) => {
        const percentage = parseInt(value, 10);
        if (processing || Number.isNaN(percentage)) return;

        setProcessing(true);
        router.post(route('career.corporation.profits.laundering.banker-percentage'), { percentage }, {
            only: RELOAD_PROPERTIES,
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div className="rounded-xl border border-slate-700/40 bg-slate-950/70 p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-[10px] font-black uppercase tracking-[0.25em] text-slate-400">Banker Percentage</p>
                <p className="text-[9px] font-black uppercase tracking-[0.18em] text-slate-500">Fee</p>
            </div>
            <select
                value={currentPercentage}
                onChange={(event) => submit(event.target.value)}
                disabled={processing}
                className={`${INPUT_CLS} mt-2`}
            >
                {MIRROR_BANKER_PERCENTAGE_OPTIONS.map((option) => (
                    <option key={option} value={option}>{option}%</option>
                ))}
            </select>
            <p className="mt-2 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">
                {processing ? 'Saving...' : 'Placement fee for the banker'}
            </p>
        </div>
    );
}

function OffshoreTrustTransferForm() {
    const [amount, setAmount] = useState('');
    const [processing, setProcessing] = useState(false);
    const transferAmount = parseInt(amount, 10);
    const canTransfer = !Number.isNaN(transferAmount) && transferAmount > 0;

    const submit = () => {
        if (processing || !canTransfer) return;

        setProcessing(true);
        router.post(route('career.corporation.profits.laundering.offshore-transfer'), {
            amount: transferAmount,
        }, {
            only: RELOAD_PROPERTIES,
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setAmount('');
            },
        });
    };

    return (
        <div className="rounded-xl border border-cyan-500/15 bg-slate-950/70 p-3">
            <p className="text-[10px] font-black uppercase tracking-[0.25em] text-cyan-300">Fund PanamaCo</p>
            <div className="mt-2 flex flex-col gap-2 sm:flex-row">
                <input
                    type="number"
                    min="1"
                    step="1000"
                    value={amount}
                    onChange={(event) => setAmount(event.target.value)}
                    placeholder="Amount from slush"
                    className={INPUT_CLS}
                    disabled={processing}
                />
                <ActionButton
                    onClick={submit}
                    disabled={processing || !canTransfer}
                    variant="secondary"
                    className="w-full shrink-0 px-4 sm:w-auto"
                >
                    {processing ? 'Moving...' : 'Move'}
                </ActionButton>
            </div>
        </div>
    );
}

function MirrorTransactionForm({ property }) {
    const [bankerName, setBankerName] = useState('');
    const [amount, setAmount] = useState('');
    const [processing, setProcessing] = useState(false);
    const [canceling, setCanceling] = useState(false);
    const [executing, setExecuting] = useState(false);
    const request = property?.data?.mirror_request ?? null;
    const accepted = request?.status === 'accepted';
    const mirrorAmount = parseInt(amount, 10);
    const canSendOffer = bankerName.trim() && !Number.isNaN(mirrorAmount) && mirrorAmount >= 1000;

    const submit = () => {
        if (processing || request || !canSendOffer) return;

        setProcessing(true);
        router.post(route('career.corporation.profits.laundering.mirror'), {
            banker_name: bankerName.trim(),
            amount: mirrorAmount,
        }, {
            only: RELOAD_PROPERTIES,
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setBankerName('');
                setAmount('');
            },
        });
    };

    const cancel = () => {
        if (!request?.request_key || canceling) return;

        setCanceling(true);
        router.post(route('career.corporation.profits.laundering.mirror.cancel'), {
            request_key: request.request_key,
        }, {
            only: RELOAD_PROPERTIES,
            preserveScroll: true,
            onFinish: () => setCanceling(false),
        });
    };

    const execute = () => {
        if (!request?.request_key || executing || !accepted) return;

        setExecuting(true);
        router.post(route('career.corporation.profits.laundering.mirror.execute'), {
            request_key: request.request_key,
        }, {
            only: RELOAD_PROPERTIES,
            preserveScroll: true,
            onFinish: () => setExecuting(false),
        });
    };

    if (request) {
        return (
            <div className="rounded-xl border border-cyan-500/20 bg-cyan-500/[0.04] p-3">
                <p className="text-[9px] font-black uppercase tracking-[0.24em] text-cyan-300">
                    {accepted ? `Accepted By ${request.banker_name}` : `Offer Sent To ${request.banker_name}`}
                </p>
                <div className="mt-2 flex items-center justify-between gap-3 rounded-lg border border-slate-800/70 bg-slate-950/65 px-3 py-2">
                    <p className="text-sm font-black text-white tabular-nums">{formatTreasuryCash(request.amount ?? 0)}</p>
                    <p className="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">
                        {accepted ? 'Ready to move' : ``}
                    </p>
                </div>
                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                    {accepted && (
                        <ActionButton onClick={execute} disabled={executing} variant="warning">
                            {executing ? 'Moving...' : 'Move Funds'}
                        </ActionButton>
                    )}
                    <ActionButton onClick={cancel} disabled={canceling || executing} variant="secondary" className={!accepted ? 'sm:col-span-2' : ''}>
                        {canceling ? 'Cancelling...' : 'Cancel'}
                    </ActionButton>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-3 rounded-xl border border-slate-700/40 bg-slate-950/70 p-3">
            <div>
                <label className="mb-1.5 block text-[10px] font-black uppercase tracking-[0.22em] text-slate-500">Banker Name</label>
                <input
                    type="text"
                    value={bankerName}
                    onChange={(event) => setBankerName(event.target.value)}
                    placeholder="Display name"
                    className={INPUT_CLS}
                    disabled={processing}
                />
            </div>
            <div>
                <label className="mb-1.5 block text-[10px] font-black uppercase tracking-[0.22em] text-slate-500">Amount from PanamaCo</label>
                <input
                    type="number"
                    min="1000"
                    step="1000"
                    value={amount}
                    onChange={(event) => setAmount(event.target.value)}
                    placeholder="Amount to mirror"
                    className={INPUT_CLS}
                    disabled={processing}
                />
            </div>
            <ActionButton
                onClick={submit}
                disabled={processing || !canSendOffer}
                variant="warning"
            >
                {processing ? 'Sending...' : 'Send Offer'}
            </ActionButton>
        </div>
    );
}

function propertyImageLabel(item) {
    if (item?.kind === 'owned') {
        if (item?.property?.operational === false) {
            if (item?.property.condition === 'DEFAULTED') {
                return 'Defaulted';
            }
            return 'Awaiting Construction';
        }

        if (item?.property?.type === 'hq') {
            return 'Current HQ';
        }

        return 'Owned Property';
    }

    return 'Available Property';
}

function PropertyImagePanel({ item, corporation, onPrevious, onNext, count }) {
    const property = item?.property;
    const imageLabel = propertyImageLabel(item);
    const hasProperty = Boolean(property);
    const isMedical = property?.type === 'medical';
    const showBlackMarket = isMedical && Boolean(corporation?.isCeo);

    return (
        <Panel>
            <div className="relative h-56 overflow-hidden rounded-xl border border-amber-500/20">
                {property?.imageUrl && (
                    <div
                        className="absolute inset-0 scale-110 bg-cover bg-center opacity-60 blur-md"
                        style={{ backgroundImage: `url(${property.imageUrl})` }}
                    />
                )}
                <img
                    src={property?.imageUrl || corporation.imageUrl || 'https://images.thedirector.app/Careers/boardroom.jpg'}
                    alt={property?.name || 'Corporate property'}
                    className="absolute inset-0 h-full w-full scale-[1.02] object-cover object-center"
                />
                <div className="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-950/10 to-transparent" />
                {count > 1 && (
                    <>
                        <button
                            type="button"
                            onClick={onPrevious}
                            className="absolute left-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full border border-white/15 bg-slate-950/70 text-white transition hover:bg-slate-800"
                            aria-label="Previous property"
                        >
                            <CaretLeft size={18} weight="bold" />
                        </button>
                        <button
                            type="button"
                            onClick={onNext}
                            className="absolute right-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full border border-white/15 bg-slate-950/70 text-white transition hover:bg-slate-800"
                            aria-label="Next property"
                        >
                            <CaretRight size={18} weight="bold" />
                        </button>
                    </>
                )}
                {imageLabel && (
                    <div className="absolute left-4 top-4 rounded-lg border border-amber-300/25 bg-slate-950/65 px-2.5 py-1 text-[9px] font-black uppercase tracking-[0.22em] text-amber-200">
                        {imageLabel}
                    </div>
                )}
            </div>
            {hasProperty && (
                property.type === 'medical'
                    ? <DrugInformation property={property} />
                    : property.type === 'laundering'
                        ? <LaunderingInformation property={property} />
                        : <PropertyData data={property.data} emptyText="No Information" />
            )}
            {showBlackMarket && <BlackMarketIntel property={property} />}
        </Panel>
    );
}

function PropertyDetailsPanel({ item, corporation, index, count, onBuy, processing }) {
    const property = item?.property;
    const isTemplate = item?.kind !== 'owned';
    const dailyUpkeep = Number(property?.dailyUpkeep ?? 0);

    if (!property) {
        return (
            <Panel title="Property" accent="text-amber-400">
                <div className="rounded-xl border border-slate-700/40 bg-slate-950/70 p-4 text-sm text-slate-400">
                    No corporation properties yet.
                </div>
            </Panel>
        );
    }

    return (
        <Panel title="Property" accent={isTemplate ? 'text-cyan-400' : 'text-amber-400'}>
            <div className="space-y-3">
                <div className="rounded-xl border border-slate-700/40 bg-slate-950/70 p-3">
                    <div className="flex items-center justify-between gap-3">
                        <p className="truncate text-sm font-bold text-white">{property.name}</p>
                        {count > 1 && <p className="text-[10px] font-black uppercase tracking-[0.18em] text-slate-600">{index + 1} / {count}</p>}
                    </div>
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <div className="rounded-xl border border-slate-700/40 bg-slate-950/70 p-3">
                        <p className="text-[10px] font-black uppercase tracking-[0.25em] text-slate-400">Type</p>
                        <p className="mt-1 text-sm font-bold text-white">{propertyTypeLabel(property.type)}</p>
                    </div>
                    <div className="rounded-xl border border-slate-700/40 bg-slate-950/70 p-3">
                        <p className="text-[10px] font-black uppercase tracking-[0.25em] text-slate-400">Tier</p>
                        <p className="mt-1 text-sm font-bold text-white">{property.tier}</p>
                    </div>
                </div>
                {isTemplate ? (
                    <div className="rounded-xl border border-slate-700/40 bg-slate-950/70 px-3 py-2">
                        <div className="flex items-center justify-between py-1.5 text-sm">
                            <span className="font-bold text-slate-400">Price</span>
                            <span className="font-black text-white">{formatCash(property.price ?? 0)}</span>
                        </div>
                        <div className="flex items-center justify-between border-t border-slate-800/70 py-1.5 text-sm">
                            <span className="font-bold text-slate-400">Daily Upkeep</span>
                            <span className="font-black text-cyan-200">{formatCash(dailyUpkeep)}</span>
                        </div>
                        {property.tax > 0 && (
                            <div className="flex items-center justify-between border-t border-slate-800/70 py-1.5 text-sm">
                                <span className="font-bold text-slate-400">Tax</span>
                                <span className="font-black text-amber-300">{formatCash(property.tax)}</span>
                            </div>
                        )}
                        <div className="flex items-center justify-between border-t border-slate-800/70 py-1.5 text-sm">
                            <span className="font-black text-slate-300">Total</span>
                            <span className="text-base font-black text-white">{formatCash(property.total ?? property.price ?? 0)}</span>
                        </div>
                        <div className="flex justify-end border-t border-slate-800/70 pt-3">
                            <ActionButton onClick={onBuy} disabled={processing} variant="warning" className="w-32">
                                {processing ? 'Buying...' : 'Buy'}
                            </ActionButton>
                        </div>
                    </div>
                ) : (
                    <>
                        <div className="rounded-xl border border-slate-700/40 bg-slate-950/70 p-3">
                            <p className="text-[10px] font-black uppercase tracking-[0.25em] text-slate-400">Status</p>
                            <p className={`mt-1 text-sm font-bold ${propertyStatusClass(property)}`}>{property.condition === 'PENDING' ? 'Awaiting Construction' : (property.condition ?? 'Active')}</p>
                            <div className="mt-3 border-t border-slate-800/70 pt-3">
                                <p className="text-[10px] font-black uppercase tracking-[0.25em] text-slate-400">Daily Upkeep</p>
                                <p className="mt-1 text-sm font-black text-cyan-200">{formatCash(dailyUpkeep)}</p>
                            </div>
                        </div>
                        {property.type === 'medical' && property.operational && corporation.isCeo && (
                            <>
                                <MedicalPayoutRateForm property={property} />
                                <MedicalNpcSellButtons property={property} />
                            </>
                        )}
                        {property.type === 'laundering' && property.operational && corporation.isCfo && (
                            <>
                                <MirrorBankerPercentageForm property={property} />
                                <OffshoreTrustTransferForm />
                                <MirrorTransactionForm property={property} />
                            </>
                        )}
                    </>
                )}
            </div>
        </Panel>
    );
}

function PropertiesPanel({ corporation }) {
    const [selectedIndex, setSelectedIndex] = useState(0);
    const [processing, setProcessing] = useState(false);
    const propertiesLoaded = corporation.properties !== undefined;
    const ownedProperties = corporation.properties ?? [];
    const purchasableProperties = corporation.propertyTemplates ?? corporation.purchasableProperties ?? [];
    const items = [
        ...ownedProperties.map((property) => ({ kind: 'owned', property })),
        ...purchasableProperties.map((property) => ({
            kind: property.canPurchaseNow ? 'purchase' : 'preview',
            property,
        })),
    ];
    const activeIndex = items.length > 0 ? Math.min(selectedIndex, items.length - 1) : 0;
    const activeItem = items[activeIndex] ?? null;
    const goPrevious = () => {
        if (items.length < 2) return;
        setSelectedIndex((current) => (current - 1 + items.length) % items.length);
    };
    const goNext = () => {
        if (items.length < 2) return;
        setSelectedIndex((current) => (current + 1) % items.length);
    };
    const buySelected = () => {
        if (!activeItem?.property || activeItem.kind === 'owned' || processing) return;

        setProcessing(true);
        router.post(route('career.corporation.properties.purchase'), { property_id: activeItem.property.id }, {
            only: RELOAD_PROPERTIES,
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    // Property data is loaded on demand when the Properties tab opens.
    if (!propertiesLoaded) {
        return (
            <Panel title="Properties" accent="text-amber-400">
                <TabSkeleton rows={4} />
            </Panel>
        );
    }

    return (
        <div className="grid gap-3 nav:grid-cols-[1.2fr_0.8fr]">
            <PropertyImagePanel
                item={activeItem}
                corporation={corporation}
                onPrevious={goPrevious}
                onNext={goNext}
                count={items.length}
            />
            <PropertyDetailsPanel
                item={activeItem}
                corporation={corporation}
                index={activeIndex}
                count={items.length}
                onBuy={buySelected}
                processing={processing}
            />
        </div>
    );
}

function CompanySettings({ corporation, errors = {} }) {
    return (
        <div className="grid gap-3 nav:grid-cols-2">
            <Panel title="Board Notes" subtitle="" accent="text-emerald-400">
                <BoardNotes notes={corporation.boardNotes} isCeo />
            </Panel>
            <Panel title="Banner" subtitle="16:9 landscape image URL." accent="text-cyan-400">
                <BannerForm imageUrl={corporation.imageUrl} errors={errors} />
            </Panel>
        </div>
    );
}

function CriticalActions({ corporation, isCeo }) {
    const isHoldingBoardMember = Boolean(corporation?.isHoldingCompany && corporation?.boardActions?.isBoardMember);



    return (
        <div className="space-y-4">
            {isCeo && <MoveCorporation corporation={corporation} />}
            {isCeo ? (
                <ConfirmDanger
                    label="Dissolve Corporation"
                    confirmText="This permanently dissolves the corporation."
                    routeName="career.corporation.dissolve"
                />
            ) : (
                <ConfirmDanger
                    label="Leave Corporation"
                    confirmText="This removes you from the corporation."
                    routeName="career.corporation.quit"
                />
            )}
        </div>
    );
}

function MoveCorporation({ corporation }) {
    const [processing, setProcessing] = useState(false);

    const pending = corporation?.pendingMoveRequest ?? null;
    const fee = corporation?.moveFee ?? 100000;

    const submitRequest = () => {
        setProcessing(true);
        router.post(route('career.corporation.move.request'), {}, {
            only: RELOAD_SUMMARY,
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    const submitCancel = () => {
        setProcessing(true);
        router.post(route('career.corporation.move.cancel'), {}, {
            only: RELOAD_SUMMARY,
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    if (pending) {
        return (
            <div className="space-y-3 rounded-xl border border-amber-500/20 bg-amber-500/5 p-4">
                <div className="space-y-1">
                    <p className="text-[10px] font-black uppercase tracking-[0.25em] text-amber-300">
                        Relocation Pending
                    </p>
                    <p className="text-xs leading-relaxed text-amber-100/80">
                        Awaiting customs approval in <strong>{pending.to_city_name}</strong>.
                    </p>
                </div>
                <ActionButton onClick={submitCancel} disabled={processing} variant="secondary">
                    {processing ? 'Working...' : 'Cancel Move Request'}
                </ActionButton>
            </div>
        );
    }

    return (
        <ActionButton onClick={submitRequest} disabled={processing} variant="warning">
            {processing ? 'Filing...' : 'Move Corporation'}
        </ActionButton>
    );
}

function CompanyActions({ corporation, merger, subsidiaryInvites, isCeo, errors = {} }) {
    if (corporation?.isHoldingCompany) {
        return <BoardActions boardActions={corporation.boardActions ?? {}} />;
    }

    // `merger` is loaded on demand when this tab opens.
    if (merger === undefined) {
        return <TabSkeleton rows={2} />;
    }

    return <MergerActions merger={merger} subsidiaryInvites={subsidiaryInvites} isCeo={isCeo} errors={errors} />;
}

function BoardroomEmptyState({ boardroom, errors = {} }) {
    const [corpName, setCorpName] = useState('');
    const [imageUrl, setImageUrl] = useState('');
    const [processing, setProcessing] = useState(false);

    const showFoundingForm = boardroom?.showFoundingForm;

    const found = () => {
        if (!showFoundingForm || !corpName.trim()) return;
        setProcessing(true);
        router.post(route('corporation.found'), {
            name: corpName.trim(),
            image_url: imageUrl.trim() || null,
        }, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    const canSubmit = showFoundingForm && corpName.trim().length >= 5;

    if (!showFoundingForm) {
        return (
            <>
                <Head title="Boardroom" />
                <div className="mx-auto flex min-h-[28rem] w-full max-w-3xl items-center px-4 py-6">
                    <section className="relative w-full overflow-hidden rounded-2xl border border-slate-700/40 bg-slate-900/75 shadow-[0_16px_45px_rgba(2,6,23,0.28)]">
                        <div className="absolute inset-y-0 right-0 hidden w-1/2 opacity-40 nav:block">
                            <img
                                src="https://images.thedirector.app/Careers/startup.jpg"
                                alt="Boardroom"
                                className="h-full w-full object-cover"
                            />
                            <div className="absolute inset-0 bg-gradient-to-r from-slate-900 via-slate-900/60 to-transparent" />
                        </div>
                        <div className="relative p-8 sm:p-10">
                            <p className="mb-2 text-[10px] font-black uppercase tracking-[0.32em] text-cyan-400">Boardroom</p>
                            <h1 className="text-3xl font-black tracking-tight text-white">No Corporation</h1>
                            <p className="mt-3 max-w-xl text-sm text-slate-300">Join an existing corporation or return when you're Rank 3 100% and  ready to start one.</p>
                        </div>
                    </section>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Boardroom" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-4 px-4 py-6">
                <motion.section
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    className="relative overflow-hidden rounded-2xl border border-white/8"
                >
                    <div className="absolute inset-0">
                        <img
                            src="https://images.thedirector.app/Careers/startup.jpg"
                            alt="Boardroom"
                            className="h-full w-full object-cover"
                        />
                        <div className="absolute inset-0 bg-gradient-to-r from-slate-950 via-slate-950/70 to-slate-950/30" />
                        <div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/30 to-transparent" />
                    </div>
                    <div className="relative px-6 py-8 sm:px-8 sm:py-10">
                        <p className="mb-2 text-[10px] font-black uppercase tracking-[0.32em] text-cyan-400">Boardroom</p>
                        <h1 className="text-3xl font-black tracking-tight text-white sm:text-4xl">Start a Corporation</h1>
                        <p className="mt-3 text-sm text-slate-300">Name the company and choose its banner.</p>
                    </div>
                </motion.section>

                <Panel
                    title="Found Corporation"
                    subtitle="Recommended: 16:9 landscape. 1280×720px"
                    accent="text-emerald-400"
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label className="mb-2 block text-[10px] font-black uppercase tracking-[0.25em] text-slate-400">Corporation Name</label>
                            <input
                                type="text"
                                value={corpName}
                                onChange={(e) => setCorpName(e.target.value)}
                                maxLength={30}
                                className={`${INPUT_CLS} ${errors?.name ? 'border-red-500/80' : ''}`}
                                placeholder="Enter a name"
                            />
                            {errors?.name && <p className="mt-2 text-xs text-red-400">{errors.name}</p>}
                        </div>
                        <div className="sm:col-span-2">
                            <label className="mb-2 block text-[10px] font-black uppercase tracking-[0.25em] text-slate-400">Banner Image</label>
                            <input
                                type="url"
                                value={imageUrl}
                                onChange={(e) => setImageUrl(e.target.value)}
                                maxLength={255}
                                className={`${INPUT_CLS} ${errors?.image_url ? 'border-red-500/80' : ''}`}
                                placeholder="https://example.com/banner.jpg"
                            />
                            {errors?.image_url && <p className="mt-2 text-xs text-red-400">{errors.image_url}</p>}
                        </div>
                        <div className="sm:col-span-2 flex flex-col gap-3 rounded-xl border border-emerald-500/15 bg-emerald-500/5 p-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p className="text-[10px] font-black uppercase tracking-[0.25em] text-emerald-300">Incorporation Cost</p>
                                <p className="mt-1 text-xl font-black text-white">{formatCash(boardroom.foundingCost)}</p>
                                {boardroom?.blocker && (
                                    <p className="mt-1 max-w-md text-xs text-amber-200/80">{boardroom.blocker}</p>
                                )}
                            </div>
                            <ActionButton onClick={found} disabled={!canSubmit || processing} variant="success" className="sm:w-auto sm:px-6">
                                {processing ? 'Incorporating...' : 'Incorporate'}
                            </ActionButton>
                        </div>
                    </div>
                </Panel>
            </div>
        </>
    );
}

function HeroFact({ label, value, accent = 'text-white' }) {
    return (
        <div className="rounded-xl border border-white/10 bg-black/20 p-3 backdrop-blur-sm">
            <p className="text-[9px] font-black uppercase tracking-[0.22em] text-slate-400">{label}</p>
            <p className={`mt-1 truncate text-sm font-bold ${accent}`}>{value}</p>
        </div>
    );
}

function TreasuryRow({ label, value, hint, valueClass = 'text-white' }) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-3 first:pt-0 last:pb-0">
            <div className="min-w-0">
                <p className="text-xs font-black uppercase tracking-[0.22em] text-slate-300">{label}</p>
                {hint && (
                    <p className="mt-1 text-[10px] uppercase tracking-[0.16em] text-slate-500">{hint}</p>
                )}
            </div>
            <p className={`shrink-0 text-xl font-black tabular-nums ${valueClass}`}>{value}</p>
        </div>
    );
}

function formatTreasuryCash(value) {
    const amount = Number(value ?? 0);

    return `$${amount.toLocaleString()}`;
}

function MemberRow({ member, corporation }) {
    return (
        <div className="flex items-center gap-2.5 rounded-xl border border-slate-800/60 bg-slate-950/50 px-3 py-2">
            <div className={`flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-lg border ${member.isCeo ? 'border-amber-500/40' : 'border-slate-700/50'} bg-slate-900`}>
                {member.avatarUrl ? (
                    <img src={member.avatarUrl} alt={member.name} className="h-full w-full object-cover" />
                ) : (
                    <User size={16} className="text-slate-500" />
                )}
            </div>
            <div className="min-w-0 flex-1">
                <Link href={route('profile.show', { displayName: member.name })} className="flex items-center gap-2 truncate text-sm font-bold text-white hover:text-cyan-300">
                    <span className="truncate">{member.name}</span>
                    {member.isFounder && <Star size={12} weight="fill" className="shrink-0 text-amber-400" />}
                </Link>
                <p className="mt-1 text-[10px] uppercase tracking-[0.18em] text-slate-500">{member.rank}</p>
                <p className="mt-0.5 text-[10px] uppercase tracking-[0.16em] text-slate-600">
                    {reportsToLabel(member, corporation)}
                </p>
            </div>
            <PositionBadge position={member.position} />
        </div>
    );
}

export default function Corporate({ corporation, boardroom, myId, errors = {}, isLocalEnvironment = false }) {
    const [activeTab, setActiveTab] = useState('overview');
    const [orgPreview, setOrgPreview] = useState('phase1');

    // Some tabs' data is not part of the first response; fetch it (partial
    // reload) when such a tab is open and the data is missing.
    // (Holding companies never show the merger panel, so skip fetching it.)
    const lazyTabProps = (TAB_LAZY_PROPS[activeTab] ?? [])
        .filter((path) => !(path === 'corporation.merger' && corporation?.isHoldingCompany));
    const missingTabProps = corporation
        ? lazyTabProps.filter((path) => corporation[path.split('.')[1]] === undefined)
        : [];
    const missingTabPropsKey = missingTabProps.join(',');
    useEffect(() => {
        if (!missingTabPropsKey) return;
        router.reload({ only: missingTabPropsKey.split(',') });
    }, [missingTabPropsKey]);

    if (!corporation) {
        return <BoardroomEmptyState boardroom={boardroom} errors={errors} />;
    }

    const members = corporation.members ?? [];
    const merger = corporation.merger;
    const subsidiaryInvites = corporation.subsidiaryInvites ?? {};
    const isHoldingCompany = Boolean(corporation.isHoldingCompany);
    const boardActions = corporation.boardActions ?? {};
    const parentTrust = corporation.parentTrust ?? null;
    const isSubsidiary = Boolean(parentTrust && !isHoldingCompany);
    const sortedMembers = [...members].sort((left, right) => {
        const order = ['BOARD_DIRECTOR', 'GROUP_PRESIDENT', 'CHAIRMAN', 'BOARD', 'CEO', 'CFO', 'CTO', 'VP', 'MEMBER'];
        const leftIndex = order.indexOf(left.position);
        const rightIndex = order.indexOf(right.position);
        return (leftIndex === -1 ? 99 : leftIndex) - (rightIndex === -1 ? 99 : rightIndex);
    });
    const nonCeoMembers = members.filter((member) => !member.isCeo);
    const me = members.find((member) => member.id === myId);
    const isCeo = corporation.isCeo;
    const isCfo = me?.position === 'CFO';
    const isHoldingBoardMember = Boolean(isHoldingCompany && corporation?.boardActions?.isBoardMember);
    const isLineManager = ['CFO', 'CTO', 'VP'].includes(me?.position);
    const lineManagers = members.filter((member) => ['CFO', 'CTO', 'VP'].includes(member.position));
    const manageableMembers = isCeo
        ? nonCeoMembers
        : members.filter((member) => Number(member.reportsToId) === Number(myId) && ['VP', 'MEMBER'].includes(member.position));
    const showOrgPreviews = Boolean(isLocalEnvironment && !isHoldingCompany && !isSubsidiary);
    const isHoldingPreview = showOrgPreviews && orgPreview !== 'phase1';
    const orgPreviewModel = isHoldingPreview ? buildOrgPreview(corporation, members, orgPreview) : null;
    const director = members.find((member) => member.position === 'BOARD_DIRECTOR') ?? null;
    const realHoldingModel = isHoldingCompany ? {
        trust: null,
        holdingCompany: {
            name: corporation.name,
            city: corporation.city,
            imageUrl: corporation.imageUrl,
            director,
            boardMembers: members.filter((member) => member.position !== 'BOARD_DIRECTOR'),
            boardCapacity: boardActions.capacity,
            boardCount: boardActions.boardCount,
        },
        operatingCompanies: corporation.subsidiaries ?? [],
    } : corporation.holdingContext ? {
        ...corporation.holdingContext,
        holdingCompany: {
            ...corporation.holdingContext.holdingCompany,
            city: corporation.holdingContext.holdingCompany?.city ?? parentTrust?.city,
        },
    } : null;
    // Quick actions in the org chart's member dialog: the same people the
    // Personnel tab lets this viewer manage (operating companies only).
    const canManageMembers = !isHoldingCompany && (isCeo || isLineManager);
    const manageableIds = new Set(canManageMembers ? manageableMembers.map((member) => Number(member.id)) : []);
    const renderMemberActions = (person, { close }) => {
        if (person.isPreview || Number(person.id) === Number(myId)) return null;
        const member = members.find((candidate) => Number(candidate.id) === Number(person.id));
        if (!member || !manageableIds.has(Number(member.id))) return null;

        return (
            <MemberQuickActions
                member={member}
                onOpenPersonnel={() => {
                    close();
                    setActiveTab('personnel');
                }}
                onDone={close}
            />
        );
    };
    const showHoldingLayout = isHoldingPreview || isHoldingCompany || Boolean(corporation.holdingContext);
    const boardSeatLabel = `${boardActions.boardCount ?? members.length} / ${boardActions.capacity ?? 0}`;
    const memberSeatLabel = `${members.length} / ${corporation.maxMembers}`;
    const visibleTabs = TABS.filter((tab) => {
        if (tab.id === 'settings') {
            return isCeo;
        }

        if (tab.id === 'voting') {
            return isHoldingCompany;
        }

        return true;
    });

    return (
        <>
            <Head title={`${corporation.name} Boardroom`} />
            <div className="mx-auto flex w-full max-w-[58rem] flex-col gap-3 px-3 py-4">
                <motion.section
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    className="relative overflow-hidden rounded-[1.25rem] border border-white/8 bg-slate-900/75 shadow-[0_16px_45px_rgba(2,6,23,0.25)]"
                >
                    <div className="absolute inset-0">
                        <img
                            src={corporation.imageUrl || 'https://images.thedirector.app/Careers/boardroom.jpg'}
                            alt=""
                            onError={(event) => { event.currentTarget.style.visibility = 'hidden'; }}
                            className="h-full w-full object-cover opacity-30 blur-sm scale-105"
                        />
                        <div className="absolute inset-0 bg-gradient-to-r from-slate-950 via-slate-950/70 to-slate-900/45" />
                        <div className="absolute inset-0 bg-[radial-gradient(circle_at_80%_15%,rgba(245,158,11,0.16),transparent_34%)]" />
                    </div>

                    <div className="relative grid gap-4 p-3 sm:p-4 nav:grid-cols-[1fr_18rem] nav:items-stretch">
                        <div className="flex min-h-[10rem] flex-col justify-end px-2 py-2 sm:px-3">
                            <p className="mb-2 text-[9px] font-black uppercase tracking-[0.35em] text-amber-300/90">Boardroom</p>
                            <h1 className="max-w-2xl text-2xl font-black tracking-tight text-white text-balance sm:text-3xl">{corporation.name}</h1>

                        </div>
                        <div className="overflow-hidden rounded-2xl border border-white/12 bg-slate-950/45 p-2 shadow-[inset_0_1px_0_rgba(255,255,255,0.06)]">
                            <CorpLogo
                                src={corporation.imageUrl}
                                name={corporation.name}
                                size={null}
                                className="h-32 w-full nav:h-40"
                                imgClassName="object-center brightness-110 contrast-105 saturate-110"
                                textClassName="text-5xl nav:text-6xl"
                            />
                        </div>
                    </div>

                    <div className="relative grid gap-2 border-t border-white/8 bg-slate-950/35 px-3 py-2.5 sm:grid-cols-3 sm:px-4">
                        <HeroFact label="HQ" value={corporation.city ?? 'Unknown'} />
                        <HeroFact
                            label={isHoldingCompany ? 'Board' : isSubsidiary ? 'Reports To' : 'Members'}
                            value={isHoldingCompany ? boardSeatLabel : isSubsidiary ? parentTrust.name : memberSeatLabel}
                        />
                        <HeroFact label="Slush" value={formatCash(corporation.slush_fund)} accent="text-emerald-300" />
                    </div>
                </motion.section>

                <div className="flex flex-wrap gap-1.5">
                    {visibleTabs.map((tab) => {
                        const active = activeTab === tab.id;
                        return (
                            <button
                                key={tab.id}
                                type="button"
                                onClick={() => setActiveTab(tab.id)}
                                className={`rounded-full px-3 py-1.5 text-[9px] font-black uppercase tracking-[0.22em] transition ${active ? 'bg-cyan-500 text-slate-950' : 'border border-slate-700/60 bg-slate-900/70 text-slate-400 hover:text-slate-200'}`}
                            >
                                {tab.label}
                            </button>
                        );
                    })}
                </div>

                {activeTab === 'overview' && (
                    <div className={`grid gap-3 ${showHoldingLayout ? '' : 'nav:grid-cols-[minmax(0,1.35fr)_minmax(16rem,0.65fr)]'}`}>
                        <Panel
                            title="Organization"
                            subtitle={isHoldingCompany ? 'Holding company and subsidiaries.' : isSubsidiary ? `Reports to ${parentTrust.name}.` : 'Live chain of command.'}
                            accent="text-amber-400"
                            className="min-w-0 overflow-hidden"
                        >
                            {showOrgPreviews && (
                                <div className="mb-3 flex flex-wrap gap-2">
                                    {[
                                        { id: 'phase1', label: 'Phase 1' },
                                        { id: 'phase2', label: 'Phase 2' },
                                        { id: 'phase3', label: 'Phase 3' },
                                    ].map((option) => {
                                        const active = orgPreview === option.id;

                                        return (
                                            <button
                                                key={option.id}
                                                type="button"
                                                onClick={() => setOrgPreview(option.id)}
                                                className={`rounded-full px-3 py-1.5 text-[9px] font-black uppercase tracking-[0.2em] transition ${active ? 'bg-amber-400 text-slate-950' : 'border border-slate-700/60 bg-slate-950/45 text-slate-400 hover:text-slate-200'}`}
                                            >
                                                {option.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                            {orgPreviewModel ? (
                                <OrgChart
                                    mode="holding"
                                    trust={orgPreviewModel.trust}
                                    holdingCompany={orgPreviewModel.holdingCompany}
                                    operatingCompanies={orgPreviewModel.operatingCompanies}
                                    currentUserId={myId}
                                />
                            ) : realHoldingModel ? (
                                <OrgChart
                                    mode="holding"
                                    trust={realHoldingModel.trust}
                                    holdingCompany={realHoldingModel.holdingCompany}
                                    operatingCompanies={realHoldingModel.operatingCompanies}
                                    currentUserId={myId}
                                    renderMemberActions={renderMemberActions}
                                />
                            ) : (
                                <OrgChart
                                    members={members}
                                    currentUserId={myId}
                                    companyName={corporation.name}
                                    rootReportsTo={parentTrust?.name ?? null}
                                    renderMemberActions={renderMemberActions}
                                />
                            )}
                        </Panel>

                        <div className={showHoldingLayout ? 'grid min-w-0 gap-3 nav:grid-cols-2' : 'min-w-0 space-y-3'}>
                            <Panel
                                title="Board Notes"
                                subtitle=""
                                accent="text-emerald-400"
                            >
                                <BoardNotes notes={corporation.boardNotes} isCeo={false} />
                            </Panel>
                        </div>
                    </div>
                )}

                {activeTab === 'personnel' && (
                    <div className="grid gap-3 nav:grid-cols-[0.78fr_1.22fr] nav:items-start">
                        <Panel
                            title="Roster"
                            subtitle={isHoldingCompany ? `${boardSeatLabel} board seats filled.` : `${memberSeatLabel} seats filled.`}
                            accent="text-cyan-400"
                            className="nav:self-start"
                        >
                            <div className="space-y-2">
                                {sortedMembers.map((member) => <MemberRow key={member.id} member={member} corporation={corporation} />)}
                            </div>
                        </Panel>

                        <div className="grid gap-3 sm:grid-cols-2">
                            {isCeo ? (
                                <>
                                    <Panel title="Invite" accent="text-emerald-400" className="sm:col-span-2">
                                        <InviteForm />
                                    </Panel>

                                    <Panel title="Assign Roles" accent="text-violet-400">
                                        <AssignForm members={nonCeoMembers} managers={lineManagers} />
                                    </Panel>

                                    <Panel title="Demote Rank" accent="text-amber-400">
                                        <DemoteRankForm members={nonCeoMembers} />
                                    </Panel>

                                    <Panel title="Remove Member" accent="text-red-400">
                                        <KickForm members={nonCeoMembers} />
                                    </Panel>

                                    <Panel title="Transfer Leadership" accent="text-amber-400">
                                        <TransferLeadershipForm members={nonCeoMembers} />
                                    </Panel>
                                </>
                            ) : isLineManager ? (
                                <>
                                    <Panel title="Demote Position" accent="text-violet-400">
                                        <AssignForm members={manageableMembers} mode="manager" />
                                    </Panel>

                                    <Panel title="Demote Rank" accent="text-amber-400">
                                        <DemoteRankForm members={manageableMembers} />
                                    </Panel>

                                    <Panel title="Remove Member" accent="text-red-400">
                                        <KickForm members={manageableMembers} label="Remove Member" />
                                    </Panel>
                                </>
                            ) : (
                                <Panel
                                    title="Access"
                                    subtitle=" You do not have any rights to manage roles or transfers."
                                    accent="text-slate-300"
                                    className="sm:col-span-2"
                                />
                            )}
                        </div>
                    </div>
                )}

                {activeTab === 'finance' && (
                    <div className="grid gap-3 nav:grid-cols-[0.9fr_1.1fr]">
                        <Panel
                            title="Treasury"
                            subtitle=""
                            accent="text-emerald-400"
                        >
                            <div className="divide-y divide-slate-800/60">
                                <TreasuryRow
                                    label="Cash Reserves"
                                    value={formatTreasuryCash(corporation.cash_reserves)}
                                />
                                <TreasuryRow
                                    label="Slush Fund"
                                    value={formatTreasuryCash(corporation.slush_fund)}
                                />
                                <TreasuryRow
                                    label="Total Profits"
                                    value={formatTreasuryCash(corporation.total_profits)}
                                    hint="Total ever earned - Deposits excluded"
                                />
                            </div>
                        </Panel>

                        <div className="space-y-4">
                            <Panel title="Deposit Funds" accent="text-emerald-400">
                                <DepositForm />
                            </Panel>

                            {((isHoldingCompany && isHoldingBoardMember) || (!isHoldingCompany && (isCeo || isCfo))) && (
                                <Panel title="Distribute Funds" accent="text-cyan-400">
                                    <DistributeForm members={members} myId={myId} isHoldingCompany={isHoldingCompany} />
                                </Panel>
                            )}

                            {!isHoldingCompany && !isCeo && !isCfo && (
                                <Panel title="Financial Access" accent="text-slate-300">
                                    <p className="text-sm text-slate-400">Only the CEO or CFO can distribute funds.</p>
                                </Panel>
                            )}

                            {isHoldingCompany && !isHoldingBoardMember && (
                                <Panel title="Finance Access" accent="text-slate-300">
                                    <p className="text-sm text-slate-400">Only board members can distribute holding funds.</p>
                                </Panel>
                            )}
                        </div>
                    </div>
                )}

                {activeTab === 'properties' && (
                    <PropertiesPanel corporation={corporation} />
                )}

                {activeTab === 'danger' && (
                    <div className="grid gap-3 nav:grid-cols-[1.25fr_0.75fr]">
                        <Panel
                            title="Create Holding Company"
                            subtitle={isHoldingCompany ? 'Kicking out a subsidiary reduces your number of board seats. You can invite a subsidiary even when board seats are full granted  that the additional +1 subsidiary seat would be enough to accomodate the new board member.' : isSubsidiary ? `` : ''}
                            accent="text-amber-400"
                        >
                            <CompanyActions
                                corporation={corporation}
                                merger={merger}
                                subsidiaryInvites={subsidiaryInvites}
                                isCeo={isCeo}
                                errors={errors}
                            />
                        </Panel>
                        <Panel
                            title="Critical Actions"

                            accent="text-red-400"
                        >
                            <CriticalActions corporation={corporation} isCeo={isCeo} />
                        </Panel>
                    </div>
                )}

                {activeTab === 'voting' && (
                    <Panel
                        title="Voting"
                        accent="text-amber-400"
                    >
                        <TrustVotePanel trustVote={boardActions.trustVote} />
                    </Panel>
                )}

                {activeTab === 'settings' && isCeo && (
                    <CompanySettings corporation={corporation} errors={errors} />
                )}
            </div>
        </>
    );
}

Corporate.layout = (page) => <GameLayout wide children={page} />;
