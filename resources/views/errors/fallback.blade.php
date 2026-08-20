<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error - TheDirector</title>
    @vite(['resources/css/app.css'])
    <style>
        body {
            margin: 0;
            background: linear-gradient(to bottom right, #0f172a, #1e293b, #0f172a);
            color: white;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: system-ui, -apple-system, sans-serif;
        }
    </style>
</head>

<body>
    <div style="text-align: center; padding: 2rem;">
        <div style="font-size: 4rem; margin-bottom: 1rem;">⚠️</div>
        <h1 style="font-size: 2rem; margin-bottom: 0.5rem;">{{ $status ?? 500 }}</h1>
        <p style="color: #94a3b8; margin-bottom: 2rem;">An unexpected error occurred.</p>
        <a href="/"
            style="display: inline-block; padding: 0.75rem 1.5rem; background: #06b6d4; color: white; text-decoration: none; border-radius: 0.5rem; font-weight: 600;">Go
            Home</a>
    </div>
</body>

</html>
