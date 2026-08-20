import { Head, Link } from "@inertiajs/react";
import { motion } from "framer-motion";
import { formatUTC } from "@/Layouts/GameLayoutComponents";

// Type accents — only two types exist server-side now (info, warning).
// `info` is a regular update, `warning` is a hotfix / serious issue.
const TYPE_ACCENT = {
    info:    { rule: "#67e8f9", label: "text-cyan-400",  pill: "border-cyan-500/30 bg-cyan-500/10 text-cyan-300",   tag: "Update"  },
    warning: { rule: "#fbbf24", label: "text-amber-400", pill: "border-amber-500/30 bg-amber-500/10 text-amber-300", tag: "Hotfix"  },
};
const accentFor = (type) => TYPE_ACCENT[type] ?? TYPE_ACCENT.info;

function AnnouncementBody({ message }) {
    const blocks = String(message ?? "")
        .split(/\n{2,}/)
        .map((block) => block.trim())
        .filter(Boolean);

    return (
        <div className="mt-6 space-y-5">
            {blocks.map((block, index) => {
                const lines = block.split("\n").map((line) => line.trim()).filter(Boolean);
                const isList = lines.length > 1 && lines.every((line) => /^[-*]\s+/.test(line));
                const heading = block.match(/^#{2,3}\s+(.+)/);

                if (heading) {
                    return (
                        <h3 key={index} className="pt-2 text-xl font-black tracking-tight text-white">
                            {heading[1]}
                        </h3>
                    );
                }

                if (isList) {
                    return (
                        <ul key={index} className="space-y-2 pl-5 text-sm font-semibold leading-7 text-white/85">
                            {lines.map((line, lineIndex) => (
                                <li key={lineIndex} className="list-disc marker:text-cyan-400">
                                    {line.replace(/^[-*]\s+/, "")}
                                </li>
                            ))}
                        </ul>
                    );
                }

                return (
                    <p key={index} className="text-[15px] font-semibold leading-7 text-white/85">
                        {block}
                    </p>
                );
            })}
        </div>
    );
}

function cleanPageLabel(label) {
    return String(label ?? "")
        .replace("&laquo;", "<")
        .replace("&raquo;", ">");
}

function AnnouncementHeader({ announcement, expired }) {
    const accent = accentFor(announcement.type);
    return (
        <header className="mb-6">
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <span className={`inline-flex items-center rounded-sm border px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.22em] ${accent.pill}`}>
                    {accent.tag}
                </span>
                {expired && (
                    <span className="inline-flex items-center rounded-sm border border-slate-700/60 bg-slate-800/30 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">
                        Archived
                    </span>
                )}
            </div>

            <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between sm:gap-5">
                <h2 className={`min-w-0 text-2xl font-black leading-tight tracking-tight sm:text-3xl ${expired ? "text-white/70" : "text-white"}`}>
                    {announcement.title}
                </h2>
                <time className={`shrink-0 text-[11px] font-black uppercase tracking-[0.22em] ${expired ? "text-slate-500" : accent.label}`}>
                    {announcement.published_at ? formatUTC(announcement.published_at) : "Pending"}
                </time>
            </div>

            <hr
                aria-hidden="true"
                style={{
                    display: "block",
                    width: "100%",
                    height: "3px",
                    margin: "1.1rem 0 0",
                    border: 0,
                    backgroundColor: expired ? "#334155" : accent.rule,
                    opacity: expired ? 0.4 : 1,
                }}
            />
        </header>
    );
}

function Pagination({ links = [] }) {
    const usefulLinks = links.filter((link) => link.url || link.active);
    if (usefulLinks.length <= 1) return null;

    return (
        <nav className="mt-12 flex flex-wrap items-center justify-center gap-2">
            {usefulLinks.map((link, index) => {
                const label = cleanPageLabel(link.label);
                const baseClass = [
                    "min-w-9 rounded border px-3 py-2 text-center text-[11px] font-black uppercase tracking-[0.14em] transition",
                    link.active
                        ? "border-cyan-400/50 bg-cyan-400/10 text-cyan-300"
                        : "border-slate-700/60 text-white/65 hover:border-cyan-400/35 hover:text-white",
                    !link.url && !link.active ? "pointer-events-none opacity-35" : "",
                ].join(" ");

                if (!link.url) {
                    return (
                        <span key={`${label}-${index}`} className={baseClass}>
                            {label}
                        </span>
                    );
                }

                return (
                    <Link
                        key={`${label}-${index}`}
                        href={link.url}
                        preserveScroll
                        replace
                        prefetch
                        className={baseClass}
                    >
                        {label}
                    </Link>
                );
            })}
        </nav>
    );
}

function isExpired(announcement) {
    if (!announcement.expires_at) return false;
    return new Date(announcement.expires_at).getTime() <= Date.now();
}

function Section({ title, items, expired = false }) {
    if (items.length === 0) return null;
    return (
        <section className="space-y-12">
            <div className="flex items-baseline justify-between border-b border-slate-800/60 pb-3">
                <h2 className="text-xs font-black uppercase tracking-[0.32em] text-white">
                    {title}
                </h2>
                <span className="text-[10px] font-black uppercase tracking-[0.22em] text-slate-500">
                    {items.length}
                </span>
            </div>
            {items.map((announcement, index) => (
                <motion.article
                    key={announcement.id}
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: index * 0.035, duration: 0.22, ease: "easeOut" }}
                    className="pb-10 last:pb-0"
                >
                    <AnnouncementHeader announcement={announcement} expired={expired} />
                    <AnnouncementBody message={announcement.message} />
                </motion.article>
            ))}
        </section>
    );
}

export default function Announcements({ announcements = [] }) {
    const page = Array.isArray(announcements)
        ? { data: announcements, links: [] }
        : announcements;
    const rows = page?.data ?? [];

    const current = rows.filter((a) => !isExpired(a));
    const archive = rows.filter((a) => isExpired(a));

    return (
        <>
            <Head title="Announcements - TheDirector" />

            <div className="min-h-screen bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950 text-white">
                <main className="mx-auto w-full max-w-3xl px-5 py-8 sm:py-10">
                    <div className="mb-8">
                        <Link
                            href="/dashboard"
                            className="text-xs font-black uppercase tracking-[0.2em] text-cyan-400 transition hover:text-cyan-300"
                        >
                            Continue to The Director
                        </Link>
                    </div>

                    <section className="mb-12 text-center">
                        <img
                            src="https://images.thedirector.app/Careers/promo.png"
                            alt=""
                            className="mx-auto h-20 w-20 object-contain sm:h-24 sm:w-24"
                        />
                        <h1 className="mt-5 font-['BankGothic'] text-3xl font-black uppercase tracking-[0.08em] text-white sm:text-4xl">
                            Announcements
                        </h1>
                    </section>

                    {rows.length === 0 ? (
                        <section className="py-14 text-center">
                            <p className="text-xs font-black uppercase tracking-[0.28em] text-cyan-400">
                                Nothing on record
                            </p>
                            <p className="mt-2 text-sm font-semibold text-white/65">
                                The board is quiet. Check back later.
                            </p>
                        </section>
                    ) : (
                        <div className="space-y-16">
                            {current.length > 0
                                ? <Section title="Current" items={current} />
                                : (
                                    <section>
                                        <div className="flex items-baseline justify-between border-b border-slate-800/60 pb-3">
                                            <h2 className="text-xs font-black uppercase tracking-[0.32em] text-white">Current</h2>
                                            <span className="text-[10px] font-black uppercase tracking-[0.22em] text-slate-500">0</span>
                                        </div>
                                        <p className="mt-6 text-sm font-semibold text-white/55">No active announcements.</p>
                                    </section>
                                )
                            }

                            <Section title="Archive" items={archive} expired />

                            <Pagination links={page?.links ?? []} />
                        </div>
                    )}
                </main>
            </div>
        </>
    );
}
