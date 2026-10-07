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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 10.43 lets spaces and tabs pad a braced count, "a{2 }"; any other
 * white space leaves the braces as text: "a{2\n}" matches "a{2\n}", not
 * "aa", and under /x, where the newline is skipped, it matches "a{2}".
 */
final class BraceCountWhitespaceTest extends TestCase
{
    #[Test]
    #[DataProvider('provideTextBraces')]
    public function test_a_count_padded_with_other_white_space_is_text(string $pattern, string $member, string $outsider): void
    {
        $this->assertSame(1, preg_match($pattern, $member), $pattern);
        $this->assertSame(0, preg_match($pattern, $outsider), $pattern);

        $sequence = RegexParser::create()->parse($pattern)->pattern;
        $this->assertInstanceOf(SequenceNode::class, $sequence);
        foreach ($sequence->children as $child) {
            $this->assertNotInstanceOf(QuantifierNode::class, $child, \sprintf('%s holds no count.', json_encode($pattern)));
        }
    }

    #[Test]
    #[DataProvider('providePaddedCounts')]
    public function test_spaces_and_tabs_still_pad_a_count(string $pattern): void
    {
        // Before PCRE2 10.43 the braces are text: the count does not pad.
        if (!PcreTarget::runtime()->pcreAtLeast('10.43')) {
            $this->markTestSkipped(sprintf('%s is verified against PCRE2 10.43 and later; PCRE2 %s reports it differently.', $pattern, \PCRE_VERSION));
        }

        $this->assertSame(1, preg_match($pattern, 'aa'), $pattern);

        $sequence = RegexParser::create()->parse($pattern)->pattern;
        $this->assertInstanceOf(SequenceNode::class, $sequence);
        $this->assertInstanceOf(QuantifierNode::class, $sequence->children[1]);
    }

    #[Test]
    public function test_the_bounds_of_a_count_take_spaces_and_tabs_only(): void
    {
        $bounds = QuantifierBounds::parse("{1,\t2 }");
        $this->assertInstanceOf(QuantifierBounds::class, $bounds);
        $this->assertSame([1, 2], [$bounds->min, $bounds->max]);

        $this->assertNull(QuantifierBounds::parse("{2\n}"));
    }

    #[Test]
    public function test_the_solver_reads_the_braces_as_text(): void
    {
        $this->assertTrue((new LanguageSolver())->equivalent("/^a{2\n}$/", "/^a\\{2\n\\}$/")->isEquivalent);
    }

    /**
     * @return iterable<string, array{pattern: string, member: string, outsider: string}>
     */
    public static function provideTextBraces(): iterable
    {
        yield 'newline before the brace' => ['pattern' => "/^a{2\n}$/", 'member' => "a{2\n}", 'outsider' => 'aa'];
        yield 'newline after the brace' => ['pattern' => "/^a{\n2}$/", 'member' => "a{\n2}", 'outsider' => 'aa'];
        yield 'carriage return after the comma' => ['pattern' => "/^a{1,\r2}$/", 'member' => "a{1,\r2}", 'outsider' => 'aa'];
        yield 'form feed' => ['pattern' => "/^a{2\f}$/", 'member' => "a{2\f}", 'outsider' => 'aa'];
        yield 'newline under x' => ['pattern' => "/^a{2\n}$/x", 'member' => 'a{2}', 'outsider' => 'aa'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePaddedCounts(): iterable
    {
        yield 'space' => ['pattern' => '/^a{2 }$/'];
        yield 'tab' => ['pattern' => "/^a{\t2}$/"];
        yield 'space after the comma' => ['pattern' => '/^a{1, 2}$/'];
    }
}
