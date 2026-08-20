<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WikiPage extends Model
{
    protected $table = 'wiki_pages';

    protected $fillable = [
        'category_id', 'slug', 'title', 'lede', 'body_markdown',
        'updated_by_id', 'published_at', 'is_landing_featured', 'sort_order',
    ];

    protected $casts = [
        'published_at'         => 'datetime',
        'is_landing_featured'  => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(WikiCategory::class, 'category_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at');
    }

    /**
     * Parse the markdown body into a sequence of sections for the Hospital-style
     * image-beside-section layout. Rules:
     *
     *   1. Every `## Heading` line starts a section.
     *   2. If the line immediately after the heading is a standalone
     *      `![alt](url)` image, that image becomes the section's left-side
     *      thumbnail and is stripped from the body.
     *   3. A standalone `**Group Label**` line (or `__Group Label__`)
     *      starts a NEW group. Every subsequent section is tagged with
     *      that group label until the next group divider. Sections before
     *      any group line have group=null.
     *   4. The leading prose (before the first H2) is returned as the first
     *      section with no heading.
     *
     * Group detection only fires on lines that are PURELY one bold token
     * (the whole line, trimmed, matches `**...**` or `__...__` with no
     * other text). Inline bolds inside paragraphs are unaffected.
     *
     * @return array<int, array{
     *     image_url: string|null,
     *     heading: string|null,
     *     body_markdown: string,
     *     group: string|null,
     * }>
     */
    public function parseSections(): array
    {
        $lines = preg_split("/\r\n|\n|\r/", (string) $this->body_markdown);
        $count = count($lines);
        $sections = [];
        $currentGroup = null;
        $current  = ['image_url' => null, 'heading' => null, 'body_markdown' => '', 'group' => null];
        $expectingImageNext = false;

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            $trimmed = trim($line);

            // Group CLEAR: a standalone `---` on its own line ends the
            // current group, so subsequent `## Heading` sections render
            // without a group label. Useful when a page has one named
            // group at the top followed by ungrouped trailing sections.
            if ($trimmed === '---') {
                if ($current['heading'] !== null || trim($current['body_markdown']) !== '') {
                    $current['body_markdown'] = trim($current['body_markdown']);
                    $sections[] = $current;
                }
                $currentGroup = null;
                $current = ['image_url' => null, 'heading' => null, 'body_markdown' => '', 'group' => null];
                $expectingImageNext = false;
                continue;
            }

            // Group divider: a line that is ENTIRELY one bold token AND the
            // next non-blank line is a `## Heading`. This intentionally narrow
            // trigger lets authors keep using **inline mini-headers** for
            // ordinary section sub-titles followed by lists or prose —
            // those don't change the layout. Only the **Bold**+`## Heading`
            // combo opts the page into the GroupLayout's row of tiles.
            if (preg_match('/^(?:\*\*|__)(.+?)(?:\*\*|__)$/', $trimmed, $gm)) {
                // Peek ahead: is the next non-blank line a heading?
                $nextIsHeading = false;
                for ($j = $i + 1; $j < $count; $j++) {
                    $peek = trim($lines[$j]);
                    if ($peek === '') continue;
                    $nextIsHeading = (bool) preg_match('/^##\s+\S/', $peek);
                    break;
                }
                if ($nextIsHeading) {
                    if ($current['heading'] !== null || trim($current['body_markdown']) !== '') {
                        $current['body_markdown'] = trim($current['body_markdown']);
                        $sections[] = $current;
                        $current = ['image_url' => null, 'heading' => null, 'body_markdown' => '', 'group' => $currentGroup];
                    }
                    $currentGroup = trim($gm[1]);
                    $current['group'] = $currentGroup;
                    $expectingImageNext = false;
                    continue;
                }
                // Not a group divider — fall through and treat the bold
                // line as ordinary inline body content.
            }

            if (preg_match('/^##\s+(.+?)\s*$/', $line, $m)) {
                if ($current['heading'] !== null || trim($current['body_markdown']) !== '') {
                    $current['body_markdown'] = trim($current['body_markdown']);
                    $sections[] = $current;
                }
                $current = ['image_url' => null, 'heading' => $m[1], 'body_markdown' => '', 'group' => $currentGroup];
                $expectingImageNext = true;
                continue;
            }

            if ($expectingImageNext) {
                $expectingImageNext = false;
                if (preg_match('/^!\[[^\]]*\]\((\S+)\)\s*$/', $trimmed, $m)) {
                    $current['image_url'] = $m[1];
                    continue;
                }
                if ($trimmed === '') {
                    $expectingImageNext = true;
                    continue;
                }
            }

            $current['body_markdown'] .= $line . "\n";
        }

        if ($current['heading'] !== null || trim($current['body_markdown']) !== '') {
            $current['body_markdown'] = trim($current['body_markdown']);
            $sections[] = $current;
        }

        return $sections;
    }
}
