<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Automata;

use PhpRegex\Automata\Exception\ComplexityException;
use PhpRegex\Automata\LanguageSolver;
use PhpRegex\Automata\Options\MatchMode;
use PhpRegex\Automata\Options\SolverOptions;
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
        $options = new SolverOptions(matchMode: MatchMode::PARTIAL);

        $result = $solver->intersection('/admin/', '/admin\\/secure/', $options);

        $this->assertFalse($result->isEmpty);
        $this->assertNotNull($result->example);
        $this->assertMatchesRegularExpression('/admin/', $result->example ?? '');
        $this->assertMatchesRegularExpression('/admin\/secure/', $result->example ?? '');
    }

    #[Test]
    public function test_partial_match_rejects_anchors(): void
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(matchMode: MatchMode::PARTIAL);

        $this->expectException(ComplexityException::class);
        $solver->intersection('/foo^bar/', '/foobar/', $options);
    }

    #[Test]
    public function test_partial_match_start_anchor_limits_language(): void
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(matchMode: MatchMode::PARTIAL);

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
        $options = new SolverOptions(matchMode: MatchMode::PARTIAL);

        $anchoredSubset = $solver->subsetOf('/a$/', '/a/', $options);
        $this->assertTrue($anchoredSubset->isSubset);

        $unanchoredSubset = $solver->subsetOf('/a/', '/a$/', $options);
        $this->assertFalse($unanchoredSubset->isSubset);
        $this->assertNotNull($unanchoredSubset->counterExample);
    }

    #[Test]
    public function test_partial_match_rejects_nested_anchor_alternation(): void
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(matchMode: MatchMode::PARTIAL);

        $this->expectException(ComplexityException::class);
        $solver->intersection('/(^a)|(^b)/', '/a|b/', $options);
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
    public function test_full_match_refuses_an_anchor_it_would_have_to_ignore(string $pattern, string $expected): void
    {
        $solver = new LanguageSolver();

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage($expected);

        $solver->equivalent($pattern, '/ab/', $this->fullMatchOptions());
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string}>
     */
    public static function provideMisplacedAnchorPatterns(): iterable
    {
        // "/a^b/" and "/a$b/" match nothing at all; compiling their anchor to
        // an epsilon transition would answer that they are the same as "/ab/".
        yield 'start anchor in the middle' => [
            'pattern' => '/a^b/',
            'expected' => 'Anchors in full match mode must appear at the start or end of each alternative.',
        ];

        yield 'end anchor in the middle' => [
            'pattern' => '/a$b/',
            'expected' => 'Anchors in full match mode must appear at the start or end of each alternative.',
        ];

        yield 'anchor nested in a group' => [
            'pattern' => '/a(^b)/',
            'expected' => 'Nested anchors are not supported in full match mode.',
        ];
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
        return new SolverOptions(matchMode: MatchMode::FULL);
    }
}
