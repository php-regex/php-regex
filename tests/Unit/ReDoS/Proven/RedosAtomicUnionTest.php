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

namespace PHPRegex\Tests\Unit\ReDoS\Proven;

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosProof;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An atomic or possessive alternation of one-character branches reads one
 * character of the union of their sets and never gives it back: the proof
 * reads it as that one set. Engine, JIT off, on 20,000 characters then "!":
 * each row fails in under 2 ms.
 */
final class RedosAtomicUnionTest extends TestCase
{
    #[Test]
    #[DataProvider('provideAtomicUnions')]
    public function test_an_atomic_alternation_of_one_character_branches_is_proven_linear(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern.' is "'.$analysis->headline().'"');
        $this->assertSame(RedosComplexity::Linear, $analysis->complexity, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAtomicUnions(): iterable
    {
        yield 'identical branches' => ['pattern' => '/^(?>a|a)+$/'];
        // "(?i)" in the first branch holds in the branches after it.
        yield 'option set in an earlier branch' => ['pattern' => '/^(?>z(?i)|a|A)*$/'];
        yield 'possessive repeat of a group' => ['pattern' => '/^(?:a|a)++$/'];
        yield 'atomic repeat of a group' => ['pattern' => '/^(?>(?:a|b)+)c$/'];
        yield 'literal and a type' => ['pattern' => '/^(?>a|\d)+$/'];
        yield 'branch holding a comment' => ['pattern' => '/^(?>a(?#c)|a)+$/'];
    }

    /**
     * A branch of two items, or of one group holding two, is no one
     * character: the group is kept as written, and listed.
     */
    #[Test]
    public function test_an_atomic_alternation_with_a_branch_of_two_items_is_kept_as_written(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/^(?>a\d|a)+$/');

        $this->assertContains('atomic group at offset 1 over-approximated', $analysis->abstractions);
        // Nor a branch of one group holding two characters.
        $this->assertContains('atomic group at offset 1 over-approximated', (new RedosAnalyzer())->analyze('/^(?>a|(?:bc))+$/')->abstractions);
    }

    /**
     * The atomic union is one character of the set: beside a branch reading
     * the same character, the loop over both is still ambiguous. Engine,
     * JIT off: 30 "a" then "!" exhaust the backtrack limit.
     */
    #[Test]
    public function test_an_atomic_union_beside_an_overlapping_branch_stays_exponential(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/^(?:(?>a|a)|a)+$/');

        $this->assertFalse($analysis->isProvenSafe(), $analysis->headline());
        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity, $analysis->headline());
    }
}
