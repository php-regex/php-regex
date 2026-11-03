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

/**
 * Asks the running engine whether a pattern compiles, through a handler of
 * its own. The warning preg_match() raises for a pattern it cannot compile
 * is read where it is raised: once a test runner's handler has taken it,
 * error_get_last() holds nothing (PHPUnit 10.5), and a suppressed call
 * reads as a compiled pattern.
 */
final class EngineProbe
{
    /**
     * The engine's warning for a pattern it does not compile, with PHP's
     * "preg_match(): " frame; null when the pattern compiles.
     */
    public static function warning(string $pattern): ?string
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning ??= $message;

            return true;
        });

        try {
            $result = preg_match($pattern, '');
        } finally {
            restore_error_handler();
        }

        if (false !== $result) {
            return null;
        }

        return $warning ?? preg_last_error_msg();
    }

    public static function compiles(string $pattern): bool
    {
        return null === self::warning($pattern);
    }
}
