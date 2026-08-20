{{-- Inline pencil-toggle editor, shown when the parent x-data has `editing: true`.
     The toolbar + form post to the existing admin update endpoint
     (route('admin.wiki.pages.update')). Pure form submit — no XHR. --}}
@php
    // A complete worked example — the "Hospital" page from the mediawiki
    // reference admins were shown originally. One click drops this into an
    // empty body so authors immediately see how the image-beside-heading
    // layout works in practice. Same text the old React editor used.
    $hospitalTemplate = <<<'MD'
The Health Services are important as players need them to heal, or implant their bionics. You can also get a sex change, or get cured from your drug habit by paying a fee at the local Hospital. Police officers require DNA sample testing, and some people may request to get that awful tattoo removed.

## Hospital Earns

- Nurse at a Local Hospital
- Doctor at a Local Hospital
- Surgeon
- Hospital Director

## Nurse
![Nurse](https://i.postimg.cc/nLbYKvvW/Makimura.webp)

Nurses can begin the rounds of the wards. It will take around 130 earns to reach the next rank. Starting at nurse rank, hospital staff can cure the flu. The patient will have to apply (for surgery) to get it cured.

## Doctor
![Doctor](https://i.postimg.cc/nLbYKvvW/Makimura.webp)

Doctors are able to help players recover from injury. To do so, head to the Hospital and it will list what injuries or operations players have requested. Clicking the link to the right of these will perform the operation, and you will gain a cut of the fees they have paid to have it done.

## Surgeon
![Surgeon](https://i.postimg.cc/nLbYKvvW/Makimura.webp)

Surgeons are able to perform operations on players. To do so, head to the Hospital and it will list what injuries or operations players have requested. It will take around 250 earns to reach the next rank.

## Hospital Director
![Hospital Director](https://i.postimg.cc/nLbYKvvW/Makimura.webp)

The Hospital Director can set the fees for the operations, and collect the profits from these, as well as the generated $50,000. These can all be done at the business management menu. The Hospital can be robbed. There is 1 Hospital Director per city.
MD;
@endphp

<div x-show="editing" x-cloak>
    <form
        method="POST"
        action="{{ route('admin.wiki.pages.update', ['page' => $page->id]) }}"
        x-data="{
            tab: 'edit',
            legendOpen: false,
            title: @js($page->title),
            slug: @js($page->slug),
            lede: @js($page->lede ?? ''),
            body: @js($page->body_markdown),
            categoryId: @js($page->category_id),
            hospitalTemplate: @js($hospitalTemplate),
            insertExample() {
                if (this.body.trim() && !confirm('Replace the current body with the Hospital template? Your unsaved text will be lost.')) return;
                this.body = this.hospitalTemplate;
            },
        }"
    >
        @csrf
        {{-- These hidden inputs back the visible x-model controls so a plain
             form POST gets the latest values. --}}
        <input type="hidden" name="category_id" :value="categoryId">
        <input type="hidden" name="slug" :value="slug">
        <input type="hidden" name="title" :value="title">
        <input type="hidden" name="lede" :value="lede">
        <input type="hidden" name="body_markdown" :value="body">
        <input type="hidden" name="sort_order" value="100">

        {{-- Sticky toolbar --}}
        <div class="sticky top-14 -mx-6 px-6 py-3 bg-white border-b border-[#e7e5dd] z-40 flex items-center gap-3 flex-wrap">
            <button type="button" @click="editing = false" class="flex items-center gap-1.5 px-3 py-1.5 rounded text-[13px] font-semibold text-[#71717a] hover:text-[#18181b] hover:bg-[#f3f3ee]">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                Cancel
            </button>

            <button
                type="button"
                @click="legendOpen = !legendOpen"
                class="flex items-center gap-1.5 px-3 py-1.5 rounded text-[12px] font-semibold text-[#52525b] hover:text-[#0e7490] hover:bg-[#ecfeff] transition-colors"
            >
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" x-show="!legendOpen"><polyline points="9 18 15 12 9 6"></polyline></svg>
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" x-show="legendOpen" x-cloak><polyline points="6 9 12 15 18 9"></polyline></svg>
                Syntax
            </button>

            <button
                type="button"
                @click="insertExample()"
                class="px-3 py-1.5 rounded text-[12px] font-semibold text-[#52525b] hover:text-[#0e7490] hover:bg-[#ecfeff] transition-colors"
                title="Insert the Hospital example into the body"
            >
                Insert example
            </button>

            <div class="ml-auto flex items-center gap-2">
                {{-- Delete: separate form so it doesn't accidentally submit the edit form. --}}
                <button
                    type="button"
                    @click="if (confirm('Delete this page? This cannot be undone.')) document.getElementById('wiki-delete-form').submit()"
                    title="Delete page"
                    class="p-2 rounded text-[#71717a] hover:text-rose-600 hover:bg-rose-50"
                >
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6M14 11v6"></path></svg>
                </button>

                {{-- Save draft: doesn't set `publish`, so the page stays unpublished. --}}
                <button
                    type="submit"
                    name="publish"
                    value="0"
                    class="px-3 py-1.5 rounded-md text-[13px] font-semibold text-[#52525b] border border-[#d6d3c7] hover:bg-[#fafaf7]"
                >
                    Save draft
                </button>
                {{-- Save & publish: sets publish=1 so the backend marks published_at. --}}
                <button
                    type="submit"
                    name="publish"
                    value="1"
                    class="flex items-center gap-1.5 px-4 py-1.5 rounded-md text-[13px] font-bold bg-[#0e7490] text-white hover:bg-[#155e75]"
                >
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                    Save &amp; Publish
                </button>
            </div>
        </div>

        {{-- Meta fields --}}
        <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 mt-6 mb-4">
            <div class="sm:col-span-4">
                <label class="block text-[11px] font-bold uppercase tracking-wider text-[#71717a] mb-1">Category</label>
                <select
                    x-model="categoryId"
                    class="w-full bg-white border border-[#d6d3c7] rounded px-3 py-2 text-[14px] text-[#18181b] focus:outline-none focus:border-[#0e7490]"
                >
                    @foreach ($nav as $c)
                        <option value="{{ $c['id'] }}">{{ $c['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-8">
                <label class="block text-[11px] font-bold uppercase tracking-wider text-[#71717a] mb-1">Slug</label>
                <input
                    x-model="slug"
                    @input="slug = slug.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')"
                    class="w-full bg-white border border-[#d6d3c7] rounded px-3 py-2 text-[14px] font-mono text-[#18181b] focus:outline-none focus:border-[#0e7490]"
                >
            </div>
        </div>

        <div class="mb-3">
            <label class="block text-[11px] font-bold uppercase tracking-wider text-[#71717a] mb-1">Title</label>
            <input
                x-model="title"
                class="w-full bg-white border border-[#d6d3c7] rounded px-3 py-2 text-[18px] font-semibold text-[#18181b] focus:outline-none focus:border-[#0e7490]"
            >
        </div>

        <div class="mb-4">
            <label class="block text-[11px] font-bold uppercase tracking-wider text-[#71717a] mb-1">Lede (one-line summary)</label>
            <input
                x-model="lede"
                placeholder="Optional short description under the title"
                class="w-full bg-white border border-[#d6d3c7] rounded px-3 py-2 text-[14px] text-[#18181b] focus:outline-none focus:border-[#0e7490] placeholder:text-[#a1a1aa]"
            >
        </div>

        {{-- Syntax legend — toggle from the toolbar Syntax button. --}}
        <div x-show="legendOpen" x-cloak class="mb-4 p-5 bg-[#fdfdf9] border border-[#e7e5dd] rounded-lg text-[13px] text-[#52525b] leading-[1.6]">
            <h3 class="text-[14px] font-bold text-[#18181b] mb-3">How wiki pages are built</h3>
            <p class="mb-4">
                Pages are written in <strong>Markdown</strong>, NOT BBCode.
                Each <code class="font-mono bg-white border border-[#e7e5dd] text-[#0e7490] px-1.5 py-px rounded text-[11.5px]">## Heading</code> starts
                a new section. If the next non-blank line is a standalone image
                (<code class="font-mono bg-white border border-[#e7e5dd] text-[#0e7490] px-1.5 py-px rounded text-[11.5px]">![alt](url)</code>),
                that image becomes the section's left-side thumbnail — the
                Hospital layout. Pages without any section images render as
                flowing prose, which is right for FAQ-style content.
            </p>

            <p class="mb-4 px-3 py-2 bg-amber-50 border border-amber-200 rounded text-amber-900 text-[12px]">
                <strong>BBCode tags do not work here.</strong>
                <code class="font-mono bg-white border border-amber-300 text-amber-800 px-1 rounded text-[11px]">[img]url[/img]</code>
                is forum syntax and renders as literal text on the wiki. Use
                <code class="font-mono bg-white border border-amber-300 text-amber-800 px-1 rounded text-[11px]">![alt](url)</code> instead.
            </p>

            <div class="grid sm:grid-cols-2 gap-4 mb-5">
                <div>
                    <div class="text-[11px] font-bold uppercase tracking-wider text-[#71717a] mb-1.5">You write</div>
                    <pre class="bg-white border border-[#e7e5dd] rounded p-3 text-[12px] font-mono text-[#18181b] leading-[1.55] overflow-x-auto whitespace-pre-wrap">## Nurse
![Nurse](https://i.postimg.cc/.../nurse.png)

Nurses can begin the rounds of the
wards. It will take around 130 earns
to reach the next rank.</pre>
                </div>
                <div>
                    <div class="text-[11px] font-bold uppercase tracking-wider text-[#71717a] mb-1.5">You get</div>
                    <div class="grid gap-3 p-3 bg-white border border-[#e7e5dd] rounded" style="grid-template-columns: 80px 1fr;">
                        <div class="w-[80px] h-[80px] bg-[#f3f3ee] border border-[#d6d3c7] rounded-[8px]"></div>
                        <div>
                            <div class="text-[15px] font-bold text-[#18181b] leading-tight mb-1">Nurse</div>
                            <div class="text-[12px] text-[#52525b]">Nurses can begin the rounds of the wards…</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-[#e7e5dd]">
                <div class="text-[11px] font-bold uppercase tracking-wider text-[#71717a] mb-2">Quick reference</div>
                <table class="w-full text-[12px]">
                    <tbody>
                        <tr><td class="py-1 pr-4 w-[40%] align-top"><code class="font-mono bg-white border border-[#e7e5dd] text-[#0e7490] px-1.5 py-px rounded text-[11.5px]">## Section heading</code></td><td class="py-1">Starts a new section. Becomes a row in the page.</td></tr>
                        <tr><td class="py-1 pr-4 align-top"><code class="font-mono bg-white border border-[#e7e5dd] text-[#0e7490] px-1.5 py-px rounded text-[11.5px]">![alt](https://…)</code></td><td class="py-1">If placed right under a <code class="font-mono bg-white border border-[#e7e5dd] text-[#0e7490] px-1 rounded text-[11px]">##</code>, becomes that section's left thumbnail (120&times;120). Anywhere else, it's an inline image.</td></tr>
                        <tr><td class="py-1 pr-4 align-top"><code class="font-mono bg-white border border-[#e7e5dd] text-[#0e7490] px-1.5 py-px rounded text-[11.5px]">**bold**</code> / <code class="font-mono bg-white border border-[#e7e5dd] text-[#0e7490] px-1.5 py-px rounded text-[11.5px]">*italic*</code></td><td class="py-1">Bold and italic text.</td></tr>
                        <tr><td class="py-1 pr-4 align-top"><code class="font-mono bg-white border border-[#e7e5dd] text-[#0e7490] px-1.5 py-px rounded text-[11.5px]">- item</code></td><td class="py-1">Bulleted list. Use <code class="font-mono bg-white border border-[#e7e5dd] text-[#0e7490] px-1 rounded text-[11px]">1.</code> for numbered.</td></tr>
                        <tr><td class="py-1 pr-4 align-top"><code class="font-mono bg-white border border-[#e7e5dd] text-[#0e7490] px-1.5 py-px rounded text-[11.5px]">[label](https://…)</code></td><td class="py-1">A link.</td></tr>
                        <tr><td class="py-1 pr-4 align-top"><code class="font-mono bg-white border border-[#e7e5dd] text-[#0e7490] px-1.5 py-px rounded text-[11.5px]">&gt; quote</code></td><td class="py-1">Blockquote.</td></tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-4 text-[12px] text-[#71717a]">
                Tip: host images on <a href="https://postimg.cc" target="_blank" rel="noreferrer" class="text-[#0e7490] underline">postimg.cc</a> or any
                public host, then paste the direct image URL into
                <code class="font-mono bg-white border border-[#e7e5dd] text-[#0e7490] px-1 rounded text-[11px]">![alt](URL)</code>.
                Click <strong>Insert example</strong> above to drop a complete
                worked example into the body.
            </p>
        </div>

        {{-- Body textarea. Live preview removed — preview requires server-side
             markdown, which would need an XHR. Save & view = the preview. --}}
        <textarea
            x-model="body"
            rows="28"
            class="w-full bg-white border border-[#d6d3c7] rounded-lg px-4 py-3 text-[14px] font-mono text-[#18181b] focus:outline-none focus:border-[#0e7490] leading-[1.6]"
        ></textarea>
    </form>

    {{-- Standalone delete form — kept outside the edit form so the trash
         button doesn't also submit the page-update payload. --}}
    <form
        id="wiki-delete-form"
        method="POST"
        action="{{ route('admin.wiki.pages.destroy', ['page' => $page->id]) }}"
        class="hidden"
    >
        @csrf
        @method('DELETE')
    </form>
</div>
