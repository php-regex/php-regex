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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CharSetAnalyzerTest extends TestCase
{
    #[Test]
    public function test_empty_literal_returns_empty_charset(): void
    {
        $analyzer = new CharSetAnalyzer();
        $set = $analyzer->firstChars(new LiteralNode('', 0, 0));

        $this->assertTrue($set->isEmpty());
    }

    #[Test]
    public function test_unicode_mode_char_types_return_unknown(): void
    {
        $analyzer = new CharSetAnalyzer('u');
        $set = $analyzer->firstChars(new CharTypeNode('d', 0, 0));

        $this->assertTrue($set->isUnknown());
    }

    #[Test]
    public function test_dot_excludes_newline_without_the_s_flag(): void
    {
        $analyzer = new CharSetAnalyzer();
        $set = $analyzer->firstChars(new DotNode(0, 0));

        $this->assertFalse($set->intersects(ByteCharSet::fromChar("\n")));
        $this->assertTrue($set->intersects(ByteCharSet::fromChar('x')));
    }

    #[Test]
    public function test_dot_covers_newline_with_the_s_flag(): void
    {
        $analyzer = new CharSetAnalyzer('s');
        $set = $analyzer->firstChars(new DotNode(0, 0));

        $this->assertTrue($set->intersects(ByteCharSet::fromChar("\n")));
    }

    /**
     * @return iterable<string, array{type: string, newline: bool}>
     */
    public static function provideNewlineShorthands(): iterable
    {
        yield 'vertical whitespace contains the newline' => ['type' => 'v', 'newline' => true];

        yield 'line break contains the newline' => ['type' => 'R', 'newline' => true];

        yield 'negated vertical whitespace excludes it' => ['type' => 'V', 'newline' => false];

        yield 'horizontal whitespace excludes it' => ['type' => 'h', 'newline' => false];

        yield 'negated horizontal whitespace contains it' => ['type' => 'H', 'newline' => true];
    }

    #[DataProvider('provideNewlineShorthands')]
    #[Test]
    public function test_newline_shorthands_resolve_to_known_sets(string $type, bool $newline): void
    {
        $set = (new CharSetAnalyzer())->firstChars(new CharTypeNode($type, 0, 0));

        $this->assertFalse($set->isUnknown());
        $this->assertSame($newline, $set->intersects(ByteCharSet::fromChar("\n")));
    }

    #[Test]
    public function test_char_type_digit_range_is_supported_without_unicode_flag(): void
    {
        $analyzer = new CharSetAnalyzer();
        $set = $analyzer->firstChars(new CharTypeNode('d', 0, 0));

        $this->assertFalse($set->isUnknown());
        $this->assertTrue($set->intersects($set));
    }

    #[Test]
    public function test_char_type_word_complement_is_supported_without_unicode_flag(): void
    {
        $analyzer = new CharSetAnalyzer();
        $set = $analyzer->firstChars(new CharTypeNode('W', 0, 0));

        $this->assertFalse($set->isUnknown());
    }

    #[Test]
    public function test_char_type_digit_complement_is_supported_without_unicode_flag(): void
    {
        $analyzer = new CharSetAnalyzer();
        $set = $analyzer->firstChars(new CharTypeNode('D', 0, 0));

        $this->assertFalse($set->isUnknown());
    }

    #[Test]
    public function test_range_with_empty_literal_returns_unknown(): void
    {
        $analyzer = new CharSetAnalyzer();
        $range = new RangeNode(new LiteralNode('', 0, 0), new LiteralNode('a', 0, 0), 0, 0);

        $set = $analyzer->firstChars($range);

        $this->assertTrue($set->isUnknown());
    }

    #[Test]
    public function test_an_unreadable_quantifier_is_not_optional(): void
    {
        $quantified = new SequenceNode([
            new QuantifierNode(new LiteralNode('x', 0, 1), 'invalid', QuantifierType::Greedy, 0, 8),
            new LiteralNode('y', 8, 9),
        ], 0, 9);

        $this->assertFalse((new CharSetAnalyzer())->firstChars($quantified)->intersects(ByteCharSet::fromChar('y')));
    }

    #[Test]
    public function test_is_optional_node_sequence_returns_true_when_all_optional(): void
    {
        $analyzer = new CharSetAnalyzer();
        $method = (new \ReflectionClass($analyzer))->getMethod('isOptionalNode');
        $sequence = new SequenceNode([
            new LiteralNode('', 0, 0),
            new LiteralNode('', 0, 0),
        ], 0, 0);

        $this->assertTrue($method->invoke($analyzer, $sequence, true));
    }

    /**
     * The newline convention a pattern opens with decides which bytes the
     * dot stops at; the last newline option wins. Under (*CRLF) a lone "\r"
     * or "\n" is an ordinary character, only the pair is a newline.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideNewlineConventions(): iterable
    {
        yield 'no option' => ['pattern' => '/a.b/'];
        yield 'LF' => ['pattern' => '/(*LF)a.b/'];
        yield 'CR' => ['pattern' => '/(*CR)a.b/'];
        yield 'CRLF' => ['pattern' => '/(*CRLF)a.b/'];
        yield 'NUL' => ['pattern' => '/(*NUL)a.b/'];
        yield 'ANYCRLF' => ['pattern' => '/(*ANYCRLF)a.b/'];
        yield 'ANY' => ['pattern' => '/(*ANY)a.b/'];
        yield 'CR then LF, the last one wins' => ['pattern' => '/(*CR)(*LF)a.b/'];
        yield 'LF then CR, the last one wins' => ['pattern' => '/(*LF)(*CR)a.b/'];
        yield 'CR after another option' => ['pattern' => '/(*LIMIT_MATCH=10)(*CR)a.b/'];
        yield 'CR read by the dot in a later group' => ['pattern' => '/(*CR)a(?:.)b/'];
    }

    #[Test]
    #[DataProvider('provideNewlineConventions')]
    public function test_dot_follows_the_newline_convention_of_the_pattern(string $pattern): void
    {
        $set = CharSetAnalyzer::forRegex(RegexParser::create()->parse($pattern))->firstChars(new DotNode(0, 0));

        foreach (["\n", "\r", "\0", "\v", "\f", 'x'] as $char) {
            $this->assertSame(
                1 === preg_match($pattern, 'a'.$char.'b'),
                $set->intersects(ByteCharSet::fromChar($char)),
                \sprintf('%s on byte 0x%s', $pattern, bin2hex($char)),
            );
        }
    }

    #[Test]
    public function test_flags_given_later_keep_the_newline_convention(): void
    {
        $analyzer = CharSetAnalyzer::forRegex(RegexParser::create()->parse('/(*NUL)a.b/'));

        $this->assertTrue($analyzer->withFlags('')->firstChars(new DotNode(0, 0))->intersects(ByteCharSet::fromChar("\n")));
        $this->assertFalse($analyzer->withFlags('')->firstChars(new DotNode(0, 0))->intersects(ByteCharSet::fromChar("\0")));
        $this->assertTrue($analyzer->withFlags('s')->firstChars(new DotNode(0, 0))->intersects(ByteCharSet::fromChar("\0")));
    }

    /**
     * A counted quantifier repeats its atom at least its minimum, lazy or
     * not: "/^x{2}?y$/" does not match "y". "{,5}" is a quantifier from
     * PCRE2 10.43, with no minimum ("/^x{,5}y$/" matches "y" on 10.49), and
     * text before it ("x{,5}y" on 10.42).
     *
     * @param list<string> $first
     */
    #[Test]
    #[DataProvider('provideCountedQuantifiers')]
    public function test_a_counted_quantifier_keeps_its_minimum(string $pattern, string $target, array $first): void
    {
        $regex = RegexParser::create(['cache' => null, 'pcre_version' => $target])->parse($pattern);
        $set = CharSetAnalyzer::forRegex($regex)->firstChars($regex);

        foreach (['x', 'y', '{'] as $char) {
            $this->assertSame(\in_array($char, $first, true), $set->intersects(ByteCharSet::fromChar($char)), $pattern.' on '.$target.': '.$char);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, target: string, first: list<string>}>
     */
    public static function provideCountedQuantifiers(): iterable
    {
        foreach (['10.42', '10.43'] as $target) {
            yield 'lazy exact count on '.$target => ['pattern' => '/x{2}?y/', 'target' => $target, 'first' => ['x']];
            yield 'lazy range on '.$target => ['pattern' => '/x{2,4}?y/', 'target' => $target, 'first' => ['x']];
            yield 'lazy open range on '.$target => ['pattern' => '/x{2,}?y/', 'target' => $target, 'first' => ['x']];
            yield 'spaced zero count on '.$target => ['pattern' => '/x{ 0 }y/', 'target' => $target, 'first' => '10.42' === $target ? ['x'] : ['x', 'y']];
        }

        yield 'no minimum on 10.42' => ['pattern' => '/x{,5}y/', 'target' => '10.42', 'first' => ['x']];
        yield 'no minimum on 10.43' => ['pattern' => '/x{,5}y/', 'target' => '10.43', 'first' => ['x', 'y']];
    }

    #[Test]
    public function test_a_lazy_count_written_with_its_suffix_keeps_its_minimum(): void
    {
        $quantified = new SequenceNode([
            new QuantifierNode(new LiteralNode('x', 0, 1), '{2}?', QuantifierType::Lazy, 0, 5),
            new LiteralNode('y', 5, 6),
        ], 0, 6);

        $set = (new CharSetAnalyzer())->firstChars($quantified);

        $this->assertFalse($set->intersects(ByteCharSet::fromChar('y')));
    }

    /**
     * A script run matches what its body matches, held to one script: its
     * characters are those of the body. One whose body can match nothing
     * lets the next item lead: "/^(*sr:a?)b/" and "/^(*sr:)b/" match "b"
     * (PHP 8.4.27, PCRE2 10.49), "/^(*asr:\d+)x/" does not match "x".
     *
     * @param list<string> $in
     * @param list<string> $out
     */
    #[Test]
    #[DataProvider('provideScriptRuns')]
    public function test_a_script_run_reads_the_set_of_its_body(string $pattern, bool $fromStart, array $in, array $out): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);
        $regex = RegexParser::create(['cache' => null])->parse($pattern);
        $analyzer = CharSetAnalyzer::forRegex($regex);
        $set = $fromStart ? $analyzer->firstChars($regex) : $analyzer->lastChars($regex);

        $this->assertFalse($set->isUnknown(), $pattern);
        foreach ($in as $char) {
            $this->assertTrue($set->intersects(ByteCharSet::fromChar($char)), $pattern.': '.$char);
        }
        foreach ($out as $char) {
            $this->assertFalse($set->intersects(ByteCharSet::fromChar($char)), $pattern.': '.$char);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, fromStart: bool, in: list<string>, out: list<string>}>
     */
    public static function provideScriptRuns(): iterable
    {
        yield 'first characters of a script run' => ['pattern' => '/(*sr:ab)c/', 'fromStart' => true, 'in' => ['a'], 'out' => ['b', 'c']];
        yield 'last characters of a script run' => ['pattern' => '/a(*script_run:bc)/', 'fromStart' => false, 'in' => ['c'], 'out' => ['a', 'b']];
        yield 'an atomic script run' => ['pattern' => '/(*asr:\d+)x/', 'fromStart' => true, 'in' => ['0', '9'], 'out' => ['x', 'a']];
        yield 'a body that can match nothing' => ['pattern' => '/(*sr:a?)b/', 'fromStart' => true, 'in' => ['a', 'b'], 'out' => ['c']];
        yield 'an empty script run' => ['pattern' => '/(*sr:)b/', 'fromStart' => true, 'in' => ['b'], 'out' => ['a']];
        yield 'a script run that matches something' => ['pattern' => '/(*sr:a+)b/', 'fromStart' => true, 'in' => ['a'], 'out' => ['b']];
    }

    #[Test]
    public function test_a_script_run_without_its_body_is_unknown(): void
    {
        $this->assertTrue((new CharSetAnalyzer())->firstChars(new ScriptRunNode('', 0, 6))->isUnknown());
    }
}
