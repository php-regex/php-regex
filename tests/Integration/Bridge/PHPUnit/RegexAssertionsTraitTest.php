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

use PHPRegex\PHPUnit\RegexAssertions;
use PHPRegex\Tests\Support\MatchesPatternCases;
use PHPRegex\Tests\Support\PlainRegexAssert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

/**
 * The RegexAssertions trait itself: the same vocabulary rows the
 * constraint door takes pass through the trait on a TestCase and on a
 * bare subclass of Assert — the narrowest surface the trait still has to
 * work on — and each assertion's failure carries its verdict with the
 * caller's note after it, never a skip.
 */
final class RegexAssertionsTraitTest extends TestCase
{
    use MatchesPatternCases;
    use RegexAssertions;

    /**
     * @param array<int|string, mixed> $expected
     */
    #[DataProvider('provideMatchExpectations')]
    #[Test]
    public function test_assert_pattern_matches_accepts_each_vocabulary_spelling(string $pattern, string $subject, array $expected): void
    {
        self::assertPatternMatches($pattern, $subject, $expected);
    }

    /**
     * @param array<int|string, mixed> $expected
     */
    #[DataProvider('provideMatchExpectations')]
    #[Test]
    public function test_assert_pattern_matches_works_on_a_bare_assert_subclass(string $pattern, string $subject, array $expected): void
    {
        PlainRegexAssert::assertPatternMatches($pattern, $subject, $expected);
    }

    #[Test]
    public function test_assert_pattern_does_not_match_passes_on_a_subject_the_pattern_misses(): void
    {
        self::assertPatternDoesNotMatch('/y/', 'x');
    }

    #[Test]
    public function test_assert_pattern_does_not_match_fails_with_the_callers_note_carried(): void
    {
        $failure = $this->failingMessage(static function (): void {
            self::assertPatternDoesNotMatch('/b/', 'abc', 'the custom note');
        });

        $this->assertStringContainsString('the custom note', $failure);
    }

    /**
     * The negated assertion pre-runs the attempt so an unusable pattern
     * fails loudly instead of passing under the negation: a pattern that
     * does not parse never reaches "does not match".
     */
    #[Test]
    public function test_assert_pattern_does_not_match_fails_with_the_library_error_on_a_parse_invalid_pattern(): void
    {
        $failure = $this->failingMessage(static function (): void {
            self::assertPatternDoesNotMatch('/(a/', 'a');
        });

        $this->assertStringContainsString('Expected ) at end of input', $failure);
    }

    #[Test]
    public function test_assert_pattern_compiles_passes_on_a_pattern_the_engine_takes(): void
    {
        self::assertPatternCompiles('/[a-z]+/');
    }

    /**
     * A refusal is an answer the engine gave, not a gap in the run: the
     * assertion fails carrying the PCRE message and the offset it names.
     */
    #[Test]
    public function test_assert_pattern_compiles_never_skips_a_refusal(): void
    {
        $failure = $this->failingMessage(static function (): void {
            self::assertPatternCompiles('/x{2,1}/');
        });

        $this->assertStringContainsString('numbers out of order', $failure);
        $this->assertStringContainsString('at offset 5', $failure);
    }

    #[Test]
    public function test_assert_pattern_is_linear_fails_with_the_verdict_as_the_first_line(): void
    {
        $failure = $this->failingMessage(static function (): void {
            self::assertPatternIsLinear('/(a+)+b/');
        });

        $this->assertStringStartsWith('proven unsafe:', $failure);
    }

    /**
     * The verdicts are memoized per pattern for the process: the analyzer
     * is deterministic per release, so the rerun answers with the very
     * message the first call built.
     */
    #[Test]
    public function test_assert_pattern_is_linear_answers_the_same_on_the_memoized_rerun(): void
    {
        $first = $this->failingMessage(static function (): void {
            self::assertPatternIsLinear('/(a|a)*b/');
        });

        $rerun = $this->failingMessage(static function (): void {
            self::assertPatternIsLinear('/(a|a)*b/');
        });

        $this->assertSame($first, $rerun);
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
