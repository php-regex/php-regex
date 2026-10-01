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

final class RedosEdgeCasesFixture
{
    public function verdicts(string $subject): void
    {
        preg_match('/^(?:(?:a{16}){16}){16}(a+)+$/', $subject); // over the budget, critical by the heuristics
        preg_match('/(\x1b+)+$/', $subject); // proven exponential, escape control character
        preg_match('/(é+)+$/u', $subject); // proven exponential, non-ASCII code point
        preg_match('/<error>(a+)+$/', $subject); // proven exponential, console markup in the prefix
        preg_match('/\\\\<(a+)+$/', $subject); // proven exponential, a backslash then "<" in the prefix
    }
}
