<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Post;

/**
 * Converts raw post text into formatted HTML.
 * Handles greentext, quote-links, cross-board links, spoilers,
 * code blocks, bold, italic, underline, strikethrough.
 */
final class ContentFormatter
{
    /**
     * Parse raw comment text into display HTML.
     */
    public function format(string $raw): string
    {
        $html = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Code blocks: [code]...[/code]
        $html = preg_replace(
            '/\[code\](.*?)\[\/code\]/s',
            '<pre class="prettyprint">$1</pre>',
            $html
        );

        // Spoilers: [spoiler]...[/spoiler]
        $html = preg_replace(
            '/\[spoiler\](.*?)\[\/spoiler\]/s',
            '<s>$1</s>',
            $html
        );

        // Bold: **text**
        $html = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $html);
        // Italic: *text*
        $html = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<i>$1</i>', $html);
        // Underline: __text__
        $html = preg_replace('/__(.+?)__/', '<u>$1</u>', $html);
        // Strikethrough: ~~text~~
        $html = preg_replace('/~~(.+?)~~/', '<s>$1</s>', $html);

        // Cross-board links: >>>/board/
        $html = preg_replace(
            '/&gt;&gt;&gt;\/(\w+)\//',
            '<a href="/$1/" class="quotelink">&gt;&gt;&gt;/$1/</a>',
            $html
        );

        // Quote links: >>12345
        $html = preg_replace(
            '/&gt;&gt;(\d+)/',
            '<a href="#p$1" class="quotelink">&gt;&gt;$1</a>',
            $html
        );

        // Greentext: lines starting with >
        $html = preg_replace(
            '/^(&gt;(?!&gt;).*)$/m',
            '<span class="quote">$1</span>',
            $html
        );

        // Line breaks
        $html = nl2br($html);

        return $html;
    }

    /**
     * Extract quoted post IDs from raw content.
     * @return int[]
     */
    public function extractQuotedIds(string $raw): array
    {
        preg_match_all('/>>(\d+)/', $raw, $matches);
        return array_unique(array_map('intval', $matches[1] ?? []));
    }

    /**
     * Generate a tripcode from the name field.
     * Name#password → Name !tripcode
     */
    public function parseNameTrip(string $name): array
    {
        if (str_contains($name, '#')) {
            [$displayName, $password] = explode('#', $name, 2);
            $salt = substr($password . 'H.', 1, 2);
            $salt = preg_replace('/[^\.-z]/', '.', $salt);
            $salt = strtr($salt, ':;<=>?@[\\]^_`', 'ABCDEFGabcdef');
            $trip = '!' . substr(crypt($password, $salt), -10);
            return [trim($displayName) ?: 'Anonymous', $trip];
        }
        return [trim($name) ?: 'Anonymous', null];
    }
}
