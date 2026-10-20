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
     * marker narrows a project range, never the range itself.
     *
     * @return non-empty-list<array{int, int|null}>|null
     */
    public static function intervals(string $constraint, bool $openBelow = false): ?array
    {
        $intervals = [];
        foreach (LibraryPcre::split('/\s*\|\|?\s*/', trim($constraint, " \t\n\r\0\x0B")) ?: [] as $branch) {
            $interval = self::branchInterval($branch, $openBelow);
            if (null === $interval) {
                return null;
            }
            $intervals[] = $interval;
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
     *
     * @return array{int, int|null}|null
     */
    private static function branchInterval(string $branch, bool $openBelow = false): ?array
    {
        // A partial upper version of a hyphen range allows its whole
        // branch: "8.2 - 8.4" is below 8.5; a full one is itself the last
        // allowed, "8.2 - 8.4.25" is below 8.4.26.
        if (1 === LibraryPcre::match('/^(\S+)\s+-\s+(\S+)$/', $branch, $range)) {
            $lowest = self::version($range[1]);
            $upper = self::version($range[2]);
            if (null === $lowest || null === $upper) {
                return null;
            }

            return [$lowest[0], self::nextAt($upper[0], $upper[1])];
        }

        // An operator may be followed by spaces: ">= 8.3".
        $branch = (string) LibraryPcre::replace('/(>=|<=|!=|==|[<>=^~])\s+/', '$1', $branch);

        $lowest = null;
        $below = null;
        foreach (LibraryPcre::split('/[\s,]+/', trim($branch, " \t\n\r\0\x0B")) ?: [] as $term) {
            if (1 !== LibraryPcre::match('/^(>=|<=|!=|==|[<>=^~])?(.+)$/', $term, $parts)) {
                return null;
            }
            $version = self::version($parts[2]);
            if (null === $version) {
                return null;
            }
            [$id, $precision, $wildcard] = $version;

            [$from, $until] = match ($parts[1]) {
                '!=' => [null, null],
                '<' => [null, $id],
                '<=' => [null, $id + 1],
                '>=', '>' => [$id, null],
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
        }

        if (null === $lowest && $openBelow && null !== $below) {
            $lowest = 0;
        }

        return null === $lowest ? null : [$lowest, $below];
    }

    /**
     * "8.3", "8.3.4", "8.3.*", "v8.3" as a PHP_VERSION_ID, the number of
     * parts given before any wildcard, and whether a wildcard ends it; "*"
     * and branch names as null.
     *
     * @return array{int, int, bool}|null
     */
    private static function version(string $version): ?array
    {
        if (1 !== LibraryPcre::match('/^v?(\d+)(?:\.(\d+|\*))?(?:\.(\d+|\*))?(?:\.\d+)?$/', $version, $parts)) {
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
