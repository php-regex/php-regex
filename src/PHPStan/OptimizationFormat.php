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

namespace PHPRegex\PHPStan;

/**
 * The format checks a rewrite of the optimizer passes before the PHPStan
 * rule offers it: a delimited pattern whose body holds at least two bytes,
 * and the "\n" escape of the original kept.
 *
 * @internal
 */
final class OptimizationFormat
{
    /**
     * Whether the rewrite of the original is fit to be offered.
     */
    public static function isSafe(string $original, string $optimized): bool
    {
        $delimiter = $optimized[0] ?? '';
        if ('' === $delimiter) {
            return false;
        }

        $lastDelimiterPos = strrpos($optimized, $delimiter);
        if (false === $lastDelimiterPos || 0 === $lastDelimiterPos) {
            return false;
        }

        $patternPart = substr($optimized, 1, $lastDelimiterPos - 1);

        $originalDelimiter = $original[0] ?? '';
        $originalPatternPart = '';
        if ('' !== $originalDelimiter) {
            $originalLastPos = strrpos($original, $originalDelimiter);
            if (false !== $originalLastPos) {
                $originalPatternPart = substr($original, 1, $originalLastPos - 1);
            }
        }

        if ('' === $patternPart) {
            return false;
        }

        if (\strlen($patternPart) < 2) {
            return false;
        }

        if (str_contains($originalPatternPart, '\n') && !str_contains($patternPart, '\n')) {
            return false;
        }

        return true;
    }
}
