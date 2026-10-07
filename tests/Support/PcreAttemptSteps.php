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

namespace PHPRegex\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The cost of one match attempt, read from the running PCRE2 without a clock.
 *
 * pcre.backtrack_limit is PCRE2's match limit, counted afresh at each start
 * position of a search: the smallest limit that lets one attempt finish is
 * that attempt's step count. The attempt is pinned to an offset with the "A"
 * modifier and run in the interpreter, "(*NO_JIT)".
 *
 * "(*NO_AUTO_POSSESS)" makes the steps visible: PCRE2 turns a loop followed by
 * a disjoint item ("a+b") into a possessive one, which still reads every
 * character of the run but records no backtracking frame, so the counter
 * stays at 2 whatever the run's length (PHP 8.4.26, PCRE2 10.49). Without
 * auto-possession the same characters are read, one frame each.
 *
 * The counter does not see inside an atomic group or a written possessive
 * quantifier either: a bounded count there proves nothing about the scan.
 */
final class PcreAttemptSteps
{
    private const MAX_LIMIT = 10_000_000;

    /**
     * The pattern with its attempt pinned to the offset preg_match() starts
     * at and its steps counted: "(*NO_JIT)(*NO_AUTO_POSSESS)" after the
     * opening delimiter, "A" after the closing one.
     */
    public static function pinned(string $pattern): string
    {
        return self::withVerbs($pattern).'A';
    }

    /**
     * The pattern run by an unanchored search with its steps counted:
     * "(*NO_JIT)(*NO_AUTO_POSSESS)" after the opening delimiter.
     */
    public static function counted(string $pattern): string
    {
        return self::withVerbs($pattern);
    }

    /**
     * The step count of the attempt of $pattern (as given: pinned() it first
     * for one attempt) on $subject from $offset: the smallest
     * pcre.backtrack_limit under which preg_match() finishes. The ini value
     * is restored whatever happens.
     */
    public static function steps(string $pattern, string $subject, int $offset = 0): int
    {
        $previous = ini_get('pcre.backtrack_limit');

        try {
            ini_set('pcre.backtrack_limit', (string) self::MAX_LIMIT);
            Assert::assertTrue(self::finishes($pattern, $subject, $offset), \sprintf('%s does not finish within %d steps from offset %d.', $pattern, self::MAX_LIMIT, $offset));

            $low = 1;
            $high = self::MAX_LIMIT;
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                ini_set('pcre.backtrack_limit', (string) $middle);
                if (!self::finishes($pattern, $subject, $offset)) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }

            return $low;
        } finally {
            ini_set('pcre.backtrack_limit', false === $previous ? '1000000' : $previous);
        }
    }

    /**
     * What preg_match() returns for $pattern on $subject when no attempt may
     * take more than $limit steps: false when one of the attempts the search
     * made needed more. The ini value is restored whatever happens.
     */
    public static function searchUnderLimit(string $pattern, string $subject, int $limit): int|false
    {
        $previous = ini_get('pcre.backtrack_limit');

        try {
            ini_set('pcre.backtrack_limit', (string) $limit);

            return @preg_match($pattern, $subject);
        } finally {
            ini_set('pcre.backtrack_limit', false === $previous ? '1000000' : $previous);
        }
    }

    /**
     * Whether preg_match() finishes under the current pcre.backtrack_limit.
     *
     * @phpstan-impure the answer moves with the ini value
     */
    private static function finishes(string $pattern, string $subject, int $offset): bool
    {
        return false !== @preg_match($pattern, $subject, offset: $offset);
    }

    private static function withVerbs(string $pattern): string
    {
        $delimiter = $pattern[0] ?? '';
        $closing = strrpos($pattern, $delimiter);
        Assert::assertNotFalse($closing, 'No closing delimiter in '.$pattern);
        Assert::assertGreaterThan(0, $closing, 'No closing delimiter in '.$pattern);

        return $delimiter.'(*NO_JIT)(*NO_AUTO_POSSESS)'.substr($pattern, 1);
    }
}
