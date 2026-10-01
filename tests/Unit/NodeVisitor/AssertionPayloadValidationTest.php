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

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\Validation\Validator;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The body of "(*pla:...)", "(?*...)" and "(*sr:...)" is checked like any
 * other part of the pattern, and an error in it is reported at its offset in
 * the whole pattern.
 *
 * The offsets are PCRE2 10.48's (PHP's warning), then 10.40's where it
 * reports another (pcre2test).
 */
final class AssertionPayloadValidationTest extends TestCase
{
    /**
     * @param list<int> $offsets
     */
    #[Test]
    #[DataProvider('provideRefusedPayloads')]
    public function test_validate_refuses_an_error_inside_the_payload_at_its_offset(string $pattern, array $offsets): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s does not compile but was reported valid.', $pattern));
        $this->assertContains($result->offset, $offsets, \sprintf('%s reported at offset %s, PCRE2 reports %s.', $pattern, var_export($result->offset, true), implode(' or ', $offsets)));

        if (1 === preg_match('/at position (\d++)/', (string) $result->error, $matches)) {
            $this->assertSame($result->offset, (int) $matches[1], 'The message quotes another position than the offset.');
        }
    }

    #[Test]
    public function test_a_variable_alphabetic_lookbehind_before_pcre2_10_43_is_reported_at_its_name(): void
    {
        // pcre2test 10.40 and 10.42: the last letter of the name.
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.42']);

        $this->assertSame(3, $regex->validate('/x(*plb:ab?c)/')->offset);
        $this->assertSame(5, $regex->validate('/x(*naplb:ab?c|PQ)/')->offset);
        $this->assertSame(30, $regex->validate('/x(*non_atomic_positive_lookbehind:ab?c)/')->offset);
        $this->assertSame(1, $regex->validate('/x(?<=ab?c)/')->offset);
    }

    #[Test]
    #[DataProvider('provideAcceptedPayloads')]
    public function test_validate_accepts_a_valid_payload(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), \sprintf('%s should compile.', $pattern));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles but was reported invalid: %s', $pattern, (string) $result->error));
    }

    #[Test]
    #[DataProvider('provideBuiltTrees')]
    #[DoesNotPerformAssertions]
    public function test_validate_leaves_a_payload_unread_when_nothing_says_where_it_starts(RegexNode $tree): void
    {
        // Built in code: a script run with no parsed body, a tree with no
        // source, or a source that is not the one the tree was read from.
        // The payload's letters cannot be read back, so nothing is refused.
        $tree->accept(new Validator());
    }

    /**
     * @return iterable<string, array{tree: \PhpRegex\Parser\Node\RegexNode}>
     */
    public static function provideBuiltTrees(): iterable
    {
        // The payload "y" at position 0 of its own text, under "(*pla:" at 0.
        $lookahead = new GroupNode(new LiteralNode('y', 0, 1), GroupType::LookaheadPositive, null, null, 0, 9);

        yield 'script run without a body' => ['tree' => new RegexNode(new ScriptRunNode('\\y', 0, 8), '', '/', 0, 8, '(*sr:\\y)')];
        yield 'no source' => ['tree' => new RegexNode($lookahead, '', '/', 0, 9)];
        yield 'source without a colon' => ['tree' => new RegexNode($lookahead, '', '/', 0, 9, '(*pla\\y)')];
    }

    /**
     * @return iterable<string, array{pattern: string, offsets: list<int>}>
     */
    public static function provideRefusedPayloads(): iterable
    {
        // An alphabetic lookbehind PCRE cannot bound is reported at the last
        // letter of its name, on every release.
        yield 'unbounded short lookbehind' => ['pattern' => '/x(*plb:a+)/', 'offsets' => [3]];
        yield 'unbounded short negative lookbehind' => ['pattern' => '/x(*nlb:a+)/', 'offsets' => [3]];
        yield 'unbounded lookbehind, spelled out' => ['pattern' => '/x(*positive_lookbehind:a+)/', 'offsets' => [19]];
        yield 'unbounded non-atomic lookbehind' => ['pattern' => '/x(*naplb:a+)/', 'offsets' => [5]];
        yield 'grapheme cluster in a lookbehind, spelled out' => ['pattern' => '/x(*non_atomic_positive_lookbehind:\\X)(a)/', 'offsets' => [30]];
        yield 'unsupported escape in a lookahead' => ['pattern' => '/(*pla:\\U)/', 'offsets' => [8]];
        yield 'unknown escape in a lookahead' => ['pattern' => '/(*pla:\\y)/', 'offsets' => [8, 7]];
        yield 'escape invalid in a class, in a lookahead' => ['pattern' => '/(*pla:[\\B])/', 'offsets' => [9, 8]];
        yield 'unknown escape in a short script run' => ['pattern' => '/(*sr:\\y)/', 'offsets' => [7, 6]];
        yield 'unknown escape in a script run' => ['pattern' => '/(*script_run:\\y)/', 'offsets' => [15, 14]];
        yield 'unknown escape in a nested lookbehind' => ['pattern' => '/a(*pla:(*plb:\\y))/', 'offsets' => [15, 14]];
        yield 'unknown escape in a short non-atomic lookahead' => ['pattern' => '/(?*\\y)/', 'offsets' => [5, 4]];
        yield 'reversed count in a lookahead' => ['pattern' => '/(*pla:a{2,1})/', 'offsets' => [11]];
        yield 'reversed count in a script run' => ['pattern' => '/(*sr:a{2,1})/', 'offsets' => [10]];
        yield 'unknown group option in a lookahead' => ['pattern' => '/(*pla:(?z))/', 'offsets' => [9, 8]];
        yield 'unknown group option in a script run' => ['pattern' => '/(*sr:(?z))/', 'offsets' => [8, 7]];
        yield 'quantifier with no target in a lookahead' => ['pattern' => '/x(*pla:*)/', 'offsets' => [8, 7]];
        yield 'missing group in a lookahead' => ['pattern' => '/(*pla:\\1)/', 'offsets' => [8, 7]];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAcceptedPayloads(): iterable
    {
        yield 'lookahead' => ['pattern' => '/(*pla:\\d)/'];
        yield 'script run' => ['pattern' => '/(*sr:\\d+)/'];
        yield 'nested lookbehind' => ['pattern' => '/(*pla:(*plb:a))/'];
        yield 'short non-atomic lookahead' => ['pattern' => '/(?*\\x{41})/'];
    }
}
