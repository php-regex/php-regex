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

final class TargetVersionFixture
{
    public function asciiOption(): void
    {
        // "(?aD)" arrived in PCRE2 10.43, which PHP bundles from 8.4; before,
        // pcre2test refuses it at offset 2.
        preg_match('/(?aD)x/', 'x');
    }
}
