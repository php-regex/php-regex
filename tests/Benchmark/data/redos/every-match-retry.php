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
    'pattern' => '/(?:[^,]*,)?/',
    'origin' => 'synthetic',
    'note' => 'One attempt proven linear and no witness for the search of preg_match(), which stops at the first empty match: the proof then reads the loop of the functions that look for every match, whose retry after each empty match reads the rest of a run.',
    'expect' => 'proven',
];
