import { useMemo, useState } from "react";
import { Link, router } from "@inertiajs/react";
import { route } from "ziggy-js";
import { motion } from "framer-motion";
import { AirplaneTilt, CaretDown, Wrench } from "@phosphor-icons/react";
import { Button, Input } from "@/Components/ui";
import { parseSymbolicAmount, prefetchPropsFor, resolveIcon } from "./GameLayoutComponents";

const readLastEarnId = () => {
    try {
        return localStorage.getItem("last_earn_id");
    } catch {
        return null;
    }
};

/**
 * Expand / quick-withdraw / quick-work state, owned by GameLayout so the
 * desktop sidebar and the mobile drawer share it (and it survives the drawer
 * closing), exactly as before the NavList extraction.
 */
export function useNavListState() {
    const [workExpanded, setWorkExpanded] = useState(false);
    const [bankExpanded, setBankExpanded] = useState(false);
    const [bankWithdrawAmount, setBankWithdrawAmount] = useState("");
    const [bankWithdrawBusy, setBankWithdrawBusy] = useState(false);
    const [quickWorking, setQuickWorking] = useState(false);

    const quickWork = (onDone) => {
        const lastEarnId = readLastEarnId();
        if (!lastEarnId || quickWorking) return;
        setQuickWorking(true);
        router.post(route("work.attempt"), { earn_id: parseInt(lastEarnId) }, {
            only: ["auth", "flash"],
            preserveScroll: true,
            onFinish: () => setQuickWorking(false),
        });
        onDone?.();
    };

    const withdraw = (url, onSuccess) => {
        const parsedVal = parseSymbolicAmount(bankWithdrawAmount);
        if (!parsedVal || parsedVal <= 0 || bankWithdrawBusy) return;
        setBankWithdrawBusy(true);
        router.post(url, { amount: parsedVal }, {
            preserveScroll: true,
            onSuccess: () => {
                setBankWithdrawAmount("");
                setBankExpanded(false);
                onSuccess?.();
            },
            onFinish: () => setBankWithdrawBusy(false),
        });
    };

    return {
        workExpanded, setWorkExpanded,
        bankExpanded, setBankExpanded,
        bankWithdrawAmount, setBankWithdrawAmount,
        bankWithdrawBusy, quickWorking,
        quickWork, withdraw,
    };
}

/** Sub-links a nav item can carry (server flags from HandleInertiaRequests::getNavigation). */
const subLinksFor = (item) => {
    const links = [];
    if (item.isTechnician) links.push({ href: "/career/technician", label: "Workshop", icon: Wrench });
    if (item.isCustoms) links.push({ href: "/career/customs", label: "Customs", icon: AirplaneTilt });
    if (item.hasActions && item.actionsUrl) links.push({ href: item.actionsUrl, label: "Actions" });
    return links;
};

const pathMatches = (path, href) =>
    typeof href === "string" && href !== "" && (path === href || path.startsWith(`${href}/`));

/**
 * Longest nav href that prefixes the current path, so /new-york/bank
 * highlights Bank (not the /new-york City item) and /actions highlights the
 * Actions sub-link under Work.
 */
export function useActiveHref(sections, pagePath) {
    return useMemo(() => {
        let best = null;
        for (const section of sections) {
            for (const item of section.items) {
                for (const href of [item.href, ...subLinksFor(item).map((s) => s.href)]) {
                    if (pathMatches(pagePath, href) && (!best || href.length > best.length)) best = href;
                }
            }
        }
        return best;
    }, [sections, pagePath]);
}

const subLinkCls = (active) =>
    `flex min-h-[40px] w-full items-center gap-3 px-4 pl-11 text-left text-xs font-bold uppercase tracking-wider transition-colors ${active
        ? "text-cyan-300 bg-cyan-500/10"
        : "text-slate-400 hover:text-cyan-300 hover:bg-slate-800/40"}`;

/**
 * Navigation sections, shared by the desktop sidebar and the mobile drawer.
 * `onNavigate` (drawer) runs after any link / quick action so the drawer closes.
 */
export default function NavList({ sections, pagePath, state, onNavigate }) {
    const activeHref = useActiveHref(sections, pagePath);
    const hasLastEarn = Boolean(readLastEarnId());

    return (
        <nav aria-label="Main" className="flex-1 overflow-y-auto overscroll-contain py-3">
            {sections.map((section) => (
                <div key={section.section} className="mb-4">
                    <div className="px-4 mb-1.5 text-label uppercase text-cyan-400">
                        {section.section}
                    </div>
                    <ul>
                        {section.items.map((item) => {
                            const hasQuickWork = item.label === "Work" && hasLastEarn;
                            const subLinks = subLinksFor(item);
                            const isExpandable = hasQuickWork || subLinks.length > 0 || item.hasWithdraw;
                            const isActive = item.href === activeHref;
                            const childActive = subLinks.some((s) => s.href === activeHref);
                            const userExpanded = item.hasWithdraw ? state.bankExpanded : state.workExpanded;
                            const expanded = isExpandable && (userExpanded || childActive);
                            const Icon = resolveIcon(item.icon);
                            const panelId = `nav-sub-${section.section}-${item.label}`.replace(/\W+/g, "-");

                            return (
                                <li key={item.label} className="flex flex-col">
                                    <div className={`relative flex items-center ${isActive || childActive ? "bg-slate-800/50" : ""}`}>
                                        {(isActive || childActive) && (
                                            <span aria-hidden className={`absolute inset-y-1 left-0 w-0.5 rounded-r ${isActive ? "bg-cyan-400" : "bg-cyan-400/40"}`} />
                                        )}
                                        <Link
                                            href={item.href}
                                            {...prefetchPropsFor(item.href)}
                                            onClick={onNavigate}
                                            aria-current={isActive ? "page" : undefined}
                                            className={`group flex min-h-[44px] flex-1 items-center justify-between gap-2 px-4 text-left transition-colors ${isActive ? "text-white" : "text-slate-300 hover:text-white hover:bg-slate-800/40"}`}
                                        >
                                            <span className="flex min-w-0 items-center gap-3">
                                                {item.isAlert ? (
                                                    <motion.span
                                                        animate={{ scale: [1, 1.18, 1] }}
                                                        transition={{ duration: 1.6, repeat: Infinity, ease: "easeInOut" }}
                                                        className="text-amber-400 shrink-0 flex"
                                                    >
                                                        <Icon size={16} />
                                                    </motion.span>
                                                ) : (
                                                    <Icon size={16} className={`shrink-0 transition-colors ${isActive || childActive ? "text-cyan-400" : "text-slate-400 group-hover:text-cyan-400"}`} />
                                                )}
                                                <span className={`truncate text-sm font-medium ${item.isAlert ? "text-amber-300" : ""}`}>{item.label}</span>
                                            </span>
                                            {item.badge > 0 && !isExpandable && (
                                                <span className="rounded-full bg-cyan-500 px-1.5 py-0.5 text-[10px] font-bold leading-none tabular-nums text-slate-950">
                                                    {item.badge}
                                                </span>
                                            )}
                                            {item.isAlert && !isExpandable && (
                                                <motion.span
                                                    animate={{ opacity: [0.4, 1, 0.4] }}
                                                    transition={{ duration: 1.6, repeat: Infinity, ease: "easeInOut" }}
                                                    className="w-1.5 h-1.5 rounded-full bg-amber-400 shrink-0"
                                                />
                                            )}
                                        </Link>
                                        {isExpandable && (
                                            <button
                                                type="button"
                                                onClick={(e) => {
                                                    e.preventDefault();
                                                    item.hasWithdraw
                                                        ? state.setBankExpanded(!state.bankExpanded)
                                                        : state.setWorkExpanded(!state.workExpanded);
                                                }}
                                                aria-expanded={expanded}
                                                aria-controls={panelId}
                                                aria-label={`${expanded ? "Hide" : "Show"} ${item.label} shortcuts`}
                                                className="flex h-11 w-11 shrink-0 items-center justify-center text-slate-400 transition-colors hover:text-white"
                                            >
                                                <CaretDown size={14} className={`transition-transform duration-150 ${expanded ? "rotate-180" : ""}`} />
                                            </button>
                                        )}
                                    </div>

                                    {expanded && (
                                        <div id={panelId} className="border-t border-slate-800/40 bg-slate-900/40 py-1">
                                            {item.hasWithdraw && (
                                                <form
                                                    className="flex gap-2 px-4 py-2 pl-11"
                                                    onSubmit={(e) => {
                                                        e.preventDefault();
                                                        state.withdraw(item.withdrawUrl, onNavigate);
                                                    }}
                                                >
                                                    <div className="min-w-0 flex-1">
                                                        <Input
                                                            aria-label="Amount to withdraw"
                                                            placeholder="Amount"
                                                            inputMode="decimal"
                                                            autoComplete="off"
                                                            value={state.bankWithdrawAmount}
                                                            onChange={(e) => state.setBankWithdrawAmount(e.target.value.replace(/[^0-9kmKMbB.]/g, ""))}
                                                        />
                                                    </div>
                                                    <Button
                                                        type="submit"
                                                        variant="secondary"
                                                        loading={state.bankWithdrawBusy}
                                                        disabled={!state.bankWithdrawAmount}
                                                    >
                                                        Withdraw
                                                    </Button>
                                                </form>
                                            )}
                                            {hasQuickWork && (
                                                <button
                                                    type="button"
                                                    onClick={(e) => {
                                                        e.preventDefault();
                                                        e.stopPropagation();
                                                        state.quickWork(onNavigate);
                                                    }}
                                                    disabled={state.quickWorking}
                                                    aria-busy={state.quickWorking || undefined}
                                                    className={`flex min-h-[40px] w-full items-center gap-3 px-4 pl-11 text-left text-xs font-bold uppercase tracking-wider transition-colors ${state.quickWorking ? "text-amber-400 animate-pulse" : "text-slate-400 hover:text-amber-400 hover:bg-slate-800/40"}`}
                                                >
                                                    <span aria-hidden className={`h-1.5 w-1.5 shrink-0 rounded-full ${state.quickWorking ? "bg-amber-400" : "bg-slate-500"}`} />
                                                    Quick Work
                                                </button>
                                            )}
                                            {subLinks.map((s) => {
                                                const SubIcon = s.icon;
                                                const subActive = s.href === activeHref;
                                                return (
                                                    <Link
                                                        key={s.href}
                                                        href={s.href}
                                                        {...prefetchPropsFor(s.href)}
                                                        onClick={onNavigate}
                                                        aria-current={subActive ? "page" : undefined}
                                                        className={subLinkCls(subActive)}
                                                    >
                                                        {SubIcon ? (
                                                            <SubIcon size={12} className="shrink-0" />
                                                        ) : (
                                                            <span aria-hidden className={`h-1.5 w-1.5 shrink-0 rounded-full ${subActive ? "bg-cyan-400" : "bg-slate-500"}`} />
                                                        )}
                                                        {s.label}
                                                    </Link>
                                                );
                                            })}
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ))}
        </nav>
    );
}
