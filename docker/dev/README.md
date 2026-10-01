# Running The Director locally

```
git pull
docker compose -f compose.dev.yaml up
```

Open http://localhost:8000.

The stack uses your existing `.env`: the Neon database, Google OAuth and app key are read from it.
Docker provides the app server (FrankenPHP), a Vite dev server with hot reload, and Redis.

- PHP changes apply on the next request.
- Frontend changes hot-reload in the browser.
- After a pull that changes `composer.json` or `package.json`, restart the stack.

Login works the same as before: Google OAuth (if `http://localhost:8000/auth/google/callback`
is registered on your OAuth client) or `/dev-login`.
