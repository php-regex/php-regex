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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan\Fixtures\RegexPatternArgumentChecks;

use PHPRegex\Parser\Attribute\RegexPattern;

final class Str
{
    public function matches(string $subject, #[RegexPattern] string $regex): bool
    {
        return 1 === preg_match($regex, $subject);
    }
}

final class Calls
{
    public function calls(Str $str, string $subject): void
    {
        $str->matches($subject, '/(a+)+$/'); // ReDoS, critical; lint
        $str->matches($subject, '/(?:a)/'); // lint: redundant non-capturing group
        // "(?aD)" arrived in PCRE2 10.43; PHP 8.2 bundles 10.40, which refuses it at offset 2.
        $str->matches($subject, '/(?aD)x/');
    }
}
