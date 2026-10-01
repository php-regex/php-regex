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

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Parser\Analysis\LengthRangeCalculator;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "[[:<:]]" and "[[:>:]]", exactly, are the start and end of a word: PCRE2
 * reads them as "\b(?=\w)" and "\b(?<=\w)" (pcre2_compile.c, "the ugly
 * syntax"). They were read as a class holding "[:<:" followed by a literal
 * "]", which every analysis then got wrong.
 */
final class PosixWordBoundaryTest extends TestCase
{
    #[Test]
    public function test_the_start_of_a_word_reads_as_a_boundary_before_a_word_character(): void
    {
        $pattern = Regex::create(['cache' => null])->parse('/[[:<:]]red/')->pattern;

        $this->assertInstanceOf(SequenceNode::class, $pattern);
        $group = $pattern->children[0];
        $this->assertInstanceOf(GroupNode::class, $group);
        $this->assertSame(GroupType::NonCapturing, $group->type);
        $this->assertInstanceOf(SequenceNode::class, $group->child);

        [$boundary, $lookaround] = $group->child->children;
        $this->assertInstanceOf(AssertionNode::class, $boundary);
        $this->assertSame('b', $boundary->value);
        $this->assertInstanceOf(GroupNode::class, $lookaround);
        $this->assertSame(GroupType::LookaheadPositive, $lookaround->type);
        $this->assertInstanceOf(CharTypeNode::class, $lookaround->child);
        $this->assertSame('w', $lookaround->child->value);
    }

    #[Test]
    public function test_the_end_of_a_word_reads_as_a_boundary_after_a_word_character(): void
    {
        $pattern = Regex::create(['cache' => null])->parse('/red[[:>:]]/')->pattern;

        $this->assertInstanceOf(SequenceNode::class, $pattern);
        $group = $pattern->children[3];
        $this->assertInstanceOf(GroupNode::class, $group);
        $this->assertInstanceOf(SequenceNode::class, $group->child);
        $lookaround = $group->child->children[1];
        $this->assertInstanceOf(GroupNode::class, $lookaround);
        $this->assertSame(GroupType::LookbehindPositive, $lookaround->type);
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_analyses_follow_what_php_matches(string $pattern, array $subjects): void
    {
        $regex = Regex::create(['cache' => null]);
        $this->assertTrue($regex->validate($pattern)->isValid, $pattern);

        // Compiled back as written.
        $this->assertSame($pattern, $regex->parse($pattern)->accept(new PatternPrinter()));

        $optimized = $regex->optimize($pattern)->optimized;
        [$min, $max] = $regex->parse($pattern)->accept(new LengthRangeCalculator());
        foreach ($subjects as $subject) {
            preg_match($pattern, $subject, $expected);
            preg_match($optimized, $subject, $actual);
            $this->assertSame($expected, $actual, \sprintf('%s optimized into %s', $pattern, $optimized));

            if ([] !== $expected) {
                $length = str_ends_with($pattern, 'u') ? mb_strlen($expected[0], 'UTF-8') : \strlen($expected[0]);
                $this->assertGreaterThanOrEqual($min, $length);
                $this->assertLessThanOrEqual($max ?? \PHP_INT_MAX, $length);
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function providePatterns(): iterable
    {
        yield 'a whole word' => ['pattern' => '/[[:<:]]red[[:>:]]/', 'subjects' => ['little red riding hood', 'bred', 'reds']];
        yield 'repeated start of a word' => ['pattern' => '/[[:<:]]+red/', 'subjects' => ['little red riding hood', 'bred']];
        yield 'counted start of a word' => ['pattern' => '/[[:<:]]{2}red/', 'subjects' => ['a red', 'bred']];
        yield 'in UTF mode' => ['pattern' => '/[[:<:]]été[[:>:]]/u', 'subjects' => ['un été chaud', 'étés']];
    }

    #[Test]
    public function test_a_word_start_leaves_the_literal_prefix_alone(): void
    {
        $set = Regex::create(['cache' => null])->literals('/[[:<:]]red[[:>:]]/')->literalSet;

        $this->assertSame(['red'], $set->prefixes);
    }
}
