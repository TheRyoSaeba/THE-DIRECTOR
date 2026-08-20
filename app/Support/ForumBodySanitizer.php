<?php

namespace App\Support;

/**
 * Sanitizes forum / city-hall post bodies before they reach the database.
 *
 * Why server-side? The frontend BBCode renderer doesn't know who wrote the
 * post, so it can't enforce per-author rules. Doing it on write means the
 * stored body is always in a renderable, policy-compliant state — and we
 * never have to recheck on read.
 *
 * Currently enforced:
 *  - [img]...[/img] is admin-only. Non-admin posts get the tag stripped
 *    (the URL inside is preserved as plain text, so the author can still see
 *    where they wanted an image). This is an IP-leak prevention: any reader
 *    of a forum thread would otherwise be doing a GET against whatever URL a
 *    random user pastes, which is a tracking-pixel / fingerprinting vector.
 */
final class ForumBodySanitizer
{
    /**
     * Strip tags the given author isn't allowed to use.
     *
     * @param  string $body     Raw user input (BBCode + plain text).
     * @param  bool   $isAdmin  Whether the author is a site admin.
     * @return string           Body safe to store.
     */
    public static function sanitize(string $body, bool $isAdmin): string
    {
        if ($isAdmin) {
            return $body;
        }

        // Replace [img]URL[/img] with the plain URL so the author can still
        // see what they typed. The space prefix prevents accidental
        // concatenation with surrounding text. Case-insensitive to match the
        // renderer's tag matcher.
        return preg_replace(
            '/\[img\]([\s\S]*?)\[\/img\]/i',
            ' $1 ',
            $body,
        ) ?? $body;
    }
}
