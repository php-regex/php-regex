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

namespace PHPRegex\Tests\Unit\Lint\Rule\Support;

use PHPRegex\Linter\Rule\GroupIndex;
use PHPRegex\Linter\Rule\Support\Continuation;
use PHPRegex\Parser\Analysis\GroupIndex as PcreGroups;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What can follow a node where it runs: the "b*" of each pattern below.
 */
final class ContinuationTest extends TestCase
{
    /**
     * @return iterable<string, array{pattern: string, ends: bool, endsOrAnchor: bool, onlyEmpty: bool}>
     */
    public static function provideContinuations(): iterable
    {
        yield 'last item' => ['pattern' => '/ab*/', 'ends' => true, 'endsOrAnchor' => true, 'onlyEmpty' => true];
        yield 'a letter after it' => ['pattern' => '/ab*c/', 'ends' => false, 'endsOrAnchor' => false, 'onlyEmpty' => false];
        yield 'a comment after it' => ['pattern' => '/ab*(?#c)/', 'ends' => true, 'endsOrAnchor' => true, 'onlyEmpty' => true];
        yield 'an end anchor after it' => ['pattern' => '/ab*$/', 'ends' => false, 'endsOrAnchor' => true, 'onlyEmpty' => false];
        yield 'small z after it' => ['pattern' => '/ab*\z/', 'ends' => false, 'endsOrAnchor' => true, 'onlyEmpty' => false];
        yield 'an optional letter after it' => ['pattern' => '/ab*c?/', 'ends' => false, 'endsOrAnchor' => false, 'onlyEmpty' => true];
        yield 'an optional group of letters after it' => ['pattern' => '/ab*(?:c|)/', 'ends' => false, 'endsOrAnchor' => false, 'onlyEmpty' => true];
        yield 'an optional boundary after it' => ['pattern' => '/ab*(?:\b)?/', 'ends' => false, 'endsOrAnchor' => false, 'onlyEmpty' => false];
        yield 'an optional reference after it' => ['pattern' => '/(a)b*\1?/', 'ends' => false, 'endsOrAnchor' => false, 'onlyEmpty' => false];
        yield 'last in an alternative' => ['pattern' => '/(?:ab*|c)/', 'ends' => true, 'endsOrAnchor' => true, 'onlyEmpty' => true];
        yield 'last in a capturing group' => ['pattern' => '/(ab*)/', 'ends' => true, 'endsOrAnchor' => true, 'onlyEmpty' => true];
        yield 'last in an atomic group' => ['pattern' => '/(?>ab*)/', 'ends' => true, 'endsOrAnchor' => true, 'onlyEmpty' => true];
        yield 'a letter after the group' => ['pattern' => '/(?:ab*|c)d/', 'ends' => false, 'endsOrAnchor' => false, 'onlyEmpty' => false];
        yield 'last in a lookahead' => ['pattern' => '/(?=ab*)/', 'ends' => false, 'endsOrAnchor' => false, 'onlyEmpty' => false];
        yield 'last in a quantified group' => ['pattern' => '/(?:ab*)+/', 'ends' => false, 'endsOrAnchor' => false, 'onlyEmpty' => false];
        yield 'in a group a call runs again' => ['pattern' => '/(?1)(ab*)/', 'ends' => false, 'endsOrAnchor' => false, 'onlyEmpty' => false];
        yield 'in a pattern that recurses' => ['pattern' => '/ab*(?R)?/', 'ends' => false, 'endsOrAnchor' => false, 'onlyEmpty' => false];
        yield 'beside a group a call runs' => ['pattern' => '/(c)?ab*(?1)?/', 'ends' => false, 'endsOrAnchor' => false, 'onlyEmpty' => false];
    }

    #[Test]
    #[DataProvider('provideContinuations')]
    public function test_what_follows_the_node(string $pattern, bool $ends, bool $endsOrAnchor, bool $onlyEmpty): void
    {
        [$continuation, $node, $parents] = self::quantifierOfB($pattern);

        $this->assertSame($ends, $continuation->endsThePatternForEveryCall($node, $parents), 'ends');
        $this->assertSame($endsOrAnchor, $continuation->endsThePatternForEveryCall($node, $parents, true), 'ends or an end anchor follows');
        $this->assertSame($onlyEmpty, $continuation->onlyEmptyMatchesFollowForEveryCall($node, $parents), 'only empty matches follow');
    }

    #[Test]
    public function test_a_node_a_call_runs_again_is_reentered(): void
    {
        [$continuation, $node, $parents] = self::quantifierOfB('/(?1)(ab*)/');
        $this->assertTrue($continuation->isReenteredBySubroutine($node, $parents));

        [$continuation, $node, $parents] = self::quantifierOfB('/(?1)(a)b*/');
        $this->assertFalse($continuation->isReenteredBySubroutine($node, $parents));

        [$continuation, $node, $parents] = self::quantifierOfB('/ab*(?R)?/');
        $this->assertTrue($continuation->isReenteredBySubroutine($node, $parents));
    }

    #[Test]
    public function test_the_followers_of_each_sequence_around_the_node_are_read_from_the_inside_out(): void
    {
        [$continuation, $node, $parents] = self::quantifierOfB('/x(?:yb*c(?#n)d)ef/');

        $seen = [];
        $this->assertTrue($continuation->followersPass($node, $parents, static function (array $followers) use (&$seen): bool {
            $seen[] = \count($followers);

            return true;
        }));
        $this->assertSame([3, 2], $seen);

        $this->assertFalse($continuation->followersPass($node, $parents, static fn (array $followers): bool => 2 !== \count($followers)));

        $inner = $parents[\count($parents) - 1];
        $this->assertInstanceOf(SequenceNode::class, $inner);
        $this->assertFalse($continuation->onlyCommentsFollowIn($inner, $node));
        $this->assertFalse($continuation->onlyCommentsFollowIn($inner, $inner->children[\count($inner->children) - 2]));
        $this->assertTrue($continuation->onlyCommentsFollowIn($inner, $inner->children[\count($inner->children) - 1]));
    }

    /**
     * @return array{Continuation, NodeInterface, list<NodeInterface>}
     */
    private static function quantifierOfB(string $pattern): array
    {
        $root = Regex::create()->parse($pattern)->pattern;
        $pcre = PcreGroups::of($root);
        $path = self::pathTo($root);
        self::assertNotNull($path, $pattern);
        $node = array_pop($path);
        self::assertNotNull($node);

        return [new Continuation(new GroupIndex(0, [], [], [], false, $pcre->subroutineTargets(), $pcre->recursesWhole(), $pcre)), $node, $path];
    }

    /**
     * The nodes from the root down to the "b*", both included.
     *
     * @return list<NodeInterface>|null
     */
    private static function pathTo(NodeInterface $node): ?array
    {
        if ($node instanceof QuantifierNode && $node->node instanceof LiteralNode && 'b' === $node->node->value) {
            return [$node];
        }

        foreach ($node->getChildren() as $child) {
            $path = self::pathTo($child);
            if (null !== $path) {
                return [$node, ...$path];
            }
        }

        return null;
    }
}
