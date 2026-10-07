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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\Types;

final class ClassConstantPattern
{
    private const PATTERN = '/(a)(b)?/';

    public function read(string $s): void
    {
        if (preg_match(self::PATTERN, $s, $m)) {
            /**
             * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
             * @psalm-trace $m
             */
        }
    }
}
