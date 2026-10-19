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
    'pattern' => '/^'.implode('-', array_fill(0, 12, '(\d+)')).'x+(x*)y$/',
    'origin' => 'synthetic',
    'note' => 'Twelve capturing groups that capture digits, then one a greedy run starves: group.alwaysEmptyCapture asks about each group, as many as its budget allows.',
];
