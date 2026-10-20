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

namespace PHPRegex\Tests\Unit\Parser\Printer;

use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Printer\DelimiterBrackets;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PHP finds where a delimited pattern ends by its own reading of the body:
 * it pairs each backslash with the character after it and, for a bracket
 * delimiter, counts the brackets it does not see escaped. A rewritten body
 * must end where PCRE reads its end. Every expected body below is checked
 * against preg_match (PHP 8.4.26 / PCRE2 10.49).
 */
final class DelimiterBracketsTest extends TestCase
{
    #[Test]
    #[DataProvider('provideUnpairedBrackets')]
    public function test_balanced_escapes_the_brackets_php_would_not_pair(string $body, string $opening, string $closing, string $expected): void
    {
        $balanced = DelimiterBrackets::balanced($body, $opening, $closing);

        $this->assertSame($expected, $balanced);
        // The printer spells "\c\" before a bracket afterwards.
        $pattern = $opening.DelimiterBrackets::spelledToEnd($balanced, $opening, $closing).$closing;
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);
    }

    /**
     * @return iterable<string, array{body: string, opening: string, closing: string, expected: string}>
     */
    public static function provideUnpairedBrackets(): iterable
    {
        yield 'an opening brace alone' => ['body' => 'a{b', 'opening' => '{', 'closing' => '}', 'expected' => 'a\{b'];
        yield 'a closing brace before an opening one' => ['body' => 'a}b{c', 'opening' => '{', 'closing' => '}', 'expected' => 'a\}b\{c'];
        yield 'a closing brace after an escaped opening one' => ['body' => '\{a}', 'opening' => '{', 'closing' => '}', 'expected' => '\{a\}'];
        yield 'a pair, left as it is' => ['body' => 'a{b}c', 'opening' => '{', 'closing' => '}', 'expected' => 'a{b}c'];
        yield 'a parenthesis closing nothing' => ['body' => '(a)b)', 'opening' => '(', 'closing' => ')', 'expected' => '(a)b\)'];
        yield 'a square bracket closing nothing' => ['body' => '[a]]', 'opening' => '[', 'closing' => ']', 'expected' => '[a]\]'];
        // "\c\" is one control character for PCRE: the brace after it is
        // a bracket of its own.
        yield 'a brace after a control backslash' => ['body' => '\c\{a', 'opening' => '{', 'closing' => '}', 'expected' => '\c\\\{a'];
    }

    #[Test]
    #[DataProvider('provideControlBackslashes')]
    public function test_spelled_to_end_writes_a_body_php_reads_up_to_the_closing_delimiter(string $body, string $opening, string $closing, string $expected, string $subject): void
    {
        $spelled = DelimiterBrackets::spelledToEnd($body, $opening, $closing);

        $this->assertSame($expected, $spelled);
        $this->assertSame(1, preg_match($opening.$spelled.$closing, $subject), $opening.$spelled.$closing);
    }

    /**
     * @return iterable<string, array{body: string, opening: string, closing: string, expected: string, subject: string}>
     */
    public static function provideControlBackslashes(): iterable
    {
        yield 'a body PHP already reads whole' => ['body' => 'a\c\b', 'opening' => '/', 'closing' => '/', 'expected' => 'a\c\b', 'subject' => "a\x1Cb"];
        // PHP reads "\c", "\\", "/": the pattern would end there. PCRE reads
        // "\c\", "\/".
        yield 'a control backslash before an escaped slash' => ['body' => '\c\\\/', 'opening' => '/', 'closing' => '/', 'expected' => '\x1C\/', 'subject' => "\x1C/"];
        yield 'a control backslash before an escaped brace' => ['body' => 'a\c\\\}', 'opening' => '{', 'closing' => '}', 'expected' => 'a\x1C\}', 'subject' => "a\x1C}"];
        // An odd run of backslashes would escape the closing delimiter: an
        // empty "\E", which PCRE ignores, closes it.
        yield 'a body ending in a control backslash' => ['body' => 'a\c\\', 'opening' => '/', 'closing' => '/', 'expected' => 'a\c\\\E', 'subject' => "a\x1C"];
        // Once PHP and PCRE disagree, every control backslash before a
        // backslash or a delimiter is spelled: one before the delimiter
        // itself takes a backslash after it, which PCRE reads as escaping
        // the delimiter, as it read the control backslash's.
        yield 'a control backslash before the delimiter, then one before a backslash' => ['body' => 'a\\c\\#b\\c\\\\#', 'opening' => '#', 'closing' => '#', 'expected' => 'a\\x1C\\#b\\x1C\\#', 'subject' => "a\x1C#b\x1C#"];
        yield 'a control backslash before an opening brace, then one before a closing brace' => ['body' => '\\c\\{a}\\c\\\\}', 'opening' => '{', 'closing' => '}', 'expected' => '\\x1C{a}\\x1C\\}', 'subject' => "\x1C{a}\x1C}"];
        yield 'a body ending in an escaped backslash' => ['body' => 'a\\\\', 'opening' => '/', 'closing' => '/', 'expected' => 'a\\\\', 'subject' => 'a\\'];
    }

    #[Test]
    public function test_spelled_to_end_spells_where_a_closing_bracket_stands_bare_before_its_partner(): void
    {
        // PHP reads "\c", "\\", then a bare "}": the body would end there,
        // whatever the brackets after it.
        $this->assertSame('\\x1C\\}{{a}', DelimiterBrackets::spelledToEnd('\\c\\\\}{{a}', '{', '}'));
    }

    #[Test]
    #[DataProvider('provideMarkedBrackets')]
    public function test_marked_balanced_counts_a_literal_bracket_only_where_it_pairs(string $body, string $expected, string $subject): void
    {
        $balanced = DelimiterBrackets::markedBalanced($body, "\x03", '{', '}');

        $this->assertSame($expected, $balanced);
        $this->assertSame(1, preg_match('{'.$balanced.'}', $subject), '{'.$balanced.'}');
    }

    /**
     * @return iterable<string, array{body: string, expected: string, subject: string}>
     */
    public static function provideMarkedBrackets(): iterable
    {
        yield 'an opening brace nothing pairs' => ['body' => "a\x03{b", 'expected' => 'a\{b', 'subject' => 'a{b'];
        yield 'a closing brace a mark name pairs' => ['body' => "(*MARK:{)a\x03}", 'expected' => '(*MARK:{)a}', 'subject' => 'a}'];
        // Unescaped, "{2}" would read as a repeat.
        yield 'a brace before a count, quoted' => ['body' => "\x03{2\x03}(*MARK:})", 'expected' => '\Q{\E2\}(*MARK:})', 'subject' => '{2}'];
        // After "\d" an unescaped "{" may open the braces of an escape.
        yield 'a brace after an escape, quoted' => ['body' => "\\d\x03{a(*MARK:})", 'expected' => '\d\Q{\Ea(*MARK:})', 'subject' => '1{a'];
        yield 'a brace marked to be quoted' => ['body' => "\x03Q{a(*MARK:})", 'expected' => '\Q{\Ea(*MARK:})', 'subject' => '{a'];
    }

    #[Test]
    public function test_quoted_spans_skip_a_quote_in_a_comment_or_a_name(): void
    {
        $regex = $this->parse('{\Qa\E(?#\Q)b(*MARK:\Q)\Qc}');

        $this->assertSame([[0, 5], [22, 25]], DelimiterBrackets::quotedSpans((string) $regex->source, $regex->pattern));
        $this->assertSame([[1, 7]], DelimiterBrackets::quotedSpans('a\Qb{\E}c', $this->parse('{a\Qb{\E}c}')->pattern));
        $this->assertSame([[1, 3]], DelimiterBrackets::quotedSpans('a\Q', $this->parse('/a\Q/')->pattern));
        // "\\Q" is an escaped backslash, then "Q".
        $this->assertSame([], DelimiterBrackets::quotedSpans('\\\\Qa', $this->parse('/\\\\Qa/')->pattern));
    }

    #[Test]
    public function test_as_written_keeps_the_comment_that_balances_a_mark_name(): void
    {
        // Oracle: dropped with its comment, the brace of the name is alone,
        // and PHP reads no end.
        $this->assertSame(0, preg_match('{(*MARK:{)a(?#})b}', ''));
        $this->assertFalse($this->compiles('{(*MARK:{)ab}'));

        $regex = $this->parse('{(*MARK:{)a(?#})b}');
        $comment = $this->comment($regex->pattern);

        $this->assertSame([[spl_object_id($comment) => '}'], []], DelimiterBrackets::asWritten($regex, $regex->source, [], true));
        // Laid out, every comment stays: none needs naming.
        $this->assertSame([[], []], DelimiterBrackets::asWritten($regex, $regex->source, [], false));
    }

    #[Test]
    public function test_as_written_keeps_the_quoted_bracket_that_balances_a_mark_name(): void
    {
        $this->assertSame(1, preg_match('{(*MARK:{)a\Q}\E}', 'a}'));

        $regex = $this->parse('{(*MARK:{)a\Q}\E}');
        $source = (string) $regex->source;

        $this->assertSame([[], [12 => true]], DelimiterBrackets::asWritten($regex, $source, DelimiterBrackets::quotedSpans($source, $regex->pattern), false));
    }

    #[Test]
    public function test_as_written_keeps_nothing_without_a_bracket_delimiter_or_a_source(): void
    {
        $regex = $this->parse('/a(?#})b/');
        $this->assertSame([[], []], DelimiterBrackets::asWritten($regex, $regex->source, [], true));

        $braced = $this->parse('{(*MARK:{)a(?#})b}');
        $this->assertSame([[], []], DelimiterBrackets::asWritten($braced, null, [], true));
    }

    #[Test]
    public function test_as_written_drops_a_comment_bracket_a_literal_balances(): void
    {
        // The comment's brace pairs with a literal one, which PHP stops
        // counting once escaped: neither is needed.
        $this->assertSame(1, preg_match('{a(?#{)b\}}}', 'ab}}'));

        $regex = $this->parse('{a(?#{)b\}}}');
        $this->assertSame([[], []], DelimiterBrackets::asWritten($regex, $regex->source, [], true));
    }

    private function compiles(string $pattern): bool
    {
        return false !== @preg_match($pattern, '');
    }

    private function parse(string $pattern): RegexNode
    {
        return RegexParser::create(['cache' => null])->parse($pattern);
    }

    private function comment(NodeInterface $node): CommentNode
    {
        if ($node instanceof CommentNode) {
            return $node;
        }

        foreach ($node->getChildren() as $child) {
            try {
                return $this->comment($child);
            } catch (\LogicException) {
            }
        }

        throw new \LogicException('No comment in the tree.');
    }
}
