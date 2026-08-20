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
      
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Google AdSense (static head tag; not managed by Inertia) -->
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1145262174285661" crossorigin="anonymous"></script>

    <!-- Vite directives -->
    @viteReactRefresh

    @if (app()->environment('local') && file_exists(base_path('public/hot')))
        <!-- Development mode - Vite dev server is running -->
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @else
        <!-- Production mode - Use built assets -->
        @php
            $manifestPath = public_path('build/manifest.json');
            $viteManifestPath = public_path('build/.vite/manifest.json');

            if (file_exists($manifestPath)) {
                $manifest = json_decode(file_get_contents($manifestPath), true);
            } elseif (file_exists($viteManifestPath)) {
                $manifest = json_decode(file_get_contents($viteManifestPath), true);
            }
        @endphp

        @if (isset($manifest['resources/js/app.tsx']))
            <script type="module" src="{{ asset('build/' . $manifest['resources/js/app.tsx']['file']) }}"></script>
            @if (isset($manifest['resources/js/app.tsx']['css']))
                @foreach ($manifest['resources/js/app.tsx']['css'] as $css)
                    <link rel="stylesheet" href="{{ asset('build/' . $css) }}">
                @endforeach
            @endif
        @endif

        @if (isset($manifest['resources/css/app.css']))
            <link rel="stylesheet" href="{{ asset('build/' . $manifest['resources/css/app.css']['file']) }}">
        @endif
    @endif

    @routes
    @inertiaHead
</head>

<body>
    @inertia
</body>

</html>
