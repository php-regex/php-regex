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

namespace RegexParser\Tests\Unit\Automata;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Automata\Determinization\DeterminizationAlgorithm;
use RegexParser\Automata\LanguageSolver;
use RegexParser\Automata\Options\MatchMode;
use RegexParser\Automata\Options\SolverOptions;

final class LanguageSolverSoundnessTest extends TestCase
{
    #[Test]
    #[DataProvider('provideDeterminizationAlgorithms')]
    public function test_solver_matches_pcre_for_bounded_language(DeterminizationAlgorithm $determinization): void
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(
            matchMode: MatchMode::FULL,
            determinizationAlgorithm: $determinization,
        );
        $patterns = $this->supportedPatterns();
        $strings = $this->generateStrings(['a', 'b'], 4);

        foreach ($patterns as $left) {
            foreach ($patterns as $right) {
                $expectedIntersectionEmpty = $this->isIntersectionEmpty($left, $right, $strings);
                $intersection = $solver->intersection($left, $right, $options);
                $this->assertSame(
                    $expectedIntersectionEmpty,
                    $intersection->isEmpty,
                    \sprintf('Intersection mismatch for %s vs %s', $left, $right),
                );

                $expectedSubset = $this->isSubset($left, $right, $strings);
                $subset = $solver->subsetOf($left, $right, $options);
                $this->assertSame(
                    $expectedSubset,
                    $subset->isSubset,
                    \sprintf('Subset mismatch for %s <= %s', $left, $right),
                );

                $expectedEquivalent = $expectedSubset && $this->isSubset($right, $left, $strings);
                $equivalent = $solver->equivalent($left, $right, $options);
                $this->assertSame(
                    $expectedEquivalent,
                    $equivalent->isEquivalent,
                    \sprintf('Equivalence mismatch for %s <=> %s', $left, $right),
                );
            }
        }
    }

    public static function provideDeterminizationAlgorithms(): \Generator
    {
        yield 'subset' => [DeterminizationAlgorithm::SUBSET];
        yield 'subset-indexed' => [DeterminizationAlgorithm::SUBSET_INDEXED];
    }

    /**
     * @return array<int, string>
     */
    private function supportedPatterns(): array
    {
        return [
            '/a/',
            '/b/',
            '/ab/',
            '/ba/',
            '/a*/',
            '/a+/',
            '/a?/',
            '/a{2}/',
            '/a{0,2}/',
            '/(a|b)/',
            '/(ab|ba)/',
            '/[ab]/',
            '/[ab]+/',
        ];
    }

    /**
     * @param array<int, string> $alphabet
     *
     * @return array<int, string>
     */
    private function generateStrings(array $alphabet, int $maxLength): array
    {
        $results = [''];
        $current = [''];

        for ($length = 1; $length <= $maxLength; $length++) {
            $next = [];
            foreach ($current as $prefix) {
                foreach ($alphabet as $char) {
                    $next[] = $prefix.$char;
                }
            }

            foreach ($next as $value) {
                $results[] = $value;
            }

            $current = $next;
        }

        return $results;
    }

    /**
     * @param array<int, string> $strings
     */
    private function isSubset(string $left, string $right, array $strings): bool
    {
        foreach ($strings as $string) {
            if ($this->matchesFull($left, $string) && !$this->matchesFull($right, $string)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $strings
     */
    private function isIntersectionEmpty(string $left, string $right, array $strings): bool
    {
        foreach ($strings as $string) {
            if ($this->matchesFull($left, $string) && $this->matchesFull($right, $string)) {
                return false;
            }
        }

        return true;
    }

    private function matchesFull(string $pattern, string $subject): bool
    {
        $matches = [];
        $result = \preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE);
        if (1 !== $result) {
            return false;
        }

        return $matches[0][0] === $subject && 0 === $matches[0][1];
    }
}
