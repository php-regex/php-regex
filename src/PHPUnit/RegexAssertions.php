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

namespace PHPRegex\PHPUnit;

use PHPRegex\PHPUnit\Constraint\CompilesPattern;
use PHPRegex\PHPUnit\Constraint\DoesNotMatchPattern;
use PHPRegex\PHPUnit\Constraint\IsLinearPattern;
use PHPRegex\PHPUnit\Constraint\MatchesPattern;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * Regex assertions for PHPUnit, backed by the PHPRegex parser.
 *
 * The methods are static so the trait works wherever PHPUnit asserts from:
 * a TestCase, and a bare subclass of Assert for arrange-only code. Every
 * path runs through assertThat(), so PHPUnit counts the assertion however
 * it answers, and every failure carries the library's or the engine's own
 * words, with the pattern shown as the caller wrote it.
 */
trait RegexAssertions
{
    /**
     * Asserts that one match attempt of the pattern against the subject
     * answers what the capture expectation asks, the attempt running under
     * PREG_UNMATCHED_AS_NULL and PREG_OFFSET_CAPTURE so offsets are bytes
     * and a non-participating group is never ambiguous.
     *
     * The expectation's group keys are validated against the pattern's own
     * groups before any value is compared: an unknown name or number, a
     * name owning several numbers, or a shape the vocabulary does not spell
     * fails naming what the pattern really has. Under the n modifier the
     * named groups still capture, renumbered from 1, so a name or the
     * number the renumbering gave it asserts as usual; a number with no
     * group behind it fails saying what the modifier did. When only the
     * captures differ, the failure renders a focused diff.
     *
     * @param array<int|string, mixed> $expected the capture expectation: true (participated), false (did not
     *                                           participate), a string value, or [value, byte offset], keyed by
     *                                           group number or name; empty asks only whether the pattern matched
     */
    public static function assertPatternMatches(string $pattern, string $subject, array $expected = [], string $message = ''): void
    {
        self::assertThat($subject, new MatchesPattern($pattern, $expected), $message);
    }

    /**
     * Asserts that the pattern does not match the subject. A pattern that
     * does not parse, or an engine refusal, fails the assertion rather
     * than passing it as "not a match".
     */
    public static function assertPatternDoesNotMatch(string $pattern, string $subject, string $message = ''): void
    {
        self::assertThat($subject, new DoesNotMatchPattern($pattern), $message);
    }

    /**
     * Asserts that the running engine compiles the pattern: a refusal
     * fails carrying the PCRE message and the offset it names, never a
     * skip. The truth is the engine's alone, so a pattern beyond the
     * library's own parse limits still passes here when the engine takes
     * it.
     */
    public static function assertPatternCompiles(string $pattern, string $message = ''): void
    {
        self::assertThat($pattern, new CompilesPattern(), $message);
    }

    /**
     * Asserts that the pattern is proven to match in linear time, the name
     * staying honest: one match attempt proven linear and the unanchored
     * search carrying no quadratic-cost witness.
     *
     * The failure's first line carries the verdict's stable prefix, so a
     * quarantine can tell a held witness from an unproven verdict apart:
     * "proven unsafe:" for an unsafe attempt or a quadratic search,
     * "could not analyze:" for a budget, a model or a parse refusal.
     */
    public static function assertPatternIsLinear(string $pattern, string $message = ''): void
    {
        $constraint = new IsLinearPattern();

        try {
            self::assertThat($pattern, $constraint, $message);
        } catch (ExpectationFailedException $failure) {
            // The verdict the constraint reached leads, its prefix the first
            // line, the caller's note after it; the constraint's own failure,
            // its prose included, rides along as the previous exception.
            throw new ExpectationFailedException(
                self::noted($constraint->verdict() ?? $failure->getMessage(), $message),
                $failure->getComparisonFailure(),
                $failure,
            );
        }
    }

    /**
     * The failure the assertion built, its caller's note carried after it,
     * so a pinned first line stays the first line.
     */
    private static function noted(string $failure, string $message): string
    {
        return '' === $message ? $failure : $failure."\n".$message;
    }
}
