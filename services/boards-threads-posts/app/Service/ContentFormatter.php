<?php
declare(strict_types=1);

/*
 * Copyright 2026 txrx-byte
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */


namespace App\Service;

use App\Model\Post;

/**
 * Converts raw post text into formatted HTML.
 *
 * Handles the following markup transformations:
 * - Greentext: Lines starting with > become <span class="quote">
 * - Quote links: >>12345 or >>uuid become anchor links
 * - Cross-board links: >>>/b/ become board links
 * - Spoilers: [spoiler]text[/spoiler] become <s>text</s>
 * - Code blocks: [code]code[/code] become <pre class="prettyprint">
 * - Bold: **text** becomes <b>text</b>
 * - Italic: *text* becomes <i>text</i>
 * - Underline: __text__ becomes <u>text</u>
 * - Strikethrough: ~~text~~ becomes <s>text</s>
 * - URLs: Bare URLs become anchor links with noopener
 *
 * All transformations are applied after HTML escaping to prevent XSS.
 *
 * @see \App\Service\BoardService For usage in post creation
 */
final class ContentFormatter
{
    /**
     * Parse raw comment text into display HTML.
     *
     * Processing order is important:
     * 1. HTML escape all content (XSS prevention)
     * 2. Process block-level markup (code, spoilers)
     * 3. Process inline markup (bold, italic, etc.)
     * 4. Process links (quote, cross-board, URLs)
     * 5. Convert newlines to <br>
     *
     * @param string $raw Raw post content with markup
     * @return string Formatted HTML safe for display
     *
     * @example Input: "Hello **world**\n>greentext"
     * @example Output: "Hello <b>world</b><br><span class=\"quote\">&gt;greentext</span>"
     */
    public function format(string $raw): string
    {
        // Step 1: HTML escape all content to prevent XSS
        $html = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Step 2: Code blocks: [code]...[/code]
        $html = preg_replace(
            '/\[code\](.*?)\[\/code\]/s',
            '<pre class="prettyprint">$1</pre>',
            $html
        ) ?? $html;

        // Step 3: Spoilers: [spoiler]...[/spoiler]
        $html = preg_replace(
            '/\[spoiler\](.*?)\[\/spoiler\]/s',
            '<s>$1</s>',
            $html
        ) ?? $html;

        // Step 4: Inline formatting
        // Bold: **text**
        $html = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $html) ?? $html;
        // Italic: *text* (not followed or preceded by *)
        $html = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<i>$1</i>', $html) ?? $html;
        // Underline: __text__
        $html = preg_replace('/__(.+?)__/', '<u>$1</u>', $html) ?? $html;
        // Strikethrough: ~~text~~
        $html = preg_replace('/~~(.+?)~~/', '<s>$1</s>', $html) ?? $html;

        // Step 5: Links
        // Cross-board links: >>>/board/
        $html = preg_replace(
            '/&gt;&gt;&gt;\/(\w+)\//',
            '<a href="/$1/" class="quotelink">&gt;&gt;&gt;/$1/</a>',
            $html
        ) ?? $html;

        // Quote links: >>uuid or >>integer
        $html = preg_replace(
            '/&gt;&gt;([a-f0-9\-]{36}|\d+)/i',
            '<a href="#p$1" class="quotelink">&gt;&gt;$1</a>',
            $html
        ) ?? $html;

        // Greentext: lines starting with > (but not >> or >>>)
        $html = preg_replace(
            '/^(&gt;(?!&gt;).*)$/m',
            '<span class="quote">$1</span>',
            $html
        ) ?? $html;

        // Auto-link bare URLs (must run after quote-link and cross-board patterns)
        // Only http/https schemes are allowed
        $html = preg_replace(
            '/(https?:\/\/[^\s<>\[\]"\']+)/i',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
            $html
        ) ?? $html;

        // Step 6: Line breaks
        $html = nl2br($html);

        return $html;
    }

    /**
     * Extract quoted post IDs from raw content.
     *
     * Finds all >>references in the content for building backlinks.
     *
     * @param string $raw Raw post content
     * @return string[] Array of unique quoted post IDs
     *
     * @example Input: "Hello >>12345 and >>abcdef"
     * @example Output: ["12345", "abcdef"]
     */
    public function extractQuotedIds(string $raw): array
    {
        preg_match_all('/>>([a-f0-9\-]{36}|\d+)/i', $raw, $matches);
        return array_unique($matches[1]);
    }

    /**
     * Generate a tripcode from the name field.
     *
     * Tripcode format: Name#password → Name !tripcode
     *
     * The tripcode algorithm:
     * 1. Split name at # character
     * 2. Take first 2 chars of password after #
     * 3. Sanitize salt to .-z range
     * 4. Map special chars to A-F
     * 5. crypt() with salt, take last 10 chars
     *
     * @param string $name Name field (may contain #password)
     * @return array{0: string, 1: string|null} [display_name, tripcode_or_null]
     *
     * @example Input: "Anonymous#secret" → Output: ["Anonymous", "!ABC123DEF"]
     * @example Input: "Anonymous" → Output: ["Anonymous", null]
     */
    public function parseNameTrip(string $name): array
    {
        if (str_contains($name, '#')) {
            [$displayName, $password] = explode('#', $name, 2);
            $salt = substr($password . 'H.', 1, 2);
            $salt = preg_replace('/[^\.-z]/', '.', $salt) ?? '.';
            $salt = strtr($salt, ':;<=>?@[\\]^_`', 'ABCDEFGabcdef');
            $trip = '!' . substr(crypt($password, $salt), -10);
            return [trim($displayName) ?: 'Anonymous', $trip];
        }
        return [trim($name) ?: 'Anonymous', null];
    }
}
