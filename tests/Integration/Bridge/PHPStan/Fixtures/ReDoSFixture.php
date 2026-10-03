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

final class ReDoSFixture
{
    public function testReDoS(string $subject): void
    {
        preg_match('/(a+)+$/', $subject); // proven exponential: the tip carries the attack and the links
    }
}
