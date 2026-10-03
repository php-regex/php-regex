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

final class ConstantSubjectFixture
{
    /**
     * @param list<string> $inputs
     */
    public function subjects(string $input, array $inputs, bool $flag): void
    {
        preg_match('/(a+)+$/', 'aaaa');
        preg_match('/(a+)+$/', $input); // reported
        $fixed = 'aaaab';
        preg_match('/(a+)+$/', $fixed);
        preg_match('/(a+)+$/', $flag ? 'aaaa' : 'bbbb');
        preg_match('/(a+)+$/', 'a'.$input); // reported
        preg_match_all('/(a+)+$/', 'aaaa');
        preg_replace('/(a+)+$/', 'x', 'aaaa');
        preg_replace('/(a+)+$/', 'x', ['aaaa', 'bbbb']);
        preg_replace('/(a+)+$/', 'x', $inputs); // reported
        preg_replace_callback('/(a+)+$/', static fn (array $m): string => '', 'aaaa');
        preg_split('/(a+)+$/', 'aaaa');
        preg_grep('/(a+)+$/', ['aaaa']);
        preg_filter('/(a+)+$/', 'x', 'aaaa');
        preg_replace_callback_array(['/(a+)+$/' => static fn (array $m): string => ''], 'aaaa');
        preg_match(subject: 'aaaa', pattern: '/(a+)+$/');
        preg_match(subject: $input, pattern: '/(a+)+$/'); // reported
        preg_match('/(a+)+$/', ...$inputs); // reported
    }
}
