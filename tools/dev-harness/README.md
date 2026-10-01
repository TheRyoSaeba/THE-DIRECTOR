# dev-harness

A local environment for measuring and screenshotting THE-DIRECTOR (Laravel 12, Inertia v3, React 19, Postgres 16, Redis) in a throwaway cloud container. After a container reset, rebuild everything with one command:

```bash
bash tools/dev-harness/setup.sh            # deps, Postgres, Redis, .env, migrations, seed, assets
bash tools/dev-harness/setup.sh --reseed   # drop and recreate the local DB, then migrate and seed
bash tools/dev-harness/setup.sh --build    # also rebuild production assets (vite build)
```

You can re-run it safely: steps that are already done are skipped. A clean run takes about 30 s, plus composer install (around 15 min, since every package is cloned from source).

## Local-DB guard

The harness writes synthetic users and data, and the owner's real database is on Neon. Every entry point refuses to run unless the database is local:

- `setup.sh` exits before touching anything if any of these hold:
  - the `DB_HOST` env var is set to something other than `127.0.0.1`/`localhost`
  - `DB_URL`/`DATABASE_URL` is set
  - an existing `.env` has a remote `DB_HOST` or sets `DB_URL`

  It never overwrites a remote `.env`.
- Every PHP script loads `lib/bootstrap.php`. This checks the connection Laravel actually resolves (host must be local, `url` must be empty) and requires `APP_ENV=local`.

## What setup.sh does

1. **Composer.** GitHub zip downloads return 403 through the proxy, so it sets `use-github-api false` and `github-protocols https`, then runs `composer install --no-dev --prefer-source --no-scripts`. Dev deps are skipped because phpstan is zip-only. Then it runs `package:discover`.
2. **Postgres 16.** Data lives in `/var/lib/pgdirector/data` (owned by `postgres`, mode 700). The server runs with trust auth on port 5432 with its socket in `/tmp`, logging to `/var/lib/pgdirector/pg.log`. The DB is `thedirector`.
   **Redis** runs on port 6379 with `--dir /var/tmp/dev-harness`. That keeps `dump.rdb` out of the repo.
3. **.env.** It copies `.env.example`, then sets:
   - `APP_ENV=local`, `DB_HOST=127.0.0.1`, `DB_SSLMODE=disable`
   - Redis for cache and session (`SESSION_CONNECTION=session`, cache DB 1, session DB 2)
   - `QUEUE_CONNECTION=sync`

   It also drops `NODE_ENV` and runs `key:generate`.
4. **Migrations** (`lib/migrate.php`). The real schema drifted, so a fresh DB needs two patches:
   - **Patch A** runs after the users/cities/careers/career_ranks base migrations:
     - It adds `cities.slug`.
     - It inserts careers 1..13: unemployed, police, healthcare, law, banking, corporation, politics, customs, technician, criminal, retail, labor, secret. The sequence is reset with `setval`.
     - It inserts cities 1 New York, 2 Tokyo and 3 London.
     - It inserts no `career_ranks`, because a migration inserts customs ranks and would collide.
   - **Patch B** runs after all migrations. It fills rank levels 1..6 (`xp_required = (r-1)*1000`) wherever they are missing. On a fresh DB, customs and technician only get rank 2 from data-migrations.
   - A migration deletes `retail`, so 12 careers remain.
5. **Seed** (`lib/seed.php`). It is skipped if `p_police@test.local` already exists.
   - **Players.** 12 players, one per career. Login is `p_<code>@test.local` / `password`. Each has a character `P_<code>` in New York at rank 3 (rank 2 where a career has fewer ranks) with XP below the promotion threshold. Money is $250k cash, $900k bank and $20k dirty.
   - **NPCs.** 300 NPCs `Npc0..Npc299` across the 3 cities and all careers. Their emails are `npcN@npc.local`, so they stay out of the /dev-login list.
   - **Stats and timers.** Every character gets `character_stats` and `character_timers`.
   - **Online users.** 120 users are online via `Presence::touch`.
   - **Journals.** 120 per player across money_transfer_received, attack_received, promotion_achieved, item_sale_request, corporation_invite_request and defense_request. Every `data[...]` key read in `app/Models/CharacterJournal.php` is filled from the file at seed time:
     - `*_id` keys get a real NPC id.
     - Numeric-looking keys get 50.
     - Everything else gets `'Sample'`.
   - **Messages.** 400 per player, exchanged with about 40 NPCs.
   - **Work.** One police `career_earns` row (`code = 'patrol'`, every stat min/max 0-1) so `/work/attempt` works.
   - **Businesses.** One each of bank, hospital, police and city-hall per city. `BusinessSeeder` is broken because it writes the dropped `owner_title` column.
6. **Assets.** Runs `NODE_ENV=production npx vite build` if `public/build/manifest.json` is missing (or with `--build`). It always removes `public/hot`.

Logs go to `/var/tmp/dev-harness/` (redis, vite build, artisan serve).

## Measuring

All three scripts boot the app **once**, like an Octane worker, and replay requests through the HTTP kernel. They log in via GET + POST `/dev-login` and carry cookies between requests. Inertia visits send `X-Inertia` and an `X-Inertia-Version` taken from `HandleInertiaRequests::version()`.

Between requests they do what Octane does:
- `auth->forgetGuards()`
- `forgetScopedInstances()`
- flush and regenerate the session store
- flush queued cookies
- `Inertia::flushShared()`

They count DB queries with `DB::listen` and Redis commands with `redis->enableEvents()` and `CommandExecuted`. Each run clears the throttles first: the app uses `throttleWithRedis()`, so the limiter keys `md5(name.userId)` live on the default Redis connection.

```bash
# status / DB queries / Redis commands / bytes / p50 / max / most repeated query
php tools/dev-harness/measure.php police /work /dashboard /journal [--n=10] [--full]

# per-prop JSON size of one Inertia response (optionally as a client that already holds once-props)
php tools/dev-harness/breakdown.php police /journal [--except-once=onlinePlayers] [--queries]

# POST like Inertia, then follow the redirect. Resets next_work_at/next_action_at to time()-60
# and clears the actions/players limiters before each run; restores XP/cash afterwards
php tools/dev-harness/action.php police /work/attempt earn_id=$(psql -h 127.0.0.1 -U postgres thedirector -qAtc "select id from career_earns where code='patrol'") --n=5
```

## Screenshots

```bash
bash tools/dev-harness/screens.sh <outDir> [nameFilterRegex]   # REBUILD=1 to rebuild assets first
```

`screens.sh` does the following:
- builds assets if they are missing
- removes `public/hot`
- starts `php artisan serve --host=127.0.0.1 --port=8010` if nothing is listening
- runs `node screens.mjs <outDir>` (honours `BASE_URL`)

Full-page PNGs are written as `<viewport>__<user>__<page>.png`, plus an `index.json`.

- **Viewports:**
  - desktop 1440x900
  - laptop 1050x800 (known bug: no navigation between 1024 and 1071px)
  - mobile 390x844 (isMobile, hasTouch, DPR 2)
- **Pages as `p_police`:** /dashboard, /work, /actions, /conflict, /journal, /messages, /new-york, /new-york/bank, /career/police, /settings, /leaderboard, /new-york/cityhall.
- **Pages as `p_corporation`:** /career/corporate.
- **Waits:** each capture waits for network idle, then 1.2 s for the card-flip animation.

Notes:
- **Playwright is not a repo dependency.** It is resolved from `$PLAYWRIGHT_MODULE_DIR`, the global npm root (the container has `playwright@1.56.1`, which matches the preinstalled `/opt/pw-browsers/chromium-1194`), or the repo. Do **not** run `playwright install`. If it is missing, run `npm i -g playwright@1.56.1`.
- **Remote images (`images.thedirector.app`) are blocked by the container's network proxy,** so avatars and photos show as broken or missing. That is expected.
- **Fixed elements repeat in mobile full-page shots.** Elements such as the mobile bottom tab bar show up mid-image in full-page captures. That is a full-page screenshot artifact.
- **Visiting /journal marks entries read,** so later screenshots show a smaller unread badge.
