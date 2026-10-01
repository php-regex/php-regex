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

use PhpRegex\Automata\Determinization\DeterminizationAlgorithm;
use PhpRegex\Automata\LanguageSolver;
use PhpRegex\Automata\Options\MatchMode;
use PhpRegex\Automata\Options\SolverOptions;
use PhpRegex\Automata\Solver\InMemoryDfaCache;
use PhpRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LanguageSolverEdgeCaseTest extends TestCase
{
    #[Test]
    public function test_intersection_of_identical_patterns(): void
    {
        $solver = new LanguageSolver();
        $result = $solver->intersection('/abc/', '/abc/', $this->options());

        $this->assertFalse($result->isEmpty);
        $this->assertSame('abc', $result->example);
    }

    #[Test]
    public function test_intersection_of_empty_languages(): void
    {
        $solver = new LanguageSolver();
        $result = $solver->intersection('/a[^\x00-\xff]/', '/b/', $this->options());

        $this->assertTrue($result->isEmpty);
        $this->assertNull($result->example);
    }

    #[Test]
    public function test_equivalence_of_case_insensitive_patterns(): void
    {
        $solver = new LanguageSolver();
        $result = $solver->equivalent('/[a-z]+/i', '/[A-Za-z]+/', $this->options());

        $this->assertTrue($result->isEquivalent);
    }

    #[Test]
    public function test_equivalence_of_char_class_shorthand(): void
    {
        $solver = new LanguageSolver();
        $result = $solver->equivalent('/[0-9]/', '/\\d/', $this->options());

        $this->assertTrue($result->isEquivalent);
    }

    #[Test]
    public function test_subset_of_dot_star(): void
    {
        $solver = new LanguageSolver();
        $result = $solver->subsetOf('/abc/', '/.*/', $this->options());

        $this->assertTrue($result->isSubset);
    }

    #[Test]
    public function test_dot_star_not_subset_of_literal(): void
    {
        $solver = new LanguageSolver();
        $result = $solver->subsetOf('/.*/', '/abc/', $this->options());

        $this->assertFalse($result->isSubset);
        $this->assertNotNull($result->counterExample);
    }

    #[Test]
    public function test_empty_string_pattern_equivalence(): void
    {
        $solver = new LanguageSolver();
        $result = $solver->equivalent('/^$/', '//', $this->options());

        $this->assertTrue($result->isEquivalent);
    }

    #[Test]
    public function test_quantifier_equivalences(): void
    {
        $solver = new LanguageSolver();

        $result = $solver->equivalent('/a?a?/', '/a{0,2}/', $this->options());
        $this->assertTrue($result->isEquivalent);

        $result = $solver->equivalent('/aaa*/', '/a{2,}/', $this->options());
        $this->assertTrue($result->isEquivalent);
    }

    #[Test]
    public function test_alternation_commutativity(): void
    {
        $solver = new LanguageSolver();
        $result = $solver->equivalent('/foo|bar/', '/bar|foo/', $this->options());

        $this->assertTrue($result->isEquivalent);
    }

    #[Test]
    public function test_alternation_distributivity(): void
    {
        $solver = new LanguageSolver();
        $result = $solver->equivalent('/ab|ac/', '/a(b|c)/', $this->options());

        $this->assertTrue($result->isEquivalent);
    }

    #[Test]
    #[DataProvider('provideDeterminizationAlgorithms')]
    public function test_both_algorithms_agree_on_intersection(DeterminizationAlgorithm $algorithm): void
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(
            matchMode: MatchMode::Full,
            determinizationAlgorithm: $algorithm,
        );

        $result = $solver->intersection('/[a-z]+/', '/[a-f]{3}/', $options);

        $this->assertFalse($result->isEmpty);
        $this->assertNotNull($result->example);
        $this->assertMatchesRegularExpression('/^[a-f]{3}$/', $result->example ?? '');
    }

    #[Test]
    #[DataProvider('provideDeterminizationAlgorithms')]
    public function test_both_algorithms_agree_on_equivalence(DeterminizationAlgorithm $algorithm): void
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(
            matchMode: MatchMode::Full,
            determinizationAlgorithm: $algorithm,
        );

        $result = $solver->equivalent('/[abc]+/', '/[a-c]+/', $options);

        $this->assertTrue($result->isEquivalent);
    }

    #[Test]
    public function test_dfa_cache_avoids_recompilation(): void
    {
        $cache = new InMemoryDfaCache();
        $solver = new LanguageSolver(dfaCache: $cache);
        $options = $this->options();

        $result1 = $solver->intersection('/abc/', '/def/', $options);
        $result2 = $solver->intersection('/abc/', '/ghi/', $options);

        $this->assertTrue($result1->isEmpty);
        $this->assertTrue($result2->isEmpty);
    }

    #[Test]
    public function test_dfa_cache_keeps_targets_apart(): void
    {
        // "{,2}" repeats from PCRE2 10.43 and is text before (pcre2test).
        $cache = new InMemoryDfaCache();
        $newer = new LanguageSolver(RegexParser::create(['cache' => null, 'pcre_version' => '10.44']), dfaCache: $cache);
        $older = new LanguageSolver(RegexParser::create(['cache' => null, 'pcre_version' => '10.42']), dfaCache: $cache);

        $this->assertFalse($newer->equivalent('/^x{,2}$/', '/^x\{,2\}$/', $this->options())->isEquivalent);
        $this->assertTrue($older->equivalent('/^x{,2}$/', '/^x\{,2\}$/', $this->options())->isEquivalent);
    }

    #[Test]
    public function test_compile_returns_dfa(): void
    {
        $solver = new LanguageSolver();
        $dfa = $solver->compile('/[a-z]+/', $this->options());

        $this->assertNotEmpty($dfa->states);
        $state = $dfa->getState($dfa->startState);
        $this->assertNotNull($state->transitionFor(\ord('a')));
    }

    #[Test]
    public function test_dotall_flag_equivalence(): void
    {
        $solver = new LanguageSolver();
        $result = $solver->equivalent('/.*/s', '/[\\x00-\\xff]*/', $this->options());

        $this->assertTrue($result->isEquivalent);
    }

    #[Test]
    public function test_word_char_equivalence(): void
    {
        $solver = new LanguageSolver();
        $result = $solver->equivalent('/\\w/', '/[A-Za-z0-9_]/', $this->options());

        $this->assertTrue($result->isEquivalent);
    }

    #[Test]
    public function test_space_char_equivalence(): void
    {
        $solver = new LanguageSolver();
        $result = $solver->equivalent('/\\s/', '/[ \\t\\n\\r\\f\\x0B]/', $this->options());

        $this->assertTrue($result->isEquivalent);
    }

    /**
     * @return \Generator<string, array{\PhpRegex\Automata\Determinization\DeterminizationAlgorithm}>
     */
    public static function provideDeterminizationAlgorithms(): \Generator
    {
        yield 'subset' => [DeterminizationAlgorithm::Subset];
        yield 'subset-indexed' => [DeterminizationAlgorithm::SubsetIndexed];
    }

    private function options(): SolverOptions
    {
        return new SolverOptions(matchMode: MatchMode::Full);
    }
}
