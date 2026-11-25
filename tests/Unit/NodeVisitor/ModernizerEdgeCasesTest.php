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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Optimizer\Modernizer;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModernizerEdgeCasesTest extends TestCase
{
    /**
     * PCRE2 makes a character repeated before a possessive or atomic group
     * that may match nothing possessive, as if the group could not be
     * skipped, and the brackets decide which: a group they decide stays,
     * and the modernized pattern matches "aa" as the pattern does, with and
     * without the JIT, where the one without the group does not.
     */
    #[Test]
    #[DataProvider('provideGroupsWhoseBracketsDecideAPossessive')]
    public function test_a_group_whose_brackets_decide_a_possessive_stays(string $pattern, string $expected, string $unwrapped): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49, and 10.34 and 10.39.
        $this->assertNotSame(preg_match($pattern, 'aa'), preg_match($unwrapped, 'aa'));
        $this->assertNotSame(preg_match(PcreInterpreter::pattern($pattern), 'aa'), preg_match(PcreInterpreter::pattern($unwrapped), 'aa'));
        $this->assertSame(preg_match($pattern, 'aa'), preg_match($expected, 'aa'));

        $modernized = RegexParser::create(['cache' => null])->parse($pattern)->accept(new Modernizer());

        $this->assertSame($expected, $modernized->accept(PatternPrinter::asWritten()));
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string, unwrapped: string}>
     */
    public static function provideGroupsWhoseBracketsDecideAPossessive(): iterable
    {
        yield 'an optional group repeated possessively' => ['pattern' => '/a+(?:b)?+a/', 'expected' => '/a+(?:b)?+a/', 'unwrapped' => '/a+b?+a/'];
        yield 'an optional group in an atomic group' => ['pattern' => '/a+(?>(?:b)?)a/', 'expected' => '/a+(?>(?:b)?)a/', 'unwrapped' => '/a+(?>b?)a/'];
        yield 'an empty group in an atomic group' => ['pattern' => '/a+(?>(?:)|b)a/', 'expected' => '/a+(?>(?:)|b)a/', 'unwrapped' => '/a+(?>|b)a/'];
        // The group around the repeat goes there: PCRE2 never makes a repeat
        // written as a group possessive, and the letter repeated possessively
        // is skipped as it should be.
        yield 'a repeat written as a group' => ['pattern' => '/(?:a)+(?:b)?+a/', 'expected' => '/(?:a)+b?+a/', 'unwrapped' => '/a+(?:b)?+a/'];
    }

    #[Test]
    public function test_char_class_multiple_literals_builds_alternation(): void
    {
        $visitor = new Modernizer();
        $expression = new AlternationNode([
            new LiteralNode('a', 0, 0),
            new LiteralNode('b', 0, 0),
        ], 0, 0);
        $charClass = new CharClassNode($expression, false, 0, 0);

        $modernized = $charClass->accept($visitor);

        $this->assertInstanceOf(CharClassNode::class, $modernized);
        $this->assertInstanceOf(AlternationNode::class, $modernized->expression);
    }

    #[Test]
    public function test_preserves_literal_with_custom_delimiter(): void
    {
        $visitor = new Modernizer();
        $regex = new RegexNode(new LiteralNode('\\a', 0, 0), '', '#', 0, 0);

        $modernized = $regex->accept($visitor);

        $this->assertInstanceOf(RegexNode::class, $modernized);
        $this->assertInstanceOf(LiteralNode::class, $modernized->pattern);
        $this->assertSame($regex->pattern, $modernized->pattern);
        // The backslash stays a character to match, whatever the printer spells.
        foreach ([PatternPrinter::asWritten(), PatternPrinter::normalized()] as $printer) {
            $printed = $modernized->accept($printer);
            $this->assertSame(1, preg_match($printed, '\\a'), $printed);
            $this->assertSame(0, preg_match($printed, 'a'), $printed);
        }
    }
}
