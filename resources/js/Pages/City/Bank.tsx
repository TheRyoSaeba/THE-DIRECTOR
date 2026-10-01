import { useState, useEffect, FormEvent } from "react";
import { router, Head } from "@inertiajs/react";
// @ts-ignore
import { route } from "ziggy-js";
import {
    Bank as BankIcon, HandCoins, Lock, ArrowUp, ArrowDown, Warning,
    User, Buildings, Crown, Shuffle, PaperPlaneRight, ClockCounterClockwise,
    MagnifyingGlass, Scroll, CaretLeft, CaretRight, Gear, CheckCircle, X,
    ChartBar, TrendUp,
} from "@phosphor-icons/react";
import { motion, AnimatePresence } from "framer-motion";
import GameLayout from "@/Layouts/GameLayout";
import { formatUTC, parseSymbolicAmount } from "@/Layouts/GameLayoutComponents";
import Picker from "@/Components/picker";
import { getCityImage } from "@/utils/cityImages";



type TxType = "deposit" | "withdraw" | "transfer_sent" | "transfer_received";

interface Transaction {
    id: number;
    type: TxType;
    amount: number;
    fee: number;
    counterparty: string | null;
    note: string | null;
    balance_after: number;
    created_at: string;
}

interface BankManager {
    id: number;
    name: string;
    rank: string | null;
    since: string | null;
    avatar_url: string | null;
}

interface BankOwner {
    name: string;
    avatar_url: string | null;
}

interface ActiveCertificate {
    id: number;
    principal: number;
    rate: number;
    interest_owed: number;
    matures_at: string;
}

interface PastCertificate {
    id: number;
    principal: number;
    rate: number;
    interest_owed: number;
    settled_at: string;
    outcome: "matured" | "defaulted";
}

interface BankData {
    balance: number;
    cash_on_hand: number;
    city_name: string;
    city_slug: string;
    bank_image: string | null;
    home_city_slug: string | null;
    is_home_city: boolean;
    loan_interest: number;
    wire_fee: number;
    my_wire_fee: number;
    is_purchasable: boolean;
    is_owner: boolean;
    is_manager: boolean;
    bank_manager: BankManager | null;
    owner: BankOwner | null;
    active_certificate: ActiveCertificate | null;
    past_certificates: PastCertificate[];
}

type ActiveTab = "account" | "transfer" | "history" | "certificates" | "ledger";

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────

const fmt$ = (n: number) => `$${Math.round(n).toLocaleString()}`;

const calcFee = (amount: number, rate: number) =>
    rate <= 0 || amount <= 0 ? 0 : Math.max(1, Math.round(amount * (rate / 100)));

function useCountdown(iso: string | null): [number, number, number] {
    const calc = (): [number, number, number] => {
        if (!iso) return [0, 0, 0];
        const diff = Math.max(0, Math.floor((new Date(iso).getTime() - Date.now()) / 1000));
        return [Math.floor(diff / 3600), Math.floor((diff % 3600) / 60), diff % 60];
    };
    const [t, setT] = useState<[number, number, number]>(calc);
    useEffect(() => {
        if (!iso) return;
        const id = setInterval(() => setT(calc()), 1000);
        return () => clearInterval(id);
    }, [iso]);
    return t;
}

const TX_FILTER_OPTIONS = [
    { value: "all", label: "All activity" },
    { value: "deposit", label: "Deposits" },
    { value: "withdraw", label: "Withdrawals" },
    { value: "transfer_sent", label: "Outgoing transfers" },
    { value: "transfer_received", label: "Incoming transfers" },
];

const TX_META: Record<TxType, { label: string; sign: "+" | "-"; color: string }> = {
    deposit: { label: "Deposit", sign: "+", color: "text-emerald-400" },
    withdraw: { label: "Withdrawal", sign: "-", color: "text-red-400" },
    transfer_sent: { label: "Transfer Out", sign: "-", color: "text-red-400" },
    transfer_received: { label: "Transfer In", sign: "+", color: "text-emerald-400" },
};

// ─────────────────────────────────────────────────────────────
// Transaction row
// ─────────────────────────────────────────────────────────────

const TX_PER_PAGE = 8;

function TxRow({ tx }: { tx: Transaction }) {
    const m = TX_META[tx.type];
    return (
        <div className="flex items-center gap-4 px-6 py-3.5 border-b border-white/[0.03] hover:bg-white/[0.025] transition-colors last:border-0">
            <div className={`w-7 h-7 rounded-full flex items-center justify-center shrink-0 ${m.sign === "+" ? "bg-emerald-500/10" : "bg-red-500/10"
                }`}>
                {m.sign === "+"
                    ? <TrendUp size={12} className="text-emerald-400" weight="bold" />
                    : <ArrowUp size={12} className="text-red-400" weight="bold" />}
            </div>
            <div className="flex-1 min-w-0">
                <div className="flex items-baseline gap-2">
                    <span className="text-sm text-slate-200 font-medium">
                        {tx.note ?? m.label}
                    </span>
                    {tx.counterparty && (
                        <span className="text-xs text-slate-500">
                            {tx.type === "transfer_sent" ? "to" : "from"} {tx.counterparty}
                        </span>
                    )}
                </div>
                <div className="text-xs text-slate-600 font-mono mt-0.5">{formatUTC(tx.created_at)}</div>
            </div>
            <div className="text-right shrink-0">
                <div className={`text-sm font-semibold tabular-nums ${m.color}`}>
                    {m.sign}{fmt$(tx.amount)}
                </div>
                {(tx.fee ?? 0) > 0 && (
                    <div className="text-xs text-amber-500/50 font-mono tabular-nums">fee {fmt$(tx.fee)}</div>
                )}
                <div className="text-xs text-slate-600 font-mono tabular-nums mt-0.5">{fmt$(tx.balance_after)}</div>
            </div>
        </div>
    );
}

function TxPager({ page, total, totalPages, onChange }: {
    page: number; total: number; totalPages: number; onChange: (p: number) => void;
}) {
    return (
        <div className="flex items-center justify-between px-6 py-3 border-t border-white/[0.06] bg-slate-950/60 shrink-0">
            <span className="text-xs text-slate-500">{total} transactions</span>
            <div className="flex items-center gap-1">
                <button onClick={() => onChange(page - 1)} disabled={page === 1}
                    className="px-3 py-1 text-xs text-slate-500 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed transition-colors">
                    Prev
                </button>
                <span className="text-xs text-slate-500 font-mono tabular-nums w-16 text-center">{page} / {totalPages}</span>
                <button onClick={() => onChange(page + 1)} disabled={page === totalPages}
                    className="px-3 py-1 text-xs text-slate-500 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed transition-colors">
                    Next
                </button>
            </div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────
// Account tab — deposit / withdraw
// ─────────────────────────────────────────────────────────────

function AccountPanel({ bankData }: { bankData: BankData }) {
    const [mode, setMode] = useState<"deposit" | "withdraw">("deposit");
    const [amount, setAmount] = useState("");
    const [busy, setBusy] = useState(false);

    const parsed = parseSymbolicAmount(amount);
    const available = mode === "deposit" ? bankData.cash_on_hand : bankData.balance;
    const isValid = parsed > 0 && parsed <= available;

    const submit = () => {
        if (!isValid || busy) return;
        setBusy(true);
        router.post(
            route(`city.bank.${mode}`, { city: bankData.city_slug }),
            { amount: parsed },
            { preserveScroll: true, onSuccess: () => setAmount(""), onFinish: () => setBusy(false) }
        );
    };

    return (
        <div className="flex flex-col lg:flex-row flex-1 min-h-0 divide-y lg:divide-y-0 lg:divide-x divide-white/[0.04] overflow-hidden">

            {/* Left — balance summary */}
            <div className="flex-1 flex flex-col p-5 gap-5 overflow-y-auto">
                <div>
                    <p className="text-xs text-slate-500 uppercase tracking-widest mb-1">Account Balance</p>
                    <p className="text-4xl font-light text-white tabular-nums">{fmt$(bankData.balance)}</p>
                </div>

                <div className="border border-white/[0.05] rounded-xl divide-y divide-white/[0.04] overflow-hidden">
                    <div className="px-4 py-3 flex justify-between items-center">
                        <span className="text-xs text-slate-500 uppercase tracking-widest">Cash on Hand</span>
                        <span className="text-sm text-slate-300 tabular-nums font-semibold">{fmt$(bankData.cash_on_hand)}</span>
                    </div>
                    <div className="px-4 py-3 flex justify-between items-center">
                        <span className="text-xs text-slate-500 uppercase tracking-widest">Wire Fee</span>
                        <span className="text-sm text-slate-300 tabular-nums font-semibold">
                            <span className="text-sm text-emerald-400 tabular-nums font-semibold">{bankData.wire_fee.toFixed(1)}%</span>
                        </span>
                    </div>
                    <div className="px-4 py-3 flex justify-between items-center">
                        <span className="text-xs text-slate-500 uppercase tracking-widest">Home City Wire Fee</span>
                        <span className="text-sm text-slate-300 tabular-nums font-semibold">
                            <span className="text-sm text-emerald-400 tabular-nums font-semibold">{bankData.my_wire_fee > 0 ? `${bankData.my_wire_fee}%` : "None"}</span>
                        </span>
                    </div>

                </div>

                {/* Active CD callout */}
                {bankData.active_certificate && (
                    <div className="flex items-center gap-3 px-4 py-3 bg-cyan-950/30 border border-cyan-800/30 rounded-xl">
                        <Scroll size={14} className="text-cyan-400 shrink-0" weight="fill" />
                        <span className="text-xs text-cyan-300">
                            Certificate active — {fmt$(bankData.active_certificate.principal)} locked
                        </span>
                    </div>
                )}
            </div>

            {/* Right — action */}
            <div className="w-full lg:w-72 shrink-0 flex flex-col p-5 gap-4 bg-slate-950/20">
                <p className="text-xs font-black text-slate-500 uppercase tracking-[0.2em]">Move Funds</p>

                {/* Mode toggle */}
                <div className="grid grid-cols-2 gap-2">
                    {(["deposit", "withdraw"] as const).map(m => (
                        <motion.button key={m} whileTap={{ scale: 0.97 }}
                            onClick={() => { setMode(m); setAmount(""); }}
                            className={`flex items-center justify-center gap-1.5 py-3 rounded-xl text-xs font-black uppercase tracking-widest border transition-all ${mode === m
                                ? m === "deposit"
                                    ? "bg-emerald-500/15 border-emerald-500/40 text-emerald-400"
                                    : "bg-red-500/15 border-red-500/40 text-red-400"
                                : "border-white/[0.06] text-slate-600 hover:border-white/10 hover:text-slate-400"
                                }`}>
                            {m === "deposit" ? <ArrowDown size={12} weight="bold" /> : <ArrowUp size={12} weight="bold" />}
                            {m === "deposit" ? "Deposit" : "Withdraw"}
                        </motion.button>
                    ))}
                </div>

                {/* Amount */}
                <div>
                    <div className="flex justify-between items-baseline mb-2">
                        <label className="text-xs text-slate-500 uppercase tracking-widest">Amount</label>
                        <button type="button" onClick={() => setAmount(String(available))}
                            className="text-xs text-slate-600 hover:text-amber-400 uppercase tracking-widest transition-colors">
                            MAX
                        </button>
                    </div>
                    <input type="text" value={amount}
                        onChange={e => setAmount(e.target.value.replace(/[^0-9kmKMbB.]/g, ""))}
                        placeholder="0" disabled={busy}
                        className={`w-full px-4 py-3 bg-slate-950/70 border rounded-xl text-white font-mono text-base placeholder:text-slate-700 focus:outline-none transition-colors disabled:opacity-50 ${parsed > available && parsed > 0
                            ? "border-red-500/40 focus:border-red-500/60"
                            : "border-white/[0.07] focus:border-emerald-500/30"
                            }`} />
                    {parsed > available && parsed > 0 && (
                        <p className="text-xs text-red-400 mt-1.5 flex items-center gap-1">
                            <Warning size={10} weight="fill" />
                            {mode === "deposit" ? "Exceeds cash on hand" : "Exceeds account balance"}
                        </p>
                    )}
                </div>

                <motion.button whileTap={isValid ? { scale: 0.97 } : {}}
                    onClick={submit} disabled={!isValid || busy}
                    className={`w-full py-3 rounded-xl text-xs font-black uppercase tracking-widest flex items-center justify-center gap-2 transition-all mt-auto ${isValid && !busy
                        ? mode === "deposit"
                            ? "bg-emerald-700 hover:bg-emerald-600 text-white shadow-[0_0_20px_rgba(52,211,153,0.15)]"
                            : "bg-red-800 hover:bg-red-700 text-white shadow-[0_0_20px_rgba(239,68,68,0.15)]"
                        : "bg-slate-800/60 text-slate-600 cursor-not-allowed"
                        }`}>
                    {busy
                        ? <><div className="w-3 h-3 border border-white/30 border-t-white rounded-full animate-spin" />Working…</>
                        : <>{mode === "deposit" ? <ArrowDown size={12} weight="bold" /> : <ArrowUp size={12} weight="bold" />}
                            {mode === "deposit" ? "Deposit" : "Withdraw"} {parsed > 0 ? fmt$(parsed) : "Funds"}</>
                    }
                </motion.button>
            </div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────
// Transfer tab
// ─────────────────────────────────────────────────────────────

function TransferPanel({ bankData }: { bankData: BankData }) {
    const [recipient, setRecipient] = useState("");
    const [amount, setAmount] = useState("");
    const [note, setNote] = useState("");
    const [processing, setProcessing] = useState(false);

    const parsed = parseSymbolicAmount(amount);
    const fee = calcFee(parsed, bankData.my_wire_fee ?? 0);
    const total = parsed + fee;
    const isValid = parsed > 0 && !!recipient.trim() && parsed <= bankData.balance;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!isValid || processing) return;
        setProcessing(true);
        router.post(
            route("city.bank.transfer", { city: bankData.city_slug }),
            { amount: parsed, recipient: recipient.trim(), note: note.trim() },
            {
                preserveScroll: true,
                onSuccess: () => { setAmount(""); setRecipient(""); setNote(""); },
                onFinish: () => setProcessing(false),
            }
        );
    };

    return (
        <div className="flex flex-col lg:flex-row flex-1 min-h-0 divide-y lg:divide-y-0 lg:divide-x divide-white/[0.04] overflow-hidden">

            {/* Left — info */}
            <div className="flex-1 flex flex-col p-5 gap-5 overflow-y-auto">
                <div>
                    <p className="text-[9px] text-slate-600 uppercase tracking-widest mb-1">Bank Transfer</p>
                    <h3 className="text-xl font-light text-white tracking-tight">Wire Transfer</h3>
                    <p className="text-sm text-slate-400 mt-2 leading-relaxed">
                        Send funds directly to any account holder. Funds transferred between home cities incur a wire fee from your home bank.
                    </p>
                </div>

                <div className="border border-white/[0.05] rounded-xl divide-y divide-white/[0.04] overflow-hidden">
                    <div className="px-4 py-3 flex justify-between items-center">
                        <span className="text-xs text-slate-500 uppercase tracking-widest">Available balance</span>
                        <span className="text-sm text-white tabular-nums font-semibold">{fmt$(bankData.balance)}</span>
                    </div>
                    <div className="px-4 py-3 flex justify-between items-center">
                        <span className="text-xs text-slate-500 uppercase tracking-widest">Home City Wire Fee</span>
                        <span className="text-sm text-slate-300 tabular-nums">
                            {bankData.my_wire_fee > 0 ? `${bankData.my_wire_fee}%` : "None"}
                        </span>
                    </div>
                </div>




            </div>

            {/* Right — form */}
            <div className="w-full lg:w-72 shrink-0 flex flex-col p-5 gap-4 bg-slate-950/20">
                <p className="text-xs font-black text-slate-500 uppercase tracking-[0.2em]">New Transfer</p>

                <form onSubmit={submit} className="flex flex-col gap-4 flex-1">
                    <div>
                        <label className="text-[10px] text-slate-500 uppercase tracking-widest block mb-1.5">Recipient</label>
                        <input type="text" value={recipient} onChange={e => setRecipient(e.target.value)}
                            placeholder="Display name…" disabled={processing}
                            className="w-full px-4 py-3 bg-slate-950/70 border border-white/[0.07] rounded-xl text-white text-sm placeholder:text-slate-700 focus:outline-none focus:border-emerald-500/30 transition-colors disabled:opacity-50" />
                    </div>

                    <div>
                        <div className="flex justify-between items-baseline mb-1.5">
                            <label className="text-[10px] text-slate-500 uppercase tracking-widest">Amount</label>
                            <button type="button" onClick={() => setAmount(String(bankData.balance))}
                                className="text-xs text-slate-600 hover:text-amber-400 uppercase tracking-widest transition-colors">
                                MAX
                            </button>
                        </div>
                        <input type="text" value={amount}
                            onChange={e => setAmount(e.target.value.replace(/[^0-9kmKMbB.]/g, ""))}
                            placeholder="0" disabled={processing}
                            className="w-full px-4 py-3 bg-slate-950/70 border border-white/[0.07] rounded-xl text-white font-mono text-base placeholder:text-slate-700 focus:outline-none focus:border-emerald-500/30 transition-colors disabled:opacity-50" />
                    </div>

                    <div>
                        <div className="flex justify-between items-baseline mb-1.5">
                            <label className="text-[10px] text-slate-500 uppercase tracking-widest">Memo</label>
                            <span className="text-[9px] text-slate-600 font-mono tabular-nums">{note.length}/20</span>
                        </div>
                        <input type="text" value={note} onChange={e => setNote(e.target.value.slice(0, 20))}
                            placeholder="Optional…" disabled={processing}
                            className="w-full px-4 py-3 bg-slate-950/70 border border-white/[0.07] rounded-xl text-white text-sm placeholder:text-slate-700 focus:outline-none focus:border-emerald-500/30 transition-colors disabled:opacity-50" />
                    </div>

                    <motion.button type="submit" whileTap={isValid ? { scale: 0.97 } : {}}
                        disabled={!isValid || processing}
                        className={`w-full py-3 rounded-xl text-xs font-black uppercase tracking-widest flex items-center justify-center gap-2 transition-all mt-auto ${isValid && !processing
                            ? "bg-emerald-700 hover:bg-emerald-600 text-white shadow-[0_0_20px_rgba(52,211,153,0.15)]"
                            : "bg-slate-800/60 text-slate-600 cursor-not-allowed"
                            }`}>
                        {processing
                            ? <><div className="w-3 h-3 border border-white/30 border-t-white rounded-full animate-spin" />Sending…</>
                            : <><Shuffle size={12} weight="bold" />Send {parsed > 0 ? fmt$(parsed) : "Funds"}</>
                        }
                    </motion.button>
                </form>
            </div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────
// History tab
// ─────────────────────────────────────────────────────────────

function HistoryPanel({ transactions, loading = false }: { transactions?: Transaction[]; loading?: boolean }) {
    const [typeFilter, setTypeFilter] = useState("all");
    const [page, setPage] = useState(1);

    const rows = transactions ?? [];
    const filtered = typeFilter === "all" ? rows : rows.filter(tx => tx.type === typeFilter);
    const totalPages = Math.max(1, Math.ceil(filtered.length / TX_PER_PAGE));
    const safePage = Math.min(page, totalPages);
    const visible = filtered.slice((safePage - 1) * TX_PER_PAGE, safePage * TX_PER_PAGE);

    return (
        <div className="flex flex-col h-full">
            {/* Filter bar */}
            <div className="flex items-center justify-between px-6 py-3 border-b border-white/[0.06] bg-slate-950/60 shrink-0 gap-4">
                <span className="text-xs font-black text-slate-500 uppercase tracking-widest">Statement</span>
                <div className="w-52">
                    <Picker options={TX_FILTER_OPTIONS} value={typeFilter}
                        onChange={(v: string) => { setTypeFilter(v); setPage(1); }} placeholder="All activity" />
                </div>
            </div>

            {filtered.length === 0 ? (
                <div className="flex flex-col items-center justify-center flex-1 py-16 text-slate-700 gap-3">
                    <ChartBar size={28} />
                    <p className="text-xs uppercase tracking-widest">
                        {loading ? "Loading transaction history..." : rows.length === 0 ? "No transactions yet" : "No matching transactions"}
                    </p>
                </div>
            ) : (
                <>
                    <div className="flex-1 overflow-y-auto">
                        {visible.map(tx => <TxRow key={tx.id} tx={tx} />)}
                    </div>
                    <TxPager page={safePage} totalPages={totalPages} total={filtered.length} onChange={setPage} />
                </>
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────
// Certificates tab
// ─────────────────────────────────────────────────────────────

function CdCountdown({ maturesAt }: { maturesAt: string }) {
    const [h, m, s] = useCountdown(maturesAt);
    const total = Math.max(0, Math.floor((new Date(maturesAt).getTime() - Date.now()) / 1000));
    const pct = Math.max(0, Math.min(100, 100 - (total / (24 * 3600)) * 100));
    const pad = (n: number) => String(n).padStart(2, "0");
    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between text-sm text-slate-400">
                <span className="text-xs text-slate-500 uppercase tracking-widest">Time remaining</span>
                <span className="font-mono font-semibold text-white tabular-nums">{pad(h)}:{pad(m)}:{pad(s)}</span>
            </div>
            <div className="h-1.5 bg-slate-800 rounded-full overflow-hidden">
                <div className="h-full bg-gradient-to-r from-cyan-700 to-cyan-400 rounded-full transition-all duration-1000"
                    style={{ width: `${pct}%` }} />
            </div>
            <div className="flex justify-between text-[10px] text-slate-600">
                <span>Opened</span>
                <span>{Math.round(pct)}% elapsed</span>
                <span>Maturity</span>
            </div>
        </div>
    );
}

function CertificatesPanel({ bankData }: { bankData: BankData }) {
    const [amount, setAmount] = useState("");
    const [submitting, setSubmitting] = useState(false);

    const isHomeCity = bankData.is_home_city;
    const rate = bankData.loan_interest;
    const rateBp = Math.round(rate * 100);
    const parsed = parseSymbolicAmount(amount);
    const interestPreview = parsed > 0 ? Math.floor(parsed * rateBp / 10000) : 0;
    const payoutPreview = parsed + interestPreview;
    const belowMin = parsed > 0 && parsed < 1_000;
    const exceedsVault = parsed > 0 && parsed > bankData.balance;
    const canSubmit = isHomeCity && parsed >= 1_000 && !belowMin && !exceedsVault && !submitting && !bankData.active_certificate;

    const submit = () => {
        if (!canSubmit) return;
        setSubmitting(true);
        router.post(
            route("city.bank.certificates.purchase", { city: bankData.city_slug }),
            { amount: parsed },
            { preserveScroll: true, onSuccess: () => setAmount(""), onFinish: () => setSubmitting(false) }
        );
    };

    return (
        <div className="flex flex-col lg:flex-row flex-1 min-h-0 divide-y lg:divide-y-0 lg:divide-x divide-white/[0.04] overflow-hidden">

            {/* Left — active CD or terms */}
            <div className="flex-1 flex flex-col p-5 gap-5 overflow-y-auto">
                {bankData.active_certificate ? (
                    <>
                        <div>
                            <p className="text-[9px] text-slate-600 uppercase tracking-widest mb-1">Active Position</p>
                            <h3 className="text-xl font-light text-white tracking-tight">Certificate of Deposit</h3>
                        </div>

                        <div className="border border-cyan-800/30 rounded-xl overflow-hidden bg-cyan-950/10">
                            <div className="flex items-center justify-between px-5 py-3.5 border-b border-white/[0.04]">
                                <div className="flex items-center gap-2">
                                    <Scroll size={14} weight="fill" className="text-cyan-400" />
                                    <span className="text-xs font-black text-cyan-400 uppercase tracking-widest">Certificate Active</span>
                                </div>
                                <span className="flex items-center gap-1.5 text-[10px] font-bold text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-2.5 py-1 rounded-full">
                                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
                                    Live
                                </span>
                            </div>

                            <div className="grid grid-cols-3 divide-x divide-white/[0.04] border-b border-white/[0.04]">
                                {[
                                    { label: "Principal", value: fmt$(bankData.active_certificate.principal) },
                                    { label: "Interest", value: `+${fmt$(bankData.active_certificate.interest_owed)}` },
                                    { label: "Total Payout", value: fmt$(bankData.active_certificate.principal + bankData.active_certificate.interest_owed) },
                                ].map(s => (
                                    <div key={s.label} className="px-4 py-3.5 text-center">
                                        <div className="text-[9px] text-slate-600 uppercase tracking-widest mb-1">{s.label}</div>
                                        <div className="text-sm font-semibold text-white tabular-nums">{s.value}</div>
                                    </div>
                                ))}
                            </div>

                            <div className="px-5 py-4">
                                <CdCountdown maturesAt={bankData.active_certificate.matures_at} />
                            </div>
                        </div>
                    </>
                ) : (
                    <>
                        <div>
                            <p className="text-[9px] text-slate-600 uppercase tracking-widest mb-1">Fixed-Term Savings</p>
                            <h3 className="text-xl font-light text-white tracking-tight">Certificate of Deposit</h3>
                            <p className="text-sm text-slate-400 mt-2 leading-relaxed">
                                Lock funds for 24 hours and earn  interest. Principal is irrevocable until maturity.
                            </p>
                        </div>

                        <div className="border border-white/[0.05] rounded-xl divide-y divide-white/[0.04] overflow-hidden">
                            {[
                                { label: "Interest Rate", value: `${rate.toFixed(2)}%`, color: "text-emerald-400" },
                                { label: "Term", value: "24 hours", color: "text-slate-300" },
                                { label: "Min. Deposit", value: "$1,000", color: "text-slate-300" },
                                { label: "Early Withdrawal", value: "Not permitted", color: "text-red-400/70" },
                            ].map(r => (
                                <div key={r.label} className="px-4 py-3 flex justify-between items-center">
                                    <span className="text-xs text-slate-500 uppercase tracking-widest">{r.label}</span>
                                    <span className={`text-sm tabular-nums font-semibold ${r.color}`}>{r.value}</span>
                                </div>
                            ))}
                        </div>

                        {/* Payout preview */}
                        {parsed >= 1_000 && !belowMin && !exceedsVault && (
                            <motion.div initial={{ opacity: 0, y: 4 }} animate={{ opacity: 1, y: 0 }}
                                className="border border-white/[0.05] rounded-xl overflow-hidden">
                                <div className="px-4 py-2.5 bg-slate-950/40 border-b border-white/[0.04]">
                                    <p className="text-[9px] text-slate-500 uppercase tracking-widest">Payout preview · {fmt$(parsed)} locked</p>
                                </div>
                                <div className="grid grid-cols-3 divide-x divide-white/[0.04]">
                                    {[
                                        { label: "Principal", value: fmt$(parsed), color: "text-slate-300" },
                                        { label: "Interest", value: `+${fmt$(interestPreview)}`, color: "text-emerald-400" },
                                        { label: "Payout", value: fmt$(payoutPreview), color: "text-white" },
                                    ].map(r => (
                                        <div key={r.label} className="px-4 py-3 text-center">
                                            <div className="text-[9px] text-slate-600 uppercase tracking-widest mb-1">{r.label}</div>
                                            <div className={`text-sm font-semibold tabular-nums ${r.color}`}>{r.value}</div>
                                        </div>
                                    ))}
                                </div>
                            </motion.div>
                        )}
                    </>
                )}

                {/* CD history */}
                {bankData.past_certificates?.length > 0 && (
                    <div className="border border-white/[0.05] rounded-xl overflow-hidden">
                        <div className="px-4 py-2.5 border-b border-white/[0.04] bg-slate-950/40">
                            <p className="text-[9px] text-slate-500 uppercase tracking-widest">CD History</p>
                        </div>
                        <div className="divide-y divide-white/[0.03]">
                            {bankData.past_certificates.map(cd => (
                                <div key={cd.id} className="flex items-center gap-4 px-4 py-3">
                                    <div className={`w-6 h-6 rounded-full flex items-center justify-center shrink-0 ${cd.outcome === "matured" ? "bg-emerald-500/10" : "bg-red-500/10"
                                        }`}>
                                        {cd.outcome === "matured"
                                            ? <CheckCircle size={12} className="text-emerald-400" weight="fill" />
                                            : <Warning size={12} className="text-red-400" weight="fill" />}
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <span className="text-sm text-slate-300 font-medium">{fmt$(cd.principal)}</span>
                                        <div className="text-xs text-slate-600 font-mono">{formatUTC(cd.settled_at)}</div>
                                    </div>
                                    <div className="text-right">
                                        <div className={`text-sm font-semibold tabular-nums ${cd.outcome === "matured" ? "text-emerald-400" : "text-red-400"}`}>
                                            {cd.outcome === "matured" ? `+${fmt$(cd.interest_owed)}` : "Defaulted"}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Right — purchase form (only at home city, and only when no active CD) */}
            {isHomeCity && !bankData.active_certificate && (
                <div className="w-full lg:w-72 shrink-0 flex flex-col p-5 gap-4 bg-slate-950/20">
                    <p className="text-xs font-black text-slate-500 uppercase tracking-[0.2em]">Open Certificate</p>

                    <div>
                        <div className="flex justify-between items-baseline mb-1.5">
                            <label className="text-[10px] text-slate-500 uppercase tracking-widest">Deposit Amount</label>
                            <button type="button" onClick={() => setAmount(String(bankData.balance))}
                                className="text-xs text-slate-600 hover:text-amber-400 uppercase tracking-widest transition-colors">
                                MAX
                            </button>
                        </div>
                        <input type="text" value={amount}
                            onChange={e => setAmount(e.target.value.replace(/[^0-9kmKMbB.]/g, ""))}
                            placeholder="0" disabled={submitting}
                            className={`w-full px-4 py-3 bg-slate-950/70 border rounded-xl text-white font-mono text-base placeholder:text-slate-700 focus:outline-none transition-colors disabled:opacity-50 ${belowMin || exceedsVault
                                ? "border-red-500/40 focus:border-red-500/60"
                                : "border-white/[0.07] focus:border-emerald-500/30"
                                }`} />
                        {(belowMin || exceedsVault) && (
                            <p className="text-xs text-red-400 mt-1.5 flex items-center gap-1">
                                <Warning size={10} weight="fill" />
                                {belowMin ? "Minimum $1,000" : "Exceeds account balance"}
                            </p>
                        )}
                    </div>

                    <div className="flex items-start gap-2 text-[10px] text-slate-600">
                        <Lock size={11} className="mt-0.5 shrink-0" weight="bold" />
                        <span>Funds are locked for the full 24-hour term. Payout is automatic at maturity.</span>
                    </div>

                    <motion.button whileTap={canSubmit ? { scale: 0.97 } : {}}
                        onClick={submit} disabled={!canSubmit}
                        className={`w-full py-3 rounded-xl text-xs font-black uppercase tracking-widest flex items-center justify-center gap-2 transition-all mt-auto ${canSubmit
                            ? "bg-emerald-700 hover:bg-emerald-600 text-white shadow-[0_0_20px_rgba(52,211,153,0.15)]"
                            : "bg-slate-800/60 text-slate-600 cursor-not-allowed"
                            }`}>
                        {submitting
                            ? <><div className="w-3 h-3 border border-white/30 border-t-white rounded-full animate-spin" />Processing…</>
                            : <><Lock size={12} weight="bold" />{parsed >= 1_000 ? `Lock ${fmt$(parsed)}` : "Lock Funds"}</>
                        }
                    </motion.button>
                </div>
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────
// Ledger tab (manager only)
// ─────────────────────────────────────────────────────────────

interface Resident { id: number; name: string; }
interface LedgerChar { id: number; name: string; avatar_url: string | null; bank_balance: number; transactions: Transaction[]; }

// Minimal JSON GET (replaces window.axios): rejects on non-2xx with the parsed body
// attached as `response.data`, so callers can keep reading `e.response.data.error`.
async function getJson<T>(url: string, params?: Record<string, string | number>): Promise<T> {
    const qs = params ? `?${new URLSearchParams(Object.entries(params).map(([k, v]) => [k, String(v)]))}` : '';
    const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1];
    const res = await fetch(url + qs, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
        },
    });
    const data = await res.json().catch(() => null);
    if (!res.ok) {
        throw Object.assign(new Error(`Request failed with status ${res.status}`), { response: { status: res.status, data } });
    }
    return data as T;
}

function LedgerPanel({ citySlug }: { citySlug: string }) {
    const [residents, setResidents] = useState<Resident[]>([]);
    const [loadingPicker, setLoadingPicker] = useState(true);
    const [pickerError, setPickerError] = useState<string | null>(null);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [charDetail, setCharDetail] = useState<LedgerChar | null>(null);
    const [loadingChar, setLoadingChar] = useState(false);
    const [charError, setCharError] = useState<string | null>(null);
    const [page, setPage] = useState(1);

    useEffect(() => {
        getJson<Resident[]>(`/${citySlug}/bank/ledger/residents`)
            .then((data) => setResidents(data))
            .catch((e: any) => setPickerError(e?.response?.data?.error ?? "Failed to load."))
            .finally(() => setLoadingPicker(false));
    }, [citySlug]);

    const pick = async (id: string) => {
        const n = parseInt(id);
        if (!n) return;
        setSelectedId(n); setCharDetail(null); setCharError(null); setLoadingChar(true); setPage(1);
        try {
            const data = await getJson<LedgerChar>(`/${citySlug}/bank/ledger/character`, { character_id: n });
            setCharDetail(data);
        } catch (e: any) {
            setCharError(e?.response?.data?.error ?? "Failed to load.");
        } finally { setLoadingChar(false); }
    };

    const txs = charDetail?.transactions ?? [];
    const totalPages = Math.max(1, Math.ceil(txs.length / TX_PER_PAGE));
    const visible = txs.slice((page - 1) * TX_PER_PAGE, page * TX_PER_PAGE);

    return (
        <div className="flex flex-col h-full">
            <div className="flex items-center justify-between px-6 py-3 border-b border-white/[0.06] bg-slate-950/60 shrink-0">
                {loadingPicker ? (
                    <div className="flex items-center gap-2 text-xs text-slate-600">
                        <div className="w-4 h-4 border border-slate-600 border-t-slate-400 rounded-full animate-spin" />
                        Loading residents…
                    </div>
                ) : pickerError ? (
                    <div className="flex items-center gap-2 text-xs text-red-400">
                        <Warning size={13} weight="fill" /> {pickerError}
                    </div>
                ) : (
                    <div className="w-64">
                        <Picker options={residents.map(r => ({ value: String(r.id), label: r.name }))}
                            value={selectedId ? String(selectedId) : ""}
                            onChange={(v: string) => pick(v)} placeholder="Search residents…" />
                    </div>
                )}
            </div>

            {selectedId && loadingChar && (
                <div className="flex justify-center py-16">
                    <div className="w-6 h-6 border-2 border-slate-700 border-t-slate-400 rounded-full animate-spin" />
                </div>
            )}
            {selectedId && charError && (
                <div className="mx-6 mt-4 flex items-center gap-2 px-4 py-3 bg-red-500/10 border border-red-500/20 rounded-xl text-xs text-red-400">
                    <Warning size={14} weight="fill" /> {charError}
                </div>
            )}
            {charDetail && !loadingChar && (
                <>
                    <div className="flex items-center justify-between px-6 py-3.5 border-b border-white/[0.04] shrink-0">
                        <div className="flex items-center gap-3">
                            <div className="w-8 h-8 rounded-full bg-slate-800 border border-white/5 overflow-hidden flex items-center justify-center shrink-0">
                                {charDetail.avatar_url
                                    ? <img src={charDetail.avatar_url} className="w-full h-full object-cover" alt="" />
                                    : <User size={13} className="text-slate-600" />}
                            </div>
                            <span className="text-sm font-semibold text-white">{charDetail.name}</span>
                        </div>
                        <span className="text-sm font-semibold text-white tabular-nums">{fmt$(charDetail.bank_balance)}</span>
                    </div>
                    {txs.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-16 text-slate-700 gap-2">
                            <ClockCounterClockwise size={22} />
                            <p className="text-xs uppercase tracking-widest">No transactions</p>
                        </div>
                    ) : (
                        <>
                            <div className="flex-1 overflow-y-auto">
                                {visible.map(tx => <TxRow key={tx.id} tx={tx} />)}
                            </div>
                            <TxPager page={page} totalPages={totalPages} total={txs.length} onChange={setPage} />
                        </>
                    )}
                </>
            )}
            {!selectedId && !loadingPicker && !pickerError && (
                <div className="flex flex-col items-center justify-center flex-1 py-16 text-slate-700 gap-3">
                    <MagnifyingGlass size={26} />
                    <p className="text-xs uppercase tracking-widest">Select a resident above</p>
                </div>
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────
// Owner rates modal
// ─────────────────────────────────────────────────────────────

function OwnerModal({ bankData, onClose }: { bankData: BankData; onClose: () => void }) {
    const [interest, setInterest] = useState(bankData.loan_interest ?? 5);
    const [wireFee, setWireFee] = useState(bankData.wire_fee ?? 0);
    const [saving, setSaving] = useState(false);

    const save = () => {
        setSaving(true);
        router.post(
            route("city.bank.settings", { city: bankData.city_slug }),
            { loan_interest: interest, wire_fee: wireFee },
            { preserveScroll: true, onFinish: () => { setSaving(false); onClose(); } }
        );
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" onClick={onClose}>
            <div className="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" />
            <motion.div initial={{ scale: 0.95, opacity: 0 }} animate={{ scale: 1, opacity: 1 }}
                className="relative bg-slate-900 border border-slate-700 rounded-2xl p-6 w-full max-w-sm shadow-2xl"
                onClick={e => e.stopPropagation()}>
                <button onClick={onClose} className="absolute top-4 right-4 text-slate-500 hover:text-white transition-colors">
                    <X size={18} />
                </button>
                <div className="flex items-center gap-3 mb-6">
                    <div className="w-9 h-9 bg-slate-800 border border-slate-700 rounded-xl flex items-center justify-center">
                        <Gear size={16} weight="bold" className="text-slate-300" />
                    </div>
                    <div>
                        <h3 className="text-sm font-bold text-white">Rate Controls</h3>
                        <p className="text-[10px] text-slate-500">Manage bank rates</p>
                    </div>
                </div>
                <div className="space-y-5">
                    {[
                        { label: "CD Interest Rate (%)", min: 0.5, max: 15.0, step: 0.5, value: interest, set: setInterest, hint: "Applied to all new certificates" },
                        { label: "Wire Fee (%)", min: 0.0, max: 15.0, step: 0.5, value: wireFee, set: setWireFee, hint: "Charged on cross-city transfers" },
                    ].map(r => (
                        <div key={r.label}>
                            <div className="flex justify-between items-center mb-2">
                                <span className="text-xs font-semibold text-slate-300">{r.label}</span>
                                <span className="text-xs font-mono font-bold text-white bg-slate-800 border border-slate-700 px-2.5 py-0.5 rounded-lg">{r.value.toFixed(1)}%</span>
                            </div>
                            <input type="range" min={r.min} max={r.max} step={r.step} value={r.value}
                                onChange={e => r.set(parseFloat(e.target.value))}
                                className="w-full h-1.5 bg-slate-800 rounded-full appearance-none cursor-pointer accent-white" />
                            <div className="flex justify-between text-[9px] text-slate-600 mt-1.5">
                                <span>{r.min}%</span>
                                <span className="text-slate-700">{r.hint}</span>
                                <span>{r.max}%</span>
                            </div>
                        </div>
                    ))}
                    <motion.button whileTap={{ scale: 0.97 }} onClick={save} disabled={saving}
                        className={`w-full py-3 rounded-xl text-xs font-black uppercase tracking-widest flex items-center justify-center gap-2 transition-all ${saving ? "bg-slate-800/60 text-slate-600 cursor-not-allowed" : "bg-white text-slate-900 hover:bg-slate-100"
                            }`}>
                        {saving ? <><div className="w-3 h-3 border-2 border-slate-400/30 border-t-slate-400 rounded-full animate-spin" />Saving…</> : "Save Rates"}
                    </motion.button>
                </div>
            </motion.div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────
// Tab config
// ─────────────────────────────────────────────────────────────

const TABS = [
    { id: "account" as const, label: "Account", icon: BankIcon },
    { id: "transfer" as const, label: "Transfer", icon: Shuffle },
    { id: "history" as const, label: "Activity", icon: ClockCounterClockwise },
    { id: "certificates" as const, label: "Certificates", icon: Scroll },
] as const;

// ─────────────────────────────────────────────────────────────
// Root
// ─────────────────────────────────────────────────────────────

export default function Bank({ bankData, transactions }: { bankData: BankData; transactions?: Transaction[] }) {
    const [activeTab, setActiveTab] = useState<ActiveTab>("account");
    const [ownerOpen, setOwnerOpen] = useState(false);
    const [transactionsRequested, setTransactionsRequested] = useState(false);

    const heroImage = bankData.bank_image || getCityImage(bankData.city_name);

    const allTabs = [
        ...TABS,
        ...(bankData.is_manager ? [{ id: "ledger" as const, label: "Ledger", icon: MagnifyingGlass }] : []),
    ];

    useEffect(() => {
        if (activeTab !== "history" || transactions !== undefined || transactionsRequested) {
            return;
        }

        setTransactionsRequested(true);
        router.reload({
            only: ["transactions"],
        });
    }, [activeTab, transactions, transactionsRequested]);

    return (
        <>
            <Head title={`Bank — ${bankData.city_name}`} />
            <div className="w-full max-w-6xl mx-auto px-1 md:px-4 py-3 md:py-6 flex flex-col gap-4 md:gap-6 h-full">

                {/* ── Hero ─────────────────────────────────────────── */}
                <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4 }}
                    className="relative rounded-t-2xl overflow-hidden border border-white/5 border-b-0 shadow-2xl shrink-0 min-h-[16rem] md:min-h-[20rem] flex flex-col justify-end"
                >
                    <div className="absolute inset-0 bg-slate-900 overflow-hidden">
                        <img src={heroImage} className="absolute inset-0 w-full h-full object-cover" alt="" />
                        <div className="absolute right-0 top-0 w-96 h-96 bg-emerald-500/10 blur-[100px] rounded-full mix-blend-screen pointer-events-none" />
                        <div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/80 to-transparent" />
                        <div className="absolute inset-0 bg-gradient-to-r from-slate-950/80 to-transparent" />
                    </div>

                    <div className="relative px-8 pt-10 pb-6 md:px-10 md:pb-8">
                        <div className="flex flex-col md:flex-row md:items-end justify-between gap-6">
                            <div className="max-w-2xl">

                                <h1 className="text-4xl md:text-5xl lg:text-6xl font-light text-white tracking-tight leading-none mb-4">
                                    State Bank of {bankData.city_name}
                                </h1>
                            </div>

                            <div className="flex items-center gap-6 md:gap-8 shrink-0">
                                {bankData.owner && (
                                    <div className="flex items-center gap-3">
                                        <div className="w-10 h-10 md:w-12 md:h-12 rounded-full overflow-hidden border border-amber-500/30 bg-slate-800 shrink-0 flex items-center justify-center">
                                            {bankData.owner.avatar_url
                                                ? <img src={bankData.owner.avatar_url} className="w-full h-full object-cover" alt="" />
                                                : <Buildings size={16} className="text-slate-600" />}
                                        </div>
                                        <div>
                                            <p className="text-[9px] font-black uppercase tracking-widest text-amber-400/80">Owner</p>
                                            <p className="text-sm md:text-base font-semibold text-white">{bankData.owner.name}</p>
                                        </div>
                                    </div>
                                )}
                                {!bankData.owner && (
                                    <div className="flex items-center gap-3">
                                        <div className="w-10 h-10 rounded-full border border-amber-500/20 bg-slate-800 shrink-0 flex items-center justify-center">
                                            <Buildings size={16} className="text-slate-600" />
                                        </div>
                                        <div>
                                            <p className="text-[9px] font-black uppercase tracking-widest text-amber-400/80">Owner</p>
                                            <p className="text-sm font-black text-slate-500 uppercase tracking-tight">Govt. Operated</p>
                                        </div>
                                    </div>
                                )}
                                {bankData.bank_manager && (
                                    <div className="flex items-center gap-3">
                                        <div className="w-10 h-10 md:w-12 md:h-12 rounded-full overflow-hidden border border-cyan-500/30 bg-slate-800 shrink-0 flex items-center justify-center">
                                            {bankData.bank_manager.avatar_url
                                                ? <img src={bankData.bank_manager.avatar_url} className="w-full h-full object-cover" alt="" />
                                                : <Crown size={16} className="text-slate-600" weight="fill" />}
                                        </div>
                                        <div>
                                            <p className="text-[9px] font-black uppercase tracking-widest text-cyan-400/80">Manager</p>
                                            <p className="text-sm md:text-base font-semibold text-white">{bankData.bank_manager.name}</p>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </motion.div>

                {/* ── Tab bar ──────────────────────────────────────── */}
                <div className="flex overflow-x-auto no-scrollbar border-b border-slate-800/40 gap-1 shrink-0 px-2 lg:px-0">
                    {allTabs.map(tab => {
                        const Icon = tab.icon;
                        const active = activeTab === tab.id;
                        return (
                            <button key={tab.id} onClick={() => setActiveTab(tab.id as ActiveTab)}
                                className={`flex items-center gap-2 px-5 py-3 text-[10px] font-black uppercase tracking-widest border-b-2 transition whitespace-nowrap ${active
                                    ? "border-cyan-400 text-white"
                                    : "border-transparent text-slate-500 hover:text-slate-300"
                                    }`}>
                                <Icon size={14} weight={active ? "fill" : "bold"} />
                                {tab.label}
                                {tab.id === "certificates" && bankData.active_certificate && (
                                    <span className="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse" />
                                )}
                            </button>
                        );
                    })}
                    {bankData.is_owner && (
                        <button onClick={() => setOwnerOpen(true)}
                            className="flex items-center gap-2 px-5 py-3 text-[10px] font-black uppercase tracking-widest border-b-2 border-transparent text-slate-600 hover:text-slate-300 transition whitespace-nowrap ml-auto">
                            <Gear size={14} weight="bold" />
                            Rates
                        </button>
                    )}
                </div>

                {/* ── Tab content ──────────────────────────────────── */}
                <div className="flex-1 min-h-0 bg-slate-900/60 border border-slate-700/40 rounded-xl shadow-lg overflow-hidden">
                    <AnimatePresence mode="wait">
                        <motion.div key={activeTab} initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                            transition={{ duration: 0.12 }} className="h-full flex flex-col">
                            {activeTab === "account" && <AccountPanel bankData={bankData} />}
                            {activeTab === "transfer" && <TransferPanel bankData={bankData} />}
                            {activeTab === "history" && <HistoryPanel transactions={transactions} loading={transactions === undefined} />}
                            {activeTab === "certificates" && <CertificatesPanel bankData={bankData} />}
                            {activeTab === "ledger" && <LedgerPanel citySlug={bankData.city_slug} />}
                        </motion.div>
                    </AnimatePresence>
                </div>
            </div>

            <AnimatePresence>
                {ownerOpen && <OwnerModal bankData={bankData} onClose={() => setOwnerOpen(false)} />}
            </AnimatePresence>
        </>
    );
}

Bank.layout = (page: React.ReactNode) => <GameLayout wide children={page} />;
