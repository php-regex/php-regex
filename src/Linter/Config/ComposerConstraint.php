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

namespace PHPRegex\Linter\Config;

use PHPRegex\Parser\Engine\LibraryPcre;

/**
 * The PHP versions a Composer constraint allows, as PHP_VERSION_ID
 * intervals: what composer.json's require.php, a guard and a
 * "@regex-target" marker are read as.
 *
 * @internal
 */
final class ComposerConstraint
{
    private function __construct() {}

    /**
     * The versions a Composer constraint allows, as PHP_VERSION_ID
     * intervals: the lowest allowed, and the first above it no longer
     * allowed (null when none is). Null when the constraint is not one of
     * the forms read: >=, >, <, <=, ^, ~, a bare version, X.Y.*, AND (comma
     * or space), OR (| or ||), a hyphen range. A branch without a lower
     * bound makes the whole constraint unreadable, unless $openBelow: then
     * it starts at the first version, as a guard or a "@regex-target"
     * marker narrows a project range, never the range itself. A $strict
     * constraint, one named where the library takes a PHP version, holds
     * no version but x.y, x.y.z and x.y.*, a major of one digit, up to PHP
     * 9.99.99 (ParserOptions::LATEST_PHP_VERSION_ID), and each part after
     * it of one or two digits: a PHP_VERSION_ID, "runtime", a bare major, a
     * branch name, PHP 10, a doubled separator or a wildcard after an
     * operator or in a hyphen range in it makes it unreadable, where
     * require.php's reading takes "80100" as a major. A
     * branch of a $strict constraint that allows no version, ">=8.4 <8.2",
     * is dropped; a $strict constraint whose every branch is dropped is
     * unreadable. "!=" leaves its version out of a $strict constraint, as
     * composer/semver does, and bounds nothing in require.php's reading.
     *
     * @return non-empty-list<array{int, int|null}>|null
     */
    public static function intervals(string $constraint, bool $openBelow = false, bool $strict = false): ?array
    {
        $intervals = [];
        foreach (LibraryPcre::split('/\s*\|\|?\s*/', trim($constraint, " \t\n\r\0\x0B")) ?: [] as $branch) {
            $pieces = self::branchIntervals($branch, $openBelow, $strict);
            if (null === $pieces) {
                return null;
            }
            foreach ($pieces as $interval) {
                if ($strict && null !== $interval[1] && $interval[1] <= $interval[0]) {
                    continue;
                }
                $intervals[] = $interval;
            }
        }

        return [] === $intervals ? null : $intervals;
    }

    /**
     * The lowest version a Composer constraint allows, as a PHP_VERSION_ID,
     * or null when the constraint is not one of the forms read.
     */
    public static function floor(string $constraint): ?int
    {
        $intervals = self::intervals($constraint);

        return null === $intervals ? null : min(array_column($intervals, 0));
    }

    /**
     * The lowest version of $from or later the intervals allow, or null
     * when they allow none.
     *
     * @param non-empty-list<array{int, int|null}> $intervals
     */
    public static function lowestFrom(array $intervals, int $from): ?int
    {
        $lowest = null;
        foreach ($intervals as [$first, $below]) {
            $candidate = max($first, $from);
            if ((null === $below || $candidate < $below) && (null === $lowest || $candidate < $lowest)) {
                $lowest = $candidate;
            }
        }

        return $lowest;
    }

    /**
     * The first version past every version sharing the first $precision
     * parts of $id: 8.2.x past 8.2 is 8.3, past 8 is 9.0.
     */
    public static function nextAt(int $id, int $precision): int
    {
        return match ($precision) {
            1 => (intdiv($id, 10000) + 1) * 10000,
            2 => (intdiv($id, 100) + 1) * 100,
            default => $id + 1,
        };
    }

    /**
     * @param bool $openBelow whether a branch without a lower bound starts at the first version, where
     *                        it makes the constraint unreadable
     * @param bool $strict    whether a version must be x.y, x.y.z or x.y.*
     *
     * @return list<array{int, int|null}>|null one interval, or the pieces a $strict "!=" leaves of it
     */
    private static function branchIntervals(string $branch, bool $openBelow, bool $strict): ?array
    {
        // A partial upper version of a hyphen range allows its whole
        // branch: "8.2 - 8.4" is below 8.5; a full one is itself the last
        // allowed, "8.2 - 8.4.25" is below 8.4.26.
        // A $strict constraint is spaced with spaces and tabs only, as the
        // schema of regex.json reads it; and an operator is never split,
        // "> =8.1" is no ">=8.1".
        $hyphen = $strict ? '/^([^ \t]+)[ \t]+-[ \t]+([^ \t]+)$/D' : '/^(\S+)\s+-\s+(\S+)$/';
        if (1 === LibraryPcre::match($hyphen, $branch, $range)) {
            $lowest = self::version($range[1], $strict);
            $upper = self::version($range[2], $strict);
            // composer/semver takes no wildcard in a hyphen range.
            if (null === $lowest || null === $upper || ($strict && ($lowest[2] || $upper[2]))) {
                return null;
            }

            return [[$lowest[0], self::nextAt($upper[0], $upper[1])]];
        }

        // An operator may be followed by spaces: ">= 8.3".
        $branch = (string) LibraryPcre::replace($strict ? '/(>=|<=|!=|==|[<>=^~])[ \t]+(?=[0-9v])/' : '/(>=|<=|!=|==|[<>=^~])\s+/', '$1', $branch);

        $lowest = null;
        $below = null;
        $excluded = [];
        // A $strict constraint keeps the empty term of a doubled separator,
        // ">=8.1,,<8.3", to refuse it.
        $separator = $strict ? '/[ \t]*,[ \t]*|[ \t]+/' : '/[\s,]+/';
        foreach (LibraryPcre::split($separator, trim($branch, " \t\n\r\0\x0B")) ?: [] as $term) {
            if (1 !== LibraryPcre::match('/^(>=|<=|!=|==|[<>=^~])?(.+)$/', $term, $parts)) {
                return null;
            }
            $version = self::version($parts[2], $strict);
            if (null === $version) {
                return null;
            }
            [$id, $precision, $wildcard] = $version;
            // composer/semver takes a wildcard alone, never after an
            // operator: ">=8.1.*" is no constraint.
            if ($strict && $wildcard && '' !== $parts[1]) {
                return null;
            }

            // ">" is strict, as composer/semver's lower bound: ">8.5.9"
            // starts at 8.5.10, a version a rule may change at.
            [$from, $until] = match ($parts[1]) {
                '!=' => [null, null],
                '<' => [null, $id],
                '<=' => [null, $id + 1],
                '>=' => [$id, null],
                '>' => [$id + 1, null],
                '^' => [$id, self::nextAt($id, 1)],
                '~' => [$id, self::nextAt($id, max(1, $precision - 1))],
                default => [$id, $wildcard ? self::nextAt($id, $precision) : $id + 1],
            };
            if (null !== $from) {
                $lowest = null === $lowest ? $from : max($lowest, $from);
            }
            if (null !== $until) {
                $below = null === $below ? $until : min($below, $until);
            }
            if ($strict && '!=' === $parts[1]) {
                $excluded[] = $id;
            }
        }

        if (null === $lowest && $openBelow && null !== $below) {
            $lowest = 0;
        }
        if (null === $lowest) {
            return null;
        }

        if ([] === $excluded) {
            return [[$lowest, $below]];
        }

        // The versions left out, in order, cut the interval in one pass:
        // each piece runs up to the next one, and none is left empty.
        $excluded = array_unique($excluded);
        sort($excluded);
        $pieces = [];
        $from = $lowest;
        foreach ($excluded as $out) {
            if (null !== $below && $out >= $below) {
                break;
            }
            if ($out > $from) {
                $pieces[] = [$from, $out];
            }
            $from = max($from, $out + 1);
        }
        if (null === $below || $from < $below) {
            $pieces[] = [$from, $below];
        }

        return $pieces;
    }

    /**
     * "8.3", "8.3.4", "8.3.*", "v8.3" as a PHP_VERSION_ID, the number of
     * parts given before any wildcard, and whether a wildcard ends it; "*"
     * and branch names as null. $strict reads "8.3", "8.3.4" and "8.3.*"
     * only, of a major below 10.
     *
     * @return array{int, int, bool}|null
     */
    private static function version(string $version, bool $strict): ?array
    {
        // A strict major of one digit bounds the version at PHP 9.99.99
        // before any product is taken: "99999999999999999999.1" is no int.
        $shape = $strict ? '/^(\d)\.(\d{1,2})(?:\.(\d{1,2}|\*))?$/D' : '/^v?(\d+)(?:\.(\d+|\*))?(?:\.(\d+|\*))?(?:\.\d+)?$/';
        if (1 !== LibraryPcre::match($shape, $version, $parts)) {
            return null;
        }

        $minor = isset($parts[2]) && '*' !== $parts[2] ? (int) $parts[2] : 0;
        $patch = isset($parts[3]) && '*' !== $parts[3] ? (int) $parts[3] : 0;

        $precision = 1;
        $wildcard = false;
        foreach ([2, 3] as $index) {
            if (!isset($parts[$index]) || '' === $parts[$index]) {
                break;
            }
            if ('*' === $parts[$index]) {
                $wildcard = true;

                break;
            }
            $precision++;
        }

        return [(int) $parts[1] * 10000 + $minor * 100 + $patch, $precision, $wildcard];
    }
}
