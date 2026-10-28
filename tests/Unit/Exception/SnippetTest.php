<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Tests\Unit\Exception;

use PHPRegex\Parser\Exception\Snippet;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The line of a pattern with a caret under an offset, as every exception
 * that shows the pattern and the runtime compile check draw it: the line
 * cut to 80 bytes around the caret, never inside a UTF-8 character.
 */
final class SnippetTest extends TestCase
{
    #[Test]
    public function test_no_pattern_or_no_offset_draws_nothing(): void
    {
        $this->assertSame('', Snippet::render(null, 1));
        $this->assertSame('', Snippet::render('abc', null));
        $this->assertSame('', Snippet::render('abc', -1));
    }

    #[Test]
    public function test_the_caret_stands_under_the_offset(): void
    {
        $this->assertSame("Line 1: foo(bar)baz\n".str_repeat(' ', 12).'^', Snippet::render('foo(bar)baz', 4));
        $this->assertSame("Line 2: bar\n".str_repeat(' ', 9).'^', Snippet::render("foo\nbar\nbaz", 5));
        $this->assertSame("Line 1: abc\n".str_repeat(' ', 11).'^', Snippet::render('abc', 9));
        // On the line break itself, the line after it is shown, the caret at its start.
        $this->assertStringEndsWith("\n".str_repeat(' ', 8).'^', Snippet::render("(?<n\n>a)", 4));
    }

    #[Test]
    public function test_a_long_line_is_cut_around_the_caret(): void
    {
        $pattern = str_repeat('a', 50).'X'.str_repeat('b', 50);

        [$line, $caret] = explode("\n", Snippet::render($pattern, 50));

        $this->assertSame('Line 1: ...'.str_repeat('a', 40).'X'.str_repeat('b', 39).'...', $line);
        $this->assertSame('X', $line[(int) strpos($caret, '^')]);
    }

    /**
     * A cut that falls inside a UTF-8 character moves to its edge: the
     * excerpt starts on a whole character and ends after one.
     */
    #[Test]
    public function test_a_cut_never_splits_a_utf8_character(): void
    {
        // The window, 40 bytes either side of the "(" at 125, opens on the
        // second byte of an "é" and closes on the first byte of another.
        $pattern = str_repeat("\u{E9}", 62).'x('.str_repeat("\u{E9}", 62);

        [$line, $caret] = explode("\n", Snippet::render($pattern, 125));

        $this->assertTrue(mb_check_encoding($line, 'UTF-8'), bin2hex($line));
        $this->assertStringStartsWith("Line 1: ...\u{E9}", $line);
        $this->assertStringEndsWith("\u{E9}...", $line);
        // The caret stands in the column the "(" is drawn in.
        $this->assertSame(mb_strlen(substr($line, 0, (int) strpos($line, '(')), 'UTF-8'), strpos($caret, '^'));
    }

    /**
     * A pattern that is no UTF-8 is cut by bytes, as before.
     */
    #[Test]
    public function test_a_pattern_that_is_no_utf8_is_cut_by_bytes(): void
    {
        $pattern = str_repeat("\xE9", 50).'X'.str_repeat("\xE9", 50);

        [$line] = explode("\n", Snippet::render($pattern, 50));

        $this->assertSame('Line 1: ...'.str_repeat("\xE9", 40).'X'.str_repeat("\xE9", 39).'...', $line);
    }

    /**
     * The validation snippet of a pattern whose error stands past a long run
     * of multibyte characters: "/" then 62 "é" then "(".
     */
    #[Test]
    public function test_the_validation_snippet_is_utf8(): void
    {
        $result = RegexParser::create(['cache' => null])->validate('/'.str_repeat("\u{E9}", 62).'(/');

        $this->assertFalse($result->isValid);
        $this->assertTrue(mb_check_encoding((string) $result->caretSnippet, 'UTF-8'), bin2hex((string) $result->caretSnippet));
        $this->assertStringStartsWith("Line 1: ...\u{E9}", (string) $result->caretSnippet);
    }

    /**
     * The caret stands under the fault as the line is shown: one column a
     * character, two a wide one, as a terminal draws them. In "あいう[",
     * offset 9 is the "[" past three wide characters, six columns in.
     */
    #[Test]
    public function test_the_caret_counts_the_columns_the_characters_take(): void
    {
        $this->assertSame("Line 1: あいう[\n".str_repeat(' ', 8 + 6).'^', Snippet::render('あいう[', 9));
        $this->assertSame("Line 1: \u{E9}t\u{E9}(\n".str_repeat(' ', 8 + 3).'^', Snippet::render("\u{E9}t\u{E9}(", 5));
        // An emoji is two columns on every PHP, whatever mbstring's tables say.
        $this->assertSame("Line 1: \u{1F600}(\n".str_repeat(' ', 8 + 2).'^', Snippet::render("\u{1F600}(", 4));
        // A line that is not UTF-8 is counted in bytes.
        $this->assertSame("Line 1: \xE9t(\n".str_repeat(' ', 8 + 2).'^', Snippet::render("\xE9t(", 2));
    }
}
