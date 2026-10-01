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
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Solver\InMemoryDfaCache;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one entry point for questions about the languages two patterns match.
 * Every witness the solver returns is checked against the real engine.
 */
final class LanguageSolverTest extends TestCase
{
    #[Test]
    public function test_intersection_of_disjoint_patterns_is_empty(): void
    {
        $result = (new LanguageSolver())->intersection('/a/', '/b/');

        $this->assertTrue($result->isEmpty);
        $this->assertNull($result->example);
    }

    #[Test]
    public function test_intersection_of_overlapping_patterns_carries_a_string_both_match(): void
    {
        $result = (new LanguageSolver())->intersection('/[a-c]+/', '/[b-d]+/');

        $this->assertFalse($result->isEmpty);
        $this->assertNotNull($result->example);
        $this->assertSame(1, preg_match('/^[a-c]+$/', $result->example));
        $this->assertSame(1, preg_match('/^[b-d]+$/', $result->example));
    }

    #[Test]
    public function test_subset_of_holds_when_every_string_of_the_left_matches_the_right(): void
    {
        $result = (new LanguageSolver())->subsetOf('/a/', '/[ab]/');

        $this->assertTrue($result->isSubset);
        $this->assertNull($result->counterExample);
    }

    #[Test]
    public function test_subset_of_fails_with_a_string_only_the_left_matches(): void
    {
        $result = (new LanguageSolver())->subsetOf('/[ab]/', '/a/');

        $this->assertFalse($result->isSubset);
        $this->assertSame('b', $result->counterExample);
        $this->assertSame(1, preg_match('/^[ab]$/', $result->counterExample));
        $this->assertSame(0, preg_match('/^a$/', $result->counterExample));
    }

    #[Test]
    public function test_equivalent_holds_for_two_spellings_of_one_language(): void
    {
        $result = (new LanguageSolver())->equivalent('/a+/', '/aa*/');

        $this->assertTrue($result->isEquivalent);
        $this->assertNull($result->leftOnlyExample);
        $this->assertNull($result->rightOnlyExample);
    }

    #[Test]
    public function test_equivalent_fails_with_a_string_on_the_side_that_matches_more(): void
    {
        $result = (new LanguageSolver())->equivalent('/ab?/', '/a/');

        $this->assertFalse($result->isEquivalent);
        $this->assertSame('ab', $result->leftOnlyExample);
        $this->assertNull($result->rightOnlyExample);
        $this->assertSame(1, preg_match('/^ab?$/', $result->leftOnlyExample));
        $this->assertSame(0, preg_match('/^a$/', $result->leftOnlyExample));
    }

    #[Test]
    public function test_compile_returns_the_dfa_of_a_pattern(): void
    {
        $dfa = (new LanguageSolver())->compile('/[a-z]+/', new SolverOptions());

        $this->assertInstanceOf(Dfa::class, $dfa);
        $start = $dfa->getState($dfa->startState);
        $this->assertFalse($start->isAccepting);

        $afterLetter = $start->transitionFor(\ord('a'));
        $afterDigit = $start->transitionFor(\ord('0'));
        $this->assertNotNull($afterLetter);
        $this->assertNotNull($afterDigit);
        $this->assertTrue($dfa->getState($afterLetter)->isAccepting);
        $this->assertFalse($dfa->getState($afterDigit)->isAccepting);
    }

    #[Test]
    public function test_compile_stores_the_dfa_in_the_cache_it_is_given(): void
    {
        $solver = new LanguageSolver(dfaCache: new InMemoryDfaCache());

        $this->assertSame($solver->compile('/abc/'), $solver->compile('/abc/'));
    }

    #[Test]
    public function test_compile_builds_a_new_dfa_without_a_cache(): void
    {
        $solver = new LanguageSolver();

        $this->assertNotSame($solver->compile('/abc/'), $solver->compile('/abc/'));
    }

    #[Test]
    public function test_patterns_are_read_for_the_target_of_the_parser_it_is_given(): void
    {
        // "{,2}" repeats from PCRE2 10.43 and is text before (pcre2test).
        $newer = new LanguageSolver(RegexParser::create(['cache' => null, 'pcre_version' => '10.44']));
        $older = new LanguageSolver(RegexParser::create(['cache' => null, 'pcre_version' => '10.42']));

        $this->assertFalse($newer->equivalent('/^x{,2}$/', '/^x\{,2\}$/')->isEquivalent);
        $this->assertTrue($older->equivalent('/^x{,2}$/', '/^x\{,2\}$/')->isEquivalent);
    }

    #[Test]
    #[DataProvider('provideQuestions')]
    public function test_a_non_regular_construct_is_refused(string $method, string $pattern): void
    {
        $solver = new LanguageSolver();

        $this->expectException(ComplexityException::class);

        match ($method) {
            'intersection' => $solver->intersection($pattern, '/a/'),
            'subsetOf' => $solver->subsetOf('/a/', $pattern),
            'equivalent' => $solver->equivalent($pattern, '/a/'),
            default => $solver->compile($pattern),
        };
    }

    /**
     * @return iterable<string, array{method: string, pattern: string}>
     */
    public static function provideQuestions(): iterable
    {
        foreach (['intersection', 'subsetOf', 'equivalent', 'compile'] as $method) {
            yield $method.' with a backreference' => ['method' => $method, 'pattern' => '/(a)\1/'];
            yield $method.' with recursion' => ['method' => $method, 'pattern' => '/a(?R)?b/'];
            yield $method.' with a lookahead' => ['method' => $method, 'pattern' => '/(?=a)a/'];
        }
    }
}
