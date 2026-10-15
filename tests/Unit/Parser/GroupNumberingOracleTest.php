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

use PHPRegex\Parser\Analysis\GroupNumberingCollector;
use PHPRegex\Parser\Analysis\PatternInfoAnalyzer;
use PHPRegex\Parser\Internal\GroupIndex;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\NodeFinder;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One numbering of the capturing groups, as PCRE numbers them: the public
 * GroupNumbering is read from the same index the validator, the linter and
 * the pattern facts use, and every number and name agrees with the engine.
 */
final class GroupNumberingOracleTest extends TestCase
{
    /**
     * @return iterable<string, array{pattern: string, subject: string, max: int, sequence: list<int>, named: array<string, list<int>>}>
     */
    public static function providePatterns(): iterable
    {
        yield 'plain' => ['pattern' => '/(a)(b)(c)/', 'subject' => 'abc', 'max' => 3, 'sequence' => [1, 2, 3], 'named' => []];
        yield 'nested' => ['pattern' => '/((a)(?:(b)))/', 'subject' => 'ab', 'max' => 3, 'sequence' => [1, 2, 3], 'named' => []];
        yield 'named' => ['pattern' => '/(?<x>a)(b)(?P<y>c)/', 'subject' => 'abc', 'max' => 3, 'sequence' => [1, 2, 3], 'named' => ['x' => [1], 'y' => [3]]];
        yield 'branch reset' => ['pattern' => '/(?|(a)|(b)(c))(d)/', 'subject' => 'ad', 'max' => 3, 'sequence' => [1, 1, 2, 3], 'named' => []];
        yield 'nested branch reset' => ['pattern' => '/(?|(a)(?|(b)|(c)(d))|(e))(f)/', 'subject' => 'abf', 'max' => 4, 'sequence' => [1, 2, 2, 3, 1, 4], 'named' => []];
        yield 'branch reset without a group' => ['pattern' => '/(?|a|b)(c)/', 'subject' => 'ac', 'max' => 1, 'sequence' => [1], 'named' => []];
        yield 'one name, one number' => ['pattern' => '/(?|(?<x>a)|(?<x>b))/', 'subject' => 'a', 'max' => 1, 'sequence' => [1, 1], 'named' => ['x' => [1]]];
        yield 'duplicate names' => ['pattern' => '/(?J)(?<x>a)|(?<x>b)/', 'subject' => 'a', 'max' => 2, 'sequence' => [1, 2], 'named' => ['x' => [1, 2]]];
        // Listed as met: group 2 first, then group 1.
        yield 'duplicate names across a reset' => ['pattern' => '/(?J)(?|(x)(?<b>y)|(?<b>z))/', 'subject' => 'xy', 'max' => 2, 'sequence' => [1, 2, 1], 'named' => ['b' => [2, 1]]];
        yield 'conditional' => ['pattern' => '/(a)?(?(1)(b)|(c))/', 'subject' => 'ab', 'max' => 3, 'sequence' => [1, 2, 3], 'named' => []];
        yield 'assertion condition' => ['pattern' => '/(?(?=(a))(a)|(b))/', 'subject' => 'a', 'max' => 3, 'sequence' => [1, 2, 3], 'named' => []];
        yield 'define' => ['pattern' => '/(?(DEFINE)(?<d>\d))(?&d)(x)/', 'subject' => '1x', 'max' => 2, 'sequence' => [1, 2], 'named' => ['d' => [1]]];
        yield 'relative calls' => ['pattern' => '/(a)(?-1)(b)\g{-1}(?+1)(c)/', 'subject' => 'aabbcc', 'max' => 3, 'sequence' => [1, 2, 3], 'named' => []];
        yield 'lookarounds' => ['pattern' => '/(?=(a))(?<=(a)|^)(?!(b))a/', 'subject' => 'a', 'max' => 3, 'sequence' => [1, 2, 3], 'named' => []];
        yield 'quantified' => ['pattern' => '/(?:(a)|(b))+/', 'subject' => 'ab', 'max' => 2, 'sequence' => [1, 2], 'named' => []];
        yield 'script run' => ['pattern' => '/(*sr:(a))(b)/', 'subject' => 'ab', 'max' => 2, 'sequence' => [1, 2], 'named' => []];
        yield 'none' => ['pattern' => '/a(?:b)(?>c)/', 'subject' => 'abc', 'max' => 0, 'sequence' => [], 'named' => []];
    }

    /**
     * @param list<int>                $sequence
     * @param array<string, list<int>> $named
     */
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_numbering_agrees_with_the_engine(string $pattern, string $subject, int $max, array $sequence, array $named): void
    {
        $this->assertSame(1, preg_match($pattern, $subject, $matches, \PREG_UNMATCHED_AS_NULL));
        $this->assertSame($max, \count(array_filter(array_keys($matches), is_int(...))) - 1, 'The engine reports up to the highest number.');
        $this->assertSame(array_keys($named), array_values(array_filter(array_keys($matches), is_string(...))));

        $numbering = (new GroupNumberingCollector())->collect(RegexParser::create()->parse($pattern));

        $this->assertSame($max, $numbering->maxGroupNumber);
        $this->assertSame($sequence, $numbering->captureSequence);
        $this->assertSame($named, $numbering->namedGroups);
    }

    /**
     * @param list<int>                $sequence
     * @param array<string, list<int>> $named
     */
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_public_numbering_is_read_from_the_index(string $pattern, string $subject, int $max, array $sequence, array $named): void
    {
        $ast = RegexParser::create()->parse($pattern);

        $this->assertEquals((new GroupNumberingCollector())->collect($ast), GroupIndex::of($ast->pattern)->numbering());

        // The validator and the pattern facts read the same numbering.
        $this->assertTrue(RegexParser::create()->validate($pattern)->isValid);
        $info = (new PatternInfoAnalyzer())->analyze($ast);
        $this->assertSame($max, $info->captureCount);
        $sorted = array_map(static function (array $numbers): array {
            sort($numbers);

            return $numbers;
        }, $named);
        ksort($sorted, \SORT_STRING);
        $this->assertSame($sorted, $info->names);
    }

    #[Test]
    public function test_the_groups_called_and_a_whole_recursion_are_known(): void
    {
        $ast = RegexParser::create()->parse('/(?<a>x)(y)(?(R1)z)(?&a)(?-1)(z)/');
        $index = GroupIndex::of($ast->pattern);
        $groups = NodeFinder::findInstanceOf($ast, GroupNode::class);

        // "(?(R1)" tests the recursion; it calls nothing.
        $this->assertSame([spl_object_id($groups[0]) => $groups[0], spl_object_id($groups[1]) => $groups[1]], $index->subroutineTargets());
        $this->assertFalse($index->recursesWhole());
        $this->assertSame(1, $index->numberOf($groups[0]));
        $this->assertSame(3, $index->numberOf($groups[2]));

        foreach (['/a(?R)?b/', '/a(?0)?b/', '/a\g<0>?b/'] as $whole) {
            $this->assertTrue(GroupIndex::of(RegexParser::create()->parse($whole)->pattern)->recursesWhole(), $whole);
        }
    }
}
