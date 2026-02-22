<?php
declare(strict_types=1);

namespace App\Tests\Unit\Helper;

use App\Helper\PgArrayParser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Helper\PgArrayParser
 */
final class PgArrayParserTest extends TestCase
{
    /* ──────────────────────────────────────
     * parse()
     * ────────────────────────────────────── */

    public function testParseEmptyString(): void
    {
        $this->assertSame([], PgArrayParser::parse(''));
    }

    public function testParseEmptyBraces(): void
    {
        $this->assertSame([], PgArrayParser::parse('{}'));
    }

    public function testParseNull(): void
    {
        $this->assertSame([], PgArrayParser::parse(null));
    }

    public function testParseSimpleArray(): void
    {
        $result = PgArrayParser::parse('{a,b,c}');
        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testParseSingleElement(): void
    {
        $result = PgArrayParser::parse('{hello}');
        $this->assertSame(['hello'], $result);
    }

    public function testParseQuotedElements(): void
    {
        $result = PgArrayParser::parse('{"hello world","foo bar"}');
        $this->assertSame(['hello world', 'foo bar'], $result);
    }

    public function testParsePhpArrayPassthrough(): void
    {
        $input = ['a', 'b', 'c'];
        $result = PgArrayParser::parse($input);
        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testParsePhpArrayCastsToStrings(): void
    {
        $input = [1, 2, 3];
        $result = PgArrayParser::parse($input);
        $this->assertSame(['1', '2', '3'], $result);
    }

    public function testParseIntegerValue(): void
    {
        $this->assertSame([], PgArrayParser::parse(42));
    }

    public function testParseBooleanValue(): void
    {
        $this->assertSame([], PgArrayParser::parse(false));
    }

    /* ──────────────────────────────────────
     * toPgArray()
     * ────────────────────────────────────── */

    public function testToPgArrayEmpty(): void
    {
        $this->assertSame('{}', PgArrayParser::toPgArray([]));
    }

    public function testToPgArraySimple(): void
    {
        $result = PgArrayParser::toPgArray(['a', 'b', 'c']);
        $this->assertSame('{"a","b","c"}', $result);
    }

    public function testToPgArrayEscapesQuotes(): void
    {
        $result = PgArrayParser::toPgArray(['he"llo']);
        $this->assertSame('{"he\\"llo"}', $result);
    }

    public function testToPgArrayEscapesBackslashes(): void
    {
        $result = PgArrayParser::toPgArray(['path\\to\\file']);
        $this->assertSame('{"path\\\\to\\\\file"}', $result);
    }

    public function testToPgArraySingleElement(): void
    {
        $result = PgArrayParser::toPgArray(['admin']);
        $this->assertSame('{"admin"}', $result);
    }

    /* ──────────────────────────────────────
     * Roundtrip: parse(toPgArray(x)) ≈ x
     * ────────────────────────────────────── */

    public function testRoundtrip(): void
    {
        $input = ['mod', 'admin', 'janitor'];
        $pgLiteral = PgArrayParser::toPgArray($input);
        $parsed = PgArrayParser::parse($pgLiteral);
        $this->assertSame($input, $parsed);
    }

    /* ──────────────────────────────────────
     * parseCollection()
     * ────────────────────────────────────── */

    public function testParseCollectionConvertsSingleProperty(): void
    {
        $item = (object) ['boards' => '{a,b,c}', 'name' => 'test'];
        $result = PgArrayParser::parseCollection([$item], 'boards');

        $this->assertCount(1, $result);
        $this->assertSame(['a', 'b', 'c'], $result[0]->boards);
        $this->assertSame('test', $result[0]->name);
    }

    public function testParseCollectionConvertsMultipleProperties(): void
    {
        $item = (object) ['boards' => '{a,b}', 'flags' => '{read,write}', 'name' => 'test'];
        $result = PgArrayParser::parseCollection([$item], 'boards', 'flags');

        $this->assertSame(['a', 'b'], $result[0]->boards);
        $this->assertSame(['read', 'write'], $result[0]->flags);
    }

    public function testParseCollectionHandlesEmptyCollection(): void
    {
        $result = PgArrayParser::parseCollection([], 'boards');
        $this->assertSame([], $result);
    }

    public function testParseCollectionHandlesNullProperty(): void
    {
        $item = (object) ['boards' => null, 'name' => 'test'];
        $result = PgArrayParser::parseCollection([$item], 'boards');

        $this->assertSame([], $result[0]->boards);
    }
}
