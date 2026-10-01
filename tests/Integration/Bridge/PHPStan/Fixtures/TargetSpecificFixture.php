<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Integration\Bridge\PHPStan\Fixtures;

final class TargetSpecificFixture
{
    public function patterns(): void
    {
        // "(*scs:...)" arrived in PCRE2 10.45; 10.40 does not know the verb.
        preg_match('/(a)(*scs:(1)a)/', 'aa');
        // Refused by every PCRE2: PHPStan core reports it, this rule stays silent.
        preg_match('/a{2,1}/', 'aa');
        // Accepted by every PCRE2 and by the target.
        preg_match('/[0-9]+/', '42');
    }
}
