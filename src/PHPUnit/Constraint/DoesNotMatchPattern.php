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

/**
 * Whether one match attempt of the pattern against the subject ran and
 * answered no match.
 *
 * The attempt runs on the same no-JIT guarded spelling MatchesPattern uses.
 * A pattern that does not parse, a pattern the engine refuses and a subject
 * the engine cannot read each fail carrying the library's or the engine's
 * own words: no attempt ran, so nothing says the pattern misses. Negating
 * MatchesPattern with LogicalNot reads any of them as "not a match", which
 * is why this constraint exists beside it.
 */
final class DoesNotMatchPattern extends Constraint
{
    private ?MatchAttempt $attempt = null;

    /**
     * @param string $pattern the pattern as the caller wrote it, also the one the failure shows
     */
    public function __construct(private readonly string $pattern) {}

    /**
     * Returns a string representation of the constraint.
     */
    public function toString(): string
    {
        return sprintf('does not match the pattern %s', PatternLab::export($this->pattern));
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

        $this->attempt = PatternLab::run($this->pattern, $other, []);

        return false === $this->attempt->matched;
    }

    /**
     * The beginning of the failure message: the subject and the constraint.
     */
    protected function failureDescription(mixed $other): string
    {
        return sprintf('%s %s', PatternLab::export($other), $this->toString());
    }

    /**
     * Why the attempt answered nothing, when it did: the library's message
     * or the engine's own words.
     */
    protected function additionalFailureDescription(mixed $other): string
    {
        return $this->attempt->failure ?? '';
    }
}
