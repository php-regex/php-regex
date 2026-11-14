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

namespace PHPRegex\Parser\Text;

/**
 * The names a near miss most likely meant, for a "Did you mean": a
 * mistyped command, configuration key or group name.
 *
 * @internal
 */
final class NearMiss
{
    /**
     * At most this many single-character edits away.
     */
    private const DISTANCE = 2;

    /**
     * The candidate the name most likely meant: the same letters in another
     * case or without underscores ("min_savings" for "minSavings"), else
     * the nearest within two edits, the first of equals; null when none is
     * that near.
     *
     * @param iterable<string> $candidates
     */
    public static function closest(string $name, iterable $candidates): ?string
    {
        $folded = self::fold($name);
        $nearest = null;
        $best = self::DISTANCE + 1;
        foreach ($candidates as $candidate) {
            if (self::fold($candidate) === $folded) {
                return $candidate;
            }

            $distance = levenshtein($name, $candidate);
            if ($distance < $best) {
                $nearest = $candidate;
                $best = $distance;
            }
        }

        return $nearest;
    }

    /**
     * Every candidate within two edits of the name, in their order.
     *
     * @param iterable<string> $candidates
     *
     * @return list<string>
     */
    public static function within(string $name, iterable $candidates): array
    {
        $near = [];
        foreach ($candidates as $candidate) {
            if (levenshtein($name, $candidate) <= self::DISTANCE) {
                $near[] = $candidate;
            }
        }

        return $near;
    }

    private static function fold(string $name): string
    {
        return Ascii::lower(str_replace('_', '', $name));
    }
}
