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

namespace PHPRegex\Parser\Engine;

use PHPRegex\Parser\Text\Ascii;

/**
 * A boolean ini setting read the way PHP reads it (zend_ini_parse_bool()):
 * the whole value "true", "yes" or "on" in any case, else a non-zero C
 * atoi() of it.
 *
 * atoi() is strtol() cast to a C int: leading white space, one optional
 * sign, decimal digits; past a 64-bit long the value saturates, then it
 * keeps its low 32 bits. So "4294967296" is off and "4294967297" on, as
 * the engine itself reads pcre.jit on a 64-bit Unix build.
 *
 * @internal
 */
final class IniFlag
{
    /**
     * The bits of a C int, 32 wide, in a PHP int: the low 32 bits on a
     * 64-bit PHP, every bit on a 32-bit PHP (php-wasm), where the literal
     * 0xFFFFFFFF would be a float. Reading a long cast to an int keeps
     * these bits.
     */
    public const INT_MASK = \PHP_INT_SIZE > 4 ? 0xFFFFFFFF : -1;

    public static function isOn(string $setting): bool
    {
        if (\in_array(Ascii::lower($setting), ['true', 'yes', 'on'], true)) {
            return true;
        }

        return self::hasLowBitsSet($setting, self::INT_MASK);
    }

    /**
     * Whether PHP prints its diagnostics under display_errors set to this
     * value (php_get_display_errors_mode()): "on", "yes", "true", "stderr"
     * or "stdout" in any case, else a C long of it cast to an unsigned
     * char, so "256" is off.
     */
    public static function displaysErrors(string $setting): bool
    {
        if (\in_array(Ascii::lower($setting), ['true', 'yes', 'on', 'stderr', 'stdout'], true)) {
            return true;
        }

        return self::hasLowBitsSet($setting, 0xFF);
    }

    /**
     * A PHP int read as the C unsigned 32-bit int the engine receives for a
     * limit: the low 32 bits on a 64-bit PHP, so -1 is 4294967295 and 2^32
     * is 0. On a 32-bit PHP a negative value stands past PHP_INT_MAX, which
     * no PHP int holds: PHP_INT_MAX is answered, and compares the same way
     * against every limit the library knows.
     */
    public static function unsignedInt(int $value): int
    {
        if (\PHP_INT_SIZE > 4) {
            return $value & self::INT_MASK;
        }

        // Reached on a 32-bit PHP only, where the test above runs it.
        return $value < 0 ? \PHP_INT_MAX : $value;
    }

    /**
     * Whether the C long strtol() reads from the value has a bit of the mask
     * set, the mask standing for the C type the long is cast to.
     */
    private static function hasLowBitsSet(string $setting, int $mask): bool
    {
        $rest = substr($setting, strspn($setting, " \t\n\r\v\f"));
        $sign = $rest[0] ?? '';
        if ('+' === $sign || '-' === $sign) {
            $rest = substr($rest, 1);
        }

        $digits = ltrim(substr($rest, 0, strspn($rest, '0123456789')), '0');
        $width = \strlen($digits);
        // A C long is as wide as a PHP int: 63 bits and a sign on a 64-bit
        // PHP, 31 on a 32-bit one.
        $longMax = (string) \PHP_INT_MAX;
        if ($width > \strlen($longMax) || ($width === \strlen($longMax) && strcmp($digits, $longMax) > 0)) {
            // strtol() saturates: LONG_MAX keeps its low bits set; LONG_MIN
            // (a magnitude of 2^63, or 2^31, is LONG_MIN itself) keeps the
            // bits the mask selects, none on a 64-bit PHP, its sign bit on a
            // 32-bit one.
            return '-' !== $sign || 0 !== (\PHP_INT_MIN & $mask);
        }

        // The sign does not change whether the low bits are zero.
        return 0 !== ((int) $digits & $mask);
    }
}
