# UI kit — `@/Components/ui`

Small, dependency-free (React 19 + Tailwind 3 + framer-motion + Phosphor, all already installed)
component kit for THE DIRECTOR's "midnight noir" look: near-black slate surfaces, cyan accent,
thin bordered rounded panels, tracked uppercase labels, green clean / red dirty cash.

```tsx
import { Button, Panel, Money, Countdown, Dialog, toast } from '@/Components/ui';
```

Rules the kit enforces (please keep them when adding pages):

- **Secondary text floor is `slate-400`** (7.9:1 on slate-950). `slate-500` is 4.2:1 and fails AA at
  small sizes; `slate-600/700` (2.7 / 1.95:1) are for borders and decoration only, never text.
- **Type scale only** — `label` (10px), `caption` (12), `body` (14), `lead` (16), `title` (20),
  `display` (30). No `text-[9px]`/`text-[8px]`/`text-[11px]`.
- **Animations ≤ 150 ms**; all motion components honour `prefers-reduced-motion`.
- **Every countdown uses the server clock** (`useServerClock` via `useCountdown`), never `Date.now()`.
- **Every interactive element has a visible `focus-visible` ring** (cyan-400).
- **One z-index scale**: header 40 · drawer 50 · dialog 60 · toast 70 · tooltip 80.

`cn()` is a plain joiner (no tailwind-merge). A `className` you pass is *appended*, so it can add
layout (`w-full`, `mt-4`) but should not try to override the component's own colours.

---

## Components

### Button

```tsx
<Button onClick={work}>Work shift</Button>                                  // primary: white → cyan hover
<Button variant="secondary" size="sm" icon={Bank}>Deposit</Button>
<Button variant="danger" loading={processing} fullWidth>Dissolve</Button>  // width never changes
<Button variant="ghost" iconOnly icon={X} aria-label="Close" />
<Button href={route('work')} prefetch="hover" cacheFor="5s">Go to work</Button>   // Inertia <Link>
<Button href={route('logout')} method="post">Log out</Button>              // renders <button> Link
<Button href="https://discord.gg/…" external>Discord</Button>              // plain <a>
```

Variants `primary | secondary | ghost | danger | success | warning`; sizes `sm` 32px · `md` 40px · `lg` 48px.
`loading` swaps the icon for a spinner (or overlays the label when there is no icon), sets
`aria-busy`, and swallows clicks. All Inertia `<Link>` props (`prefetch`, `cacheFor`, `only`,
`preserveScroll`, `method`, `data` …) pass straight through when `href` is set. `disabled` on a link
renders a non-interactive `aria-disabled` span. Accepts `ref` (React 19 prop).

### Panel

```tsx
<Panel title="Treasury" subtitle="Corporate accounts" actions={<Badge tone="emerald">Solvent</Badge>}>
    …
</Panel>
<Panel tone="danger" title="Danger zone" padding="lg">…</Panel>
<Panel tone="gold" as="article" padding="none">…</Panel>
```

`tone`: `default` (cyan eyebrow) · `danger` (red) · `gold` (amber-300). `padding`: `none | sm | md | lg`.

### StatTile · StatBar

```tsx
<div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
    <StatTile label="Clean cash" value={<Money amount={cash} compact />} icon={CurrencyDollar} />
    <StatTile label="Heat" value="72%" tone="red" sub={<Countdown target={heatResetAt} />} />
</div>

<StatBar label="Health" value={hp} max={maxHp} tone="emerald" valueLabel />
<StatBar value={xp} max={nextXp} size="xs" aria-label="Experience" />
```

`StatBar` paints at its real width on first render; only *changes* animate (150 ms), so bars never
sweep up from zero on navigation.

### Badge · Spinner · Skeleton

```tsx
<Badge tone="cyan">Prosecutor</Badge>
<Badge tone="gold" dot>Founder</Badge>
<Badge tone="red" shape="tag">Wanted</Badge>

<Spinner size={16} />                      // inherits currentColor

<Skeleton width="100%" height={120} rounded="2xl" />   // explicit size → zero layout shift
<SkeletonText lines={3} />
```

Badge tones: `slate cyan emerald red amber gold purple blue`.

### Countdown · useCountdown

```tsx
<Countdown target={character.timers.next_work_at} />                 // "5m 3s" → "Ready"
<Countdown target={election.ends_at} format="clock" />               // "02:14:09"
<Countdown target={release_at} readyLabel="Released" onReady={() => router.reload({ only: ['jail'] })} />

const { remaining, ready } = useCountdown(job.next_at);              // seconds, from the server clock
formatDuration(3723)            // "1h 2m"
formatDuration(3723, 'clock')   // "01:02:03"
```

`target` accepts unix seconds, unix ms, numeric strings, ISO strings, `"Y-m-d H:i:s"` (read as UTC) or a
`Date`. Rendered with `tabular-nums` and a fixed `min-width` (7ch / 8ch) so it does not jitter.
`onReady` fires once when it crosses zero while mounted (not on mount if already ready).

### Dialog · ConfirmDialog

```tsx
<Dialog
    open={open}
    onClose={() => setOpen(false)}
    title="Hospital"
    description="Recover faster with private care."
    media={{ image: hospitalImg, badges: [{ text: 'Private', tone: 'emerald' }] }}
    footer={<Button onClick={pay} loading={processing}>Pay $5,000</Button>}
>
    …body…
</Dialog>

<ConfirmDialog
    open={confirming}
    onClose={() => setConfirming(false)}
    tone="danger"
    title="Dissolve corporation?"
    description="Every member is removed. This cannot be undone."
    confirmLabel="Dissolve"
    onConfirm={() => new Promise((resolve) => router.post(route('corp.dissolve'), {}, { onFinish: resolve }))}
/>
```

Portalled to `<body>` at z-60; scrim (`scrim="clear"` keeps the page at full brightness like the old
StyledModal); focus moves in on open and is restored on close; Tab is trapped; Escape closes (only
the top-most dialog when nested); body scroll is locked; `role="dialog"`, `aria-modal`,
`aria-labelledby`/`aria-describedby` are wired. `dismissible={false}` blocks Escape / scrim / close
while submitting. Sizes `sm md lg xl`. `ConfirmDialog` shows a spinner and stays open while a
returned promise is pending; destructive confirms focus **Cancel** first.

### Tabs · useTabState

```tsx
const [tab, setTab] = useTabState(['cases', 'docket', 'history'] as const);   // reads/writes ?tab=

<Tabs
    idBase="law"
    value={tab}
    onChange={setTab}
    items={[
        { id: 'cases', label: 'Cases', icon: Scales, count: referred.length },
        { id: 'docket', label: 'Docket' },
        { id: 'history', label: 'History' },
    ]}
/>
<TabPanel idBase="law" id={tab}>…</TabPanel>

<Tabs variant="pill" items={…} value={…} onChange={…} />
```

URL sync uses `router.replace({ url, preserveState: true, preserveScroll: true })` — a client-side
history replace, **no request**. The default tab is dropped from the URL. The strip scrolls
horizontally on mobile and keeps the active tab in view; ←/→/Home/End move between tabs.

### Pagination

```tsx
<Pagination page={page} total={items.length} pageSize={10} onChange={setPage} />           // client
<Pagination page={p.current_page} totalPages={p.last_page} getHref={(n) => `?page=${n}`} /> // server (Links, hover prefetch)
<Pagination compact page={page} totalPages={pages} onChange={setPage} />                     // ‹ 3 / 12 ›
```

40 px touch targets; renders nothing for one page.

### EmptyState

```tsx
<EmptyState icon={Scales} title="No cases on your desk" description="Detectives refer cases here." />
<EmptyState compact title="Nothing yet" action={<Button size="sm" href="/work">Find work</Button>} />
```

### Money · formatMoney

```tsx
<Money amount={character.cash} />                       // $12,400   (clean = emerald-400)
<Money amount={character.dirty_cash} kind="dirty" />    // red-400
<Money amount={1_240_000} compact />                    // $1.2M     (title="$1,240,000")
<Money amount={-5000} kind="delta" />                   // −$5,000 red · +$5,000 green
<Money amount={price} kind="neutral" />                 // inherits colour

formatMoney(1_240_000, { compact: true })               // "$1.2M"
```

Compact abbreviates from 100,000 by default (same threshold as `formatCash`); the full value is always
in `title`.

### Field · Input · MoneyInput

```tsx
<Field label="Withdraw" hint="Accepts 250k, 1.5m, 2b" error={errors.amount} aside={<>Balance <Money amount={bank} /></>}>
    <MoneyInput value={amount} onChange={(raw) => setAmount(raw)} max={bank} />
</Field>

<Field label="Corporation name" required error={errors.name}>
    <Input value={name} onChange={(e) => setName(e.target.value)} maxLength={32} />
</Field>
```

`Field` wires `id`, `htmlFor`, `aria-describedby` (hint/error) and `aria-invalid` into the input
through context. `MoneyInput` parses with the existing `parseSymbolicAmount` (GameLayoutComponents)
— the same parser Bank / Banking / Actions use — and previews `= $1,500,000` when shorthand is typed.
`onChange(raw, amount)`. `kind="dirty"` colours the `$` red.

### Tooltip

```tsx
<Tooltip content="Clean cash can be spent anywhere."><Button size="sm" variant="ghost">?</Button></Tooltip>
<Tooltip content="Heat decays hourly" focusable><Info size={14} /></Tooltip>   // non-focusable child
```

Opens on mouse hover (120 ms delay), keyboard focus, **and touch tap** (tap toggles, tap-outside /
Escape closes). Portalled, fixed, z-80, flips top/bottom and clamps to the viewport.

### Avatar

```tsx
<Avatar src={player.avatar} name={player.displayName} size="sm" status="online" />
<Avatar name="Korvan" size="lg" ringClassName="ring-amber-300" shape="rounded" />
```

Sizes `xs 24 · sm 32 · md 40 · lg 56 · xl 80`. Falls back to initials (stable colour per name) when
there is no `src` or the image errors. `status`: `online | idle | offline | dead`.

### Toasts

```tsx
// once, in GameLayout:
useFlashToasts({ ignore: (kind, _msg, page) => kind === 'success' && page.component.startsWith('Conflict/') });
…
<Toaster />

// anywhere:
toast.success('Shift complete. +$420');
toast.error('Not enough cash');
const t = useToast(); t.warning('Heat is rising', { duration: 6000 });
toast.dismiss(id);
```

Fixed position, z-70: **top-right under the header** at the `nav` breakpoint (≥1072px),
**bottom, above the 56px mobile tab bar** (+ safe-area) below it. `aria-live="polite"` (errors use
`role="alert"`). Auto-dismiss 4 s (warning 5 s, error 6 s), paused on hover/focus; max 4 visible;
an identical message already on screen is bumped (×2) instead of stacking. The store is
module-level, so toasts survive GameLayout re-mounting between pages. `ToastProvider` is an optional
wrapper that just renders `<Toaster/>` after its children.

**`useFlashToasts()` dedupe** — `flash` is an `Inertia::always()` prop, so the same message can ride
on several responses. The hook:

1. reads flash only when a **server response is applied** (Inertia `beforeUpdate`, which fires once
   per response and *not* for back/forward restores or prefetch-cache fills), plus the very first page;
2. bumps an **action epoch** on every non-GET visit and every full (non-partial, non-prefetch) GET;
   partial reloads (`only`/`except`) and prefetches don't bump it;
3. shows each `(kind, message)` at most once per epoch.

So a flash repeated by a poll / partial reload is ignored, but the *same text after a new action*
(e.g. working twice) shows again.

---

## Tokens & type scale

**Applied.** The live values are the `:root` block in `resources/css/app.css` (updated in place);
`resources/css/tokens.css` is the annotated reference copy (not imported — change both together).
The tailwind patch below is applied in `tailwind.config.js`, and `styles.ts` already uses
`text-label` / `z-dialog` etc. Tokens map the shadcn variable names onto the palette, plus game tokens:

| Token | Value | Use |
|---|---|---|
| `--background` | slate-950 | page |
| `--card` / `--popover` | slate-900 | panels, menus |
| `--muted` / `--secondary` / `--accent` | slate-800 | fills, hovers |
| `--muted-foreground` | **slate-400** | secondary text floor |
| `--foreground` | slate-50 | primary text |
| `--primary` / `--ring` | cyan-400 | brand, focus (ring was orange) |
| `--destructive` | red-500 | |
| `--success` | emerald-500 | |
| `--warning` | amber-500 | |
| `--border` | slate-800 | |
| `--input` | slate-700 | input borders |
| `--cash-clean` | emerald-400 | clean cash |
| `--cash-dirty` | red-400 | dirty cash |
| `--gold` | amber-300 | gold tier |

It also sets `color-scheme: dark`, a zero-specificity cyan `:focus-visible` outline fallback for
not-yet-migrated controls, and `::selection`.

### `tailwind.config.js` patch (applied)

```js
// theme.extend
colors: {
    // `/ <alpha-value>` makes opacity modifiers (bg-card/60, border-border/50) work with the vars
    border: 'hsl(var(--border) / <alpha-value>)',
    input: 'hsl(var(--input) / <alpha-value>)',
    ring: 'hsl(var(--ring) / <alpha-value>)',
    background: 'hsl(var(--background) / <alpha-value>)',
    foreground: 'hsl(var(--foreground) / <alpha-value>)',
    primary:     { DEFAULT: 'hsl(var(--primary) / <alpha-value>)',     foreground: 'hsl(var(--primary-foreground) / <alpha-value>)' },
    secondary:   { DEFAULT: 'hsl(var(--secondary) / <alpha-value>)',   foreground: 'hsl(var(--secondary-foreground) / <alpha-value>)' },
    destructive: { DEFAULT: 'hsl(var(--destructive) / <alpha-value>)', foreground: 'hsl(var(--destructive-foreground) / <alpha-value>)' },
    success:     { DEFAULT: 'hsl(var(--success) / <alpha-value>)',     foreground: 'hsl(var(--success-foreground) / <alpha-value>)' },
    warning:     { DEFAULT: 'hsl(var(--warning) / <alpha-value>)',     foreground: 'hsl(var(--warning-foreground) / <alpha-value>)' },
    muted:       { DEFAULT: 'hsl(var(--muted) / <alpha-value>)',       foreground: 'hsl(var(--muted-foreground) / <alpha-value>)' },
    accent:      { DEFAULT: 'hsl(var(--accent) / <alpha-value>)',      foreground: 'hsl(var(--accent-foreground) / <alpha-value>)' },
    popover:     { DEFAULT: 'hsl(var(--popover) / <alpha-value>)',     foreground: 'hsl(var(--popover-foreground) / <alpha-value>)' },
    card:        { DEFAULT: 'hsl(var(--card) / <alpha-value>)',        foreground: 'hsl(var(--card-foreground) / <alpha-value>)' },
    cash: {
        clean: 'hsl(var(--cash-clean) / <alpha-value>)',
        dirty: 'hsl(var(--cash-dirty) / <alpha-value>)',
    },
    gold: 'hsl(var(--gold) / <alpha-value>)',
},
fontSize: {
    // extend (keeps xs…9xl); `label` is the only new size, the rest are named aliases of the defaults
    label:   ['10px', { lineHeight: '14px', letterSpacing: '0.16em', fontWeight: '800' }], // + `uppercase` class
    caption: ['12px', { lineHeight: '16px' }],
    body:    ['14px', { lineHeight: '20px' }],
    lead:    ['16px', { lineHeight: '24px' }],
    title:   ['20px', { lineHeight: '28px' }],
    display: ['30px', { lineHeight: '36px' }],
},
zIndex: {
    header: '40',
    drawer: '50',
    dialog: '60',
    toast: '70',
    tooltip: '80',
},
transitionDuration: { DEFAULT: '150ms' },
```

`styles.ts` now uses `text-label uppercase` and `z-header … z-tooltip`.

Type-scale mapping for migration:

| Old | New |
|---|---|
| `text-[8px]`, `text-[9px]`, `text-[10px]` (tracked uppercase) | `label` (10/14, .16em, 800) |
| `text-[10px]`/`text-[11px]` (sentence case) | `caption` (`text-xs`) |
| `text-[13px]`, `text-sm` | `body` |
| `tracking-[0.18em…0.32em]`, `tracking-widest` on labels | `.16em` (part of `label`) |
| `text-slate-500/600/700` on text | `text-slate-400` / `text-muted-foreground` |

---

## Migration guide

| Hand-rolled pattern | Replace with | Where it lives today |
|---|---|---|
| `ActionButton` (purple hover in styledmodal.tsx, cyan in Corporate.jsx), white `bg-white … hover:bg-cyan-400` buttons | `Button` (`primary` = white → cyan everywhere) | `Layouts/styledmodal.tsx`, `Pages/Careers/Corporate.jsx`, most pages |
| Corporate `Panel`, `rounded-2xl border border-slate-700/xx bg-slate-900/xx` sections | `Panel` | `Pages/Careers/Corporate.jsx` + most pages |
| `InfoCard`, ad-hoc label/number boxes | `StatTile` | `styledmodal.tsx`, City pages |
| Health/XP/progress `div` bars (some animate from 0) | `StatBar` | GameLayout sidebar, Work, Talents |
| `Pill`, `PositionBadge`, badge spans | `Badge` | Law, Police, Corporate, styledmodal |
| `Spinner` copies, `animate-spin` spans | `Spinner` | Law, Police, Help |
| `StyledModal`, `StyledModalConflict`, ~22 `fixed inset-0` overlays (no focus trap / Escape / aria-modal) | `Dialog` (`media` = StyledModal header) | Profile, Messages, PoliceHq, Hospital, TransitHub, Admin/CareerSection, Wardrobe, Healthcare, Main, University, CityHall, Bank, Leaderboard, ConflictResults, ShopModal, destroyedmodal, Destroyed, AchievementEntry, GamePreviewOverlay |
| `ConfirmDanger`, "Are you sure?" inline toggles | `ConfirmDialog` | Corporate and others |
| `TabBar` copies, tab button rows, `useState` tab without URL | `Tabs` + `useTabState` | Law, Police, Bank, Corporate, Profile … |
| 7 `Pagination` implementations (28 px targets) | `Pagination` | Law, Police, Help, Journal, Leaderboard, Announcements … |
| `Empty` (slate-700 text — fails contrast) | `EmptyState` | Law, Police, lists |
| `formatCash`, `toLocaleString()` + manual `text-emerald-400` / `text-red-400` | `Money` / `formatMoney` | everywhere cash is shown |
| `StyledInput`, raw `<input>` + label, per-page shorthand parsing | `Field` + `Input` / `MoneyInput` | styledmodal, Bank, Banking, Actions, GameLayout quick-withdraw |
| hover-only `title`/custom tooltips | `Tooltip` | various |
| avatar `img` + manual fallback | `Avatar` | Profile, Messages, online list |
| Per-page `useCountdown`/`formatTime` (several on `Date.now()`, 3 formats) | `Countdown` / `useCountdown` / `formatDuration` | Election, Bank, PoliceHq, Banned, Messages, University, Shop, CityHall, Hospital |
| Inline flash banners above `<main>` (push content down) | `useFlashToasts()` + `<Toaster/>` | `Layouts/GameLayout.jsx` |
