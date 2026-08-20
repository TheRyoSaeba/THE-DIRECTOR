<?php

namespace App\Http\Controllers;

use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumReply;
use App\Support\ForumBodySanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ForumController extends Controller
{
    public function data(Request $request)
    {
        $character = $this->requireCharacter($request);

        if (!$character) {
            return response()->json(['error' => 'No character found.'], 403);
        }

        $categories = Cache::remember('forum_categories', 120, function () {
            return ForumCategory::active()
                ->orderBy('sort_order')
                ->get(['id', 'name', 'slug', 'description', 'icon', 'admin_only'])
                ->toArray();
        });

        $posts = ForumPost::with([
            'character:id,display_name,custom_avatar_url,career_id,career_rank,glow_color,user_id',
            'character.user:id,is_admin,username',
        ])
            ->withCount('replies')
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->limit(150)
            ->get()
            ->map(function ($post) {
                return [
                    'id'            => $post->id,
                    'characterId'   => $post->character_id,
                    'categoryId'    => $post->category_id,
                    'title'         => $post->title,
                    'body'          => $post->body,
                    'isPinned'      => $post->is_pinned,
                    'isLocked'      => $post->is_locked,
                    'replyCount'    => $post->replies_count,
                    'createdAt'     => $post->created_at->timestamp,
                    'author'        => $post->character ? $post->character->display_name : 'Deceased',
                    'authorAvatar'  => $post->character?->avatar_url,
                    'authorGlow'    => $post->character?->glow_color ?? 'cyan',
                    'authorRole'    => $post->character?->user?->is_admin ? 'admin' : 'player',
                ];
            });

        return response()->json([
            'categories'          => $categories,
            'posts'               => $posts,
            'is_admin'            => (bool) $request->user()?->is_admin,
            'viewer_character_id' => $character->id,
        ]);
    }

    public function postDetail(Request $request, ForumPost $post)
    {
        $character = $this->requireCharacter($request);

        if (!$character) {
            return response()->json(['error' => 'No character found.'], 403);
        }

        $post->load([
            'replies' => fn($q) => $q->orderBy('created_at'),
            'replies.character:id,display_name,custom_avatar_url,career_id,career_rank,glow_color,user_id',
            'replies.character.user:id,is_admin,username',
        ]);

        return response()->json([
            'replies' => $post->replies->map(function ($reply) {
                return [
                    'id'           => $reply->id,
                    'characterId'  => $reply->character_id,
                    'body'         => $reply->body,
                    'isSolution'   => $reply->is_solution,
                    'createdAt'    => $reply->created_at->timestamp,
                    'author'       => $reply->character ? $reply->character->display_name : 'Deceased',
                    'authorAvatar' => $reply->character?->avatar_url,
                    'authorGlow'   => $reply->character?->glow_color ?? 'cyan',
                    'authorRole'   => $reply->character?->user?->is_admin ? 'admin' : 'player',
                ];
            }),
        ]);
    }

    public function store(Request $request)
    {
        $character = $this->requireCharacter($request);

        if (!$character) {
            return $request->wantsJson()
                ? response()->json(['error' => 'No character found.'], 403)
                : back()->with('error', 'No character found.');
        }

        $validated = $request->validate([
            'category_id' => 'required|integer|exists:forum_categories,id',
            'title'       => 'required|string|min:3|max:50',
            'body'        => 'required|string|min:3|max:5000',
        ]);

        $validated['body'] = ForumBodySanitizer::sanitize(
            $validated['body'],
            (bool) $character->user?->is_admin,
        );

        $category = ForumCategory::findOrFail($validated['category_id']);

        if ($category->admin_only && !$character->user?->is_admin) {
            return $request->wantsJson()
                ? response()->json(['error' => 'This category is restricted to administrators.'], 403)
                : back()->with('error', 'This category is restricted to administrators.');
        }

        ForumPost::create([
            'category_id'  => $validated['category_id'],
            'character_id' => $character->id,
            'title'        => $validated['title'],
            'body'         => $validated['body'],
        ]);

        return $request->wantsJson()
            ? response()->json(['success' => true])
            : back()->with('success', 'Post created.');
    }

    public function reply(Request $request, ForumPost $post)
    {
        $character = $this->requireCharacter($request);

        if (!$character) {
            return $request->wantsJson()
                ? response()->json(['error' => 'No character found.'], 403)
                : back()->with('error', 'No character found.');
        }

        if ($post->is_locked) {
            return $request->wantsJson()
                ? response()->json(['error' => 'This post is locked.'], 422)
                : back()->with('error', 'This post is locked.');
        }

        $validated = $request->validate([
            'body' => 'required|string|min:2|max:1000',
        ]);

        $validated['body'] = ForumBodySanitizer::sanitize(
            $validated['body'],
            (bool) $character->user?->is_admin,
        );

        ForumReply::create([
            'post_id'      => $post->id,
            'character_id' => $character->id,
            'body'         => $validated['body'],
        ]);

        return $request->wantsJson()
            ? response()->json(['success' => true])
            : back()->with('success', 'Reply posted.');
    }

    

    public function editPost(Request $request, ForumPost $post)
    {
        $character = $this->requireCharacter($request);

        if (!$character) {
            return response()->json(['error' => 'No character found.'], 403);
        }

        if ($post->character_id !== $character->id && !$character->user?->is_admin) {
            return response()->json(['error' => 'You can only edit your own posts.'], 403);
        }

        $validated = $request->validate([
            'body' => 'required|string|min:3|max:5000',
        ]);

        $validated['body'] = ForumBodySanitizer::sanitize(
            $validated['body'],
            (bool) $character->user?->is_admin,
        );

        $post->update(['body' => $validated['body']]);

        return response()->json(['success' => true, 'body' => $validated['body']]);
    }

    

    public function deletePost(Request $request, ForumPost $post)
    {
        $character = $this->requireCharacter($request);

        if (!$character) {
            return response()->json(['error' => 'No character found.'], 403);
        }

        if ($post->character_id !== $character->id && !$character->user?->is_admin) {
            return response()->json(['error' => 'You can only delete your own posts.'], 403);
        }

        $post->replies()->delete();
        $post->delete();

        return response()->json(['success' => true]);
    }

    

    public function editReply(Request $request, ForumReply $reply)
    {
        $character = $this->requireCharacter($request);

        if (!$character) {
            return response()->json(['error' => 'No character found.'], 403);
        }

        if ($reply->character_id !== $character->id && !$character->user?->is_admin) {
            return response()->json(['error' => 'You can only edit your own replies.'], 403);
        }

        $validated = $request->validate([
            'body' => 'required|string|min:2|max:1000',
        ]);

        $validated['body'] = ForumBodySanitizer::sanitize(
            $validated['body'],
            (bool) $character->user?->is_admin,
        );

        $reply->update(['body' => $validated['body']]);

        return response()->json(['success' => true, 'body' => $validated['body']]);
    }

    

    public function deleteReply(Request $request, ForumReply $reply)
    {
        $character = $this->requireCharacter($request);

        if (!$character) {
            return response()->json(['error' => 'No character found.'], 403);
        }

        if ($reply->character_id !== $character->id && !$character->user?->is_admin) {
            return response()->json(['error' => 'You can only delete your own replies.'], 403);
        }

        $reply->delete();

        return response()->json(['success' => true]);
    }

    /**
     * @deprecated Now a no-op kept only so existing call sites don't break.
     *             Body sanitation lives in App\Support\ForumBodySanitizer
     *             and runs silently — non-admins who paste [img] just see
     *             the URL preserved as plain text rather than getting a
     *             rejection wall.
     */
    private function validateBodyTags(string $body, bool $isAdmin): ?string
    {
        return null;
    }
}
