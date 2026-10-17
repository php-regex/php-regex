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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan\Fixtures\Shadowed;

/*
 * A constant of this namespace: an unqualified PHP_VERSION_ID here is no
 * PHP version, and a guard on it says nothing of the PHP running.
 */
const PHP_VERSION_ID = 1;

final class ShadowedVersionFixture
{
    public function guardedByTheNamespacedConstant(string $s): void
    {
        if (PHP_VERSION_ID < 80500) {
            preg_match('/(?<=a\Kb)c/', $s);
        }
    }
}
