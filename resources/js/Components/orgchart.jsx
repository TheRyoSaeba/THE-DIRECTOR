import { useMemo } from 'react';
import { motion } from 'framer-motion';
import { Star, User } from '@phosphor-icons/react';

const ROLE_META = {
    BOARD_DIRECTOR: { label: 'Director of the Board', color: 'text-slate-100', border: 'border-slate-400/35', bg: 'bg-slate-500/10', order: 0 },
    CHAIRMAN: { label: 'CHAIRMAN', color: 'text-orange-200', border: 'border-orange-300/30', bg: 'bg-orange-300/10', order: 1 },
    GROUP_PRESIDENT: { label: 'Group President', color: 'text-orange-200', border: 'border-orange-300/30', bg: 'bg-orange-300/10', order: 1 },
    BOARD: { label: 'Board of Directors', color: 'text-slate-200', border: 'border-slate-500/45', bg: 'bg-slate-500/10', order: 2 },
    DIRECTOR: { label: 'Director', color: 'text-violet-300', border: 'border-violet-400/35', bg: 'bg-violet-400/10', order: 3 },
    CEO: { label: 'CEO', color: 'text-amber-300', border: 'border-amber-400/45', bg: 'bg-amber-400/10', order: 4 },
    CFO: { label: 'CFO', color: 'text-emerald-300', border: 'border-emerald-400/35', bg: 'bg-emerald-400/10', order: 5 },
    CTO: { label: 'CTO', color: 'text-sky-300', border: 'border-sky-400/35', bg: 'bg-sky-400/10', order: 6 },
    VP: { label: 'VP', color: 'text-cyan-300', border: 'border-cyan-400/30', bg: 'bg-cyan-400/10', order: 7 },
    MEMBER: { label: 'Member', color: 'text-slate-300', border: 'border-slate-600/50', bg: 'bg-slate-800/45', order: 8 },
};

const LINE = 'bg-white/75 shadow-[0_0_10px_rgba(255,255,255,0.22)]';

function normalizePosition(position) {
    return (position || 'MEMBER').toString().toUpperCase();
}

function roleMeta(person) {
    return ROLE_META[normalizePosition(person?.position)] ?? ROLE_META.MEMBER;
}

function sortPeople(people = []) {
    return [...people].sort((left, right) => {
        const leftMeta = roleMeta(left);
        const rightMeta = roleMeta(right);

        if (leftMeta.order !== rightMeta.order) {
            return leftMeta.order - rightMeta.order;
        }

        return (left.name ?? '').localeCompare(right.name ?? '');
    });
}

function OrgNode({ person, currentUserId, large = false, compact = false, micro = false }) {
    const meta = roleMeta(person);
    const isCurrentUser = person?.id === currentUserId;
    const label = person?.title ?? meta.label;
    const frameSize = large ? 'h-16 w-16' : micro ? 'h-9 w-9' : compact ? 'h-12 w-12' : 'h-14 w-14';
    const iconSize = large ? 24 : micro ? 14 : compact ? 16 : 20;
    const widthClass = large ? 'w-40' : micro ? 'w-20' : compact ? 'w-28' : 'w-36';

    return (
        <motion.div
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            className={`relative flex flex-col items-center ${widthClass}`}
        >
            {person?.isFounder && (
                <div className={`absolute top-0 z-10 flex items-center justify-center rounded-full bg-amber-400 text-slate-950 ring-2 ring-slate-950 ${micro ? 'right-1 h-3.5 w-3.5' : compact ? 'right-2 h-4 w-4' : 'right-4 h-5 w-5'}`}>
                    <Star size={micro ? 9 : 11} weight="fill" />
                </div>
            )}

            <div className={`relative overflow-hidden rounded-2xl border ${meta.border} ${meta.bg} ${frameSize} shadow-[0_10px_28px_rgba(2,6,23,0.28)]`}>
                {person?.avatarUrl ? (
                    <img src={person.avatarUrl} alt={person.name} className="h-full w-full object-cover" />
                ) : (
                    <div className="flex h-full w-full items-center justify-center bg-slate-950/70">
                        <User size={iconSize} className={meta.color} weight="bold" />
                    </div>
                )}
            </div>

            <div className={`max-w-full truncate text-center font-black uppercase tracking-[0.03em] text-white ${large ? 'mt-2 text-sm' : micro ? 'mt-1 text-[9px]' : compact ? 'mt-1.5 text-[11px]' : 'mt-2 text-xs'}`}>
                {person?.name ?? 'Vacant'}
            </div>

            <div className={`mt-1 max-w-full truncate rounded-md border py-0.5 font-black uppercase ${micro ? 'px-1 text-[7px] tracking-[0.08em]' : compact ? 'px-1.5 text-[8px] tracking-[0.09em]' : 'px-2 text-[9px] tracking-[0.12em]'} ${meta.border} ${meta.bg} ${meta.color}`}>
                {label}
            </div>

            {isCurrentUser && (
                <div className={`mt-1 font-black uppercase tracking-[0.2em] text-cyan-300 ${micro ? 'text-[6px]' : compact ? 'text-[7px]' : 'text-[8px]'}`}>You</div>
            )}
        </motion.div>
    );
}

function VerticalLine({ height = 'h-6' }) {
    return <div className={`${height} w-[3px] ${LINE}`} />;
}

function chartWidth(count, compact = false, micro = false) {
    const columnWidth = micro ? 5.25 : compact ? 7.25 : 9.5;

    return `${Math.max(count * columnWidth, columnWidth)}rem`;
}

function companyChartWidth(count) {
    return `${Math.max(1, count) * 14.5}rem`;
}

function connectorPosition(index, count) {
    if (count <= 1) {
        return 50;
    }

    return ((index + 0.5) / count) * 100;
}

function ConnectorRow({ fromCount = 1, toCount = 1, width }) {
    const parentPositions = Array.from({ length: fromCount }, (_, index) => connectorPosition(index, fromCount));
    const childPositions = Array.from({ length: toCount }, (_, index) => connectorPosition(index, toCount));
    const spanPositions = [...parentPositions, ...childPositions];
    const left = Math.min(...spanPositions);
    const right = 100 - Math.max(...spanPositions);

    return (
        <div className="relative h-7" style={{ width }}>
            <div
                className={`absolute top-1/2 h-[3px] -translate-y-1/2 ${LINE}`}
                style={{ left: `${left}%`, right: `${right}%` }}
            />

            {parentPositions.map((position, index) => (
                <div
                    key={`parent-${index}`}
                    className={`absolute top-0 h-1/2 w-[3px] -translate-x-1/2 ${LINE}`}
                    style={{ left: `${position}%` }}
                />
            ))}

            {childPositions.map((position, index) => (
                <div
                    key={`child-${index}`}
                    className={`absolute bottom-0 h-1/2 w-[3px] -translate-x-1/2 ${LINE}`}
                    style={{ left: `${position}%` }}
                />
            ))}
        </div>
    );
}

function ConnectorLabelRow({ children, align = 'right', count = 1, width, height = '1.75rem' }) {
    const positions = Array.from({ length: Math.max(1, count) }, (_, index) => connectorPosition(index, Math.max(1, count)));

    return (
        <div className="relative" style={{ width, height }}>
            {positions.map((position, index) => (
                <div
                    key={`label-bridge-${index}`}
                    className={`absolute top-0 h-full w-[3px] -translate-x-1/2 ${LINE}`}
                    style={{ left: `${position}%` }}
                />
            ))}
            <span
                className={`absolute top-1/2 z-10 -translate-y-1/2 bg-slate-900 px-2 text-[8px] font-black uppercase tracking-[0.26em] text-slate-500 ${align === 'left' ? 'left-0' : align === 'center' ? 'left-1/2 -translate-x-1/2' : 'right-0'
                    }`}
            >
                {children}
            </span>
        </div>
    );
}

function PeopleRow({ people, currentUserId, width, compact = false, micro = false }) {
    if (!people.length) {
        return null;
    }

    return (
        <div
            className={`grid ${micro ? 'gap-1.5' : compact ? 'gap-2' : 'gap-3'}`}
            style={{ width, gridTemplateColumns: `repeat(${people.length}, minmax(0, 1fr))` }}
        >
            {people.map((person) => (
                <div key={person.id} className="flex justify-center">
                    <OrgNode person={person} currentUserId={currentUserId} compact={compact} micro={micro} />
                </div>
            ))}
        </div>
    );
}

function ReportsGrid({ managers, reportsByManager, currentUserId, compact = false, micro = false, width }) {
    if (!managers.length) {
        return null;
    }

    return (
        <div
            className={`grid ${micro ? 'gap-1.5' : compact ? 'gap-2' : 'gap-3'}`}
            style={{ width, gridTemplateColumns: `repeat(${managers.length}, minmax(0, 1fr))` }}
        >
            {managers.map((manager) => {
                const reports = reportsByManager.get(manager.id) ?? [];
                const reportWidth = chartWidth(Math.max(reports.length, 1), compact, micro);
                const reportManagers = reports.filter((person) => normalizePosition(person.position) === 'VP');
                const hasNestedReports = reportManagers.some((person) => (reportsByManager.get(person.id) ?? []).length > 0);

                return (
                    <div
                        key={`reports-${manager.id}`}
                        className="flex min-w-max flex-col items-center justify-self-center"
                        style={{ width: reportWidth }}
                    >
                        {reports.length > 0 ? (
                            <>
                                <ConnectorRow fromCount={1} toCount={reports.length} width={reportWidth} />
                                <PeopleRow people={reports} currentUserId={currentUserId} width={reportWidth} compact={compact} micro={micro} />
                                {hasNestedReports && (
                                    <ReportsGrid
                                        managers={reportManagers}
                                        reportsByManager={reportsByManager}
                                        currentUserId={currentUserId}
                                        compact={compact}
                                        micro={micro}
                                        width={reportWidth}
                                    />
                                )}
                            </>
                        ) : (
                            <div className="h-7" />
                        )}
                    </div>
                );
            })}
        </div>
    );
}

function SingleCompanyChart({ members, currentUserId, compact = false, micro = false }) {
    const { ceo, rows, fallback, cSuite, assignedReports, unassignedLower, hasAssignedReports } = useMemo(() => {
        const sorted = sortPeople(members);
        const chief = sorted.find((member) => normalizePosition(member.position) === 'CEO') ?? null;
        const others = sorted.filter((member) => member.id !== chief?.id);
        const executives = others.filter((member) => ['CFO', 'CTO'].includes(normalizePosition(member.position)));
        const vps = others.filter((member) => normalizePosition(member.position) === 'VP');
        const lower = others.filter((member) => ['VP', 'MEMBER'].includes(normalizePosition(member.position)));
        const managers = [...executives, ...vps];
        const managerIds = new Set(managers.map((member) => member.id));
        const reportsMap = new Map(managers.map((member) => [member.id, []]));

        lower.forEach((member) => {
            const reportsToId = Number(member.reportsToId);
            if (managerIds.has(reportsToId)) {
                reportsMap.get(reportsToId).push(member);
            }
        });

        const assignedIds = new Set([...reportsMap.values()].flat().map((member) => member.id));

        return {
            ceo: chief,
            rows: [
                executives,
                others.filter((member) => normalizePosition(member.position) === 'VP'),
                others.filter((member) => normalizePosition(member.position) === 'MEMBER'),
            ].filter((row) => row.length > 0),
            fallback: sorted,
            cSuite: executives,
            assignedReports: reportsMap,
            unassignedLower: lower.filter((member) => !assignedIds.has(member.id)),
            hasAssignedReports: assignedIds.size > 0,
        };
    }, [members]);

    if (!fallback.length) {
        return (
            <div className="flex flex-col items-center justify-center py-10 text-center">
                <User size={34} className="mb-3 text-slate-700" weight="duotone" />
                <p className="text-sm font-semibold text-slate-500">No organization structure</p>
            </div>
        );
    }

    if (!ceo) {
        return (
            <div className={`overflow-x-auto ${micro ? 'py-2' : 'py-4'}`}>
                <PeopleRow
                    people={fallback}
                    currentUserId={currentUserId}
                    width={chartWidth(fallback.length, compact, micro)}
                    compact={compact}
                    micro={micro}
                />
            </div>
        );
    }

    const maxColumns = Math.max(1, ...rows.map((row) => row.length));
    const rowWidth = chartWidth(maxColumns, compact, micro);
    const topDirectReports = sortPeople([...cSuite, ...unassignedLower]);

    if ((hasAssignedReports || unassignedLower.length > 0) && topDirectReports.length > 0) {
        const assignedWidth = chartWidth(topDirectReports.length, compact, micro);
        return (
            <div className={`overflow-x-auto ${micro ? 'py-2' : 'py-4'}`}>
                <div className={`mx-auto flex min-w-max flex-col items-center ${micro ? 'px-1' : 'px-4'}`}>
                    <OrgNode person={ceo} currentUserId={currentUserId} large={!compact && !micro} compact={compact} micro={micro} />
                    <ConnectorRow fromCount={1} toCount={topDirectReports.length} width={assignedWidth} />
                    <PeopleRow people={topDirectReports} currentUserId={currentUserId} width={assignedWidth} compact={compact} micro={micro} />
                    <ReportsGrid
                        managers={topDirectReports}
                        reportsByManager={assignedReports}
                        currentUserId={currentUserId}
                        compact={compact}
                        micro={micro}
                        width={assignedWidth}
                    />
                </div>
            </div>
        );
    }

    return (
        <div className={`overflow-x-auto ${micro ? 'py-2' : 'py-4'}`}>
            <div className={`mx-auto flex min-w-max flex-col items-center ${micro ? 'px-1' : 'px-4'}`}>
                <OrgNode person={ceo} currentUserId={currentUserId} large={!compact && !micro} compact={compact} micro={micro} />
                {rows.map((row, index) => (
                    <div key={index} className="flex w-full flex-col items-center">
                        <ConnectorRow
                            fromCount={index === 0 ? 1 : rows[index - 1].length}
                            toCount={row.length}
                            width={rowWidth}
                        />
                        <PeopleRow people={row} currentUserId={currentUserId} width={rowWidth} compact={compact} micro={micro} />
                    </div>
                ))}
            </div>
        </div>
    );
}

function CompanyTile({ title, subtitle, label, imageUrl = null, large = false }) {
    const widthClass = large ? 'w-[17rem]' : 'w-[13.75rem]';
    const imageHeight = large ? 'h-20' : 'h-14';

    return (
        <div className={`overflow-hidden rounded-2xl border border-white/12 bg-slate-950 shadow-[0_10px_22px_rgba(2,6,23,0.22)] ${widthClass}`}>
            <div className={`relative ${imageHeight} bg-slate-900`}>
                {imageUrl ? (
                    <img
                        src={imageUrl}
                        alt={title ?? ''}
                        className="absolute inset-0 h-full w-full object-cover brightness-110 contrast-105 saturate-110"
                    />
                ) : (
                    <div className="absolute inset-0 bg-gradient-to-br from-slate-700 via-slate-900 to-slate-950" />
                )}
            </div>
            <div className="border-t border-white/10 bg-slate-950 px-3 py-2 text-center">
                {label && (
                    <p className="text-[8px] font-black uppercase tracking-[0.24em] text-slate-500">
                        {label}
                    </p>
                )}
                <p className={`truncate font-black uppercase tracking-[0.04em] text-white ${large ? 'text-sm' : 'text-xs'}`}>
                    {title}
                </p>
                {subtitle && (
                    <p className="mt-0.5 truncate text-[9px] font-bold uppercase tracking-[0.16em] text-slate-400">
                        {subtitle}
                    </p>
                )}
            </div>
        </div>
    );
}

function BoardRow({ boardMembers, currentUserId, width }) {
    if (!boardMembers.length) {
        return null;
    }

    return (
        <div className="flex flex-col items-center">
            <ConnectorLabelRow width={width}>Board of Directors</ConnectorLabelRow>
            <ConnectorRow fromCount={1} toCount={boardMembers.length} width={width} />
            <PeopleRow people={boardMembers} currentUserId={currentUserId} width={width} compact />
        </div>
    );
}

function CompanyRow({ companies, currentUserId, width }) {
    if (!companies.length) {
        return null;
    }

    return (
        <div
            className="grid gap-0"
            style={{ width, gridTemplateColumns: `repeat(${companies.length}, minmax(0, 1fr))` }}
        >
            {companies.map((company, index) => (
                <div key={company.id ?? index} className="flex min-w-max justify-center">
                    <div className="flex flex-col items-center">
                        <CompanyTile
                            title={company.name}
                            subtitle={company.city}
                            label="Operating Company"
                            imageUrl={company.imageUrl || company.image_url}
                        />
                        <VerticalLine height="h-4" />
                        <SingleCompanyChart members={company.members ?? []} currentUserId={currentUserId} compact micro />
                    </div>
                </div>
            ))}
        </div>
    );
}

function HoldingOrgChart({ trust, holdingCompany, operatingCompanies = [], currentUserId }) {
    const symbolicDirector = holdingCompany?.director ?? trust?.BOARD_DIRECTOR ?? null;
    const boardMembers = sortPeople(holdingCompany?.boardMembers ?? [])
        .filter((member) => member?.id !== symbolicDirector?.id && normalizePosition(member?.position) !== 'BOARD_DIRECTOR');
    const companies = operatingCompanies ?? [];
    const visibleCompanies = companies.slice(0, 4);
    const visibleBoardMembers = boardMembers.slice(0, 5);
    const companyWidth = companyChartWidth(visibleCompanies.length);
    const boardWidth = visibleBoardMembers.length > 0 ? chartWidth(visibleBoardMembers.length, true) : chartWidth(1, true);
    const scaffoldWidth = `${Math.max(parseFloat(companyWidth), parseFloat(boardWidth), 18)}rem`;

    return (
        <div className="w-full overflow-x-auto py-3">
            <div className="mx-auto flex min-w-max flex-col items-center px-2">
                {symbolicDirector && (
                    <>
                        <OrgNode
                            person={{
                                ...symbolicDirector,
                                position: 'BOARD_DIRECTOR',
                                title: 'Director of the Board',
                            }}
                            currentUserId={currentUserId}
                            large
                        />
                        <VerticalLine height="h-4" />
                    </>
                )}

                <CompanyTile
                    title={holdingCompany?.name ?? 'Holding company'}
                    subtitle={symbolicDirector ? '' : null}
                    label="Holding Company"
                    imageUrl={holdingCompany?.imageUrl || holdingCompany?.image_url}
                    large
                />

                {visibleBoardMembers.length > 0 && (
                    <BoardRow boardMembers={visibleBoardMembers} currentUserId={currentUserId} width={scaffoldWidth} />
                )}

                {visibleCompanies.length > 0 && (
                    <>
                        <ConnectorLabelRow
                            align="left"
                            count={visibleBoardMembers.length || 1}
                            width={scaffoldWidth}
                        >
                            Operating Companies
                        </ConnectorLabelRow>
                        <ConnectorRow
                            fromCount={visibleBoardMembers.length || 1}
                            toCount={visibleCompanies.length}
                            width={scaffoldWidth}
                        />
                        <CompanyRow companies={visibleCompanies} currentUserId={currentUserId} width={scaffoldWidth} />
                    </>
                )}
            </div>
        </div>
    );
}

export default function OrgChart({
    members = [],
    mode = 'single',
    trust = null,
    holdingCompany = null,
    operatingCompanies = [],
    currentUserId,
}) {
    if (mode === 'holding') {
        return (
            <HoldingOrgChart
                trust={trust}
                holdingCompany={holdingCompany}
                operatingCompanies={operatingCompanies}
                currentUserId={currentUserId}
            />
        );
    }

    return <SingleCompanyChart members={members} currentUserId={currentUserId} />;
}
