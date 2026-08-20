export interface User {
    id: number;
    username: string;
    email: string;
    is_banned: boolean;
    is_admin: boolean;
    last_login_at?: string;
    last_ip?: string;
    email_verified_at?: string;
    banned_at?: string;
    ban_reason?: string;
    registration_ip?: string;
    created_at: string;
    character?: {
        id: number;
        display_name: string;
        health: number;
        max_health: number;
        cash_on_hand: number;
        cash_in_bank: number;
        dirty_cash: number;
        career_xp: number;
        rank?: string;
        career?: string;
    };
}

export interface Career {
    id: number;
    name: string;
    code: string;
    earns_count?: number;
    ranks_count?: number;
}

export interface CareerEarn {
    id: number;
    career_id: number;
    code: string;
    title: string;
    min_rank: number;
    min_career_xp: number;
    rng_min: number;
    rng_max: number;
    payout_min: number;
    payout_max: number;
    xp_gain_min: number;
    xp_gain_max: number;
    stat_intelligence_min: number;
    stat_intelligence_max: number;
    stat_offense_min: number;
    stat_offense_max: number;
    stat_defense_min: number;
    stat_defense_max: number;
    stat_luck_min: number;
    stat_luck_max: number;
    stat_influence_min: number;
    stat_influence_max: number;
    success_message: string;
    failure_message: string;
}

export interface GameItem {
    id: number;
    name: string;
    slug: string;
    type: 'weapon' | 'armor' | 'gadget' | 'item' | 'vehicle';
    slot?: string;
    description?: string;
    image_url?: string;
    price: number;
    durability: number;
    offense: number;
    defense: number;
    intelligence: number;
    influence: number;
    luck: number;
    is_active: boolean;
    stock?: number | null;
    max_stock?: number | null;
    data?: Record<string, any> | null;
}

export interface Property {
    id: number;
    name: string;
    price: number;
    image_url?: string;
    vehicle_capacity: number;
    safe_capacity: number;
    has_alarm: boolean;
    influence_bonus_pct: number;
    intelligence_bonus_pct: number;
    offense_bonus_pct: number;
    defense_bonus_pct: number;
}

export interface Business {
    id: number;
    name: string;
    slug: string;
    description?: string;
    base_price?: number;
    is_purchasable: boolean;
    is_active: boolean;
    owner_id?: number | null;
    balance?: number;
    data?: Record<string, any>;
    city?: { id: number; name: string };
}

export interface Announcement {
    id: number;
    title: string;
    message: string;
    type: 'info' | 'warning' | 'success' | 'danger';
    is_active: boolean;
    published_at?: string;
    expires_at?: string | null;
}

export interface CronJob {
    jobid: number;
    jobname: string;
    schedule: string;
    command: string;
    active: boolean;
    database?: string;
}

export interface ActivityLogEntry {
    id: number;
    action: string;
    user_id?: number;
    subject_id?: number;
    subject_type?: string;
    changes?: Record<string, any>;
    ip_address?: string;
    created_at: string;
    user?: { id: number; username: string };
}

export interface LaravelLog {
    timestamp: string;
    level: string;
    message: string;
}

export interface LogFile {
    name: string;
    size: number;
    modified: number;
}

export interface Stats {
    total_users: number;
    active_users: number;
    banned_users: number;
    total_characters: number;
    alive_characters: number;
    total_economy: number;
    dirty_economy: number;
}

export interface ForumCategory {
    id: number;
    name: string;
    slug: string;
    description?: string;
    icon: string;
    sort_order: number;
    is_active: boolean;
    admin_only: boolean;
    posts_count: number;
}

export interface ForumPostAdmin {
    id: number;
    title: string;
    author?: string;
    category_id: number;
    is_pinned: boolean;
    is_locked: boolean;
    votes: number;
    created_at: string;
}

export interface ForumStats {
    totalPosts: number;
    totalCategories: number;
}

export interface WalDiagnostics {
    checked_at: string;
    meta: {
        connected: boolean;
        latency_ms: number | null;
        server_version: string | null;
        connection: string;
        database: string | null;
        driver: string;
    };
    settings: Record<string, string | null>;
    wal: {
        records: number;
        fpi: number;
        bytes: number;
        stats_reset: string | null;
        current_lsn: string | null;
        current_file: string | null;
    } | null;
    database_size: {
        pretty: string | null;
        bytes: number | null;
    };
    connections: {
        total: number | null;
        active: number | null;
        idle: number | null;
        waiting: number | null;
        max: string | null;
    };
    database_stats: {
        cache_hit_ratio: number | null;
        commits: number | null;
        rollbacks: number | null;
        deadlocks: number | null;
        temp_bytes: number | null;
    };
    long_running: {
        pid: number;
        user: string;
        state: string;
        wait: string;
        query: string;
        duration: string;
    }[];
    locks: {
        mode: string;
        granted: boolean;
        count: number;
    }[];
    runtime?: {
        server: string;
        octane: boolean;
        php_version: string;
        sapi: string;
        memory: {
            usage_bytes: number;
            peak_bytes: number;
            limit: string | null;
        };
        opcache: {
            enabled: boolean;
            hit_rate: number | null;
            used_memory: number | null;
            free_memory: number | null;
            jit_enabled: boolean;
        };
        cache: {
            default_store: string | null;
            limiter_store: string | null;
            redis_client: string | null;
            session_driver: string | null;
        };
        cloud_run: {
            service: string | null;
            revision: string | null;
            configuration: string | null;
        };
    };
    logs?: LaravelLog[];
    notes: string[];
}
