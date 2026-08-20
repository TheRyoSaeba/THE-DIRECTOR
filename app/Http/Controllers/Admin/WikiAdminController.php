<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WikiCategory;
use App\Models\WikiPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WikiAdminController extends Controller
{
    // ─── Categories ──────────────────────────────────────────────────────

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:80',
            'slug'        => 'nullable|string|max:64|regex:/^[a-z0-9-]+$/',
            'description' => 'nullable|string|max:200',
            'sort_order'  => 'nullable|integer|min:0|max:9999',
        ]);

        $data['slug']       = Str::slug($data['slug'] ?: $data['name']);
        $data['sort_order'] = $data['sort_order'] ?? 100;

        WikiCategory::create($data);
        $this->flushCache();

        return back()->with('success', 'Category created.');
    }

    public function updateCategory(Request $request, WikiCategory $category)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:80',
            'slug'        => 'required|string|max:64|regex:/^[a-z0-9-]+$/|unique:wiki_categories,slug,' . $category->id,
            'description' => 'nullable|string|max:200',
            'sort_order'  => 'nullable|integer|min:0|max:9999',
        ]);

        $category->update($data);
        $this->flushCache();

        return back()->with('success', 'Category updated.');
    }

    public function destroyCategory(WikiCategory $category)
    {
        $category->delete(); // cascade deletes pages
        $this->flushCache();
        return back()->with('success', 'Category and all its pages deleted.');
    }

    // ─── Pages ───────────────────────────────────────────────────────────

    public function storePage(Request $request)
    {
        $data = $this->validatePage($request, null);
        $data['updated_by_id'] = $request->user()->id;

        if ($request->boolean('publish')) {
            $data['published_at'] = now();
        }

        $page = WikiPage::create($data);
        $this->flushCache();

        // Defend against a category vanishing between validate() and now (race or
        // manual delete). Fall back to the wiki index rather than crashing.
        $cat = WikiCategory::find($page->category_id);
        if (! $cat) {
            return redirect()->route('wiki.index')->with('success', 'Page created.');
        }

        return redirect()
            ->route('wiki.show', ['category' => $cat->slug, 'page' => $page->slug])
            ->with('success', 'Page created.');
    }

    public function updatePage(Request $request, WikiPage $page)
    {
        $data = $this->validatePage($request, $page);
        $data['updated_by_id'] = $request->user()->id;

        if ($request->has('publish')) {
            $data['published_at'] = $request->boolean('publish') ? ($page->published_at ?: now()) : null;
        }

        $page->update($data);
        $this->flushCache();

        // Redirect to the page's CURRENT canonical URL — the slug or category
        // may have changed via the form, so back() would land on a stale URL.
        $cat = WikiCategory::find($page->category_id);
        if (! $cat) {
            return redirect()->route('wiki.index')->with('success', 'Page saved.');
        }
        return redirect()
            ->route('wiki.show', ['category' => $cat->slug, 'page' => $page->slug])
            ->with('success', 'Page saved.');
    }

    public function destroyPage(WikiPage $page)
    {
        $page->delete();
        $this->flushCache();
        return redirect()->route('wiki.index')->with('success', 'Page deleted.');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function validatePage(Request $request, ?WikiPage $existing): array
    {
        $slugRule = 'required|string|max:80|regex:/^[a-z0-9-]+$/';
        $data = $request->validate([
            'category_id'         => 'required|exists:wiki_categories,id',
            'slug'                => $slugRule,
            'title'               => 'required|string|max:120',
            'lede'                => 'nullable|string|max:250',
            'body_markdown'       => 'required|string|max:60000',
            'is_landing_featured' => 'sometimes|boolean',
            'sort_order'          => 'nullable|integer|min:0|max:9999',
        ]);

        // Enforce unique (category_id, slug) manually so we can scope the rule.
        $clash = WikiPage::where('category_id', $data['category_id'])
            ->where('slug', $data['slug'])
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->exists();
        if ($clash) {
            abort(422, 'A page with that slug already exists in this category.');
        }

        $data['sort_order']          = $data['sort_order'] ?? 100;
        $data['is_landing_featured'] = (bool) ($data['is_landing_featured'] ?? false);

        return $data;
    }

    private function flushCache(): void
    {
        Cache::forget('wiki.landing.v5');
        Cache::forget('wiki.nav.admin');
        Cache::forget('wiki.nav.public');
    }
}
