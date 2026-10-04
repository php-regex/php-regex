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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LanguageSolverSemanticsTest extends TestCase
{
    #[Test]
    #[DataProvider('provideIntersectionCases')]
    public function test_intersection_results(
        string $left,
        string $right,
        bool $expectedEmpty,
        ?string $expectedExample,
    ): void {
        $solver = new LanguageSolver();
        $result = $solver->intersection($left, $right, $this->fullMatchOptions());

        $this->assertSame($expectedEmpty, $result->isEmpty);
        $this->assertSame($expectedExample, $result->example);
    }

    /**
     * PCRE folds case as equivalence classes, not per-character variants:
     * under /iu, "k" also matches the Kelvin sign U+212A and "s" the long s
     * U+017F (oracle: preg_match('/k/iu', "\u{212A}") and
     * preg_match('/s/iu', "\u{17F}") are both 1). The solver used to read
     * only each character's own case variants and answered "empty".
     */
    #[Test]
    public function test_full_match_folds_unicode_case_as_equivalence_classes(): void
    {
        $solver = new LanguageSolver();

        $this->assertFalse($solver->intersection('/k/iu', '/\x{212A}/u', $this->fullMatchOptions())->isEmpty);
        $this->assertFalse($solver->intersection('/[a-k]/iu', '/\x{212A}/u', $this->fullMatchOptions())->isEmpty);
        $this->assertFalse($solver->intersection('/s/iu', '/\x{17F}/u', $this->fullMatchOptions())->isEmpty);
    }

    /**
     * Atomicity is ordered semantics, not a language: a possessive quantifier
     * never gives back and an atomic group never retries, so "/^a*+a$/" and
     * "/(?>ab|a)b/" match smaller languages than their greedy spellings
     * (oracle: preg_match('/^a*+a$/', 'aaa') and
     * preg_match('/(?>ab|a)b/', 'ab') are both false). Building them as
     * greedy answered for a language PCRE does not match; the solver refuses
     * them instead of answering wrong.
     */
    #[Test]
    public function test_the_solver_refuses_atomic_and_possessive_semantics(): void
    {
        $solver = new LanguageSolver();

        foreach (['/^a*+a$/', '/(?>ab|a)b/', '/(?>(a+))b/'] as $pattern) {
            try {
                $result = $solver->intersection($pattern, '/a/', $this->fullMatchOptions());
                $this->fail(sprintf('%s was answered as a pure language; the solver must refuse it.', $pattern));
            } catch (ComplexityException $e) {
                $this->assertStringContainsString('pure language', $e->getMessage(), $pattern);
            }
        }
    }

    /**
     * When nothing that follows can take back what the possessive
     * quantifier matched — disjoint first characters, or an anchor — its
     * language is its greedy spelling, and the solver answers: "a++" before
     * a "b", and the Symfony requirement idiom "[^/]++" before a "/".
     */
    #[Test]
    public function test_possessive_before_a_disjoint_follower_is_answered(): void
    {
        $solver = new LanguageSolver();

        $this->assertFalse($solver->intersection('/a++b/', '/aab/', $this->fullMatchOptions())->isEmpty);
        $this->assertFalse($solver->intersection('#^/users/[^/]++$#', '#^/users/list$#', $this->fullMatchOptions())->isEmpty);
        $this->assertTrue($solver->intersection('#^/users/[^/]++$#', '#^/users/list/extra$#', $this->fullMatchOptions())->isEmpty);
        // Through groups and sequences, the way Symfony wraps a requirement.
        $this->assertFalse($solver->intersection('/(?:ab*+)c/', '/abbc/', $this->fullMatchOptions())->isEmpty);
        // A follower made of several alternatives joins its first characters.
        $this->assertFalse($solver->intersection('/a*+(?:[bc]|d)e/', '/ade/', $this->fullMatchOptions())->isEmpty);
        // Through a skippable follower: the set is {b, c}, disjoint from {a}
        // (oracle: "/^a*+b?c$/" matches "ac", "abc" and "aaabc" alike).
        $this->assertFalse($solver->intersection('/a*+b?c/', '/ac/', $this->fullMatchOptions())->isEmpty);
    }

    /**
     * A follower the possessive quantifier may have to feed — overlapping
     * first characters, or one it can skip — still reads as a shorter match
     * mattering, so it stays refused.
     */
    #[Test]
    public function test_a_zero_quantifier_matches_only_the_empty_string(): void
    {
        $solver = new LanguageSolver();

        $this->assertTrue($solver->equivalent('/a{0}b/', '/b/', $this->fullMatchOptions())->isEquivalent);
    }

    #[Test]
    public function test_possessive_before_a_follower_that_can_take_back_stays_refused(): void
    {
        $solver = new LanguageSolver();

        foreach (['/^a*+a$/', '/(?:ab*+)b/', '/a*+/', '/(?:a|b)*+c/', '/x*+(?:ab*+)/', '/a++(?:ab)?/', '/a*+(?:ab)?/', '/k++(?:kx)?/iu', '/a++(?:ab|)/'] as $pattern) {
            try {
                $solver->intersection($pattern, '/a/', $this->fullMatchOptions());
                $this->fail(sprintf('%s was answered; the solver must refuse it.', $pattern));
            } catch (ComplexityException $e) {
                $this->assertStringContainsString('pure language', $e->getMessage(), $pattern);
            }
        }
    }

    #[Test]
    #[DataProvider('provideSubsetCases')]
    public function test_subset_results(
        string $left,
        string $right,
        bool $expectedSubset,
        bool $expectsCounterExample,
    ): void {
        $solver = new LanguageSolver();
        $result = $solver->subsetOf($left, $right, $this->fullMatchOptions());

        $this->assertSame($expectedSubset, $result->isSubset);

        if ($expectsCounterExample) {
            $this->assertNotNull($result->counterExample);
        } else {
            $this->assertNull($result->counterExample);
        }
    }

    #[Test]
    public function test_route_shadowing_is_detected(): void
    {
        $solver = new LanguageSolver();
        $result = $solver->subsetOf('/edit/', '/[a-z]+/', $this->fullMatchOptions());

        $this->assertTrue($result->isSubset);
    }

    #[Test]
    public function test_equivalence_of_refactorings_is_detected(): void
    {
        $solver = new LanguageSolver();
        $result = $solver->equivalent('/(a|b)c/', '/ac|bc/', $this->fullMatchOptions());

        $this->assertTrue($result->isEquivalent);
        $this->assertNull($result->leftOnlyExample);
        $this->assertNull($result->rightOnlyExample);
    }

    #[Test]
    public function test_non_equivalence_returns_counter_example(): void
    {
        $solver = new LanguageSolver();
        $result = $solver->equivalent('/a*/', '/a+/', $this->fullMatchOptions());

        $this->assertFalse($result->isEquivalent);
        $this->assertSame('', $result->leftOnlyExample);
        $this->assertNull($result->rightOnlyExample);
    }

    #[Test]
    public function test_full_match_semantics_treat_anchors_as_redundant(): void
    {
        $solver = new LanguageSolver();
        $result = $solver->equivalent('/^foo$/', '/foo/', $this->fullMatchOptions());

        $this->assertTrue($result->isEquivalent);
    }

    #[Test]
    public function test_partial_match_intersection_uses_search_semantics(): void
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(matchMode: MatchMode::Partial);

        $result = $solver->intersection('/admin/', '/admin\\/secure/', $options);

        $this->assertFalse($result->isEmpty);
        $this->assertNotNull($result->example);
        $this->assertMatchesRegularExpression('/admin/', $result->example ?? '');
        $this->assertMatchesRegularExpression('/admin\/secure/', $result->example ?? '');
    }

    #[Test]
    public function test_partial_match_reads_an_anchor_in_the_middle(): void
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(matchMode: MatchMode::Partial);

        // Oracle: preg_match('/foo^bar/', 'foobar') is 0, no subject has a
        // start after "foo".
        $this->assertSame(0, preg_match('/foo^bar/', 'foobar'));
        $this->assertTrue($solver->intersection('/foo^bar/', '/foobar/', $options)->isEmpty);
    }

    #[Test]
    public function test_partial_match_start_anchor_limits_language(): void
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(matchMode: MatchMode::Partial);

        $anchoredSubset = $solver->subsetOf('/^a/', '/a/', $options);
        $this->assertTrue($anchoredSubset->isSubset);

        $unanchoredSubset = $solver->subsetOf('/a/', '/^a/', $options);
        $this->assertFalse($unanchoredSubset->isSubset);
        $this->assertNotNull($unanchoredSubset->counterExample);
    }

    #[Test]
    public function test_partial_match_end_anchor_limits_language(): void
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(matchMode: MatchMode::Partial);

        $anchoredSubset = $solver->subsetOf('/a$/', '/a/', $options);
        $this->assertTrue($anchoredSubset->isSubset);

        $unanchoredSubset = $solver->subsetOf('/a/', '/a$/', $options);
        $this->assertFalse($unanchoredSubset->isSubset);
        $this->assertNotNull($unanchoredSubset->counterExample);
    }

    #[Test]
    public function test_partial_match_reads_nested_anchor_alternation(): void
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(matchMode: MatchMode::Partial);

        // Oracle: preg_match('/(^a)|(^b)/', ...) is 1 on "a", "b" and "a\nb",
        // 0 on "xa": the anchors nested in groups still pin the start.
        $this->assertSame(0, preg_match('/(^a)|(^b)/', 'xa'));
        $this->assertTrue($solver->equivalent('/(^a)|(^b)/', '/^[ab]/', $options)->isEquivalent);
        $this->assertFalse($solver->equivalent('/(^a)|(^b)/', '/a|b/', $options)->isEquivalent);
    }

    public static function provideIntersectionCases(): \Generator
    {
        yield 'disjoint char classes' => ['/[a-z]/', '/[0-9]/', true, null];
        yield 'literal within word class' => ['/\\w+/', '/abc/', false, 'abc'];
    }

    public static function provideSubsetCases(): \Generator
    {
        yield 'letters are subset of alnum' => ['/[a-z]+/', '/[a-z0-9]+/', true, false];
        yield 'alnum is not subset of letters' => ['/[a-z0-9]+/', '/[a-z]+/', false, true];
    }

    #[Test]
    #[DataProvider('provideMisplacedAnchorPatterns')]
    public function test_full_match_reads_an_anchor_it_must_not_ignore(string $pattern): void
    {
        $solver = new LanguageSolver();

        $this->assertSame(0, preg_match($pattern, 'ab'));
        $this->assertSame(0, preg_match($pattern, "a\nb"));
        $this->assertFalse($solver->equivalent($pattern, '/ab/', $this->fullMatchOptions())->isEquivalent);
        $this->assertTrue($solver->intersection($pattern, '/[\s\S]*/', $this->fullMatchOptions())->isEmpty);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideMisplacedAnchorPatterns(): iterable
    {
        // "/a^b/" and "/a$b/" match nothing at all; compiling their anchor to
        // an epsilon transition would answer that they are the same as "/ab/".
        yield 'start anchor in the middle' => ['pattern' => '/a^b/'];
        yield 'end anchor in the middle' => ['pattern' => '/a$b/'];
        yield 'anchor nested in a group' => ['pattern' => '/a(^b)/'];
    }

    #[Test]
    public function test_full_match_keeps_accepting_anchors_at_the_edges(): void
    {
        $solver = new LanguageSolver();

        // A whole-string match starts at the start and ends at the end, so
        // these anchors really do say nothing.
        $this->assertTrue($solver->equivalent('/^ab$/', '/ab/', $this->fullMatchOptions())->isEquivalent);
    }

    private function fullMatchOptions(): SolverOptions
    {
        return new SolverOptions(matchMode: MatchMode::Full);
    }
}
