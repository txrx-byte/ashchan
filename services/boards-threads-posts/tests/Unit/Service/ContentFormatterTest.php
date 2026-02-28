<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ContentFormatter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Service\ContentFormatter
 */
final class ContentFormatterTest extends TestCase
{
    private ContentFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ContentFormatter();
    }

    /* ──────────────────────────────────────────────
     * Greentext Tests
     * ────────────────────────────────────────────── */

    public function testFormatConvertsGreentext(): void
    {
        $input = ">This is a greentext";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<span class="quote">&gt;This is a greentext</span>', $output);
    }

    public function testFormatConvertsMultipleGreentextLines(): void
    {
        $input = ">Line 1\n>Line 2\n>Line 3";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<span class="quote">&gt;Line 1</span>', $output);
        $this->assertStringContainsString('<span class="quote">&gt;Line 2</span>', $output);
        $this->assertStringContainsString('<span class="quote">&gt;Line 3</span>', $output);
    }

    public function testFormatDoesNotConvertDoubleGreentext(): void
    {
        $input = ">>12345";
        $output = $this->formatter->format($input);

        // Should be a quote link, not greentext
        $this->assertStringNotContainsString('<span class="quote">&gt;&gt;12345</span>', $output);
        $this->assertStringContainsString('<a href="#p12345"', $output);
    }

    public function testFormatDoesNotConvertTripleGreentext(): void
    {
        $input = ">>>/b/";
        $output = $this->formatter->format($input);

        // Should be a cross-board link, not greentext
        $this->assertStringNotContainsString('<span class="quote">&gt;&gt;&gt;/b/</span>', $output);
        $this->assertStringContainsString('<a href="/b/"', $output);
    }

    /* ──────────────────────────────────────────────
     * Quote Links Tests
     * ────────────────────────────────────────────── */

    public function testFormatConvertsNumericQuoteLink(): void
    {
        $input = ">>12345";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<a href="#p12345" class="quotelink">&gt;&gt;12345</a>', $output);
    }

    public function testFormatConvertsUUIDQuoteLink(): void
    {
        $uuid = 'abcdef12-3456-7890-abcd-ef1234567890';
        $input = ">>{$uuid}";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString("<a href=\"#p{$uuid}\"", $output);
    }

    public function testFormatConvertsMultipleQuoteLinks(): void
    {
        $input = ">>12345 and >>67890";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<a href="#p12345"', $output);
        $this->assertStringContainsString('<a href="#p67890"', $output);
    }

    /* ──────────────────────────────────────────────
     * Cross-Board Links Tests
     * ────────────────────────────────────────────── */

    public function testFormatConvertsCrossBoardLink(): void
    {
        $input = ">>>/b/";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<a href="/b/" class="quotelink">&gt;&gt;&gt;/b/</a>', $output);
    }

    public function testFormatConvertsMultipleCrossBoardLinks(): void
    {
        $input = ">>>/g/ and >>>/v/";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<a href="/g/"', $output);
        $this->assertStringContainsString('<a href="/v/"', $output);
    }

    /* ──────────────────────────────────────────────
     * Spoilers Tests
     * ────────────────────────────────────────────── */

    public function testFormatConvertsSpoiler(): void
    {
        $input = "This is [spoiler]hidden[/spoiler] text";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<s>hidden</s>', $output);
    }

    public function testFormatConvertsMultipleSpoilers(): void
    {
        $input = "[spoiler]one[/spoiler] and [spoiler]two[/spoiler]";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<s>one</s>', $output);
        $this->assertStringContainsString('<s>two</s>', $output);
    }

    public function testFormatHandlesNestedSpoilerWithGreentext(): void
    {
        $input = "[spoiler]>secret[/spoiler]";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<s>', $output);
        $this->assertStringContainsString('&gt;secret', $output);
    }

    /* ──────────────────────────────────────────────
     * Code Blocks Tests
     * ────────────────────────────────────────────── */

    public function testFormatConvertsCodeBlock(): void
    {
        $input = "Check this [code]console.log('hello');[/code]";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<pre class="prettyprint">console.log(\'hello\');</pre>', $output);
    }

    public function testFormatConvertsMultilineCodeBlock(): void
    {
        $input = "[code]function test() {\n  return true;\n}[/code]";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<pre class="prettyprint">', $output);
        $this->assertStringContainsString('function test()', $output);
    }

    /* ──────────────────────────────────────────────
     * Bold Text Tests
     * ────────────────────────────────────────────── */

    public function testFormatConvertsBold(): void
    {
        $input = "This is **bold** text";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<b>bold</b>', $output);
    }

    public function testFormatConvertsMultipleBold(): void
    {
        $input = "**one** and **two**";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<b>one</b>', $output);
        $this->assertStringContainsString('<b>two</b>', $output);
    }

    /* ──────────────────────────────────────────────
     * Italic Text Tests
     * ────────────────────────────────────────────── */

    public function testFormatConvertsItalic(): void
    {
        $input = "This is *italic* text";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<i>italic</i>', $output);
    }

    public function testFormatDoesNotConvertAsteriskInBold(): void
    {
        $input = "**bold**";
        $output = $this->formatter->format($input);

        // Should only be bold, not italic
        $this->assertStringContainsString('<b>bold</b>', $output);
        $this->assertStringNotContainsString('<i>', $output);
    }

    /* ──────────────────────────────────────────────
     * Underline Text Tests
     * ────────────────────────────────────────────── */

    public function testFormatConvertsUnderline(): void
    {
        $input = "This is __underlined__ text";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<u>underlined</u>', $output);
    }

    /* ──────────────────────────────────────────────
     * Strikethrough Text Tests
     * ────────────────────────────────────────────── */

    public function testFormatConvertsStrikethrough(): void
    {
        $input = "This is ~~strikethrough~~ text";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<s>strikethrough</s>', $output);
    }

    /* ──────────────────────────────────────────────
     * URL Auto-Linking Tests
     * ────────────────────────────────────────────── */

    public function testFormatAutoLinksHTTPURL(): void
    {
        $input = "Visit http://example.com for more";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<a href="http://example.com"', $output);
        $this->assertStringContainsString('rel="noopener noreferrer"', $output);
        $this->assertStringContainsString('target="_blank"', $output);
    }

    public function testFormatAutoLinksHTTPSURL(): void
    {
        $input = "Visit https://example.com for more";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<a href="https://example.com"', $output);
    }

    public function testFormatAutoLinksMultipleURLs(): void
    {
        $input = "Check http://a.com and https://b.com";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<a href="http://a.com"', $output);
        $this->assertStringContainsString('<a href="https://b.com"', $output);
    }

    public function testFormatDoesNotLinkFTP(): void
    {
        $input = "FTP ftp://example.com";
        $output = $this->formatter->format($input);

        $this->assertStringNotContainsString('<a href="ftp://', $output);
    }

    public function testFormatDoesNotLinkJavascript(): void
    {
        $input = "JS javascript:alert(1)";
        $output = $this->formatter->format($input);

        $this->assertStringNotContainsString('<a href="javascript:', $output);
    }

    /* ──────────────────────────────────────────────
     * XSS Prevention Tests
     * ────────────────────────────────────────────── */

    public function testFormatEscapesScriptTags(): void
    {
        $input = "<script>alert('xss')</script>";
        $output = $this->formatter->format($input);

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    public function testFormatEscapesImageTags(): void
    {
        $input = '<img src="x" onerror="alert(1)">';
        $output = $this->formatter->format($input);

        $this->assertStringNotContainsString('<img', $output);
        $this->assertStringContainsString('&lt;img', $output);
    }

    public function testFormatEscapesQuotesInScriptContext(): void
    {
        $input = '" onclick="alert(1)"';
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('&quot;', $output);
        $this->assertStringContainsString('&#039;', $output);
    }

    public function testFormatEscapesGreentextWithScript(): void
    {
        $input = "><script>alert('xss')</script>";
        $output = $this->formatter->format($input);

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&gt;&lt;script&gt;', $output);
    }

    /* ──────────────────────────────────────────────
     * Line Break Tests
     * ────────────────────────────────────────────── */

    public function testFormatConvertsNewlinesToBreaks(): void
    {
        $input = "Line 1\nLine 2\nLine 3";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString("<br />\n", $output);
    }

    public function testFormatPreservesParagraphStructure(): void
    {
        $input = "Para 1\n\nPara 2";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString("<br />\n<br />\n", $output);
    }

    /* ──────────────────────────────────────────────
     * Combined Formatting Tests
     * ────────────────────────────────────────────── */

    public function testFormatHandlesComplexPost(): void
    {
        $input = ">Be me\n>>12345\nCheck **bold** and *italic*\nVisit https://example.com\n[spoiler]secret[/spoiler]";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<span class="quote">&gt;Be me</span>', $output);
        $this->assertStringContainsString('<a href="#p12345"', $output);
        $this->assertStringContainsString('<b>bold</b>', $output);
        $this->assertStringContainsString('<i>italic</i>', $output);
        $this->assertStringContainsString('<a href="https://example.com"', $output);
        $this->assertStringContainsString('<s>secret</s>', $output);
    }

    public function testFormatOrderMatters(): void
    {
        // Greentext should not interfere with quote links
        $input = ">>12345\n>greentext";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<a href="#p12345"', $output);
        $this->assertStringContainsString('<span class="quote">&gt;greentext</span>', $output);
    }

    /* ──────────────────────────────────────────────
     * extractQuotedIds Tests
     * ────────────────────────────────────────────── */

    public function testExtractQuotedIdsFindsNumericIds(): void
    {
        $input = ">>12345 and >>67890";
        $result = $this->formatter->extractQuotedIds($input);

        $this->assertEquals(['12345', '67890'], $result);
    }

    public function testExtractQuotedIdsFindsUUIDs(): void
    {
        $uuid = 'abcdef12-3456-7890-abcd-ef1234567890';
        $input = "Reply to >>{$uuid}";
        $result = $this->formatter->extractQuotedIds($input);

        $this->assertEquals([$uuid], $result);
    }

    public function testExtractQuotedIdsReturnsUniqueIds(): void
    {
        $input = ">>12345 and >>12345 and >>67890";
        $result = $this->formatter->extractQuotedIds($input);

        $this->assertEquals(['12345', '67890'], $result);
        $this->assertCount(2, $result);
    }

    public function testExtractQuotedIdsHandlesMixedIds(): void
    {
        $uuid = 'abcdef12-3456-7890-abcd-ef1234567890';
        $input = ">>12345 and >>{$uuid} and >>99999";
        $result = $this->formatter->extractQuotedIds($input);

        $this->assertCount(3, $result);
        $this->assertContains('12345', $result);
        $this->assertContains($uuid, $result);
        $this->assertContains('99999', $result);
    }

    public function testExtractQuotedIdsReturnsEmptyForNoQuotes(): void
    {
        $input = "No quotes here";
        $result = $this->formatter->extractQuotedIds($input);

        $this->assertEmpty($result);
    }

    /* ──────────────────────────────────────────────
     * parseNameTrip Tests
     * ────────────────────────────────────────────── */

    public function testParseNameTripReturnsNameWithoutTripcode(): void
    {
        [$name, $trip] = $this->formatter->parseNameTrip('Anonymous');

        $this->assertEquals('Anonymous', $name);
        $this->assertNull($trip);
    }

    public function testParseNameTripReturnsEmptyNameAsAnonymous(): void
    {
        [$name, $trip] = $this->formatter->parseNameTrip('');

        $this->assertEquals('Anonymous', $name);
        $this->assertNull($trip);
    }

    public function testParseNameTripGeneratesTripcode(): void
    {
        [$name, $trip] = $this->formatter->parseNameTrip('Anonymous#secret');

        $this->assertEquals('Anonymous', $name);
        $this->assertNotNull($trip);
        $this->assertStringStartsWith('!', $trip);
        $this->assertEquals(11, strlen($trip)); // ! + 10 chars
    }

    public function testParseNameTripGeneratesConsistentTripcode(): void
    {
        [, $trip1] = $this->formatter->parseNameTrip('Name#password');
        [, $trip2] = $this->formatter->parseNameTrip('Name#password');

        $this->assertEquals($trip1, $trip2);
    }

    public function testParseNameTripGeneratesDifferentTripcodeForDifferentPasswords(): void
    {
        [, $trip1] = $this->formatter->parseNameTrip('Name#pass1');
        [, $trip2] = $this->formatter->parseNameTrip('Name#pass2');

        $this->assertNotEquals($trip1, $trip2);
    }

    public function testParseNameTripHandlesEmptyPassword(): void
    {
        [$name, $trip] = $this->formatter->parseNameTrip('Name#');

        $this->assertEquals('Name', $name);
        $this->assertNotNull($trip);
        $this->assertStringStartsWith('!', $trip);
    }

    public function testParseNameTripTrimsName(): void
    {
        [$name, $trip] = $this->formatter->parseNameTrip('  Anonymous  #secret');

        $this->assertEquals('Anonymous', $name);
        $this->assertNotNull($trip);
    }

    /* ──────────────────────────────────────────────
     * Edge Cases
     * ────────────────────────────────────────────── */

    public function testFormatHandlesEmptyString(): void
    {
        $output = $this->formatter->format('');
        $this->assertEquals('', $output);
    }

    public function testFormatHandlesOnlyWhitespace(): void
    {
        $output = $this->formatter->format("   \n\n   ");
        $this->assertNotEmpty($output);
    }

    public function testFormatHandlesUnicodeContent(): void
    {
        $input = "日本語テスト **bold**";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('日本語テスト', $output);
        $this->assertStringContainsString('<b>bold</b>', $output);
    }

    public function testFormatHandlesEmojiContent(): void
    {
        $input = "Hello 👋 **world**";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('👋', $output);
        $this->assertStringContainsString('<b>world</b>', $output);
    }

    public function testFormatHandlesVeryLongContent(): void
    {
        $input = str_repeat('a', 10000);
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('aaa', $output);
    }

    public function testFormatHandlesMalformedMarkup(): void
    {
        $input = "**unclosed bold and *unclosed italic";
        $output = $this->formatter->format($input);

        // Should not crash, should escape HTML
        $this->assertNotEmpty($output);
    }

    public function testFormatHandlesNestedBoldAndItalic(): void
    {
        $input = "**bold *italic* bold**";
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('<b>bold', $output);
        $this->assertStringContainsString('<i>italic</i>', $output);
    }

    public function testFormatEscapesHTMLEntitiesInAttributes(): void
    {
        $input = 'Test "quotes" and \'apostrophes\'';
        $output = $this->formatter->format($input);

        $this->assertStringContainsString('&quot;', $output);
        $this->assertStringContainsString('&#039;', $output);
    }

    public function testFormatHandlesMixedCaseTags(): void
    {
        $input = "[SPOILER]text[/SPOILER]";
        $output = $this->formatter->format($input);

        // Should not match (case sensitive)
        $this->assertStringNotContainsString('<s>text</s>', $output);
    }
}
