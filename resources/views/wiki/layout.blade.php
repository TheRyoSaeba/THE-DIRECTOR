<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? '' }} — The Director Wiki</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css'])

    {{-- Alpine.js for the inline edit toggle + new-page / new-category sidebar forms.
         CDN-loaded so we don't add a JS bundle for what is mostly a static wiki. --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>

    <style>
        /* Hard override: app.css applies a slate gradient to `body` globally,
           which would bleed through the wiki's cream surface. !important
           forces this rule to win the cascade over the bundled stylesheet. */
        body {
            background: #fafaf7 !important;
            color: #18181b;
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
        }

        /* Subtle prose defaults for server-rendered CommonMark output.
           Used on both the landing page (introduction section) and the
           article page (every section body). Centralised here so the size
           knobs live in one place. */
        .wiki-prose p { font-size: 15px; line-height: 1.6; color: #18181b; margin: 0.6rem 0; }
        .wiki-prose strong { font-weight: 600; color: #18181b; }
        .wiki-prose em { font-style: italic; }
        .wiki-prose ul { list-style: disc; padding-left: 1.5rem; margin: 0.85rem 0; }
        .wiki-prose ol { list-style: decimal; padding-left: 1.5rem; margin: 0.85rem 0; }
        .wiki-prose li { margin: 0.25rem 0; font-size: 15px; line-height: 1.6; }
        .wiki-prose a { color: #0e7490; text-decoration: underline; text-underline-offset: 2px; }
        .wiki-prose a:hover { color: #155e75; }
        .wiki-prose code { font-family: ui-monospace, monospace; font-size: 0.88em; background: #f4f4f0; border: 1px solid #e7e5dd; color: #18181b; padding: 0.0625rem 0.375rem; border-radius: 0.25rem; }
        .wiki-prose blockquote { border-left: 2px solid #0e7490; padding-left: 1rem; margin: 0.85rem 0; color: #52525b; font-style: italic; }
        .wiki-prose h3 { font-size: 20px; font-weight: 600; margin-top: 1.75rem; margin-bottom: 0.5rem; color: #18181b; }
        .wiki-prose hr { margin: 1.5rem 0; border-color: #e7e5dd; }
        .wiki-prose img { max-width: 100%; height: auto; }
    </style>
</head>
<body class="min-h-screen">

    {{-- ─── Topbar ──────────────────────────────────────────────────── --}}
    <header class="sticky top-0 z-50 bg-white/85 backdrop-blur-md border-b border-[#e7e5dd]">
        <div class="px-6 h-14 flex items-center gap-6">
            <a href="{{ route('wiki.index') }}" class="flex items-center gap-2 font-bold text-[15px] tracking-tight text-[#18181b] no-underline">
                {{-- Game favicon as the brand mark — same icon the browser tab
                    shows, so it's already familiar. /favicon.ico is served
                    unchanged in prod via Cloudflare/Cloud Run. --}}
                <img src="/favicon.ico" alt="" aria-hidden="true" class="w-[22px] h-[22px] rounded">
                <span>The Director</span>
                <span class="text-[#a1a1aa] font-normal">/</span>
                <span class="font-medium text-[#52525b]">Wiki</span>
            </a>
        </div>
    </header>

    {{-- ─── Body ──────────────────────────────────────────────────────
         Full-bleed layout. The grid spans the entire viewport — sidebar
         hugs the left edge, TOC hugs the right edge, content fills the
         middle. NO outer max-width cap.

         Readability is preserved by capping the ARTICLE PROSE inside the
         middle column (max-w-[80ch] mx-auto inside <main>), not the
         layout shell. So on a 4K monitor the sidebar and TOC stay at
         their fixed widths against the screen edges, and the article
         sits centered in the very wide middle column at a comfortable
         reading width.

         The "no sidebar" variant (landing page edge case if ever toggled)
         still caps because there's no sidebar/TOC to anchor the edges.
    --}}
    @php
        $showToc     = ($showToc ?? false) && (! empty($toc ?? []));
        $showSidebar = ($showSidebar ?? true);
        if ($showToc) {
            $wrapper = 'grid gap-8 lg:grid-cols-[260px_1fr] xl:grid-cols-[260px_1fr_240px]';
        } elseif ($showSidebar) {
            $wrapper = 'grid gap-8 lg:grid-cols-[260px_1fr]';
        } else {
            $wrapper = 'max-w-[1100px] mx-auto px-6';
        }
    @endphp
    <div class="{{ $wrapper }}">
        @if ($showSidebar)
            @include('wiki.partials.sidebar', [
                'nav'                => $nav,
                'activeCategorySlug' => $activeCategorySlug ?? null,
                'activePageSlug'     => $activePageSlug ?? null,
                'isAdmin'            => $isAdmin ?? false,
            ])
        @endif

        {{-- Main column fills its grid cell, no outer cap. Article CHROME
             (breadcrumb, title, hero, prev/next) uses the full width;
             prose paragraphs cap themselves at ~80ch inside .wiki-prose
             so reading stays comfortable on wide monitors. Tile grids
             (Properties, Services) use the full width to flow more
             tiles per row at wider viewports. --}}
        <main class="py-8 px-6 min-w-0">
            {{-- Flash success / error banners — non-Inertia replacement for the toast layer. --}}
            @if (session('success'))
                <div class="mb-6 px-4 py-3 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 text-sm">
                    {{ session('success') }}
                </div>
            @endif
            @if (session('error'))
                <div class="mb-6 px-4 py-3 rounded-lg border border-rose-200 bg-rose-50 text-rose-800 text-sm">
                    {{ session('error') }}
                </div>
            @endif

            @yield('content')
        </main>

        @if ($showToc)
            <aside class="hidden xl:block sticky top-14 self-start max-h-[calc(100vh-3.5rem)] overflow-y-auto py-8 text-[13px]"
                x-data="{
                    active: null,
                    init() {
                        const ids = @js(array_column($toc, 'id'));
                        const els = ids.map(id => document.getElementById(id)).filter(Boolean);
                        if (!els.length) return;
                        const observer = new IntersectionObserver((entries) => {
                            // Highlight the topmost section currently in the upper third of the viewport.
                            const visible = entries.filter(e => e.isIntersecting);
                            if (visible.length) {
                                visible.sort((a, b) => a.target.offsetTop - b.target.offsetTop);
                                this.active = visible[0].target.id;
                            }
                        }, { rootMargin: '-72px 0px -66% 0px', threshold: 0 });
                        els.forEach(el => observer.observe(el));
                    }
                }"
            >
                <div class="text-[11px] font-bold uppercase tracking-[0.06em] text-[#71717a] mb-2.5">On this page</div>
                @foreach ($toc as $item)
                    <a
                        href="#{{ $item['id'] }}"
                        :class="active === @js($item['id']) ? 'text-[#0e7490] border-l-[#0e7490] font-medium' : 'text-[#71717a] border-l-[#e7e5dd] hover:text-[#18181b] hover:border-l-[#71717a]'"
                        class="block py-1 pl-3 border-l-2 no-underline transition-colors leading-[1.4]"
                    >
                        {{ $item['text'] }}
                    </a>
                @endforeach
            </aside>
        @endif
    </div>
</body>
</html>
