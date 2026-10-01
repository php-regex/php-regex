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

final class ProvenReDoSFixture
{
    public function verdicts(string $subject): void
    {
        preg_match('/(a+)+$/', $subject); // proven exponential
        preg_match('/a*a*a*$/', $subject); // proven polynomial, degree 3
        preg_match('/(a+)+\1$/', $subject); // out of the model: heuristic
        preg_match('/(?>a+)+$/', $subject); // proven safe: no error
    }
}
