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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan\Fixtures;

final class NamedRangeGuardFixture
{
    public function keepInLookbehind(string $subject): void
    {
        // PHP 8.5 refuses "\K" in a lookbehind: the guard keeps the call
        // off it.
        if (\PHP_VERSION_ID < 80500) {
            preg_match('/(?<=a\Kb)c/', $subject);
        }

        preg_match('/(?<=a\Kb)c/', $subject);
    }

    public function variableLengthLookbehind(string $subject): void
    {
        // PCRE2 10.43, bundled from PHP 8.4, compiles a lookbehind of
        // variable length: the guard keeps the call off the PHPs before.
        if (\PHP_VERSION_ID >= 80400) {
            preg_match('/(?<=ab?)c/', $subject);
        }

        preg_match('/(?<=ab?)c/', $subject);
    }
}
