<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit;

use PhpRegex\Parser\ErrorCode;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every offset counts from the first character of the pattern body, the
 * coordinate PCRE uses: a fault among the modifiers is counted past the body
 * and its closing delimiter, and a pattern with no body has no offset. The
 * caret snippet shows the text from the body on, the caret under the fault.
 */
final class BodyRelativeOffsetTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('provideFaultsOutsideTheBody')]
    public function test_offset_counts_from_the_body(string $pattern, array $options, ErrorCode $code, int $offset, string $underCaret): void
    {
        $result = Regex::create(['cache' => null] + $options)->validate($pattern);

        $this->assertSame($code, $result->errorCode, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
        $this->assertSame($underCaret, self::characterUnderCaret((string) $result->caretSnippet), $pattern);
    }

    /**
     * The message names the same offset as the result.
     */
    #[Test]
    public function test_delimiter_message_names_the_offset_from_the_body(): void
    {
        $result = Regex::create(['cache' => null])->validate('/ab/c/');

        $this->assertSame(2, $result->offset);
        $this->assertStringContainsString('at position 2 ends the pattern early', (string) $result->error);
    }

    /**
     * @return iterable<string, array{pattern: string, options: array<string, mixed>, code: ErrorCode, offset: int, underCaret: string}>
     */
    public static function provideFaultsOutsideTheBody(): iterable
    {
        yield 'unknown modifier' => ['pattern' => '/a/Q', 'options' => [], 'code' => ErrorCode::FlagUnknown, 'offset' => 2, 'underCaret' => 'Q'];
        yield 'unknown modifier after a known one' => ['pattern' => '/ab/iQ', 'options' => [], 'code' => ErrorCode::FlagUnknown, 'offset' => 4, 'underCaret' => 'Q'];
        yield 'unknown modifier after whitespace' => ['pattern' => "/a/i \nQ", 'options' => [], 'code' => ErrorCode::FlagUnknown, 'offset' => 5, 'underCaret' => 'Q'];
        yield 'unknown modifier with leading whitespace' => ['pattern' => '  /a/Q', 'options' => [], 'code' => ErrorCode::FlagUnknown, 'offset' => 2, 'underCaret' => 'Q'];
        yield 'first of two unknown modifiers' => ['pattern' => '/a/iQW', 'options' => [], 'code' => ErrorCode::FlagUnknown, 'offset' => 3, 'underCaret' => 'Q'];
        yield 'bracket delimiters' => ['pattern' => '{a}Q', 'options' => [], 'code' => ErrorCode::FlagUnknown, 'offset' => 2, 'underCaret' => 'Q'];
        yield 'removed e modifier' => ['pattern' => '/a/ie', 'options' => [], 'code' => ErrorCode::FlagRemovedE, 'offset' => 3, 'underCaret' => 'e'];
        yield 'delimiter ending the pattern early' => ['pattern' => '/a/b/', 'options' => [], 'code' => ErrorCode::DelimiterUnescaped, 'offset' => 1, 'underCaret' => '/'];
        yield 'maximum length inside the leading whitespace' => ['pattern' => '   /ab/', 'options' => ['max_pattern_length' => 2], 'code' => ErrorCode::PatternTooLong, 'offset' => 0, 'underCaret' => 'a'];
        yield 'pattern past the maximum length after whitespace' => ['pattern' => '  /abcdefghi/', 'options' => ['max_pattern_length' => 10], 'code' => ErrorCode::PatternTooLong, 'offset' => 7, 'underCaret' => 'h'];
        yield 'pattern past the maximum length' => ['pattern' => '/abcdefghi/', 'options' => ['max_pattern_length' => 10], 'code' => ErrorCode::PatternTooLong, 'offset' => 9, 'underCaret' => '/'];
    }

    /**
     * A fault outside the body is shown in the pattern as it was written,
     * opening delimiter included: the offset counts from the body, the
     * caret stands under the character at fault.
     *
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('provideFaultsOutsideTheBody')]
    public function test_snippet_shows_the_pattern_as_written(string $pattern, array $options, ErrorCode $code, int $offset, string $underCaret): void
    {
        unset($code, $offset, $underCaret);
        $result = Regex::create(['cache' => null] + $options)->validate($pattern);

        $lines = explode("\n", (string) $result->caretSnippet);
        $shown = (string) preg_replace('/^Line \d+: /', '', $lines[0]);
        $written = explode("\n", ltrim($pattern));

        $this->assertContains($shown, $written, $pattern);
        if (1 === \count($written)) {
            $this->assertStringStartsWith(ltrim($pattern)[0], $shown, $pattern);
        }
    }

    /**
     * A syntax error inside the body already counted from the body: it
     * does not move.
     */
    #[Test]
    public function test_offset_inside_the_body_is_unchanged(): void
    {
        $result = Regex::create(['cache' => null])->validate('/ab(/i');

        $this->assertSame(ErrorCode::GroupUnclosed, $result->errorCode);
        $this->assertSame(3, $result->offset);
    }

    #[Test]
    #[DataProvider('provideFaultsWithNoBody')]
    public function test_pattern_with_no_body_has_no_offset(string $pattern, ErrorCode $code): void
    {
        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertSame($code, $result->errorCode, $pattern);
        $this->assertNull($result->offset, $pattern);
        $this->assertNull($result->caretSnippet, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode}>
     */
    public static function provideFaultsWithNoBody(): iterable
    {
        yield 'empty string' => ['pattern' => '', 'code' => ErrorCode::PatternEmpty];
        yield 'lone delimiter' => ['pattern' => '/', 'code' => ErrorCode::DelimiterUnclosed];
        yield 'lone letter' => ['pattern' => 'a', 'code' => ErrorCode::DelimiterInvalid];
        yield 'alphanumeric delimiter' => ['pattern' => 'abc', 'code' => ErrorCode::DelimiterInvalid];
        yield 'no closing delimiter' => ['pattern' => '/abc', 'code' => ErrorCode::DelimiterUnclosed];
    }

    /**
     * The character of the snippet's text line that stands above its caret.
     */
    private static function characterUnderCaret(string $snippet): string
    {
        $lines = explode("\n", $snippet);
        $caretLine = (string) array_pop($lines);
        $column = strpos($caretLine, '^');
        self::assertNotFalse($column, 'The snippet has no caret: '.$snippet);

        return substr((string) end($lines), $column, 1);
    }
}
