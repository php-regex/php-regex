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

use PHPRegex\PHPUnit\Constraint\DoesNotMatchPattern;
use PHPRegex\PHPUnit\Constraint\MatchesPattern;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Constraint\LogicalNot;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

/**
 * The DoesNotMatchPattern constraint: it passes only when the match attempt
 * ran and answered no match. A pattern that does not parse, a pattern the
 * engine refuses and a subject the engine cannot read each fail carrying
 * the library's or the engine's own words, where negating MatchesPattern
 * would read any of them as "not a match".
 */
final class DoesNotMatchPatternTest extends TestCase
{
    #[Test]
    public function test_does_not_match_pattern_passes_on_a_subject_the_pattern_misses(): void
    {
        $this->assertThat('x', new DoesNotMatchPattern('/y/'));
    }

    #[Test]
    public function test_does_not_match_pattern_fails_on_a_subject_the_pattern_matches(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat('abc', new DoesNotMatchPattern('/b/'));
        });

        $this->assertSame("Failed asserting that 'abc' does not match the pattern '/b/'.", $failure);
    }

    /**
     * Negating MatchesPattern passes on a pattern that does not parse; the
     * constraint fails on it with the library's message and caret.
     */
    #[Test]
    public function test_does_not_match_pattern_fails_with_the_library_error_on_a_parse_invalid_pattern(): void
    {
        $this->assertTrue((new LogicalNot(new MatchesPattern('/a(/')))->evaluate('x', '', true), 'The row must be one the negation passes.');

        $failure = $this->failingMessage(function (): void {
            $this->assertThat('x', new DoesNotMatchPattern('/a(/'));
        });

        $this->assertStringStartsWith("Failed asserting that 'x' does not match the pattern '/a(/'.\n", $failure);
        $this->assertStringContainsString('Expected ) at end of input', $failure);
        $this->assertStringContainsString('^', $failure);
    }

    /**
     * The parser accepts the reversed bounds; preg_match() refuses them
     * ("numbers out of order in {} quantifier at offset 5" on PHP 8.4.26).
     */
    #[Test]
    public function test_does_not_match_pattern_fails_with_the_engine_error_on_a_pattern_the_parser_accepts(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat('a', new DoesNotMatchPattern('/x{2,1}/'));
        });

        $this->assertStringContainsString('numbers out of order', $failure);
        $this->assertStringContainsString('at offset 5', $failure);
    }

    /**
     * preg_match('/a/u', "\xFFa") answers false, "Malformed UTF-8
     * characters": no attempt ran, so nothing says the pattern misses.
     */
    #[Test]
    public function test_does_not_match_pattern_fails_with_the_engine_error_on_an_invalid_utf8_subject(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat("\xFFa", new DoesNotMatchPattern('/a/u'));
        });

        $this->assertStringContainsString('Malformed UTF-8 characters', $failure);
    }

    #[Test]
    public function test_does_not_match_pattern_fails_with_the_limit_message_beyond_its_parse_limits(): void
    {
        $tooLong = '/'.str_repeat('a', 100_001).'/';

        $failure = $this->failingMessage(function () use ($tooLong): void {
            $this->assertThat('b', new DoesNotMatchPattern($tooLong));
        });

        $this->assertStringContainsString('exceeds maximum length', $failure);
    }

    #[Test]
    public function test_does_not_match_pattern_refuses_a_non_string_subject(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat(['a'], new DoesNotMatchPattern('/b/'));
        });

        $this->assertStringContainsString('the subject must be a string, array given', $failure);
    }

    #[Test]
    public function test_does_not_match_pattern_carries_the_callers_note(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat('x', new DoesNotMatchPattern('/a(/'), 'the row that regressed');
        });

        $this->assertStringStartsWith("the row that regressed\nFailed asserting that 'x' does not match the pattern '/a(/'.", $failure);
    }

    /**
     * A failure is an answer, so PHPUnit counts the assertion whatever the
     * pattern is: a broken one never leaves the test without assertions.
     */
    #[Test]
    public function test_does_not_match_pattern_counts_one_assertion_on_a_broken_pattern(): void
    {
        $before = self::getCount();

        $this->failingMessage(function (): void {
            $this->assertThat('x', new DoesNotMatchPattern('/a(/'));
        });

        $this->assertSame($before + 1, self::getCount());
    }

    #[Test]
    public function test_does_not_match_pattern_reads_as_one_constraint(): void
    {
        $constraint = new DoesNotMatchPattern('/b/');

        $this->assertSame("does not match the pattern '/b/'", $constraint->toString());
        $this->assertCount(1, $constraint);
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
