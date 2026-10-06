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
use PHPRegex\Parser\Internal\LookbehindLength;
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
 * The length range of a lookbehind body, as PCRE measures it. Where PCRE2
 * reports a maximum (pcre2test 10.49 "Max lookbehind"), the row's maximum
 * is that value. The minimum decides, before PCRE2 10.43, whether a branch
 * has a fixed length.
 */
final class LookbehindLengthTest extends TestCase
{
    private const TARGET = ['php_version' => '8.4', 'pcre_version' => '10.44'];

    /**
     * @param array{0: int, 1: int|null} $expected
     */
    #[Test]
    #[DataProvider('provideBodies')]
    public function test_a_body_is_measured_as_pcre_measures_it(string $pattern, array $expected): void
    {
        [$regex, $lookbehind] = $this->lookbehind($pattern);

        $this->assertSame($expected, (new LookbehindLength(GroupIndex::of($regex->pattern), false, true))->of($lookbehind->child));
    }

    /**
     * @return iterable<string, array{pattern: string, expected: array{0: int, 1: int|null}}>
     */
    public static function provideBodies(): iterable
    {
        yield 'a lookahead adds nothing' => ['pattern' => '/(?<=a(?=b))x/', 'expected' => [1, 1]];
        yield 'a repeated lookahead adds nothing' => ['pattern' => '/(?<=(?=a)+b)x/', 'expected' => [1, 1]];
        yield 'two empty alternatives' => ['pattern' => '/(?<=x(?:|))y/', 'expected' => [1, 1]];
        yield 'alternatives of two lengths' => ['pattern' => '/(?<=(?:a|bcd))x/', 'expected' => [1, 3]];
        yield 'both branches of a conditional' => ['pattern' => '/(?<=(?(1)ab|c))(x)/', 'expected' => [1, 2]];
        yield 'a DEFINE group adds nothing' => ['pattern' => '/(?<=(?(DEFINE)(a))b)x/', 'expected' => [1, 1]];
        yield 'a group repeated zero times' => ['pattern' => '/(?<=(?:a|bc){0}d)x/', 'expected' => [1, 1]];
        yield 'spaces inside the braces' => ['pattern' => '/(?<=a{1, 3})x/', 'expected' => [1, 3]];
        yield 'a possessive repeat' => ['pattern' => '/(?<=a{1,3}+)x/', 'expected' => [1, 3]];
        yield 'a forward relative call' => ['pattern' => '/(?<=(?+1)x)(ab)/', 'expected' => [3, 3]];
        yield 'a call runs the first group of a branch reset' => ['pattern' => '/(?<=(?1))(?|(a)|(bc))/', 'expected' => [1, 1]];
        yield 'a relative back reference' => ['pattern' => '/(ab)(?<=\g-1)x/', 'expected' => [2, 2]];
        yield 'a numbered back reference' => ['pattern' => '/(ab)(?<=\g{1})x/', 'expected' => [2, 2]];
        yield 'a group inside a script run' => ['pattern' => '/(*sr:(ab))(?<=\1)c/', 'expected' => [2, 2]];
    }

    /**
     * Before PCRE2 10.43 a group of variable length stays variable even
     * repeated zero times.
     */
    #[Test]
    public function test_a_group_repeated_zero_times_keeps_its_maximum_before_variable_lengths(): void
    {
        [$regex, $lookbehind] = $this->lookbehind('/(?<=(?:a|bc){0}d)x/');

        $this->assertSame([1, 3], (new LookbehindLength(GroupIndex::of($regex->pattern), false, false))->of($lookbehind->child));
    }

    #[Test]
    #[DataProvider('provideUnbounded')]
    public function test_pcre_finds_no_bound(string $pattern): void
    {
        [$regex, $lookbehind] = $this->lookbehind($pattern);

        $this->assertNull((new LookbehindLength(GroupIndex::of($regex->pattern), false, true))->of($lookbehind->child)[1]);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideUnbounded(): iterable
    {
        yield 'an unbounded repeat' => ['pattern' => '/(?<=a+)x/'];
        // PCRE stops at the first alternative it cannot bound: a later
        // bounded one does not give the alternation a maximum.
        yield 'an unbounded alternative first' => ['pattern' => '/(?<=(?:a+|b))x/'];
        yield 'a grapheme cluster' => ['pattern' => '/(?<=\X)x/'];
        yield 'a call back into the group it measures' => ['pattern' => '/(?<=(a(?1)?))x/'];
        yield 'a back reference into a branch reset' => ['pattern' => '/(?|(a)|(bc))(?<=\1)x/'];
        yield 'a name two groups bear' => ['pattern' => '/(?J)(?<n>a)|(?<n>bc)(?<=\k<n>)x/'];
    }

    #[Test]
    public function test_a_call_into_a_group_being_measured_has_no_bound(): void
    {
        // The validator lists the groups a lookbehind sits in.
        [$regex, $lookbehind] = $this->lookbehind('/(a(?<=(?1)))/');
        $group = NodeFinder::findFirst($regex, static fn (NodeInterface $node): bool => $node instanceof GroupNode && GroupType::Capturing === $node->type);
        $this->assertInstanceOf(GroupNode::class, $group);
        $measure = new LookbehindLength(GroupIndex::of($regex->pattern), false, true);

        $this->assertNull($measure->of($lookbehind->child, [spl_object_id($group) => true])[1]);
    }

    #[Test]
    public function test_the_listeners_hear_what_the_measure_meets(): void
    {
        [$regex, $lookbehind] = $this->lookbehind('/(ab)(?<=(c)(?=d)\1)x/');
        $heard = [];
        $measure = new LookbehindLength(
            GroupIndex::of($regex->pattern),
            false,
            true,
            static function (GroupNode $node, array $expanding) use (&$heard): void {
                $heard[] = 'lookaround '.$node->type->name;
            },
            static function (NodeInterface $body) use (&$heard, $regex): void {
                $heard[] = 'body '.self::text($regex, $body);
            },
            static function (SubroutineNode|BackrefNode $node, array $groups) use (&$heard, $regex): void {
                $heard[] = 'reference '.($node instanceof BackrefNode ? $node->ref : $node->reference).' to '.implode(', ', array_map(static fn (GroupNode $group): string => self::text($regex, $group), $groups));
            },
        );

        $this->assertSame([3, 3], $measure->of($lookbehind->child));
        $this->assertSame(['body c', 'lookaround LookaheadPositive', 'reference \1 to (ab)', 'body ab'], $heard);
    }

    /**
     * @return array{RegexNode, GroupNode}
     */
    private function lookbehind(string $pattern): array
    {
        $regex = RegexParser::create(self::TARGET)->parse($pattern);
        $lookbehind = NodeFinder::findFirst($regex, static fn (NodeInterface $node): bool => $node instanceof GroupNode && GroupType::LookbehindPositive === $node->type);
        $this->assertInstanceOf(GroupNode::class, $lookbehind);

        return [$regex, $lookbehind];
    }

    private static function text(RegexNode $regex, NodeInterface $node): string
    {
        return substr((string) $regex->source, $node->getStartPosition(), $node->getEndPosition() - $node->getStartPosition());
    }
}
