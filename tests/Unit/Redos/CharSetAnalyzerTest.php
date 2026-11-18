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
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CharSetAnalyzerTest extends TestCase
{
    use PcreGates;

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

    /**
     * Read above ASCII, as the ReDoS analysis reads them, the sets hold the
     * characters PCRE reads. Without UTF a character is a byte: the set of
     * an atom is the set of bytes the engine matches with it, above ASCII
     * as below, whether the atom is written with an escape or not.
     */
    #[Test]
    #[DataProvider('provideAtomsReadOnBytes')]
    public function test_a_set_read_on_bytes_is_the_set_the_engine_matches(string $options, string $atom, string $flags): void
    {
        $this->requiresCompile('/'.$options.$atom.'/'.$flags);
        [$analyzer, $node] = self::atom('/'.$options.$atom.'/'.$flags);
        $first = $analyzer->firstChars($node);
        $last = $analyzer->lastChars($node);

        $this->assertFalse($first->isUnknown(), $atom);
        $this->assertFalse($last->isUnknown(), $atom);
        for ($byte = 0; $byte <= 0xFF; $byte++) {
            $matched = 1 === preg_match('/'.$options.'^(?:'.$atom.')\z/'.$flags, \chr($byte));
            $this->assertSame($matched, $first->intersects(ByteCharSet::fromChar(\chr($byte))), \sprintf('%s%s first, byte 0x%02X', $options, $atom, $byte));
            $this->assertSame($matched, $last->intersects(ByteCharSet::fromChar(\chr($byte))), \sprintf('%s%s last, byte 0x%02X', $options, $atom, $byte));
        }
    }

    /**
     * @return iterable<string, array{options: string, atom: string, flags: string}>
     */
    public static function provideAtomsReadOnBytes(): iterable
    {
        yield 'a hex range' => ['options' => '', 'atom' => '[\xC2-\xDF]', 'flags' => ''];
        yield 'a hex range of continuation bytes' => ['options' => '', 'atom' => '[\x80-\xBF]', 'flags' => ''];
        yield 'a braced hex range' => ['options' => '', 'atom' => '[\x{C2}-\x{DF}]', 'flags' => ''];
        yield 'a legacy octal range' => ['options' => '', 'atom' => '[\302-\337]', 'flags' => ''];
        yield 'a braced octal range' => ['options' => '', 'atom' => '[\o{302}-\o{337}]', 'flags' => ''];
        yield 'a range from an escape to a letter' => ['options' => '', 'atom' => '[\x20-z]', 'flags' => ''];
        yield 'a hex member beside a letter' => ['options' => '', 'atom' => '[a\xE9]', 'flags' => ''];
        yield 'the NUL octal escape' => ['options' => '', 'atom' => '[\0]', 'flags' => ''];
        yield 'a range of control characters' => ['options' => '', 'atom' => '[\cA-\cZ]', 'flags' => ''];
        yield 'a hex escape outside a class' => ['options' => '', 'atom' => '\xE9', 'flags' => ''];
        yield 'a negated hex range' => ['options' => '', 'atom' => '[^\x80-\xBF]', 'flags' => ''];
        yield 'a negated class of a letter' => ['options' => '', 'atom' => '[^a]', 'flags' => ''];
        yield 'a class of a two-byte letter' => ['options' => '', 'atom' => '[é]', 'flags' => ''];
        yield 'a negated class of a two-byte letter' => ['options' => '', 'atom' => '[^é]', 'flags' => ''];
        yield 'the dot' => ['options' => '', 'atom' => '.', 'flags' => ''];
        yield 'the dot under (*ANY)' => ['options' => '(*ANY)', 'atom' => '.', 'flags' => ''];
        yield 'not a digit' => ['options' => '', 'atom' => '\D', 'flags' => ''];
        yield 'not a word character' => ['options' => '', 'atom' => '\W', 'flags' => ''];
        yield 'not a space' => ['options' => '', 'atom' => '\S', 'flags' => ''];
        yield 'a horizontal space' => ['options' => '', 'atom' => '\h', 'flags' => ''];
        yield 'not a horizontal space' => ['options' => '', 'atom' => '\H', 'flags' => ''];
        yield 'a vertical space' => ['options' => '', 'atom' => '\v', 'flags' => ''];
        yield 'not a vertical space' => ['options' => '', 'atom' => '\V', 'flags' => ''];
        yield 'a line break' => ['options' => '', 'atom' => '\R', 'flags' => ''];
        yield 'a space under (*UCP)' => ['options' => '(*UCP)', 'atom' => '\s', 'flags' => ''];
        yield 'not a space under (*UCP)' => ['options' => '(*UCP)', 'atom' => '\S', 'flags' => ''];
        yield 'a hex range under i' => ['options' => '', 'atom' => '[\xC2-\xDF]', 'flags' => 'i'];
        yield 'a negated hex range under i' => ['options' => '', 'atom' => '[^\x80-\xBF]', 'flags' => 'i'];
        yield 'a hex digit range under i' => ['options' => '', 'atom' => '[\x30-\x39]', 'flags' => 'i'];
        // Extended classes, from PCRE2 10.45.
        yield 'an extended class difference' => ['options' => '', 'atom' => '(?[ [\x80-\xFF] - [\xC0-\xFF] ])', 'flags' => ''];
        yield 'an extended class complement' => ['options' => '', 'atom' => '(?[ ![\x00-\x7F] ])', 'flags' => ''];
        yield 'an extended class symmetric difference' => ['options' => '', 'atom' => '(?[ [\x80-\xFF] ^ [\xC0-\xFF\x41] ])', 'flags' => ''];
    }

    /**
     * Under UTF a character above ASCII is two to four bytes, and a set
     * knows it by the byte it starts with, as the first character of an
     * atom and as its last: both sets hold that byte for each character the
     * engine matches, so the last character of one atom meets the first of
     * the next when they are the same. An ASCII character is in a set
     * exactly when the engine matches it.
     */
    #[Test]
    #[DataProvider('provideAtomsReadOnCharacters')]
    public function test_a_set_read_under_utf_holds_the_first_byte_of_what_the_engine_matches(string $options, string $atom, string $flags): void
    {
        $this->requiresCompile('/'.$options.$atom.'/'.$flags);
        [$analyzer, $node] = self::atom('/'.$options.$atom.'/'.$flags);
        $first = $analyzer->firstChars($node);
        $last = $analyzer->lastChars($node);

        $this->assertFalse($first->isUnknown(), $atom);
        $this->assertFalse($last->isUnknown(), $atom);
        foreach (self::characters() as $char) {
            $matched = 1 === preg_match('/'.$options.'^(?:'.$atom.')\z/'.$flags, $char);
            if (1 === \strlen($char)) {
                $this->assertSame($matched, $first->intersects(ByteCharSet::fromChar($char)), \sprintf('%s%s first, %s', $options, $atom, bin2hex($char)));
                $this->assertSame($matched, $last->intersects(ByteCharSet::fromChar($char)), \sprintf('%s%s last, %s', $options, $atom, bin2hex($char)));
            } elseif ($matched) {
                $this->assertTrue($first->intersects(ByteCharSet::fromChar($char[0])), \sprintf('%s%s first, %s', $options, $atom, bin2hex($char)));
                $this->assertTrue($last->intersects(ByteCharSet::fromChar($char[0])), \sprintf('%s%s last, %s', $options, $atom, bin2hex($char)));
            }
        }
    }

    /**
     * @return iterable<string, array{options: string, atom: string, flags: string}>
     */
    public static function provideAtomsReadOnCharacters(): iterable
    {
        yield 'a hex range' => ['options' => '', 'atom' => '[\xC2-\xDF]', 'flags' => 'u'];
        yield 'a hex range of C1 controls and symbols' => ['options' => '', 'atom' => '[\x80-\xBF]', 'flags' => 'u'];
        yield 'a hex range under (*UTF)' => ['options' => '(*UTF)', 'atom' => '[\xC2-\xDF]', 'flags' => ''];
        yield 'a range across every length' => ['options' => '', 'atom' => '[\x{20}-\x{10FFFF}]', 'flags' => 'u'];
        yield 'a range of two-byte letters' => ['options' => '', 'atom' => '[à-é]', 'flags' => 'u'];
        yield 'a range from a letter to a code point above ASCII' => ['options' => '', 'atom' => '[a-\x{100}]', 'flags' => 'u'];
        yield 'a hex escape' => ['options' => '', 'atom' => '\xE9', 'flags' => 'u'];
        yield 'a named code point' => ['options' => '', 'atom' => '\N{U+212A}', 'flags' => 'u'];
        yield 'a negated hex range' => ['options' => '', 'atom' => '[^\x80-\xBF]', 'flags' => 'u'];
        yield 'a negated class of a letter' => ['options' => '', 'atom' => '[^a]', 'flags' => 'u'];
        yield 'a negated class of a two-byte letter' => ['options' => '', 'atom' => '[^é]', 'flags' => 'u'];
        yield 'the dot' => ['options' => '', 'atom' => '.', 'flags' => 'u'];
        yield 'the dot under (*ANY)' => ['options' => '(*ANY)', 'atom' => '.', 'flags' => 'u'];
        yield 'a space' => ['options' => '', 'atom' => '\s', 'flags' => 'u'];
        yield 'not a space' => ['options' => '', 'atom' => '\S', 'flags' => 'u'];
        yield 'a horizontal space' => ['options' => '', 'atom' => '\h', 'flags' => 'u'];
        yield 'not a horizontal space' => ['options' => '', 'atom' => '\H', 'flags' => 'u'];
        yield 'a vertical space' => ['options' => '', 'atom' => '\v', 'flags' => 'u'];
        yield 'a line break' => ['options' => '', 'atom' => '\R', 'flags' => 'u'];
        yield 'a horizontal space under (*UTF)' => ['options' => '(*UTF)', 'atom' => '\h', 'flags' => ''];
        // Extended classes, from PCRE2 10.45.
        yield 'an extended class complement' => ['options' => '', 'atom' => '(?[ ![\x00-\x7F] ])', 'flags' => 'u'];
        yield 'an extended class difference' => ['options' => '', 'atom' => '(?[ [\x{80}-\x{10FFFF}] - [\xC0-\xFF] ])', 'flags' => 'u'];
        yield 'an extended class symmetric difference' => ['options' => '', 'atom' => '(?[ [a-z] ^ [\x{E0}-\x{FF}] ])', 'flags' => 'u'];
    }

    /**
     * Under i a letter is matched in either case: "\x41" takes "a", and
     * under UTF "\x{212A}", the Kelvin sign, takes "k". An escape the case
     * may change is not read, nor a range that holds one; one the case
     * leaves alone is read as it stands. An option setting may set i at
     * any point of the pattern, so the pattern as a whole is read as if it
     * did.
     */
    #[Test]
    #[DataProvider('provideEscapesTheCaseMayChange')]
    public function test_an_escape_the_case_may_change_is_not_read(string $options, string $atom, string $flags, string $other): void
    {
        $this->assertSame(1, preg_match('/'.$options.'^(?:'.$atom.')\z/'.$flags, $other), 'Oracle: the engine matches the other case.');

        [$analyzer, $node] = self::atom('/'.$options.$atom.'/'.$flags);

        $this->assertTrue($analyzer->firstChars($node)->isUnknown(), $atom);
        $this->assertTrue($analyzer->lastChars($node)->isUnknown(), $atom);
    }

    /**
     * @return iterable<string, array{options: string, atom: string, flags: string, other: string}>
     */
    public static function provideEscapesTheCaseMayChange(): iterable
    {
        yield 'a letter' => ['options' => '', 'atom' => '\x41', 'flags' => 'i', 'other' => 'a'];
        yield 'a range that holds letters' => ['options' => '', 'atom' => '[\x20-\x7E]', 'flags' => 'i', 'other' => 'a'];
        yield 'a range of letters in octal' => ['options' => '', 'atom' => '[\101-\132]', 'flags' => 'i', 'other' => 'q'];
        yield 'a letter under UTF' => ['options' => '', 'atom' => '\x{4B}', 'flags' => 'iu', 'other' => "\u{212A}"];
        yield 'the Kelvin sign' => ['options' => '', 'atom' => '\x{212A}', 'flags' => 'iu', 'other' => 'k'];
        yield 'a Latin-1 letter under UTF' => ['options' => '', 'atom' => '\xB5', 'flags' => 'iu', 'other' => "\u{3BC}"];
        yield 'a Latin-1 letter under (*UCP)' => ['options' => '(*UCP)', 'atom' => '\xC9', 'flags' => 'i', 'other' => "\xE9"];
        yield 'a letter after an option setting' => ['options' => '(?i)', 'atom' => '\x41', 'flags' => '', 'other' => 'a'];
        yield 'a letter after a reset that sets i' => ['options' => '(?^i)', 'atom' => '\x41', 'flags' => '', 'other' => 'a'];
    }

    /**
     * Read for the linter, whose rules ask the engine and the tables of the
     * target's locale above ASCII, a set stops at ASCII: an escape above it
     * is not known, the complement of a class holds no byte above it, and
     * an escape within it is read as the character it names.
     */
    #[Test]
    public function test_read_in_ascii_a_set_stops_there(): void
    {
        [$analyzer, $node] = self::atom('/[\xC2-\xDF]/', false);
        $this->assertTrue($analyzer->firstChars($node)->isUnknown());

        [$analyzer, $node] = self::atom('/\xE9/', false);
        $this->assertTrue($analyzer->firstChars($node)->isUnknown());

        [$analyzer, $node] = self::atom('/[^\x00-\x1F]/', false);
        $this->assertSame(1, preg_match('/^[^\x00-\x1F]$/', "\x80"), 'Oracle: the negated class takes a byte above ASCII.');
        $this->assertFalse($analyzer->firstChars($node)->isUnknown());
        $this->assertTrue($analyzer->firstChars($node)->intersects(ByteCharSet::fromChar(' ')));
        $this->assertFalse($analyzer->firstChars($node)->intersects(ByteCharSet::fromChar("\x1F")));
        $this->assertFalse($analyzer->firstChars($node)->intersects(ByteCharSet::fromChar("\x80")));

        [$analyzer, $node] = self::atom('/\x41/', false);
        $this->assertSame(1, preg_match('/^\x41$/', 'A'));
        $this->assertTrue($analyzer->firstChars($node)->intersects(ByteCharSet::fromChar('A')));
        $this->assertFalse($analyzer->firstChars($node)->intersects(ByteCharSet::fromChar('a')));
    }

    /**
     * The analyzer of the pattern, and its last atom: the one after the
     * options it opens with.
     *
     * @return array{CharSetAnalyzer, NodeInterface}
     */
    private static function atom(string $pattern, bool $readsAboveAscii = true): array
    {
        $regex = RegexParser::create(['cache' => null])->parse($pattern);
        $node = $regex->pattern;
        if ($node instanceof SequenceNode) {
            $node = $node->children[\count($node->children) - 1];
        }

        return [CharSetAnalyzer::forRegex($regex, $readsAboveAscii), $node];
    }

    /**
     * Every ASCII character, and characters of each UTF-8 length above it:
     * the C1 controls, the Latin-1 letters, the spaces and line breaks
     * above ASCII, the letters whose other case lies elsewhere, and the
     * ends of each length.
     *
     * @return list<string>
     */
    private static function characters(): array
    {
        $codePoints = [...range(0, 0x7F), 0x80, 0x85, 0x9F, 0xA0, 0xA9, 0xB5, 0xBF, 0xC2, 0xC3, 0xDF, 0xE0, 0xE9, 0xFF, 0x100, 0x17F, 0x3BC, 0x7FF, 0x800, 0x1680, 0x2000, 0x2028, 0x212A, 0x3000, 0xFFFD, 0x10000, 0x10FFFF];

        return array_map(static fn (int $codePoint): string => (string) mb_chr($codePoint, 'UTF-8'), $codePoints);
    }
}
