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

namespace PHPRegex\Parser\Internal;

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
    private const LONG_MAX_DIGITS = '9223372036854775807';

    public static function isOn(string $setting): bool
    {
        if (\in_array(strtolower($setting), ['true', 'yes', 'on'], true)) {
            return true;
        }

        return self::hasLowBitsSet($setting, 0xFFFFFFFF);
    }

    /**
     * Whether PHP prints its diagnostics under display_errors set to this
     * value (php_get_display_errors_mode()): "on", "yes", "true", "stderr"
     * or "stdout" in any case, else a C long of it cast to an unsigned
     * char, so "256" is off.
     */
    public static function displaysErrors(string $setting): bool
    {
        if (\in_array(strtolower($setting), ['true', 'yes', 'on', 'stderr', 'stdout'], true)) {
            return true;
        }

        return self::hasLowBitsSet($setting, 0xFF);
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
        if ($width > \strlen(self::LONG_MAX_DIGITS) || ($width === \strlen(self::LONG_MAX_DIGITS) && strcmp($digits, self::LONG_MAX_DIGITS) > 0)) {
            // strtol() saturates: LONG_MAX keeps its low bits set, LONG_MIN
            // none (a magnitude of 2^63 is LONG_MIN itself).
            return '-' !== $sign;
        }

        // The sign does not change whether the low bits are zero.
        return 0 !== ((int) $digits & $mask);
    }
}
