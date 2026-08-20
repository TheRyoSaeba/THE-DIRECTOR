@extends('wiki.layout', [
    'title'              => $page->title,
    'nav'                => $nav,
    'isAdmin'            => $isAdmin,
    'activeCategorySlug' => $category->slug,
    'activePageSlug'     => $page->slug,
    'showSidebar'        => true,
    'showToc'            => true,
    'toc'                => $toc,
])

@section('content')
<div x-data="{ editing: false }">

    {{-- ────────────── READ MODE ────────────── --}}
    <div x-show="!editing">

        {{-- Breadcrumb --}}
        <div class="text-[12px] text-[#71717a] flex items-center gap-2 mb-3">
            <a href="{{ route('wiki.index') }}" class="text-[#71717a] font-medium no-underline hover:text-[#0e7490]">Wiki</a>
            <span class="text-[#a1a1aa]">/</span>
            <span class="font-medium">{{ $category->name }}</span>
            <span class="text-[#a1a1aa]">/</span>
            <span>{{ $page->title }}</span>
        </div>

        <div class="flex items-start justify-between gap-4 mb-3">
            <h1 class="text-[36px] font-bold tracking-[-0.02em] leading-[1.15] text-[#18181b]">
                {{ $page->title }}
            </h1>
            @if ($isAdmin)
                <button
                    @click="editing = true"
                    title="Edit this page"
                    class="flex items-center gap-1.5 px-3 py-2 mt-2 rounded-md bg-white border border-[#d6d3c7] text-[#52525b] text-[12px] font-semibold hover:bg-[#fafaf7] hover:border-[#0e7490] hover:text-[#0e7490] transition-colors no-underline shrink-0"
                >
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    Edit
                </button>
            @endif
        </div>

        @if ($page->lede)
            <p class="text-[17px] text-[#52525b] leading-[1.55] mb-6 max-w-[65ch]">{{ $page->lede }}</p>
        @endif

        <div class="flex items-center gap-4 text-[12px] text-[#71717a] pb-6 mb-8 border-b border-[#e7e5dd]">
            @if (! $page->published_at)
                <span class="font-mono text-[11px] uppercase tracking-wider px-2 py-0.5 rounded bg-amber-100 text-amber-800 border border-amber-200">Draft</span>
            @endif
            @if ($page->updated_at)
                <span>
                    Last updated {{ $page->updated_at->diffForHumans() }}
                    @if ($page->updatedBy)
                        {{-- Prefer the in-game display_name (same as the landing
                            staff grid). Falls back to the User's username if
                            the editor has no character (rare for admins). --}}
                        by <span class="text-[#0e7490] font-medium">{{ $page->updatedBy->character?->display_name ?? $page->updatedBy->username }}</span>
                    @endif
                </span>
            @endif
        </div>

        {{-- Article fills the middle column width. Prose readability is
             enforced inside .wiki-prose (p/li get their own max-width).
             Tile grids get to use the extra space at wide viewports. --}}
        <article class="wiki-prose">
            @if ($hasGroups)
                {{-- GroupLayout: bold-line group dividers split sections into
                     horizontal rows. Each group renders its label as a header,
                     then a 2-column grid of compact image-rail tiles. Each
                     tile keeps the existing image-left / text-right shape,
                     just narrower because two share the row.
                     Sections with no group (the leading prose before any
                     **Group**) render first as full-width context. --}}
                @php
                    $ungrouped = collect($sections)->filter(fn ($s) => empty($s['group']))->values();
                    $groupedBuckets = collect($sections)
                        ->filter(fn ($s) => ! empty($s['group']))
                        ->groupBy('group');
                @endphp

                {{-- Ungrouped prose / sections at the top (intro paragraphs etc.) --}}
                @foreach ($ungrouped as $s)
                    @if ($s['heading'] === null)
                        <div class="mb-6">{!! $s['body_html'] !!}</div>
                    @else
                        <section class="grid gap-6 py-6 border-t border-[#e7e5dd] first-of-type:border-t-0 first-of-type:pt-4" style="grid-template-columns: 120px 1fr;">
                            @if ($s['image_url'])
                                <img src="{{ $s['image_url'] }}" alt="{{ $s['heading'] }}" class="w-[120px] h-[120px] object-cover bg-[#f3f3ee] border border-[#d6d3c7] rounded-[10px] shadow-sm">
                            @else
                                <div class="w-[120px] h-[120px] bg-[#f3f3ee] border border-[#d6d3c7] rounded-[10px]"></div>
                            @endif
                            <div class="min-w-0">
                                <h2 id="{{ $s['anchor'] }}" class="text-[24px] font-bold tracking-[-0.015em] text-[#18181b] mb-2 leading-[1.25] scroll-mt-20">{{ $s['heading'] }}</h2>
                                {!! $s['body_html'] !!}
                            </div>
                        </section>
                    @endif
                @endforeach

                {{-- One block per group: header + sections.
                    groupedStyle decides the body shape:
                      - 'stack' → full-width image-rail rows under each
                        group header (Services: many sections, most with
                        no body text, image-rail reads cleanly).
                      - 'tiles' → 2-col auto-fit tile grid (Properties:
                        every section has a paragraph, tiles work). --}}
                @foreach ($groupedBuckets as $groupLabel => $groupSections)
                    <div class="mt-10 first:mt-0">
                        <h2 class="text-[24px] font-bold tracking-[-0.015em] text-[#18181b] mb-4 pb-2 border-b border-[#e7e5dd]">
                            {{ $groupLabel }}
                        </h2>

                        @if (($groupedStyle ?? 'tiles') === 'stack')
                            {{-- Stack layout — full-width image-rail rows,
                                like Corporation. Used by Services. --}}
                            @foreach ($groupSections as $s)
                                <section class="grid gap-6 py-4 border-t border-[#e7e5dd] first-of-type:border-t-0 first-of-type:pt-0" style="grid-template-columns: 120px 1fr;">
                                    @if ($s['image_url'])
                                        <img src="{{ $s['image_url'] }}" alt="{{ $s['heading'] }}" class="w-[120px] h-[120px] object-cover bg-[#f3f3ee] border border-[#d6d3c7] rounded-[10px] shadow-sm">
                                    @else
                                        <div class="w-[120px] h-[120px] bg-[#f3f3ee] border border-[#d6d3c7] rounded-[10px]"></div>
                                    @endif
                                    <div class="min-w-0">
                                        <h3 id="{{ $s['anchor'] }}" class="text-[20px] font-bold tracking-[-0.01em] text-[#18181b] mb-1 leading-[1.25] scroll-mt-20">{{ $s['heading'] }}</h3>
                                        {!! $s['body_html'] !!}
                                    </div>
                                </section>
                            @endforeach
                        @else
                        <div class="grid gap-4 items-start" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
                            @foreach ($groupSections as $s)
                                <section class="bg-white border border-[#e7e5dd] rounded-lg p-4">
                                    @if ($s['image_url'])
                                        {{-- w-36 h-28 = 144×112px box. object-contain
                                            so landscape source images (Bank,
                                            Hospital — ~1280×720 photos) fit
                                            inside without stretching, square
                                            sources (Properties) sit centered.
                                            Previously had `w-38 h-28` — Tailwind
                                            has no `w-38` in its default scale,
                                            so the browser ignored it and the
                                            image rendered at its intrinsic
                                            width scaled to the h-28 height.
                                            That's why landscape photos looked
                                            ~200px wide and square ones ~112px. --}}
                                        <img src="{{ $s['image_url'] }}" alt="{{ $s['heading'] }}" class="block w-36 h-28 object-contain rounded-md bg-[#f3f3ee] border border-[#e7e5dd] mb-3">
                                    @endif
                                    <h3 id="{{ $s['anchor'] }}" class="text-[16px] font-bold tracking-[-0.01em] text-[#18181b] mb-1.5 leading-[1.3] scroll-mt-20">{{ $s['heading'] }}</h3>
                                    <div class="text-[14px] leading-[1.55] text-[#52525b]">
                                        {!! $s['body_html'] !!}
                                    </div>
                                </section>
                            @endforeach
                        </div>
                        @endif
                    </div>
                @endforeach

            @elseif ($hasImages)
                {{-- GuideLayout: hybrid per-section rendering. The layout
                     decision is made section-by-section, not page-wide:

                       1. No image → plain prose, full-width heading + body
                          (no empty 120px grey placeholder rail).
                       2. SVG image → diagram block. Heading + prose stack
                          on top, the SVG renders full-width below. Used
                          for flowcharts and other wide vector art that
                          would be illegible in a 120px column.
                       3. Raster image (PNG/JPG/WEBP/etc.) → traditional
                          120px rail on the left + prose on the right.
                          Used for the property/career photo thumbnails.

                     This means a page with a single SVG diagram (Combat
                     Guide → Damage Outcomes) keeps the other H2 sections
                     looking like clean prose — no phantom rail — while
                     the one diagram section gets the full width it needs.
                --}}
                @foreach ($sections as $s)
                    @if ($s['heading'] === null)
                        <div class="mb-4">{!! $s['body_html'] !!}</div>
                    @else
                        @php
                            $imgUrl = $s['image_url'] ?? null;
                            $isSvg  = $imgUrl && str_ends_with(strtolower(parse_url($imgUrl, PHP_URL_PATH) ?? $imgUrl), '.svg');
                        @endphp

                        @if ($isSvg)
                            {{-- Diagram section: prose flows full-width in the
                                 column (no inner cap — matches every other
                                 section), then a fluid SVG figure below.

                                 Prose: no max-w. The article column already
                                 caps page-width via the layout grid; capping
                                 again here at 80ch left the text looking
                                 stranded next to neighbouring sections that
                                 use the full column. Consistency > "ideal
                                 measure" because the surrounding sections
                                 also exceed 80ch on wide viewports.

                                 SVG: width uses clamp(420px, 60vw, 960px).
                                 At narrow widths the floor keeps it readable;
                                 at wide widths it scales with the viewport
                                 but never exceeds 960px (the diagram is
                                 viewBox-derived 1080×880, so 960px keeps it
                                 just under 1:1 and avoids upscaling artefacts
                                 on vector text). Aligned left (no mx-auto)
                                 so it doesn't look stranded in dead centre
                                 of a 1600px column — sits flush with the
                                 prose left edge. --}}
                            <section class="py-6 border-t border-[#e7e5dd] first-of-type:border-t-0 first-of-type:pt-4">
                                <h2 id="{{ $s['anchor'] }}" class="text-[24px] font-bold tracking-[-0.015em] text-[#18181b] mb-3 leading-[1.25] scroll-mt-20">
                                    {{ $s['heading'] }}
                                </h2>
                                {!! $s['body_html'] !!}
                                <figure class="mt-5" style="width: clamp(420px, 60vw, 960px);">
                                    <img
                                        src="{{ $imgUrl }}"
                                        alt="{{ $s['heading'] }}"
                                        class="block w-full h-auto bg-white border border-[#e7e5dd] rounded-[10px] shadow-sm"
                                    >
                                </figure>
                            </section>
                        @elseif ($imgUrl)
                            {{-- Photo rail section: 120px image left, prose right. --}}
                            <section class="grid gap-6 py-6 border-t border-[#e7e5dd] first-of-type:border-t-0 first-of-type:pt-4" style="grid-template-columns: 120px 1fr;">
                                <img src="{{ $imgUrl }}" alt="{{ $s['heading'] }}" class="w-[120px] h-[120px] object-cover bg-[#f3f3ee] border border-[#d6d3c7] rounded-[10px] shadow-sm">
                                <div class="min-w-0">
                                    <h2 id="{{ $s['anchor'] }}" class="text-[24px] font-bold tracking-[-0.015em] text-[#18181b] mb-2 leading-[1.25] scroll-mt-20">
                                        {{ $s['heading'] }}
                                    </h2>
                                    {!! $s['body_html'] !!}
                                </div>
                            </section>
                        @else
                            {{-- No image: full-width prose, NO grey placeholder. --}}
                            <section class="py-6 border-t border-[#e7e5dd] first-of-type:border-t-0 first-of-type:pt-4">
                                <h2 id="{{ $s['anchor'] }}" class="text-[24px] font-bold tracking-[-0.015em] text-[#18181b] mb-2 leading-[1.25] scroll-mt-20">
                                    {{ $s['heading'] }}
                                </h2>
                                {!! $s['body_html'] !!}
                            </section>
                        @endif
                    @endif
                @endforeach
            @else
                {{-- ProseLayout: plain flowing prose with H2 headings. --}}
                @foreach ($sections as $s)
                    <section class="mb-6">
                        @if ($s['heading'])
                            <h2 id="{{ $s['anchor'] }}" class="text-[24px] font-bold tracking-[-0.015em] text-[#18181b] mt-8 mb-3 leading-[1.25] first:mt-0 scroll-mt-20">
                                {{ $s['heading'] }}
                            </h2>
                        @endif
                        {!! $s['body_html'] !!}
                    </section>
                @endforeach
            @endif
        </article>

        @if ($prev || $next)
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-12 pt-6 border-t border-[#e7e5dd]">
                @if ($prev)
                    <a href="{{ route('wiki.show', ['category' => $category->slug, 'page' => $prev->slug]) }}" class="block p-4 border border-[#e7e5dd] rounded-lg bg-white no-underline hover:border-[#0e7490]">
                        <div class="text-[11px] font-bold tracking-[0.06em] uppercase text-[#71717a] mb-1.5">← Previous</div>
                        <div class="text-[16px] font-semibold text-[#18181b]">{{ $prev->title }}</div>
                    </a>
                @else
                    <div></div>
                @endif
                @if ($next)
                    <a href="{{ route('wiki.show', ['category' => $category->slug, 'page' => $next->slug]) }}" class="block p-4 border border-[#e7e5dd] rounded-lg bg-white no-underline hover:border-[#0e7490] text-right">
                        <div class="text-[11px] font-bold tracking-[0.06em] uppercase text-[#71717a] mb-1.5">Next →</div>
                        <div class="text-[15px] font-semibold text-[#18181b]">{{ $next->title }}</div>
                    </a>
                @else
                    <div></div>
                @endif
            </div>
        @endif
    </div>

    {{-- ────────────── EDIT MODE ────────────── --}}
    @if ($isAdmin)
        @include('wiki.partials.editor', ['page' => $page, 'nav' => $nav])
    @endif
</div>

{{-- .wiki-prose styles live in wiki/layout.blade.php so the landing page's
     intro section can share them. --}}
@endsection
