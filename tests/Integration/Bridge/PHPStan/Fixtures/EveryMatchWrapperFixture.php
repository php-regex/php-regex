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

use Illuminate\Support\Str;
use Nette\Utils\Strings;
use Spatie\Regex\Regex;

final class EveryMatchWrapperFixture
{
    /**
     * @param array<string, bool> $options
     */
    public function verdicts(string $subject, bool $lazy, array $options): void
    {
        Strings::matchAll($subject, '/(?:a+\d)?/'); // preg_match_all()
        Strings::matchAll($subject, '/(?:a+\d)?/', lazy: false);
        Strings::split($subject, '/(?:a+\d)?/'); // preg_split()
        Strings::replace($subject, '/(?:a+\d)?/'); // preg_replace()
        Strings::replace($subject, '/(?:a+\d)?/', static fn (array $m): string => ''); // preg_replace_callback()
        Regex::matchAll('/(?:a+\d)?/', $subject);
        Regex::replace('/(?:a+\d)?/', '', $subject);
        Str::matchAll('/(?:a+\d)?/', $subject);
        Str::replaceMatches('/(?:a+\d)?/', static fn (array $m): string => '', $subject); // preg_replace_callback()
        Strings::matchAll($subject, '/(?:a+\d)?/', lazy: true); // preg_match() from the end of each match
        Strings::matchAll($subject, '/(?:a+\d)?/', lazy: $lazy); // not known to loop
        Strings::matchAll($subject, '/(?:a+\d)?/', ...$options);
        Strings::split($subject, '/(?:a+\d)?/', limit: 2); // the limit bounds the loop
        Strings::replace($subject, '/(?:a+\d)?/', '', 1);
        Regex::replace('/(?:a+\d)?/', '', $subject, 1);
        Str::replaceMatches('/(?:a+\d)?/', '', $subject, 1);
        Strings::match($subject, '/(?:a+\d)?/'); // the first match ends the search
        Regex::match('/(?:a+\d)?/', $subject);
        Str::isMatch('/(?:a+\d)?/', $subject);
        Strings::matchAll($subject, '/(?:a+\d)??/', lazy: true); // no retry after the empty match it finds first
    }
}
