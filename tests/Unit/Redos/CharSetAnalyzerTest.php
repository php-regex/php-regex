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
use PHPRegex\Parser\Engine\CharacterTables;
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
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CharSetAnalyzerTest extends TestCase
{
    use InstalledLocales;
    use PcreGates;

    /**
     * The locale of the tables a test gives.
     */
    private const GIVEN = 'xx_XX.GIVEN';

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
     * as below, whether the atom is written with an escape or not, and a
     * POSIX class as PCRE2's own tables fill it, "[:lower:]" and
     * "[:upper:]" taking every letter under i.
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
        yield 'a letter under i' => ['options' => '', 'atom' => 'a', 'flags' => 'i'];
        yield 'a hex letter under i' => ['options' => '', 'atom' => '\x41', 'flags' => 'i'];
        yield 'a range of letters under i' => ['options' => '', 'atom' => '[a-c]', 'flags' => 'i'];
        yield 'a negated letter under i' => ['options' => '', 'atom' => '[^a]', 'flags' => 'i'];
        yield 'a class of hex escapes under i' => ['options' => '', 'atom' => '[\x20-\x7E]', 'flags' => 'i'];
        yield 'Latin-1 letters under i alone' => ['options' => '', 'atom' => '[\xC0-\xC5]', 'flags' => 'i'];
        yield 'Latin-1 letters under (*UCP) and i' => ['options' => '(*UCP)', 'atom' => '[\xC0-\xC5]', 'flags' => 'i'];
        yield 'a negated Latin-1 letter under (*UCP) and i' => ['options' => '(*UCP)', 'atom' => '[^\xE9]', 'flags' => 'i'];
        yield 'the micro sign under (*UCP) and i' => ['options' => '(*UCP)', 'atom' => '\xB5', 'flags' => 'i'];
        yield 'a two-byte letter in a class under (*UCP) and i' => ['options' => '(*UCP)', 'atom' => '[é]', 'flags' => 'i'];
        yield 'a letter after an option setting' => ['options' => '(?i)', 'atom' => 'a', 'flags' => ''];
        yield 'a letter after i is turned on and off' => ['options' => '(?i)(?-i)', 'atom' => 'a', 'flags' => ''];
        yield 'a letter where i is turned off' => ['options' => '(?-i)', 'atom' => 'a', 'flags' => 'i'];
        yield 'a letter after a reset' => ['options' => '(?^)', 'atom' => 'a', 'flags' => 'i'];
        yield 'a letter in a group that turns i off' => ['options' => '', 'atom' => '(?-i:a)', 'flags' => 'i'];
        // Extended classes, from PCRE2 10.45.
        yield 'an extended class difference' => ['options' => '', 'atom' => '(?[ [\x80-\xFF] - [\xC0-\xFF] ])', 'flags' => ''];
        yield 'an extended class complement' => ['options' => '', 'atom' => '(?[ ![\x00-\x7F] ])', 'flags' => ''];
        yield 'an extended class symmetric difference' => ['options' => '', 'atom' => '(?[ [\x80-\xFF] ^ [\xC0-\xFF\x41] ])', 'flags' => ''];
        yield 'an extended class difference of a POSIX class' => ['options' => '', 'atom' => '(?[ [[:alnum:]] - [[:digit:]] ])', 'flags' => ''];
        // The POSIX classes, as PCRE2's own tables fill them.
        foreach (['alpha', 'lower', 'upper', 'alnum', 'ascii', 'blank', 'cntrl', 'digit', 'graph', 'print', 'punct', 'space', 'word', 'xdigit'] as $name) {
            yield 'the POSIX class '.$name => ['options' => '', 'atom' => '[[:'.$name.':]]', 'flags' => ''];
            yield 'the negated POSIX class '.$name => ['options' => '', 'atom' => '[[:^'.$name.':]]', 'flags' => ''];
            yield 'the POSIX class '.$name.' under i' => ['options' => '', 'atom' => '[[:'.$name.':]]', 'flags' => 'i'];
        }
        yield 'a negated class of a POSIX class' => ['options' => '', 'atom' => '[^[:alpha:]]', 'flags' => ''];
        yield 'a POSIX class beside a letter' => ['options' => '', 'atom' => '[x[:digit:]]', 'flags' => ''];
        yield 'a POSIX class beside a letter under i' => ['options' => '', 'atom' => '[x[:digit:]]', 'flags' => 'i'];
        yield 'a POSIX class beside a byte above ASCII' => ['options' => '', 'atom' => '[\xE9[:punct:]]', 'flags' => ''];
        yield 'two POSIX classes, one negated' => ['options' => '', 'atom' => '[[:digit:][:^alnum:]]', 'flags' => ''];
        yield 'no POSIX small letter under i' => ['options' => '', 'atom' => '[[:^lower:]]', 'flags' => 'i'];
        yield 'a negated class of POSIX capitals under i' => ['options' => '', 'atom' => '[^[:upper:]]', 'flags' => 'i'];
        yield 'POSIX capitals in a group that sets i' => ['options' => '', 'atom' => '(?i:[[:upper:]])', 'flags' => ''];
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
        yield 'a letter under iu' => ['options' => '', 'atom' => 'k', 'flags' => 'iu'];
        yield 'the Kelvin sign under iu' => ['options' => '', 'atom' => '\x{212A}', 'flags' => 'iu'];
        yield 'the long s under iu' => ['options' => '', 'atom' => '\x{17F}', 'flags' => 'iu'];
        yield 'a range of letters under iu' => ['options' => '', 'atom' => '[a-z]', 'flags' => 'iu'];
        yield 'a two-byte letter under iu' => ['options' => '', 'atom' => 'é', 'flags' => 'iu'];
        yield 'the micro sign under iu' => ['options' => '', 'atom' => '\xB5', 'flags' => 'iu'];
        yield 'a negated letter under iu' => ['options' => '', 'atom' => '[^k]', 'flags' => 'iu'];
        // The r modifier, from PHP 8.4 and PCRE2 10.43.
        yield 'the Kelvin sign under iur' => ['options' => '', 'atom' => '\x{212A}', 'flags' => 'iur'];
        yield 'a letter under iur' => ['options' => '', 'atom' => 'k', 'flags' => 'iur'];
        // Extended classes, from PCRE2 10.45.
        yield 'an extended class complement' => ['options' => '', 'atom' => '(?[ ![\x00-\x7F] ])', 'flags' => 'u'];
        yield 'an extended class difference' => ['options' => '', 'atom' => '(?[ [\x{80}-\x{10FFFF}] - [\xC0-\xFF] ])', 'flags' => 'u'];
        yield 'an extended class symmetric difference' => ['options' => '', 'atom' => '(?[ [a-z] ^ [\x{E0}-\x{FF}] ])', 'flags' => 'u'];
        // The POSIX classes under (*UTF) without UCP, which the tables fill.
        yield 'a POSIX class under (*UTF)' => ['options' => '(*UTF)', 'atom' => '[[:alpha:]]', 'flags' => ''];
        yield 'a negated POSIX class under (*UTF)' => ['options' => '(*UTF)', 'atom' => '[[:^alpha:]]', 'flags' => ''];
        yield 'a negated class of a POSIX class under (*UTF)' => ['options' => '(*UTF)', 'atom' => '[^[:punct:]]', 'flags' => ''];
        yield 'POSIX capitals under (*UTF) and i' => ['options' => '(*UTF)', 'atom' => '[[:upper:]]', 'flags' => 'i'];
    }

    /**
     * Under i a letter is matched in either case: "\x41" takes "a", and
     * under UTF "\x{212A}", the Kelvin sign, takes "k". A set read under i
     * holds the other case, by the byte it starts with, whether the
     * modifier or an option setting before the atom sets i.
     */
    #[Test]
    #[DataProvider('provideEscapesTheCaseMayChange')]
    public function test_a_set_read_under_i_holds_the_other_case(string $options, string $atom, string $flags, string $other): void
    {
        $this->requiresCompile('/'.$options.$atom.'/'.$flags);
        $this->assertSame(1, preg_match('/'.$options.'^(?:'.$atom.')\z/'.$flags, $other), 'Oracle: the engine matches the other case.');

        [$analyzer, $node] = self::atom('/'.$options.$atom.'/'.$flags);

        $this->assertTrue($analyzer->firstChars($node)->intersects(ByteCharSet::fromChar($other[0])), $atom);
        $this->assertTrue($analyzer->lastChars($node)->intersects(ByteCharSet::fromChar($other[0])), $atom);
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
        yield 'a letter in a group that sets i' => ['options' => '', 'atom' => '(?i:\x41)', 'flags' => '', 'other' => 'a'];
        yield 'the long s under UTF' => ['options' => '', 'atom' => '\x{17F}', 'flags' => 'iu', 'other' => 's'];
        yield 'a letter whose other case is the long s' => ['options' => '', 'atom' => 'S', 'flags' => 'iu', 'other' => "\u{17F}"];
        yield 'a capital of Latin-1 under UTF' => ['options' => '', 'atom' => 'É', 'flags' => 'iu', 'other' => 'é'];
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

        // A byte above ASCII written as it is stays that byte, and the
        // complement of a class that holds one stops at ASCII all the same.
        [$analyzer, $node] = self::atom("/\x90/", false);
        $this->assertSame([[0x90, 0x90]], $analyzer->firstChars($node)->ranges());

        [$analyzer, $node] = self::atom("/[^\xE9]/", false);
        $this->assertSame(1, preg_match("/^[^\xE9]$/", "\x90"), 'Oracle: the negated class takes a byte above ASCII.');
        $this->assertSame([[0x00, 0x7F]], $analyzer->firstChars($node)->ranges());

        // A POSIX class is read from PCRE2's own tables, which put no byte
        // above ASCII in one, and its negation stops at ASCII as well.
        [$analyzer, $node] = self::atom('/[[:alpha:]]/', false);
        $this->assertSame([1, 1, 0], [preg_match('/^[[:alpha:]]$/', 'A'), preg_match('/^[[:alpha:]]$/', 'z'), preg_match('/^[[:alpha:]]$/', "\xE9")], 'Oracle: the letters of ASCII alone.');
        $this->assertSame([[0x41, 0x5A], [0x61, 0x7A]], $analyzer->firstChars($node)->ranges());

        [$analyzer, $node] = self::atom('/[[:^alpha:]]/', false);
        $this->assertSame([0, 1, 1], [preg_match('/^[[:^alpha:]]$/', 'A'), preg_match('/^[[:^alpha:]]$/', '@'), preg_match('/^[[:^alpha:]]$/', "\xE9")], 'Oracle: every byte but the letters of ASCII.');
        $this->assertSame([[0x00, 0x40], [0x5B, 0x60], [0x7B, 0x7F]], $analyzer->firstChars($node)->ranges());
    }

    /**
     * Read for the ReDoS analysis under another locale's tables than
     * PCRE2's own, a set holds the bytes above ASCII a literal or an escape
     * writes, and those the complement of a class takes. What the tables
     * do not decide is read as under PCRE2's own: "\h", "\v" and the dot,
     * and under u the spaces and the other case of a character above
     * ASCII, which Unicode gives. Under u a set knows a character by the
     * byte it starts with.
     */
    #[Test]
    #[DataProvider('provideReadsUnderAnotherLocalesTables')]
    public function test_read_under_another_locales_tables_a_set_holds_the_bytes_the_pattern_writes(string $pattern, string $taken, string $refused): void
    {
        $this->assertSame(1, preg_match(self::anchored($pattern), $taken), \sprintf('Oracle: %s takes %s.', addcslashes($pattern, "\200..\377"), bin2hex($taken)));
        $this->assertSame(0, preg_match(self::anchored($pattern), $refused), \sprintf('Oracle: %s refuses %s.', addcslashes($pattern, "\200..\377"), bin2hex($refused)));

        [$analyzer, $node] = self::atomUnderAnotherLocalesTables($pattern);
        $set = $analyzer->firstChars($node);

        $this->assertFalse($set->isUnknown(), $pattern);
        $this->assertTrue($set->intersects(ByteCharSet::fromChar($taken)), $pattern.' takes '.bin2hex($taken));
        $this->assertFalse($set->intersects(ByteCharSet::fromChar($refused)), $pattern.' refuses '.bin2hex($refused));
    }

    /**
     * @return iterable<string, array{pattern: string, taken: string, refused: string}>
     */
    public static function provideReadsUnderAnotherLocalesTables(): iterable
    {
        yield 'a negated class' => ['pattern' => '/[^a]/', 'taken' => "\xC3", 'refused' => 'a'];
        yield 'a byte written as it is' => ['pattern' => "/\xC3/", 'taken' => "\xC3", 'refused' => "\xC2"];
        yield 'an escape above ASCII' => ['pattern' => '/\xC3/', 'taken' => "\xC3", 'refused' => "\xC2"];
        yield 'a range above ASCII' => ['pattern' => '/[\xC2-\xDF]/', 'taken' => "\xDF", 'refused' => "\xE0"];
        yield 'a negated byte above ASCII' => ['pattern' => "/[^\xE9]/", 'taken' => "\x90", 'refused' => "\xE9"];
        yield 'a negated letter of two bytes, read in bytes' => ['pattern' => '/[^é]/', 'taken' => "\x90", 'refused' => "\xA9"];
        yield 'a negated range' => ['pattern' => '/[^\x00-\x1F]/', 'taken' => "\x80", 'refused' => "\x1F"];
        yield 'a letter in its own case' => ['pattern' => '/a/', 'taken' => 'a', 'refused' => 'A'];
        yield 'the horizontal spaces' => ['pattern' => '/\h/', 'taken' => "\xA0", 'refused' => "\x85"];
        yield 'the vertical spaces' => ['pattern' => '/\v/', 'taken' => "\x85", 'refused' => "\xA0"];
        yield 'no horizontal space' => ['pattern' => '/\H/', 'taken' => "\xE9", 'refused' => "\xA0"];
        yield 'the dot' => ['pattern' => '/./', 'taken' => "\xE9", 'refused' => "\n"];
        yield 'a negated class under u' => ['pattern' => '/[^a]/u', 'taken' => 'é', 'refused' => 'a'];
        yield 'the spaces under u' => ['pattern' => '/\s/u', 'taken' => ' ', 'refused' => 'é'];
        yield 'a letter above ASCII under iu' => ['pattern' => '/é/iu', 'taken' => 'É', 'refused' => 'e'];
    }

    /**
     * Where another locale's tables are not known, or the machine cannot
     * set their locale, what they decide is not known: the bytes of "\d",
     * "\s" and "\w" without UCP, (*UTF) alone included, and the other case
     * of a character, which the tables give a byte, and under u or (*UCP)
     * an ASCII character (pcre2_compile.c reads Unicode's other case above
     * ASCII alone). The oracle shows the Latin-1 tables putting 0xE9 in
     * "\w", under (*UTF) too, and pairing it with 0xC9 under i, where
     * PCRE2's own leave it out of both; "\d" and "\s" read the tables as
     * "\w" does, which pcre2_maketables() builds from isdigit() and
     * isspace(), and the POSIX classes too, where the Latin-1 tables put
     * 0xE9 in "[:alpha:]". Read in ASCII alone, a type or a POSIX class the
     * tables give a byte above ASCII is not known either.
     */
    #[Test]
    public function test_read_where_another_locales_tables_are_not_known_what_they_decide_is_not_known(): void
    {
        $latin1 = 'fr_FR.ISO8859-1';
        $this->requiresLocale($latin1, 'What the Latin-1 tables decide');

        $read = static function (string $locale): array {
            $matches = [];
            CharacterTables::under($locale, static function () use (&$matches): void {
                $matches = [
                    preg_match('/^\w$/', "\xE9"),
                    preg_match('/^\W$/', "\xE9"),
                    preg_match("/^\xC9$/i", "\xE9"),
                    // What either path takes: the interpreter's start-up
                    // study leaves "é" out, pcre.jit=0 included.
                    max(preg_match('/(*UTF)^\w$/', 'é'), preg_match('/(*NO_JIT)(*NO_START_OPT)(*UTF)^\w$/', 'é')),
                    preg_match_all('/\d/', implode('', array_map(\chr(...), range(0, 0xFF)))),
                    preg_match('/^[[:alpha:]]$/', "\xE9"),
                    preg_match('/^[[:^alpha:]]$/', "\xE9"),
                ];
            });

            return $matches;
        };
        $this->assertSame([0, 1, 0, 0, 10, 0, 1], $read(CharacterTables::BUILT_IN), 'Oracle: under PCRE2\'s own tables.');
        $this->assertSame([1, 0, 1, 1, 10, 1, 0], $read($latin1), 'Oracle: under the Latin-1 tables.');

        foreach ([null, 'xx_YY.NOWHERE'] as $locale) {
            foreach (['/\w/', '/\W/', '/\s/', '/\S/', '/\d/', '/\D/', '/[^\w]/', '/(*UTF)\w/', '/(*UTF)\s/', '/[[:alpha:]]/', '/[[:^alpha:]]/', '/[^[:digit:]]/', '/(*UTF)[[:alpha:]]/'] as $pattern) {
                [$analyzer, $node] = self::atomUnderAnotherLocalesTables($pattern, $locale);
                $this->assertTrue($analyzer->firstChars($node)->isUnknown(), $pattern);
                $this->assertTrue($analyzer->lastChars($node)->isUnknown(), $pattern);
            }
        }

        foreach ([null, 'xx_YY.NOWHERE'] as $locale) {
            foreach (['/a/i', "/\xC9/i", '/[a-z]/i', '/[^a]/i', '/a/iu', '/(*UCP)a/i'] as $pattern) {
                [$analyzer, $node] = self::atomUnderAnotherLocalesTables($pattern, $locale);
                $this->assertTrue($analyzer->firstChars($node)->isUnknown(), $pattern);
                $this->assertTrue($analyzer->lastChars($node)->isUnknown(), $pattern);
            }
        }

        // Read in ASCII alone, or under Turkish casing, a case stays unknown
        // under the Latin-1 tables too.
        $regex = RegexParser::create(['cache' => null])->parse('/a/i');
        $this->assertTrue((new CharSetAnalyzer('i', '', false, locale: $latin1))->firstChars($regex->pattern)->isUnknown());
        $this->assertFalse((new CharSetAnalyzer('i', '', true, locale: $latin1))->firstChars($regex->pattern)->isUnknown());
        $this->assertTrue((new CharSetAnalyzer('iu', '(*TURKISH_CASING)', true, locale: $latin1))->firstChars($regex->pattern)->isUnknown());
        $this->assertFalse((new CharSetAnalyzer('iu', '', true, locale: $latin1))->firstChars($regex->pattern)->isUnknown());

        // Read in ASCII alone, "\w" holds 0xE9 under the Latin-1 tables,
        // which a set has no place for, and "\d" the ASCII digits alone.
        $this->assertTrue((new CharSetAnalyzer('', '', false, locale: $latin1))->firstChars(RegexParser::create(['cache' => null])->parse('/\w/')->pattern)->isUnknown());
        $this->assertSame([[0x30, 0x39]], (new CharSetAnalyzer('', '', false, locale: $latin1))->firstChars(RegexParser::create(['cache' => null])->parse('/\d/')->pattern)->ranges());
        $this->assertSame([[0x00, 0x2F], [0x3A, 0x7F]], (new CharSetAnalyzer('', '', false, locale: $latin1))->firstChars(RegexParser::create(['cache' => null])->parse('/\D/')->pattern)->ranges());
        $this->assertTrue((new CharSetAnalyzer('', '', false, locale: $latin1))->firstChars(RegexParser::create(['cache' => null])->parse('/[[:alpha:]]/')->pattern)->isUnknown());
        $this->assertSame([[0x30, 0x39]], (new CharSetAnalyzer('', '', false, locale: $latin1))->firstChars(RegexParser::create(['cache' => null])->parse('/[[:digit:]]/')->pattern)->ranges());

        // Under PCRE2's own tables each is known.
        foreach (['/\w/', '/\s/', '/\d/', '/(*UTF)\s/', '/a/i', "/\xC9/i", '/[^a]/i', '/a/iu', '/[[:alpha:]]/', '/[[:^alpha:]]/', '/(*UTF)[[:alpha:]]/'] as $pattern) {
            [$analyzer, $node] = self::atom($pattern);
            $this->assertFalse($analyzer->firstChars($node)->isUnknown(), $pattern);
        }
    }

    /**
     * Read under the tables of a locale the machine sets, a caseless item
     * takes the other case those tables give each byte, and under u or
     * (*UCP) each ASCII character: the set is what the engine matches, on
     * the JIT as on the interpreter, alone or repeated, ASCII letters
     * included, and the complement of a class leaves out the pair alone.
     * The interpreter reads ς (0xF2) alone under el_GR.ISO8859-7 as itself,
     * which the JIT, and the interpreter's repeat, pair with Σ (0xD3): the
     * set holds what either takes. Above ASCII under u or (*UCP) the case
     * is Unicode's, (*UCP) pairing ς with Ò (0xD2). Under u a set knows a
     * character by the byte it starts with.
     */
    #[Test]
    #[DataProvider('provideCaselessItemsUnderEachTables')]
    public function test_read_under_a_locales_tables_a_caseless_item_takes_the_cases_they_give(string $options, string $atom, string $flags, string $locale): void
    {
        $this->requiresLocale($locale, 'The cases a locale\'s tables give');

        $utf = str_contains($flags, 'u');
        $subjects = [];
        foreach ($utf ? [...range(0, 0x24F), 0x37E, 0x3A3, 0x3C2, 0x1E9E, 0x212A] : range(0, 0xFF) as $codePoint) {
            $subjects[] = $utf ? (string) mb_chr($codePoint, 'UTF-8') : \chr($codePoint);
        }

        // Oracle: the characters the item matches under the locale, alone
        // and repeated, on the JIT and on the interpreter, by the byte each
        // starts with. The interpreter's repeat reads a character as the
        // JIT does alone, which pcre.jit=0 leaves out.
        $taken = [];
        CharacterTables::under($locale, static function () use ($options, $atom, $flags, $subjects, &$taken): void {
            foreach ($subjects as $subject) {
                foreach (['', '(*NO_JIT)(*NO_START_OPT)'] as $engine) {
                    if (1 === preg_match('/'.$engine.$options.'^(?:'.$atom.')$/'.$flags, $subject)
                        || 1 === preg_match('/'.$engine.$options.'^'.$atom.'{2}$/'.$flags, $subject.$subject)) {
                        $taken[\ord($subject[0])] = true;
                    }
                }
            }
        });
        ksort($taken);
        $expected = ByteCharSet::empty();
        foreach (array_keys($taken) as $byte) {
            $expected = $expected->union(ByteCharSet::fromBytes($byte, $byte));
        }

        [$analyzer, $node] = self::atomUnderAnotherLocalesTables('/'.$options.$atom.'/'.$flags, $locale);
        $set = $analyzer->firstChars($node);
        $label = \sprintf('/%s%s/%s under %s', $options, addcslashes($atom, "\200..\377"), $flags, $locale);

        $this->assertFalse($set->isUnknown(), $label);
        $this->assertSame($set->ranges(), $analyzer->lastChars($node)->ranges(), $label);
        if (!$utf) {
            $this->assertSame($expected->ranges(), $set->ranges(), $label);

            return;
        }

        // Under u a byte above ASCII stands for every character it starts.
        $ascii = ByteCharSet::fromRange(0, 0x7F);
        $this->assertSame($expected->intersect($ascii)->ranges(), $set->intersect($ascii)->ranges(), $label);
        $this->assertSame($expected->ranges(), $expected->intersect($set)->ranges(), $label);
    }

    /**
     * @return iterable<string, array{options: string, atom: string, flags: string, locale: string}>
     */
    public static function provideCaselessItemsUnderEachTables(): iterable
    {
        $items = [
            'an ASCII letter' => ['', 'a', 'i'],
            'an ASCII letter written as an escape' => ['', '\x41', 'i'],
            'a byte above ASCII written as it is' => ['', "\xE9", 'i'],
            'a byte above ASCII written as an escape' => ['', '\xC9', 'i'],
            'the final sigma of Greek' => ['', '\xF2', 'i'],
            'a range of ASCII letters' => ['', '[a-z]', 'i'],
            'a range above ASCII' => ['', '[\xC0-\xDE]', 'i'],
            'a class of bytes written as they are' => ['', "[\xE0\xF2]", 'i'],
            'a negated ASCII letter' => ['', '[^a]', 'i'],
            'a negated byte above ASCII' => ['', "[^\xE9]", 'i'],
            'an ASCII letter under UCP' => ['(*UCP)', 'a', 'i'],
            'a byte above ASCII under UCP' => ['(*UCP)', '\xF2', 'i'],
            'an ASCII letter under u' => ['', 'a', 'iu'],
            'the letter k under u' => ['', 'k', 'iu'],
            'a range of ASCII letters under u' => ['', '[a-z]', 'iu'],
            'a negated ASCII letter under u' => ['', '[^a]', 'iu'],
        ];

        foreach (['fr_FR.ISO8859-1', 'el_GR.ISO8859-7', 'ru_RU.KOI8-R', 'en_US.UTF-8'] as $locale) {
            foreach ($items as $name => [$options, $atom, $flags]) {
                yield $name.', '.$locale => ['options' => $options, 'atom' => $atom, 'flags' => $flags, 'locale' => $locale];
            }
        }
    }

    /**
     * Read under the tables of a locale the machine sets, "\d", "\s" and
     * "\w" without UCP take the bytes those tables give, and their
     * complements the others: the set is what the engine matches, alone or
     * repeated, on the JIT as on the interpreter, in a class as outside it.
     * A POSIX class takes the bytes the tables give its name, its negation
     * the others, and under i "[:lower:]" and "[:upper:]" take every letter
     * those tables give "[:alpha:]". Under (*UTF) the tables name the code
     * points below 256, which the engine's paths read apart above ASCII:
     * on macOS under the Latin-1 tables a single "\w" takes é, the
     * interpreter's repeat of it does not (pcre2_match.c), and its repeat
     * of "\W" takes é. The set knows each character any of them takes by
     * the byte it starts with, and ASCII ones exactly.
     */
    #[Test]
    #[DataProvider('provideTypesUnderEachTables')]
    #[DataProvider('providePosixClassesUnderEachTables')]
    public function test_read_under_a_locales_tables_a_type_or_a_posix_class_takes_the_bytes_they_give(string $options, string $atom, string $flags, string $locale): void
    {
        $this->requiresLocale($locale, 'The bytes a locale\'s tables give a type or a POSIX class');

        $utf = str_contains($options, '(*UTF)');
        $subjects = [];
        foreach ($utf ? [...range(0, 0x24F), 0x2028, 0x3000] : range(0, 0xFF) as $codePoint) {
            $subjects[] = $utf ? (string) mb_chr($codePoint, 'UTF-8') : \chr($codePoint);
        }

        // Oracle: the characters the item matches under the locale, alone
        // and twice in a row, on the JIT and on the interpreter, with and
        // without the start optimizations, by the byte each starts with.
        $taken = [];
        CharacterTables::under($locale, static function () use ($options, $atom, $flags, $subjects, &$taken): void {
            foreach ($subjects as $subject) {
                foreach (['', '(*NO_JIT)', '(*NO_JIT)(*NO_START_OPT)'] as $engine) {
                    foreach (['' => $subject, '{2}' => $subject.$subject] as $count => $text) {
                        if (1 === preg_match('/'.$engine.$options.'^(?:'.$atom.')'.$count.'$/'.$flags, $text)) {
                            $taken[\ord($subject[0])] = true;
                        }
                    }
                }
            }
        });
        ksort($taken);
        $expected = ByteCharSet::empty();
        foreach (array_keys($taken) as $byte) {
            $expected = $expected->union(ByteCharSet::fromBytes($byte, $byte));
        }

        [$analyzer, $node] = self::atomUnderAnotherLocalesTables('/'.$options.$atom.'/'.$flags, $locale);
        $set = $analyzer->firstChars($node);
        $label = \sprintf('/%s%s/%s under %s', $options, $atom, $flags, $locale);

        $this->assertFalse($set->isUnknown(), $label);
        $this->assertSame($set->ranges(), $analyzer->lastChars($node)->ranges(), $label);
        if (!$utf) {
            $this->assertSame($expected->ranges(), $set->ranges(), $label);

            return;
        }

        // Under UTF a byte above ASCII stands for every character it starts.
        $ascii = ByteCharSet::fromRange(0, 0x7F);
        $this->assertSame($expected->intersect($ascii)->ranges(), $set->intersect($ascii)->ranges(), $label);
        $this->assertSame($expected->ranges(), $expected->intersect($set)->ranges(), $label);
    }

    /**
     * @return iterable<string, array{options: string, atom: string, flags: string, locale: string}>
     */
    public static function provideTypesUnderEachTables(): iterable
    {
        $items = [
            'a digit' => ['', '\d', ''],
            'no digit' => ['', '\D', ''],
            'a space' => ['', '\s', ''],
            'no space' => ['', '\S', ''],
            'a word character' => ['', '\w', ''],
            'no word character' => ['', '\W', ''],
            'a word character in a class' => ['', '[\w]', ''],
            'a negated class of word characters' => ['', '[^\w]', ''],
            'a class of digits and spaces' => ['', '[\d\s]', ''],
            'a negated class of digits and spaces' => ['', '[^\d\s]', ''],
            'a word character under i' => ['', '\w', 'i'],
            'no word character under i' => ['', '\W', 'i'],
            'a word character under (*UTF)' => ['(*UTF)', '\w', ''],
            'no word character under (*UTF)' => ['(*UTF)', '\W', ''],
            'a space under (*UTF)' => ['(*UTF)', '\s', ''],
            'no space under (*UTF)' => ['(*UTF)', '\S', ''],
            'a digit under (*UTF)' => ['(*UTF)', '\d', ''],
        ];

        foreach (['fr_FR.ISO8859-1', 'el_GR.ISO8859-7', 'ru_RU.KOI8-R', 'en_US.UTF-8'] as $locale) {
            foreach ($items as $name => [$options, $atom, $flags]) {
                yield $name.', '.$locale => ['options' => $options, 'atom' => $atom, 'flags' => $flags, 'locale' => $locale];
            }
        }
    }

    /**
     * @return iterable<string, array{options: string, atom: string, flags: string, locale: string}>
     */
    public static function providePosixClassesUnderEachTables(): iterable
    {
        $items = [];
        foreach (['alpha', 'lower', 'upper', 'alnum', 'ascii', 'blank', 'cntrl', 'digit', 'graph', 'print', 'punct', 'space', 'word', 'xdigit'] as $name) {
            $items['the POSIX class '.$name] = ['', '[[:'.$name.':]]', ''];
            $items['the negated POSIX class '.$name] = ['', '[[:^'.$name.':]]', ''];
        }

        $items += [
            'a negated class of a POSIX class' => ['', '[^[:alpha:]]', ''],
            'a POSIX class beside a digit' => ['', '[0[:alpha:]]', ''],
            'two POSIX classes, one negated' => ['', '[[:digit:][:^alnum:]]', ''],
            'POSIX small letters under i' => ['', '[[:lower:]]', 'i'],
            'POSIX capitals under i' => ['', '[[:upper:]]', 'i'],
            'no POSIX capital under i' => ['', '[[:^upper:]]', 'i'],
            'a negated class of POSIX small letters under i' => ['', '[^[:lower:]]', 'i'],
            'POSIX punctuation under i' => ['', '[[:punct:]]', 'i'],
            'a POSIX class under (*UTF)' => ['(*UTF)', '[[:alpha:]]', ''],
            'a negated POSIX class under (*UTF)' => ['(*UTF)', '[[:^alpha:]]', ''],
            'POSIX capitals under (*UTF) and i' => ['(*UTF)', '[[:upper:]]', 'i'],
        ];

        foreach (['fr_FR.ISO8859-1', 'el_GR.ISO8859-7', 'ru_RU.KOI8-R', 'en_US.UTF-8'] as $locale) {
            foreach ($items as $name => [$options, $atom, $flags]) {
                yield $name.', '.$locale => ['options' => $options, 'atom' => $atom, 'flags' => $flags, 'locale' => $locale];
            }
        }
    }

    /**
     * Under tables a test gives, as no machine here builds them, "\d",
     * "\s" and "\w" without UCP take the bytes given, and their
     * complements every other byte, in a class as outside it, under i as
     * without it: a type has no case. Under (*UTF) the bytes are code
     * points, known by the byte each starts with. Under UCP the tables do
     * not decide: "\d" and "\w" take Unicode's characters, which a set does
     * not hold, and "\s" Unicode's spaces, the next line 0x85 among them,
     * which the tables leave out.
     *
     * A POSIX class takes the bytes given its name, PCRE2's own where none
     * are, and its negation every other byte; under i "[:lower:]" and
     * "[:upper:]" take those of "[:alpha:]", and no POSIX class takes the
     * other case of its bytes beyond. Under UCP several POSIX classes read
     * Unicode's properties, which a set does not hold, and a name PCRE2
     * does not give has no bytes to read.
     *
     * @param list<array{0: int, 1: int}>|null $ranges the bytes of the set, null where it is not known
     */
    #[Test]
    #[DataProvider('provideTypesUnderGivenTables')]
    #[DataProvider('providePosixClassesUnderGivenTables')]
    public function test_read_under_given_tables_a_type_or_a_posix_class_takes_the_bytes_they_give(string $pattern, ?array $ranges): void
    {
        self::giveTables();
        [$analyzer, $node] = self::atomUnderAnotherLocalesTables($pattern, self::GIVEN);
        $set = $analyzer->firstChars($node);

        if (null === $ranges) {
            $this->assertTrue($set->isUnknown(), $pattern);

            return;
        }

        $this->assertSame($ranges, $set->ranges(), $pattern);
        $this->assertSame($ranges, $analyzer->lastChars($node)->ranges(), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, ranges: list<array{0: int, 1: int}>|null}>
     */
    public static function provideTypesUnderGivenTables(): iterable
    {
        yield 'a digit' => ['pattern' => '/\d/', 'ranges' => [[0x30, 0x39], [0xB2, 0xB3]]];
        yield 'no digit' => ['pattern' => '/\D/', 'ranges' => [[0x00, 0x2F], [0x3A, 0xB1], [0xB4, 0xFF]]];
        yield 'a space' => ['pattern' => '/\s/', 'ranges' => [[0x09, 0x0D], [0x20, 0x20], [0xA0, 0xA0]]];
        yield 'no space' => ['pattern' => '/\S/', 'ranges' => [[0x00, 0x08], [0x0E, 0x1F], [0x21, 0x9F], [0xA1, 0xFF]]];
        yield 'a word character' => ['pattern' => '/\w/', 'ranges' => [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A], [0xAA, 0xAA], [0xC0, 0xD6], [0xD8, 0xF6], [0xF8, 0xFF]]];
        yield 'no word character' => ['pattern' => '/\W/', 'ranges' => [[0x00, 0x2F], [0x3A, 0x40], [0x5B, 0x5E], [0x60, 0x60], [0x7B, 0xA9], [0xAB, 0xBF], [0xD7, 0xD7], [0xF7, 0xF7]]];
        yield 'a class of digits and spaces' => ['pattern' => '/[\d\s]/', 'ranges' => [[0x09, 0x0D], [0x20, 0x20], [0x30, 0x39], [0xA0, 0xA0], [0xB2, 0xB3]]];
        yield 'a negated class of digits and spaces' => ['pattern' => '/[^\d\s]/', 'ranges' => [[0x00, 0x08], [0x0E, 0x1F], [0x21, 0x2F], [0x3A, 0x9F], [0xA1, 0xB1], [0xB4, 0xFF]]];
        yield 'a word character under i, which takes no case' => ['pattern' => '/\w/i', 'ranges' => [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A], [0xAA, 0xAA], [0xC0, 0xD6], [0xD8, 0xF6], [0xF8, 0xFF]]];
        yield 'a word character under (*UTF)' => ['pattern' => '/(*UTF)\w/', 'ranges' => [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A], [0xC2, 0xC3]]];
        yield 'no word character under (*UTF)' => ['pattern' => '/(*UTF)\W/', 'ranges' => [[0x00, 0x2F], [0x3A, 0x40], [0x5B, 0x5E], [0x60, 0x60], [0x7B, 0xFF]]];
        yield 'a digit under (*UTF)' => ['pattern' => '/(*UTF)\d/', 'ranges' => [[0x30, 0x39], [0xC2, 0xC2]]];
        yield 'a space under (*UTF)' => ['pattern' => '/(*UTF)\s/', 'ranges' => [[0x09, 0x0D], [0x20, 0x20], [0xC2, 0xC2]]];
        yield 'a space under (*UCP)' => ['pattern' => '/(*UCP)\s/', 'ranges' => [[0x09, 0x0D], [0x20, 0x20], [0x85, 0x85], [0xA0, 0xA0]]];
        yield 'a word character under (*UCP)' => ['pattern' => '/(*UCP)\w/', 'ranges' => null];
        yield 'no word character under (*UCP)' => ['pattern' => '/(*UCP)\W/', 'ranges' => null];
        yield 'a digit under u' => ['pattern' => '/\d/u', 'ranges' => null];
        yield 'no digit under u' => ['pattern' => '/\D/u', 'ranges' => null];
    }

    /**
     * @return iterable<string, array{pattern: string, ranges: list<array{0: int, 1: int}>|null}>
     */
    public static function providePosixClassesUnderGivenTables(): iterable
    {
        $letters = [[0x41, 0x5A], [0x61, 0x7A], [0xAA, 0xAA], [0xC0, 0xD6], [0xD8, 0xF6], [0xF8, 0xFF]];
        $noLetter = [[0x00, 0x40], [0x5B, 0x60], [0x7B, 0xA9], [0xAB, 0xBF], [0xD7, 0xD7], [0xF7, 0xF7]];
        yield 'a POSIX class' => ['pattern' => '/[[:alpha:]]/', 'ranges' => $letters];
        yield 'a negated POSIX class' => ['pattern' => '/[[:^alpha:]]/', 'ranges' => $noLetter];
        yield 'a negated class of a POSIX class' => ['pattern' => '/[^[:alpha:]]/', 'ranges' => $noLetter];
        yield 'POSIX capitals' => ['pattern' => '/[[:upper:]]/', 'ranges' => [[0x41, 0x5A], [0xC0, 0xD6], [0xD8, 0xDE]]];
        yield 'POSIX small letters' => ['pattern' => '/[[:lower:]]/', 'ranges' => [[0x61, 0x7A], [0xAA, 0xAA], [0xDF, 0xF6], [0xF8, 0xFF]]];
        yield 'POSIX capitals under i, every letter' => ['pattern' => '/[[:upper:]]/i', 'ranges' => $letters];
        yield 'POSIX small letters under i, every letter' => ['pattern' => '/[[:lower:]]/i', 'ranges' => $letters];
        yield 'no POSIX small letter under i, no letter' => ['pattern' => '/[[:^lower:]]/i', 'ranges' => $noLetter];
        yield 'POSIX capitals beside a digit under i' => ['pattern' => '/[1[:upper:]]/i', 'ranges' => [[0x31, 0x31], ...$letters]];
        yield 'POSIX capitals in a group that sets i' => ['pattern' => '/(?i:[[:upper:]])/', 'ranges' => $letters];
        yield 'POSIX capitals in a group that turns i off' => ['pattern' => '/(?-i:[[:upper:]])/i', 'ranges' => [[0x41, 0x5A], [0xC0, 0xD6], [0xD8, 0xDE]]];
        yield 'a POSIX class the tables give no bytes for, read as PCRE2\'s own' => ['pattern' => '/[[:digit:]]/', 'ranges' => [[0x30, 0x39]]];
        yield 'a POSIX class beside a byte above ASCII' => ['pattern' => '/[\xB5[:digit:]]/', 'ranges' => [[0x30, 0x39], [0xB5, 0xB5]]];
        yield 'two POSIX classes' => ['pattern' => '/[[:digit:][:upper:]]/', 'ranges' => [[0x30, 0x39], [0x41, 0x5A], [0xC0, 0xD6], [0xD8, 0xDE]]];
        yield 'a POSIX class under (*UTF)' => ['pattern' => '/(*UTF)[[:alpha:]]/', 'ranges' => [[0x41, 0x5A], [0x61, 0x7A], [0xC2, 0xC3]]];
        yield 'a negated POSIX class under (*UTF)' => ['pattern' => '/(*UTF)[[:^alpha:]]/', 'ranges' => [[0x00, 0x40], [0x5B, 0x60], [0x7B, 0xFF]]];
        yield 'a POSIX class under (*UCP)' => ['pattern' => '/(*UCP)[[:alpha:]]/', 'ranges' => null];
        yield 'a negated POSIX class under (*UCP)' => ['pattern' => '/(*UCP)[[:^alpha:]]/', 'ranges' => null];
        yield 'a POSIX class under u' => ['pattern' => '/[[:digit:]]/u', 'ranges' => null];
        yield 'a name PCRE2 does not give' => ['pattern' => '/[[:letter:]]/', 'ranges' => null];
    }

    /**
     * Where the engine's paths read the other case of a byte otherwise, a
     * caseless item of it is not known: under tr_TR.ISO8859-9 on macOS the
     * interpreter matches 0xDD for "i" alone, which a class of "i" leaves
     * out, and 0xDD matches "I" there. A byte they agree on is known.
     */
    #[Test]
    public function test_read_under_a_locales_tables_a_case_the_engine_paths_read_otherwise_is_not_known(): void
    {
        $locale = 'tr_TR.ISO8859-9';
        $this->requiresLocale($locale, 'A case the engine\'s paths read otherwise');

        $subject = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $subject .= \chr($byte);
        }

        $characters = ['i', 'I', "\xDD", "\xFD", 'a', "\xDE"];
        $reads = [];
        CharacterTables::under($locale, static function () use ($characters, $subject, &$reads): void {
            foreach ($characters as $character) {
                preg_match_all('/['.$character.']/i', $subject, $inClass);
                preg_match_all('/(*NO_JIT)(*NO_START_OPT)'.$character.'/i', $subject, $alone);
                $reads[$character] = ['class' => $inClass[0], 'alone' => $alone[0]];
            }
        });

        foreach ($characters as $character) {
            $agreed = [] === array_diff($reads[$character]['alone'], $reads[$character]['class']);
            [$analyzer, $node] = self::atomUnderAnotherLocalesTables('/'.$character.'/i', $locale);
            $this->assertSame(!$agreed, $analyzer->firstChars($node)->isUnknown(), \sprintf('Oracle: 0x%02X alone matches %s, a class of it %s.', \ord($character), bin2hex(implode('', $reads[$character]['alone'])), bin2hex(implode('', $reads[$character]['class']))));
        }

        $this->assertSame(\PHP_OS_FAMILY === 'Darwin', [] !== array_diff($reads['i']['alone'], $reads['i']['class']), 'Oracle: "i" alone under tr_TR.ISO8859-9.');
    }

    /**
     * PCRE2 reads a class of two bytes, the second the other case the
     * tables give the first, as the first, caseless (pcre2_compile.c), the
     * pattern caseless or not: the interpreter compares it alone by the
     * small letters of the tables, where the JIT, and the interpreter's
     * repeat, take the two bytes. Under tr_TR.ISO8859-9 on macOS "[Ii]"
     * takes 0xDD on the interpreter, and "[\xDDi]" takes "I": such a class
     * is not known where the paths read the case of its first byte
     * otherwise. Elsewhere it holds what either path takes: "[\xFDI]" both
     * bytes, though the interpreter leaves "I" out.
     *
     * @param list<array{int, int}>|null $ranges the bytes either path takes, null where the set is not known
     */
    #[Test]
    #[DataProvider('provideClassesOfTwoBytes')]
    public function test_read_under_a_locales_tables_a_class_of_a_byte_and_its_other_case_holds_what_either_path_takes(string $class, string $locale, ?array $ranges): void
    {
        $this->requiresLocale($locale, 'A class of a byte and its other case');

        // Oracle: the bytes the class takes, alone and twice in a row, on
        // the JIT and on the interpreter, with and without the start
        // optimizations.
        $taken = ByteCharSet::empty();
        $alone = ByteCharSet::empty();
        CharacterTables::under($locale, static function () use ($class, &$taken, &$alone): void {
            for ($byte = 0; $byte <= 0xFF; $byte++) {
                foreach (['', '(*NO_JIT)', '(*NO_JIT)(*NO_START_OPT)'] as $engine) {
                    foreach (['' => \chr($byte), '{2}' => \chr($byte).\chr($byte)] as $count => $text) {
                        if (1 === preg_match('/'.$engine.'^'.$class.$count.'$/', $text)) {
                            $taken = $taken->union(ByteCharSet::fromBytes($byte, $byte));
                        }
                    }
                }

                if (1 === preg_match('/(*NO_JIT)(*NO_START_OPT)^'.$class.'$/', \chr($byte))) {
                    $alone = $alone->union(ByteCharSet::fromBytes($byte, $byte));
                }
            }
        });

        [$analyzer, $node] = self::atomUnderAnotherLocalesTables('/'.$class.'/', $locale);
        $label = addcslashes($class, "\200..\377").' under '.$locale;
        if (null === $ranges) {
            $this->assertNotCount(2, array_merge(...array_map(static fn (array $range): array => range($range[0], $range[1]), $alone->ranges())), 'Oracle: the interpreter reads '.$label.' otherwise than its two bytes.');
            $this->assertTrue($analyzer->firstChars($node)->isUnknown(), $label);

            return;
        }

        $this->assertSame($ranges, $taken->ranges(), 'Oracle: '.$label);
        $this->assertSame($ranges, $analyzer->firstChars($node)->ranges(), $label);
    }

    /**
     * @return iterable<string, array{class: string, locale: string, ranges: list<array{int, int}>|null}>
     */
    public static function provideClassesOfTwoBytes(): iterable
    {
        $turkish = 'tr_TR.ISO8859-9';
        yield 'I and i' => ['class' => '[Ii]', 'locale' => $turkish, 'ranges' => null];
        yield 'i and I' => ['class' => '[iI]', 'locale' => $turkish, 'ranges' => null];
        yield 'the dotted capital I and i' => ['class' => '[\xDDi]', 'locale' => $turkish, 'ranges' => null];
        yield 'the dotless i and I' => ['class' => '[\xFDI]', 'locale' => $turkish, 'ranges' => [[0x49, 0x49], [0xFD, 0xFD]]];
        yield 'I and the dotless i' => ['class' => '[I\xFD]', 'locale' => $turkish, 'ranges' => [[0x49, 0x49], [0xFD, 0xFD]]];
        yield 'a and A' => ['class' => '[aA]', 'locale' => $turkish, 'ranges' => [[0x41, 0x41], [0x61, 0x61]]];
        yield 'I, i and x, three bytes' => ['class' => '[Iix]', 'locale' => $turkish, 'ranges' => [[0x49, 0x49], [0x69, 0x69], [0x78, 0x78]]];
        yield 'I written twice' => ['class' => '[II]', 'locale' => $turkish, 'ranges' => [[0x49, 0x49]]];
        yield 'the final sigma and the capital sigma' => ['class' => '[\xF2\xD3]', 'locale' => 'el_GR.ISO8859-7', 'ranges' => [[0xD3, 0xD3], [0xF2, 0xF2]]];
        yield 'a Latin-1 capital and its small letter' => ['class' => "[\xC9\xE9]", 'locale' => 'fr_FR.ISO8859-1', 'ranges' => [[0xC9, 0xC9], [0xE9, 0xE9]]];
    }

    /**
     * Under tables a test gives, as no machine here builds them, the sets
     * read each case where pcre2_compile_class.c and pcre2_match.c put it.
     * Without UTF a case is a byte. Under UTF or UCP the tables give the
     * case of an ASCII character alone, Unicode that of the others: a class
     * holds the code point the tables give, which under UTF is known by the
     * byte it starts with, while the interpreter compares the first byte of
     * a character with it, and the set holds both. A byte the interpreter
     * or a release reads otherwise is not known, and nor is an ASCII
     * character under Turkish casing, which reads "i" and "I" apart from
     * the tables. The tables pair "i" with 0xDD and "I" with 0xFD as glibc
     * does under tr_TR.ISO-8859-9, 0xF2 with 0xD3 as Greek's final sigma,
     * 0xE9 with 0xC9, and, at the bounds of ASCII, "x" with 0x7F and "q"
     * with 0x80; 0xB5 is read otherwise by the interpreter, and 0xFF by
     * the tables before 10.43.
     *
     * @param list<array{0: int, 1: int}>|null $ranges the bytes of the set, null where it is not known
     */
    #[Test]
    #[DataProvider('provideReadsUnderGivenTables')]
    public function test_read_under_given_tables_a_caseless_item_takes_the_cases_they_give(string $pattern, ?array $ranges): void
    {
        self::giveTables();
        [$analyzer, $node] = self::atomUnderAnotherLocalesTables($pattern, self::GIVEN);
        $set = $analyzer->firstChars($node);

        if (null === $ranges) {
            $this->assertTrue($set->isUnknown(), $pattern);

            return;
        }

        $this->assertSame($ranges, $set->ranges(), $pattern);
        $this->assertSame($ranges, $analyzer->lastChars($node)->ranges(), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, ranges: list<array{0: int, 1: int}>|null}>
     */
    public static function provideReadsUnderGivenTables(): iterable
    {
        yield 'an ASCII letter paired as in ASCII' => ['pattern' => '/a/i', 'ranges' => [[0x41, 0x41], [0x61, 0x61]]];
        yield 'an ASCII letter paired with a byte above ASCII' => ['pattern' => '/i/i', 'ranges' => [[0x69, 0x69], [0xDD, 0xDD]]];
        yield 'the capital of another pair' => ['pattern' => '/I/i', 'ranges' => [[0x49, 0x49], [0xFD, 0xFD]]];
        yield 'a byte paired with an ASCII letter' => ['pattern' => '/\xDD/i', 'ranges' => [[0x69, 0x69], [0xDD, 0xDD]]];
        yield 'a byte paired with another' => ['pattern' => '/\xF2/i', 'ranges' => [[0xD3, 0xD3], [0xF2, 0xF2]]];
        yield 'a range above ASCII' => ['pattern' => '/[\xC0-\xDE]/i', 'ranges' => [[0x69, 0x69], [0xC0, 0xDE], [0xE9, 0xE9], [0xF3, 0xF3]]];
        yield 'a negated ASCII letter' => ['pattern' => '/[^i]/i', 'ranges' => [[0x00, 0x68], [0x6A, 0xDC], [0xDE, 0xFF]]];
        yield 'the last ASCII character' => ['pattern' => '/x/i', 'ranges' => [[0x78, 0x78], [0x7F, 0x7F]]];
        yield 'the first byte above ASCII' => ['pattern' => '/q/i', 'ranges' => [[0x71, 0x71], [0x80, 0x80]]];
        yield 'a byte the interpreter reads otherwise' => ['pattern' => '/\xB5/i', 'ranges' => null];
        yield 'a byte a release reads otherwise' => ['pattern' => '/\xFF/i', 'ranges' => null];
        yield 'a range that holds one' => ['pattern' => '/[\xF0-\xFF]/i', 'ranges' => null];
        yield 'an ASCII letter under UCP' => ['pattern' => '/(*UCP)i/i', 'ranges' => [[0x69, 0x69], [0xDD, 0xDD]]];
        yield 'a byte above ASCII under UCP, which Unicode pairs' => ['pattern' => '/(*UCP)\xF2/i', 'ranges' => [[0xD2, 0xD2], [0xF2, 0xF2]]];
        yield 'a range across the bound of ASCII under UCP' => ['pattern' => '/(*UCP)[\x7F-\x80]/i', 'ranges' => [[0x78, 0x78], [0x7F, 0x80]]];
        yield 'an ASCII letter under u' => ['pattern' => '/i/iu', 'ranges' => [[0x69, 0x69], [0xC3, 0xC3], [0xDD, 0xDD]]];
        yield 'an ASCII letter paired with the first byte above ASCII under u' => ['pattern' => '/q/iu', 'ranges' => [[0x71, 0x71], [0x80, 0x80], [0xC2, 0xC2]]];
        yield 'the last ASCII character under u' => ['pattern' => '/\x7F/iu', 'ranges' => [[0x78, 0x78], [0x7F, 0x7F]]];
        yield 'an ASCII letter paired with the last ASCII character under u' => ['pattern' => '/x/iu', 'ranges' => [[0x78, 0x78], [0x7F, 0x7F]]];
        yield 'a range across the bound of ASCII under u' => ['pattern' => '/[\x7F-\x{80}]/iu', 'ranges' => [[0x78, 0x78], [0x7F, 0xFF]]];
        yield 'a letter above ASCII under u' => ['pattern' => '/é/iu', 'ranges' => [[0x80, 0xFF]]];
        yield 'a byte the interpreter reads otherwise, a character under u' => ['pattern' => '/\xB5/iu', 'ranges' => [[0x80, 0xFF]]];
        yield 'an ASCII letter under Turkish casing' => ['pattern' => '/(*TURKISH_CASING)a/iu', 'ranges' => null];
        yield 'a letter above ASCII under Turkish casing' => ['pattern' => '/(*TURKISH_CASING)é/iu', 'ranges' => [[0x80, 0xFF]]];
    }

    /**
     * Where the tables are not known, the locale not named or one the
     * machine cannot set, a case is not known; nor is it in ASCII alone,
     * where a case above ASCII has no place, under tables a test gives.
     */
    #[Test]
    public function test_read_where_the_tables_are_not_known_a_case_is_not_known(): void
    {
        foreach ([null, 'xx_YY.NOWHERE'] as $locale) {
            foreach (['/a/i', "/\xC9/i", '/[^a]/i', '/a/iu', '/(*UCP)a/i'] as $pattern) {
                [$analyzer, $node] = self::atomUnderAnotherLocalesTables($pattern, $locale);
                $this->assertTrue($analyzer->firstChars($node)->isUnknown(), $pattern);
            }

            // A character above ASCII under u takes Unicode's case all the same.
            [$analyzer, $node] = self::atomUnderAnotherLocalesTables('/é/iu', $locale);
            $this->assertSame([[0x80, 0xFF]], $analyzer->firstChars($node)->ranges());
        }

        self::giveTables();
        $regex = RegexParser::create(['cache' => null])->parse('/a/i');
        $this->assertTrue((new CharSetAnalyzer('i', '', false, locale: self::GIVEN))->firstChars($regex->pattern)->isUnknown());
        $this->assertSame([[0x41, 0x41], [0x61, 0x61]], (new CharSetAnalyzer('i', '', true, locale: self::GIVEN))->firstChars($regex->pattern)->ranges());
    }

    /**
     * An option setting holds for the items after it, read from either end
     * of the sequence, and in ASCII as above it: in "(?i)a(?-i)b" the "a"
     * takes "A" and the "b" does not take "B".
     */
    #[Test]
    public function test_an_option_setting_holds_for_the_items_after_it(): void
    {
        $this->assertSame(1, preg_match('/^(?i)a(?-i)b$/', 'Ab'), 'Oracle: "a" is read in either case.');
        $this->assertSame(0, preg_match('/^(?i)a(?-i)b$/', 'aB'), 'Oracle: "b" is not.');

        foreach ([false, true] as $readsAboveAscii) {
            $regex = RegexParser::create(['cache' => null])->parse('/(?i)a(?-i)b/');
            $analyzer = CharSetAnalyzer::forRegex($regex, $readsAboveAscii);

            $this->assertTrue($analyzer->firstChars($regex)->intersects(ByteCharSet::fromChar('A')));
            $this->assertTrue($analyzer->lastChars($regex)->intersects(ByteCharSet::fromChar('b')));
            $this->assertFalse($analyzer->lastChars($regex)->intersects(ByteCharSet::fromChar('B')));
            $this->assertTrue($analyzer->lastChars(RegexParser::create(['cache' => null])->parse('/(?i)a/'))->intersects(ByteCharSet::fromChar('A')));
        }
    }

    /**
     * A lookaround reads nothing: the characters a match starts and ends
     * with are those of the items around it, whatever its body reads, at
     * the head of the pattern, at its tail, and alone, where the pattern
     * reads nothing. The engine is the oracle: each match PCRE finds at
     * each offset of every text of up to four of "a", "b" and "x" starts
     * and ends with a character of the set. A positive lookaround keeps
     * the set of the item it stands before or after, which holds the
     * characters it lets through. A branch that may match empty, a
     * lookaround or nothing at all, lets the items after or before the
     * alternation lead as well.
     */
    #[Test]
    #[DataProvider('provideLookarounds')]
    public function test_a_lookaround_reads_nothing(string $pattern, string $first, string $last): void
    {
        $this->requiresCompile($pattern);
        $regex = RegexParser::create(['cache' => null])->parse($pattern);
        $analyzer = CharSetAnalyzer::forRegex($regex);

        [$starts, $ends] = self::boundaryBytes($pattern);
        foreach (['first' => [$analyzer->firstChars($regex), $first, $starts], 'last' => [$analyzer->lastChars($regex), $last, $ends]] as $side => [$set, $expected, $seen]) {
            $this->assertFalse($set->isUnknown(), $pattern.' '.$side);
            foreach (str_split('abx') as $char) {
                $this->assertSame(str_contains($expected, $char), $set->intersects(ByteCharSet::fromChar($char)), \sprintf('%s %s: %s', $pattern, $side, $char));
            }
            foreach ($seen as $char) {
                $this->assertStringContainsString($char, $expected, \sprintf('Oracle: a match of %s %s with "%s".', $pattern, 'first' === $side ? 'starts' : 'ends', $char));
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string, first: string, last: string}>
     */
    public static function provideLookarounds(): iterable
    {
        yield 'a negative lookahead at the head' => ['pattern' => '/(?!x)a/', 'first' => 'a', 'last' => 'a'];
        yield 'a positive lookahead at the head' => ['pattern' => '/(?=[ab])a/', 'first' => 'a', 'last' => 'a'];
        yield 'a positive lookbehind at the head' => ['pattern' => '/(?<=x)a/', 'first' => 'a', 'last' => 'a'];
        yield 'a negative lookbehind at the head' => ['pattern' => '/(?<!x)a/', 'first' => 'a', 'last' => 'a'];
        yield 'a positive lookahead at the tail' => ['pattern' => '/a(?=x)/', 'first' => 'a', 'last' => 'a'];
        yield 'a negative lookahead at the tail' => ['pattern' => '/a(?!x)/', 'first' => 'a', 'last' => 'a'];
        yield 'a positive lookbehind at the tail' => ['pattern' => '/[ab](?<=b)/', 'first' => 'ab', 'last' => 'ab'];
        yield 'a negative lookbehind at the tail' => ['pattern' => '/a(?<!x)/', 'first' => 'a', 'last' => 'a'];
        yield 'a lookaround on each side' => ['pattern' => '/(?<=x)(?!b)a(?=b)(?<!x)/', 'first' => 'a', 'last' => 'a'];
        yield 'a lookahead before an optional item' => ['pattern' => '/(?!x)a?b/', 'first' => 'ab', 'last' => 'b'];
        yield 'a lookahead in a group' => ['pattern' => '/(?:(?!x))a/', 'first' => 'a', 'last' => 'a'];
        yield 'a lookahead in a branch' => ['pattern' => '/(?:(?=x)|b)a/', 'first' => 'ab', 'last' => 'a'];
        yield 'a lookahead between two items' => ['pattern' => '/a(?!x)b/', 'first' => 'a', 'last' => 'b'];
        yield 'an empty branch before an item' => ['pattern' => '/(?:|b)a/', 'first' => 'ab', 'last' => 'a'];
        yield 'an empty branch after an item' => ['pattern' => '/a(?:b|)/', 'first' => 'a', 'last' => 'ab'];
        yield 'an optional branch before an item' => ['pattern' => '/(?:b|x?)a/', 'first' => 'abx', 'last' => 'a'];
        yield 'a positive lookahead alone' => ['pattern' => '/(?=a)/', 'first' => '', 'last' => ''];
        yield 'a negative lookbehind alone' => ['pattern' => '/(?<!x)/', 'first' => '', 'last' => ''];
        yield 'the alphabetic negative lookahead' => ['pattern' => '/(*nla:x)a/', 'first' => 'a', 'last' => 'a'];
        yield 'the alphabetic positive lookahead' => ['pattern' => '/a(*positive_lookahead:x)/', 'first' => 'a', 'last' => 'a'];
        yield 'the alphabetic positive lookbehind' => ['pattern' => '/(*plb:x)a/', 'first' => 'a', 'last' => 'a'];
        yield 'the alphabetic negative lookbehind' => ['pattern' => '/a(*negative_lookbehind:x)/', 'first' => 'a', 'last' => 'a'];
        yield 'the non-atomic positive lookahead' => ['pattern' => '/(*napla:x)a|a/', 'first' => 'a', 'last' => 'a'];
        yield 'the non-atomic positive lookbehind' => ['pattern' => '/a(?<*x)/', 'first' => 'a', 'last' => 'a'];
        yield 'a scan of a captured substring at the tail' => ['pattern' => '/(a)b(*scs:(1)a)/', 'first' => 'a', 'last' => 'b'];
    }

    /**
     * An option the pattern opens with reads nothing: the analyzer reads
     * its effect from the run of options, and the pattern starts with what
     * follows the run.
     */
    #[Test]
    #[DataProvider('provideStartOptions')]
    public function test_an_option_the_pattern_opens_with_reads_nothing(string $pattern, string $first): void
    {
        // PCRE2 reads "(*CASELESS_RESTRICT)" from 10.45.
        $this->requiresPcre2For($pattern, ['10.45' => ['/(*CASELESS_RESTRICT)a/i']]);

        foreach (str_split('abx') as $char) {
            $this->assertSame(str_contains($first, $char) ? 1 : 0, preg_match($pattern, $char), \sprintf('Oracle: %s on "%s".', $pattern, $char));
        }

        $regex = RegexParser::create(['cache' => null])->parse($pattern);
        $set = CharSetAnalyzer::forRegex($regex)->firstChars($regex);

        $this->assertFalse($set->isUnknown(), $pattern);
        foreach (str_split('abx') as $char) {
            $this->assertSame(str_contains($first, $char), $set->intersects(ByteCharSet::fromChar($char)), \sprintf('%s: %s', $pattern, $char));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, first: string}>
     */
    public static function provideStartOptions(): iterable
    {
        yield 'the Unicode properties' => ['pattern' => '/(*UCP)a/', 'first' => 'a'];
        yield 'UTF then the Unicode properties' => ['pattern' => '/(*UTF)(*UCP)[ab]/', 'first' => 'ab'];
        yield 'the caseless restriction' => ['pattern' => '/(*CASELESS_RESTRICT)a/i', 'first' => 'a'];
        yield 'a newline convention' => ['pattern' => '/(*CR)a/', 'first' => 'a'];
        yield 'the restriction to a non-empty match' => ['pattern' => '/(*NOTEMPTY)[ab]/', 'first' => 'ab'];
        yield 'a match limit' => ['pattern' => '/(*LIMIT_MATCH=50)a/', 'first' => 'a'];
        yield 'a depth limit' => ['pattern' => '/(*LIMIT_DEPTH=50)a/', 'first' => 'a'];
        yield 'two optimizations turned off' => ['pattern' => '/(*NO_JIT)(*NO_START_OPT)b/', 'first' => 'b'];
    }

    /**
     * The word characters "\b" reads: ASCII's under PCRE2's own tables,
     * every byte another locale's tables put in "\w", the Latin-1 letters
     * of GIVEN, and none known under (*UCP), whose properties of Unicode
     * the sets do not hold.
     */
    #[Test]
    public function test_the_word_set_is_the_set_of_w_the_tables_give(): void
    {
        self::giveTables();

        $ascii = [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A]];
        $this->assertSame($ascii, (new CharSetAnalyzer())->wordSet()->ranges());
        $this->assertSame($ascii, (new CharSetAnalyzer('', '', true))->wordSet()->ranges());
        $this->assertSame([...$ascii, [0xAA, 0xAA], [0xC0, 0xD6], [0xD8, 0xF6], [0xF8, 0xFF]], (new CharSetAnalyzer('', '', true, locale: self::GIVEN))->wordSet()->ranges());
        $this->assertTrue((new CharSetAnalyzer('', '(*UCP)', true, locale: self::GIVEN))->wordSet()->isUnknown());
    }

    /**
     * The pattern matched whole: "/a|b/u" as "/^(?:a|b)$/u".
     */
    private static function anchored(string $pattern): string
    {
        $end = (int) strrpos($pattern, $pattern[0]);

        return $pattern[0].'^(?:'.substr($pattern, 1, $end - 1).')$'.substr($pattern, $end);
    }

    /**
     * Gives the tables of GIVEN: ASCII's cases but for "i", paired with
     * 0xDD, "I" with 0xFD, "x" with 0x7F and "q" with 0x80, and 0xE9 with
     * 0xC9, 0xF2 with 0xD3, 0xD3 with 0xF3; 0xB5 read otherwise by the
     * interpreter, 0xFF by the tables before 10.43. They put the Latin-1
     * letters in "\w" and "[:alpha:]", their capitals in "[:upper:]" and
     * their small letters with 0xAA in "[:lower:]", 0xB2 and 0xB3 in "\d"
     * and the no-break space 0xA0 in "\s".
     */
    private static function giveTables(): void
    {
        $others = [0x69 => 0xDD, 0xDD => 0x69, 0x49 => 0xFD, 0xFD => 0x49, 0x78 => 0x7F, 0x7F => 0x78, 0x71 => 0x80, 0x80 => 0x71, 0xE9 => 0xC9, 0xC9 => 0xE9, 0xF2 => 0xD3, 0xD3 => 0xF3, 0xF3 => 0xD3];
        $cases = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $cases .= \chr($others[$byte] ?? (Ascii::isAlpha(\chr($byte)) ? $byte ^ 0x20 : $byte));
        }

        $before = $cases;
        $before[0xFF] = 'x';
        CharacterTables::useTables(self::GIVEN, $cases, [0xB5], $before, [
            'd' => [[0x30, 0x39], [0xB2, 0xB3]],
            's' => [[0x09, 0x0D], [0x20, 0x20], [0xA0, 0xA0]],
            'w' => [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A], [0xAA, 0xAA], [0xC0, 0xD6], [0xD8, 0xF6], [0xF8, 0xFF]],
            'alpha' => [[0x41, 0x5A], [0x61, 0x7A], [0xAA, 0xAA], [0xC0, 0xD6], [0xD8, 0xF6], [0xF8, 0xFF]],
            'upper' => [[0x41, 0x5A], [0xC0, 0xD6], [0xD8, 0xDE]],
            'lower' => [[0x61, 0x7A], [0xAA, 0xAA], [0xDF, 0xF6], [0xF8, 0xFF]],
        ]);
    }

    /**
     * The analyzer of the pattern read under another locale's tables, those
     * of $locale, or tables not known for null, and its last atom.
     *
     * @return array{CharSetAnalyzer, NodeInterface}
     */
    private static function atomUnderAnotherLocalesTables(string $pattern, ?string $locale = null): array
    {
        $regex = RegexParser::create(['cache' => null])->parse($pattern);
        $node = $regex->pattern;
        if ($node instanceof SequenceNode) {
            $node = $node->children[\count($node->children) - 1];
        }

        $inForce = CharSetAnalyzer::forRegex($regex, true, $locale)->inForce($regex);

        return [$inForce[$node], $node];
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

        $inForce = CharSetAnalyzer::forRegex($regex, $readsAboveAscii)->inForce($regex);

        return [$inForce[$node], $node];
    }

    /**
     * The characters the matches PCRE finds start and end with: at each
     * offset of every text of up to four of "a", "b" and "x", the match
     * that starts there, read with what comes before it.
     *
     * @return array{list<string>, list<string>}
     */
    private static function boundaryBytes(string $pattern): array
    {
        $anchored = $pattern[0].'\G(?:'.substr($pattern, 1, (int) strrpos($pattern, $pattern[0]) - 1).')'.substr($pattern, (int) strrpos($pattern, $pattern[0]));
        $texts = [''];
        $starts = [];
        $ends = [];
        for ($length = 1; $length <= 4; $length++) {
            $longer = [];
            foreach ($texts as $text) {
                foreach (['a', 'b', 'x'] as $char) {
                    $longer[] = $text.$char;
                }
            }
            $texts = $longer;
            foreach ($texts as $text) {
                for ($offset = 0; $offset < $length; $offset++) {
                    if (1 === preg_match($anchored, $text, $match, 0, $offset) && '' !== $match[0]) {
                        $starts[$match[0][0]] = true;
                        $ends[$match[0][-1]] = true;
                    }
                }
            }
        }

        return [array_map(strval(...), array_keys($starts)), array_map(strval(...), array_keys($ends))];
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
