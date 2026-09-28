<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharLiteralType;
use RegexParser\Node\GroupNode;
use RegexParser\Node\GroupType;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\SequenceNode;
use RegexParser\NodeVisitor\CompilerNodeVisitor;
use RegexParser\Regex;

/**
 * An escape that takes a variable number of digits ("\1", "\0", "\g1",
 * "\xa") ends where the pattern stops it, and "\E", "\Q\E" or an empty group
 * can be what stops it: "(a)\1\E0" is a reference to group 1 then "0", while
 * "(a)\10" is the octal escape \010. Braces work the same way: "a{\E2}" is
 * the text "a{2}", not a repeat.
 *
 * Writing a pattern back must keep the two items apart, or PCRE reads one
 * longer escape (or a repeat) where the pattern had two items.
 */
final class EscapeBoundaryRoundTripTest extends TestCase
{
    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideQuoteEndBoundaries')]
    public function test_compiling_keeps_the_quote_end_between_two_items(string $pattern, array $subjects): void
    {
        $compiled = Regex::create()->parse($pattern)->accept(new CompilerNodeVisitor());

        $this->assertSame($pattern, $compiled);
        $this->assertSameMatches($pattern, $compiled, $subjects);
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideQuotedRunBoundaries')]
    public function test_compiling_keeps_a_quoted_run_apart_from_an_escape(string $pattern, string $expected, array $subjects): void
    {
        $compiled = Regex::create()->parse($pattern)->accept(new CompilerNodeVisitor());

        $this->assertSame($expected, $compiled);
        $this->assertSameMatches($pattern, $compiled, $subjects);
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideEmptyGroupBoundaries')]
    public function test_optimizing_away_an_empty_group_keeps_the_items_apart(string $pattern, array $subjects): void
    {
        $optimized = Regex::create()->optimize($pattern)->optimized;

        $this->assertSameMatches($pattern, $optimized, $subjects);
    }

    #[Test]
    public function test_compiling_a_built_tree_keeps_a_reference_apart_from_a_digit(): void
    {
        // (a)\1 then "0", built in code: no source says where "\1" ended.
        $group = new GroupNode(new LiteralNode('a', 1, 2), GroupType::T_GROUP_CAPTURING, null, null, 0, 3);
        $sequence = new SequenceNode([$group, new BackrefNode('1', 3, 5), new LiteralNode('0', 5, 6)], 0, 6);

        $compiled = (new RegexNode($sequence, '', '/', 0, 6))->accept(new CompilerNodeVisitor());

        $this->assertSame('/(a)\\1(?:)0/', $compiled);
        $this->assertSameMatches('/^(a)\\1\\E0$/', '/^'.substr($compiled, 1, -1).'$/', ['aa0', "a\x08"]);
    }

    #[Test]
    public function test_compiling_a_transformed_tree_does_not_copy_a_dropped_node_back(): void
    {
        // "(a)\1(?:)0" with its empty group taken out after parsing: the
        // source still holds "(?:)" between the reference and the digit, but
        // no node does any more, so it is not copied back as a separator.
        $parsed = Regex::create()->parse('/(a)\\1(?:)0/');
        $this->assertInstanceOf(SequenceNode::class, $parsed->pattern);
        [$group, $reference, , $digit] = $parsed->pattern->children;
        $sequence = new SequenceNode([$group, $reference, $digit], 0, 10);

        $compiled = (new RegexNode($sequence, '', '/', 0, 10, $parsed->source))->accept(new CompilerNodeVisitor());

        $this->assertSame('/(a)\\1(?:)0/', $compiled);
        $this->assertSameMatches('/^(a)\\1\\E0$/', '/^'.substr($compiled, 1, -1).'$/', ['aa0', "a\x08"]);
    }

    #[Test]
    #[DataProvider('provideBuiltClassMembers')]
    public function test_compiling_a_built_class_keeps_an_octal_apart_from_a_digit(string $shape): void
    {
        // [\0 then "1"], built in code: inside a class only "\E" separates.
        $members = [
            new CharLiteralNode('\\0', 0, CharLiteralType::OCTAL_LEGACY, 1, 3),
            new LiteralNode('1', 3, 4),
        ];
        $expression = 'sequence' === $shape ? new SequenceNode($members, 1, 4) : new AlternationNode($members, 1, 4);
        $class = new CharClassNode($expression, false, 0, 5);

        $compiled = (new RegexNode($class, '', '/', 0, 5))->accept(new CompilerNodeVisitor());

        $this->assertSame('/[\\0\\E1]/', $compiled);
        $this->assertSameMatches('/^[\\0\\E1]$/', '/^'.substr($compiled, 1, -1).'$/', ["\x00", '1', "\x01"]);
    }

    /**
     * @return iterable<string, array{shape: string}>
     */
    public static function provideBuiltClassMembers(): iterable
    {
        yield 'members as the parser builds them' => ['shape' => 'alternation'];
        yield 'members as a sequence' => ['shape' => 'sequence'];
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideQuoteEndBoundaries(): iterable
    {
        yield 'reference then digit' => ['pattern' => '/^(a)\\1\\E0$/', 'subjects' => ['aa0', "a\x08"]];
        yield 'reference then digit, empty quote' => ['pattern' => '/^(a)\\1\\Q\\E0$/', 'subjects' => ['aa0', "a\x08"]];
        yield '\\g reference then digit' => ['pattern' => '/^(a)\\g1\\E0$/', 'subjects' => ['aa0', 'a']];
        yield 'octal \\0 then digit' => ['pattern' => '/^\\0\\E1$/', 'subjects' => ["\x001", "\x01"]];
        yield 'one-digit hex then hex digit' => ['pattern' => '/^\\xa\\Eb$/', 'subjects' => ["\nb", "\xab"]];
        yield 'brace then count' => ['pattern' => '/^a{\\E2}$/', 'subjects' => ['a{2}', 'aa']];
        yield 'count then closing brace' => ['pattern' => '/^a{2\\E}$/', 'subjects' => ['a{2}', 'aa']];
        yield 'whitespace under x separates on its own' => ['pattern' => '/^(a)\\1 0$/x', 'subjects' => ['aa0', "a\x08"]];
        yield 'count split after the comma' => ['pattern' => '/^a{2,\\E3}$/', 'subjects' => ['a{2,3}', 'aa']];
        yield 'bracket then colon in a class' => ['pattern' => '/^[[\\E:alpha:]]$/', 'subjects' => ['[]', 'a]', 'b', ':']];
        yield '\\N then braces under UTF' => ['pattern' => '/^\\N\\E{U+41}$/u', 'subjects' => ['x{U+41}', 'A']];
        yield 'octal \\0 then digit in a class' => ['pattern' => '/^[\\0\\E1]$/', 'subjects' => ["\x00", '1', "\x01"]];
        yield 'one-digit hex then hex digit in a class' => ['pattern' => '/^[\\xa\\Eb]$/', 'subjects' => ["\n", 'b', "\xab"]];
        yield 'range end then digit in a class' => ['pattern' => '/^[\\0-\\01\\E7]$/', 'subjects' => ['7', "\x01", "\x0f"]];
        yield 'quote end kept under x' => ['pattern' => '/^(a)\\1 \\E 0$/x', 'subjects' => ['aa0', "a\x08"]];
    }

    /**
     * Quoted text comes back escaped, without its "\Q...\E": the closing
     * "\E" goes with it, and a separator takes its place only where a digit
     * escape would otherwise read on.
     *
     * @return iterable<string, array{pattern: string, expected: string, subjects: list<string>}>
     */
    public static function provideQuotedRunBoundaries(): iterable
    {
        yield 'reference then quoted digit' => ['pattern' => '/^(a)\\1\\Q0\\E$/', 'expected' => '/^(a)\\1(?:)0$/', 'subjects' => ['aa0', "a\x08"]];
        yield 'octal then quoted digit' => ['pattern' => '/^\\0\\Q1\\E$/', 'expected' => '/^\\0(?:)1$/', 'subjects' => ["\x001", "\x01"]];
        yield 'octal then quoted digit in a class' => ['pattern' => '/^[\\0\\Q1\\E]$/', 'expected' => '/^[\\0\\E1]$/', 'subjects' => ["\x00", '1', "\x01"]];
        yield 'quoted run then letter' => ['pattern' => '/^\\Qab\\Ec$/', 'expected' => '/^abc$/', 'subjects' => ['abc', 'ab']];
        yield 'quoted brace then count' => ['pattern' => '/^a\\Q{\\E2}$/', 'expected' => '/^a\\{2}$/', 'subjects' => ['a{2}', 'aa']];
        yield 'quote ends that separate nothing are dropped' => ['pattern' => '/^a\\E\\Q\\Eb$/', 'expected' => '/^ab$/', 'subjects' => ['ab', 'a']];
        yield 'stray quote end after a quoted run' => ['pattern' => '/^\\Qa\\E\\E0$/', 'expected' => '/^a0$/', 'subjects' => ['a0', 'a']];
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideEmptyGroupBoundaries(): iterable
    {
        yield 'reference then digit' => ['pattern' => '/^(a)\\1(?:)0$/', 'subjects' => ['aa0', "a\x08"]];
        yield '\\g reference then digit' => ['pattern' => '/^(a)\\g1(?:)0$/', 'subjects' => ['aa0', 'a']];
        yield 'relative \\g reference then digit' => ['pattern' => '/^(a)\\g-1(?:)0$/', 'subjects' => ['aa0', 'a']];
        yield 'octal \\0 then digit' => ['pattern' => '/^\\0(?:)1$/', 'subjects' => ["\x001", "\x01"]];
        yield 'two-digit octal then digit' => ['pattern' => '/^\\01(?:)7$/', 'subjects' => ["\x017", "\x0f"]];
        yield 'one-digit hex then hex digit' => ['pattern' => '/^\\xa(?:)b$/', 'subjects' => ["\nb", "\xab"]];
        yield 'escaped backslash then digit stays as written' => ['pattern' => '/^\\\\1(?:)0$/', 'subjects' => ['\\10', '1']];
    }

    /**
     * @param list<string> $subjects
     */
    private function assertSameMatches(string $pattern, string $rewritten, array $subjects): void
    {
        $this->assertNotFalse(@preg_match($rewritten, ''), \sprintf('%s was rewritten to %s, which does not compile.', $pattern, $rewritten));

        foreach ($subjects as $subject) {
            $this->assertSame(
                preg_match($pattern, $subject),
                preg_match($rewritten, $subject),
                \sprintf('%s was rewritten to %s, which disagrees on %s.', json_encode($pattern), json_encode($rewritten), json_encode($subject)),
            );
        }
    }
}
