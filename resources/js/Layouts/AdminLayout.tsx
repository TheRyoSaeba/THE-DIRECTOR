import { Link, usePage, router } from "@inertiajs/react";
import { route } from "ziggy-js";
import {
    Users,
    Terminal,
    Briefcase,
    Globe,
    Gear,
    Database,
    ArrowLeft,
    SignOut,
    ShieldStar,
    List,
    X,
    ChatTeardrop,
} from "@phosphor-icons/react";
import { useState } from "react";
import { AnimatePresence, motion } from "framer-motion";


const SECTIONS = [
    { id: "users", label: "Users", icon: Users },
    { id: "activity", label: "Activity", icon: Terminal },
    { id: "career", label: "Careers", icon: Briefcase },
    { id: "world", label: "World", icon: Globe },
    { id: "engine", label: "Engine", icon: Gear },
    { id: "wal", label: "Health", icon: Database },
    { id: "forum", label: "Forum", icon: ChatTeardrop },
] as const;

type SectionId = (typeof SECTIONS)[number]["id"];


interface FlashMessage {
    success?: string;
    error?: string;
    warning?: string;
    info?: string;
}

interface AdminPageProps {
    [key: string]: unknown;
    auth: {
        user: { username: string };
        character?: { citySlug?: string } | null;
    };
    flash: FlashMessage;
    section?: string;
}


export default function AdminLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const page = usePage<AdminPageProps>();
    const active = (page.props.section ?? "users") as SectionId;
    const character = page.props.auth?.character;

    const [mobileOpen, setMobileOpen] = useState(false);

    const gameLink = character?.citySlug
        ? `/${character.citySlug}`
        : "/dashboard";

    const logout = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(route("logout"));
    };

    return (
        <div className="min-h-screen bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950 text-white flex flex-col">
            <header className="h-12 border-b border-slate-800/60 flex items-center justify-between px-4 sticky top-0 z-50 bg-slate-950 shrink-0">
                <div className="flex items-center gap-3">
                    <button
                        onClick={() => setMobileOpen((v) => !v)}
                        className="lg:hidden p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800/50 transition"
                    >
                        {mobileOpen ? <X size={18} /> : <List size={18} />}
                    </button>

                    <div className="flex items-center gap-2">
                        <ShieldStar
                            size={16}
                            className="text-amber-400"
                            weight="fill"
                        />
                        <span className="font-bold text-sm tracking-wide">
                            THE <span className="text-amber-400">DIRECTOR</span>
                        </span>
                        <span className="hidden sm:inline px-2 py-0.5 text-[9px] font-black uppercase tracking-widest bg-amber-500/15 border border-amber-500/30 text-amber-400 rounded">
                            Admin
                        </span>
                    </div>
                </div>

                <div className="flex items-center gap-1 sm:gap-2">
                    <Link
                        href={gameLink}
                        className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold text-slate-400 hover:text-white hover:bg-slate-800/50 transition"
                    >
                        <ArrowLeft size={14} weight="bold" />
                        <span className="hidden sm:inline">Back to Game</span>
                    </Link>
                    <form onSubmit={logout}>
                        <button
                            type="submit"
                            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold text-slate-500 hover:text-red-400 hover:bg-red-500/10 transition"
                        >
                            <SignOut size={14} weight="bold" />
                            <span className="hidden sm:inline">Sign Out</span>
                        </button>
                    </form>
                </div>
            </header>

            <AnimatePresence>
                {page.props.flash?.success && (
                    <motion.div
                        initial={{ opacity: 0, y: -20, x: '-50%' }}
                        animate={{ opacity: 1, y: 0, x: '-50%' }}
                        exit={{ opacity: 0, y: -20, x: '-50%' }}
                        className="fixed top-16 left-1/2 z-[100] bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 px-4 py-2 rounded-xl shadow-lg flex items-center gap-2"
                    >
                        <div className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
                        <span className="text-sm font-bold">{page.props.flash.success}</span>
                    </motion.div>
                )}
                {page.props.flash?.error && (
                    <motion.div
                        initial={{ opacity: 0, y: -20, x: '-50%' }}
                        animate={{ opacity: 1, y: 0, x: '-50%' }}
                        exit={{ opacity: 0, y: -20, x: '-50%' }}
                        className="fixed top-16 left-1/2 z-[100] bg-red-500/10 border border-red-500/20 text-red-400 px-4 py-2 rounded-xl shadow-lg flex items-center gap-2"
                    >
                        <div className="w-2 h-2 rounded-full bg-red-400 animate-pulse" />
                        <span className="text-sm font-bold">{page.props.flash.error}</span>
                    </motion.div>
                )}
            </AnimatePresence>

            <div className="flex flex-1 overflow-hidden">
                <AnimatePresence>
                    {mobileOpen && (
                        <div className="lg:hidden fixed inset-0 z-40">
                            <motion.div
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                exit={{ opacity: 0 }}
                                onClick={() => setMobileOpen(false)}
                                className="absolute inset-0 bg-black/70"
                            />
                            <motion.aside
                                initial={{ x: -280 }}
                                animate={{ x: 0 }}
                                exit={{ x: -280 }}
                                transition={{
                                    type: "spring",
                                    damping: 28,
                                    stiffness: 260,
                                }}
                                className="absolute left-0 top-0 bottom-0 w-64 bg-slate-950 border-r border-slate-800/60 flex flex-col pt-4"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <Sidebar
                                    active={active}
                                    onNavigate={() => setMobileOpen(false)}
                                />
                            </motion.aside>
                        </div>
                    )}
                </AnimatePresence>

                <aside className="hidden lg:flex w-56 xl:w-60 border-r border-slate-800/60 flex-col shrink-0">
                    <Sidebar active={active} />
                </aside>

                <main className="flex-1 overflow-y-auto">
                    <div className="max-w-screen-2xl mx-auto p-5 sm:p-7 text-[15px] sm:text-[16px]">
                        {children}
                    </div>
                </main>
            </div>
        </div>
    );
}


function Sidebar({
    active,
    onNavigate,
}: {
    active: SectionId;
    onNavigate?: () => void;
}) {
    return (
        <nav className="flex-1 py-3 px-3 space-y-0.5">
            <div className="px-3 mb-3 text-[9px] font-black uppercase tracking-[0.15em] text-slate-600">
                Sections
            </div>

            {SECTIONS.map(({ id, label, icon: Icon }) => {
                const isActive = active === id;
                return (
                    <Link
                        key={id}
                        href={route("admin.index") + `?section=${id}`}
                        onClick={onNavigate}
                        className={[
                            "group w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold transition-all",
                            isActive
                                ? "bg-amber-500/15 text-amber-300 border border-amber-500/25 shadow-[inset_0_0_12px_rgba(245,158,11,0.05)]"
                                : "text-slate-400 hover:text-white hover:bg-slate-800/50 border border-transparent",
                        ].join(" ")}
                    >
                        <Icon
                            size={16}
                            weight={isActive ? "fill" : "regular"}
                            className={
                                isActive
                                    ? "text-amber-400"
                                    : "text-slate-500 group-hover:text-slate-300"
                            }
                        />
                        {label}

                        {isActive && (
                            <span className="ml-auto w-1.5 h-1.5 rounded-full bg-amber-400 shadow-[0_0_6px_rgba(245,158,11,0.6)]" />
                        )}
                    </Link>
                );
            })}
        </nav>
    );
}
