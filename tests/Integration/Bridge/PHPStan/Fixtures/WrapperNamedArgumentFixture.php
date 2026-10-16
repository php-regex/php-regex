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

use Nette\Utils\Strings;

final class WrapperNamedArgumentFixture
{
    public function calls(string $input): void
    {
        Strings::match($input, pattern: '/(a+)+$/'); // line 22
        Strings::match($input, regex: '/(b+)+$/'); // line 23: no such parameter
        Strings::match($input, patterns: '/(c+)+$/'); // line 24: no such parameter
        Strings::match($input, PATTERN: '/(d+)+$/'); // line 25: names are case-sensitive
    }
}
