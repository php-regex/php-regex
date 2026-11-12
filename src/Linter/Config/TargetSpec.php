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
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\ParserOptions;

/**
 * A PHP version as a setting names it, in one of three forms: a single
 * version ("8.3", 80300, "runtime"), a Composer constraint (">=8.1",
 * "^8.1 <8.7"), or a {"min": …, "max": …} object.
 *
 * A single version is exact, and is read by ParserOptions::phpVersionIdOf()
 * as it always was. A constraint is read as require.php is, its versions
 * x.y, x.y.z or x.y.* only. The object's bounds are PHP_VERSION_IDs or
 * "x.y[.z]" strings, max inclusive: a PHP_VERSION_ID or a three-part string
 * is that release, a two-part string the whole series, as the upper version
 * of a hyphen range.
 *
 * @internal
 */
final class TargetSpec
{
    /**
     * What a setting of a PHP version takes, as an error message names it.
     */
    public const EXPECTED = 'a version like "8.3", a PHP_VERSION_ID like 80300, "runtime", a Composer constraint like ">=8.1", or {"min": "8.1", "max": "8.6"}';

    /**
     * The refusal of a string that names neither a version nor a range: the
     * option is named by its key, which an entry point replaces with its own
     * name, as it does for ParserOptions::PHP_VERSION_EXPECTED.
     */
    public const RANGE_EXPECTED = '"php_version" must be a version string like "8.2", a PHP_VERSION_ID integer, "runtime", or a Composer constraint like ">=8.1" that names a lowest version.';

    /**
     * The refusal of a {"min", "max"} object that names no range.
     */
    public const OBJECT_EXPECTED = '"php_version" must be {"min": …, "max": …}, each bound a version like "8.1" or a PHP_VERSION_ID like 80100, min not above max.';

    /**
     * A version bound of a major 7 to 9: "8.1" or "8.1.10".
     */
    private const VERSION_BOUND = '/^([7-9])\.([0-9]{1,2})(?:\.([0-9]{1,2}))?$/D';

    private function __construct() {}

    /**
     * The PHP versions a constraint allows, as PHP_VERSION_ID intervals as
     * ComposerConstraint::intervals() gives them; null when the value names
     * a single version, left for ParserOptions::phpVersionIdOf() to read.
     *
     * @throws InvalidRegexOptionException when the value names no version and no range: a constraint no PHP
     *                                     satisfies, ">=8.4 <8.2", or one naming PHP 10 or later, is refused
     *
     * @return non-empty-list<array{int, int|null}>|null
     */
    public static function intervals(string|int $value): ?array
    {
        if (\is_int($value)) {
            return null;
        }

        // What a single version is written as, read or refused as it was
        // before a range was taken: "8.3", "8.3.4", 80300, "runtime".
        if (1 === LibraryPcre::match('/^(?:\d++(?:\.\d++){0,2}|runtime)$/iD', trim($value, " \t\n\r\0\x0B"))) {
            return null;
        }

        return ComposerConstraint::intervals($value, strict: true) ?? throw new InvalidRegexOptionException(self::RANGE_EXPECTED);
    }

    /**
     * The interval of a {"min", "max"} object: from min, up to the first
     * version past max. The object is checked for its keys where it is
     * read: the schema of regex.json, the settings of an entry point.
     *
     * @throws InvalidRegexOptionException when a bound names no version, or min is above max
     *
     * @return array{int, int}
     */
    public static function minMax(string|int $min, string|int $max): array
    {
        [$from] = self::bound($min);
        [$last, $precision] = self::bound($max);
        $below = ComposerConstraint::nextAt($last, $precision);
        if ($from >= $below) {
            throw new InvalidRegexOptionException(self::OBJECT_EXPECTED);
        }

        return [$from, $below];
    }

    /**
     * Whether a value is a bound of a {"min", "max"} object: a
     * PHP_VERSION_ID of PHP 7.0 up to 9.99.99, or a version "x.y" or "x.y.z"
     * of a major 7 to 9, as the schema of regex.json takes it, never "08.1"
     * nor PHP 10. Every entry point that checks a bound before reading the
     * range asks it here; minMax() reads what it accepts.
     */
    public static function isBound(int|float|string|bool|null $bound): bool
    {
        return \is_int($bound)
            ? $bound >= ParserOptions::OLDEST_PHP_VERSION_ID && $bound <= ParserOptions::LATEST_PHP_VERSION_ID
            : \is_string($bound) && 1 === LibraryPcre::match(self::VERSION_BOUND, $bound);
    }

    /**
     * A bound as a PHP_VERSION_ID, and the number of parts it was written
     * with: 3 for a PHP_VERSION_ID, which names one release.
     *
     * @throws InvalidRegexOptionException when the bound names no version of PHP 7.0 or later
     *
     * @return array{int, int}
     */
    private static function bound(string|int $bound): array
    {
        if (!self::isBound($bound)) {
            throw new InvalidRegexOptionException(self::OBJECT_EXPECTED);
        }

        if (\is_int($bound)) {
            return [$bound, 3];
        }

        LibraryPcre::match(self::VERSION_BOUND, $bound, $parts);

        return [(int) ($parts[1] ?? 0) * 10000 + (int) ($parts[2] ?? 0) * 100 + (int) ($parts[3] ?? 0), isset($parts[3]) ? 3 : 2];
    }
}
