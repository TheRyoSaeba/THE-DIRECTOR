# Running The Director locally

The dev stack runs the app (FrankenPHP), a Vite dev server with hot reload, and Redis.
The database is a **Neon branch**: a copy-on-write clone of production, so you get real
schema and data without touching the live database.

## First-time setup

1. **Create a Neon branch.** Neon console → your project → Branches → *Create branch*,
   parent `main`. Copy its **pooled** connection details.

2. **Create `.env`** from `.env.example` and fill in the branch:

   ```
   APP_ENV=local
   APP_DEBUG=true
   DB_HOST=<branch-endpoint>-pooler.<region>.aws.neon.tech
   DB_DATABASE=<db name>
   DB_USERNAME=<role>
   DB_PASSWORD=<password>
   DB_SSLMODE=require
   CACHE_STORE=redis
   SESSION_DRIVER=database
   ```

   Then generate a key: `docker compose -f compose.dev.yaml run --rm app php artisan key:generate`

3. **Start everything:**

   ```
   docker compose -f compose.dev.yaml up
   ```

   Open http://localhost:8000.

## Logging in

Google OAuth needs `http://localhost:8000/auth/google/callback` registered as a redirect
URI on your OAuth client. The quicker route is `/dev-login` (only exists when `APP_ENV=local`):
give your own account a password **on the branch** and sign in with it.

```
docker compose -f compose.dev.yaml exec app php artisan tinker --execute \
  "App\Models\User::where('email', 'you@example.com')->update(['password' => bcrypt('dev')]);"
```

## Following along with changes

`git pull` on the working branch. PHP changes apply on the next request; frontend changes
hot-reload in the browser. After a pull that changes `composer.json` or `package.json`,
restart the stack.
