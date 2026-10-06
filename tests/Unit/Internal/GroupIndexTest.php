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

namespace PHPRegex\Tests\Unit\Internal;

use PHPRegex\Parser\Internal\GroupIndex;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\NodeFinder;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The capturing groups of a pattern numbered as PCRE numbers them, and the
 * groups a call or a back reference names. A call runs the first group
 * bearing its number or name; a back reference may point to every group
 * bearing it (a branch reset, or a name shared under "J").
 */
final class GroupIndexTest extends TestCase
{
    /**
     * "(*scs:" needs PCRE2 10.45.
     */
    private const TARGET = ['php_version' => '8.4', 'pcre_version' => '10.45'];

    #[Test]
    public function test_numbers_named_are_ascending_and_listed_once(): void
    {
        // Met as group 2 first, then as group 1.
        $this->assertSame([1, 2], $this->index('/(?J)(?|(x)(?<b>y)|(?<b>z))/')->numbersNamed('b'));
        // One number, borne by two groups.
        $this->assertSame([1], $this->index('/(?|(?<x>a)|(?<x>b))/')->numbersNamed('x'));
        $this->assertSame([], $this->index('/(?<x>a)/')->numbersNamed('y'));
    }

    /**
     * @param list<string> $expected the groups, as written
     */
    #[Test]
    #[DataProvider('provideCalls')]
    public function test_a_call_runs_the_first_group_bearing_its_number_or_name(string $pattern, array $expected): void
    {
        $regex = $this->parse($pattern);
        $call = NodeFinder::findInstanceOf($regex, SubroutineNode::class)[0];

        $this->assertSame($expected, self::texts($regex, GroupIndex::of($regex->pattern)->groupsCalledBy($call)));
    }

    /**
     * @return iterable<string, array{pattern: string, expected: list<string>}>
     */
    public static function provideCalls(): iterable
    {
        yield 'relative, back' => ['pattern' => '/(a)(b)(?-1)/', 'expected' => ['(b)']];
        yield 'relative, back two' => ['pattern' => '/(a)(b)(?-2)/', 'expected' => ['(a)']];
        yield 'relative, forward' => ['pattern' => '/(a)(?+1)(b)/', 'expected' => ['(b)']];
        yield 'relative, forward two' => ['pattern' => '/(?+2)(a)(b)/', 'expected' => ['(b)']];
        yield 'numbered' => ['pattern' => '/(a)(b)(?2)/', 'expected' => ['(b)']];
        yield 'numbered, first of a branch reset' => ['pattern' => '/(?|(a)|(bc))(?1)/', 'expected' => ['(a)']];
        yield 'relative, first of a branch reset' => ['pattern' => '/(?|(a)|(bc))(?-1)/', 'expected' => ['(a)']];
        yield 'named, first of a branch reset' => ['pattern' => '/(?|(?<n>a)|(?<n>bc))(?&n)/', 'expected' => ['(?<n>a)']];
        yield 'named, first of a name shared under J' => ['pattern' => '/(?J)(?<n>a)|(?<n>bc)(?&n)/', 'expected' => ['(?<n>a)']];
        yield 'no such group' => ['pattern' => '/(a)(?3)/', 'expected' => []];
        // PCRE refuses a relative zero (error 126): it names no group.
        yield 'relative zero' => ['pattern' => '/(a)(?+0)(b)/', 'expected' => []];
        yield 'whole-pattern recursion' => ['pattern' => '/(a)(?R)?/', 'expected' => []];
    }

    /**
     * @param list<string> $expected the groups, as written
     */
    #[Test]
    #[DataProvider('provideBackReferences')]
    public function test_a_back_reference_names_every_group_bearing_its_number_or_name(string $pattern, array $expected): void
    {
        $regex = $this->parse($pattern);
        $reference = NodeFinder::findInstanceOf($regex, BackrefNode::class)[0];

        $this->assertSame($expected, self::texts($regex, GroupIndex::of($regex->pattern)->groupsReferencedBy($reference)));
    }

    /**
     * @return iterable<string, array{pattern: string, expected: list<string>}>
     */
    public static function provideBackReferences(): iterable
    {
        yield 'relative in braces' => ['pattern' => '/(a)(b)\g{-1}/', 'expected' => ['(b)']];
        yield 'relative in braces, back two' => ['pattern' => '/(a)(b)\g{-2}/', 'expected' => ['(a)']];
        yield 'relative without braces' => ['pattern' => '/(a)\g-1(b)/', 'expected' => ['(a)']];
        yield 'relative, forward' => ['pattern' => '/(a)\g{+1}(b)/', 'expected' => ['(b)']];
        yield 'relative, forward two' => ['pattern' => '/\g{+2}(a)(b)/', 'expected' => ['(b)']];
        yield 'relative before any group' => ['pattern' => '/\g{-1}(a)/', 'expected' => []];
        // PCRE refuses a relative zero (error 126): it names no group.
        yield 'relative zero' => ['pattern' => '/(a)\g{-0}(b)/', 'expected' => []];
        yield 'numbered in braces' => ['pattern' => '/(a)(b)\g{1}/', 'expected' => ['(a)']];
        yield 'numbered after \g' => ['pattern' => '/(a)(b)\g2/', 'expected' => ['(b)']];
        yield 'numbered' => ['pattern' => '/(a)(b)\2/', 'expected' => ['(b)']];
        yield 'every group of a branch reset' => ['pattern' => '/(?|(a)|(bc))\1/', 'expected' => ['(a)', '(bc)']];
        yield 'named' => ['pattern' => '/(?<n>a)\k<n>/', 'expected' => ['(?<n>a)']];
        yield 'every group of a name shared under J' => ['pattern' => '/(?J)(?<n>a)|(?<n>bc)\k<n>/', 'expected' => ['(?<n>a)', '(?<n>bc)']];
        yield 'no such name' => ['pattern' => '/(?<n>a)\k<m>/', 'expected' => []];
        // A group inside a script run is numbered like any other.
        yield 'group inside a script run' => ['pattern' => '/(*sr:(ab))(c)\2/', 'expected' => ['(c)']];
    }

    #[Test]
    public function test_where_a_reference_sits_in_the_count(): void
    {
        // Three groups open before the reference, all numbered 1: the next
        // group would be 2.
        $regex = $this->parse('/(?|(a)|(b)|(c))\1/');
        $index = GroupIndex::of($regex->pattern);
        $reference = NodeFinder::findInstanceOf($regex, BackrefNode::class)[0];

        $this->assertSame(3, $index->captureIndexAt($reference));
        $this->assertSame(2, $index->nextNumberAt($reference));
        $this->assertNull($index->nextNumberAt($regex->pattern));
        $this->assertNull(GroupIndex::empty()->nextNumberAt($reference));
    }

    #[Test]
    public function test_a_scan_substring_counts_its_relative_groups_from_where_it_stands(): void
    {
        $regex = $this->parse('/(a)(*scs:(+1)b)(c)/');
        $scan = NodeFinder::findFirst($regex, static fn (NodeInterface $node): bool => $node instanceof GroupNode && GroupType::ScanSubstring === $node->type);
        $this->assertInstanceOf(GroupNode::class, $scan);
        $group = NodeFinder::findFirst($regex, static fn (NodeInterface $node): bool => $node instanceof GroupNode && GroupType::Capturing === $node->type);
        $this->assertInstanceOf(GroupNode::class, $group);

        $index = GroupIndex::of($regex->pattern);

        $this->assertSame(2, $index->nextNumberAt($scan));
        $this->assertNull($index->nextNumberAt($group), 'Only a call, a reference or a scan substring is placed.');
    }

    #[Test]
    public function test_branch_resets_are_known(): void
    {
        $regex = $this->parse('/(a)(?|(b)|(c))/');
        $index = GroupIndex::of($regex->pattern);
        $groups = NodeFinder::findInstanceOf($regex, GroupNode::class);
        $inReset = [];
        foreach ($groups as $group) {
            if (GroupType::Capturing === $group->type) {
                $inReset[self::text($regex, $group)] = $index->isInBranchReset($group);
            }
        }

        $this->assertTrue($index->hasBranchReset());
        $this->assertSame(['(a)' => false, '(b)' => true, '(c)' => true], $inReset);
        $this->assertFalse(GroupIndex::of($this->parse('/(a)(b)/')->pattern)->hasBranchReset());
    }

    private function parse(string $pattern): RegexNode
    {
        return RegexParser::create(self::TARGET)->parse($pattern);
    }

    private function index(string $pattern): GroupIndex
    {
        return GroupIndex::of($this->parse($pattern)->pattern);
    }

    /**
     * @param list<GroupNode> $groups
     *
     * @return list<string>
     */
    private static function texts(RegexNode $regex, array $groups): array
    {
        return array_map(static fn (GroupNode $group): string => self::text($regex, $group), $groups);
    }

    private static function text(RegexNode $regex, NodeInterface $node): string
    {
        return substr((string) $regex->source, $node->getStartPosition(), $node->getEndPosition() - $node->getStartPosition());
    }
}
