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

return [
    'pattern' => '/'.str_repeat('(a)', 99).implode('', array_map(static fn (int $i): string => '\\g{'.$i.'}', range(1, 99))).'/',
    'origin' => 'synthetic',
    'note' => '99 groups each referenced back: the reference table at its fullest.',
];
