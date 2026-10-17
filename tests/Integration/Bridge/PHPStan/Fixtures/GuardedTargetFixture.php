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

/**
 * "(?a)" needs PCRE2 10.43: PHP 8.2 (10.40) and 8.3 (10.42) refuse it, 8.4
 * (10.44) accepts it. "\K" in a lookbehind: PHP 8.4 accepts it, 8.5 refuses
 * it. The range judged is PHP 8.2 to 8.5.
 */
final class GuardedTargetFixture
{
    public function inside(string $s): void
    {
        if (PHP_VERSION_ID >= 80400) {
            preg_match('/(?a)\d+/', $s);
        }
        preg_match('/(?a)\d+/', $s);
    }

    public function afterAnEarlyReturn(string $s): void
    {
        if (\PHP_VERSION_ID < 80400) {
            return;
        }
        preg_match('/(?a)\d+/', $s);
    }

    public function inATernary(string $s): int|false
    {
        return \PHP_VERSION_ID >= 80400 ? preg_match('/(?a)\d+/', $s) : false;
    }

    public function underBothBounds(string $s): void
    {
        if (PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80500) {
            preg_match('/(?a)\d+/', $s);
            preg_match('/(?<=a\Kb)c/', $s);
        }
    }

    public function inAnElseifChain(string $s): void
    {
        if (PHP_VERSION_ID < 80300) {
            preg_match('/(?<=a\Kb)c/', $s);
        } elseif (PHP_VERSION_ID < 80400) {
            preg_match('/(?a)\d+/', $s);
        } else {
            preg_match('/(?a)\d+/', $s);
            preg_match('/(?<=a\Kb)c/', $s);
        }
    }

    public function nested(string $s): void
    {
        if (PHP_VERSION_ID >= 80300) {
            if (PHP_VERSION_ID < 80500) {
                preg_match('/(?<=a\Kb)c/', $s);
            }
            preg_match('/(?<=a\Kb)c/', $s);
        }
    }

    public function negated(string $s): void
    {
        if (!(PHP_VERSION_ID >= 80500)) {
            preg_match('/(?<=a\Kb)c/', $s);
        }
    }

    public function eitherOr(string $s): void
    {
        if (PHP_VERSION_ID >= 80400 || '' === $s) {
            preg_match('/(?a)\d+/', $s);
        }
    }

    public function againstAVariable(string $s, int $from): void
    {
        if (PHP_VERSION_ID >= $from) {
            preg_match('/(?a)\d+/', $s);
        }
    }

    public function outsideTheRange(string $s): void
    {
        if (PHP_VERSION_ID >= 90000) {
            preg_match('/(?a)\d+/', $s);
        }
    }

    public function linted(string $s): void
    {
        if (PHP_VERSION_ID >= 80400) {
            preg_match('/[11]/', $s);
            preg_match('/[0-9]+x/', $s);
        }
    }

    public function onPcre1Only(string $s): void
    {
        if (PHP_VERSION_ID < 70300) {
            preg_match('/(?a)\d+/', $s);
        }
    }
}
