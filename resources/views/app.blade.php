<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title data-inertia>{{ config('app.name', 'TheDirector') }}</title>
    <meta name="title" content="TheDirector | Greed is Good." />
    <meta name="description" content="A strategic game where corporate rules, political governance, and player choice determine whether you survive or thrive." />


    <meta property="og:type" content="website" />
    <meta property="og:url" content="https://thedirector.app/" />
    <meta property="og:title" content="TheDirector | Greed is Good." />
    <meta property="og:description" content="A strategic game where corporate rules, political governance, and player choice determine whether you survive or thrive." />
      
    {{-- Fonts: own <link> so it loads in parallel with app.css instead of chaining behind a CSS @import --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">

    <!-- Google AdSense (static head tag; not managed by Inertia) -->
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1145262174285661" crossorigin="anonymous"></script>

    {{-- Vite: entry + current page component, so Laravel emits modulepreload tags for
         the entry, its vendor chunks and the page chunk (and its imports) up front.
         Uses the dev server automatically when public/hot exists. --}}
    @php
        $pageComponent = $page['component'] ?? null;
        $pageEntry = null;
        if (is_string($pageComponent) && preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*$#', $pageComponent)) {
            foreach (['tsx', 'jsx'] as $ext) {
                if (file_exists(resource_path("js/Pages/{$pageComponent}.{$ext}"))) {
                    $pageEntry = "resources/js/Pages/{$pageComponent}.{$ext}";
                    break;
                }
            }
        }
    @endphp
    @viteReactRefresh
    @vite(array_values(array_filter(['resources/css/app.css', 'resources/js/app.tsx', $pageEntry])))

    {{-- Admins get every named route; everyone else gets the 'player' group (no admin.*). --}}
    @if (auth()->user()?->is_admin)
        @routes
    @else
        @routes('player')
    @endif
    @inertiaHead
</head>

<body>
    @inertia
</body>

</html>
