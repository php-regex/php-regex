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

final class DefaultThresholdFixture
{
    public function patterns(string $subject): void
    {
        preg_match('/(a+)+$/', $subject); // exponential, critical
        preg_match('/\d*\d*\d*$/', $subject); // polynomial of degree 3, high
        preg_match('/^\d*\d*$/', $subject); // polynomial of degree 2, medium
    }
}
