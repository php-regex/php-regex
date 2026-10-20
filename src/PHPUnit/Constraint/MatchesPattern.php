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

namespace PHPRegex\PHPUnit\Constraint;

use PHPRegex\PHPUnit\MatchAttempt;
use PHPRegex\PHPUnit\PatternLab;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\ExpectationFailedException;
use SebastianBergmann\Comparator\ComparisonFailure;

/**
 * Whether one match attempt of the pattern against the subject answers
 * what the capture expectation asks.
 *
 * The attempt runs under PREG_UNMATCHED_AS_NULL and PREG_OFFSET_CAPTURE on
 * a no-JIT guarded spelling of the pattern, the expectation's group keys
 * are validated against the pattern's own groups before any value is
 * compared, and a pattern that does not parse or an engine refusal fails
 * carrying the library's or the engine's own words. When only the captures
 * differ, the failure carries a comparison of the expectation against the
 * engine's answer, so PHPUnit renders a focused diff.
 *
 * Assert no match with DoesNotMatchPattern rather than negating this one:
 * LogicalNot reads every failure as "not a match", the failure of a pattern
 * that does not parse included.
 */
final class MatchesPattern extends Constraint
{
    private ?MatchAttempt $attempt = null;

    /**
     * @param string                   $pattern  the pattern as the caller wrote it, also the one the failure shows
     * @param array<int|string, mixed> $expected the capture expectation: true (participated), false (did not
     *                                           participate), a string value, or [value, byte offset], keyed by
     *                                           group number or name
     */
    public function __construct(private readonly string $pattern, private readonly array $expected = []) {}

    /**
     * Returns a string representation of the constraint.
     */
    public function toString(): string
    {
        return sprintf('matches the pattern %s', PatternLab::export($this->pattern));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function evaluate(mixed $other, string $description = '', bool $returnResult = false): ?bool
    {
        $success = $this->matches($other);

        if ($returnResult) {
            return $success;
        }

        if (!$success) {
            $this->failWithDiff($other, $description);
        }

        return null;
    }

    protected function matches(mixed $other): bool
    {
        if (!\is_string($other)) {
            $this->attempt = new MatchAttempt(
                null,
                [],
                [],
                sprintf('the subject must be a string, %s given', get_debug_type($other)),
            );

            return false;
        }

        $this->attempt = PatternLab::run($this->pattern, $other, $this->expected);

        if (null !== $this->attempt->failure || true !== $this->attempt->matched) {
            return false;
        }

        return $this->attempt->actual === $this->attempt->expected;
    }

    /**
     * The beginning of the failure message: the subject and the constraint.
     */
    protected function failureDescription(mixed $other): string
    {
        return sprintf('%s %s', PatternLab::export($other), $this->toString());
    }

    /**
     * Why the attempt answered nothing, when it did: the library's message,
     * a vocabulary refusal, or the engine's own words.
     */
    protected function additionalFailureDescription(mixed $other): string
    {
        return $this->attempt->failure ?? '';
    }

    /**
     * Throws the failure the way the constraint's own fail() composes it,
     * the capture comparison attached when only the captures differ.
     *
     * @throws ExpectationFailedException
     */
    private function failWithDiff(mixed $other, string $description): void
    {
        $failure = sprintf('Failed asserting that %s.', $this->failureDescription($other));

        $additional = $this->additionalFailureDescription($other);
        if ('' !== $additional) {
            $failure .= "\n".$additional;
        }

        if ('' !== $description) {
            $failure = $description."\n".$failure;
        }

        throw new ExpectationFailedException($failure, $this->comparison());
    }

    /**
     * The expectation against the engine's answer in the expectation's own
     * spelling, when the match answered and only the values differ.
     */
    private function comparison(): ?ComparisonFailure
    {
        $attempt = $this->attempt;
        if (null === $attempt || null !== $attempt->failure || true !== $attempt->matched) {
            return null;
        }

        return new ComparisonFailure(
            $attempt->expected,
            $attempt->actual,
            PatternLab::export($attempt->expected),
            PatternLab::export($attempt->actual),
        );
    }
}
