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

use Composer\Pcre\Preg;
use Composer\Pcre\Regex as R;
use Illuminate\Support\Str;
use Nette\Utils\Strings;
use Spatie\Regex\Regex;

final class WrapperCallFixture
{
    public function calls(string $input): void
    {
        preg_match('/(a+)+$/', $input); // line 26
        Preg::match('/(b+)+$/', $input); // line 27
        R::isMatch('/(c+)+$/', $input); // line 28
        Preg::replaceCallbackArray(['/(d+)+$/' => static fn (array $m): string => ''], $input); // line 29
        Strings::match($input, '/(e+)+$/'); // line 30
        Strings::replace($input, ['/(f+)+$/' => 'x']); // line 31
        Strings::replace($input, ['/(m+)+$/'], static fn (array $m): string => ''); // line 32
        Regex::match('/(g+)+$/', $input); // line 33
        Str::isMatch('/(h+)+$/', $input); // line 34
        Preg::match(subject: $input, pattern: '/(i+)+$/'); // line 35
        \Safe\preg_match('/(j+)+$/', $input); // line 36
        Unrelated::match('/(k+)+$/', $input);
        Strings::match('/(l+)+$/', $input);
        $class = Preg::class;
        $class::match('/(n+)+$/', $input);
        Preg::match(...['/(o+)+$/', $input]);
    }
}
