<?php

namespace App\Jobs;

use App\Models\ForumPost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Posts a forum thread to a Discord channel via webhook.
 *
 * Currently fired by ForumPost::booted() when a post lands in the
 * 'changelogs' category. The job is queued (database driver) so the
 * HTTP response from the forum POST never blocks on Discord, and it
 * dispatches afterCommit so we don't push to Discord for a thread that
 * gets rolled back.
 */
class PostForumThreadToDiscord implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries     = 3;
    public int $backoff   = 30;     // seconds between retries
    public int $timeout   = 15;     // hard ceiling on Discord HTTP call

    public function __construct(public int $postId)
    {
    }

    public function handle(): void
    {
        $webhookUrl = config('services.discord.changelog_webhook_url');
        if (empty($webhookUrl)) {
            Log::warning('[Discord] No changelog webhook configured \u2014 skipping forum push.', [
                'post_id' => $this->postId,
            ]);
            return;
        }

        $post = ForumPost::with(['character:id,display_name,custom_avatar_url', 'category:id,name,slug'])
            ->find($this->postId);

        if (!$post) {
            // Post was deleted between dispatch and execution \u2014 nothing to do.
            return;
        }

        $authorName   = $post->character?->display_name ?? 'Deceased';
        $authorAvatar = $post->character?->custom_avatar_url ?: null;

        // Discord embed description has a 4096-char ceiling. Forum bodies
        // can hit 5000 chars per the validator, so trim with an ellipsis
        // and a "read more" footer linking to the thread.
        $description = $this->trimForEmbed($post->body, 3800);

        $payload = [
            'username'   => 'The Director',
            'embeds'     => [[
                'title'       => Str::limit($post->title, 256, '\u2026'),
                'description' => $description,
                'url'         => url("/forum/post/{$post->id}"),
                'color'       => 0x22d3ee, // matches Staff role + game accent
                'timestamp'   => $post->created_at->toIso8601String(),
                'author'      => array_filter([
                    'name'     => $authorName,
                    'icon_url' => $authorAvatar,
                ]),
                'footer'      => [
                    'text' => "Posted in #{$post->category?->name}",
                ],
            ]],
            // No content + suppress mentions \u2014 a changelog post should never
            // accidentally @everyone via [@everyone] in the body.
            'allowed_mentions' => ['parse' => []],
        ];

        $response = Http::timeout($this->timeout)
            ->retry(2, 200) // network-level retries; the job's $tries handles 5xx/429
            ->asJson()
            ->post($webhookUrl, $payload);

        if (! $response->successful()) {
            Log::error('[Discord] Forum push failed.', [
                'post_id' => $this->postId,
                'status'  => $response->status(),
                'body'    => Str::limit($response->body(), 500),
            ]);

            // Non-2xx \u2014 throw so the queue worker retries with backoff.
            // 4xx (bad payload, bad webhook) will exhaust retries and
            // land in failed_jobs; 5xx/429 will usually clear on retry.
            throw new \RuntimeException("Discord webhook returned {$response->status()}");
        }
    }

    /**
     * Trim body for Discord embed. Strips BBCode-style tags the forum
     * supports but Discord doesn't render natively; converts a couple
     * to Markdown equivalents.
     */
    private function trimForEmbed(string $body, int $max): string
    {
        // Cheap BBCode \u2192 Markdown for the common cases. Anything not
        // mapped is stripped of its tags but the inner text is kept.
        $body = preg_replace('/\[b\](.*?)\[\/b\]/is',     '**$1**', $body);
        $body = preg_replace('/\[i\](.*?)\[\/i\]/is',     '*$1*',   $body);
        $body = preg_replace('/\[u\](.*?)\[\/u\]/is',     '__$1__', $body);
        $body = preg_replace('/\[code\](.*?)\[\/code\]/is', "```\n$1\n```", $body);
        $body = preg_replace('/\[\/?[a-z][a-z0-9]*(?:=[^\]]+)?\]/i', '', $body);

        if (mb_strlen($body) <= $max) {
            return $body;
        }

        return mb_substr($body, 0, $max - 1) . "\u2026";
    }
}
