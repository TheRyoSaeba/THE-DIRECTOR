<aside class="hidden lg:block sticky top-14 self-start max-h-[calc(100vh-3.5rem)] overflow-y-auto py-8 pr-3" style="scrollbar-width: thin;">
    <nav x-data="{ openNewPageFor: null }">
        @foreach ($nav as $cat)
            <div class="mb-7 group/cat">
                <div class="px-2 mb-2 flex items-center justify-between">
                    <div class="text-[11px] font-bold uppercase tracking-[0.06em] text-[#71717a]">
                        {{ $cat['name'] }}
                    </div>
                    @if ($isAdmin)
                        <button
                            @click="openNewPageFor = openNewPageFor === {{ $cat['id'] }} ? null : {{ $cat['id'] }}"
                            title="Add page to {{ $cat['name'] }}"
                            class="opacity-0 group-hover/cat:opacity-100 text-[#a1a1aa] hover:text-[#0e7490] transition-all"
                        >
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </button>
                    @endif
                </div>

                @if ($isAdmin)
                    <div x-show="openNewPageFor === {{ $cat['id'] }}" x-cloak class="ml-2 mb-2 p-2 bg-white border border-[#e7e5dd] rounded-md shadow-sm">
                        <form method="POST" action="{{ route('admin.wiki.pages.store') }}" x-data="{ title: '' }">
                            @csrf
                            <input type="hidden" name="category_id" value="{{ $cat['id'] }}">
                            <input type="hidden" name="body_markdown" value="# New page&#10;&#10;Start writing…">
                            <input type="hidden" name="sort_order" value="100">
                            {{-- slug derived from title on the server side via Str::slug if blank --}}
                            <input type="hidden" name="slug" :value="title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 80)">

                            <input
                                x-model="title"
                                name="title"
                                placeholder="Page title"
                                required
                                class="w-full px-2 py-1 text-[13px] bg-[#fafaf7] border border-[#e7e5dd] rounded focus:outline-none focus:border-[#0e7490] text-[#18181b] placeholder:text-[#a1a1aa]"
                            >
                            <div class="flex gap-1 mt-2">
                                <button type="button" @click="openNewPageFor = null" class="flex-1 px-2 py-1 text-[11px] font-semibold text-[#71717a] hover:text-[#18181b]">
                                    Cancel
                                </button>
                                <button type="submit" x-bind:disabled="!title.trim()" class="flex-1 px-2 py-1 text-[11px] font-bold bg-[#0e7490] text-white rounded hover:bg-[#155e75] disabled:opacity-40 disabled:cursor-not-allowed">
                                    Create
                                </button>
                            </div>
                        </form>
                    </div>
                @endif

                @if (count($cat['pages']) === 0)
                    <div class="ml-2 px-2 text-[12px] text-[#a1a1aa] italic">No pages yet</div>
                @endif

                @foreach ($cat['pages'] as $p)
                    @php
                        $isActive = ($activeCategorySlug === $cat['slug']) && ($activePageSlug === $p['slug']);
                        $isDraft  = ! ($p['is_published'] ?? true);
                    @endphp
                    <a
                        href="{{ $p['url'] }}"
                        class="flex items-center justify-between gap-2 ml-2 px-2 py-1 text-[13px] rounded-md border-l-2 no-underline transition-colors {{
                            $isActive
                                ? 'text-[#0e7490] border-[#0e7490] font-semibold bg-[#ecfeff]'
                                : 'text-[#52525b] border-transparent hover:bg-[#f3f3ee] hover:text-[#18181b]'
                        }}"
                    >
                        {{-- Wrap, don't truncate — long titles like "Bank &
                            Certificates" or "Businesses & Ownership" would
                            otherwise lose the second word in the 240px rail. --}}
                        <span class="leading-tight">{{ $p['title'] }}</span>
                        @if ($isDraft)
                            <span class="shrink-0 text-[9px] font-bold uppercase tracking-wider px-1 py-px rounded bg-amber-100 text-amber-700 border border-amber-200">
                                Draft
                            </span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>

    @if ($isAdmin)
        <div x-data="{ open: false }" class="mt-8 border-t border-[#e7e5dd] pt-4">
            <template x-if="!open">
                <button @click="open = true" class="flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-semibold text-[#0e7490] hover:bg-[#ecfeff] rounded-md transition-colors">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    New category
                </button>
            </template>
            <div x-show="open" x-cloak class="ml-2 p-2 bg-white border border-[#e7e5dd] rounded-md shadow-sm">
                <form method="POST" action="{{ route('admin.wiki.categories.store') }}" x-data="{ name: '' }">
                    @csrf
                    <input type="hidden" name="sort_order" value="100">
                    <input type="hidden" name="slug" :value="name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 64)">

                    <input
                        x-model="name"
                        name="name"
                        placeholder="Category name"
                        required
                        class="w-full px-2 py-1 text-[13px] bg-[#fafaf7] border border-[#e7e5dd] rounded focus:outline-none focus:border-[#0e7490] text-[#18181b] placeholder:text-[#a1a1aa]"
                    >
                    <div class="flex gap-1 mt-2">
                        <button type="button" @click="open = false" class="flex-1 px-2 py-1 text-[11px] font-semibold text-[#71717a] hover:text-[#18181b]">
                            Cancel
                        </button>
                        <button type="submit" x-bind:disabled="!name.trim()" class="flex-1 px-2 py-1 text-[11px] font-bold bg-[#0e7490] text-white rounded hover:bg-[#155e75] disabled:opacity-40 disabled:cursor-not-allowed">
                            Create
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</aside>

<style>
    [x-cloak] { display: none !important; }
</style>
