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

use PHPRegex\Automata\Determinization\DeterminizationAlgorithm;
use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LanguageSolverSoundnessTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    #[DataProvider('provideDeterminizationAlgorithms')]
    public function test_solver_matches_pcre_for_bounded_language(DeterminizationAlgorithm $determinization): void
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(
            matchMode: MatchMode::Full,
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
        yield 'subset' => [DeterminizationAlgorithm::Subset];
        yield 'subset-indexed' => [DeterminizationAlgorithm::SubsetIndexed];
    }

    /**
     * @return array<int, string>
     */
    /**
     * Oracle: PCRE's full-match language of "/a++(?:ab)?/" is plain "a+" —
     * "aaab" is rejected while "/a+(?:ab)?/" accepts it. The greedy spelling
     * would answer a language PCRE does not match, so the pattern is refused.
     */
    #[Test]
    public function test_a_possessive_before_a_nullable_last_follower_is_refused(): void
    {
        $this->assertFalse((bool) @preg_match('/^(?:a++(?:ab)?)$/', 'aaab'));
        $this->assertTrue((bool) @preg_match('/^(?:a+(?:ab)?)$/', 'aaab'));

        $this->expectException(ComplexityException::class);
        (new LanguageSolver())->intersection('/a++(?:ab)?/', '/aaab/');
    }

    /**
     * A possessive quantifier gives back nothing, an iteration included: a
     * body that may match more than one way, an optional letter or a count
     * between two bounds, keeps from the greedy spelling a string the
     * follower or the next iteration would take back. Oracle: "ab" is
     * matched by "/^(?:ab?)+b$/" alone, "aaaab" by "/^(?:a{2,3})+b$/" alone;
     * the solver called each pair equivalent.
     */
    #[Test]
    #[DataProvider('providePossessivesWhoseBodyGivesBack')]
    public function test_a_possessive_whose_body_gives_back_is_refused(string $possessive, string $greedy, string $subject): void
    {
        $this->assertSame(0, preg_match($possessive, $subject));
        $this->assertSame(1, preg_match($greedy, $subject));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('Possessive quantifiers never give back');

        (new LanguageSolver())->equivalent($possessive, $greedy);
    }

    /**
     * @return iterable<string, array{possessive: string, greedy: string, subject: string}>
     */
    public static function providePossessivesWhoseBodyGivesBack(): iterable
    {
        yield 'an optional letter in the body' => ['possessive' => '/^(?:ab?)++b$/', 'greedy' => '/^(?:ab?)+b$/', 'subject' => 'ab'];
        yield 'a count between two bounds in the body' => ['possessive' => '/^(?:a{2,3})++b$/', 'greedy' => '/^(?:a{2,3})+b$/', 'subject' => 'aaaab'];
        yield 'an alternation in a sequence of the body' => ['possessive' => '/^(?:a(?:bc|b))++c$/', 'greedy' => '/^(?:a(?:bc|b))+c$/', 'subject' => 'abc'];
    }

    /**
     * A body that gives back only whole iterations, or an unbounded run of
     * one class, before a follower it never starts like, reads the language
     * of its greedy spelling. Oracle: every string over "abc" up to five
     * characters.
     */
    #[Test]
    #[DataProvider('providePossessivesThatGiveNothingBack')]
    public function test_a_possessive_that_gives_nothing_back_reads_its_greedy_language(string $possessive, string $greedy): void
    {
        foreach ($this->generateStrings(['a', 'b', 'c'], 5) as $subject) {
            $this->assertSame(preg_match($greedy, $subject), preg_match($possessive, $subject), (string) json_encode($subject));
        }

        $this->assertTrue((new LanguageSolver())->equivalent($possessive, $greedy)->isEquivalent);
    }

    /**
     * @return iterable<string, array{possessive: string, greedy: string}>
     */
    public static function providePossessivesThatGiveNothingBack(): iterable
    {
        yield 'a fixed sequence' => ['possessive' => '/^(?:ab)++c$/', 'greedy' => '/^(?:ab)+c$/'];
        yield 'an unbounded run of a letter' => ['possessive' => '/^(?:a+)++b$/', 'greedy' => '/^(?:a+)+b$/'];
        yield 'a single class' => ['possessive' => '/^[ab]++c$/', 'greedy' => '/^[ab]+c$/'];
        yield 'a sequence holding a group' => ['possessive' => '/^(?:(a)b)++c$/', 'greedy' => '/^(?:(a)b)+c$/'];
        yield 'an unbounded run of a group' => ['possessive' => '/^(?:(a)+)++b$/', 'greedy' => '/^(?:(a)+)+b$/'];
    }

    /**
     * @return list<string>
     */
    private function supportedPatterns(): array
    {
        return [
            '/a/',
            '/b/',
            '/a*ab/',
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
