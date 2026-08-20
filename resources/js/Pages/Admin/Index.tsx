import { usePage } from "@inertiajs/react";
import { motion, AnimatePresence } from "framer-motion";
import AdminLayout from "@/Layouts/AdminLayout";

import {
    UsersSection,
    ActivitySection,
    CareerSection,
    WorldSection,
    EngineSection,
    WalSection,
    ForumSection,
} from "./Sections";

import type {
    User,
    Career,
    CareerEarn,
    GameItem,
    Property,
    Business,
    Announcement,
    CronJob,
    ActivityLogEntry,
    LaravelLog,
    LogFile,
    Stats,
    WalDiagnostics,
    ForumCategory,
    ForumPostAdmin,
    ForumStats,
} from "./Sections";


type SectionId = "users" | "activity" | "career" | "world" | "engine" | "wal" | "forum";

interface PageProps {
    section: SectionId;

    users?: { data: User[]; links: any[]; current_page: number; total: number };
    filters?: { search: string; filter: string };

    logs?: { data: ActivityLogEntry[]; links: any[] };
    stats?: Stats;
    laravel_logs?: LaravelLog[];
    log_files?: LogFile[];
    current_log?: string;

    careers?: Career[];
    selectedCareer?: Career;
    earns?: CareerEarn[];
    ranks?: any[];
    items?: GameItem[];

    cities?: any[];
    properties?: Property[];
    businesses?: Business[];

    isInstalled?: boolean;
    jobs?: CronJob[];
    announcements?: { data: Announcement[]; links: any[] };

    wal?: WalDiagnostics;

    forumCategories?: ForumCategory[];
    forumPosts?: ForumPostAdmin[];
    forumStats?: ForumStats;
    [key: string]: any;
}


export default function Admin() {
    const props = usePage<PageProps>().props;
    const section = props.section ?? "users";

    return (
        <AnimatePresence mode="wait">
            <motion.div
                key={section}
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: -6 }}
                transition={{ duration: 0.15 }}
            >
                {section === "users" && props.users && (
                    <UsersSection
                        users={props.users}
                        filters={props.filters ?? { search: "", filter: "all" }}
                    />
                )}

                {section === "activity" && props.stats && props.logs && (
                    <ActivitySection
                        logs={props.logs}
                        stats={props.stats}
                        laravel_logs={props.laravel_logs ?? []}
                        log_files={props.log_files ?? []}
                        current_log={props.current_log ?? "laravel"}
                    />
                )}

                {section === "career" && props.careers && props.items && (
                    <CareerSection
                        careers={props.careers}
                        selectedCareer={props.selectedCareer}
                        earns={props.earns}
                        items={props.items}
                    />
                )}

                {section === "world" &&
                    props.properties &&
                    props.businesses && (
                        <WorldSection
                            properties={props.properties}
                            businesses={props.businesses}
                        />
                    )}

                {section === "engine" &&
                    props.jobs !== undefined &&
                    props.announcements && (
                        <EngineSection
                            isInstalled={props.isInstalled ?? false}
                            jobs={props.jobs ?? []}
                            announcements={props.announcements}
                        />
                    )}

                {section === "wal" && props.wal && <WalSection wal={props.wal} />}

                {section === "forum" && props.forumCategories && props.forumStats && (
                    <ForumSection
                        categories={props.forumCategories}
                        posts={props.forumPosts ?? []}
                        stats={props.forumStats}
                    />
                )}
            </motion.div>
        </AnimatePresence>
    );
}

Admin.layout = (page: React.ReactNode) => <AdminLayout>{page}</AdminLayout>;
