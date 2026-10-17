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

namespace PHPRegex\Tests\Integration\Bridge\PHPUnit;

use PHPRegex\PHPUnit\Constraint\IsLinearPattern;
use PHPRegex\PHPUnit\RegexAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

/**
 * The IsLinearPattern constraint and its trait door: a proven-linear
 * pattern passes, and every failure leads with its verdict's stable
 * prefix — "proven unsafe:" for a held witness or a quadratic search,
 * "could not analyze:" for a budget, a model or a parse refusal — so a
 * quarantine can tell the two apart on the first line alone.
 */
final class IsLinearTest extends TestCase
{
    use RegexAssertions;

    #[Test]
    public function test_is_linear_passes_on_a_proven_linear_pattern(): void
    {
        $this->assertThat('/^a+$/', new IsLinearPattern());
    }

    #[DataProvider('provideProvenUnsafePatterns')]
    #[Test]
    public function test_is_linear_fails_proven_unsafe_with_the_witness_leading(string $pattern): void
    {
        $failure = $this->failingMessage(static function () use ($pattern): void {
            self::assertPatternIsLinear($pattern);
        });

        $this->assertStringStartsWith('proven unsafe:', $failure);
        $this->assertStringContainsString('!b', $failure);
    }

    /**
     * One match attempt of these patterns is proven linear — isProvenSafe()
     * alone would pass them — but the unanchored search retries that
     * attempt at every start, a cost the analyzer holds a witness for, so
     * the searchCost guard fails them under the honest name.
     */
    #[DataProvider('provideQuadraticSearchPatterns')]
    #[Test]
    public function test_is_linear_fails_a_quadratic_search_a_linear_attempt_would_excuse(string $pattern): void
    {
        $failure = $this->failingMessage(static function () use ($pattern): void {
            self::assertPatternIsLinear($pattern);
        });

        $this->assertStringStartsWith('proven unsafe:', $failure);
        $this->assertStringContainsString('quadratic unanchored search', $failure);
    }

    #[DataProvider('provideUnprovenPatterns')]
    #[Test]
    public function test_is_linear_fails_could_not_analyze_when_no_verdict_was_reached(string $pattern): void
    {
        $failure = $this->failingMessage(static function () use ($pattern): void {
            self::assertPatternIsLinear($pattern);
        });

        $this->assertStringStartsWith('could not analyze:', $failure);
    }

    #[Test]
    public function test_is_linear_carries_the_verdict_through_the_constraint_door(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat('/(a+)+b/', new IsLinearPattern());
        });

        $this->assertStringStartsWith('Failed asserting that', $failure);
        $this->assertStringContainsString('proven unsafe:', $failure);
        $this->assertStringContainsString('!b', $failure);
    }

    /**
     * The verdicts are memoized per pattern for the process: the analyzer
     * is deterministic per release, so the rerun answers with the very
     * message the first call built.
     */
    #[Test]
    public function test_is_linear_answers_the_same_on_the_memoized_rerun(): void
    {
        $first = $this->failingMessage(static function (): void {
            self::assertPatternIsLinear('/(a+)(?1)$/');
        });

        $rerun = $this->failingMessage(static function (): void {
            self::assertPatternIsLinear('/(a+)(?1)$/');
        });

        $this->assertSame($first, $rerun);
    }

    /**
     * The operand is the pattern, and a non-string one is refused with what
     * arrived, never handed to the analyzer.
     */
    #[Test]
    public function test_is_linear_refuses_a_non_string_operand(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat(42, new IsLinearPattern());
        });

        $this->assertStringContainsString('the pattern must be a string, int given', $failure);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideProvenUnsafePatterns(): iterable
    {
        // The nested quantifier holds an exponential witness ending "!b".
        yield 'nested quantifier' => ['pattern' => '/(a+)+b/'];
        // The alternation's branches agree, so the backtracking is the same
        // exponential one under another spelling.
        yield 'ambiguous alternation' => ['pattern' => '/(a|a)*b/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideQuadraticSearchPatterns(): iterable
    {
        // Each start of a run of letters is retried against the digit.
        yield 'letters before a digit' => ['pattern' => '/[a-z]+\d/'];
        // The anchored end leaves the search to try every start of a run
        // of spaces.
        yield 'trailing space run' => ['pattern' => '/\s+$/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideUnprovenPatterns(): iterable
    {
        // Four thousand and ninety-six states deep, past the budget.
        yield 'budget exhausted' => ['pattern' => '/^(?:(?:a{16}){16}){16}$/'];
        // A backreference is outside the analyzer's model.
        yield 'backreference outside the model' => ['pattern' => '/(a+)\1/'];
        // A subroutine recursion is outside it too.
        yield 'recursion outside the model' => ['pattern' => '/(a+)(?1)$/'];
        // A pattern that does not parse cannot be judged either.
        yield 'parse-invalid pattern' => ['pattern' => '/(a/'];
    }

    /**
     * The failure message of an assertion that had to fail, so its wording
     * can be pinned; fail() escapes the catch by throwing the parent class.
     *
     * @param callable(): mixed $assertion
     */
    private function failingMessage(callable $assertion): string
    {
        try {
            $assertion();
            self::fail('Expected the assertion to fail.');
        } catch (ExpectationFailedException $failure) {
            return $failure->getMessage();
        }
    }
}
