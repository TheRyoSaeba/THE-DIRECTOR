<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Error — TheDirector</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:        #020817;
            --surface:   #0f172a;
            --border:    #1e293b;
            --text:      #e2e8f0;
            --muted:     #64748b;
            --accent:    #06b6d4;
            --danger:    #ef4444;
            --danger-bg: rgba(239,68,68,.08);
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: ui-monospace, 'Cascadia Code', 'Fira Code', Consolas, monospace;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .card {
            width: 100%;
            max-width: 520px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 1rem;
            padding: 2.5rem 2rem;
            box-shadow: 0 24px 64px rgba(0,0,0,.6);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            font-size: .65rem;
            font-weight: 800;
            letter-spacing: .15em;
            text-transform: uppercase;
            padding: .3rem .75rem;
            border-radius: 2rem;
            border: 1px solid rgba(239,68,68,.3);
            background: var(--danger-bg);
            color: var(--danger);
            margin-bottom: 1.75rem;
        }

        .dot {
            width: .45rem;
            height: .45rem;
            border-radius: 50%;
            background: var(--danger);
            animation: pulse 1.6s ease-in-out infinite;
        }

        @keyframes pulse {
            0%,100% { opacity: 1; }
            50%      { opacity: .3; }
        }

        .brand {
            font-size: .7rem;
            font-weight: 900;
            letter-spacing: .2em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: .75rem;
        }

        .brand span { color: var(--accent); }

        h1 {
            font-size: clamp(3.5rem, 10vw, 5rem);
            font-weight: 900;
            letter-spacing: -.02em;
            line-height: 1;
            color: #fff;
            margin-bottom: .5rem;
        }

        .subtitle {
            font-size: .85rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 1.5rem;
        }

        .message {
            font-size: .82rem;
            line-height: 1.6;
            color: #94a3b8;
            margin-bottom: 2rem;
            font-family: system-ui, -apple-system, sans-serif;
        }

        .divider {
            height: 1px;
            background: var(--border);
            margin: 1.75rem 0;
        }

        .actions {
            display: flex;
            gap: .75rem;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .65rem 1.25rem;
            border-radius: .5rem;
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
            text-decoration: none;
            border: 1px solid transparent;
            cursor: pointer;
            transition: opacity .15s;
        }

        .btn:hover { opacity: .8; }

        .btn-primary {
            background: var(--accent);
            color: #000;
            border-color: var(--accent);
        }

        .btn-ghost {
            background: transparent;
            color: var(--muted);
            border-color: var(--border);
        }

        .meta {
            font-size: .65rem;
            color: var(--muted);
            margin-top: 1.5rem;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .5rem;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">
            <span class="dot"></span>
            Server Error
        </div>

        <div class="brand">THE <span>DIRECTOR</span></div>

        <h1>500</h1>
        <div class="subtitle">Internal Server Error</div>

        <p class="message">
            Our backend ran into an error with that request. Please try again in a moment.
        </p>

        <div class="divider"></div>

        <div class="actions">
            <a href="/" class="btn btn-primary">← Return Home</a>
            <button onclick="window.location.reload()" class="btn btn-ghost">Try Again</button>
        </div>

        <div class="meta">
            <span>TheDirector.app</span>
            <span id="ts"></span>
        </div>
    </div>

    <script>
        document.getElementById('ts').textContent = new Date().toUTCString();
    </script>
</body>
</html>
