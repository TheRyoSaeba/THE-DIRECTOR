@extends('wiki.layout', [
    'title'      => 'Wiki',
    'nav'        => $nav,
    'isAdmin'    => $isAdmin,
    'showSidebar' => true,
])

@section('content')
    {{-- Hero (slim — no body, body lives in its own "Introduction" section
         below the category grid). Admin pencil deep-links to the same
         _meta/landing editor. --}}
    <section class="relative text-center py-12 mb-10 border-b border-[#e7e5dd]">
        @if ($isAdmin && $landing['edit_url'])
            <a
                href="{{ $landing['edit_url'] }}"
                title="Edit landing hero & intro"
                class="absolute top-2 right-0 flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-white border border-[#d6d3c7] text-[#52525b] text-[12px] font-semibold no-underline hover:border-[#0e7490] hover:text-[#0e7490] transition-colors"
            >
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                Edit hero
            </a>
        @endif

        <p class="text-[12px] font-semibold tracking-[0.1em] uppercase text-[#0e7490] mb-4">
            The Director Wiki
        </p>
        <h1 class="text-4xl sm:text-[44px] font-bold tracking-tight leading-[1.05] text-[#18181b] mb-4">
            {{ $landing['title'] }}
        </h1>
        @if ($landing['lede'])
            <p class="text-[17px] text-[#52525b] max-w-[640px] mx-auto leading-[1.6]">
                {{ $landing['lede'] }}
            </p>
        @endif
    </section>

    {{-- Introduction — admin-editable via the landing page's body_markdown.
         Renders only when there's text; nothing for empty + nothing for
         admin placeholders. Left-aligned with the main column. --}}
    @if (!empty($landing['body_html']))
        <section class="mb-8 text-[15px] text-[#18181b] leading-[1.6] wiki-prose">
            {!! $landing['body_html'] !!}
        </section>
    @endif

    {{-- Start-here cards — hardcoded shortcut to the two pages new players
         hit first. Sits as a follow-up inside the intro section (no header,
         no separator), per user direction. Renders as a 2-column grid that
         collapses to a single column on narrow viewports.

         Card titles use the actual WikiPage title. No subtitle/blurb text:
         the user has explicit rules against me writing copy. If a blurb
         is wanted, set it as the destination page's `lede` and we'll
         surface that here. --}}
    <section class="mb-12 grid gap-4 sm:grid-cols-2">
        <a
            href="{{ route('wiki.show', ['category' => 'getting-started', 'page' => 'new-player-faq']) }}"
            class="block p-5 bg-white border border-[#e7e5dd] rounded-lg no-underline hover:border-[#0e7490] transition-colors"
        >
            <div class="text-[18px] font-semibold text-[#18181b] leading-[1.25]">New Player FAQ</div>
        </a>
        <a
            href="{{ route('wiki.show', ['category' => 'getting-started', 'page' => 'first-24-hours']) }}"
            class="block p-5 bg-white border border-[#e7e5dd] rounded-lg no-underline hover:border-[#0e7490] transition-colors"
        >
            <div class="text-[18px] font-semibold text-[#18181b] leading-[1.25]">Your First 24 Hours</div>
        </a>
    </section>

    {{-- Staff & Contact --}}
    <section>
        <h2 class="text-[24px] font-bold tracking-tight text-[#18181b] mb-1">Staff &amp; Contact</h2>
        <hr class="border-[#e7e5dd] mb-6">

        @if (count($staff) > 0)
            <div class="grid gap-4 mb-8" style="grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));">
                @foreach ($staff as $s)
                    <div class="text-center">
                        @if ($s['avatar_url'])
                            <img
                                src="{{ $s['avatar_url'] }}"
                                alt="{{ $s['name'] }}"
                                class="w-full aspect-square object-cover bg-[#f3f3ee] border border-[#e7e5dd] rounded-[10px] mb-2"
                            >
                        @else
                            <div class="w-full aspect-square bg-[#f3f3ee] border border-[#e7e5dd] rounded-[10px] grid place-items-center text-[#71717a] font-mono text-[14px] font-semibold mb-2">
                                {{ strtoupper(substr($s['name'], 0, 2)) }}
                            </div>
                        @endif
                        <div class="text-[14px] font-semibold text-[#18181b]">{{ $s['name'] }}</div>
                        <div class="text-[12px] text-[#71717a]">{{ $s['role'] }}</div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-[14px] text-[#71717a] italic mb-8">No staff listed.</p>
        @endif

        <div class="flex flex-wrap gap-3">
            <a
                href="https://discord.gg/esGpNynbER"
                target="_blank"
                rel="noreferrer"
                class="flex items-center gap-2.5 px-4 py-2.5 bg-white border border-[#e7e5dd] rounded-lg text-[14px] font-semibold text-[#18181b] no-underline hover:border-[#5865F2] hover:text-[#5865F2] transition-colors"
            >
                <svg width="20" height="20" viewBox="0 0 24 24" fill="#5865F2"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515a.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0a12.64 12.64 0 0 0-.617-1.25a.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057a19.9 19.9 0 0 0 5.993 3.03a.078.078 0 0 0 .084-.028a14.09 14.09 0 0 0 1.226-1.994a.076.076 0 0 0-.041-.106a13.107 13.107 0 0 1-1.872-.892a.077.077 0 0 1-.008-.128a10.2 10.2 0 0 0 .372-.292a.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127a12.299 12.299 0 0 1-1.873.892a.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028a19.839 19.839 0 0 0 6.002-3.03a.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419c0-1.333.956-2.419 2.157-2.419c1.21 0 2.176 1.096 2.157 2.42c0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419c0-1.333.955-2.419 2.157-2.419c1.21 0 2.176 1.096 2.157 2.42c0 1.333-.946 2.418-2.157 2.418z"/></svg>
                <div class="flex flex-col items-start leading-tight">
                    <span>Discord</span>
                    <span class="text-[11px] font-normal text-[#71717a]">discord.gg/esGpNynbER</span>
                </div>
            </a>
            <a
                href="mailto:admin@thedirector.app"
                class="flex items-center gap-2.5 px-4 py-2.5 bg-white border border-[#e7e5dd] rounded-lg text-[14px] font-semibold text-[#18181b] no-underline hover:border-[#0e7490] hover:text-[#0e7490] transition-colors"
            >
                <svg width="20" height="20" viewBox="0 0 24 24" fill="#0e7490"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5l-8-5V6l8 5l8-5v2z"/></svg>
                <div class="flex flex-col items-start leading-tight">
                    <span>Email</span>
                    <span class="text-[11px] font-normal text-[#71717a]">admin@thedirector.app</span>
                </div>
            </a>
        </div>
    </section>
@endsection
