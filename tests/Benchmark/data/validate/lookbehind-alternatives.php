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
    'pattern' => '/(?<='.implode('|', array_map(static fn (int $i): string => str_repeat('a', $i), range(1, 60))).')x/',
    'origin' => 'synthetic',
    'note' => 'A lookbehind of 60 alternatives of different lengths: each length is checked.',
];
