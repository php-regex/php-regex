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
    'pattern' => '/^(?:'.implode('|', array_map(static fn (int $i): string => 'word'.$i, range(1, 3000))).')$/',
    'origin' => 'synthetic',
    'note' => '3,000 alternatives in one group: the alternation node at its widest.',
];
