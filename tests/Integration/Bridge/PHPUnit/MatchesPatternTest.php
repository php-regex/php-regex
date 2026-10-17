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

use PHPRegex\PHPUnit\Constraint\MatchesPattern;
use PHPRegex\Tests\Support\MatchesPatternCases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Constraint\LogicalNot;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

/**
 * The MatchesPattern constraint: every spelling the capture-expectation
 * vocabulary accepts passes, and every refusal fails naming what the
 * pattern really has — the library's words for a parse refusal, the
 * engine's for a runtime one, and the vocabulary's own for an expectation
 * no group of the pattern answers to.
 */
final class MatchesPatternTest extends TestCase
{
    use MatchesPatternCases;

    /**
     * @param array<int|string, mixed> $expected
     */
    #[DataProvider('provideMatchExpectations')]
    #[Test]
    public function test_matches_pattern_accepts_each_vocabulary_spelling(string $pattern, string $subject, array $expected): void
    {
        $this->assertThat($subject, new MatchesPattern($pattern, $expected));
    }

    #[Test]
    public function test_matches_pattern_fails_when_the_subject_does_not_match(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat('hello', new MatchesPattern('/world/'));
        });

        $this->assertNotSame('', $failure);
    }

    /**
     * The match itself succeeds on "ab"; only the typo'd name can fail the
     * assertion — the silent pass this constraint exists to prevent.
     */
    #[Test]
    public function test_matches_pattern_fails_listing_the_real_groups_on_an_unknown_name(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat('ab', new MatchesPattern('/(?<alpha>a)(?<beta>b)/', ['alpa' => 'a']));
        });

        $this->assertStringContainsString('alpha', $failure);
        $this->assertStringContainsString('beta', $failure);
    }

    #[Test]
    public function test_matches_pattern_fails_on_an_unknown_group_number(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat('ab', new MatchesPattern('/(a)(b)/', [3 => 'a']));
        });

        $this->assertStringContainsString('3', $failure);
    }

    /**
     * Under n the un-named groups never capture: the refusal says what the
     * modifier really does rather than letting a number silently miss.
     */
    #[Test]
    public function test_matches_pattern_refuses_a_numeric_expectation_under_the_n_modifier(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat('a', new MatchesPattern('/(a)/n', [1 => true]));
        });

        $this->assertStringContainsString('no group 1 under the n modifier', $failure);
        $this->assertStringContainsString('only named groups capture', $failure);
    }

    /**
     * The renderer must not follow a circular or deep structure into the
     * stack floor — the refusal is the answer, not a crash.
     */
    #[Test]
    public function test_matches_pattern_refuses_a_circular_expectation_without_recursing(): void
    {
        $circular = ['x'];
        $circular[0] = &$circular;

        $failure = $this->failingMessage(function () use ($circular): void {
            $this->assertThat('a', new MatchesPattern('/(a)/', [1 => $circular]));
        });

        $this->assertStringContainsString('unknown expectation for group 1', $failure);
    }

    /**
     * The ten leading groups are written as repeated "(a)", so the digits
     * the refusal lists appear nowhere else but in the group numbers it
     * names for the duplicate.
     */
    #[Test]
    public function test_matches_pattern_rejects_a_name_owning_several_numbers(): void
    {
        $pattern = '/'.str_repeat('(a)', 10).'(?J)(?<d>b)x|(?<d>c)y/';

        $failure = $this->failingMessage(function () use ($pattern): void {
            $this->assertThat('bx', new MatchesPattern($pattern, ['d' => 'b']));
        });

        $this->assertStringContainsString('11', $failure);
        $this->assertStringContainsString('12', $failure);
    }

    #[Test]
    public function test_matches_pattern_rejects_a_contradictory_whole_match_expectation(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat('a', new MatchesPattern('/(a)/', [0 => false]));
        });

        $this->assertStringContainsString('whole match', $failure);
    }

    /**
     * On "x" group 2 participates by matching the empty string while
     * group 1 stays out: false must not excuse the one nor '' the other.
     */
    #[Test]
    public function test_matches_pattern_keeps_matched_empty_apart_from_non_participation(): void
    {
        $nonParticipationSpelledFalse = $this->failingMessage(function (): void {
            $this->assertThat('x', new MatchesPattern('/(a)?(b*)/', [2 => false]));
        });

        $matchedEmptySpelledAsAValue = $this->failingMessage(function (): void {
            $this->assertThat('x', new MatchesPattern('/(a)?(b*)/', [1 => '']));
        });

        $this->assertNotSame('', $nonParticipationSpelledFalse);
        $this->assertNotSame('', $matchedEmptySpelledAsAValue);
    }

    #[Test]
    public function test_matches_pattern_fails_with_the_library_error_on_a_parse_invalid_pattern(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat('a', new MatchesPattern('/(a/'));
        });

        $this->assertStringContainsString('Expected ) at end of input', $failure);
        $this->assertStringContainsString('^', $failure);
    }

    #[Test]
    public function test_matches_pattern_fails_with_the_engine_error_on_an_invalid_utf8_subject(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat("\xFFa", new MatchesPattern('/a/u'));
        });

        $this->assertStringContainsString('Malformed UTF-8 characters', $failure);
    }

    /**
     * Parse keeps its limits for vocabulary validation: a pattern past the
     * library's maximum length fails here carrying the limit message. The
     * asymmetry with assertPatternCompiles(), whose truth is the engine's
     * alone, is documented in the guide.
     */
    #[Test]
    public function test_matches_pattern_fails_with_the_limit_message_beyond_its_parse_limits(): void
    {
        $tooLong = '/'.str_repeat('a', 100_001).'/';

        $failure = $this->failingMessage(function () use ($tooLong): void {
            $this->assertThat('a', new MatchesPattern($tooLong));
        });

        $this->assertStringContainsString('exceeds maximum length', $failure);
    }

    /**
     * A number past the renumbering under n has no group behind it: the
     * second group this pattern's text seems to promise is the named one,
     * number 1, so number 2 fails saying what the modifier did.
     */
    #[Test]
    public function test_matches_pattern_fails_on_a_group_the_engine_does_not_capture_under_n(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat('ab', new MatchesPattern('/(a)(?<x>b)/n', [2 => true]));
        });

        $this->assertStringContainsString('no group 2 under the n modifier', $failure);
        $this->assertStringContainsString("1 'x'", $failure);
    }

    #[Test]
    public function test_matches_pattern_fails_with_the_recursion_limit_message(): void
    {
        $tooDeep = '/'.str_repeat('(', 1_025).'a'.str_repeat(')', 1_025).'/';

        $failure = $this->failingMessage(function () use ($tooDeep): void {
            $this->assertThat('a', new MatchesPattern($tooDeep));
        });

        $this->assertStringContainsString('Recursion limit of 1024 exceeded', $failure);
    }

    /**
     * The parser accepts the reversed bounds; the engine alone refuses it,
     * and the refusal reaches the failure with the engine's own words and
     * the offset the caller's pattern spells (the guard's prefix removed).
     */
    #[Test]
    public function test_matches_pattern_fails_with_the_engine_error_on_a_pattern_the_parser_accepts(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat('a', new MatchesPattern('/x{2,1}/'));
        });

        $this->assertStringContainsString('numbers out of order', $failure);
        $this->assertStringContainsString('at offset 5', $failure);
    }

    /**
     * A pattern the parser reads but the vocabulary's validation refuses —
     * a conditional on a group that does not exist — fails with the
     * library's message and caret, before any match is attempted.
     */
    #[Test]
    public function test_matches_pattern_fails_with_the_library_error_when_validation_refuses(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat('b', new MatchesPattern('/(?(9)a)b/', [0 => 'b']));
        });

        $this->assertStringContainsString('Backreference to non-existent group', $failure);
        $this->assertStringContainsString('^', $failure);
    }

    /**
     * An object is no spelling the vocabulary reads: the refusal names the
     * debug type, so the caller sees what was handed over.
     */
    #[Test]
    public function test_matches_pattern_refuses_an_object_expectation_spelling(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat('a', new MatchesPattern('/(a)/', [1 => new \stdClass()]));
        });

        $this->assertStringContainsString('unknown expectation for group 1', $failure);
        $this->assertStringContainsString('stdClass', $failure);
    }

    #[Test]
    public function test_matches_pattern_composes_with_logical_not(): void
    {
        $this->assertThat('x', new LogicalNot(new MatchesPattern('/y/')));

        $failure = $this->failingMessage(function (): void {
            $this->assertThat('y', new LogicalNot(new MatchesPattern('/y/')));
        });

        $this->assertNotSame('', $failure);
    }

    /**
     * The operand is the subject, and a non-string one is refused with what
     * arrived, never handed to the engine.
     */
    #[Test]
    public function test_matches_pattern_refuses_a_non_string_subject(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat(['a'], new MatchesPattern('/a/'));
        });

        $this->assertStringContainsString('the subject must be a string, array given', $failure);
    }

    /**
     * The caller's note rides the capture diff too, ahead of it, so a
     * failing vocabulary comparison still carries why the row mattered.
     * The diff itself rides the ComparisonFailure, not the message string.
     */
    #[Test]
    public function test_matches_pattern_carries_the_callers_note_into_the_capture_diff(): void
    {
        try {
            $this->assertThat('ab', new MatchesPattern('/(a)(b)/', [2 => 'c']), 'the row that regressed');
            self::fail('Expected the assertion to fail.');
        } catch (ExpectationFailedException $failure) {
            $this->assertStringContainsString('the row that regressed', $failure->getMessage());
            $this->assertStringContainsString("+[2 => 'b']", (string) $failure->getComparisonFailure()?->getDiff());
        }
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
