# Running The Director locally

## First time (fresh clone)

```
git clone https://github.com/TheRyoSaeba/THE-DIRECTOR.git
cd THE-DIRECTOR
git checkout claude/the-director-review-d772w8
cp .env.example .env
```

Fill in `.env`:

- `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` from the Neon console
  (Connect → the **pooled** connection, host ends in `-pooler...neon.tech`). `DB_SSLMODE`
  defaults to `require`, which Neon needs.
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` if you want Google login locally.

Leave `APP_KEY` empty; it's generated on first start.

```
docker compose -f compose.dev.yaml up
```

Open http://localhost:8000.

## Database migrations

New code may need new columns. When a pull adds migrations, run them once:

```
docker compose -f compose.dev.yaml exec -e DB_HOST=<direct host, without -pooler> app php artisan migrate
```

Neon recommends the **direct** (non `-pooler`) host for migrations; the index migration builds
indexes `CONCURRENTLY`.

## Following along

`git pull`. PHP changes apply on the next request; frontend changes hot-reload in the browser.
After a pull that changes `composer.json` or `package.json`, restart the stack.

## Logging in

Google OAuth works if `http://localhost:8000/auth/google/callback` is registered on your OAuth
client. Otherwise use `/dev-login` (local only) after giving your account a password:

```
docker compose -f compose.dev.yaml exec app php artisan tinker --execute \
  "App\Models\User::where('email', 'you@example.com')->update(['password' => bcrypt('dev')]);"
```

## Troubleshooting

- **"This page isn't working" on localhost:8000**: the app container isn't serving yet or has
  stopped. Check its log: `docker compose -f compose.dev.yaml logs app`. The first start runs
  `composer install`, which takes a few minutes; wait for `App ready on http://localhost:8000`.
- **A Laravel error page**: with `APP_DEBUG=true` server errors show the real exception. The full
  log is in `storage/logs/laravel.log`.
- **After pulling dependency changes**: `docker compose -f compose.dev.yaml down`, then `up` again.
  If PHP packages still look stale, delete `vendor/` and start again.
