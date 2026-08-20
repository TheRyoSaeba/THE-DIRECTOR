<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WikiCategory;
use App\Models\WikiPage;
use App\Support\SafeCache;
use League\CommonMark\CommonMarkConverter;

class WikiController extends Controller
{
    /**
     * Convert a markdown string to safe HTML using CommonMark.
     * html_input = 'escape' — any literal HTML in the markdown source is
     * escaped, so an admin posting `<script>` renders as text.
     * allow_unsafe_links = false — `javascript:`, `data:` etc. URLs are dropped.
     */
    private static function md(string $body): string
    {
        static $converter = null;
        if ($converter === null) {
            $converter = new CommonMarkConverter([
                'html_input'         => 'escape',
                'allow_unsafe_links' => false,
            ]);
        }
        return (string) $converter->convert($body);
    }


    /**
     * Landing page: hero + staff grid + contact chips. Public read-only.
     * Cached for 5 minutes since it changes rarely and gets hit by every visitor.
     */
    public function index()
    {
        // SafeCache (not Cache) — Redis on Upstash drops connections from
        // time to time; without this wrapper a transient drop turns the
        // whole wiki landing into a 500. SafeCache catches the cache-side
        // exception, runs the resolver directly against the DB, and only
        // returns the empty-shape fallback if the DB query itself also
        // explodes. Same pattern PageController uses for census/messages.
        $data = SafeCache::remember('wiki.landing.v5', 300, function () {
            $staff = User::where('is_admin', true)
                ->with(['character' => fn ($q) => $q
                    ->whereNull('deleted_at')
                    ->with('career'),
                ])
                ->get()
                ->map(function ($u) {
                    $char = $u->character;
                    return [
                        'name'       => $char?->display_name ?? $u->username,
                        'role'       => 'Admin',
                        'avatar_url' => $char?->avatar_url,
                    ];
                })
                ->values()
                ->all();

            // The landing hero lives in a real wiki page: _meta/landing.
            $landingCat  = WikiCategory::where('slug', '_meta')->first();
            $landingPage = $landingCat
                ? WikiPage::where('category_id', $landingCat->id)->where('slug', 'landing')->first()
                : null;

            $landing = [
                'id'        => $landingPage?->id,
                'title'     => $landingPage?->title ?? 'All you need to succeed',
                'lede'      => $landingPage?->lede ?? '',
                'body_html' => $landingPage && trim($landingPage->body_markdown) !== ''
                    ? self::md($landingPage->body_markdown)
                    : '',
                'edit_url'  => $landingPage
                    ? route('wiki.show', ['category' => '_meta', 'page' => 'landing'])
                    : null,
            ];

            return ['staff' => $staff, 'landing' => $landing];
        }, fallback: [
            // Worst-case shape (cache AND db both down): empty staff and a
            // landing block with the same key set the view expects, so
            // wiki/index.blade.php renders without warnings.
            'staff'   => [],
            'landing' => [
                'id'        => null,
                'title'     => 'All you need to succeed',
                'lede'      => '',
                'body_html' => '',
                'edit_url'  => null,
            ],
        ]);

        return view('wiki.index', [
            'staff'   => $data['staff'],
            'landing' => $data['landing'],
            'nav'     => $this->buildNav(),
            'isAdmin' => (bool) (request()->user()?->is_admin ?? false),
        ]);
    }

    /**
     * Article page. Routed as /wiki/{category}/{page} where both are slugs.
     */
    public function show(string $category, string $page)
    {
        $cat   = WikiCategory::where('slug', $category)->firstOrFail();
        $query = WikiPage::where('category_id', $cat->id)->where('slug', $page);

        $isAdmin = (bool) (request()->user()?->is_admin ?? false);
        if (! $isAdmin) {
            $query->whereNotNull('published_at');
        }

        // Eager-load the editor's character so the "last updated by" footer
        // can show the in-game display_name (consistent with how staff are
        // shown on the landing page).
        //
        // withTrashed() on the character is intentional: characters use
        // soft-deletes-as-death, and an admin who died in-game would still
        // want their author credit to render with the character name they
        // edited the page as, not their User email/username.
        $pg = $query->with(['updatedBy' => fn ($q) => $q
            ->select('id', 'username')
            ->with(['character' => fn ($c) => $c
                ->withTrashed()
                ->select('id', 'user_id', 'display_name', 'custom_avatar_url', 'gender', 'career_id', 'career_rank')
            ])
        ])->firstOrFail();

        // Pre-render each section's markdown body into HTML server-side, so
        // the Blade view just echoes the html. Section parser stays in PHP.
        //
        // We also assign a unique slug (`anchor`) per section with a heading,
        // and collect those into a `$toc` array for the right-side
        // "On this page" panel. Slugs are de-duplicated so two sections
        // titled the same don't collide.
        $seen = [];
        $sections = collect($pg->parseSections())->map(function ($s) use (&$seen) {
            $anchor = null;
            if ($s['heading'] !== null && trim($s['heading']) !== '') {
                $base   = \Illuminate\Support\Str::slug($s['heading']) ?: 'section';
                $anchor = $base;
                $n = 1;
                while (isset($seen[$anchor])) { $anchor = $base . '-' . (++$n); }
                $seen[$anchor] = true;
            }
            return [
                'heading'   => $s['heading'],
                'image_url' => $s['image_url'],
                'body_html' => self::md($s['body_markdown']),
                'anchor'    => $anchor,
                'group'     => $s['group'] ?? null,
            ];
        })->all();

        // Whether ANY section sits inside a bold-line group. Drives a
        // different render path in the Blade (grouped rows of horizontally-
        // flowing sections, vs. the existing single-column GuideLayout).
        $hasGroups = collect($sections)->contains(fn ($s) => ! empty($s['group']));

        $toc = collect($sections)
            ->filter(fn ($s) => $s['anchor'] !== null)
            ->map(fn ($s) => ['id' => $s['anchor'], 'text' => $s['heading']])
            ->values()
            ->all();

        // Previous / next within category by sort_order.
        $siblings = WikiPage::published()
            ->where('category_id', $cat->id)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get(['id', 'slug', 'title']);
        $idx = $siblings->search(fn ($p) => $p->id === $pg->id);
        $prev = $idx > 0 ? $siblings[$idx - 1] : null;
        $next = $idx !== false && $idx < $siblings->count() - 1 ? $siblings[$idx + 1] : null;

        // When a grouped page has many sections without substantial bodies
        // (Services: heading + image, nothing else), the tile grid feels
        // empty. When it has rich bodies (Properties: each section has a
        // descriptive paragraph), tiles work well. Hard-coded per-slug
        // override for the small set of pages that exist today.
        $groupedStyle = match ("{$cat->slug}/{$pg->slug}") {
            'cities/services' => 'stack',     // full-width image-rail rows + group headers
            default           => 'tiles',     // 2-col tile grid per group (Properties)
        };

        return view('wiki.show', [
            'category'  => $cat,
            'page'      => $pg,
            'sections'  => $sections,
            'toc'       => $toc,
            'prev'      => $prev,
            'next'      => $next,
            'nav'       => $this->buildNav(),
            'isAdmin'   => $isAdmin,
            // Layout flags. hasGroups takes precedence over hasImages —
            // grouped pages render rows of section tiles (Properties),
            // or as full-width stacked rows with group dividers (Services).
            // groupedStyle picks which. Non-grouped pages fall back to
            // GuideLayout / ProseLayout.
            'hasImages'    => collect($sections)->contains(fn ($s) => ! empty($s['image_url'])),
            'hasGroups'    => $hasGroups,
            'groupedStyle' => $groupedStyle,
        ]);
    }

    /**
     * Sidebar nav: categories with their pages. Admins see drafts too.
     * Cache key splits by audience so admins never poison the public cache.
     */
    private function buildNav(): array
    {
        $isAdmin = request()->user()?->is_admin ?? false;
        $key = $isAdmin ? 'wiki.nav.admin' : 'wiki.nav.public';

        // SafeCache so a Redis hiccup doesn't 500 every wiki page
        // request — sidebar nav falls back to a live query, and only
        // falls back to an empty array if both cache AND db fail.
        return SafeCache::remember($key, 300, function () use ($isAdmin) {
            return WikiCategory::with([
                    'pages' => function ($q) use ($isAdmin) {
                        if (! $isAdmin) {
                            $q->whereNotNull('published_at');
                        }
                        $q->orderBy('sort_order')->orderBy('title');
                    },
                ])
                ->whereNotIn('slug', ['_meta'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn ($cat) => [
                    'id'    => $cat->id,
                    'slug'  => $cat->slug,
                    'name'  => $cat->name,
                    'pages' => $cat->pages->map(fn ($p) => [
                        'id'           => $p->id,
                        'slug'         => $p->slug,
                        'title'        => $p->title,
                        'is_published' => $p->published_at !== null,
                        'url'          => route('wiki.show', ['category' => $cat->slug, 'page' => $p->slug]),
                    ])->values()->all(),
                ])
                ->values()
                ->all();
        }, fallback: []);
    }
}
