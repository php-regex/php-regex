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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Optimizer\PossessiveRewrites;
use PHPRegex\Optimizer\RepeatRewrites;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Optimizer\RewriteState;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What follows a non-atomic lookaround backtracks into it, as it does into
 * a scan substring: "^(*napla:(a+b?))\1\1" gives an "a" of group 1 back to
 * the references after it on "aa", where "(a++b?)" gives none. The
 * possessive rewrite reads past its end, as it stops at the end of an
 * atomic lookaround, which nothing after it backtracks into.
 *
 * The rewriter does not reach these today: the "*" of a non-atomic
 * lookaround reads as an inline flag, which keeps the possessive rewrite
 * out of the whole pattern. The walk is asked here on its own, given what
 * follows each item and each group around the repetition as the rewriter
 * records it on its way down.
 */
final class PossessiveNonAtomicLookaroundTest extends TestCase
{
    #[Test]
    #[DataProvider('provideNonAtomicLookarounds')]
    public function test_a_repetition_ending_a_non_atomic_lookaround_stays_greedy(string $pattern, string $possessive, string $subject): void
    {
        $this->assertNotSame(self::matched($pattern, $subject), self::matched($possessive, $subject), \sprintf('Oracle: %s and %s match alike on "%s".', $pattern, $possessive, $subject));

        $this->assertSame(QuantifierType::Greedy, self::firstRepetition($pattern)->type);
        $ast = Regex::create(['cache' => null])->parse($pattern);
        $this->assertSame($ast, $ast->accept(new Rewriter(possessive: true)), 'The rewriter leaves the pattern as written.');
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string, subject: string}>
     */
    public static function provideNonAtomicLookarounds(): iterable
    {
        yield 'a non-atomic lookahead' => ['pattern' => '/^(*napla:(a+b?))\1\1/', 'possessive' => '/^(*napla:(a++b?))\1\1/', 'subject' => 'aa'];
        yield 'a non-atomic lookahead, its long name' => ['pattern' => '/^(*non_atomic_positive_lookahead:(a+b?))\1\1/', 'possessive' => '/^(*non_atomic_positive_lookahead:(a++b?))\1\1/', 'subject' => 'aa'];
        yield 'a non-atomic lookahead, its short form' => ['pattern' => '/^(?*(a+b?))\1\1/', 'possessive' => '/^(?*(a++b?))\1\1/', 'subject' => 'aa'];
        yield 'a non-atomic lookahead in a non-atomic lookbehind' => ['pattern' => '/^(*naplb:(*napla:(a+b?)))\1\1/', 'possessive' => '/^(*naplb:(*napla:(a++b?)))\1\1/', 'subject' => 'aa'];
        yield 'a non-atomic lookahead in a non-atomic lookbehind, short forms' => ['pattern' => '/^(?<*(?*(a+b?)))\1\1/', 'possessive' => '/^(?<*(?*(a++b?)))\1\1/', 'subject' => 'aa'];
    }

    #[Test]
    #[DataProvider('provideAtomicLookarounds')]
    public function test_a_repetition_ending_an_atomic_lookaround_is_made_possessive(string $pattern, string $possessive): void
    {
        foreach (['', 'a', 'aa', 'ab', 'aab', 'aaaa', 'aaab', 'abab', 'aabaab', 'baa'] as $subject) {
            $this->assertSame(self::matched($pattern, $subject), self::matched($possessive, $subject), \sprintf('Oracle: %s and %s differ on "%s".', $pattern, $possessive, $subject));
        }

        $this->assertSame(QuantifierType::Possessive, self::firstRepetition($pattern)->type);
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string}>
     */
    public static function provideAtomicLookarounds(): iterable
    {
        yield 'a lookahead' => ['pattern' => '/^(?=(a+b?))\1\1/', 'possessive' => '/^(?=(a++b?))\1\1/'];
        yield 'a lookahead, its name' => ['pattern' => '/^(*pla:(a+b?))\1\1/', 'possessive' => '/^(*pla:(a++b?))\1\1/'];
        yield 'a non-atomic lookahead in a lookbehind' => ['pattern' => '/^(?<=(?*(a+b?)))\1\1/', 'possessive' => '/^(?<=(?*(a++b?)))\1\1/'];
        yield 'a lookahead in a non-atomic lookbehind' => ['pattern' => '/^(*naplb:(?=(a+b?)))\1\1/', 'possessive' => '/^(*naplb:(?=(a++b?)))\1\1/'];
    }

    /**
     * The first repetition of the pattern as the possessive rewrite returns
     * the items of its sequence.
     */
    private static function firstRepetition(string $pattern): QuantifierNode
    {
        $ast = Regex::create(['cache' => null])->parse($pattern);
        $state = new RewriteState();
        $state->start($ast, false);
        $items = self::possessified($ast->pattern, new PossessiveRewrites($state, new RepeatRewrites($state, 4)));
        $repetition = $items[0] ?? null;
        self::assertInstanceOf(QuantifierNode::class, $repetition);

        return $repetition;
    }

    /**
     * The items of the first sequence that starts with a repetition, as the
     * possessive rewrite returns them, the way down recorded as the
     * rewriter records it.
     *
     * @return list<NodeInterface>|null
     */
    private static function possessified(NodeInterface $node, PossessiveRewrites $rewrites): ?array
    {
        if ($node instanceof SequenceNode && $node->children[0] instanceof QuantifierNode) {
            return $rewrites->possessify($node->children);
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $index => $child) {
                $rewrites->enter($node->children, $index + 1);
                $items = self::possessified($child, $rewrites);
                $rewrites->leave();
                if (null !== $items) {
                    return $items;
                }
            }

            return null;
        }

        if (!$node instanceof GroupNode) {
            return null;
        }

        $rewrites->enterGroup($node);
        $items = self::possessified($node->child, $rewrites);
        $rewrites->leaveGroup($node);

        return $items;
    }

    /**
     * What preg_match() answers, with the text and the offset of the match
     * and of each group.
     *
     * @return array{int|false, array<array-key, mixed>}
     */
    private static function matched(string $pattern, string $subject): array
    {
        $result = preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE);

        return [$result, $matches];
    }
}
