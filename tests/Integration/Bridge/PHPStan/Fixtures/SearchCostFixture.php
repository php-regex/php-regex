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

final class SearchCostFixture
{
    public function verdicts(string $subject): void
    {
        preg_match('/\s+$/', $subject); // one attempt linear, the search quadratic
        preg_match('/^\s+$/', $subject); // anchored: no search cost
        preg_match('/(a+)+$/', $subject); // exponential attempt: regex.redos only
        preg_match('/\s+$/', 'constant subject'); // no attacker-controlled subject
    }
}
