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

final class EveryMatchSearchCostFixture
{
    public function verdicts(string $subject, int $limit, bool $flag): void
    {
        preg_match_all('/(?:a+\d)?/', $subject); // every match: PHP retries each empty one
        preg_replace('/(?:a+\d)?/', '', $subject);
        preg_filter('/(?:a+\d)?/', '', $subject);
        preg_replace_callback('/(?:a+\d)?/', static fn (array $matches): string => '', $subject);
        preg_replace_callback_array(['/(?:a+\d)?/' => static fn (array $matches): string => ''], $subject);
        preg_split('/(?:a+\d)?/', $subject);
        Preg::matchAll('/(?:a+\d)?/', $subject);
        preg_replace('/(?:a+\d)?/', '', $subject, $limit); // a limit no type bounds
        preg_match('/(?:a+\d)?/', $subject); // the first match ends the search
        preg_grep('/(?:a+\d)?/', [$subject]);
        preg_replace('/(?:a+\d)?/', '', $subject, 1); // the limit bounds the loop
        preg_split('/(?:a+\d)?/', $subject, 2);
        preg_match_all('/(?:a+\d)?/', 'constant subject'); // no attacker-controlled subject
        preg_replace('/(?:a+\d)?/', '', $subject, $flag ? 1 : -1); // one of the limits bounds nothing
        strlen($subject); // no pattern
    }
}
